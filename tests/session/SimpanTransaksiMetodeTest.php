<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 2 — Api::simpanTransaksi menolak metode pembayaran yang tidak
 * dikenal alih-alih diam-diam menganggapnya "lunas".
 *
 * Cabang metode divalidasi SEBELUM transaksi ditulis, jadi test ini
 * tidak bergantung pada skema detail_transaksi.
 *
 * @internal
 */
final class SimpanTransaksiMetodeTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private function sesiKasir(): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => 'kasir',
            'id_user'       => 7,
            'nama'          => 'Test kasir',
            'last_activity' => time(),
        ];
    }

    private function keranjang(): array
    {
        return [['nama' => 'Barang', 'jumlah' => 1, 'harga' => 5000, 'subtotal' => 5000]];
    }

    public function testMetodeTidakDikenalDitolak(): void
    {
        $res = $this->withSession($this->sesiKasir())
            ->withBodyFormat('json')
            ->post('api/simpan-transaksi', [
                'keranjang' => $this->keranjang(),
                'metode'    => 'bitcoin',
            ]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertStringContainsString('tidak dikenal', $res->getJSON());
    }

    public function testMetodeDpTidakValidDitolak(): void
    {
        $res = $this->withSession($this->sesiKasir())
            ->withBodyFormat('json')
            ->post('api/simpan-transaksi', [
                'keranjang' => $this->keranjang(),
                'metode'    => 'dp',
                'jumlah_dp' => 2000,
                'metode_dp' => 'crypto',
            ]);

        $res->assertJSONFragment(['status' => 'error']);
        $this->assertStringContainsString('DP tidak valid', $res->getJSON());
    }

    public function testMetodeRiilTetapDiterimaSebagaiIntentLunas(): void
    {
        // 'transfer' adalah metode riil -> tidak ditolak di gate metode.
        // (Penyimpanan penuh diuji terpisah; di sini cukup memastikan
        //  tidak kena error "tidak dikenal".)
        $res = $this->withSession($this->sesiKasir())
            ->withBodyFormat('json')
            ->post('api/simpan-transaksi', [
                'keranjang' => $this->keranjang(),
                'metode'    => 'transfer',
            ]);

        $this->assertStringNotContainsString('tidak dikenal', $res->getJSON());
    }
}
