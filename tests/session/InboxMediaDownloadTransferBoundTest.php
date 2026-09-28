<?php

use App\Controllers\Inbox;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as GatewayInboxConfig;

/**
 * TEST-701 (SEC-602/COR-701): test PERILAKU untuk bound transfer
 * `Inbox::callGatewayMediaDownload()` -- menjalankan cURL SUNGGUHAN
 * melawan loopback HTTP server lokal yang mengalirkan body TANPA header
 * `Content-Length`. Ini menggantikan guard statis (assert substring
 * sumber) yang rapuh: yang diuji adalah hasil nyata fungsi, bukan teks.
 *
 * - Body melebihi bound -> transfer dibatalkan saat mengalir, `413`,
 *   deterministik walau `Content-Length` tidak ada.
 * - Body dalam bound -> sukses, byte utuh.
 */
final class InboxMediaDownloadTransferBoundTest extends CIUnitTestCase
{
    private ?int $port = null;

    /** @var resource|null */
    private $proses = null;

    private string $serverDir = '';

    protected function tearDown(): void
    {
        $this->hentikanServer();

        parent::tearDown();
    }

    public function testUnduhanMelebihiBoundDibatalkanDiTransferTanpaContentLength(): void
    {
        $port = $this->mulaiServer();
        $this->assertNotNull($port, 'Loopback server gagal start pada lingkungan ini.');

        $config                 = new GatewayInboxConfig();
        $config->gatewayToken   = 'test-token';
        $config->gatewayBaseUrl = 'http://127.0.0.1:' . $port . '/bytes/524288';

        // Server mengalirkan 512KB; bound 256KB. Tanpa Content-Length.
        $hasil = (new Inbox())->callGatewayMediaDownload(
            $config,
            ['media_type' => 'image', 'direct_path' => '/gw', 'media_key_base64' => 'kk'],
            'image/jpeg',
            30,
            256 * 1024
        );

        $this->assertFalse($hasil['ok'], 'Body melebihi bound harus gagal.');
        $this->assertSame(413, $hasil['status'], 'COR-701: overflow deterministik 413 tanpa Content-Length.');
        $this->assertSame('Lampiran dari WhatsApp melebihi batas ukuran.', $hasil['error']);
    }

    public function testUnduhanDalamBoundSuksesUtuh(): void
    {
        $port = $this->mulaiServer();
        $this->assertNotNull($port, 'Loopback server gagal start pada lingkungan ini.');

        $config                 = new GatewayInboxConfig();
        $config->gatewayToken   = 'test-token';
        $config->gatewayBaseUrl = 'http://127.0.0.1:' . $port . '/bytes/65536';

        $hasil = (new Inbox())->callGatewayMediaDownload(
            $config,
            ['media_type' => 'image', 'direct_path' => '/gw', 'media_key_base64' => 'kk'],
            'image/jpeg',
            30,
            256 * 1024
        );

        $this->assertTrue($hasil['ok'], 'Body di bawah bound harus sukses.');
        $this->assertSame(64 * 1024, strlen((string) $hasil['binary']), 'Byte diterima utuh.');
    }

    // ------------------------------------------------------------------
    // Loopback server (php -S) -- router mengalirkan N byte tanpa
    // Content-Length lewat query `?bytes=N`.
    // ------------------------------------------------------------------

    private function mulaiServer(): ?int
    {
        $this->serverDir = sys_get_temp_dir() . '/aulia_media_bound_' . bin2hex(random_bytes(4));
        @mkdir($this->serverDir, 0775, true);

        $router = $this->serverDir . DIRECTORY_SEPARATOR . 'router.php';
        file_put_contents($router, <<<'PHP'
            <?php
            $path  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $parts = explode('/', trim($path, '/'));
            $idx   = array_search('bytes', $parts, true);
            $bytes = ($idx !== false && isset($parts[$idx + 1])) ? max(0, (int) $parts[$idx + 1]) : 0;
            header('Content-Type: application/octet-stream');
            $chunk = str_repeat('b', 8192);
            $sent  = 0;
            while ($sent < $bytes) {
                $n = min(8192, $bytes - $sent);
                echo $n === 8192 ? $chunk : substr($chunk, 0, $n);
                $sent += $n;
                flush();
            }
            PHP);

        $port = $this->cariPort();
        if ($port === null) {
            return null;
        }

        $this->proses = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->serverDir
        );

        if (! is_resource($this->proses)) {
            $this->proses = null;

            return null;
        }

        if (! $this->tungguServer($port)) {
            $this->hentikanServer();

            return null;
        }

        $this->port = $port;

        return $port;
    }

    private function cariPort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            return null;
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return $name === false ? null : (int) substr(strrchr($name, ':'), 1);
    }

    private function tungguServer(int $port): bool
    {
        $batas = microtime(true) + 5.0;

        while (microtime(true) < $batas) {
            $koneksi = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($koneksi)) {
                fclose($koneksi);

                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    private function hentikanServer(): void
    {
        if (is_resource($this->proses)) {
            proc_terminate($this->proses);
            proc_close($this->proses);
        }

        $this->proses = null;
        $this->port   = null;

        if ($this->serverDir !== '' && is_dir($this->serverDir)) {
            foreach (glob($this->serverDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->serverDir);
        }

        $this->serverDir = '';
    }
}
