<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * TODO-F9 / Fase 2 -- endpoint POST /inbox/percakapan/(:num)/nudge-unduh:
 * catat note internal "[auto] ..." ketika kasir memilih "[Unduh saja]" pada
 * percakapan belum_diambil, dengan coalesce 30 menit per user+conversation.
 *
 * Regresi yang dijaga:
 *  - note ditulis sebagai is_internal=1, direction=outgoing (tidak dikirim ke
 *    WhatsApp) dan kolom `conversations` TIDAK berubah (assigned_to tetap
 *    NULL, last_message_at/direction tetap);
 *  - klik berulang user sama dalam 30 menit -> hanya satu note;
 *  - user berbeda -> note terpisah;
 *  - grup ditolak (403), percakapan closed ditolak (409), id tak ada (404).
 *
 * @internal
 */
final class InboxNudgeUnduhTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();

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
        \Config\Database::forge()->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    private function seedUser(int $id, string $role = 'kasir'): void
    {
        db_connect()->table('users')->insert([
            'id' => $id, 'username' => 'user' . $id, 'nama' => 'User ' . $id,
            'inisial' => 'U' . $id, 'role' => $role, 'is_active' => 1, 'priority' => null,
        ]);
    }

    private function chatId(string $domain = '@s.whatsapp.net'): string
    {
        return '62812009' . random_int(100000, 999999) . $domain;
    }

    private function rute(): void
    {
        $this->withRoutes([
            ['POST', 'inbox/percakapan/(:num)/nudge-unduh', '\App\Controllers\Inbox::catatNudgeUnduh/$1', ['filter' => 'auth']],
        ]);
    }

    private function panggil(int $userId, int $conversationId, string $role = 'kasir')
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->post('/inbox/percakapan/' . $conversationId . '/nudge-unduh');
    }

    private function json($response): array
    {
        $decoded = json_decode(trim(strip_tags($response->getBody())), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function jumlahNote(int $conversationId, ?int $userId = null): int
    {
        $builder = $this->inbox->table('messages')
            ->where('conversation_id', $conversationId)
            ->where('is_internal', 1);

        if ($userId !== null) {
            $builder->where('sent_by_user_id', $userId);
        }

        return $builder->countAllResults();
    }

    public function testMembuatNoteDanTidakMengubahConversation(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConversation($this->chatId());
        $sebelum = $this->inbox->table('conversations')->where('id', $conv)->get()->getRowArray();
        $this->rute();

        $hasil = $this->panggil(21, $conv);
        $hasil->assertStatus(200);
        $this->assertTrue($this->json($hasil)['ok']);

        $note = $this->inbox->table('messages')->where('conversation_id', $conv)->get()->getRowArray();
        $this->assertNotNull($note);
        $this->assertSame(1, (int) $note['is_internal']);
        $this->assertSame('outgoing', $note['direction']);
        $this->assertSame('text', $note['message_type']);
        $this->assertSame(21, (int) $note['sent_by_user_id']);
        $this->assertStringStartsWith('[auto] ', $note['text']);
        $this->assertStringContainsString('U21', $note['text']);

        // conversations TIDAK tersentuh sama sekali.
        $sesudah = $this->inbox->table('conversations')->where('id', $conv)->get()->getRowArray();
        $this->assertNull($sesudah['assigned_to']);
        $this->assertSame($sebelum['last_message_at'], $sesudah['last_message_at']);
        $this->assertSame($sebelum['last_message_direction'], $sesudah['last_message_direction']);
    }

    public function testCoalesce30MenitUserSama(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConversation($this->chatId());
        $this->rute();

        $this->panggil(21, $conv)->assertStatus(200);
        $kedua = $this->panggil(21, $conv);
        $kedua->assertStatus(200);

        $json = $this->json($kedua);
        $this->assertFalse($json['ok']);
        $this->assertSame('duplicate', $json['reason']);
        $this->assertSame(1, $this->jumlahNote($conv, 21));
    }

    public function testNoteLebih30MenitTidakCoalesce(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConversation($this->chatId());

        // Note lama (31 menit lalu) oleh user yang sama -> di luar window
        // coalesce, jadi panggilan berikutnya HARUS membuat note baru.
        $lama = (new \DateTime('-31 minutes', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $this->inbox->table('messages')->insert([
            'conversation_id'   => $conv,
            'wa_message_id'     => 'internal-' . $conv . '-lama',
            'direction'         => 'outgoing',
            'message_type'      => 'text',
            'text'              => '[auto] U21 mengunduh lampiran tanpa mengambil percakapan.',
            'message_timestamp' => $lama,
            'sent_by_user_id'   => 21,
            'send_status'       => 'sent',
            'is_internal'       => 1,
            'created_at'        => $lama,
        ]);
        $this->rute();

        $hasil = $this->panggil(21, $conv);
        $hasil->assertStatus(200);
        $this->assertTrue($this->json($hasil)['ok']);
        $this->assertSame(2, $this->jumlahNote($conv, 21));
    }

    public function testUserBerbedaTetapDicatat(): void
    {
        $this->seedUser(21);
        $this->seedUser(22);
        $conv = $this->seedConversation($this->chatId());
        $this->rute();

        $this->panggil(21, $conv)->assertStatus(200);
        $this->panggil(22, $conv)->assertStatus(200);

        $this->assertSame(1, $this->jumlahNote($conv, 21));
        $this->assertSame(1, $this->jumlahNote($conv, 22));
        $this->assertSame(2, $this->jumlahNote($conv));
    }

    public function testGrupDitolak(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConversation($this->chatId('@g.us'));
        $this->inbox->table('conversations')->where('id', $conv)->update(['jid_type' => 'group']);
        $this->rute();

        $this->panggil(21, $conv)->assertStatus(403);
        $this->assertSame(0, $this->jumlahNote($conv));
    }

    public function testClosedDitolak(): void
    {
        $this->seedUser(21);
        $conv = $this->seedConversation($this->chatId());
        $this->inbox->table('conversations')->where('id', $conv)->update(['status' => 'closed']);
        $this->rute();

        $this->panggil(21, $conv)->assertStatus(409);
        $this->assertSame(0, $this->jumlahNote($conv));
    }

    public function testDitolakBilaSudahPunyaPemilik(): void
    {
        $this->seedUser(21);
        $this->seedUser(22);
        $conv = $this->seedConversation($this->chatId());
        // Percakapan sudah diklaim kasir lain -- klien kasir 21 boleh saja
        // masih menganggapnya belum_diambil (stale), tapi server menolak.
        $this->inbox->table('conversations')->where('id', $conv)->update(['assigned_to' => 22]);
        $this->rute();

        $hasil = $this->panggil(21, $conv);
        $hasil->assertStatus(409);

        $json = $this->json($hasil);
        $this->assertFalse($json['ok']);
        $this->assertSame('already_assigned', $json['reason']);
        $this->assertSame(0, $this->jumlahNote($conv));
    }

    public function testTidakDitemukan(): void
    {
        $this->seedUser(21);
        $this->rute();

        $this->panggil(21, 999999)->assertStatus(404);
    }
}
