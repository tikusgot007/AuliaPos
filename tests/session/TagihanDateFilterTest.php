<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Filter rentang tanggal di halaman Tagihan (/tagihan).
 * Default saat halaman dibuka tanpa parameter: 7 hari lalu s/d hari ini.
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
        $db->table('pelanggan')->insert(['id' => 1, 'nama' => 'Budi']);

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
        ]);
    }

    private function session(): array
    {
        return ['isLoggedIn' => true, 'role' => 'kasir', 'id_user' => 1, 'last_activity' => time()];
    }

    public function testDefaultRangeSemingguTerakhir(): void
    {
        $result = $this->withSession($this->session())->get('tagihan');
        $body = $result->getBody();

        $result->assertStatus(200);
        // Input tanggal terisi default: 7 hari lalu s/d hari ini.
        $this->assertStringContainsString('value="' . date('Y-m-d', strtotime('-7 days')) . '"', $body);
        $this->assertStringContainsString('value="' . date('Y-m-d') . '"', $body);

        $this->assertStringContainsString('INV-RECENT', $body);
        $this->assertStringNotContainsString('INV-OLD', $body);   // di luar 7 hari
        $this->assertStringNotContainsString('INV-LUNAS', $body); // sudah lunas
        $this->assertStringNotContainsString('INV-BATAL', $body); // status batal
    }

    public function testRangeEksplisitMemunculkanYangLama(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?' . http_build_query([
            'tanggal_awal'  => date('Y-m-d', strtotime('-40 days')),
            'tanggal_akhir' => date('Y-m-d'),
        ]))->getBody();

        $this->assertStringContainsString('INV-OLD', $body);
        $this->assertStringContainsString('INV-RECENT', $body);
    }

    public function testTanggalTidakValidJatuhKeDefault(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?tanggal_awal=bukan-tanggal')->getBody();

        $this->assertStringContainsString('value="' . date('Y-m-d', strtotime('-7 days')) . '"', $body);
        $this->assertStringNotContainsString('INV-OLD', $body);
    }

    public function testFilterSayaTetapBerfungsiBersamaTanggal(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?saya=1')->getBody();

        $this->assertStringContainsString('INV-RECENT', $body);       // kasir 1, dalam range
        $this->assertStringNotContainsString('INV-KASIR2', $body);    // kasir lain
        // Default 7 hari tetap berlaku walau ada parameter saya: INV-OLD
        // juga milik kasir 1 tapi 30 hari lalu, jadi tersaring oleh tanggal.
        $this->assertStringNotContainsString('INV-OLD', $body);
        $this->assertStringContainsString('name="saya" value="1"', $body); // ikut terbawa di form filter
    }

    public function testSayaDenganRangeEksplisitMengabaikanDefault(): void
    {
        $body = $this->withSession($this->session())->get('tagihan?' . http_build_query([
            'saya'          => '1',
            'tanggal_awal'  => date('Y-m-d', strtotime('-40 days')),
            'tanggal_akhir' => date('Y-m-d'),
        ]))->getBody();

        $this->assertStringContainsString('INV-OLD', $body);       // kasir 1, kini masuk range
        $this->assertStringContainsString('INV-RECENT', $body);
        $this->assertStringNotContainsString('INV-KASIR2', $body); // kasir lain tetap tersaring
    }
}
