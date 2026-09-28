<?php

use App\Controllers\InboxGatewayApi;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Psr\Log\NullLogger;

/**
 * TEST-801/REQ-801 (review Axis A PERF-01): test PERILAKU untuk bound
 * ingest prefetch media saat webhook pesan masuk.
 *
 * `InboxGatewayApi::messages()` mem-buffer + men-dekripsi + menulis ke
 * disk byte dari Gateway (jalur tak terpercaya). Sebelum remediasi ia
 * memakai default `callGatewayMediaDownload()` = batas UNDUH/tampilan
 * 100MB. Test ini menjalankan cURL SUNGGUHAN ke loopback server dan
 * membuktikan:
 *   - body melebihi `maxMediaPrefetchMb` -> prefetch GAGAL, tidak ada file
 *     tersimpan (kalau masih 100MB, body 2MB akan sukses dan tersimpan);
 *   - body dalam `maxMediaPrefetchMb` -> sukses tersimpan.
 *
 * Jalur `Inbox::media()` (batas unduh) dan jalur Teruskan (batas unggah)
 * sudah dikunci terpisah oleh test lain (`InboxTeruskanMediaTest`
 * `capturedDownloadMaxBytes`); test ini fokus pada sumbu ingest.
 *
 * @internal
 */
final class InboxPrefetchIngestBoundTest extends CIUnitTestCase
{
    private const CHAT_ID = '6281200000098@s.whatsapp.net';

    private ?int $port = null;

    /** @var resource|null */
    private $proses = null;

    private string $serverDir = '';
    private string $logFile = '';
    private string $storageDir = '';

    /** @var array<string, string|null> */
    private array $envAsli = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        // TEST-802 (isolasi): test lain (mis. InboxHandoffTest yang sengaja
        // memaksa insert gagal) bisa meninggalkan flag transStatus=false pada
        // koneksi `inbox` bersama. Reset supaya test ini deterministik walau
        // dijalankan di tengah suite, bukan hanya sendirian.
        $db->resetTransStatus();
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();

        foreach (['inbox.gatewayBaseUrl', 'inbox.mediaStoragePath', 'inbox.maxMediaPrefetchMb'] as $kunci) {
            $this->envAsli[$kunci] = $_ENV[$kunci] ?? null;
        }

        $this->storageDir = sys_get_temp_dir() . '/aulia_prefetch_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        // CLN-1001: pembersihan artefak server hanya di sini -- bukan di
        // tengah retry `mulaiServer()`, supaya router.php tetap ada untuk
        // percobaan berikutnya.
        $this->hentikanProses();
        $this->bersihkanServerDir();

        foreach ($this->envAsli as $kunci => $nilai) {
            if ($nilai === null) {
                unset($_ENV[$kunci]);
            } else {
                $_ENV[$kunci] = $nilai;
            }
        }

        if ($this->storageDir !== '' && is_dir($this->storageDir)) {
            foreach (glob($this->storageDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->storageDir);
        }

        parent::tearDown();
    }

    public function testPrefetchDalamBatasIngestMenyimpanFile(): void
    {
        $port = $this->mulaiServer();
        if ($port === null) {
            $this->markTestSkipped('Loopback server (php -S) tidak tersedia di lingkungan ini; bound ingest prefetch TIDAK tervalidasi pada run ini.');

            return;
        }

        $this->pasangEnv($port, 1); // 1MB
        $body = $this->callMessages($this->payloadMedia('/bytes/262144')); // 256KB

        $this->assertSame('success', $body['status'], 'Respons: ' . json_encode($body));

        $row = $this->lastMessage();
        $this->assertNotNull($row['media_local_filename'], 'Body di bawah batas ingest harus ter-prefetch.');
        $this->assertFileExists($this->storageDir . DIRECTORY_SEPARATOR . $row['media_local_filename']);
        $this->assertNotNull($row['media_download_attempted_at'], 'Percobaan prefetch harus tercatat.');
    }

    public function testPrefetchMelebihiBatasIngestTidakMenyimpanWalaupunDalamBatasUnduh(): void
    {
        $port = $this->mulaiServer();
        if ($port === null) {
            $this->markTestSkipped('Loopback server (php -S) tidak tersedia di lingkungan ini; bound ingest prefetch TIDAK tervalidasi pada run ini.');

            return;
        }

        $this->pasangEnv($port, 1); // batas ingest 1MB, jauh di bawah batas unduh 100MB
        $body = $this->callMessages($this->payloadMedia('/bytes/2097152')); // 2MB

        // Pesan tetap diterima (prefetch best-effort di luar transaksi).
        $this->assertSame('success', $body['status'], 'Respons: ' . json_encode($body));

        $row = $this->lastMessage();
        $this->assertNull(
            $row['media_local_filename'],
            'REQ-801: body 2MB melebihi batas ingest 1MB -> dipastikan bukan memakai batas unduh 100MB.'
        );
        $this->assertSame([], glob($this->storageDir . DIRECTORY_SEPARATOR . '*'), 'Tidak ada file ingest tersimpan saat bound dilewati.');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function pasangEnv(int $port, int $prefetchMb): void
    {
        $_ENV['inbox.gatewayBaseUrl']     = 'http://127.0.0.1:' . $port;
        $_ENV['inbox.mediaStoragePath']   = $this->storageDir;
        $_ENV['inbox.maxMediaPrefetchMb'] = (string) $prefetchMb;
    }

    /** @param array<string, mixed> $payload */
    private function callMessages(array $payload): array
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));

        $controller = new InboxGatewayApi();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return json_decode($controller->messages()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function payloadMedia(string $directPath): array
    {
        return [
            'wa_message_id'     => 'PREFETCH-' . bin2hex(random_bytes(4)),
            'chat_id'           => self::CHAT_ID,
            'jid_type'          => 'pn',
            'message_type'      => 'image',
            'message_timestamp' => date('Y-m-d H:i:s'),
            'sender_jid'        => self::CHAT_ID,
            'media'             => [
                'direct_path'      => $directPath,
                'media_key_base64' => 'a2V5',
                'mimetype'         => 'image/jpeg',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function lastMessage(): array
    {
        $rows = db_connect('inbox')->table('messages')->orderBy('id', 'DESC')->get()->getResultArray();
        $this->assertNotEmpty($rows, 'Harus ada baris pesan tersimpan.');

        return $rows[0];
    }

    // ------------------------------------------------------------------
    // Loopback server (php -S): mengalirkan N byte dari POST JSON
    // `direct_path` = /bytes/N, tanpa Content-Length.
    // ------------------------------------------------------------------

    private function mulaiServer(): ?int
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $this->serverDir = sys_get_temp_dir() . '/aulia_prefetch_srv_' . bin2hex(random_bytes(4));
        @mkdir($this->serverDir, 0775, true);

        $router = $this->serverDir . DIRECTORY_SEPARATOR . 'router.php';
        file_put_contents($router, <<<'PHP'
            <?php
            $raw    = (string) file_get_contents('php://input');
            $data   = json_decode($raw, true);
            $direct = is_array($data) ? (string) ($data['direct_path'] ?? '') : '';
            $bytes  = preg_match('#^/bytes/(\d+)$#', $direct, $m) ? (int) $m[1] : 0;
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

        // Dua percobaan: menutup socket probe di cariPort() membuka celah
        // TOCTOU kecil (port bisa direbut proses lain sebelum `php -S` bind).
        // Retry sekali dengan port baru membuat test tahan balapan itu.
        for ($percobaan = 0; $percobaan < 2; $percobaan++) {
            $port = $this->cariPort();
            if ($port === null) {
                continue;
            }

            $this->logFile = $this->serverDir . DIRECTORY_SEPARATOR . 'server-' . $port . '.log';

            // stdio dialihkan ke berkas log supaya pipe TIDAK perlu dikuras --
            // php -S bisa memblokir saat buffer pipe penuh dan tak dibaca.
            $this->proses = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
                [1 => ['file', $this->logFile, 'a'], 2 => ['file', $this->logFile, 'a']],
                $pipes,
                $this->serverDir
            );

            if (! is_resource($this->proses)) {
                $this->proses = null;
                continue;
            }

            if ($this->tungguServer($port)) {
                $this->port = $port;

                return $port;
            }

            $this->hentikanProses();
        }

        return null;
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

    /**
     * CLN-1001: HENTIKAN proses saja. TIDAK menghapus `serverDir`/`logFile`,
     * sehingga retry `mulaiServer()` pada percobaan berikutnya masih
     * menemukan `router.php` dan benar-benar dapat berhasil. Sebelum fix,
     * helper ini menghapus direktori + mengosongkan path, membuat percobaan
     * kedua selalu gagal dan test turun jadi `markTestSkipped`.
     */
    private function hentikanProses(): void
    {
        if (is_resource($this->proses)) {
            proc_terminate($this->proses);
            proc_close($this->proses);
        }

        $this->proses = null;
        $this->port   = null;
    }

    /**
     * CLN-1001: pembersihan artefak server -- hanya dipanggil dari
     * `tearDown()`, bukan saat retry.
     */
    private function bersihkanServerDir(): void
    {
        if ($this->serverDir !== '' && is_dir($this->serverDir)) {
            foreach (glob($this->serverDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->serverDir);
        }

        $this->serverDir = '';
        $this->logFile   = '';
    }
}
