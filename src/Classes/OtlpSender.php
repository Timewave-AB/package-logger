<?php

namespace Timewave\Logger\Classes;

/**
 * OTLP HTTP sender, fire-and-forget by design.
 *
 * Every call to http() appends to an in-memory queue. The queue is drained
 * either by an explicit flush() / flushAll() or by a single process-wide
 * shutdown hook registered on first use. Sends never block the caller — the
 * library assumes a low-latency OTLP collector running locally (e.g. otelcol
 * on the same VM/pod) that handles batching, retries, and the actual wire
 * traffic.
 *
 * Senders are created and shared explicitly by callers — there is no
 * library-level singleton. Construct one per (host) in your composition
 * root and pass it into the CustomLogger / Span instances that need it.
 */
class OtlpSender
{
    /**
     * Soft cap on the in-memory queue. Long-running workers with a dead
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

    /** Set once per process so the shutdown function is wired exactly once. */
    private static bool $shutdownHookRegistered = false;

    /**
     * Every sender that has ever appended to its queue, tracked statically
     * so flushAll() (and the process-wide shutdown hook) can drain them all.
     * Not a singleton lookup — there is no key-based retrieval — purely a
     * cleanup roster for "flush whatever's left."
     *
     * @var array<int, self>
     */
    private static array $sendersNeedingFlush = [];

    private string $otlpHttpHost;

    /** @var resource|\CurlHandle|null reused across calls to keep host resolution + TLS state warm */
    private $curlHandle = null;

    /** @var array<int, array{0: string, 1: array}> path/payload pairs awaiting flush */
    private array $queue = [];

    private bool $isFlushing = false;

    private bool $queueFullWarned = false;

    /** @var resource|null cached php://stdout handle to avoid per-call fopen churn */
    private $stdoutHandle = null;

    public function __construct(string $otlpHttpHost)
    {
        $this->otlpHttpHost = $otlpHttpHost;
    }

    /**
     * Drain every sender that has queued items. Call this manually when you
     * need OTLP delivery before a specific point — e.g. right before
     * `fastcgi_finish_request()` so the response goes out after the flush
     * rather than blocking on it, or in tests that need to assert delivery.
     */
    public static function flushAll(): void
    {
        // Snapshot keys; flush() unsets entries as it runs.
        foreach (array_keys(self::$sendersNeedingFlush) as $id) {
            if (isset(self::$sendersNeedingFlush[$id])) {
                self::$sendersNeedingFlush[$id]->flush();
            }
        }
    }

    /** @internal Drop tracking state. Used by tests; not for production. */
    public static function resetForTesting(): void
    {
        self::$sendersNeedingFlush = [];
        // Note: $shutdownHookRegistered is intentionally kept — PHP's shutdown
        // hook can't be unregistered, so flipping the flag back would just
        // register a second hook on the next use.
    }

    public function getOtlpHttpHost(): string
    {
        return $this->otlpHttpHost;
    }

    public function http(string $path, array $payload): void
    {
        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            if (!$this->queueFullWarned) {
                $this->writeStdout(
                    'OTLP ERROR: queue full (>= ' . self::MAX_QUEUE_SIZE
                    . ' items), dropping new entries until flush'
                );
                $this->queueFullWarned = true;
            }
            return;
        }

        $this->queue[] = [$path, $payload];
        self::$sendersNeedingFlush[spl_object_id($this)] = $this;
        $this->registerShutdownHook();
    }

    public function flush(): void
    {
        // Reentrancy guard: a nested call returns immediately rather than
        // double-sending.
        if ($this->isFlushing) {
            return;
        }
        $this->isFlushing = true;
        try {
            // Snapshot the queue and clear it. Any append that happens during
            // the send loop will be picked up by a subsequent flush(), not
            // by this one.
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
        register_shutdown_function([self::class, 'flushAll']);
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
