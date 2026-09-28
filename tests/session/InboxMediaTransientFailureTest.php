<?php

use App\Controllers\Inbox;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as GatewayInboxConfig;
use Psr\Log\NullLogger;

/**
 * Kegagalan SEMENTING pada `GET /inbox/media/(:num)` (`Inbox::media()`)
 * TIDAK BOLEH diubah menjadi status permanen.
 *
 * Latar belakang (`plan-bugfix-inbox-media-unavailable-v1.0`, REQ-001):
 * `Inbox::media()` memakai HTTP `410` sebagai SATU-SATUNYA sinyal
 * "media benar-benar hilang" -- status itu menulis `media_confirmed_gone_at`,
 * dan request berikutnya di-short-circuit `410` tanpa pernah menghubungi
 * Gateway lagi (`Inbox.php:468-478`). Kalau Gateway salah memetakan
 * kegagalan sesaat (reconnect, timeout, kedip jaringan) menjadi `410`,
 * satu foto yang utuh akan diblacklist permanen.
 *
 * Test ini mengunci sisi CI4 dari kontrak itu: `503` (Gateway tidak bisa
 * dihubungi) dan `504` (Gateway timeout) harus diteruskan apa adanya dan
 * TIDAK menulis `media_confirmed_gone_at`, sehingga permintaan berikutnya
 * masih mencoba live-fetch. Jalur `410` yang benar tetap menandai gone
 * (CON-002) -- dijaga oleh `InboxMediaAuthTest`.
 *
 * Sisi Gateway (pemetaan error yang benar) ditutup oleh
 * `test/simulate-media-download-errors.js` di repo WA-Gateway.
 *
 * @internal
 */
final class InboxMediaTransientFailureTest extends CIUnitTestCase
{
    private string $mediaDir = '';

    /** @var string|null Nilai $_ENV['inbox.mediaStoragePath'] sebelum test. */
    private ?string $prevMediaStoragePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();

        $_SESSION = ['id_user' => 7, 'role' => 'kasir'];

        // Folder storage lokal di-override ke direktori sementara supaya test
        // ini tidak bergantung pada konfigurasi .env mesin mana pun. Pesan
        // yang di-seed sengaja TIDAK punya media_local_filename, jadi yang
        // diuji murni jalur live-fetch ke Gateway.
        $this->mediaDir = sys_get_temp_dir() . '/aulia_inbox_media_transient_' . bin2hex(random_bytes(4));
        mkdir($this->mediaDir, 0775, true);
        $this->prevMediaStoragePath = $_ENV['inbox.mediaStoragePath'] ?? null;
        $_ENV['inbox.mediaStoragePath'] = $this->mediaDir;
    }

    protected function tearDown(): void
    {
        if ($this->prevMediaStoragePath === null) {
            unset($_ENV['inbox.mediaStoragePath']);
        } else {
            $_ENV['inbox.mediaStoragePath'] = $this->prevMediaStoragePath;
        }

        unset($_SESSION);

        if ($this->mediaDir !== '' && is_dir($this->mediaDir)) {
            foreach (glob($this->mediaDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->mediaDir);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // REQ-001: Gateway tidak bisa dihubungi -> 503, media TIDAK di-blacklist
    // ------------------------------------------------------------------

    public function testGatewayTidakBisaDihubungi503TidakMenandaiMediaGone(): void
    {
        $conversationId = $this->seedConversation('628111111111@s.whatsapp.net');
        $messageId      = $this->seedImageMessage($conversationId);

        $controller = $this->makeController();
        $controller->gatewayResponse = [
            'ok'     => false,
            'status' => 503,
            'error'  => 'Gateway sedang tidak bisa dihubungi.',
        ];

        $response = $controller->media($messageId);

        $this->assertSame(503, $response->getStatusCode(), 'Status Gateway yang diteruskan apa adanya, tidak diturunkan jadi 410.');
        $this->assertSame(1, $controller->gatewayMediaDownloadCalls, 'Permintaan harus benar-benar mencapai jalur Gateway.');

        $row = db_connect('inbox')->table('messages')->where('id', $messageId)->get()->getRowArray();
        $this->assertNull(
            $row['media_confirmed_gone_at'],
            'REQ-001: kegagalan sementara TIDAK BOLEH menulis media_confirmed_gone_at (foto utuh akan diblacklist permanen).'
        );
    }

    // ------------------------------------------------------------------
    // REQ-001/REQ-005: Gateway timeout -> 504, TIDAK menandai media gone
    // ------------------------------------------------------------------

    public function testGatewayTimeout504TidakMenandaiMediaGone(): void
    {
        $conversationId = $this->seedConversation('628222222222@s.whatsapp.net');
        $messageId      = $this->seedImageMessage($conversationId);

        $controller = $this->makeController();
        $controller->gatewayResponse = [
            'ok'     => false,
            'status' => 504,
            'error'  => 'Gateway melewati batas waktu saat mengunduh media.',
        ];

        $response = $controller->media($messageId);

        $this->assertSame(504, $response->getStatusCode(), 'Timeout Gateway harus diteruskan sebagai 504.');
        $this->assertSame(1, $controller->gatewayMediaDownloadCalls, 'Permintaan harus benar-benar mencapai jalur Gateway.');

        $row = db_connect('inbox')->table('messages')->where('id', $messageId)->get()->getRowArray();
        $this->assertNull(
            $row['media_confirmed_gone_at'],
            'REQ-001: timeout Gateway TIDAK BOLEH ditulis sebagai media hilang permanen.'
        );
    }

    // ------------------------------------------------------------------
    // REQ-003: setelah 503, permintaan BERIKUTNYA masih menghubungi
    // Gateway -- inilah bukti foto tidak jadi diblacklist selamanya.
    // ------------------------------------------------------------------

    public function testPermintaanKeduaSetelah503MasihMenghubungiGateway(): void
    {
        $conversationId = $this->seedConversation('628333333333@s.whatsapp.net');
        $messageId      = $this->seedImageMessage($conversationId);

        $controller = $this->makeController();
        $controller->gatewayResponse = [
            'ok'     => false,
            'status' => 503,
            'error'  => 'Gateway sedang tidak bisa dihubungi.',
        ];

        $this->assertSame(503, $controller->media($messageId)->getStatusCode());

        // Gateway pulih.
        $controller->gatewayResponse = ['ok' => true, 'binary' => 'GATEWAY-BYTES-SETELAH-PULIH'];

        $response = $controller->media($messageId);

        $this->assertSame(
            2,
            $controller->gatewayMediaDownloadCalls,
            'REQ-003: permintaan kedua harus mencoba Gateway lagi, bukan short-circuit 410 di media_confirmed_gone_at.'
        );
        $this->assertSame(200, $response->getStatusCode(), 'Setelah Gateway pulih, media harus benar-benar tersaji.');
        $this->assertSame('GATEWAY-BYTES-SETELAH-PULIH', (string) $response->getBody());
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function makeController(): InboxMediaTransientSpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());

        $controller = new InboxMediaTransientSpy();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    private function seedConversation(string $chatId): int
    {
        $db = db_connect('inbox');
        $db->table('conversations')->insert([
            'chat_id'                => $chatId,
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => null,
            'last_message_direction' => 'incoming',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    private function seedImageMessage(int $conversationId): int
    {
        $db = db_connect('inbox');
        $db->table('messages')->insert([
            'conversation_id'       => $conversationId,
            'wa_message_id'         => 'MEDIA-TRANSIENT-' . bin2hex(random_bytes(4)),
            'direction'             => 'incoming',
            'message_type'          => 'image',
            'sender_jid'            => '628999888777@s.whatsapp.net',
            'media_metadata'        => json_encode(['direct_path' => '/x', 'media_key_base64' => 'k']),
            'media_mime_type'       => 'image/jpeg',
            'media_filename'        => 'x.jpg',
            'media_local_filename'    => null,
            'media_confirmed_gone_at' => null,
            'message_timestamp'     => date('Y-m-d H:i:s'),
            'send_status'           => 'received',
        ]);

        return (int) $db->insertID();
    }
}

final class InboxMediaTransientSpy extends Inbox
{
    /** Berapa kali jalur live-fetch Gateway dipanggil. */
    public int $gatewayMediaDownloadCalls = 0;

    /** @var array<string, mixed> Respons Gateway yang dikembalikan stub. */
    public array $gatewayResponse = ['ok' => false, 'status' => 503, 'error' => 'Gateway belum bisa dihubungi.'];

    public function callGatewayMediaDownload(GatewayInboxConfig $config, array $mediaRef, ?string $mimetype, int $timeoutSeconds = 30, ?int $maxBytes = null): array
    {
        $this->gatewayMediaDownloadCalls++;

        return $this->gatewayResponse;
    }
}
