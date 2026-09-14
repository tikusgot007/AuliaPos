<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabel `closing_kas`: rekonsiliasi kas SELURUH SISTEM oleh admin, per
 * business date yang sudah lewat. Ini BUKAN opname kasir (`cash_opname`)
 * -- opname adalah snapshot aktual milik kasir dengan timestamp kapan
 * opname dilakukan; closing kas adalah snapshot final admin untuk satu
 * tanggal kalender (`tanggal` selalu disimpan jam 23:59:59), boleh
 * diedit kapan saja, dan murni catatan audit -- tidak mengubah saldo,
 * tidak membuat transaksi kas, tidak menjadi saldo pembuka.
 *
 * Satu tanggal hanya boleh punya satu record (unique index di
 * `tanggal`), di-enforce juga di level DB supaya aman dari race
 * condition, bukan cuma dicek di aplikasi.
 */
class CreateClosingKasTable extends Migration
{
    public function up()
    {
        $this->db->query(<<<'SQL'
CREATE TABLE `closing_kas` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tanggal` datetime NOT NULL,
  `saldo_sistem` decimal(15,2) NOT NULL,
  `saldo_fisik` decimal(15,2) NOT NULL,
  `selisih` decimal(15,2) NOT NULL,
  `updated_at` datetime NOT NULL,
  `updated_by` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_closing_kas_tanggal` (`tanggal`),
  KEY `idx_closing_kas_updated_by` (`updated_by`),
  CONSTRAINT `fk_closing_kas_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
    }

    public function down()
    {
        $this->forge->dropTable('closing_kas', true);
    }
}
