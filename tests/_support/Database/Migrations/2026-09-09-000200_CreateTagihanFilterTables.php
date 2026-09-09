<?php

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tabel minimal untuk menguji filter rentang tanggal di Tagihan::index()
 * (query join transaksi + pelanggan + users, filter tanggal/status).
 * Forge dipakai supaya jalan di SQLite test DB.
 */
class CreateTagihanFilterTables extends Migration
{
    protected $DBGroup = 'tests';

    public function up(): void
    {
        $this->forge->addField('id');
        $this->forge->addField([
            'username'      => ['type' => 'varchar', 'constraint' => 100, 'null' => true],
            'profile_photo' => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
        ]);
        $this->forge->createTable('users');

        $this->forge->addField('id');
        $this->forge->addField([
            'nama' => ['type' => 'varchar', 'constraint' => 200, 'null' => true],
        ]);
        $this->forge->createTable('pelanggan');

        $this->forge->addField('id');
        $this->forge->addField([
            'kode_invoice'      => ['type' => 'varchar', 'constraint' => 64, 'null' => true],
            'no_order'          => ['type' => 'integer', 'null' => true],
            'tanggal'           => ['type' => 'datetime', 'null' => true],
            'pelanggan_id'      => ['type' => 'integer', 'null' => true],
            'kasir_id'          => ['type' => 'integer', 'null' => true],
            'grand_total'       => ['type' => 'integer', 'default' => 0],
            'total_dibayar'     => ['type' => 'integer', 'default' => 0],
            'status_pembayaran' => ['type' => 'varchar', 'constraint' => 32, 'default' => 'belum_bayar'],
            'status'            => ['type' => 'varchar', 'constraint' => 32, 'default' => 'proses'],
        ]);
        $this->forge->createTable('transaksi');
    }

    public function down(): void
    {
        $this->forge->dropTable('transaksi', true);
        $this->forge->dropTable('pelanggan', true);
        $this->forge->dropTable('users', true);
    }
}
