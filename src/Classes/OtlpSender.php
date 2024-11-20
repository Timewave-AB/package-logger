<?php

namespace Timewave\Logger\Classes;

class OtlpSender
{
    public function __construct(
        public string $otlpHttpHost, // For example http://10.130.40.33:4318
    )
    {
    }

    public function http(string $path, array $payload): void
    {
        $ch = curl_init(rtrim($this->otlpHttpHost, '/') . $path);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2); // 2s, never wait to long on OTLP collector

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);

        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            fwrite(fopen('php://stdout', 'w'), 'OTLP ERROR: cURL error sending to OTLP: ' . $error . "\n");
            return;
        }

        if ($statusCode === 200 && trim($response) == '{"partialSuccess":{}}') {
            // Idiotic response "partialSuccess" actually means total success.
            return;
        }

        fwrite(fopen('php://stdout', 'w'), 'OTLP ERROR: sending was unsuccessful. statusCode: ' . $statusCode . " response: '" . $response . "'\n");
    }
}
