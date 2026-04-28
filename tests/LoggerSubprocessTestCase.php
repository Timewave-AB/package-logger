<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;

abstract class LoggerSubprocessTestCase extends TestCase
{
    /**
     * Run a snippet of PHP code in a fresh subprocess with the package autoloader
     * already required, and return everything written to stdout.
     *
     * The logger writes via fwrite(fopen('php://stdout', 'w'), ...) which goes
     * directly to fd 1 and is not captured by ob_start, so a subprocess is the
     * reliable way to assert on its real output.
     */
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
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp);
            $output = shell_exec($cmd . ' 2>&1');
            return $output ?? '';
        } finally {
            @unlink($tmp);
        }
    }
}
