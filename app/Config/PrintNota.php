<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Konfigurasi "Cetak Langsung" (Nota) -- lihat App\Controllers\Cetak::notaLangsung().
 *
 * SENGAJA jadi Config class terpisah (bukan konstanta di controller)
 * supaya printer tujuan & lokasi tool cetak bisa diubah admin/ops
 * lewat `.env` tanpa ubah kode, TAPI tetap 100% dari sisi server --
 * browser TIDAK PERNAH bisa mengirim path printer sendiri (lihat
 * audit keamanan di docs Section 30).
 *
 * Override lewat .env (opsional, default di bawah sudah cukup untuk
 * kebanyakan kasus):
 *   printnota.printerLangsung = "\\\\AULIA-DP1\\L300"
 *   printnota.sumatraPdfPath  = "C:\Tools\SumatraPDF\SumatraPDF.exe"
 */
class PrintNota extends BaseConfig
{
    /**
     * Target printer untuk "Cetak Langsung" -- HARUS UNC path Windows
     * yang valid. Terpisah total dari target Thermal existing
     * (smb://guest@aulia6/POS-58, lihat Cetak::thermal()) -- jangan
     * disamakan/digabung.
     */
    public string $printerLangsung = '\\\\AAN-PC\\L3210';

    /**
     * Path ke executable command-line yang dipakai mengirim PDF ke
     * printer. Default mengasumsikan SumatraPDF (portable, gratis,
     * mendukung flag `-print-to <printer> -silent <file>` -- pilihan
     * paling umum untuk automasi print PDF di Windows). Kalau server
     * production pakai tool lain (mis. PDFtoPrinter.exe, format
     * argumennya beda), sesuaikan lewat .env DAN cek/format ulang
     * argumen di Cetak::kirimPdfKePrinter().
     */
    public string $sumatraPdfPath = 'C:\\Tools\\SumatraPDF\\SumatraPDF.exe';

    /**
     * Diteruskan ke flag `-print-settings` SumatraPDF -- MEMAKSA
     * printer pakai kertas A6 landscape, supaya tidak bergantung pada
     * ukuran kertas default printer (yang bisa saja A4/Letter dan
     * bikin nota ke-scale walau PDF-nya sendiri sudah A6 landscape
     * dari Dompdf).
     *
     * Format string ini SPESIFIK ke SumatraPDF (comma-separated):
     * "landscape"  -> paksa orientasi landscape
     * "paper=A6"   -> paksa ukuran kertas A6
     * "fit"        -> skalakan konten ke kertas yang BENAR-BENAR
     *                 terpasang di printer (BUKAN "noscale")
     *
     * CATATAN (2026-09-09): sempat dicoba "noscale" (cetak 100% apa
     * adanya, tanpa penyesuaian) tapi hasilnya konten terpotong --
     * sebagian besar nota (header/item/total) tercetak DI LUAR area
     * kertas fisik, cuma footer yang kelihatan. Penyebabnya: kertas
     * fisik yang terpasang tidak PERSIS berukuran A6 (atau setting
     * default printer di Windows belum benar-benar A6), dan
     * "noscale" tidak mentolerir selisih itu sama sekali. "fit"
     * dipilih sebagai default yang lebih aman -- menyesuaikan ke
     * kertas yang ada, dengan risiko sedikit margin/skala tidak
     * 100% persis, tapi seluruh konten dijamin tidak terpotong.
     *
     * TIDAK ADA opsi "kualitas/DPI" di sini -- SumatraPDF tidak
     * punya flag command-line untuk itu. Kualitas cetak (draft/
     * normal/high) adalah setting driver printer di Windows, bukan
     * sesuatu yang bisa dikontrol lewat command ini -- lihat catatan
     * di docs Section 30.6 untuk cara mengaturnya di level printer.
     */
    public string $printSettings = 'protrait,paper=A6';

    /**
     * Batas waktu (detik) menunggu proses command-line cetak selesai
     * sebelum dianggap gagal -- mencegah request AJAX menggantung
     * lama kalau printer/tool hang.
     */
    public int $timeoutDetik = 25;
}
