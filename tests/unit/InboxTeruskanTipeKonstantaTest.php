<?php

use App\Controllers\Inbox;
use PHPUnit\Framework\TestCase;

/**
 * TEST-602 (PRN-601): relasi subset daftar tipe sumber Teruskan.
 *
 * `TIPE_TERUSKAN_DIIZINKAN` DITURUNKAN dari `TIPE_TERUSKAN_LAMPIRAN`, jadi
 * test ini mengunci RELASINYA (bukan sekadar nilai final) supaya penambahan
 * tipe lampiran di masa depan otomatis diizinkan dan tidak bisa drift.
 *
 * Refleksi dipakai karena kedua konstanta bersifat privat; test tidak pernah
 * menyalin nilainya, jadi aman bila isi daftar berubah.
 *
 * @internal
 */
final class InboxTeruskanTipeKonstantaTest extends TestCase
{
    private ReflectionClass $refleksi;

    protected function setUp(): void
    {
        $this->refleksi = new ReflectionClass(Inbox::class);
    }

    /**
     * @return list<string>
     */
    private function konstanta(string $nama): array
    {
        $nilai = $this->refleksi->getConstant($nama);

        $this->assertIsArray($nilai, "Inbox::{$nama} harus berupa array.");

        return array_values($nilai);
    }

    public function testSetiapTipeLampiranAdaDiDaftarTipeDiizinkan(): void
    {
        $lampiran  = $this->konstanta('TIPE_TERUSKAN_LAMPIRAN');
        $diizinkan = $this->konstanta('TIPE_TERUSKAN_DIIZINKAN');

        $this->assertNotEmpty($lampiran, 'Daftar lampiran tidak boleh kosong.');

        foreach ($lampiran as $tipe) {
            $this->assertContains(
                $tipe,
                $diizinkan,
                "PRN-601: tipe lampiran '{$tipe}' wajib ikut di TIPE_TERUSKAN_DIIZINKAN."
            );
        }
    }

    public function testTeksHanyaAdaDiDaftarDiizinkanBukanDiDaftarLampiran(): void
    {
        $lampiran  = $this->konstanta('TIPE_TERUSKAN_LAMPIRAN');
        $diizinkan = $this->konstanta('TIPE_TERUSKAN_DIIZINKAN');

        $this->assertContains('text', $diizinkan, 'Teks selalu boleh diteruskan lewat jalur teks.');
        $this->assertNotContains('text', $lampiran, 'Teks bukan lampiran; jangan disatukan ke daftar media.');
    }

    public function testDaftarDiizinkanTidakPunyaTipeDuplikat(): void
    {
        $diizinkan = $this->konstanta('TIPE_TERUSKAN_DIIZINKAN');

        $this->assertSame(
            $diizinkan,
            array_values(array_unique($diizinkan)),
            'Derivasi PRN-601 tidak boleh menghasilkan entri duplikat.'
        );
    }
}
