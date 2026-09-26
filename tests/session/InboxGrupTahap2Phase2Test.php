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
 * Phase 2 (Vertical Slice B: identitas pengirim per pesan + gerbang 400).
 *
 * Mencakup:
 * - TASK-006 (REQ-010, AC-009): pesan grup MASUK tanpa `sender_jid`
 *   ditolak 400 SEBELUM transaksi -- tanpa baris `messages`, tanpa
 *   perubahan/penciptaan `conversations` (termasuk `group_name`).
 *   Lingkup INCOMING saja; non-grup dan grup outgoing tidak berubah.
 * - TASK-007 (REQ-008/REQ-009, CON-003): label `sender_name` per pesan --
 *   nomor untuk `@s.whatsapp.net`, `'LID'` untuk `@lid`/`*.lid`, `'Pengirim'`
 *   untuk format lain (TIDAK PERNAH JID mentah); NULL -> tanpa label;
 *   outgoing POS -> nama staff; outgoing sinkron -> 'Staff (WA Web/HP)';
 *   percakapan pribadi tidak berubah.
 * - TASK-401..403 (REQ-011/AC-012, spec v1.4): JID grup `@g.us` (nilai legacy
 *   non-NULL) -> tanpa identitas/tanpa label; nilai DB tidak diubah.
 *
 * @internal
 */
final class InboxGrupTahap2Phase2Test extends CIUnitTestCase
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
        db_connect()->table('users')->emptyTable();
    }

    /** @return array{http: int, body: array} */
    private function callMessages(array $payload): array
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));

        $controller = new InboxGatewayApi();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        $response = $controller->messages();

        return [
            'http' => $response->getStatusCode(),
            'body' => json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function groupPayload(string $chatId, string $waId, string $direction = 'incoming', bool $withSender = true, ?string $groupName = null): array
    {
        $payload = [
            'wa_message_id'     => $waId,
            'chat_id'           => $chatId,
            'jid_type'          => 'group',
            'message_type'      => 'text',
            'text'              => 'halo grup',
            'message_timestamp' => '2026-09-26 10:00:00',
            'direction'         => $direction,
        ];

        if ($withSender) {
            $payload['sender_jid'] = '628111111111@s.whatsapp.net';
        }
        if ($groupName !== null) {
            $payload['group_name'] = $groupName;
        }

        return $payload;
    }

    private function conversationCount(string $chatId): int
    {
        return db_connect('inbox')->table('conversations')->where('chat_id', $chatId)->countAllResults();
    }

    private function seedConversation(array $override = []): int
    {
        $db  = db_connect('inbox');
        $now = '2026-09-26 10:00:00';

        $db->table('conversations')->insert(array_merge([
            'chat_id'                => '120363' . random_int(100000000, 999999999) . '@g.us',
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

    private function seedMessage(int $conversationId, array $override = []): void
    {
        db_connect('inbox')->table('messages')->insert(array_merge([
            'conversation_id'   => $conversationId,
            'wa_message_id'     => 'seed-' . bin2hex(random_bytes(6)),
            'direction'         => 'incoming',
            'message_type'      => 'text',
            'sender_jid'        => null,
            'text'              => 'pesan uji',
            'message_timestamp' => '2026-09-26 10:00:00',
            'sent_by_user_id'   => null,
            'send_status'       => 'received',
            'is_internal'       => false,
            'created_at'        => '2026-09-26 10:00:00',
        ], $override));
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

    /**
     * GET thread and index the messages by wa_message_id.
     *
     * @return array<string, array>
     */
    private function threadById(int $conversationId): array
    {
        $res = $this->withSession($this->sesi())->get('inbox/api/conversations/' . $conversationId . '/messages');
        $res->assertOK();

        $data = json_decode($res->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $data['status']);

        $byId = [];
        foreach ($data['messages'] as $message) {
            $byId[$message['wa_message_id']] = $message;
        }

        return $byId;
    }

    // ---- TASK-006: gerbang 400 -------------------------------------------

    public function testGrupIncomingTanpaSenderJidDitolak400TanpaJejakTersimpan(): void
    {
        $chatId = '120363000000000201@g.us';

        $res = $this->callMessages($this->groupPayload($chatId, 'grup-400-1', 'incoming', false, 'Grup Tidak Boleh Tersimpan'));

        $this->assertSame(400, $res['http']);
        $this->assertSame('error', $res['body']['status']);

        // Ditolak SEBELUM transaksi: conversation pun tidak dibuat, jadi
        // tidak ada group_name/jejak apa pun yang tersimpan.
        $this->assertSame(0, $this->conversationCount($chatId), 'Tidak boleh ada conversation baru.');
    }

    public function testGrupIncomingDenganSenderJidDiterimaDanTersimpan(): void
    {
        $chatId = '120363000000000202@g.us';

        $res = $this->callMessages($this->groupPayload($chatId, 'grup-400-ok', 'incoming', true, 'Grup Simpan'));

        $this->assertSame(200, $res['http']);
        $this->assertSame('success', $res['body']['status']);

        $conversation = db_connect('inbox')->table('conversations')->where('chat_id', $chatId)->get()->getRowArray();
        $this->assertNotNull($conversation);
        $this->assertSame('Grup Simpan', $conversation['group_name']);

        $message = db_connect('inbox')->table('messages')->where('conversation_id', $conversation['id'])->get()->getRowArray();
        $this->assertSame('628111111111@s.whatsapp.net', $message['sender_jid'], 'AC-001: sender_jid tersimpan persis.');
    }

    public function testGroupNameTerlaluPanjangTerpotong255TanpaError(): void
    {
        // SEC-01/TASK-303: `group_name` input tak tepercaya dibatasi di boundary
        // (VARCHAR(255)) -- tidak error, tidak tersimpan lebih dari 255.
        $chatId = '120363000000000210@g.us';
        $panjang = str_repeat('N', 300);

        $res = $this->callMessages($this->groupPayload($chatId, 'grup-panjang-1', 'incoming', true, $panjang));

        $this->assertSame(200, $res['http']);
        $stored = db_connect('inbox')->table('conversations')->where('chat_id', $chatId)->get()->getRowArray()['group_name'];
        $this->assertSame(255, mb_strlen($stored), 'group_name harus terpotong ke 255 karakter.');
        $this->assertSame(mb_substr($panjang, 0, 255), $stored);
    }

    public function testNonGrupTanpaSenderJidTidakBerubah(): void
    {
        // CON-001/REQ-010: guard hanya untuk grup incoming.
        $chatId = '6281200000200@s.whatsapp.net';

        $res = $this->callMessages([
            'wa_message_id'     => 'pn-no-sender-1',
            'chat_id'           => $chatId,
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'halo',
            'message_timestamp' => '2026-09-26 10:00:00',
            'direction'         => 'incoming',
        ]);

        $this->assertSame(200, $res['http']);
        $this->assertSame('success', $res['body']['status']);
    }

    public function testGrupOutgoingTanpaSenderJidTetapDiterima(): void
    {
        // Keputusan pemilik 2026-09-26 / AC-011: outgoing sinkron WA Web/HP
        // (sender_jid NULL) tetap diproses.
        $chatId = '120363000000000203@g.us';

        $res = $this->callMessages($this->groupPayload($chatId, 'grup-out-1', 'outgoing', false));

        $this->assertSame(200, $res['http']);
        $this->assertSame('success', $res['body']['status']);

        $conversation = db_connect('inbox')->table('conversations')->where('chat_id', $chatId)->get()->getRowArray();
        $message = db_connect('inbox')->table('messages')->where('conversation_id', $conversation['id'])->get()->getRowArray();
        $this->assertNull($message['sender_jid']);
    }

    // ---- TASK-007: label identitas pengirim ------------------------------

    public function testPesanGrupIncomingMenampilkanNomorTelepon(): void
    {
        $id = $this->seedConversation();
        $this->seedMessage($id, [
            'wa_message_id' => 'label-pn-1',
            'sender_jid'    => '628123456789@s.whatsapp.net',
        ]);

        $message = $this->threadById($id)['label-pn-1'];
        $this->assertSame('628123456789', $message['sender_name']);
    }

    public function testLabelNomorMembersihkanSufiksDevice(): void
    {
        // TASK-301/CORR-03: key.participant dapat membawa sufiks device
        // (`:NN`); nomor telepon yang tampil harus bersih.
        $id = $this->seedConversation();
        $this->seedMessage($id, [
            'wa_message_id' => 'label-device-1',
            'sender_jid'    => '6281234567890:12@s.whatsapp.net',
        ]);

        $this->assertSame('6281234567890', $this->threadById($id)['label-device-1']['sender_name']);
    }

    public function testLabelLidDanFallbackPengirimTidakPernahJidMentah(): void
    {
        $id = $this->seedConversation();

        $kasus = [
            'label-lid-1'    => ['sender_jid' => '999888777666@lid', 'harapan' => 'LID'],
            'label-lid-2'    => ['sender_jid' => '123456@hosted.lid', 'harapan' => 'LID'],
            'label-unknown'  => ['sender_jid' => 'aneh@hosted.example', 'harapan' => 'Pengirim'],
            // REQ-011/AC-012 (spec v1.4): `@g.us` -> tanpa identitas (null),
            // bukan 'Pengirim'.
            'label-grup-jid' => ['sender_jid' => '120363@g.us', 'harapan' => null],
            'label-broken'   => ['sender_jid' => 'tanpa-at', 'harapan' => 'Pengirim'],
        ];

        foreach ($kasus as $waId => $info) {
            $this->seedMessage($id, ['wa_message_id' => $waId, 'sender_jid' => $info['sender_jid']]);
        }

        $thread = $this->threadById($id);

        foreach ($kasus as $waId => $info) {
            $this->assertSame($info['harapan'], $thread[$waId]['sender_name'], "Label untuk {$waId}");
            $this->assertNotSame($info['sender_jid'], $thread[$waId]['sender_name'], 'JANGAN pernah merender JID mentah.');
        }
    }

    public function testPesanGrupLamaTanpaSenderJidTidakBerlabel(): void
    {
        // CON-003/AC-006: sender_jid NULL -> tanpa baris label.
        $id = $this->seedConversation();
        $this->seedMessage($id, ['wa_message_id' => 'label-null-1', 'sender_jid' => null]);

        $this->assertNull($this->threadById($id)['label-null-1']['sender_name']);
    }

    public function testPesanGrupLamaBerSenderJidGrupTidakBerlabelDanDbTidakBerubah(): void
    {
        // REQ-011/AC-012 (spec v1.4): baris legacy NYATA berisi JID grup
        // (`@g.us`), BUKAN NULL (Gateway lama mengisi sender_jid = remoteJid).
        // Harus tampil tanpa label, tanpa merender JID mentah, dan nilai
        // tersimpan TIDAK diubah oleh jalur baca (tanpa UPDATE/migrasi).
        $jid = '120363012345678901@g.us';

        $id = $this->seedConversation();
        $this->seedMessage($id, ['wa_message_id' => 'label-legacy-gus-1', 'sender_jid' => $jid]);

        $message = $this->threadById($id)['label-legacy-gus-1'];
        $this->assertNull($message['sender_name'], 'AC-012: legacy @g.us -> tanpa label.');
        $this->assertNotSame($jid, $message['sender_name'], 'JID grup tidak pernah dirender.');

        $stored = db_connect('inbox')->table('messages')
            ->where('wa_message_id', 'label-legacy-gus-1')->get()->getRowArray()['sender_jid'];
        $this->assertSame($jid, $stored, 'AC-012: nilai DB legacy tidak berubah.');
    }

    public function testOutgoingPosMenampilkanNamaStaff(): void
    {
        // AC-007: nama staff via sent_by_user_id.
        db_connect()->table('users')->insert([
            'id' => 7, 'username' => 'user7', 'nama' => 'Kasir Tujuh', 'role' => 'kasir', 'is_active' => 1,
        ]);

        $id = $this->seedConversation();
        $this->seedMessage($id, [
            'wa_message_id'   => 'label-staff-1',
            'direction'       => 'outgoing',
            'sender_jid'      => null,
            'sent_by_user_id' => 7,
            'send_status'     => 'sent',
        ]);

        $this->assertSame('Kasir Tujuh', $this->threadById($id)['label-staff-1']['sender_name']);
    }

    public function testOutgoingSinkronMenampilkanStaffWaWebHp(): void
    {
        // AC-011: outgoing tanpa sent_by_user_id -> label tetap.
        $id = $this->seedConversation();
        $this->seedMessage($id, [
            'wa_message_id' => 'label-sync-1',
            'direction'     => 'outgoing',
            'sender_jid'    => null,
            'send_status'   => 'sent',
        ]);

        $this->assertSame('Staff (WA Web/HP)', $this->threadById($id)['label-sync-1']['sender_name']);
    }

    public function testPercakapanPribadiTidakBerubah(): void
    {
        // AC-008: pada percakapan pribadi, label sender_name tidak berubah
        // (incoming tanpa sent_by_user_id -> null, bukan label grup).
        $id = $this->seedConversation([
            'chat_id'  => '6281200000300@s.whatsapp.net',
            'jid_type' => 'pn',
        ]);
        $this->seedMessage($id, ['wa_message_id' => 'label-pribadi-1', 'sender_jid' => '628999888777@s.whatsapp.net']);

        $this->assertNull($this->threadById($id)['label-pribadi-1']['sender_name']);
    }
}
