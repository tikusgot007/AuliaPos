<?php

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Kolom tambahan di tabel `detail_transaksi` test (dibuat minimal oleh
 * 2026-09-09-000100_CreateProdukKasirSortTables -- cuma id+produk_id)
 * + tabel `cash_expense` (belum ada) -- supaya bisa dipakai menguji
 * alokasi proporsional per kategori & exclude batal di
 * Laporan::getLaporanBulananData() (lihat
 * tests/session/LaporanBulananExcludeBatalTest.php). Idempoten (cek
 * fieldExists/tableExists dulu) mengikuti pola
 * 2026-09-11-000300_CreatePhase2PaymentTables.
 */
class AddDetailTransaksiKategoriKolom extends Migration
{
    protected $DBGroup = 'tests';

    public function up(): void
    {
        $kolom = [
            'transaksi_id' => ['type' => 'integer', 'null' => true],
            'nama_produk'  => ['type' => 'varchar', 'constraint' => 200, 'null' => true],
            'kategori_id'  => ['type' => 'integer', 'null' => true],
            'jumlah'       => ['type' => 'integer', 'default' => 0],
            'harga_satuan' => ['type' => 'integer', 'default' => 0],
            'subtotal'     => ['type' => 'integer', 'default' => 0],
        ];

        $tambah = [];
        foreach ($kolom as $nama => $definisi) {
            if (! $this->db->fieldExists($nama, 'detail_transaksi')) {
                $tambah[$nama] = $definisi;
            }
        }

        if ($tambah !== []) {
            $this->forge->addColumn('detail_transaksi', $tambah);
        }

        if (! $this->db->tableExists('cash_expense')) {
            $this->forge->addField('id');
            $this->forge->addField([
                'tanggal'    => ['type' => 'datetime', 'null' => true],
                'kategori'   => ['type' => 'varchar', 'constraint' => 32, 'null' => true],
                'nominal'    => ['type' => 'integer', 'default' => 0],
                'user_id'    => ['type' => 'integer', 'null' => true],
            ]);
            $this->forge->createTable('cash_expense');
        }
    }

    public function down(): void
    {
        // Sengaja tidak drop kolom (SQLite tidak mendukung DROP COLUMN
        // andal) -- konsisten dengan pola CreatePhase2PaymentTables.
    }
}
