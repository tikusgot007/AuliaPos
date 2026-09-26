<?php

use App\Controllers\InboxGatewayApi;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Psr\Log\NullLogger;

/**
 * Grup Tahap 2 (spec-design-grup-tahap2-identitas v1.3) -- sisi AuliaPos,
 * Phase 1 (Vertical Slice A: judul grup asli & stabil / write-once).
 *
 * Mencakup:
 * - TASK-002 (REQ-005/REQ-006, GUD-002): `group_name` ditulis WRITE-ONCE,
 *   pada conversation yang baru dibuat (`created=true`) maupun yang sudah
 *   ada, hanya saat `jid_type='group'` dan kolom masih NULL.
 * - CON-001: `jid_type` selain 'group' tidak pernah menyentuh kolom itu.
 * - TASK-003 (REQ-007): daftar & JS menampilkan `group_name ?: 'Grup'`,
 *   TIDAK PERNAH memakai whatsapp_name/identitas pengirim untuk grup.
 *
 * Guard 400 (`sender_jid` wajib) adalah Phase 2 (TASK-006) dan tidak diuji
 * di sini -- Phase 1 harus tetap additive terhadap Gateway lama.
 *
 * @internal
 */
final class InboxGrupTahap2Test extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversation_identities')->emptyTable();
        $db->table('conversations')->emptyTable();
        $db->table('gateway_status')->emptyTable();
    }

    private function callMessages(array $payload): array
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));

        $controller = new InboxGatewayApi();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        $response = $controller->messages();

        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function groupPayload(string $chatId, string $waId, ?string $groupName): array
    {
        $payload = [
            'wa_message_id'     => $waId,
            'chat_id'           => $chatId,
            'jid_type'          => 'group',
            'message_type'      => 'text',
            'text'              => 'halo grup',
            'message_timestamp' => '2026-09-26 10:00:00',
            'direction'         => 'incoming',
            'sender_jid'        => '628111111111@s.whatsapp.net',
        ];

        if ($groupName !== null) {
            $payload['group_name'] = $groupName;
        }

        return $payload;
    }

    private function conversationRow(string $chatId): array
    {
        return db_connect('inbox')->table('conversations')->where('chat_id', $chatId)->get()->getRowArray();
    }

    private function seedConversation(array $override = []): int
    {
        $db  = db_connect('inbox');
        $now = '2026-09-26 10:00:00';

        $db->table('conversations')->insert(array_merge([
            'chat_id'                => '628' . random_int(100000000, 999999999) . '-' . random_int(1000000, 9999999) . '@g.us',
            'jid_type'               => 'group',
            'contact_name'           => null,
            'whatsapp_name'          => null,
            'group_name'             => null,
            'phone'                  => null,
            'status'                 => 'open',
            'assigned_to'            => null,
            'snoozed_until'          => null,
            'last_message_at'        => $now,
            'last_message_direction' => 'incoming',
            'created_at'             => $now,
            'updated_at'             => $now,
        ], $override));

        return (int) $db->insertID();
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

    private function halamanInbox(): string
    {
        $response = $this->withSession($this->sesi())->get('inbox');
        $response->assertOK();

        return (string) $response->getBody();
    }

    // ---- TASK-002: group_name write-once ---------------------------------

    public function testPercakapanGrupBaruMengisiGroupNamePadaRequestItu(): void
    {
        // created=true: conversation dibuat dalam request ini, group_name
        // harus langsung terisi (tidak ditunda ke pesan berikutnya).
        $chatId = '120363000000000101@g.us';

        $body = $this->callMessages($this->groupPayload($chatId, 'grup-new-1', 'Grup Jualan Online'));
        $this->assertSame('success', $body['status']);

        $this->assertSame('Grup Jualan Online', $this->conversationRow($chatId)['group_name']);
    }

    public function testGroupNameTidakDitimpaSetelahTerisi(): void
    {
        // REQ-006 write-once: nilai berikutnya (termasuk nama berbeda)
        // TIDAK menimpa.
        $chatId = '120363000000000102@g.us';

        $this->callMessages($this->groupPayload($chatId, 'grup-wo-1', 'Nama Pertama'));
        $this->callMessages($this->groupPayload($chatId, 'grup-wo-2', 'Nama Berubah'));

        $this->assertSame('Nama Pertama', $this->conversationRow($chatId)['group_name']);
    }

    public function testGroupNameTerisiSaatKolomMasihNull(): void
    {
        // Jalur update (!created): nama belum diketahui di pesan pertama,
        // terisi saat pesan berikutnya membawa nama valid (REQ-003 sisi
        // Gateway memberi kesempatan berulang).
        $chatId = '120363000000000103@g.us';

        $this->callMessages($this->groupPayload($chatId, 'grup-fill-1', null));
        $this->assertNull($this->conversationRow($chatId)['group_name']);

        $this->callMessages($this->groupPayload($chatId, 'grup-fill-2', 'Grup Terisi Kemudian'));
        $this->assertSame('Grup Terisi Kemudian', $this->conversationRow($chatId)['group_name']);

        // Pesan ketiga dengan nama lain tetap tidak menimpa.
        $this->callMessages($this->groupPayload($chatId, 'grup-fill-3', 'Nama Lain'));
        $this->assertSame('Grup Terisi Kemudian', $this->conversationRow($chatId)['group_name']);
    }

    public function testGroupNameTidakPernahDitulisUntukPercakapanPribadi(): void
    {
        // CON-001: hanya jid_type='group' yang boleh menyentuh kolom ini.
        $chatId = '6281200000099@s.whatsapp.net';

        $body = $this->callMessages([
            'wa_message_id'     => 'pn-group-name-1',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'halo pribadi',
            'message_timestamp' => '2026-09-26 10:00:00',
            'direction'         => 'incoming',
            'group_name'        => 'Ini Bukan Untuk Grup',
        ]);
        $this->assertSame('success', $body['status']);

        $this->assertNull($this->conversationRow($chatId)['group_name']);
    }

    public function testGroupNameAbsenMembiarkanKolomTetapNull(): void
    {
        // GUD-002/REQ-002: Gateway lama (tanpa field group_name) tidak boleh
        // membuat AuliaPos error; kolom cukup tetap NULL.
        $chatId = '120363000000000104@g.us';

        $body = $this->callMessages($this->groupPayload($chatId, 'grup-absent-1', null));
        $this->assertSame('success', $body['status']);

        $this->assertNull($this->conversationRow($chatId)['group_name']);
    }

    // ---- TASK-003: judul grup di layar ------------------------------------

    public function testBarisDaftarMenampilkanGroupNameDanFallbackGrup(): void
    {
        // REQ-007: judul = group_name bila ada, kalau NULL -> "Grup"
        // generik. TIDAK PERNAH whatsapp_name/identitas pengirim terakhir.
        $this->seedConversation([
            'chat_id'       => '120363000000000105@g.us',
            'group_name'    => 'Grup Jualan Online',
            'whatsapp_name' => 'Pengirim Terakhir A',
        ]);
        $this->seedConversation([
            'chat_id'       => '120363000000000106@g.us',
            'group_name'    => null,
            'whatsapp_name' => 'Nama Pengirim Terakhir B',
        ]);
        // Kontrol: percakapan pribadi tetap memakai nama kontaknya (AC-008).
        $this->seedConversation([
            'chat_id'       => '6281200000100@s.whatsapp.net',
            'jid_type'      => 'pn',
            'contact_name'  => 'Pelanggan Pribadi',
            'group_name'    => null,
            'whatsapp_name' => 'WA Pribadi',
        ]);

        $body = $this->halamanInbox();

        $this->assertStringContainsString('<span class="list-name">Grup Jualan Online</span>', $body);
        $this->assertStringContainsString('<span class="list-name">Grup</span>', $body);
        // Judul grup TIDAK boleh memakai whatsapp_name. Dicek pada markup
        // baris daftar (bukan seluruh halaman: `daftarConversation` di-embed
        // sebagai JSON mentah di baris ~728 dan memang memuat whatsapp_name --
        // nilai itu tidak pernah ditampilkan untuk grup).
        $this->assertStringNotContainsString('<span class="list-name">Nama Pengirim Terakhir B</span>', $body);
        $this->assertStringContainsString('<span class="list-name">Pelanggan Pribadi</span>', $body, 'Percakapan pribadi tidak berubah.');
    }

    public function testApiDaftarMembawaGroupNameUntukRenderJs(): void
    {
        // TASK-501/CC-01: jalur JS (`renderDaftarConversation` untuk baris
        // daftar, `formatIdentitasCustomer` untuk header thread) bekerja dari
        // data respons API. Uji KONTRAK DATA-nya -- bukan teks sumber JS --
        // supaya tidak rapuh terhadap perubahan format kode. Perilaku
        // fallback grup/`whatsapp_name` diuji lewat `tests/js/...check.js`.
        $this->seedConversation([
            'chat_id'    => '120363000000000107@g.us',
            'group_name' => 'Grup Api',
        ]);
        $this->seedConversation([
            'chat_id'      => '6281200000101@s.whatsapp.net',
            'jid_type'     => 'pn',
            'contact_name' => 'Pelanggan Api',
            'group_name'   => null,
        ]);

        $response = $this->withSession($this->sesi())->get('inbox/api/conversations');
        $response->assertOK();

        $data = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $data['status']);

        $byChatId = [];
        foreach ($data['conversations'] as $conversation) {
            $byChatId[$conversation['chat_id']] = $conversation;
        }

        $this->assertSame('Grup Api', $byChatId['120363000000000107@g.us']['group_name']);
        $this->assertSame('Pelanggan Api', $byChatId['6281200000101@s.whatsapp.net']['contact_name']);
        $this->assertNull($byChatId['6281200000101@s.whatsapp.net']['group_name']);
    }
}
