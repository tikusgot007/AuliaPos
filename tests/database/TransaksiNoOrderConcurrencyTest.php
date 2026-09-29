<?php

use App\Models\TransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * TransaksiModel::simpanTransaksi() menolak no_order yang masih dipakai
 * transaksi aktif (proses/selesai/mangkrak) dengan RuntimeException kode
 * 409, dan mengizinkan no_order dipakai ulang setelah transaksi lamanya
 * dibatalkan (no_order dikosongkan oleh ubahStatus()). Dibawa dari v2.1
 * (commit f03e10e/caf600d/ffe12e7/55d8376) yang belum pernah masuk v2.3.
 *
 * Test suite berjalan di SQLite (grup `tests`, lihat app/Config/Database.php),
 * jadi tahap MySQL GET_LOCK()/RELEASE_LOCK() dilewati oleh
 * TransaksiModel::simpanTransaksi() sendiri (deteksi getPlatform() ===
 * 'MySQLi') -- test ini SENGAJA hanya membuktikan aturan bisnis "no_order
 * aktif ditolak, no_order batal boleh dipakai ulang", BUKAN perilaku lock
 * lintas-proses MySQL (lihat tests/unit/TransaksiNoOrderLockPlatformTest.php
 * untuk itu).
 *
 * @internal
 */
final class TransaksiNoOrderConcurrencyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'Tests\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    private function keranjangItems(): array
    {
        return [
            [
                'produk_id'    => 1,
                'nama_produk'  => 'Barang Uji',
                'kategori_id'  => 1,
                'jumlah'       => 1,
                'harga_satuan' => 5000,
                'subtotal'     => 5000,
            ],
        ];
    }

    private function dataTransaksi(?int $noOrder): array
    {
        $data = [
            'kode_invoice'      => 'INV-LOCK-' . random_int(100000, 999999),
            'tanggal'           => date('Y-m-d H:i:s'),
            'grand_total'       => 5000,
            'total_dibayar'     => 0,
            'status_pembayaran' => 'belum_bayar',
            'sumber'            => 'kasir_pos',
        ];

        if ($noOrder !== null) {
            $data['no_order'] = $noOrder;
        }

        return $data;
    }

    public function testNoOrderKosongTidakDicekSamaSekali(): void
    {
        $model = new TransaksiModel();
        $id    = $model->simpanTransaksi($this->dataTransaksi(null), $this->keranjangItems());

        $this->assertIsInt($id);
        $this->seeInDatabase('transaksi', ['id' => $id, 'no_order' => null]);
    }

    public function testNoOrderDiisiBerhasilTersimpan(): void
    {
        $model = new TransaksiModel();
        $id    = $model->simpanTransaksi($this->dataTransaksi(555), $this->keranjangItems());

        $this->assertIsInt($id);
        $this->seeInDatabase('transaksi', ['id' => $id, 'no_order' => 555]);
    }

    public function testNoOrderYangSudahDipakaiTransaksiAktifDitolak409(): void
    {
        $model     = new TransaksiModel();
        $idPertama = $model->simpanTransaksi($this->dataTransaksi(777), $this->keranjangItems());
        $this->assertIsInt($idPertama);

        try {
            $model->simpanTransaksi($this->dataTransaksi(777), $this->keranjangItems());
            $this->fail('Seharusnya melempar RuntimeException karena no_order 777 masih aktif.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
            $this->assertStringContainsString('777', $e->getMessage());
            $this->assertStringContainsString('digunakan oleh transaksi aktif', $e->getMessage());
        }

        // Tidak ada baris kedua yang ditulis untuk percobaan yang ditolak.
        $this->assertSame(
            1,
            db_connect()->table('transaksi')->where('no_order', 777)->countAllResults()
        );
    }

    /**
     * @dataProvider statusAktifProvider
     */
    public function testNoOrderDitolakUntukSetiapStatusAktif(string $statusAktif): void
    {
        db_connect()->table('transaksi')->insert(array_merge(
            $this->dataTransaksi(321),
            ['status' => $statusAktif]
        ));

        $model = new TransaksiModel();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        $model->simpanTransaksi($this->dataTransaksi(321), $this->keranjangItems());
    }

    public static function statusAktifProvider(): array
    {
        return [
            'proses'  => ['proses'],
            'selesai' => ['selesai'],
            'mangkrak' => ['mangkrak'],
        ];
    }

    public function testNoOrderTransaksiBatalBolehDipakaiUlang(): void
    {
        $model     = new TransaksiModel();
        $idPertama = $model->simpanTransaksi($this->dataTransaksi(888), $this->keranjangItems());
        $this->assertIsInt($idPertama);

        // Batalkan transaksi pertama -- no_order harus dikosongkan (perilaku
        // pra-eksisting TransaksiModel::ubahStatus(), tidak diubah sesi ini)
        // sehingga nomor yang sama boleh dipakai transaksi baru.
        $model->ubahStatus($idPertama, 'batal', true);
        $this->seeInDatabase('transaksi', ['id' => $idPertama, 'no_order' => null]);

        $idKedua = $model->simpanTransaksi($this->dataTransaksi(888), $this->keranjangItems());
        $this->assertIsInt($idKedua);
        $this->seeInDatabase('transaksi', ['id' => $idKedua, 'no_order' => 888]);
    }

    public function testDuaNoOrderBerbedaTidakSalingMenghalangi(): void
    {
        $model = new TransaksiModel();
        $id1   = $model->simpanTransaksi($this->dataTransaksi(901), $this->keranjangItems());
        $id2   = $model->simpanTransaksi($this->dataTransaksi(902), $this->keranjangItems());

        $this->assertIsInt($id1);
        $this->assertIsInt($id2);
        $this->seeInDatabase('transaksi', ['id' => $id1, 'no_order' => 901]);
        $this->seeInDatabase('transaksi', ['id' => $id2, 'no_order' => 902]);
    }
}
