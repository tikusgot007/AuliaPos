<?php

use App\Models\TransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Tahap 5 — backdate payment (TransaksiModel::tambahPembayaran()) kini
 * juga boleh dilakukan Effective Shift Leader, persis seperti admin
 * (bukan role permanen baru -- lihat App\Services\Authority). Validasi
 * masa-depan & tidak-boleh-sebelum-tanggal-transaksi TETAP berlaku
 * tanpa kecuali untuk kedua kapabilitas ini.
 *
 * Diuji lewat model langsung (bukan HTTP) dengan sengaja -- lihat
 * catatan di tests/session/ApiTambahPembayaranTest.php docblock soal
 * jalur backdate lewat HTTP pernah meninggalkan process-state yang
 * mengganggu test lain saat full run; pola DB-only ini (sama seperti
 * tests/database/Phase2TransaksiPembayaranTest.php) tidak menyentuh
 * session()/Services sama sekali sehingga menghindari kelas masalah
 * itu.
 *
 * @internal
 */
final class TambahPembayaranBackdateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private TransaksiModel $transaksiModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transaksiModel = new TransaksiModel();
    }

    /** Buat transaksi PROSES + belum_bayar bertanggal $tanggalTransaksi, kembalikan id-nya. */
    private function buatTransaksi(string $tanggalTransaksi, int $grandTotal = 100000): int
    {
        $db = db_connect();
        $db->table('transaksi')->insert([
            'kode_invoice'      => 'INV-BD-' . random_int(1000, 9999),
            'tanggal'           => $tanggalTransaksi,
            'kasir_id'          => 7,
            'grand_total'       => $grandTotal,
            'total_dibayar'     => 0,
            'status_pembayaran' => 'belum_bayar',
            'status'            => 'proses',
            'sumber'            => 'kasir_pos',
        ]);

        return (int) $db->insertID();
    }

    public function testAdminBolehBackdate(): void
    {
        $id = $this->buatTransaksi('2026-09-01 10:00:00');

        $ok = $this->transaksiModel->tambahPembayaran($id, [
            'jumlah'   => 50000,
            'metode'   => 'tunai',
            'tanggal'  => '2026-09-02 09:00:00', // backdate valid (setelah tanggal transaksi)
            'kasir_id' => 7,
        ], true, false);

        $this->assertTrue($ok);
        $this->seeInDatabase('pembayaran', ['transaksi_id' => $id, 'jumlah' => 50000]);
    }

    public function testShiftLeaderBolehBackdate(): void
    {
        $id = $this->buatTransaksi('2026-09-01 10:00:00');

        $ok = $this->transaksiModel->tambahPembayaran($id, [
            'jumlah'   => 50000,
            'metode'   => 'tunai',
            'tanggal'  => '2026-09-02 09:00:00',
            'kasir_id' => 7,
        ], false, true); // isAdmin=false, isShiftLeader=true

        $this->assertTrue($ok);
        $this->seeInDatabase('pembayaran', ['transaksi_id' => $id, 'jumlah' => 50000]);
    }

    public function testKasirBiasaBukanLeaderDitolakBackdate(): void
    {
        $id = $this->buatTransaksi('2026-09-01 10:00:00');

        $this->expectExceptionMessage('Hanya admin atau Shift Leader yang dapat mencatat pembayaran dengan tanggal berbeda (backdate).');

        $this->transaksiModel->tambahPembayaran($id, [
            'jumlah'   => 50000,
            'metode'   => 'tunai',
            'tanggal'  => '2026-09-02 09:00:00',
            'kasir_id' => 7,
        ], false, false);
    }

    public function testShiftLeaderTetapDitolakUntukTanggalMasaDepan(): void
    {
        $id = $this->buatTransaksi('2026-09-01 10:00:00');

        $this->expectExceptionMessage('Tanggal pembayaran tidak boleh di masa depan.');

        $this->transaksiModel->tambahPembayaran($id, [
            'jumlah'   => 50000,
            'metode'   => 'tunai',
            'tanggal'  => date('Y-m-d H:i:s', strtotime('+1 day')),
            'kasir_id' => 7,
        ], false, true);
    }

    public function testShiftLeaderTetapDitolakUntukTanggalSebelumTransaksi(): void
    {
        $id = $this->buatTransaksi('2026-09-05 10:00:00');

        $this->expectExceptionMessage('Tanggal pembayaran tidak boleh sebelum tanggal transaksi.');

        $this->transaksiModel->tambahPembayaran($id, [
            'jumlah'   => 50000,
            'metode'   => 'tunai',
            'tanggal'  => '2026-09-01 10:00:00', // sebelum tanggal transaksi
            'kasir_id' => 7,
        ], false, true);
    }

    public function testPembayaranNormalTanpaBackdateTidakButuhOtorisasiApaPun(): void
    {
        $id = $this->buatTransaksi(date('Y-m-d H:i:s'));

        // Tanggal = sekarang -> bukan backdate -> isAdmin/isShiftLeader tidak relevan.
        $ok = $this->transaksiModel->tambahPembayaran($id, [
            'jumlah'   => 50000,
            'metode'   => 'tunai',
            'tanggal'  => date('Y-m-d H:i:s'),
            'kasir_id' => 7,
        ], false, false);

        $this->assertTrue($ok);
    }
}
