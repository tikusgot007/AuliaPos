<?php

namespace App\Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

class LaporanBL18Test extends CIUnitTestCase
{
    private $laporanController;

    protected function setUp(): void
    {
        parent::setUp();
        $this->laporanController = new \App\Controllers\Laporan();
    }

    public function testMultiKategoriPartialPaymentProrata()
    {
        $processMethod = new \ReflectionMethod($this->laporanController, 'processDetailTransaksi');
        $processMethod->setAccessible(true);

        $transaksiData = [
            [
                'id' => 1,
                'kode_invoice' => 'INV-20260907-997',
                'tanggal' => '2026-09-07 19:07:48',
                'no_order' => null,
                'pelanggan_nama' => 'Test Pelanggan',
                'subtotal' => 5000,
                'diskon' => 0,
                'grand_total' => 5000,
                'total_dibayar' => 2000,
                'status_pembayaran' => 'sebagian',
                'status' => 'selesai',
            ]
        ];

        $detailGroup = [
            1 => [
                [
                    'id' => 1,
                    'transaksi_id' => 1,
                    'produk_id' => 1,
                    'nama_produk' => 'Print HVS',
                    'kategori_id' => 1,
                    'qty' => 3,
                    'satuan' => 'lembar',
                    'harga' => 1000,
                    'subtotal' => 3000,
                    'diskon_item' => 0,
                ],
                [
                    'id' => 2,
                    'transaksi_id' => 1,
                    'produk_id' => 2,
                    'nama_produk' => '4x6',
                    'kategori_id' => 16,
                    'qty' => 1,
                    'satuan' => 'lembar',
                    'harga' => 2000,
                    'subtotal' => 2000,
                    'diskon_item' => 0,
                ],
            ]
        ];

        $resultNoFilter = $processMethod->invoke($this->laporanController, $transaksiData, $detailGroup, null);
        $resultKategori1 = $processMethod->invoke($this->laporanController, $transaksiData, $detailGroup, 1);
        $resultKategori16 = $processMethod->invoke($this->laporanController, $transaksiData, $detailGroup, 16);

        $this->assertCount(1, $resultNoFilter, 'Without filter should return 1 row');
        $this->assertCount(1, $resultKategori1, 'Kategori 1 should return 1 row');
        $this->assertCount(1, $resultKategori16, 'Kategori 16 should return 1 row');

        $rowNoFilter = $resultNoFilter[0];
        $rowKat1 = $resultKategori1[0];
        $rowKat16 = $resultKategori16[0];

        $this->assertEquals(5000, $rowNoFilter['grand_total'], 'No filter: grand_total = 5000');
        $this->assertEquals(3000, $rowNoFilter['sisa_tagihan'], 'No filter: sisa_tagihan = 5000 - 2000 = 3000');

        $this->assertEquals(3000, $rowKat1['grand_total'], 'Kategori 1: grand_total = 3000 (pro-rata)');
        $this->assertEquals(1800, $rowKat1['sisa_tagihan'], 'Kategori 1: sisa_tagihan = 3000 - (2000 * 3000/5000) = 1800; BL18 FIX');

        $this->assertEquals(2000, $rowKat16['grand_total'], 'Kategori 16: grand_total = 2000 (pro-rata)');
        $this->assertEquals(1200, $rowKat16['sisa_tagihan'], 'Kategori 16: sisa_tagihan = 2000 - (2000 * 2000/5000) = 1200; BL18 FIX');
    }

    public function testMultiKategoriWithDiskonProrata()
    {
        $processMethod = new \ReflectionMethod($this->laporanController, 'processDetailTransaksi');
        $processMethod->setAccessible(true);

        $transaksiData = [
            [
                'id' => 2,
                'kode_invoice' => 'INV-TEST-002',
                'tanggal' => '2026-10-08 00:00:00',
                'no_order' => null,
                'pelanggan_nama' => 'Test Pelanggan',
                'subtotal' => 10000,
                'diskon' => 1000,
                'grand_total' => 9000,
                'total_dibayar' => 3000,
                'status_pembayaran' => 'sebagian',
                'status' => 'selesai',
            ]
        ];

        $detailGroup = [
            2 => [
                [
                    'id' => 3,
                    'transaksi_id' => 2,
                    'produk_id' => 1,
                    'nama_produk' => 'Print HVS',
                    'kategori_id' => 1,
                    'qty' => 6,
                    'satuan' => 'lembar',
                    'harga' => 1000,
                    'subtotal' => 6000,
                    'diskon_item' => 0,
                ],
                [
                    'id' => 4,
                    'transaksi_id' => 2,
                    'produk_id' => 2,
                    'nama_produk' => '4x6',
                    'kategori_id' => 16,
                    'qty' => 2,
                    'satuan' => 'lembar',
                    'harga' => 2000,
                    'subtotal' => 4000,
                    'diskon_item' => 0,
                ],
            ]
        ];

        $resultKat1 = $processMethod->invoke($this->laporanController, $transaksiData, $detailGroup, 1);

        $this->assertCount(1, $resultKat1, 'Kategori 1 should return 1 row');

        $rowKat1 = $resultKat1[0];

        $diskonProrata = (6000 / 10000) * 1000;
        $grandTotalProrata = 6000 - $diskonProrata;
        $paymentAllocated = 3000 * (6000 / 10000);
        $sisaTagihanProrata = max(0, $grandTotalProrata - $paymentAllocated);

        $this->assertEqualsWithDelta($grandTotalProrata, $rowKat1['grand_total'], 0.01, 'grand_total should be pro-rata with diskon');
        $this->assertEqualsWithDelta($sisaTagihanProrata, $rowKat1['sisa_tagihan'], 0.01, 'sisa_tagihan should use pro-rata grand_total (BL18 FIX)');
    }

    public function testWithoutKategoriFilterUnchanged()
    {
        $processMethod = new \ReflectionMethod($this->laporanController, 'processDetailTransaksi');
        $processMethod->setAccessible(true);

        $transaksiData = [
            [
                'id' => 3,
                'kode_invoice' => 'INV-TEST-003',
                'tanggal' => '2026-10-08 00:00:00',
                'no_order' => null,
                'pelanggan_nama' => 'Test Pelanggan',
                'subtotal' => 5000,
                'diskon' => 0,
                'grand_total' => 5000,
                'total_dibayar' => 2000,
                'status_pembayaran' => 'sebagian',
                'status' => 'selesai',
            ]
        ];

        $detailGroup = [
            3 => [
                [
                    'id' => 5,
                    'transaksi_id' => 3,
                    'produk_id' => 1,
                    'nama_produk' => 'Print HVS',
                    'kategori_id' => 1,
                    'qty' => 5,
                    'satuan' => 'lembar',
                    'harga' => 1000,
                    'subtotal' => 5000,
                    'diskon_item' => 0,
                ],
            ]
        ];

        $resultNoFilter = $processMethod->invoke($this->laporanController, $transaksiData, $detailGroup, null);

        $this->assertCount(1, $resultNoFilter, 'Without filter should return 1 row');

        $row = $resultNoFilter[0];

        $this->assertEquals(5000, $row['grand_total'], 'Without filter: grand_total = 5000');
        $this->assertEquals(3000, $row['sisa_tagihan'], 'Without filter: sisa_tagihan = 5000 - 2000 = 3000 (unchanged)');
    }

    public function testEmptyDetailSkipped()
    {
        $processMethod = new \ReflectionMethod($this->laporanController, 'processDetailTransaksi');
        $processMethod->setAccessible(true);

        $transaksiData = [
            [
                'id' => 4,
                'kode_invoice' => 'INV-TEST-004',
                'tanggal' => '2026-10-08 00:00:00',
                'no_order' => null,
                'pelanggan_nama' => 'Test Pelanggan',
                'subtotal' => 5000,
                'diskon' => 0,
                'grand_total' => 5000,
                'total_dibayar' => 0,
                'status_pembayaran' => 'belum_bayar',
                'status' => 'selesai',
            ]
        ];

        $detailGroup = [
            4 => [
                [
                    'id' => 6,
                    'transaksi_id' => 4,
                    'produk_id' => 1,
                    'nama_produk' => 'Print HVS',
                    'kategori_id' => 1,
                    'qty' => 5,
                    'satuan' => 'lembar',
                    'harga' => 1000,
                    'subtotal' => 5000,
                    'diskon_item' => 0,
                ],
            ]
        ];

        $resultKat16 = $processMethod->invoke($this->laporanController, $transaksiData, $detailGroup, 16);

        $this->assertCount(0, $resultKat16, 'Row with no matching kategori should be skipped');
    }
}
