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
 * Teruskan (Tahap 4, TASK-002) -- feature test untuk meneruskan pesan TEKS di
 * seam HTTP `Inbox::kirimKeConversation()`.
 *
 * Cakupan: isi pesan diambil dari DB server (bukan kiriman client), aturan
 * `forward` yang TIDAK PERNAH berpasangan dengan `quoted` (CON-001), kolom
 * `is_forwarded` yang tunggal sementara seluruh kolom `quoted_*` tetap NULL
 * (REQ-009), atribut forwardability per `message_type` (REQ-006/GUD-001),
 * ownership HANYA pada percakapan tujuan (REQ-007/AC-004/AC-005), dan
 * replay `operation_id` (REQ-010).
 *
 * @internal
 */
final class InboxTeruskanTest extends CIUnitTestCase
{
    private ?Inbox $controller = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();
        $db->table('gateway_status')->emptyTable();
        $db->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        // UserModel::find() membaca tabel `db_users` pada koneksi default --
        // pola yang sama dipakai InboxBalasPesanTest. Baris 9 dibutuhkan
        // supaya cekOwnership() bisa menyebut nama penangan lain.
        db_connect()->query('CREATE TABLE IF NOT EXISTS db_users (id INTEGER PRIMARY KEY, nama TEXT, username TEXT)');
        db_connect()->table('db_users')->whereIn('id', [7, 9])->delete();
        db_connect()->table('db_users')->insert(['id' => 7, 'nama' => 'Test Kasir', 'username' => 'kasir']);
        db_connect()->table('db_users')->insert(['id' => 9, 'nama' => 'Kasir Lain', 'username' => 'kasir2']);

        $_SESSION = ['id_user' => 7, 'role' => 'kasir'];
    }

    protected function tearDown(): void
    {
        $this->controller = null;
        unset($_SESSION);

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC-001/REQ-008/REQ-009: forward teks sukses
    // ------------------------------------------------------------------

    public function testTeruskanTeksBerhasilMenyimpanPenandaDanTanpaKutipan(): void
    {
        $sumberConversation = $this->seedConversation('628111111111@s.whatsapp.net');
        $tujuanConversation = $this->seedConversation('628222222222@s.whatsapp.net');
        $sourceId           = $this->seedMessage($sumberConversation, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'Kapan pesanan saya dikirim?',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $tujuanConversation, $sourceId);
        $body       = $this->body($controller);

        $this->assertSame(200, $response->getStatusCode());

        // REQ-001: payload Gateway membawa `forward: true` dan TIDAK pernah
        // `quoted` bersamaan (CON-001).
        $this->assertTrue($controller->capturedForward, 'REQ-001: forward:true dikirim ke Gateway.');
        $this->assertNull($controller->capturedQuoted, 'CON-001: forward tidak pernah bersama quoted.');

        $row = $this->lastOutgoing($tujuanConversation);
        $this->assertSame(1, (int) $row['is_forwarded'], 'REQ-008: is_forwarded = 1.');
        $this->assertSame('Kapan pesanan saya dikirim?', $row['text'], 'Isi pesan sumber ikut tersimpan.');

        // REQ-009: kutipan TIDAK ikut terbawa, semuanya NULL.
        $this->assertNull($row['quoted_wa_message_id']);
        $this->assertNull($row['quoted_sender_label']);
        $this->assertNull($row['quoted_snippet']);
        $this->assertNull($row['quoted_media_available']);
        $this->assertNull($row['quoted_source_message_id']);
        $this->assertNull($row['quoted_media_type']);

        // REQ-003: respons melaporkan metode penanda Gateway; REQ-008: label UI
        // tetap berdiri sendiri dari kolom is_forwarded.
        $this->assertArrayHasKey('forward_marker_applied', $body);
        $this->assertSame('native', $body['forward_marker_applied']);
        $this->assertSame(1, (int) $body['message']['is_forwarded'], 'Label UI bersandar pada is_forwarded.');
    }

    public function testIsiPesanDiambilDariDbServerBukanDariKirimanBrowser(): void
    {
        // Section 9 "Always do": kasir tidak boleh memalsukan isi pesan yang
        // diteruskan. Nilai `text` yang dikirim browser diabaikan.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'TEKS ASLI DARI DB',
        ]);

        $controller = $this->controller();
        $this->invoke($controller, $conversationId, 'TEKS PALSU DARI CLIENT', $sourceId);

        $this->assertSame('TEKS ASLI DARI DB', $this->lastOutgoing($conversationId)['text'], 'ALT-002/Section 9: teks wajib dari DB server.');
        $this->assertSame('TEKS ASLI DARI DB', $controller->capturedText, 'Teks yang dikirim ke Gateway juga dari DB server.');
    }

    public function testKirimLewatEndpointKirimTidakMewajibkanTeksBrowser(): void
    {
        // TASK-002 (a): mode Teruskan tidak mengirim `text` sama sekali,
        // jadi validasi "Teks pesan tidak boleh kosong" harus dilewati.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan yang diteruskan',
        ]);

        $controller = $this->controller([
            'conversation_id'         => $conversationId,
            'forward_from_message_id' => $sourceId,
        ]);
        $response = $controller->kirim();

        $this->assertSame(200, $response->getStatusCode(), 'Mode Teruskan tidak boleh gagal karena text kosong.');
        $this->assertSame('pesan yang diteruskan', $this->lastOutgoing($conversationId)['text']);
    }

    public function testKirimTanpaForwardTetapMenolakTeksKosong(): void
    {
        // Regresi: jalur kirim biasa tidak boleh ikut melonggarkan validasi.
        $conversationId = $this->seedConversation();

        $controller = $this->controller(['conversation_id' => $conversationId]);
        $response   = $controller->kirim();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $this->countOutgoing($conversationId), 'Tidak ada baris yang ditulis.');
    }

    public function testResponsKirimBiasaTidakMenyertakanForwardMarker(): void
    {
        // CON-007: respons tanpa Teruskan tidak berubah satu byte pun.
        $conversationId = $this->seedConversation();

        $controller = $this->controller();
        $this->invoke($controller, $conversationId, 'pesan biasa', null);
        $body = $this->body($controller);

        $this->assertNull($controller->capturedForward, 'Kirim biasa tidak pernah mengirim forward:true.');
        $this->assertArrayNotHasKey('forward_marker_applied', $body);
        $this->assertArrayNotHasKey('quote_applied', $body, 'Kirim biasa tanpa kutipan tidak melaporkan quote_applied.');
        $this->assertSame(0, (int) $this->lastOutgoing($conversationId)['is_forwarded'], 'Pesan biasa is_forwarded = 0.');
    }

    public function testGatewayTanpaForwardMarkerDilaporkanApaAdanya(): void
    {
        // Rollout parsial (EXT-001): Gateway lama tidak mengirim field ini.
        // Nilainya hilang apa adanya; pesan tetap terkirim dan label AuliaPos
        // tetap berdiri sendiri (REQ-008).
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $controller = $this->controller([], ['forward_marker_applied' => null]);
        $this->invoke($controller, $conversationId, '', $sourceId);
        $body = $this->body($controller);

        $this->assertArrayHasKey('forward_marker_applied', $body);
        $this->assertNull($body['forward_marker_applied']);
        $this->assertSame(1, (int) $this->lastOutgoing($conversationId)['is_forwarded'], 'Label AuliaPos tidak bergantung Gateway.');
    }

    // ------------------------------------------------------------------
    // CON-001: forward + quoted saling-menolak
    // ------------------------------------------------------------------

    public function testForwardDanQuotedBersamaanDitolakDengan400(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);
        $quotedId = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan yang ingin dikutip',
        ]);

        $controller = $this->controller(['quoted_message_id' => $quotedId]);
        $response   = $this->teruskan($controller, $conversationId, $sourceId);
        $body       = $this->body($controller);

        $this->assertSame(400, $response->getStatusCode(), 'CON-001: forward + quoted -> 400.');
        $this->assertNull($controller->capturedForward, 'Gateway tidak boleh dipanggil sama sekali.');
        $this->assertSame(0, $this->countOutgoing($conversationId), 'Tidak ada kolom/baris yang ditulis.');
        $this->assertStringContainsString('meneruskan', $body['message']);
    }

    // ------------------------------------------------------------------
    // REQ-006/GUD-001: aturan forwardability ditegakkan di server
    // ------------------------------------------------------------------

    public function testSumberAudioDitolakDengan400(): void
    {
        // AC-002/GUD-001: penonaktifan di UI bukan satu-satunya pengaman --
        // request langsung ke endpoint tetap ditolak.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'audio',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, $sourceId);
        $body       = $this->body($controller);

        $this->assertSame(400, $response->getStatusCode(), 'REQ-006: audio tidak pernah forwardable.');
        $this->assertStringContainsString('Audio/video', $body['message'], 'Pesan error harus bisa dibaca kasir.');
        $this->assertNull($controller->capturedForward, 'Gateway tidak boleh dipanggil.');
        $this->assertSame(0, $this->countOutgoing($conversationId), 'Tidak ada baris yang ditulis.');
    }

    public function testSumberVideoDitolakDengan400(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'outgoing',
            'message_type' => 'video',
            'send_status'  => 'sent',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, $sourceId);

        $this->assertSame(400, $response->getStatusCode(), 'REQ-006: video tidak pernah forwardable.');
        // Baris outgoing yang ada hanyalah SUMBER yang sengaja diseed di atas
        // -- tidak ada baris BARU (hasil Teruskan) yang ditulis.
        $this->assertSame(1, $this->countOutgoing($conversationId));
    }

    public function testSumberCatatanInternalDitolakDengan400(): void
    {
        // ASSUMPTION-012: catatan internal adalah isi untuk toko, bukan pesan
        // pelanggan -- meneruskannya berarti membocorkan isi internal.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'outgoing',
            'message_type' => 'text',
            'text'         => 'catataninternal',
            'send_status'  => 'sent',
            'is_internal'  => 1,
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, $sourceId);
        $body       = $this->body($controller);

        $this->assertSame(400, $response->getStatusCode(), 'Catatan internal tidak boleh diteruskan.');
        $this->assertStringContainsString('Catatan internal', $body['message']);
        // Baris outgoing yang ada hanyalah SUMBER (catatan internal itu
        // sendiri) -- tidak ada baris BARU hasil Teruskan yang ditulis.
        $this->assertSame(1, $this->countOutgoing($conversationId));
    }

    public function testSumberOutgoingYangBelumTerkirimDitolakDengan400(): void
    {
        // Cermin aturan tombol "Balas": pesan yang tidak pernah sampai ke
        // siapa pun tidak boleh ikut diteruskan.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'outgoing',
            'message_type' => 'text',
            'text'         => 'gagal terkirim',
            'send_status'  => 'failed',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, $sourceId);
        $body       = $this->body($controller);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('belum terkirim', $body['message']);
        // Baris outgoing yang ada hanyalah SUMBER (gagal terkirim) -- tidak
        // ada baris BARU hasil Teruskan yang ditulis.
        $this->assertSame(1, $this->countOutgoing($conversationId));
    }

    public function testSumberTidakAdaDitolakDengan400(): void
    {
        $conversationId = $this->seedConversation();

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, 999999);
        $body       = $this->body($controller);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('tidak ditemukan', $body['message']);
        $this->assertSame(0, $this->countOutgoing($conversationId));
    }

    public function testSumberDenganTipeDiLuarDaftarDitolakDengan400(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'location',
            'text'         => 'lokasi',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, $sourceId);

        $this->assertSame(400, $response->getStatusCode(), 'Tipe di luar {text,image,document,sticker} ditolak.');
        $this->assertSame(0, $this->countOutgoing($conversationId));
    }

    public function testSumberTanpaTeksDitolakDengan400(): void
    {
        // Melindungi dari pengiriman pesan kosong (CON-002 spirit): sumber
        // tanpa teks tidak boleh jadi pesan teks kosong.
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => null,
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, $sourceId);
        $body       = $this->body($controller);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('tidak punya teks', $body['message']);
        $this->assertNull($controller->capturedForward, 'Gateway tidak boleh dipanggil.');
    }

    // ------------------------------------------------------------------
    // REQ-007/AC-004/AC-005: ownership HANYA pada percakapan tujuan
    // ------------------------------------------------------------------

    public function testTujuanMilikKasirLainDitolakDengan403(): void
    {
        $conversationAsal = $this->seedConversation('628111111111@s.whatsapp.net');
        $tujuan           = $this->seedConversation('628222222222@s.whatsapp.net', 9);
        $sourceId         = $this->seedMessage($conversationAsal, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $tujuan, $sourceId);

        $this->assertSame(403, $response->getStatusCode(), 'AC-005: ownership tujuan tetap berlaku.');
        $this->assertNull($controller->capturedForward, 'Gateway tidak boleh dipanggil.');
        $this->assertSame(0, $this->countOutgoing($tujuan), 'Tidak ada baris yang ditulis.');
    }

    public function testSumberMilikKasirLainTapiTujuanMilikSendiriTetapBerhasil(): void
    {
        // AC-004/REQ-007: percakapan sumber TIDAK melalui cekOwnership().
        $conversationAsal = $this->seedConversation('628111111111@s.whatsapp.net', 9);
        $tujuan           = $this->seedConversation('628222222222@s.whatsapp.net', 7);
        $sourceId         = $this->seedMessage($conversationAsal, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'pesan dari percakapan orang lain',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $tujuan, $sourceId);

        $this->assertSame(200, $response->getStatusCode(), 'AC-004: ownership sumber tidak diperiksa.');
        $this->assertSame(1, (int) $this->lastOutgoing($tujuan)['is_forwarded']);
    }

    public function testTujuanSamaDenganPercakapanSumberTetapBoleh(): void
    {
        // Section 12: meneruskan ke percakapan yang sama diizinkan, dan
        // cekOwnership() tetap dijalankan karena itu tetap "percakapan tujuan".
        $conversationId = $this->seedConversation('628111111111@s.whatsapp.net', 7);
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $conversationId, $sourceId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, (int) $this->lastOutgoing($conversationId)['is_forwarded']);
    }

    public function testSumberMilikKasirLainDanTujuanJugaMilikKasirLainTetap403(): void
    {
        // Otorisasi menang atas resolusi Teruskan: 403, bukan 400.
        $conversationAsal = $this->seedConversation('628111111111@s.whatsapp.net', 9);
        $tujuan           = $this->seedConversation('628222222222@s.whatsapp.net', 9);

        $controller = $this->controller();
        $response   = $this->teruskan($controller, $tujuan, 999999);

        $this->assertSame(403, $response->getStatusCode(), 'Otorisasi menang atas validasi sumber.');
    }

    // ------------------------------------------------------------------
    // REQ-010: idempotensi
    // ------------------------------------------------------------------

    public function testReplayDenganOperationIdSamaTidakMenulisBarisKedua(): void
    {
        $conversationId = $this->seedConversation();
        $sourceId       = $this->seedMessage($conversationId, [
            'direction'    => 'incoming',
            'message_type' => 'text',
            'text'         => 'sumber',
        ]);

        $post = ['operation_id' => 'ui-forward-1', 'forward_from_message_id' => $sourceId];

        $first = $this->controller($post);
        $this->invoke($first, $conversationId, '', $sourceId);
        $this->assertFalse($this->body($first)['replayed'] ?? false);

        $second = $this->controller($post);
        $this->invoke($second, $conversationId, '', $sourceId);
        $secondBody = $this->body($second);

        $this->assertTrue($secondBody['replayed'], 'REQ-010: replay terdeteksi.');
        $this->assertSame('native', $secondBody['forward_marker_applied'], 'Replay melaporkan marker yang sama.');
        $this->assertSame(1, $this->countOutgoing($conversationId), 'Hanya satu baris pesan tersimpan.');
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $gatewayOverrides
     */
    private function controller(array $post = [], array $gatewayOverrides = []): InboxTeruskanSpy
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', $post);
        $controller = new InboxTeruskanSpy($gatewayOverrides);
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    /** Teruskan ke percakapan tujuan lewat seam private yang sama dengan kirim(). */
    private function teruskan(Inbox $controller, int $conversationIdTujuan, int $sourceId): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->invoke($controller, $conversationIdTujuan, '', $sourceId);
    }

    private function invoke(Inbox $controller, int $conversationId, string $text, ?int $forwardFromMessageId = null): \CodeIgniter\HTTP\ResponseInterface
    {
        $method = (new \ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        return $method->invoke($controller, $this->conversation($conversationId), $text, $forwardFromMessageId);
    }

    private function body(Inbox $controller): array
    {
        $property = (new \ReflectionClass($controller))->getParentClass()->getProperty('response');
        $property->setAccessible(true);

        return json_decode($property->getValue($controller)->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedConversation(string $chatId = '628123456789@s.whatsapp.net', int $assignedTo = 7): int
    {
        $db = db_connect('inbox');
        $db->table('conversations')->insert([
            'chat_id'                => $chatId,
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'assigned_to'            => $assignedTo,
            'last_message_direction' => 'incoming',
            'created_at'             => date('Y-m-d H:i:s'),
            'updated_at'             => date('Y-m-d H:i:s'),
        ]);

        return (int) $db->insertID();
    }

    /** @param array<string, mixed> $attributes */
    private function seedMessage(int $conversationId, array $attributes): int
    {
        $db = db_connect('inbox');
        $db->table('messages')->insert([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'SRC-' . bin2hex(random_bytes(6)),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'sender_jid'        => '628999888777@s.whatsapp.net',
            'text'              => null,
            'is_internal'       => 0,
            'message_timestamp' => date('Y-m-d H:i:s'),
            'send_status'       => 'received',
        ]);
        $id = (int) $db->insertID();

        $db->table('messages')->where('id', $id)->update($attributes);

        return $id;
    }

    private function conversation(int $id): array
    {
        return db_connect('inbox')->table('conversations')->where('id', $id)->get()->getResultArray()[0];
    }

    /** @return array<string, mixed> */
    private function lastOutgoing(int $conversationId): array
    {
        $rows = db_connect('inbox')->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $this->assertNotEmpty($rows, 'Harus ada pesan outgoing untuk percakapan ini.');

        return $rows[0];
    }

    private function countOutgoing(int $conversationId): int
    {
        return db_connect('inbox')->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', 'outgoing')
            ->countAllResults();
    }
}

final class InboxTeruskanSpy extends Inbox
{
    /** @var bool|null Nilai `forward` yang diterima controller (null = kirim biasa). */
    public ?bool $capturedForward = null;

    /** @var array<string, mixed>|null Objek `quoted` yang diterima controller. */
    public ?array $capturedQuoted = null;

    public string $capturedText = '';

    public function __construct(private readonly array $gatewayOverrides = [])
    {
    }

    protected function callGatewaySend(GatewayInboxConfig $config, string $chatId, string $text, ?string $operationId = null, ?array $quoted = null, ?bool $forward = null): array
    {
        $this->capturedForward = $forward;
        $this->capturedQuoted   = $quoted;
        $this->capturedText     = $text;

        return [
            'ok'                      => true,
            'wa_message_id'           => 'wa-out-' . bin2hex(random_bytes(4)),
            'quote_applied'           => array_key_exists('quote_applied', $this->gatewayOverrides)
                ? $this->gatewayOverrides['quote_applied']
                : true,
            'forward_marker_applied'  => array_key_exists('forward_marker_applied', $this->gatewayOverrides)
                ? $this->gatewayOverrides['forward_marker_applied']
                : 'native',
        ];
    }
}
