<?php

namespace Timewave\Logger\Classes;

class OtlpSender
{
    /**
     * Soft cap on the deferred queue. Long-running workers with a dead
     * collector would otherwise grow this without bound until OOM.
     */
    public const MAX_QUEUE_SIZE = 10000;

    /**
     * Per-call latency threshold (ms). Every send() measures elapsed time
     * but only writes the JSON-line `otlp_stopwatch` record to stdout when
     * the call exceeded this threshold — so production gets a signal when
     * OTLP is slow without flooding the log stream on every span.
     */
    public const STOPWATCH_THRESHOLD_MS = 200;

    /**
     * Process-wide registry of senders keyed by (host, deferred). Lets
     * many CustomLogger / Span instances share one OtlpSender (and one
     * cURL handle + one shutdown hook) for the life of the process.
     *
     * @var array<string, self>
     */
    private static array $sharedRegistry = [];

    /**
     * Set once per process so the shutdown function is wired exactly
     * once, regardless of how many deferred senders are created.
     */
    private static bool $shutdownHookRegistered = false;

    /**
     * Senders that have buffered items awaiting shutdown flush. Static
     * so the single shutdown hook can drain every deferred sender.
     *
     * @var array<int, self>
     */
    private static array $sendersNeedingFlush = [];

    private string $otlpHttpHost;

    private bool $deferred;

    /** @var resource|\CurlHandle|null reused across calls to keep host resolution + TLS state warm */
    private $curlHandle = null;

    /** @var array<int, array{0: string, 1: array}> path/payload pairs queued in deferred mode */
    private array $queue = [];

    private bool $isFlushing = false;

    private bool $queueFullWarned = false;

    /** @var resource|null cached php://stdout handle to avoid per-call fopen churn */
    private $stdoutHandle = null;

    public function __construct(string $otlpHttpHost, bool $deferred = false)
    {
        $this->otlpHttpHost = $otlpHttpHost;
        $this->deferred = $deferred;
    }

    /**
     * Get the process-wide sender for this (host, deferred) pair. Use this
     * from production call sites — it keeps the cURL handle and (when
     * deferred) the shutdown hook bounded to one per endpoint. Tests that
     * want a fresh isolated sender should call `new OtlpSender(...)`
     * directly.
     */
    public static function shared(string $otlpHttpHost, bool $deferred = false): self
    {
        $key = $otlpHttpHost . '|' . ($deferred ? '1' : '0');
        if (!isset(self::$sharedRegistry[$key])) {
            self::$sharedRegistry[$key] = new self($otlpHttpHost, $deferred);
        }
        return self::$sharedRegistry[$key];
    }

    /** @internal Drop every shared sender. Used by tests; not for production. */
    public static function clearSharedRegistry(): void
    {
        self::$sharedRegistry = [];
        self::$sendersNeedingFlush = [];
        // Note: $shutdownHookRegistered is intentionally kept — PHP's shutdown
        // hook can't be unregistered, so flipping the flag back would just
        // register a second hook on the next deferred call.
    }

    public function getOtlpHttpHost(): string
    {
        return $this->otlpHttpHost;
    }

    public function isDeferred(): bool
    {
        return $this->deferred;
    }

    public function http(string $path, array $payload): void
    {
        if ($this->deferred) {
            if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
                if (!$this->queueFullWarned) {
                    $this->writeStdout(
                        'OTLP ERROR: deferred queue full (>= ' . self::MAX_QUEUE_SIZE
                        . ' items), dropping new entries until flush'
                    );
                    $this->queueFullWarned = true;
                }
                return;
            }

            $this->queue[] = [$path, $payload];
            self::$sendersNeedingFlush[spl_object_id($this)] = $this;
            $this->registerShutdownHook();
            return;
        }

        $this->send($path, $payload);
    }

    public function flush(): void
    {
        // Reentrancy guard: a nested call (shutdown hook firing while a
        // manual flush() is mid-loop, or a future send() that somehow
        // re-enters) returns immediately rather than double-sending.
        if ($this->isFlushing) {
            return;
        }
        $this->isFlushing = true;
        try {
            // Snapshot the queue and clear it. Any append that happens during
            // the send loop (e.g. another part of the app logging) will be
            // picked up by a subsequent flush() call, not by this one.
            $batch = $this->queue;
            $this->queue = [];
            $this->queueFullWarned = false;

            foreach ($batch as [$path, $payload]) {
                $this->send($path, $payload);
            }
        } finally {
            $this->isFlushing = false;
            unset(self::$sendersNeedingFlush[spl_object_id($this)]);
        }
    }

    private function registerShutdownHook(): void
    {
        if (self::$shutdownHookRegistered) {
            return;
        }
        self::$shutdownHookRegistered = true;
        register_shutdown_function(static function (): void {
            // Snapshot keys; flush() unsets entries as it runs.
            foreach (array_keys(self::$sendersNeedingFlush) as $id) {
                if (isset(self::$sendersNeedingFlush[$id])) {
                    self::$sendersNeedingFlush[$id]->flush();
                }
            }
        });
    }

    private function send(string $path, array $payload): void
    {
        $start = microtime(true);

        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        }
        $ch = $this->curlHandle;
        // NOTE: every option used by send() MUST be in this array — the cURL
        // handle is reused across calls, so anything we set elsewhere would
        // leak between sends.
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->otlpHttpHost, '/') . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2, // never wait too long on OTLP collector
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        if ($latencyMs > self::STOPWATCH_THRESHOLD_MS) {
            $this->writeStdout((string) json_encode([
                'level' => 'WARNING',
                'name' => 'otlp_stopwatch',
                'path' => $path,
                'latencyMs' => $latencyMs,
                'thresholdMs' => self::STOPWATCH_THRESHOLD_MS,
            ]));
        }

        if ($error) {
            $this->writeStdout('OTLP ERROR: cURL error sending to OTLP: ' . $error);
            return;
        }

        if ($statusCode === 200 && trim((string) $response) === '{"partialSuccess":{}}') {
            // Idiotic response "partialSuccess" actually means total success.
            return;
        }

        $this->writeStdout("OTLP ERROR: sending was unsuccessful. statusCode: {$statusCode} response: '{$response}'");
    }

    private function writeStdout(string $line): void
    {
        if ($this->stdoutHandle === null) {
            $this->stdoutHandle = fopen('php://stdout', 'w');
        }
        fwrite($this->stdoutHandle, $line . "\n");
    }
}
