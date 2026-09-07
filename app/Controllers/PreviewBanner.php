<?php

namespace App\Controllers;

/**
 * Halaman "Preview Banner" -- alat bantu internal untuk staff membuat
 * gambar preview banner (dengan garis ukuran & crop mark) sebelum
 * dicetak, untuk dikirim ke customer via WhatsApp minta approval.
 *
 * SENGAJA murni client-side (upload gambar, hitung ukuran, generate
 * preview via <canvas>, download) -- tidak ada data yang dikirim atau
 * disimpan ke server sama sekali. Tidak terhubung ke transaksi/tabel
 * manapun (keputusan sadar, lihat docs/aturan-bisnis-AULIA.md
 * Section 26).
 *
 * Alat aslinya (public/tools/preview-banner.html) SENGAJA tidak
 * diubah sama sekali dan ditampilkan lewat <iframe> di
 * views/preview-banner/index.php -- supaya CSS-nya (yang pakai nama
 * class umum seperti .btn, .btn-primary) tidak bentrok dengan CSS
 * Bootstrap yang dipakai di seluruh halaman AULIA lainnya.
 */
class PreviewBanner extends BaseController
{
    public function index()
    {
        $data = [
            'title'   => 'Preview Banner | AULIA',
            'content' => 'preview-banner/index',
        ];

        return view('layout/main', $data);
    }
}
