<?php

namespace Timewave\Logger\Tests;

/**
 * Run a snippet of PHP code in a fresh subprocess with the package autoloader
 * already required, and return everything written to stdout + stderr.
 *
 * Output via fwrite(fopen('php://stdout', 'w'), ...) goes directly to fd 1 and
 * is not captured by ob_start, so a subprocess is the only reliable way to
 * assert on the logger's real output.
 */
trait LoggerSubprocessTrait
{
    protected function runLoggerScript(string $code): string
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoload === false) {
            $this->fail('vendor/autoload.php not found — run composer install');
        }

        $script = "<?php require " . var_export($autoload, true) . "; " . $code;
        $tmp = tempnam(sys_get_temp_dir(), 'logtest_');
        file_put_contents($tmp, $script);

        try {
            $proc = proc_open(
                [PHP_BINARY, $tmp],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );
            if (!is_resource($proc)) {
                $this->fail('failed to spawn subprocess');
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            $output = (string) $stdout . (string) $stderr;
            if ($exitCode !== 0) {
                $this->fail("subprocess exited {$exitCode}; output:\n{$output}");
            }
            return $output;
        } finally {
            @unlink($tmp);
        }
    }

    /** @return string[] */
    protected function nonEmptyLines(string $output): array
    {
        return array_values(array_filter(
            preg_split('/\R/', $output) ?: [],
            static function (string $l): bool { return $l !== ''; }
        ));
    }
}
