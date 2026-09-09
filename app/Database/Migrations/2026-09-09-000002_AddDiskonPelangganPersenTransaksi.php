<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah `transaksi`.`diskon_pelanggan_persen` (nullable) -- snapshot
 * persentase Diskon Pelanggan yang AKTIF DIPAKAI saat transaksi
 * dibuat/diedit, TIDAK PERNAH ikut berubah walau `pelanggan.diskon`
 * (master) diubah belakangan.
 *
 * NULL = diskon transaksi ini murni manual (mekanisme existing,
 * `transaksi.diskon` tetap dipakai apa adanya sebagai nominal Rp).
 * Tidak NULL = diskon Pelanggan sedang aktif, nilainya adalah persen
 * yang dipakai (dikonversi ke Rp oleh
 * App\Services\KalkulasiDiskonTransaksi::hitung() saat itu, hasilnya
 * yang tersimpan di `transaksi.diskon`).
 *
 * Kolom ini murni informasi tambahan (untuk merekonstruksi state
 * checkbox "Diskon Pelanggan" saat transaksi dibuka lagi di halaman
 * edit) -- TIDAK dipakai untuk kalkulasi apa pun selain itu. Sumber
 * kebenaran nilai Rp diskon tetap `transaksi.diskon`, sama seperti
 * sebelum fitur ini ada.
 */
class AddDiskonPelangganPersenTransaksi extends Migration
{
    public function up()
    {
        $this->db->query(
            "ALTER TABLE `transaksi` ADD COLUMN `diskon_pelanggan_persen` " .
            "DECIMAL(5,2) NULL DEFAULT NULL AFTER `diskon`"
        );
    }

    public function down()
    {
        $this->db->query(
            "ALTER TABLE `transaksi` DROP COLUMN `diskon_pelanggan_persen`"
        );
    }
}
