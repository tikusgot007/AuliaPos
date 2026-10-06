<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * POST /inbox/percakapan/(:num)/ambil -- perluasan izin take-over.
 *
 * Business rule (docs/CHANGELOG.md, 2026-10-06): non-admin boleh mengambil
 * percakapan bukan hanya saat unassigned, tapi juga saat owner-nya off-shift
 * (dengan grace 30 menit via last_seen_by_assignee_at) atau saat pengambil
 * adalah Shift Leader aktif.
 *
 * `conversations` ada di group `inbox` (aulia_inboxdb_test). `jadwal`/`users`
 * yang dibaca ownerOffShift()/Authority ada di group `default` -- dalam
 * ENVIRONMENT=testing group itu diarahkan ke SQLite :memory: (prefix db_),
 * jadi tabelnya di-forge per test di sini.
 *
 * @internal
 */
final class InboxAmbilAlihTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('conversations')->emptyTable();

        // Tabel kasir (group default -> tests SQLite). Di-forge ulang tiap
        // test supaya data jadwal/priority tidak bocor antar-test.
        $forge = \Config\Database::forge();
        $forge->dropTable('jadwal', true);
        $forge->dropTable('users', true);

        $forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true],
            'karyawan_id' => ['type' => 'INTEGER'],
            'tanggal'     => ['type' => 'TEXT'],
            'shift'       => ['type' => 'TEXT'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('jadwal', true);

        $forge->addField([
            'id'       => ['type' => 'INTEGER', 'auto_increment' => true],
            'username' => ['type' => 'TEXT'],
            'nama'     => ['type' => 'TEXT', 'null' => true],
            'inisial'  => ['type' => 'TEXT', 'null' => true],
            'role'     => ['type' => 'TEXT', 'default' => 'kasir'],
            'is_active' => ['type' => 'INTEGER', 'default' => 1],
            'priority' => ['type' => 'INTEGER', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('jadwal', true);
        $forge->dropTable('users', true);

        parent::tearDown();
    }

    private function seedUser(int $id, string $role = 'kasir', ?int $priority = null): void
    {
        db_connect()->table('users')->insert([
            'id'       => $id,
            'username' => 'user' . $id,
            'nama'     => 'User ' . $id,
            'inisial'  => 'U' . $id,
            'role'     => $role,
            'is_active' => 1,
            'priority' => $priority,
        ]);
    }

    private function seedJadwal(int $karyawanId, string $shift): void
    {
        db_connect()->table('jadwal')->insert([
            'karyawan_id' => $karyawanId,
            'tanggal'     => date('Y-m-d'),
            'shift'       => $shift,
        ]);
    }

    private function seedConversation(array $override = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'                => '6281200009' . random_int(1000, 9999) . '@s.whatsapp.net',
            'jid_type'               => 'pn',
            'status'                 => 'open',
            'last_message_direction' => 'incoming',
            'last_message_at'        => $now,
            'assigned_to'            => null,
            'created_at'             => $now,
            'updated_at'             => $now,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function ambil(int $userId, string $role, int $conversationId)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->post('/inbox/percakapan/' . $conversationId . '/ambil');
    }

    private function ownerOf(int $conversationId): ?int
    {
        $row = $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray();

        return $row['assigned_to'] !== null ? (int) $row['assigned_to'] : null;
    }

    /** Apakah jam server (Asia/Jakarta) berada di dalam jendela shift ini. */
    private function sedangDalamShift(string $shift): bool
    {
        return \App\Services\EvaluasiJendelaKerjaShift::sedangBekerja($shift, date('H:i'));
    }

    /** Skenario 1: unassigned -> klaim biasa. */
    public function testUnassignedBolehDiambil(): void
    {
        $conv = $this->seedConversation();
        $this->seedUser(10);

        $response = $this->ambil(10, 'kasir', $conv);

        $response->assertStatus(200);
        $this->assertSame(10, $this->ownerOf($conv));
    }

    /**
     * Skenario 2: owner on-shift -> staf lain ditolak.
     *
     * Memakai shift 'S' (13:30-20:30) dan jam server nyata. Kalau test
     * dijalankan di luar semua jendela shift (mis. dini hari), owner
     * dianggap off-shift sehingga kasus "on-shift ditolak" tidak bisa
     * diuji; saat itu test di-skip, bukan gagal.
     */
    public function testOwnerOnShiftTidakBisaDiambilStafLain(): void
    {
        if (!$this->sedangDalamShift('S')) {
            $this->markTestSkipped('Di luar jam shift S (13:30-20:30); butuh jam server di dalam shift.');
        }

        $this->seedUser(11);
        $this->seedUser(12);
        $this->seedJadwal(11, 'S');
        $conv = $this->seedConversation(['assigned_to' => 11]);

        $response = $this->ambil(12, 'kasir', $conv);

        $response->assertStatus(403);
        $this->assertSame(11, $this->ownerOf($conv));
    }

    /** Skenario 3: owner off-shift (Libur) + grace lewat -> takeover boleh. */
    public function testOwnerLiburBisaDiambilAlih(): void
    {
        $this->seedUser(11);
        $this->seedUser(12);
        $this->seedJadwal(11, 'L');
        $conv = $this->seedConversation([
            'assigned_to'              => 11,
            'last_seen_by_assignee_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
        ]);

        $response = $this->ambil(12, 'kasir', $conv);

        $response->assertStatus(200);
        $this->assertSame(12, $this->ownerOf($conv));
    }

    /** Skenario 4: owner off-shift TAPI masih dalam grace 30 menit -> ditolak. */
    public function testGraceBelumHabisDitolak(): void
    {
        $this->seedUser(11);
        $this->seedUser(12);
        $this->seedJadwal(11, 'L');
        $conv = $this->seedConversation([
            'assigned_to'              => 11,
            'last_seen_by_assignee_at' => date('Y-m-d H:i:s', strtotime('-10 minutes')),
        ]);

        $response = $this->ambil(12, 'kasir', $conv);

        $response->assertStatus(403);
        $this->assertSame(11, $this->ownerOf($conv));
    }

    /** Skenario 5: Shift Leader aktif boleh takeover walau owner on-shift. */
    public function testShiftLeaderBisaAmbilAlihSaatOwnerOnShift(): void
    {
        if (!$this->sedangDalamShift('S')) {
            $this->markTestSkipped('Di luar jam shift S (13:30-20:30); butuh jam server di dalam shift.');
        }

        $this->seedUser(11, 'kasir', 10); // staf biasa (bukan leader)
        $this->seedUser(20, 'kasir', 90); // priority tertinggi = leader
        // Owner & leader sama-sama shift 'S' (13:30-20:30); test hanya
        // bermakna saat jam server di dalam jendela itu (lihat guard skip).
        $this->seedJadwal(11, 'S');
        $this->seedJadwal(20, 'S');
        $conv = $this->seedConversation([
            'assigned_to'              => 11,
            'last_seen_by_assignee_at' => date('Y-m-d H:i:s'),
        ]);

        $response = $this->ambil(20, 'kasir', $conv);

        $response->assertStatus(200);
        $this->assertSame(20, $this->ownerOf($conv));
    }

    /** Skenario 6: grup tidak pernah bisa diambil (CON-004). */
    public function testGrupTidakBisaDiambil(): void
    {
        $this->seedUser(12);
        $conv = $this->seedConversation(['jid_type' => 'group']);

        $response = $this->ambil(12, 'kasir', $conv);

        $response->assertStatus(403);
        $this->assertNull($this->ownerOf($conv));
    }

    /** Skenario 7: admin selalu boleh override (regresi). */
    public function testAdminSelaluBolehOverride(): void
    {
        $this->seedUser(11);
        $this->seedUser(1, 'admin');
        $this->seedJadwal(11, 'P');
        $conv = $this->seedConversation([
            'assigned_to'              => 11,
            'last_seen_by_assignee_at' => date('Y-m-d H:i:s'),
        ]);

        $response = $this->ambil(1, 'admin', $conv);

        $response->assertStatus(200);
        $this->assertSame(1, $this->ownerOf($conv));
    }

    /** Idempotent: sudah milik sendiri -> sukses tanpa ubah apa pun (regresi). */
    public function testSudahMilikSendiriSuksesIdempotent(): void
    {
        $this->seedUser(11);
        $this->seedUser(12);
        $this->seedJadwal(11, 'L');
        $conv = $this->seedConversation(['assigned_to' => 11]);

        $response = $this->ambil(11, 'kasir', $conv);

        $response->assertStatus(200);
        $this->assertSame(11, $this->ownerOf($conv));
    }
}
