<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;
use App\Controllers\Inbox as InboxController;
use App\Libraries\InboxOutgoingRequest;
use App\Database\Migrations\CreateMessageSendAudit;
use App\Database\Migrations\AddPreviewToMessageSendAudit;
use Config\Inbox as InboxConfig;

// Migrasi CI4 diberi nama berkas ber-timestamp, jadi tidak PSR-4 autoloadable
// lewat nama kelas. Muat eksplisit supaya test bisa menjalankan migrasi asli
// (bukan menyalin skema) di DB uji.
require_once __DIR__ . '/../../app/Database/Migrations/2026-10-09-000001_CreateMessageSendAudit.php';
// TODO-L3: kolom preview_text/media_* (additive) -- tanpa ini, skema DB uji
// hanya punya kolom lama dan insertAudit() yang menulis kolom baru gagal
// diam-diam (ditangkap try/catch), membuat SEMUA assertion "1 baris audit"
// gagal dengan size 0.
require_once __DIR__ . '/../../app/Database/Migrations/2026-10-10-000001_AddPreviewToMessageSendAudit.php';

/**
 * Test double: menjalankan alur kirim/edit POS yang ASLI (validasi,
 * cekOwnership, pastikanGatewaySiap) tetapi mengganti panggilan HTTP ke
 * Gateway dengan respons gagal yang bisa diprogram. Seam sah karena
 * callGatewaySend()/callGatewaySendMedia()/callGatewayEdit()/callGatewayDelete()
 * memang `protected` di controller asli.
 */
class InboxAuditStub extends InboxController
{
    /** @var array<int, array<string, mixed>> respons berurutan; di-pop per panggilan */
    public static array $antrian = [];
    public static int $jumlahPanggilan = 0;

    private function berikutnya(): array
    {
        self::$jumlahPanggilan++;

        return array_shift(self::$antrian) ?? ['ok' => false, 'error' => 'antrian stub habis', 'error_code' => 'STUB_EMPTY'];
    }

    protected function callGatewaySend(InboxConfig $config, InboxOutgoingRequest $request): array
    {
        return $this->berikutnya();
    }

    protected function callGatewaySendMedia(InboxConfig $config, InboxOutgoingRequest $request): array
    {
        return $this->berikutnya();
    }

    protected function callGatewayEdit(InboxConfig $config, string $chatId, string $waMessageId, string $newText, ?string $operationId): array
    {
        return $this->berikutnya();
    }

    protected function callGatewayDelete(InboxConfig $config, string $chatId, string $waMessageId, ?string $operationId): array
    {
        return $this->berikutnya();
    }
}

/**
 * L3: audit kiriman keluar yang gagal (tabel `message_send_audit`).
 *
 * Regresi yang dijaga:
 *  - AT1 definitive (INVALID_CHAT_ID) -> outcome_kind 'definitive'
 *  - AT2 ambigu (SEND_UNRESOLVED)     -> outcome_kind 'unresolved'
 *  - AT3 OPERATION_ID_REUSED          -> outcome_kind 'definitive'
 *  - AT4 network (tanpa error_code)   -> outcome_kind 'network'
 *  - AT5 bentuk JSON respons TIDAK berubah
 *  - AT6 insertAudit gagal TIDAK mengubah respons
 *  - AT7 pemanggil existing (kirim teks + edit) tetap berjalan & tercatat
 *
 * Catatan: jalur media (`kirimMediaViaGateway`, pemanggil Inbox.php:1888)
 * memakai helper yang SAMA; tidak ada e2e multipart di sini karena upload
 * $_FILES tidak dapat di-inject andal lewat FeatureTestTrait di repo ini.
 *
 * @internal
 */
final class InboxMessageSendAuditTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();
        InboxAuditStub::$antrian = [];
        InboxAuditStub::$jumlahPanggilan = 0;

        putenv('inbox.gatewayBaseUrl=http://127.0.0.1:1');
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('gateway_status')->where('id', 1)->delete();
        $this->inbox->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => $now,
            'updated_at'        => $now,
        ]);

        // Tabel audit: migrasi asli dijalankan di DB uji (inbox group -> test DB).
        $migration = new CreateMessageSendAudit();
        $migration->down();
        $migration->up();
        (new AddPreviewToMessageSendAudit())->up();
        $this->inbox->table('message_send_audit')->emptyTable();

        $forge = \Config\Database::forge();
        $forge->dropTable('users', true);
        $forge->addField([
            'id'        => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'  => ['type' => 'TEXT'],
            'nama'      => ['type' => 'TEXT', 'null' => true],
            'inisial'   => ['type' => 'TEXT', 'null' => true],
            'role'      => ['type' => 'TEXT', 'default' => 'kasir'],
            'is_active' => ['type' => 'INTEGER', 'default' => 1],
            'priority'  => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
    }

    protected function tearDown(): void
    {
        putenv('inbox.gatewayBaseUrl');
        (new CreateMessageSendAudit())->down();
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function ruteStub(): void
    {
        $this->withRoutes([
            ['POST', 'inbox/kirim', '\InboxAuditStub::kirim', ['filter' => 'auth']],
            ['POST', 'inbox/pesan/(:num)/edit', '\InboxAuditStub::editPesan/$1', ['filter' => 'auth']],
        ]);
    }

    private function postKirim(int $conversationId, string $operationId, string $text = 'halo')
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/kirim', [
                'conversation_id' => $conversationId,
                'text'            => $text,
                'operation_id'    => $operationId,
            ]);
    }

    private function json($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function auditRows(int $conversationId): array
    {
        return $this->inbox->table('message_send_audit')
            ->where('conversation_id', $conversationId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function seedConv(): int
    {
        return $this->seedConversation('6281299' . random_int(10000, 99999) . '@s.whatsapp.net');
    }

    // ---------------- AT1 ----------------

    public function testAT1DefinitifMencatatAudit(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
            'state' => null, 'replayed' => false,
        ]];
        $this->ruteStub();

        $res = $this->postKirim($conv, 'op-at1-1');
        $res->assertStatus(400);
        $json = $this->json($res);
        $this->assertSame('error', $json['status']);
        $this->assertSame('INVALID_CHAT_ID', $json['error_code']);
        $this->assertArrayNotHasKey('uncertain', $json);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows, 'AT1: harus ada tepat 1 baris audit');
        $this->assertSame('definitive', $rows[0]['outcome_kind']);
        $this->assertSame('INVALID_CHAT_ID', $rows[0]['error_code']);
        $this->assertSame('outgoing', $rows[0]['direction']);
        $this->assertSame('kirimKeConversation', $rows[0]['context']);
        $this->assertSame(21, (int) $rows[0]['user_id']);
        $this->assertSame('op-at1-1', $rows[0]['operation_id']);
    }

    // ---------------- AT2 ----------------

    public function testAT2AmbiguMencatatAudit(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'SEND_UNRESOLVED',
            'error' => 'hasil belum pasti', 'http_code' => 504,
            'state' => 'in_flight', 'replayed' => false,
        ]];
        $this->ruteStub();

        $res = $this->postKirim($conv, 'op-at2-1');
        $res->assertStatus(504);
        $json = $this->json($res);
        $this->assertTrue($json['uncertain']);
        $this->assertSame('SEND_UNRESOLVED', $json['error_code']);
        $this->assertSame('in_flight', $json['state']);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame('unresolved', $rows[0]['outcome_kind']);
        $this->assertSame('SEND_UNRESOLVED', $rows[0]['error_code']);
        $this->assertSame('op-at2-1', $rows[0]['operation_id']);
    }

    // ---------------- AT3 ----------------

    public function testAT3OperationIdReusedDefinitif(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'OPERATION_ID_REUSED',
            'error' => 'kunci dipakai ulang', 'http_code' => 409,
            'state' => 'in_flight', 'replayed' => false,
        ]];
        $this->ruteStub();

        $res = $this->postKirim($conv, 'op-at3-1');
        $res->assertStatus(409);
        $json = $this->json($res);
        $this->assertTrue($json['new_key_required']);
        $this->assertSame('OPERATION_ID_REUSED', $json['error_code']);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame('definitive', $rows[0]['outcome_kind']);
        $this->assertSame('OPERATION_ID_REUSED', $rows[0]['error_code']);
    }

    // ---------------- AT4 ----------------

    public function testAT4NetworkTanpaErrorCode(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok'    => false,
            'error' => 'Tidak bisa menghubungi Gateway: timeout',
        ]];
        $this->ruteStub();

        $res = $this->postKirim($conv, 'op-at4-1');
        $res->assertStatus(502);
        $json = $this->json($res);
        $this->assertSame('error', $json['status']);
        $this->assertNull($json['error_code']);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame('network', $rows[0]['outcome_kind']);
        $this->assertNull($rows[0]['error_code']);
        $this->assertStringContainsString('Tidak bisa menghubungi Gateway', (string) $rows[0]['error_message']);
    }

    // ---------------- AT5 ----------------

    public function testAT5BentukResponsTidakBerubah(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
            'state' => null, 'replayed' => false,
        ]];
        $this->ruteStub();

        $json = $this->json($this->postKirim($conv, 'op-at5-1'));

        // Field-field yang sudah ada harus tetap sama persis (tanpa field baru).
        $this->assertSame(
            ['status', 'error_code', 'state', 'replayed', 'message'],
            array_keys($json),
            'AT5: bentuk respons definitif harus sama seperti sebelumnya'
        );
        $this->assertSame('error', $json['status']);
        $this->assertNull($json['state']);
        $this->assertFalse($json['replayed']);
        $this->assertStringStartsWith('Gagal mengirim pesan: ', $json['message']);
    }

    // ---------------- AT6 ----------------

    public function testAT6AuditGagalTidakMengubahRespons(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
            'state' => null, 'replayed' => false,
        ]];
        $this->ruteStub();

        // Paksa insertAudit gagal: tabel audit dihapus sebelum request.
        (new CreateMessageSendAudit())->down();
        $this->assertFalse($this->inbox->tableExists('message_send_audit'), 'tabel audit harus sudah tidak ada');

        $res = $this->postKirim($conv, 'op-at6-1');
        $res->assertStatus(400);

        $json = $this->json($res);
        $this->assertSame('error', $json['status']);
        $this->assertSame('INVALID_CHAT_ID', $json['error_code']);
        $this->assertSame(
            ['status', 'error_code', 'state', 'replayed', 'message'],
            array_keys($json),
            'AT6: audit gagal tidak boleh mengubah bentuk respons'
        );
    }

    // ---------------- AT7 ----------------

    public function testAT7PemanggilExistingKirimTeksTetapSama(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
        ]];
        $this->ruteStub();

        $res = $this->postKirim($conv, 'op-at7-text');
        $res->assertStatus(400);
        $this->assertSame('INVALID_CHAT_ID', $this->json($res)['error_code']);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame('kirimKeConversation', $rows[0]['context']);
        $this->assertSame('op-at7-text', $rows[0]['operation_id']);
        $this->assertSame(21, (int) $rows[0]['user_id']);
    }

    public function testAT7PemanggilExistingEditPesanTetapSama(): void
    {
        $conv = $this->seedConv();
        $now  = date('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conv,
            'wa_message_id'     => 'WAMSG-AT7-' . random_int(10000, 99999),
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'text'              => 'pesan keluar',
            'send_status'       => 'sent',
            'message_timestamp' => $now,
            'created_at'        => $now,
            'is_internal'       => 0,
            'is_forwarded'      => 0,
        ]);
        $msgId = (int) $this->inbox->insertID();

        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
        ]];
        $this->ruteStub();

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $msgId . '/edit', ['new_text' => 'pesan diedit', 'operation_id' => 'op-at7-edit']);
        $res->assertStatus(400);
        $this->assertSame('INVALID_CHAT_ID', $this->json($res)['error_code']);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame('editPesan', $rows[0]['context']);
        $this->assertSame('op-at7-edit', $rows[0]['operation_id']);
    }

    // ---------------- AT8 (TODO-L3: pratinjau isi yang gagal) ----------------

    public function testAT8KirimTeksMencatatPreviewTextUtuh(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
        ]];
        $this->ruteStub();

        $res = $this->postKirim($conv, 'op-at8-text', 'Halo, ini pesan yang gagal terkirim');
        $res->assertStatus(400);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame('Halo, ini pesan yang gagal terkirim', $rows[0]['preview_text']);
        $this->assertNull($rows[0]['media_type'], 'kirim teks tidak boleh mengisi kolom media');
    }

    public function testAT8KirimTeksMemotongPreviewTextKePanjangMaksimum(): void
    {
        $conv = $this->seedConv();
        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
        ]];
        $this->ruteStub();

        $teksPanjang = str_repeat('a', 1500);
        $res = $this->postKirim($conv, 'op-at8-panjang', $teksPanjang);
        $res->assertStatus(400);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame(
            \App\Models\MessageSendAuditModel::PREVIEW_TEXT_MAX_LENGTH,
            mb_strlen((string) $rows[0]['preview_text']),
            'preview_text harus dipotong ke batas maksimum, bukan disimpan utuh'
        );
    }

    public function testAT8EditPesanMencatatPreviewTextBaru(): void
    {
        $conv = $this->seedConv();
        $now  = date('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conv,
            'wa_message_id'     => 'WAMSG-AT8-' . random_int(10000, 99999),
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'text'              => 'pesan keluar lama',
            'send_status'       => 'sent',
            'message_timestamp' => $now,
            'created_at'        => $now,
            'is_internal'       => 0,
            'is_forwarded'      => 0,
        ]);
        $msgId = (int) $this->inbox->insertID();

        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
        ]];
        $this->ruteStub();

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $msgId . '/edit', ['new_text' => 'teks hasil edit yang gagal', 'operation_id' => 'op-at8-edit']);
        $res->assertStatus(400);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertSame('teks hasil edit yang gagal', $rows[0]['preview_text'], 'preview harus teks HASIL EDIT (baru), bukan teks lama');
    }

    public function testAT8HapusPesanTidakMencatatPreview(): void
    {
        $conv = $this->seedConv();
        $now  = date('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conv,
            'wa_message_id'     => 'WAMSG-AT8-HAPUS-' . random_int(10000, 99999),
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'text'              => 'pesan yang mau dihapus',
            'send_status'       => 'sent',
            'message_timestamp' => $now,
            'created_at'        => $now,
            'is_internal'       => 0,
            'is_forwarded'      => 0,
        ]);
        $msgId = (int) $this->inbox->insertID();

        InboxAuditStub::$antrian = [[
            'ok' => false, 'error_code' => 'INVALID_CHAT_ID',
            'error' => 'chat_id tidak valid', 'http_code' => 400,
        ]];
        $this->withRoutes([
            ['POST', 'inbox/pesan/(:num)/hapus', '\InboxAuditStub::hapusPesan/$1', ['filter' => 'auth']],
        ]);

        $res = $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->post('/inbox/pesan/' . $msgId . '/hapus', ['operation_id' => 'op-at8-hapus']);
        $res->assertStatus(400);

        $rows = $this->auditRows($conv);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['preview_text'], 'hapus pesan tidak mencatat preview (yang gagal adalah AKSI, bukan konten baru)');
        $this->assertNull($rows[0]['media_type']);
    }
}
