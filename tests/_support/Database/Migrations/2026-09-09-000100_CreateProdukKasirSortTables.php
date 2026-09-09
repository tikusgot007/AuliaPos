<?php

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabel minimal untuk menguji urutan produk di halaman kasir
 * (ProdukModel::getProdukAktif / getProdukAktifWithPopularity).
 * Sengaja hanya kolom yang dipakai query tersebut, pakai Forge biar
 * jalan di SQLite test DB.
 */
class CreateProdukKasirSortTables extends Migration
{
    protected $DBGroup = 'tests';

    public function up(): void
    {
        $this->forge->addField('id');
        $this->forge->addField([
            'barcode'    => ['type' => 'varchar', 'constraint' => 64, 'null' => true],
            'nama'       => ['type' => 'varchar', 'constraint' => 200],
            'kategori_id' => ['type' => 'integer', 'null' => true],
            'satuan'     => ['type' => 'varchar', 'constraint' => 32, 'default' => 'pcs'],
            'harga_jual' => ['type' => 'integer', 'default' => 0],
            'harga_beli' => ['type' => 'integer', 'default' => 0],
            'is_active'  => ['type' => 'integer', 'default' => 1],
            'is_locked'  => ['type' => 'integer', 'default' => 0],
            'created_at' => ['type' => 'datetime', 'null' => true],
            'updated_at' => ['type' => 'datetime', 'null' => true],
        ]);
        $this->forge->createTable('produk');

        $this->forge->addField('id');
        $this->forge->addField([
            'produk_id' => ['type' => 'integer'],
        ]);
        $this->forge->createTable('detail_transaksi');
    }

    public function down(): void
    {
        $this->forge->dropTable('detail_transaksi', true);
        $this->forge->dropTable('produk', true);
    }
}
