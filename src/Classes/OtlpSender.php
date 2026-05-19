<?php

namespace Timewave\Logger\Classes;

class OtlpSender
{
    public string $otlpHttpHost;

    public bool $deferred;

    /** @var resource|\CurlHandle|null reused across calls so cURL keeps host resolution + TLS state alive */
    private $curlHandle = null;

    /** @var array<int, array{0: string, 1: array}> path/payload pairs queued in deferred mode */
    private array $queue = [];

    private bool $shutdownRegistered = false;

    public function __construct(string $otlpHttpHost, bool $deferred = false)
    {
        $this->otlpHttpHost = $otlpHttpHost;
        $this->deferred = $deferred;
    }

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    public function http(string $path, array $payload): void
    {
        if ($this->deferred) {
            $this->queue[] = [$path, $payload];
            if (!$this->shutdownRegistered) {
                register_shutdown_function([$this, 'flush']);
                $this->shutdownRegistered = true;
            }
            return;
        }

        $this->send($path, $payload);
    }

    public function flush(): void
    {
        while (!empty($this->queue)) {
            [$path, $payload] = array_shift($this->queue);
            $this->send($path, $payload);
        }
    }

    private function send(string $path, array $payload): void
    {
        $start = microtime(true);

        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
        }
        $ch = $this->curlHandle;
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
        $this->writeStdout("OTLP stopwatch: {$path} {$latencyMs}ms");

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
        fwrite(fopen('php://stdout', 'w'), $line . "\n");
    }
}
