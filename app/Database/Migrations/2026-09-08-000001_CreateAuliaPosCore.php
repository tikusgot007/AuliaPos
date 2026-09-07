<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * AuliaPos v2.x database baseline.
 *
 * This migration intentionally defines the complete AuliaPos core schema in
 * one place, based on the verified MariaDB structure exported from the v2.0
 * database. It is a baseline, not a history of incremental ALTER TABLEs.
 *
 * Initial/master data is handled separately by AuliaPosInitialSeeder.
 * Operational data (customers, transactions, payments, cash records, and
 * schedules) is intentionally NOT seeded.
 *
 * The CodeIgniter `migrations` table is not created here; the framework owns
 * that table.
 */
class CreateAuliaPosCore extends Migration
{
    public function up()
    {
        // Parent/reference tables first.
        $this->db->query(<<<'SQL'
CREATE TABLE `bonus_rule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `kode` varchar(50) NOT NULL,
  `persentase` decimal(5,2) NOT NULL DEFAULT 0.00,
  `aktif` tinyint(1) NOT NULL DEFAULT 1,
  `tanggal_mulai` date DEFAULT NULL,
  `tanggal_selesai` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bonus_rule_kode` (`kode`),
  KEY `idx_bonus_rule_aktif` (`aktif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `nama` varchar(100) DEFAULT NULL,
  `inisial` varchar(20) DEFAULT NULL,
  `divisi` varchar(50) DEFAULT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','kasir') DEFAULT 'kasir',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `kategori` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(50) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `pelanggan` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `diskon` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `master_jadwal` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Product master depends on bonus_rule and kategori.
        $this->db->query(<<<'SQL'
CREATE TABLE `produk` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barcode` varchar(50) DEFAULT NULL,
  `nama` varchar(200) NOT NULL,
  `kategori_id` int(11) NOT NULL,
  `satuan` varchar(20) DEFAULT 'pcs',
  `sort_order` int(11) DEFAULT 0,
  `panjang` int(11) DEFAULT NULL COMMENT 'Panjang dalam mm (untuk produk cetak)',
  `lebar` int(11) DEFAULT NULL COMMENT 'Lebar dalam mm (untuk produk cetak)',
  `harga_jual` decimal(15,2) NOT NULL,
  `harga_beli` decimal(15,2) DEFAULT NULL,
  `bonus_rule_id` int(11) DEFAULT NULL,
  `stok` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode_unique` (`barcode`),
  KEY `idx_kategori` (`kategori_id`),
  KEY `idx_produk_bonus_rule` (`bonus_rule_id`),
  KEY `idx_active_locked` (`is_active`,`is_locked`),
  CONSTRAINT `fk_produk_bonus_rule` FOREIGN KEY (`bonus_rule_id`) REFERENCES `bonus_rule` (`id`) ON DELETE SET NULL,
  CONSTRAINT `produk_ibfk_1` FOREIGN KEY (`kategori_id`) REFERENCES `kategori` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `master_jadwal_detail` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `master_jadwal_id` int(11) unsigned NOT NULL,
  `karyawan_id` int(11) NOT NULL,
  `hari` tinyint(1) unsigned NOT NULL COMMENT '1=Senin .. 7=Minggu',
  `shift` enum('P','S','PM','L') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_master_detail_karyawan_hari` (`master_jadwal_id`,`karyawan_id`,`hari`),
  KEY `idx_master_detail_master` (`master_jadwal_id`),
  KEY `idx_master_detail_karyawan` (`karyawan_id`),
  CONSTRAINT `fk_master_detail_karyawan` FOREIGN KEY (`karyawan_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_master_detail_master` FOREIGN KEY (`master_jadwal_id`) REFERENCES `master_jadwal` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `jadwal` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `karyawan_id` int(11) NOT NULL,
  `tanggal` date NOT NULL,
  `shift` enum('P','S','PM','L') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jadwal_karyawan_tanggal` (`karyawan_id`,`tanggal`),
  KEY `idx_jadwal_tanggal` (`tanggal`),
  KEY `idx_jadwal_karyawan` (`karyawan_id`),
  CONSTRAINT `fk_jadwal_karyawan` FOREIGN KEY (`karyawan_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `transaksi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merged_into` int(11) DEFAULT NULL,
  `kode_invoice` varchar(20) NOT NULL,
  `no_order` int(50) DEFAULT NULL,
  `tanggal` datetime DEFAULT current_timestamp(),
  `pelanggan_id` int(11) DEFAULT NULL,
  `kasir_id` int(11) NOT NULL,
  `subtotal` decimal(15,2) NOT NULL,
  `diskon` decimal(15,2) DEFAULT 0.00,
  `pajak` decimal(15,2) DEFAULT 0.00,
  `grand_total` decimal(15,2) NOT NULL,
  `total_dibayar` decimal(15,2) DEFAULT 0.00,
  `status_pembayaran` enum('belum_bayar','dp','lunas') DEFAULT 'belum_bayar',
  `status` enum('proses','selesai','diambil','batal') DEFAULT 'proses',
  `sumber` enum('kasir_pos','aplikasi_cetak_foto') DEFAULT 'kasir_pos',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `selisih_pembulatan` decimal(15,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode_invoice` (`kode_invoice`),
  KEY `pelanggan_id` (`pelanggan_id`),
  KEY `kasir_id` (`kasir_id`),
  KEY `idx_tanggal` (`tanggal`),
  KEY `idx_invoice` (`kode_invoice`),
  CONSTRAINT `transaksi_ibfk_1` FOREIGN KEY (`pelanggan_id`) REFERENCES `pelanggan` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transaksi_ibfk_2` FOREIGN KEY (`kasir_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `detail_transaksi` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaksi_id` int(11) NOT NULL,
  `produk_id` int(11) NOT NULL,
  `nama_produk` varchar(200) DEFAULT NULL,
  `kategori_id` int(11) DEFAULT NULL,
  `jumlah` decimal(10,2) NOT NULL,
  `harga_satuan` decimal(15,2) NOT NULL,
  `subtotal` decimal(15,2) NOT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `transaksi_id` (`transaksi_id`),
  KEY `produk_id` (`produk_id`),
  CONSTRAINT `detail_transaksi_ibfk_1` FOREIGN KEY (`transaksi_id`) REFERENCES `transaksi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detail_transaksi_ibfk_2` FOREIGN KEY (`produk_id`) REFERENCES `produk` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `pembayaran` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaksi_id` int(11) NOT NULL,
  `tanggal` datetime DEFAULT current_timestamp(),
  `jumlah` decimal(15,2) NOT NULL,
  `uang_diterima` decimal(15,2) DEFAULT NULL,
  `kembalian` decimal(15,2) NOT NULL DEFAULT 0.00,
  `metode` enum('tunai','qris','transfer') NOT NULL,
  `keterangan` varchar(100) DEFAULT NULL,
  `kasir_id` int(11) NOT NULL,
  `status` enum('aktif','reversed') NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `kasir_id` (`kasir_id`),
  KEY `idx_transaksi` (`transaksi_id`),
  KEY `idx_transaksi_status` (`transaksi_id`,`status`),
  CONSTRAINT `pembayaran_ibfk_1` FOREIGN KEY (`transaksi_id`) REFERENCES `transaksi` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pembayaran_ibfk_2` FOREIGN KEY (`kasir_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `cash_expense` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tanggal` datetime NOT NULL DEFAULT current_timestamp(),
  `kategori` enum('kas_awal_hari','pengeluaran','refund_penjualan','penyesuaian') NOT NULL,
  `nominal` decimal(15,2) NOT NULL,
  `keterangan` varchar(255) DEFAULT NULL,
  `penerima` varchar(150) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cash_expense_tanggal` (`tanggal`),
  KEY `idx_cash_expense_kategori` (`kategori`),
  KEY `idx_cash_expense_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE `cash_opname` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tanggal` datetime NOT NULL DEFAULT current_timestamp(),
  `saldo_awal_hari` decimal(15,2) DEFAULT 0.00,
  `pemasukan_tunai` decimal(15,2) DEFAULT 0.00,
  `pengeluaran_tunai` decimal(15,2) DEFAULT 0.00,
  `saldo_sistem` decimal(15,2) NOT NULL,
  `saldo_fisik` decimal(15,2) NOT NULL,
  `selisih` decimal(15,2) NOT NULL,
  `status_selisih` enum('sesuai','kurang','lebih') NOT NULL,
  `alasan_selisih` varchar(255) DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cash_opname_tanggal` (`tanggal`),
  KEY `idx_cash_opname_user` (`user_id`),
  CONSTRAINT `fk_cash_opname_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);

        // Views are recreated without a hard-coded DEFINER so the baseline
        // also works on another local/server MySQL account.
        $this->db->query('DROP VIEW IF EXISTS `v_daftar_pembayaran`');
        $this->db->query(<<<'SQL'
CREATE VIEW `v_daftar_pembayaran` AS SELECT
    p.id AS pembayaran_id,
    p.transaksi_id,
    t.kode_invoice,
    t.tanggal AS tanggal_transaksi,
    t.status AS status_transaksi,
    p.tanggal AS tanggal_pembayaran,
    pl.nama AS nama_pelanggan,
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

        $this->db->query('DROP VIEW IF EXISTS `v_pembayaran_item_harian`');
        $this->db->query(<<<'SQL'
CREATE VIEW `v_pembayaran_item_harian` AS SELECT
    DATE(p.tanggal) AS tanggal_pembayaran,
    p.id AS pembayaran_id,
    p.transaksi_id,
    t.kode_invoice,
    t.status AS status_transaksi,
    dt.id AS detail_id,
    dt.produk_id,
    dt.nama_produk,
    dt.kategori_id,
    dt.jumlah,
    dt.harga_satuan,
    dt.subtotal AS subtotal_item,
    total_detail.total_subtotal,
    p.metode,
    p.jumlah AS jumlah_pembayaran,
    CASE
        WHEN total_detail.total_subtotal > 0
        THEN p.jumlah * (dt.subtotal / total_detail.total_subtotal)
        ELSE 0
    END AS nilai_teralokasi
FROM pembayaran p
JOIN transaksi t ON t.id = p.transaksi_id
JOIN detail_transaksi dt ON dt.transaksi_id = p.transaksi_id
JOIN (
    SELECT transaksi_id, SUM(subtotal) AS total_subtotal
    FROM detail_transaksi
    GROUP BY transaksi_id
) total_detail ON total_detail.transaksi_id = p.transaksi_id
WHERE t.status != 'batal'
  AND p.status = 'aktif'
SQL);
    }

    public function down()
    {
        $this->db->query('DROP VIEW IF EXISTS `v_pembayaran_item_harian`');
        $this->db->query('DROP VIEW IF EXISTS `v_daftar_pembayaran`');

        // Drop children before parents because of foreign keys.
        $this->forge->dropTable('cash_opname', true);
        $this->forge->dropTable('cash_expense', true);
        $this->forge->dropTable('pembayaran', true);
        $this->forge->dropTable('detail_transaksi', true);
        $this->forge->dropTable('transaksi', true);
        $this->forge->dropTable('jadwal', true);
        $this->forge->dropTable('master_jadwal_detail', true);
        $this->forge->dropTable('produk', true);
        $this->forge->dropTable('master_jadwal', true);
        $this->forge->dropTable('pelanggan', true);
        $this->forge->dropTable('kategori', true);
        $this->forge->dropTable('users', true);
        $this->forge->dropTable('bonus_rule', true);
    }
}
