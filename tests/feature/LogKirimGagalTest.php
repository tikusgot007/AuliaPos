<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;
use App\Database\Migrations\CreateMessageSendAudit;
use App\Database\Migrations\AddPreviewToMessageSendAudit;

// Migrasi CI4 diberi nama berkas ber-timestamp, jadi tidak PSR-4 autoloadable
// lewat nama kelas (pola sama dengan InboxMessageSendAuditTest.php). Muat
// eksplisit supaya test bisa menjalankan migrasi asli (bukan menyalin skema)
// di DB uji. Test tetap menegakkan skemanya sendiri lewat up()/down() di
// setUp()/tearDown() agar mandiri, terlepas dari apakah
// `aulia:sync-inbox-test-db` (TODO-Q3b) sudah menyiapkan tabelnya.
require_once __DIR__ . '/../../app/Database/Migrations/2026-10-09-000001_CreateMessageSendAudit.php';
require_once __DIR__ . '/../../app/Database/Migrations/2026-10-10-000001_AddPreviewToMessageSendAudit.php';

/**
 * TODO-L3: halaman admin read-only "Log Kiriman Gagal"
 * (`LogKirimGagal::index()`/`data()`, tabel `message_send_audit`).
 *
 * Membuktikan:
 *  - Guard admin-only: kasir ditolak, non-login diarahkan ke /login.
 *  - Endpoint data() mengembalikan baris audit dengan preview + info
 *    percakapan (join `conversations`) + nama kasir (resolve manual
 *    lewat UserModel, karena `user_id` logical reference ke DB lain).
 *  - Filter tanggal bekerja (hanya baris dalam rentang yang dikembalikan).
 *
 * @internal
 */
final class LogKirimGagalTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();

        $migration = new CreateMessageSendAudit();
        $migration->down();
        $migration->up();
        (new AddPreviewToMessageSendAudit())->up();
        $this->inbox->table('message_send_audit')->emptyTable();

        $forge = \Config\Database::forge();
        $forge->dropTable('users', true);
        $forge->addField([
            'id'            => ['type' => 'INTEGER', 'auto_increment' => true],
            'username'      => ['type' => 'TEXT'],
            'nama'          => ['type' => 'TEXT', 'null' => true],
            'inisial'       => ['type' => 'TEXT', 'null' => true],
            'divisi'        => ['type' => 'TEXT', 'null' => true],
            'password_hash' => ['type' => 'TEXT', 'null' => true],
            'role'          => ['type' => 'TEXT', 'default' => 'kasir'],
            'is_active'     => ['type' => 'INTEGER', 'default' => 1],
            'no_hp'         => ['type' => 'TEXT', 'null' => true],
            'profile_photo' => ['type' => 'TEXT', 'null' => true],
            'priority'      => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
        \Config\Database::connect()->table('users')->insert(['id' => 5, 'username' => 'kasir1', 'nama' => 'Siti', 'role' => 'kasir']);
    }

    protected function tearDown(): void
    {
        (new CreateMessageSendAudit())->down();
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function seedAuditRow(?int $conversationId, array $overrides = []): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('message_send_audit')->insert(array_merge([
            'operation_id'    => 'op-' . random_int(1000, 9999),
            'conversation_id' => $conversationId,
            'direction'       => 'outgoing',
            'outcome_kind'    => 'definitive',
            'error_code'      => 'INVALID_CHAT_ID',
            'error_message'   => 'chat_id tidak valid',
            'http_code'       => 400,
            'context'         => 'kirimKeConversation',
            'user_id'         => 5,
            'preview_text'    => 'Pesan yang gagal terkirim',
            'media_type'      => null,
            'media_file_name' => null,
            'media_size'      => null,
            'created_at'      => $now,
        ], $overrides));
    }

    public function testNonLoginDiarahkanKeLogin(): void
    {
        $response = $this->get('/log-kirim-gagal');
        $response->assertRedirectTo('/login');
    }

    public function testKasirDitolakAksesHalaman(): void
    {
        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'kasir'])
            ->get('/log-kirim-gagal');
        $response->assertRedirectTo('/kasir');
    }

    public function testAdminBisaAksesHalaman(): void
    {
        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-kirim-gagal');
        $response->assertStatus(200);
    }

    public function testDataMenampilkanPreviewDanInfoKonversasi(): void
    {
        $convId = $this->seedConversation('6281400000077@s.whatsapp.net', 'Budi');
        $this->seedAuditRow($convId);

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-kirim-gagal/data');
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(1, $json['data']);
        $row = $json['data'][0];
        $this->assertSame('Pesan yang gagal terkirim', $row['preview_text']);
        $this->assertSame('Siti', $row['kasir'], 'nama kasir harus di-resolve dari UserModel (DB lain)');
        $this->assertSame($convId, $row['conversation_id']);
        $this->assertSame('INVALID_CHAT_ID', $row['error_code']);
    }

    public function testDataFilterTanggalMenyaring(): void
    {
        $convId = $this->seedConversation('6281400000078@s.whatsapp.net');
        $this->seedAuditRow($convId, ['created_at' => '2020-01-01 10:00:00']);
        $this->seedAuditRow($convId, ['created_at' => date('Y-m-d H:i:s')]);

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-kirim-gagal/data?tanggal_awal=' . date('Y-m-d') . '&tanggal_akhir=' . date('Y-m-d'));
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame(1, $json['total'], 'hanya baris hari ini yang lolos filter');
    }

    public function testDataTanpaConversationIdTetapTampilDenganKontakTidakDiketahui(): void
    {
        $this->seedAuditRow(null);

        $response = $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->get('/log-kirim-gagal/data');
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertCount(1, $json['data']);
        $this->assertNull($json['data'][0]['conversation_id']);
    }
}
