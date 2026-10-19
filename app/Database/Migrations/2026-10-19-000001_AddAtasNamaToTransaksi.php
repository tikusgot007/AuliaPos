<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah `transaksi`.`atas_nama` (nullable): nama yang disebut pembeli bila
 * beda dari nama master pelanggan (mis. master "SUHERMAN 1234", atas nama
 * "Alby"). Murni label tampilan; piutang/diskon/riwayat tetap ikut
 * `pelanggan_id`. NULL = sama dengan nama master.
 */
class AddAtasNamaToTransaksi extends Migration
{
    public function up()
    {
        $this->db->query('ALTER TABLE `transaksi` ADD COLUMN `atas_nama` VARCHAR(100) NULL DEFAULT NULL AFTER `pelanggan_id`');
    }

    public function down()
    {
        $this->db->query('ALTER TABLE `transaksi` DROP COLUMN `atas_nama`');
    }
}
