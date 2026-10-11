<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Starts tests/_support/fake-gateway-router.php under PHP's built-in web
 * server on a free local port, so feature tests can exercise the real HTTP
 * client code in Inbox.php against scripted Gateway answers (TODO-Q2).
 *
 * The built-in server is single-threaded: a slow scenario blocks later
 * requests, so tests that use `lambat` should run last in their class.
 */
final class FakeGatewayServer
{
    /** @var resource */
    private $process;

    private function __construct($process, private int $port, private string $logFile)
    {
        $this->process = $process;
    }

    public static function start(): self
    {
        $port    = self::unusedPort();
        $dir     = sys_get_temp_dir();
        $logFile = $dir . DIRECTORY_SEPARATOR . 'fake-gateway-' . $port . '.jsonl';
        @unlink($logFile);

        $env = getenv();
        $env['FAKE_GATEWAY_LOG'] = $logFile;

        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . DIRECTORY_SEPARATOR . 'fake-gateway-router.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $dir . DIRECTORY_SEPARATOR . 'fake-gateway-server.log', 'a'],
                2 => ['file', $dir . DIRECTORY_SEPARATOR . 'fake-gateway-server.log', 'a'],
            ],
            $pipes,
            null,
            $env
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Gagal menjalankan fake gateway (php -S).');
        }

        $server = new self($process, $port, $logFile);

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            usleep(100_000);
        }

        $server->stop();

        throw new RuntimeException('Fake gateway tidak siap di port ' . $port . '.');
    }

    /** A local port that nothing is listening on (bound once, then released). */
    public static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('Tidak bisa mencari port kosong: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** @var array{0: mixed, 1: mixed, 2: string|false}|null */
    private static ?array $urlAsli = null;

    /**
     * Point Config\Inbox at $url. The .env value is already loaded into
     * $_ENV/$_SERVER at bootstrap and env() reads $_ENV before getenv(), so
     * all three must be overridden. The original is remembered once and put
     * back by restoreGatewayBaseUrl(), so later tests still see .env.
     */
    public static function useGatewayBaseUrl(string $url): void
    {
        $key = 'inbox.gatewayBaseUrl';
        self::$urlAsli ??= [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];

        putenv($key . '=' . $url);
        $_ENV[$key]    = $url;
        $_SERVER[$key] = $url;
    }

    public static function restoreGatewayBaseUrl(): void
    {
        if (self::$urlAsli === null) {
            return;
        }

        $key = 'inbox.gatewayBaseUrl';
        [$env, $server, $getenv] = self::$urlAsli;
        self::$urlAsli = null;

        if ($env === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        putenv($getenv === false ? $key : $key . '=' . $getenv);
    }

    public function baseUrl(string $skenario): string
    {
        return 'http://127.0.0.1:' . $this->port . '/skenario/' . $skenario;
    }

    /** @return list<array<string, mixed>> requests received, oldest first */
    public function requests(): array
    {
        if (! is_file($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static fn (string $line) => json_decode($line, true), $lines);
    }

    public function clearRequests(): void
    {
        @unlink($this->logFile);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->logFile);
    }
}
