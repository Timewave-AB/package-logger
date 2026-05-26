<?php

namespace Timewave\Logger\Classes;

class OtlpSender
{
    /** Soft cap; protects long-running workers from OOM when the collector is dead. */
    public const MAX_QUEUE_SIZE = 10000;

    public const STOPWATCH_THRESHOLD_MS = 200;

    private static bool $shutdownHookRegistered = false;

    /** @var array<int, self> */
    private static array $sendersNeedingFlush = [];

    private string $otlpHttpHost;

    /** @var resource|\CurlHandle|null */
    private $curlHandle = null;

    /** @var array<int, array{0: string, 1: array}> */
    private array $queue = [];

    private bool $isFlushing = false;

    private bool $queueFullWarned = false;

    /** @var resource|null */
    private $stdoutHandle = null;

    public function __construct(string $otlpHttpHost)
    {
        $this->otlpHttpHost = $otlpHttpHost;
    }

    public static function flushAll(): void
    {
        foreach (array_keys(self::$sendersNeedingFlush) as $id) {
            if (isset(self::$sendersNeedingFlush[$id])) {
                self::$sendersNeedingFlush[$id]->flush();
            }
        }
    }

    /** @internal Test-only state reset. */
    public static function resetForTesting(): void
    {
        self::$sendersNeedingFlush = [];
        // $shutdownHookRegistered intentionally kept — PHP shutdown hooks can't be unregistered.
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
        if ($this->isFlushing) {
            return;
        }
        $this->isFlushing = true;
        try {
            // Snapshot then clear: re-entrant appends survive to the next flush().
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
            $ch = curl_init();
            if ($ch === false) {
                $this->writeStdout('OTLP ERROR: curl_init() failed');
                return;
            }
            $this->curlHandle = $ch;
        }
        $ch = $this->curlHandle;
        // Handle is reused: every option must be set here to avoid leaks across sends.
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->otlpHttpHost, '/') . $path,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
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

        // OTLP's "partialSuccess" actually means full success.
        if ($statusCode === 200 && trim((string) $response) === '{"partialSuccess":{}}') {
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
