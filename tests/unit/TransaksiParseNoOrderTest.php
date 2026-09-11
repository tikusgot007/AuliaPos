<?php

use App\Controllers\Transaksi;
use PHPUnit\Framework\TestCase;

/**
 * Safety net untuk Transaksi::parseNoOrder() (TP) SEBELUM
 * dikonsolidasikan dengan parse_no_order() (GP, app/Helpers/order_helper.php).
 *
 * TP dipakai HANYA di Transaksi::applyKeywordFilter() (global search
 * daftar transaksi) -- beda kontrak dari GP yang dipakai di form
 * input no_order (Api::simpanTransaksi(), Transaksi::updateTransaksi()).
 * Satu-satunya perbedaan sengaja: TP mengembalikan null untuk keyword
 * angka polos (dibiarkan ditangani LIKE biasa), GP mengembalikan
 * integer apa adanya untuk angka polos (dibutuhkan form input no_order
 * transaksi lama). Lihat audit "TP vs GP" -- jangan disatukan tanpa
 * mempertahankan guard angka-polos ini.
 *
 * Method-nya private dan murni (tidak menyentuh $this->request/model),
 * jadi diuji lewat Reflection tanpa bootstrap framework -- pola sama
 * spirit-nya dengan unit test murni lain di folder ini.
 *
 * @internal
 */
final class TransaksiParseNoOrderTest extends TestCase
{
    private function parse($formatted)
    {
        $controller = (new \ReflectionClass(Transaksi::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Transaksi::class, 'parseNoOrder');
        $method->setAccessible(true);

        return $method->invoke($controller, $formatted);
    }

    public function testAngkaPolosMengembalikanNull(): void
    {
        // Sengaja null -- keyword angka polos ditangani cukup lewat
        // LIKE biasa di applyKeywordFilter(), bukan exact no_order.
        $this->assertNull($this->parse('230'));
        $this->assertNull($this->parse('100003'));
        $this->assertNull($this->parse('0001'));
    }

    public function testFormatHurufAngkaDidecodeKeNomorInternal(): void
    {
        // A0003 -> ambang(100000) + posisi(3) = 100003
        $this->assertSame(100003, $this->parse('A0003'));

        // Huruf kecil tetap didecode benar (strtoupper di awal method).
        $this->assertSame(100003, $this->parse('a0003'));
    }

    public function testFormatTidakDikenalMengembalikanNull(): void
    {
        $this->assertNull($this->parse(''));
        $this->assertNull($this->parse('ABC'));
        $this->assertNull($this->parse('123ABC'));
    }
}
