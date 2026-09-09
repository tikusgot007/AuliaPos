<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah status baru 'mangkrak' ke enum `transaksi`.`status`.
 *
 * Beda dengan 'batal' ("transaksi ini dianggap tidak pernah terjadi"):
 * 'mangkrak' adalah transaksi yang BENERAN terjadi (ada order, mungkin
 * sudah ada DP/pekerjaan berjalan) tapi macet tanpa kejelasan --
 * belum dibayar, pelanggan tidak mengambil, dst. Tujuannya supaya
 * transaksi begini bisa "dilepas" dari radar aktif (Tagihan/badge/
 * reminder) TANPA dianggap tidak pernah terjadi, dan tetap ikut proses
 * Archive normal setelah 6 bulan seperti transaksi lain (lihat
 * docs/aturan-bisnis-AULIA.md Section 28 & keputusan terkait status
 * mangkrak).
 *
 * Nilai enum lain (termasuk `diambil` yang sudah tidak dipakai di
 * level aplikasi, lihat Section 1) SENGAJA dipertahankan apa adanya --
 * migration ini murni menambah satu nilai baru, bukan membersihkan
 * yang lama.
 */
class AddStatusMangkrakTransaksi extends Migration
{
    public function up()
    {
        $this->db->query(
            "ALTER TABLE `transaksi` MODIFY COLUMN `status` " .
            "ENUM('proses','selesai','diambil','batal','mangkrak') " .
            "NOT NULL DEFAULT 'proses'"
        );
    }

    public function down()
    {
        // Turunkan balik ke enum semula. Kalau ada baris berstatus
        // 'mangkrak' saat rollback dijalankan, MySQL akan menolak
        // ALTER ini (data tidak sesuai enum baru) -- itu perilaku
        // yang benar/aman: rollback tidak akan diam-diam menghapus
        // atau mengubah status transaksi yang sudah ditandai mangkrak.
        // Ubah dulu status baris tsb secara manual sebelum rollback
        // kalau memang perlu.
        $this->db->query(
            "ALTER TABLE `transaksi` MODIFY COLUMN `status` " .
            "ENUM('proses','selesai','diambil','batal') " .
            "NOT NULL DEFAULT 'proses'"
        );
    }
}
