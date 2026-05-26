<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Classes\OtlpSender;

/**
 * Per-test `php -S` server that mimics an OTLP collector: appends each
 * received request to a temp file (LOCK_EX) and responds with
 * `{"partialSuccess":{}}`. Override responseDelayMs() to simulate slow.
 */
abstract class OtlpHttpServerTestCase extends TestCase
{
    use LoggerSubprocessTrait;

    protected string $serverHost = '127.0.0.1';
    protected int $serverPort;
    protected string $requestsFile;

    /** @var resource|null */
    private $serverProcess;
    /** @var array<int, resource> */
    private array $serverPipes = [];
    private string $routerScript;

    protected function setUp(): void
    {
        parent::setUp();
        OtlpSender::resetForTesting();
        $this->serverPort = $this->findFreePort();
        $this->requestsFile = tempnam(sys_get_temp_dir(), 'otlpreqs_');
        file_put_contents($this->requestsFile, '');
        $this->routerScript = $this->writeRouterScript($this->requestsFile, $this->responseDelayMs());

        $this->serverProcess = proc_open(
            [
                PHP_BINARY,
                '-S', $this->serverHost . ':' . $this->serverPort,
                $this->routerScript,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ],
            $this->serverPipes
        );
        if (!is_resource($this->serverProcess)) {
            $this->fail('failed to start embedded php server');
        }
        $this->waitForServerReady();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess, defined('SIGTERM') ? SIGTERM : 15);
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->serverProcess);
                if (!$status['running']) {
                    break;
                }
                usleep(20_000);
            }
            $status = proc_get_status($this->serverProcess);
            if ($status['running']) {
                proc_terminate($this->serverProcess, defined('SIGKILL') ? SIGKILL : 9);
            }
            foreach ($this->serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($this->serverProcess);
        }
        if (isset($this->requestsFile)) {
            @unlink($this->requestsFile);
        }
        if (!empty($this->routerScript)) {
            @unlink($this->routerScript);
        }
        OtlpSender::resetForTesting();
        parent::tearDown();
    }

    protected function responseDelayMs(): int
    {
        return 0;
    }

    protected function otlpHost(): string
    {
        return "http://{$this->serverHost}:{$this->serverPort}";
    }

    /** @return array<int, array<string, mixed>> */
    protected function readRequests(): array
    {
        clearstatcache(true, $this->requestsFile);
        $contents = trim((string) file_get_contents($this->requestsFile));
        if ($contents === '') {
            return [];
        }
        $out = [];
        foreach (explode("\n", $contents) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    /**
     * Retry-read for requests written by a subprocess — the parent's view of
     * the shared file can lag the LOCK_EX write on slow CI runners.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function waitForRequests(int $expectedCount, float $timeoutSec = 2.0): array
    {
        $deadline = microtime(true) + $timeoutSec;
        $requests = $this->readRequests();
        while (count($requests) < $expectedCount && microtime(true) < $deadline) {
            usleep(20_000);
            $requests = $this->readRequests();
        }
        return $requests;
    }

    private function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            $this->fail("could not allocate port: $errstr");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function writeRouterScript(string $requestsFile, int $delayMs): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'router_');
        $reqsExport = var_export($requestsFile, true);
        $delayExport = var_export($delayMs, true);
        $script = <<<PHP
<?php
\$delay = $delayExport;
if (\$delay > 0) {
    usleep(\$delay * 1000);
}
\$body = file_get_contents('php://input');
\$entry = json_encode([
    'path' => \$_SERVER['REQUEST_URI'],
    'method' => \$_SERVER['REQUEST_METHOD'],
    'body_len' => strlen(\$body),
    'body' => \$body,
]) . "\\n";
file_put_contents($reqsExport, \$entry, FILE_APPEND | LOCK_EX);
http_response_code(200);
header('Content-Type: application/json');
echo '{"partialSuccess":{}}';
PHP;
        file_put_contents($tmp, $script);
        return $tmp;
    }

    private function waitForServerReady(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $errno = 0;
            $errstr = '';
            $f = @stream_socket_client(
                "tcp://{$this->serverHost}:{$this->serverPort}",
                $errno,
                $errstr,
                0.2
            );
            if ($f !== false) {
                fclose($f);
                return;
            }
            usleep(20_000);
        }
        $this->fail("embedded php server failed to start on {$this->serverHost}:{$this->serverPort}");
    }
}
