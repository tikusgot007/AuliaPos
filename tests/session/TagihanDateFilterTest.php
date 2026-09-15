<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Filter rentang tanggal di halaman Tagihan (/tagihan).
 * Default saat halaman dibuka tanpa parameter: TANPA batas tanggal --
 * semua tagihan belum lunas ditampilkan, termasuk yang lama (justru itu
 * yang paling perlu ditagih). Filter tanggal hanya berlaku kalau diisi.
 *
 * @internal
 */
final class TagihanDateFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->table('users')->insertBatch([
            ['id' => 1, 'username' => 'kasir1', 'profile_photo' => null],
            ['id' => 2, 'username' => 'kasir2', 'profile_photo' => null],
        ]);
        $db->table('pelanggan')->insertBatch([
            ['id' => 1, 'nama' => 'Budi'],
            ['id' => 2, 'nama' => 'Ani'],
        ]);

        $mk = static fn (string $inv, string $tgl, array $ovr = []): array => array_merge([
            'kode_invoice'      => $inv,
            'no_order'          => null,
            'tanggal'           => $tgl,
            'pelanggan_id'      => 1,
            'kasir_id'          => 1,
            'grand_total'       => 100000,
            'total_dibayar'     => 0,
            'status_pembayaran' => 'belum_bayar',
            'status'            => 'proses',
        ], $ovr);

        $db->table('transaksi')->insertBatch([
            $mk('INV-RECENT', date('Y-m-d H:i:s', strtotime('-3 days'))),
            $mk('INV-OLD',    date('Y-m-d H:i:s', strtotime('-30 days'))),
            $mk('INV-LUNAS',  date('Y-m-d H:i:s', strtotime('-2 days')), ['status_pembayaran' => 'lunas']),
            $mk('INV-BATAL',  date('Y-m-d H:i:s', strtotime('-1 days')), ['status' => 'batal']),
            $mk('INV-KASIR2', date('Y-m-d H:i:s', strtotime('-2 days')), ['kasir_id' => 2]),
            $mk('INV-DP',     date('Y-m-d H:i:s', strtotime('-2 days')), ['status_pembayaran' => 'dp', 'total_dibayar' => 50000]),
            $mk('INV-ANI',    date('Y-m-d H:i:s', strtotime('-2 days')), ['pelanggan_id' => 2]),
        ]);
    }

    private function session(): array
    {
        return ['isLoggedIn' => true, 'role' => 'kasir', 'id_user' => 1, 'last_activity' => time()];
    }

    public function testDefaultTanpaBatasTanggalMenampilkanSemua(): void
    {
        $result = $this->withSession($this->session())->get('tagihan');
        $body = $result->getBody();

        $result->assertStatus(200);
        // Input tanggal kosong secara default -- tidak ada batas.
        $this->assertStringContainsString('value=""', $body);

        $this->assertStringContainsString('INV-RECENT', $body);
        $this->assertStringContainsString('INV-OLD', $body);       // tetap tampil walau lama
        $this->assertStringNotContainsString('INV-LUNAS', $body); // sudah lunas
        $this->assertStringNotContainsString('INV-BATAL', $body); // status batal
    }

    public function testRangeEksplisitMembatasiTanggal(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?' . http_build_query([
            'tanggal_awal'  => date('Y-m-d', strtotime('-7 days')),
            'tanggal_akhir' => date('Y-m-d'),
        ]))->getBody();

        $this->assertStringContainsString('INV-RECENT', $body);
        $this->assertStringNotContainsString('INV-OLD', $body); // di luar range eksplisit
    }

    public function testTanggalTidakValidDiabaikanBukanCrash(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?tanggal_awal=bukan-tanggal')->getBody();

        $this->assertStringContainsString('value=""', $body);
        $this->assertStringContainsString('INV-OLD', $body); // filter tidak valid -> diabaikan, bukan default sempit
    }

    public function testFilterSayaTetapBerfungsiTanpaBatasTanggal(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?saya=1')->getBody();

        $this->assertStringContainsString('INV-RECENT', $body);       // kasir 1
        $this->assertStringContainsString('INV-OLD', $body);          // kasir 1, tetap tampil (tanpa batas tanggal)
        $this->assertStringNotContainsString('INV-KASIR2', $body);    // kasir lain
        $this->assertStringContainsString('name="saya" value="1"', $body); // ikut terbawa di form filter
    }

    public function testSayaDenganRangeEksplisitTetapMembatasiTanggal(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?' . http_build_query([
            'saya'          => '1',
            'tanggal_awal'  => date('Y-m-d', strtotime('-7 days')),
            'tanggal_akhir' => date('Y-m-d'),
        ]))->getBody();

        $this->assertStringNotContainsString('INV-OLD', $body);    // di luar range eksplisit
        $this->assertStringContainsString('INV-RECENT', $body);
        $this->assertStringNotContainsString('INV-KASIR2', $body); // kasir lain tetap tersaring
    }

    public function testFilterStatusPembayaran(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?status_pembayaran=dp')->getBody();

        $this->assertStringContainsString('INV-DP', $body);
        $this->assertStringNotContainsString('INV-RECENT', $body); // belum_bayar, tersaring

        $bodyBelumBayar = $this->withSession($this->session())->get('tagihan?status_pembayaran=belum_bayar')->getBody();

        $this->assertStringContainsString('INV-RECENT', $bodyBelumBayar);
        $this->assertStringNotContainsString('INV-DP', $bodyBelumBayar);
    }

    public function testFilterStatusPembayaranTidakValidDiabaikan(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?status_pembayaran=lunas')->getBody();

        // 'lunas' bukan pilihan valid untuk filter ini (tagihan lunas memang
        // tidak pernah masuk daftar) -- diabaikan, bukan error.
        $this->assertStringContainsString('INV-RECENT', $body);
        $this->assertStringContainsString('INV-DP', $body);
    }

    public function testFilterPelanggan(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?' . http_build_query(['pelanggan' => 'Ani']))->getBody();

        $this->assertStringContainsString('INV-ANI', $body);
        $this->assertStringNotContainsString('INV-RECENT', $body); // milik Budi, tersaring
    }
}
