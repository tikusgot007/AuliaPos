<?php

use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * Inbox -> Kasir: POST /inbox/percakapan/(:num)/buat-pesanan.
 *
 * Maps docs/requirements/2026-10-16-inbox-ke-kasir.md AC-1..AC-11.
 *
 * Two databases are involved, exactly like production:
 * - `conversations` lives in group `inbox` (real MariaDB `aulia_inboxdb_test`).
 * - `pelanggan` / `users` live in group `default`, which ENVIRONMENT=testing
 *   redirects to in-memory SQLite (prefix `db_`), so they are forged per test.
 *
 * Because `pelanggan` is SQLite here, the MariaDB behaviour of the
 * REPLACE(...) LIKE phone filter is covered separately by
 * PelangganFindByNomorTest, not by this class.
 *
 * AC-9 (two simultaneous cashiers) cannot be reproduced in one PHP process;
 * only the sequential double-click (second request after the first) is tested.
 *
 * @internal
 */
final class InboxBuatPesananTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use GatewayApiTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gatewaySetUp();

        $forge = \Config\Database::forge();
        $forge->dropTable('pelanggan', true);
        $forge->dropTable('users', true);

        $forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
            'nama'       => ['type' => 'TEXT'],
            'no_hp'      => ['type' => 'TEXT', 'null' => true],
            'alamat'     => ['type' => 'TEXT', 'null' => true],
            'diskon'     => ['type' => 'REAL', 'default' => 0],
            'created_at' => ['type' => 'TEXT', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pelanggan', true);

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

        foreach ([21, 30] as $id) {
            db_connect()->table('users')->insert([
                'id' => $id, 'username' => 'user' . $id, 'nama' => 'User ' . $id,
                'inisial' => 'U' . $id, 'role' => 'kasir', 'is_active' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('pelanggan', true);
        $forge->dropTable('users', true);
        $this->gatewayTearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function seedPercakapan(array $override = []): int
    {
        $now = date('Y-m-d H:i:s');

        $this->inbox->table('conversations')->insert(array_merge([
            'chat_id'       => '62811' . random_int(10000000, 99999999) . '@s.whatsapp.net',
            'jid_type'      => 'pn',
            'status'        => 'open',
            'contact_name'  => 'Budi',
            'created_at'    => $now,
            'updated_at'    => $now,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function seedPelanggan(string $nama, ?string $noHp, float $diskon = 0): int
    {
        db_connect()->table('pelanggan')->insert(['nama' => $nama, 'no_hp' => $noHp, 'diskon' => $diskon]);

        return (int) db_connect()->insertID();
    }

    /**
     * Decode the RAW response body. Not gatewayBodyJson(): TestResponse::getBody()
     * wraps output in HTML, which turns "&" into "&amp;" and would corrupt the
     * redirect_url query string (verified: the raw body from the controller is correct).
     */
    private function json($response): array
    {
        $decoded = json_decode($response->response()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function jumlahPelanggan(): int
    {
        return db_connect()->table('pelanggan')->countAllResults();
    }

    private function linkOf(int $conversationId): ?int
    {
        $row = $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray();

        return $row['pelanggan_id'] !== null ? (int) $row['pelanggan_id'] : null;
    }

    /**
     * POST with a valid (current) CSRF token unless $token is given.
     *
     * Sejak 2026-10-18 endpoint dua fase: `$nama` null = fase 1 (hanya cek),
     * `$nama` string = fase 2 (validasi + tulis). Pelanggan yang sudah
     * berformat `NAMA 4DIGIT` tetap selesai di fase 1, jadi test lama yang
     * memakai pelanggan sudah-ada tidak perlu mengirim nama.
     */
    private function buatPesanan(int $conversationId, ?string $token = null, int $userId = 21, string $role = 'kasir', ?string $nama = null)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => $userId, 'role' => $role])
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'X-CSRF-TOKEN'     => $token ?? csrf_hash(),
            ])
            ->post('/inbox/percakapan/' . $conversationId . '/buat-pesanan', $nama !== null ? ['nama' => $nama] : []);
    }

    private function assertCsrfDitolak(callable $kirim): void
    {
        try {
            $kirim();
        } catch (SecurityException $e) {
            $this->assertSame(403, $e->getCode());

            return;
        }

        $this->fail('Request tanpa token CSRF yang valid seharusnya ditolak (SecurityException 403).');
    }

    // ------------------------------------------------------------------
    // AC-1: schema
    // ------------------------------------------------------------------

    /** AC-1: kolom ada, UNSIGNED INT, nullable; percakapan baru bernilai NULL. */
    public function testKolomPelangganIdAdaDanNullable(): void
    {
        $col = $this->inbox->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'pelanggan_id'"
        )->getRowArray();

        $this->assertNotNull($col, 'conversations.pelanggan_id tidak ada; jalankan aulia:sync-inbox-test-db');
        $this->assertSame('int(11) unsigned', $col['COLUMN_TYPE']);
        $this->assertSame('YES', $col['IS_NULLABLE']);
        $this->assertNull($this->linkOf($this->seedPercakapan()));
    }

    // ------------------------------------------------------------------
    // AC-10: CSRF
    // ------------------------------------------------------------------

    /** AC-10: tanpa token -> ditolak, tidak ada data tertulis. */
    public function testTanpaTokenCsrfDitolak(): void
    {
        $id = $this->seedPercakapan();

        $this->assertCsrfDitolak(function () use ($id) {
            $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post('/inbox/percakapan/' . $id . '/buat-pesanan');
        });

        $this->assertSame(0, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    /** AC-10: token salah -> ditolak, tidak ada data tertulis. */
    public function testTokenCsrfSalahDitolak(): void
    {
        $id = $this->seedPercakapan();

        $this->assertCsrfDitolak(function () use ($id) {
            $this->buatPesanan($id, 'token-yang-salah');
        });

        $this->assertSame(0, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    /**
     * AC-10: token berputar. Balasan sukses membawa token baru; token lama
     * ditolak, token baru diterima. Inilah alasan JS wajib memperbarui token.
     */
    public function testTokenCsrfBerputarDanTokenLamaDitolak(): void
    {
        $this->seedPelanggan('CSRF UJI 7890', '081234567890');
        $id    = $this->seedPercakapan(['phone' => '6281234567890']);
        $lama  = csrf_hash();

        $json = $this->json($this->buatPesanan($id, $lama));
        $this->assertSame('success', $json['status'] ?? null);
        $this->assertNotEmpty($json['csrf'] ?? '');
        $this->assertNotSame($lama, $json['csrf']);

        $this->assertCsrfDitolak(function () use ($id, $lama) {
            $this->buatPesanan($id, $lama);
        });

        $ulang = $this->buatPesanan($id, $json['csrf']);
        $ulang->assertStatus(200);
    }

    /** Balasan error (mis. 404) juga membawa token baru, supaya klik berikutnya tidak 403. */
    public function testBalasanErrorTetapMembawaTokenBaru(): void
    {
        $lama = csrf_hash();
        $res  = $this->buatPesanan(999999, $lama);

        $res->assertStatus(404);
        $json = $this->json($res);
        $this->assertNotEmpty($json['csrf'] ?? '');
        $this->assertNotSame($lama, $json['csrf']);
    }

    // ------------------------------------------------------------------
    // AC-6: guards
    // ------------------------------------------------------------------

    public function testPercakapanTidakAdaMengembalikan404(): void
    {
        $this->buatPesanan(999999)->assertStatus(404);
        $this->assertSame(0, $this->jumlahPelanggan());
    }

    public function testPercakapanGrupDitolak403(): void
    {
        $id = $this->seedPercakapan([
            'chat_id' => '120363431232595303@g.us', 'jid_type' => 'group', 'group_name' => 'Grup Uji',
        ]);

        $res = $this->buatPesanan($id);

        $res->assertStatus(403);
        $this->assertSame(0, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    public function testPercakapanKasirLainDitolak403TapiAdminBoleh(): void
    {
        $id = $this->seedPercakapan(['assigned_to' => 30, 'phone' => '6281234567890']);

        $this->buatPesanan($id, null, 21, 'kasir')->assertStatus(403);
        $this->assertSame(0, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));

        $this->buatPesanan($id, null, 1, 'admin', 'Tamu')->assertStatus(200);
        $this->assertSame(1, $this->jumlahPelanggan());
    }

    // ------------------------------------------------------------------
    // AC-2: pencocokan nomor
    // ------------------------------------------------------------------

    public static function formatNomorPelangganProvider(): array
    {
        return [
            'lokal 08'            => ['081234567890'],
            'plus spasi strip'    => ['+62 812-3456-7890'],
            'kanonik 62'          => ['6281234567890'],
            'titik'               => ['0812.3456.7890'],
            'kurung'              => ['(0812) 3456 7890'],
        ];
    }

    /**
     * AC-2: pelanggan lama dengan format nomor apa pun dipakai ulang; tidak ada
     * baris baru, tautan terisi.
     *
     * @dataProvider formatNomorPelangganProvider
     */
    public function testPelangganLamaDipakaiLintasFormatNomor(string $noHpTersimpan): void
    {
        $pelangganId = $this->seedPelanggan('PELANGGAN LAMA 7890', $noHpTersimpan, 5);
        $id          = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'Nama Chat Berbeda']);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertSame($pelangganId, $json['pelanggan_id']);
        $this->assertFalse($json['pelanggan_baru']);
        $this->assertSame(1, $this->jumlahPelanggan());
        $this->assertSame($pelangganId, $this->linkOf($id));
    }

    /** AC-2: nomor berakhiran sama tetapi BUKAN nomor yang sama tidak dicocokkan. */
    public function testNomorBerakhiranSamaTetapiBedaTidakDicocokkan(): void
    {
        $this->seedPelanggan('ORANG LAIN 7890', '1081234567890');
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'Budi Baru']);

        $fase1 = $this->json($this->buatPesanan($id));
        $this->assertSame('butuh_nama', $fase1['status'] ?? null);

        $json = $this->json($this->buatPesanan($id, $fase1['csrf'], 21, 'kasir', 'Budi Baru'));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertTrue($json['pelanggan_baru']);
        $this->assertSame(2, $this->jumlahPelanggan());
    }

    /** AC-11: phone & manual_phone kosong -> nomor diambil dari chat_id `pn`. */
    public function testNomorDiambilDariChatIdBilaPhoneKosong(): void
    {
        $pelangganId = $this->seedPelanggan('DARI CHAT ID 7890', '0812-3456-7890');
        $id          = $this->seedPercakapan(['chat_id' => '6281234567890@s.whatsapp.net', 'phone' => null]);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame($pelangganId, $json['pelanggan_id'] ?? null);
        $this->assertFalse($json['pelanggan_baru']);
        $this->assertSame(1, $this->jumlahPelanggan());
    }

    /** AC-11: sufiks perangkat pada chat_id tidak dibaca sebagai nomor. */
    public function testChatIdDenganSufiksPerangkatTidakDibaca(): void
    {
        $this->seedPelanggan('JANGAN COCOK 7890', '081234567890');
        $id = $this->seedPercakapan(['chat_id' => '6281234567890:12@s.whatsapp.net', 'phone' => null, 'contact_name' => 'Tamu']);

        // Tanpa nomor terverifikasi -> diblok (AC-5 dokumen 2026-10-18).
        $res = $this->buatPesanan($id, null, 21, 'kasir', 'Tamu');
        $res->assertStatus(422);
        $this->assertSame(1, $this->jumlahPelanggan());
    }

    /**
     * AC-11 + AC-5 (2026-10-18): percakapan `lid` tidak pernah ditebak
     * nomornya, dan karena tidak ada nomor terverifikasi pelanggan baru
     * TIDAK dibuat -- kasir harus mengonfirmasi nomor dulu.
     */
    public function testPercakapanLidTidakDitebakNomornya(): void
    {
        $this->seedPelanggan('PEMILIK NOMOR DIGIT 2345', '123456789012345');
        $id = $this->seedPercakapan(['chat_id' => '123456789012345@lid', 'jid_type' => 'lid', 'phone' => null, 'contact_name' => 'Tamu Lid']);

        $res = $this->buatPesanan($id, null, 21, 'kasir', 'Tamu Lid');

        $res->assertStatus(422);
        $this->assertSame(1, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    // ------------------------------------------------------------------
    // AC-3: pelanggan baru
    // ------------------------------------------------------------------

    /** AC-3: dibuat dengan nama `NAMA 4DIGIT`, no_hp format 08, diskon 0, tautan tersimpan. */
    public function testPelangganBaruDibuatDanDitautkan(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'Siti Aminah']);

        $fase1 = $this->json($this->buatPesanan($id));
        $json  = $this->json($this->buatPesanan($id, $fase1['csrf'], 21, 'kasir', 'Siti Aminah'));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertTrue($json['pelanggan_baru']);

        $baru = db_connect()->table('pelanggan')->where('id', $json['pelanggan_id'])->get()->getRowArray();
        $this->assertSame('SITI AMINAH 7890', $baru['nama']);
        $this->assertSame('081234567890', $baru['no_hp']);
        $this->assertEquals(0, $baru['diskon']);
        $this->assertSame((int) $json['pelanggan_id'], $this->linkOf($id));
    }

    /**
     * Nama saran dari `whatsapp_name` dipotong 95 karakter (batas bagian
     * nama), sehingga nama final + " 7890" tetap <= 100 karakter.
     */
    public function testNamaFallbackKeWhatsappNameDanDipotong95(): void
    {
        $id = $this->seedPercakapan([
            'phone' => '6281234567890', 'contact_name' => null, 'whatsapp_name' => str_repeat('X', 120),
        ]);

        $fase1 = $this->json($this->buatPesanan($id));
        $this->assertSame('butuh_nama', $fase1['status'] ?? null);
        $this->assertSame(str_repeat('X', 95), $fase1['nama_usulan']);

        $json = $this->json($this->buatPesanan($id, $fase1['csrf'], 21, 'kasir', str_repeat('X', 95)));
        $baru = db_connect()->table('pelanggan')->where('id', $json['pelanggan_id'])->get()->getRowArray();
        $this->assertSame(str_repeat('X', 95) . ' 7890', $baru['nama']);
    }

    // ------------------------------------------------------------------
    // AC-7: nama tidak layak / bentrok
    // ------------------------------------------------------------------

    public static function namaTidakLayakProvider(): array
    {
        return [
            'kosong'        => [null, null],
            'satu karakter' => ['A', null],
            'spasi saja'    => ['   ', ''],
        ];
    }

    /**
     * AC-7 (+ dokumen 2026-10-18 AC-4): profil tanpa nama layak -> fase 1
     * memberi saran kosong, fase 2 menolak 422 dan tidak menulis apa pun.
     *
     * @dataProvider namaTidakLayakProvider
     */
    public function testTanpaNamaLayakDitolak422(?string $contactName, ?string $whatsappName): void
    {
        $id = $this->seedPercakapan([
            'phone' => '6281234567890', 'contact_name' => $contactName, 'whatsapp_name' => $whatsappName,
        ]);

        $fase1 = $this->json($this->buatPesanan($id));
        $this->assertSame('butuh_nama', $fase1['status'] ?? null);
        $this->assertSame('', $fase1['nama_usulan']);
        $this->assertSame(0, $this->jumlahPelanggan());

        $res = $this->buatPesanan($id, $fase1['csrf'], 21, 'kasir', '   ');

        $res->assertStatus(422);
        $this->assertSame(0, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    /** AC-7: nama sama dengan pelanggan lain (nomor berbeda) -> 409, tidak menimpa/menduplikasi. */
    public function testNamaBentrokDenganNomorBedaDitolak409(): void
    {
        $this->seedPelanggan('BUDI 7890', '081999999999');
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'Budi']);

        $fase1 = $this->json($this->buatPesanan($id));
        $res   = $this->buatPesanan($id, $fase1['csrf'], 21, 'kasir', 'Budi');

        $res->assertStatus(409);
        $json = $this->json($res);
        $this->assertSame('error', $json['status'] ?? null);
        $this->assertSame(1, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    // ------------------------------------------------------------------
    // AC-4 / AC-5: tautan
    // ------------------------------------------------------------------

    /** AC-4 / AC-9 (sekuensial): klik kedua memakai pelanggan yang sama, tidak ada baris baru. */
    public function testKlikKeduaMemakaiPelangganYangSama(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'Dobel Klik']);

        $fase1   = $this->json($this->buatPesanan($id));
        $pertama = $this->json($this->buatPesanan($id, $fase1['csrf'], 21, 'kasir', 'Dobel Klik'));
        $kedua   = $this->json($this->buatPesanan($id, $pertama['csrf']));

        $this->assertTrue($pertama['pelanggan_baru']);
        $this->assertFalse($kedua['pelanggan_baru']);
        $this->assertSame($pertama['pelanggan_id'], $kedua['pelanggan_id']);
        $this->assertSame(1, $this->jumlahPelanggan());
    }

    /** AC-4: tautan yang sudah ada menang atas pencocokan nomor. */
    public function testTautanYangAdaDipakaiMeskiNomorCocokDenganOrangLain(): void
    {
        $tertaut = $this->seedPelanggan('SUDAH TERTAUT 1111', '081111111111');
        $this->seedPelanggan('COCOK NOMOR 7890', '081234567890');
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'pelanggan_id' => $tertaut]);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame($tertaut, $json['pelanggan_id']);
        $this->assertSame(2, $this->jumlahPelanggan());
    }

    /** AC-5: pelanggan_id menggantung (baris sudah dihapus) -> diselesaikan ulang, bukan error. */
    public function testTautanMenggantungDiselesaikanUlang(): void
    {
        $pelangganId = $this->seedPelanggan('MASIH ADA 7890', '081234567890');
        $id          = $this->seedPercakapan(['phone' => '6281234567890', 'pelanggan_id' => 987654]);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertSame($pelangganId, $json['pelanggan_id']);
        $this->assertSame($pelangganId, $this->linkOf($id));
    }

    // ------------------------------------------------------------------
    // AC-8: URL kembalian
    // ------------------------------------------------------------------

    /**
     * AC-8: URL /kasir?pelanggan_id=...&pelanggan_nama=...&pelanggan_telp=...&pelanggan_diskon=...
     * persis format yang dibaca restorePelangganFromUrl() (kasir-shared.js:880),
     * termasuk nama dengan karakter khusus yang harus selamat dari encode/decode.
     */
    public function testRedirectUrlMemakaiFormatKasir(): void
    {
        $pelangganId = $this->seedPelanggan('TOKO A&B "JAYA" #1 7890', '081234567890', 10);
        $id          = $this->seedPercakapan(['phone' => '6281234567890']);

        $json = $this->json($this->buatPesanan($id));

        $url = parse_url($json['redirect_url']);
        $this->assertStringEndsWith('/kasir', $url['path']);

        parse_str($url['query'], $q);
        $this->assertSame(['pelanggan_id', 'pelanggan_nama', 'pelanggan_telp', 'pelanggan_diskon', 'dari_inbox'], array_keys($q));
        $this->assertSame((string) $pelangganId, $q['pelanggan_id']);
        $this->assertSame('TOKO A&B "JAYA" #1 7890', $q['pelanggan_nama']);
        $this->assertSame('1', $q['dari_inbox']);
        $this->assertSame('081234567890', $q['pelanggan_telp']);
        $this->assertEquals(10, $q['pelanggan_diskon']);
    }

    /** AC-8: pelanggan baru -> pelanggan_diskon=0 (sesuai kesepakatan Opsi A). */
    public function testRedirectUrlPelangganBaruDiskonNol(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'Baru Banget']);

        $fase1 = $this->json($this->buatPesanan($id));
        $json  = $this->json($this->buatPesanan($id, $fase1['csrf'], 21, 'kasir', 'Baru Banget'));

        parse_str(parse_url($json['redirect_url'], PHP_URL_QUERY), $q);
        $this->assertSame('BARU BANGET 7890', $q['pelanggan_nama']);
        $this->assertEquals(0, $q['pelanggan_diskon']);
        $this->assertNotSame('', $q['pelanggan_id']);
    }
}
