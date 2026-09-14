<?php

use App\Models\TransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * TransaksiModel::getRecommendedNoOrder() dipakai sebagai fallback pivot
 * di Transaksi::getAvailableNoOrdersForEdit() saat transaksi yang diedit
 * belum punya no_order (lihat app/Controllers/Transaksi.php). Sebelum
 * fix ini, dropdown no_order di halaman edit kosong sama sekali untuk
 * kasus itu, berbeda dari halaman kasir/index yang selalu menghitung
 * rekomendasi -- lihat riwayat perbaikan bug "no_order tidak muncul di
 * dropdown edit".
 *
 * @internal
 */
final class TransaksiRecommendedNoOrderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private function buatTransaksi(int $noOrder, string $tanggal): void
    {
        db_connect()->table('transaksi')->insert([
            'no_order'   => $noOrder,
            'tanggal'    => $tanggal,
            'status'     => 'proses',
            'grand_total' => 0,
        ]);
    }

    public function testTidakAdaTransaksiSamaSekaliMulaiDariSatu(): void
    {
        $this->assertSame(1, (new TransaksiModel())->getRecommendedNoOrder());
    }

    public function testTertinggiHariIniPlusSatu(): void
    {
        $hariIni = date('Y-m-d H:i:s');
        $this->buatTransaksi(10, $hariIni);
        $this->buatTransaksi(15, $hariIni);

        $this->assertSame(16, (new TransaksiModel())->getRecommendedNoOrder());
    }

    public function testTidakAdaHariIniPakaiTertinggiKeseluruhanPlusSatu(): void
    {
        $this->buatTransaksi(99, date('Y-m-d H:i:s', strtotime('-3 days')));

        $this->assertSame(100, (new TransaksiModel())->getRecommendedNoOrder());
    }
}
