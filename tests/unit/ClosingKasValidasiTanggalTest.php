<?php

use App\Models\ClosingKasModel;
use PHPUnit\Framework\TestCase;

/**
 * ClosingKasModel::validasiTanggal() murni pure (tidak menyentuh DB),
 * jadi diuji dengan PHPUnit\Framework\TestCase polos -- pola sama
 * dengan tests/unit/KalkulasiStatusPembayaranTest.php.
 *
 * Ini adalah satu-satunya aturan bisnis non-trivial di fitur Closing
 * Kas yang bisa diuji tanpa bootstrap DB: menolak tanggal hari ini
 * & masa depan, sambil menerima tanggal lampau.
 *
 * @internal
 */
final class ClosingKasValidasiTanggalTest extends TestCase
{
    public function testTanggalLampauValid(): void
    {
        $this->assertNull(ClosingKasModel::validasiTanggal('2026-09-10', '2026-09-14'));
    }

    public function testHariIniDitolak(): void
    {
        $this->assertNotNull(ClosingKasModel::validasiTanggal('2026-09-14', '2026-09-14'));
    }

    public function testMasaDepanDitolak(): void
    {
        $this->assertNotNull(ClosingKasModel::validasiTanggal('2026-09-15', '2026-09-14'));
    }

    public function testFormatTidakValidDitolak(): void
    {
        $this->assertNotNull(ClosingKasModel::validasiTanggal('14-09-2026', '2026-09-14'));
        $this->assertNotNull(ClosingKasModel::validasiTanggal(null, '2026-09-14'));
        $this->assertNotNull(ClosingKasModel::validasiTanggal('2026-02-30', '2026-09-14'));
    }
}
