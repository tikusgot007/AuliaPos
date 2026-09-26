<?php

use Config\Inbox as GatewayInboxConfig;
use App\Controllers\Inbox;
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Psr\Log\NullLogger;

/**
 * Grup Tahap 1 (spec-design-grup-tahap1-tab-inbox.md v1.1) -- test
 * otomatis untuk item yang mengubah kondisi endpoint (bisa diassert
 * programatis), sesuai pembagian kelas uji Section 6 spec: badge/tombol
 * tersembunyi murni visual (CON-005/CON-006) diverifikasi manual, TIDAK
 * di sini.
 *
 * Mencakup:
 * - AC-001/AC-002: filter status=grup hanya mengembalikan jid_type='group',
 *   dan grup tidak pernah muncul di 5 status lama.
 * - AC-003: badge perlu_dibalas mengecualikan grup.
 * - AC-006: endpoint aksi (ambil/lepas/tutup/snooze/konfirmasi-nomor/
 *   profil) menolak 403 untuk grup, sekalipun request langsung (melewati UI).
 * - AC-009: hapusPercakapan() grup oleh admin -> 200 walau status bukan
 *   closed; kasir non-admin -> tetap 403 (gate admin-only tidak berubah).
 * - AC-010: auto-assign TIDAK mengisi assigned_to saat kasir membalas
 *   grup (teks maupun media).
 *
 * @internal
 */
final class InboxGrupTahap1Test extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private const PERCAKAPAN_URL = 'inbox/percakapan/';

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversation_identities')->emptyTable();
        $db->table('conversations')->emptyTable();
        $db->table('gateway_status')->emptyTable();
    }

    private function sesi(string $role = 'kasir', int $idUser = 7): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => $role,
            'id_user'       => $idUser,
            'nama'          => 'Test ' . $role,
            'last_activity' => time(),
        ];
    }

    private function seedConversation(array $override = []): int
    {
        $db  = db_connect('inbox');
        $now = '2026-09-26 10:00:00';

        $db->table('conversations')->insert(array_merge([
            'chat_id'                  => '628' . random_int(100000000, 999999999) . '-' . random_int(1000000, 9999999) . '@g.us',
            'jid_type'                 => 'group',
            'contact_name'             => null,
            'phone'                    => null,
            'status'                   => 'open',
            'assigned_to'              => null,
            'last_seen_by_assignee_at' => null,
            'snoozed_until'            => null,
            'last_message_at'          => $now,
            'last_message_direction'   => 'incoming',
            'created_at'               => $now,
            'updated_at'               => $now,
        ], $override));

        return (int) $db->insertID();
    }

    private function seedConversationPribadi(array $override = []): int
    {
        return $this->seedConversation(array_merge([
            'chat_id'  => '628' . random_int(100000000, 999999999) . '@s.whatsapp.net',
            'jid_type' => 'pn',
        ], $override));
    }

    private function conversation(int $id): array
    {
        return db_connect('inbox')
            ->table('conversations')
            ->getWhere(['id' => $id])
            ->getRowArray();
    }

    private function payloadDari(string $query): array
    {
        $res = $this->withSession($this->sesi())
            ->get('inbox/api/conversations' . $query);

        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $data['status']);

        return $data['conversations'];
    }

    private function idsDari(string $query): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], $this->payloadDari($query));
    }

    // ---- AC-001/AC-002: filter status=grup ------------------------------

    public function testStatusGrupHanyaMengembalikanJidTypeGroup(): void
    {
        $grup = $this->seedConversation();
        $pribadi = $this->seedConversationPribadi();

        $ids = $this->idsDari('?status=grup');

        $this->assertSame([$grup], $ids);
        $this->assertNotContains($pribadi, $ids);

        $baris = $this->payloadDari('?status=grup')[0];
        $this->assertSame('grup', $baris['queue_status']);
        $this->assertSame('group', $baris['jid_type']);
    }

    public function testGrupTidakPernahMunculDiLimaStatusLama(): void
    {
        $this->seedConversation([
            'assigned_to'             => null,
            'last_message_direction'  => 'incoming',
            'last_seen_by_assignee_at' => null,
        ]);

        foreach (['belum_diambil', 'open', 'menunggu', 'ditunda', 'selesai'] as $status) {
            $this->assertSame([], $this->idsDari('?status=' . $status), "Grup tidak boleh muncul di status={$status}");
        }
    }

    // ---- AC-003: badge perlu_dibalas mengecualikan grup ------------------

    public function testBadgePerluDibalasMengecualikanGrup(): void
    {
        // Grup ini secara response_state akan terhitung 'perlu_dibalas'
        // (incoming, belum pernah dilihat) TAPI harus dikecualikan karena
        // jid_type='group' (REQ-004).
        $this->seedConversation([
            'status'                   => 'open',
            'last_message_direction'   => 'incoming',
            'last_seen_by_assignee_at' => null,
        ]);

        // Kontrol: percakapan pribadi 'perlu_dibalas' TETAP terhitung.
        $this->seedConversationPribadi([
            'status'                   => 'open',
            'last_message_direction'   => 'incoming',
            'last_seen_by_assignee_at' => null,
        ]);

        $res = $this->withSession($this->sesi())->get('inbox/api/perlu-dibalas-count');
        $res->assertOK();
        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('success', $data['status']);
        $this->assertSame(1, $data['count']);
    }

    // ---- AC-006: 403 pada endpoint aksi untuk grup -----------------------

    public function testAmbilPercakapanGrupDitolak403(): void
    {
        $id = $this->seedConversation(['assigned_to' => null]);

        $res = $this->withSession($this->sesi())
            ->post(self::PERCAKAPAN_URL . $id . '/ambil');

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertNull($this->conversation($id)['assigned_to']);
    }

    public function testLepasPercakapanGrupDitolak403(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        $res = $this->withSession($this->sesi())
            ->post(self::PERCAKAPAN_URL . $id . '/lepas');

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame(7, (int) $this->conversation($id)['assigned_to']);
    }

    public function testTutupPercakapanGrupDitolak403(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7, 'status' => 'open']);

        $res = $this->withSession($this->sesi())
            ->post(self::PERCAKAPAN_URL . $id . '/tutup');

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertSame('open', $this->conversation($id)['status']);
    }

    public function testSnoozePercakapanGrupDitolak403(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        $res = $this->withSession($this->sesi())
            ->withBodyFormat('json')
            ->post(self::PERCAKAPAN_URL . $id . '/snooze', ['menit' => 60]);

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertNull($this->conversation($id)['snoozed_until']);
    }

    public function testKonfirmasiNomorGrupDitolak403(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        $res = $this->withSession($this->sesi())
            ->post(self::PERCAKAPAN_URL . $id . '/konfirmasi-nomor', ['phone' => '628123456789']);

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertNull($this->conversation($id)['phone']);
    }

    public function testUpdateCustomerProfileGrupDitolak403(): void
    {
        $id = $this->seedConversation(['assigned_to' => 7]);

        $res = $this->withSession($this->sesi())
            ->post(self::PERCAKAPAN_URL . $id . '/profil', ['customer_name' => 'Nama Baru']);

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertNull($this->conversation($id)['contact_name']);
    }

    // ---- AC-009: hapusPercakapan() grup dikecualikan dari syarat closed --

    public function testHapusPercakapanGrupOlehAdminSuksesWalauBukanClosed(): void
    {
        $id = $this->seedConversation(['status' => 'open']);

        $res = $this->withSession($this->sesi('admin', 1))
            ->post(self::PERCAKAPAN_URL . $id . '/hapus');

        $res->assertOK();
        $res->assertJSONFragment(['status' => 'success']);
        $this->assertNotNull($this->conversation($id)['deleted_at']);
    }

    public function testHapusPercakapanGrupOlehKasirNonAdminTetapDitolak403(): void
    {
        $id = $this->seedConversation(['status' => 'open']);

        $res = $this->withSession($this->sesi('kasir', 7))
            ->post(self::PERCAKAPAN_URL . $id . '/hapus');

        $res->assertStatus(403);
        $res->assertJSONFragment(['status' => 'error']);
        $this->assertNull($this->conversation($id)['deleted_at']);
    }

    /**
     * Kontrol negatif: percakapan pribadi TETAP mensyaratkan status
     * closed (regresi nol, AC-007) -- membuktikan pengecualian REQ-007
     * benar-benar hanya berlaku untuk grup.
     */
    public function testHapusPercakapanPribadiTetapMensyaratkanClosed(): void
    {
        $id = $this->seedConversationPribadi(['status' => 'open']);

        $res = $this->withSession($this->sesi('admin', 1))
            ->post(self::PERCAKAPAN_URL . $id . '/hapus');

        $res->assertStatus(409);
        $this->assertNull($this->conversation($id)['deleted_at']);
    }

    // ---- AC-010: auto-assign tidak mengisi assigned_to untuk grup --------

    private function controllerUntukKirim(): InboxGrupKirimSpy
    {
        $db = db_connect('inbox');
        $db->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', []);

        $controller = new InboxGrupKirimSpy();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        return $controller;
    }

    public function testKirimTeksKeGrupTidakMengisiAssignedTo(): void
    {
        $id = $this->seedConversation(['assigned_to' => null]);

        $controller = $this->controllerUntukKirim();
        $method = (new ReflectionClass($controller))->getMethod('kirimKeConversation');
        $method->setAccessible(true);

        $_SESSION['id_user'] = 7;
        $_SESSION['role'] = 'kasir';

        $response = $method->invoke($controller, $this->conversation($id), 'Balasan ke grup');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->conversation($id)['assigned_to']);

        unset($_SESSION['id_user'], $_SESSION['role']);
    }

    private function fakeUploadedMedia(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'aulia-grup-media-');
        $this->assertIsString($path);

        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/wD/AH8AAAAASUVORK5CYII='));

        return new InboxGrupTestUploadedMedia($path, 'grup-test.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK);
    }

    public function testKirimMediaKeGrupTidakMengisiAssignedTo(): void
    {
        $id = $this->seedConversation(['assigned_to' => null]);

        $db = db_connect('inbox');
        $db->table('gateway_status')->insert([
            'id'                => 1,
            'status'            => 'connected',
            'last_heartbeat_at' => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setGlobal('post', ['conversation_id' => (string) $id, 'caption' => '']);

        $collection    = new FileCollection();
        $filesProperty = new \ReflectionProperty($collection, 'files');
        $filesProperty->setAccessible(true);
        $filesProperty->setValue($collection, ['media' => $this->fakeUploadedMedia()]);

        $requestFiles = new \ReflectionProperty($request, 'files');
        $requestFiles->setAccessible(true);
        $requestFiles->setValue($request, $collection);

        $controller = new InboxGrupKirimSpy();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        $_SESSION['id_user'] = 7;
        $_SESSION['role'] = 'kasir';

        $method = (new ReflectionClass($controller))->getMethod('kirimMedia');
        $method->setAccessible(true);
        $response = $method->invoke($controller);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->conversation($id)['assigned_to']);

        unset($_SESSION['id_user'], $_SESSION['role']);
    }
}

/**
 * Test-only upload whose isValid() no longer requires a real HTTP upload
 * (is_uploaded_file() is always false under CLI) -- sama pola dengan
 * InboxTestUploadedMedia di InboxOutgoingIdempotencyTest.php.
 */
final class InboxGrupTestUploadedMedia extends UploadedFile
{
    public function isValid(): bool
    {
        return true;
    }
}

final class InboxGrupKirimSpy extends Inbox
{
    protected function callGatewaySend(GatewayInboxConfig $config, string $chatId, string $text, ?string $operationId = null): array
    {
        return ['ok' => true, 'wa_message_id' => 'wa-grup-test-' . bin2hex(random_bytes(4))];
    }

    protected function callGatewaySendMedia(GatewayInboxConfig $config, string $chatId, string $mediaType, string $mediaBase64, ?string $mimetype, ?string $fileName, string $caption, ?string $operationId = null): array
    {
        return [
            'ok'            => true,
            'wa_message_id' => 'wa-grup-media-test-' . bin2hex(random_bytes(4)),
            'media_ref'     => null,
            'error_code'    => null,
            'state'         => 'sent',
            'replayed'      => false,
        ];
    }
}
