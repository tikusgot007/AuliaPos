<?php

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 2 — kolom & tabel minimal untuk menguji alur pembayaran /
 * status transaksi lewat TransaksiModel & PembayaranModel di SQLite
 * test DB.
 *
 * Tabel `transaksi` dibuat migrasi 000200 (skema minimal uji filter
 * Tagihan); di sini hanya DITAMBAH kolom yang dibutuhkan TransaksiModel
 * (timestamps, sumber, subtotal, dst) + tabel `pembayaran` yang belum
 * ada. up() dibuat idempoten supaya siklus refresh (down->up) di SQLite
 * — yang tidak mendukung DROP COLUMN andal — tetap aman.
 */
class CreatePhase2PaymentTables extends Migration
{
    protected $DBGroup = 'tests';

    public function up(): void
    {
        $kolomTransaksi = [
            'subtotal'                => ['type' => 'integer', 'default' => 0],
            'diskon'                  => ['type' => 'integer', 'default' => 0],
            'diskon_pelanggan_persen' => ['type' => 'integer', 'null' => true],
            'pajak'                   => ['type' => 'integer', 'default' => 0],
            'selisih_pembulatan'      => ['type' => 'integer', 'default' => 0],
            'sumber'                  => ['type' => 'varchar', 'constraint' => 32, 'default' => 'kasir_pos'],
            'merged_into'             => ['type' => 'integer', 'null' => true],
            'created_at'              => ['type' => 'datetime', 'null' => true],
            'updated_at'              => ['type' => 'datetime', 'null' => true],
        ];

        $tambah = [];
        foreach ($kolomTransaksi as $nama => $definisi) {
            if (! $this->db->fieldExists($nama, 'transaksi')) {
                $tambah[$nama] = $definisi;
            }
        }
        if ($tambah !== []) {
            $this->forge->addColumn('transaksi', $tambah);
        }

        if (! $this->db->tableExists('pembayaran')) {
            $this->forge->addField('id');
            $this->forge->addField([
                'transaksi_id'  => ['type' => 'integer'],
                'tanggal'       => ['type' => 'datetime', 'null' => true],
                'jumlah'        => ['type' => 'integer', 'default' => 0],
                'uang_diterima' => ['type' => 'integer', 'null' => true],
                'kembalian'     => ['type' => 'integer', 'default' => 0],
                'metode'        => ['type' => 'varchar', 'constraint' => 32, 'null' => true],
                'keterangan'    => ['type' => 'varchar', 'constraint' => 255, 'null' => true],
                'kasir_id'      => ['type' => 'integer', 'null' => true],
                'status'        => ['type' => 'varchar', 'constraint' => 16, 'default' => 'aktif'],
            ]);
            $this->forge->createTable('pembayaran');
        }
    }

    public function down(): void
    {
        // Hanya tabel `pembayaran` yang di-drop. Kolom tambahan di
        // `transaksi` dibiarkan: migrasi 000200 akan men-drop seluruh
        // tabel `transaksi` saat regress, dan up() di atas idempoten.
        $this->forge->dropTable('pembayaran', true);
    }
}
