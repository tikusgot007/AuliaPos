<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * v_daftar_pembayaran: nama_pelanggan tampil "Alby (SUHERMAN 1234)" bila
 * transaksi punya atas_nama (Tahap 2 identitas pelanggan). Kolom lain sama
 * persis dengan definisi di CreateAuliaPosCore.
 */
class UpdateVDaftarPembayaranAtasNama extends Migration
{
    public function up()
    {
        $this->buat("(CASE WHEN t.atas_nama IS NULL OR t.atas_nama = '' THEN pl.nama ELSE CONCAT(t.atas_nama, ' (', COALESCE(pl.nama, ''), ')') END)");
    }

    public function down()
    {
        $this->buat('pl.nama');
    }

    private function buat(string $ekspresiNama): void
    {
        $this->db->query('DROP VIEW IF EXISTS `v_daftar_pembayaran`');
        $this->db->query(<<<SQL
CREATE VIEW `v_daftar_pembayaran` AS SELECT
    p.id AS pembayaran_id,
    p.transaksi_id,
    t.kode_invoice,
    t.tanggal AS tanggal_transaksi,
    t.status AS status_transaksi,
    p.tanggal AS tanggal_pembayaran,
    {$ekspresiNama} AS nama_pelanggan,
    u.nama AS nama_kasir,
    u.username AS username_kasir,
    u.inisial AS inisial_kasir,
    p.metode,
    p.jumlah,
    p.uang_diterima,
    p.kembalian,
    p.keterangan,
    t.pelanggan_id,
    p.kasir_id
FROM pembayaran p
JOIN transaksi t ON t.id = p.transaksi_id
LEFT JOIN pelanggan pl ON pl.id = t.pelanggan_id
LEFT JOIN users u ON u.id = p.kasir_id
WHERE t.status != 'batal'
  AND p.status = 'aktif'
SQL);
    }
}
