<?php

namespace Tests\Unit;

use App\Services\KalkulasiDiskonTransaksi;
use App\Services\ValidasiItemTransaksi;
use PHPUnit\Framework\TestCase;

/**
 * TODO-BL03: cart lines must be validated/normalized server-side before they are
 * persisted. This pure service is the single source of truth used by both the
 * create (Api::simpanTransaksi) and edit (Transaksi::updateTransaksi) paths
 * (AC-6 is structural: both call this one service).
 *
 * @internal
 */
final class ValidasiItemTransaksiTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function item(array $overrides = []): array
    {
        return array_merge([
            'produk_id'   => 1,
            'kategori_id' => 1,
            'nama'        => 'Produk Uji',
            'harga'       => 10000,
            'jumlah'      => 2,
            'subtotal'    => 20000,
        ], $overrides);
    }

    public function testItemValidMengembalikanSubtotalKonsisten(): void
    {
        $hasil = ValidasiItemTransaksi::normalisasi([$this->item()]);

        $this->assertNull($hasil['error']);
        $this->assertSame(20000.0, $hasil['subtotal']);
        $this->assertSame(2.0, $hasil['items'][0]['jumlah']);
        $this->assertSame(10000.0, $hasil['items'][0]['harga']);
        $this->assertSame(20000.0, $hasil['items'][0]['subtotal']);
    }

    public function testJumlahHarusPositif(): void
    {
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi([$this->item(['jumlah' => 0, 'subtotal' => 0])])['error']);
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi([$this->item(['jumlah' => -1, 'subtotal' => -20000])])['error']);
    }

    public function testJumlahBukanAngkaDitolak(): void
    {
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi([$this->item(['jumlah' => 'abc'])])['error']);
    }

    public function testJumlahMelebihiBatasDitolak(): void
    {
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi([$this->item(['jumlah' => 10000, 'subtotal' => 100000000])])['error']);
    }

    public function testNilaiNegatifDitolak(): void
    {
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi([$this->item(['harga' => -1])])['error']);
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi([$this->item(['subtotal' => -20000])])['error']);
    }

    public function testSubtotalTidakKonsistenDitolak(): void
    {
        // harga x jumlah = 20000, but subtotal says 10000.
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi([$this->item(['subtotal' => 10000])])['error']);
    }

    public function testBannerSubtotalJadiAcuan(): void
    {
        // harga 333 x 3 = 999, but the cashier's total is 1000 -> banner keeps
        // subtotal and derives harga = round(1000 / 3) = 333.
        $hasil = ValidasiItemTransaksi::normalisasi([$this->item([
            'is_banner' => true,
            'harga'     => 333,
            'jumlah'    => 3,
            'subtotal'  => 1000,
        ])]);

        $this->assertNull($hasil['error']);
        $this->assertSame(1000.0, $hasil['subtotal']);
        $this->assertSame(333.0, $hasil['items'][0]['harga']);
        $this->assertSame(1000.0, $hasil['items'][0]['subtotal']);
    }

    public function testDiskonDihitungDariSubtotalHasilNormalisasi(): void
    {
        $hasil = ValidasiItemTransaksi::normalisasi([$this->item()]);

        $kalkulasi = KalkulasiDiskonTransaksi::hitung($hasil['subtotal'], null, 5000);

        $this->assertSame(20000.0, $hasil['subtotal']);
        $this->assertSame(15000.0, $kalkulasi['grand_total']);
    }

    public function testItemBukanArrayDitolak(): void
    {
        $this->assertNotNull(ValidasiItemTransaksi::normalisasi(['bukan-array'])['error']);
    }
}
