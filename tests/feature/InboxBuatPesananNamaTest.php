<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\GatewayApiTestTrait;

/**
 * "Buat Pesanan" v2: konfirmasi nama pelanggan + format `NAMA 4DIGIT`.
 *
 * Maps docs/requirements/2026-10-18-buat-pesanan-konfirmasi-nama-dan-tab-utama.md
 * AC-1..AC-7. Alur lama (pencocokan nomor, tautan, CSRF, redirect) tetap
 * dijaga oleh InboxBuatPesananTest -- kelas ini HANYA menguji perilaku baru.
 *
 * Dua database, sama seperti produksi: `conversations` di grup `inbox`
 * (MariaDB `aulia_inboxdb_test`), `pelanggan` di grup `default` (SQLite
 * in-memory saat testing).
 *
 * @internal
 */
final class InboxBuatPesananNamaTest extends CIUnitTestCase
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

        db_connect()->table('users')->insert([
            'id' => 21, 'username' => 'user21', 'nama' => 'User 21',
            'inisial' => 'U21', 'role' => 'kasir', 'is_active' => 1,
        ]);
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
            'chat_id'      => '62811' . random_int(10000000, 99999999) . '@s.whatsapp.net',
            'jid_type'     => 'pn',
            'status'       => 'open',
            'contact_name' => 'Budi',
            'created_at'   => $now,
            'updated_at'   => $now,
        ], $override));

        return (int) $this->inbox->insertID();
    }

    private function seedPelanggan(string $nama, ?string $noHp): int
    {
        db_connect()->table('pelanggan')->insert(['nama' => $nama, 'no_hp' => $noHp, 'diskon' => 0]);

        return (int) db_connect()->insertID();
    }

    private function json($response): array
    {
        $decoded = json_decode($response->response()->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function jumlahPelanggan(): int
    {
        return db_connect()->table('pelanggan')->countAllResults();
    }

    private function pelangganById(int $id): array
    {
        return db_connect()->table('pelanggan')->where('id', $id)->get()->getRowArray() ?? [];
    }

    private function linkOf(int $conversationId): ?int
    {
        $row = $this->inbox->table('conversations')->where('id', $conversationId)->get()->getRowArray();

        return ($row['pelanggan_id'] ?? null) !== null ? (int) $row['pelanggan_id'] : null;
    }

    /** POST buat-pesanan; `$nama` null = fase 1 (tanpa nama). */
    private function buatPesanan(int $conversationId, ?string $nama = null, ?string $token = null)
    {
        $body = $nama !== null ? ['nama' => $nama] : [];

        return $this->withSession(['isLoggedIn' => true, 'id_user' => 21, 'role' => 'kasir'])
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'X-CSRF-TOKEN'     => $token ?? csrf_hash(),
            ])
            ->post('/inbox/percakapan/' . $conversationId . '/buat-pesanan', $body);
    }

    // ------------------------------------------------------------------
    // AC-1: pelanggan sudah sesuai format -> langsung, tanpa modal
    // ------------------------------------------------------------------

    /** AC-1: nama sudah `NAMA 4DIGIT` dan digit cocok -> sukses tanpa nama. */
    public function testPelangganSesuaiFormatLangsungKeKasirTanpaModal(): void
    {
        $pelangganId = $this->seedPelanggan('SITI AMINAH 7890', '081234567890');
        $id          = $this->seedPercakapan(['phone' => '6281234567890']);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertSame($pelangganId, $json['pelanggan_id']);
        $this->assertFalse($json['pelanggan_baru']);
        $this->assertSame('SITI AMINAH 7890', $this->pelangganById($pelangganId)['nama']);
    }

    /** AC-1: nama sudah berformat tetapi huruf kecil -> tetap butuh konfirmasi. */
    public function testNamaFormatTapiHurufKecilTetapButuhKonfirmasi(): void
    {
        $this->seedPelanggan('Siti Aminah 7890', '081234567890');
        $id = $this->seedPercakapan(['phone' => '6281234567890']);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('butuh_nama', $json['status'] ?? null);
    }

    // ------------------------------------------------------------------
    // AC-2: pelanggan baru -> butuh_nama, tidak menulis
    // ------------------------------------------------------------------

    /** AC-2: fase 1 memberi saran nama HURUF BESAR + digit, tanpa insert. */
    public function testPelangganBaruButuhNamaTanpaMenulis(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'rulyapping']);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('butuh_nama', $json['status'] ?? null);
        $this->assertNull($json['pelanggan_id']);
        $this->assertSame('RULYAPPING', $json['nama_usulan']);
        $this->assertSame('7890', $json['digit']);
        $this->assertNotEmpty($json['csrf'] ?? '');
        $this->assertSame(0, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    /** AC-2: saran nama memakai whatsapp_name bila contact_name kosong, dan membuang emoji. */
    public function testSaranNamaDariWhatsappNameDanTanpaEmoji(): void
    {
        $id = $this->seedPercakapan([
            'phone' => '6281234567890', 'contact_name' => null, 'whatsapp_name' => 'Ruly 🦋 Apping',
        ]);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('butuh_nama', $json['status'] ?? null);
        $this->assertSame('RULY APPING', $json['nama_usulan']);
    }

    /** AC-2: nama profil kosong -> saran kosong, kasir wajib mengetik. */
    public function testSaranNamaKosongBilaProfilTanpaNama(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => null, 'whatsapp_name' => '🦋']);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('butuh_nama', $json['status'] ?? null);
        $this->assertSame('', $json['nama_usulan']);
    }

    // ------------------------------------------------------------------
    // AC-3: simpan dengan nama
    // ------------------------------------------------------------------

    /** AC-3: nama + digit tersimpan sebagai `NAMA 7890`, no_hp format 08, tertaut. */
    public function testSimpanDenganNamaMenghasilkanFormatNamaEmpatDigit(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'rulyapping']);

        $json = $this->json($this->buatPesanan($id, 'Ruly'));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertTrue($json['pelanggan_baru']);

        $baru = $this->pelangganById((int) $json['pelanggan_id']);
        $this->assertSame('RULY 7890', $baru['nama']);
        $this->assertSame('081234567890', $baru['no_hp']);
        $this->assertSame((int) $json['pelanggan_id'], $this->linkOf($id));
    }

    /** AC-3: kasir mengetik nama yang sudah membawa digit -> tidak dobel. */
    public function testNamaYangSudahMembawaDigitTidakDobel(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890']);

        $json = $this->json($this->buatPesanan($id, 'Ruly 7890'));

        $this->assertSame('RULY 7890', $this->pelangganById((int) $json['pelanggan_id'])['nama']);
    }

    // ------------------------------------------------------------------
    // AC-4: validasi server
    // ------------------------------------------------------------------

    public static function namaTidakLayakProvider(): array
    {
        return [
            'kosong'        => [''],
            'spasi saja'    => ['   '],
            'satu karakter' => ['A'],
            'emoji saja'    => ['🦋🦋'],
        ];
    }

    /**
     * AC-4: nama tidak layak -> 422, tidak ada pelanggan dibuat.
     *
     * @dataProvider namaTidakLayakProvider
     */
    public function testNamaTidakLayakDitolak422(string $nama): void
    {
        $id  = $this->seedPercakapan(['phone' => '6281234567890']);
        $res = $this->buatPesanan($id, $nama);

        $res->assertStatus(422);
        $this->assertSame(0, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    /** AC-4: terlalu panjang (>95 sebelum digit) ditolak 422. */
    public function testNamaTerlaluPanjangDitolak422(): void
    {
        $id  = $this->seedPercakapan(['phone' => '6281234567890']);
        $res = $this->buatPesanan($id, str_repeat('A', 96));

        $res->assertStatus(422);
        $this->assertSame(0, $this->jumlahPelanggan());
    }

    /** AC-4: nama dirapikan server -- huruf kecil jadi besar, spasi berlebih dipadatkan. */
    public function testNamaDirapikanServer(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890']);

        $json = $this->json($this->buatPesanan($id, '  ruly   apping  '));

        $this->assertSame('RULY APPING 7890', $this->pelangganById((int) $json['pelanggan_id'])['nama']);
    }

    /** AC-4: nama bentrok dengan pelanggan lain -> 409, tidak menimpa. */
    public function testNamaBentrokDitolak409(): void
    {
        $this->seedPelanggan('RULY 7890', '081999999999');
        $id = $this->seedPercakapan(['phone' => '6281234567890']);

        $res = $this->buatPesanan($id, 'Ruly');

        $res->assertStatus(409);
        $this->assertSame(1, $this->jumlahPelanggan());
        $this->assertNull($this->linkOf($id));
    }

    // ------------------------------------------------------------------
    // AC-5: tanpa nomor terverifikasi -> diblok
    // ------------------------------------------------------------------

    /** AC-5: percakapan @lid tanpa nomor -> 422, tidak ada pelanggan. */
    public function testTanpaNomorTerverifikasiDiblok422(): void
    {
        $id  = $this->seedPercakapan([
            'chat_id' => '182690745680043@lid', 'jid_type' => 'lid', 'phone' => null, 'contact_name' => 'Tamu',
        ]);
        $res = $this->buatPesanan($id, 'Tamu');

        $res->assertStatus(422);
        $json = $this->json($res);
        $this->assertSame('error', $json['status'] ?? null);
        $this->assertStringContainsString('Konfirmasi nomor', $json['message'] ?? '');
        $this->assertSame(0, $this->jumlahPelanggan());
    }

    /** AC-5: nomor non-Indonesia (`60...`) tidak bisa dinormalisasi -> 422. */
    public function testNomorLuarNegeriDiblok422(): void
    {
        $id  = $this->seedPercakapan(['chat_id' => '60122459129@s.whatsapp.net', 'phone' => null]);
        $res = $this->buatPesanan($id, 'Tamu Malaysia');

        $res->assertStatus(422);
        $this->assertSame(0, $this->jumlahPelanggan());
    }

    // ------------------------------------------------------------------
    // AC-2b: pelanggan lama belum sesuai format -> UPDATE, bukan duplikat
    // ------------------------------------------------------------------

    /** AC-2b: pelanggan lama tanpa no_hp -> nama & no_hp diperbarui, tanpa baris baru. */
    public function testPelangganLamaTanpaHpDiperbaruiBukanDuplikat(): void
    {
        $pelangganId = $this->seedPelanggan('Rulyapping', null);
        $id          = $this->seedPercakapan(['phone' => '6281234567890', 'pelanggan_id' => $pelangganId]);

        $fase1 = $this->json($this->buatPesanan($id));
        $this->assertSame('butuh_nama', $fase1['status'] ?? null);
        $this->assertSame($pelangganId, $fase1['pelanggan_id']);
        $this->assertSame('RULYAPPING', $fase1['nama_usulan']);
        $this->assertSame('7890', $fase1['digit']);

        $json = $this->json($this->buatPesanan($id, 'Ruly', $fase1['csrf']));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertFalse($json['pelanggan_baru']);
        $this->assertSame(1, $this->jumlahPelanggan());

        $row = $this->pelangganById($pelangganId);
        $this->assertSame('RULY 7890', $row['nama']);
        $this->assertSame('081234567890', $row['no_hp']);
    }

    /** AC-2b: pelanggan lama ber-HP -> nama diperbarui, no_hp TIDAK ditimpa. */
    public function testHpPelangganYangBedaTidakDitimpa(): void
    {
        $pelangganId = $this->seedPelanggan('Ruly Lama', '081111111111');
        $id          = $this->seedPercakapan(['phone' => '6281234567890', 'pelanggan_id' => $pelangganId]);

        $fase1 = $this->json($this->buatPesanan($id));
        $this->assertSame('butuh_nama', $fase1['status'] ?? null);
        // Digit acuan = nomor pelanggan sendiri (1111), bukan nomor percakapan.
        $this->assertSame('1111', $fase1['digit']);
        $this->assertSame('RULY LAMA', $fase1['nama_usulan']);

        $json = $this->json($this->buatPesanan($id, 'Ruly', $fase1['csrf']));

        $this->assertSame('success', $json['status'] ?? null);
        $row = $this->pelangganById($pelangganId);
        $this->assertSame('RULY 1111', $row['nama']);
        $this->assertSame('081111111111', $row['no_hp']);
        $this->assertSame(1, $this->jumlahPelanggan());
    }

    /** AC-2b: cek bentrok nama tidak menghitung pelanggan itu sendiri. */
    public function testNamaSendiriTidakDianggapBentrok(): void
    {
        $pelangganId = $this->seedPelanggan('rulyapping', '081234567890');
        $id          = $this->seedPercakapan(['phone' => '6281234567890', 'pelanggan_id' => $pelangganId]);

        $fase1 = $this->json($this->buatPesanan($id));
        $json  = $this->json($this->buatPesanan($id, 'Rulyapping', $fase1['csrf']));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertSame('RULYAPPING 7890', $this->pelangganById($pelangganId)['nama']);
    }

    /**
     * AC-2b + keputusan Gate 2 (opsi A): tautan lama menang walau nomornya
     * beda. Pelanggan tertaut tidak pernah diganti oleh pencocokan nomor.
     */
    public function testTautanTetapMenangWalauNomorBeda(): void
    {
        $tertaut = $this->seedPelanggan('SUDAH TERTAUT 1111', '081111111111');
        $this->seedPelanggan('COCOK NOMOR 7890', '081234567890');
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'pelanggan_id' => $tertaut]);

        $json = $this->json($this->buatPesanan($id));

        $this->assertSame('success', $json['status'] ?? null);
        $this->assertSame($tertaut, $json['pelanggan_id']);
        $this->assertSame(2, $this->jumlahPelanggan());
    }

    // ------------------------------------------------------------------
    // AC-6: dua kiriman berurutan tidak menduplikasi
    // ------------------------------------------------------------------

    /** AC-6: fase 1 + fase 2 berurutan -> satu pelanggan, klik kedua memakai yang sama. */
    public function testSubmitKeduaMemakaiPelangganYangSama(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890', 'contact_name' => 'Ruly']);

        $fase1  = $this->json($this->buatPesanan($id));
        $kedua  = $this->json($this->buatPesanan($id, 'Ruly', $fase1['csrf']));
        $ketiga = $this->json($this->buatPesanan($id, 'Ruly', $kedua['csrf']));

        $this->assertSame('success', $kedua['status'] ?? null);
        $this->assertSame('success', $ketiga['status'] ?? null);
        $this->assertSame($kedua['pelanggan_id'], $ketiga['pelanggan_id']);
        $this->assertSame(1, $this->jumlahPelanggan());
    }

    // ------------------------------------------------------------------
    // AC-12: penanda dari_inbox pada URL kembalian
    // ------------------------------------------------------------------

    /** AC-12: redirect membawa `dari_inbox=1` supaya Kasir membuang keranjang lama. */
    public function testRedirectMembawaPenandaDariInbox(): void
    {
        $id = $this->seedPercakapan(['phone' => '6281234567890']);

        $fase1 = $this->json($this->buatPesanan($id));
        $json  = $this->json($this->buatPesanan($id, 'Ruly', $fase1['csrf']));

        parse_str(parse_url($json['redirect_url'], PHP_URL_QUERY), $q);
        $this->assertSame('1', (string) ($q['dari_inbox'] ?? ''));
        $this->assertSame('RULY 7890', $q['pelanggan_nama']);
    }
}
