<?php

namespace Tests\Support\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tambah kolom role/is_active/priority/inisial ke `users` test + tabel
 * `jadwal` minimal, untuk menguji EffectiveShiftLeaderService/
 * Authority/POC otorisasi selesai. up() idempoten (pola sama seperti
 * 2026-09-11-000300_CreatePhase2PaymentTables.php) karena SQLite
 * tidak mendukung DROP COLUMN andal.
 *
 * `inisial` ditambahkan supaya SELECT u.inisial di
 * EffectiveShiftLeaderService (dipakai badge Shift Leader) tidak
 * gagal di test DB -- tanpa ini Authority::isCurrentShiftLeader()
 * fail-closed ke false untuk SEMUA test (exception tertelan, bukan
 * bug logic), bukan cuma test yang benar-benar menguji kolom ini.
 */
class AddPriorityAndJadwalTestSupport extends Migration
{
    protected $DBGroup = 'tests';

    public function up(): void
    {
        $kolom = [
            'nama'      => ['type' => 'varchar', 'constraint' => 100, 'null' => true],
            'inisial'   => ['type' => 'varchar', 'constraint' => 20, 'null' => true],
            'role'      => ['type' => 'varchar', 'constraint' => 16, 'default' => 'kasir'],
            'is_active' => ['type' => 'integer', 'default' => 1],
            'priority'  => ['type' => 'integer', 'null' => true],
        ];
        $tambah = [];
        foreach ($kolom as $namaKolom => $definisi) {
            if (! $this->db->fieldExists($namaKolom, 'users')) {
                $tambah[$namaKolom] = $definisi;
            }
        }
        if ($tambah !== []) {
            $this->forge->addColumn('users', $tambah);
        }

        // Forge tidak punya API "tambah unique index ke tabel existing"
        // di luar konteks createTable() -- pakai raw SQL langsung ke
        // SQLite (satu-satunya driver test grup 'tests'), idempoten
        // lewat pengecekan sqlite_master. Mem-verifikasi bahwa
        // konstraint UNIQUE (bukan hanya app-level check) benar-benar
        // menolak duplikat priority non-NULL, sekaligus mengizinkan
        // banyak NULL (perilaku default index SQLite/MySQL).
        //
        // Raw query TIDAK di-prefix otomatis oleh CI4 (beda dari
        // Forge/fieldExists yang sudah menangani DBPrefix sendiri) --
        // nama tabel & index harus disertakan manual di sini.
        $tabelUsers = $this->db->DBPrefix . 'users';
        $namaIndex  = $this->db->DBPrefix . 'ux_test_users_priority';
        $indexAda   = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND name = " . $this->db->escape($namaIndex)
        )->getRow();
        if (! $indexAda) {
            $this->db->query("CREATE UNIQUE INDEX {$namaIndex} ON {$tabelUsers}(priority)");
        }

        if (! $this->db->tableExists('jadwal')) {
            $this->forge->addField('id');
            $this->forge->addField([
                'karyawan_id' => ['type' => 'integer'],
                'tanggal'     => ['type' => 'date'],
                'shift'       => ['type' => 'varchar', 'constraint' => 4],
            ]);
            $this->forge->createTable('jadwal');
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('jadwal', true);
        // Kolom users dibiarkan (idempoten di up(), sama seperti pola 000300).
    }
}
