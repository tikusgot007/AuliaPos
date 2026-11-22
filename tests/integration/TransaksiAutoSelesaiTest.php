<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Auto-SELESAI 7 hari: hanya transaksi PROSES + LUNAS + umur >= N hari +
 * belum digabung. Jalan di SQLite :memory: (grup `tests`), tidak menyentuh DB .env.
 *
 * @internal
 */
final class TransaksiAutoSelesaiTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $forge = \Config\Database::forge();
        $forge->addField([
            'id'                => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'merged_into'       => ['type' => 'INTEGER', 'null' => true],
            'tanggal'           => ['type' => 'DATETIME', 'null' => true],
            'grand_total'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'total_dibayar'     => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status_pembayaran' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'belum_bayar'],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'proses'],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);

        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'transaksi_id' => ['type' => 'INTEGER', 'constraint' => 11],
            'tanggal'      => ['type' => 'DATETIME', 'null' => true],
            'jumlah'       => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'status'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'aktif'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pembayaran', true);

        $lama = date('Y-m-d H:i:s', strtotime('-8 days'));
        $baru = date('Y-m-d H:i:s', strtotime('-3 days'));

        // id => [tanggal, status, status_pembayaran, merged_into, bayar]
        $rows = [
            1 => [$lama, 'proses',   'lunas',       null, 100000], // harus berubah
            2 => [$baru, 'proses',   'lunas',       null, 100000], // terlalu baru
            3 => [$lama, 'proses',   'belum_bayar', null, 0],      // belum lunas
            4 => [$lama, 'proses',   'dp',          null, 50000],  // dp
            5 => [$lama, 'mangkrak', 'lunas',       null, 100000], // mangkrak
            6 => [$lama, 'batal',    'lunas',       null, 100000], // batal
            7 => [$lama, 'proses',   'lunas',       1,    100000], // sudah digabung
            8 => [$lama, 'proses',   'lunas',       null, 0],      // cache lunas basi, tanpa pembayaran
        ];
        $db = db_connect();
        foreach ($rows as $id => [$tgl, $st, $sp, $merged, $bayar]) {
            $db->table('transaksi')->insert([
                'id' => $id, 'merged_into' => $merged, 'tanggal' => $tgl, 'grand_total' => 100000,
                'total_dibayar' => $bayar, 'status_pembayaran' => $sp, 'status' => $st,
                'created_at' => $tgl, 'updated_at' => $tgl,
            ]);
            if ($bayar > 0) {
                $db->table('pembayaran')->insert([
                    'transaksi_id' => $id, 'tanggal' => $tgl, 'jumlah' => $bayar, 'status' => 'aktif',
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        $forge = \Config\Database::forge();
        $forge->dropTable('pembayaran', true);
        $forge->dropTable('transaksi', true);
        parent::tearDown();
    }

    private function statusTrx(int $id): string
    {
        return (string) db_connect()->table('transaksi')->where('id', $id)->get()->getRow()->status;
    }

    public function testDryRunTidakMengubahData(): void
    {
        $hasil = (new \App\Models\TransaksiModel())->autoSelesaikanLunasLama(7, true);

        $this->assertSame(2, $hasil['kandidat']); // id 1 dan 8 (cache lunas)
        $this->assertSame(0, $hasil['diubah']);
        $this->assertSame('proses', $this->statusTrx(1));
    }

    public function testHanyaLunasProsesLamaYangBerubah(): void
    {
        $hasil = (new \App\Models\TransaksiModel())->autoSelesaikanLunasLama(7);

        $this->assertSame('selesai', $this->statusTrx(1));
        foreach ([2, 3, 4, 7] as $id) {
            $this->assertSame('proses', $this->statusTrx($id), "id {$id} tidak boleh berubah");
        }
        $this->assertSame('mangkrak', $this->statusTrx(5));
        $this->assertSame('batal', $this->statusTrx(6));
        // id 8: cache 'lunas' basi, tabel pembayaran kosong -> ditolak ubahStatus()
        $this->assertSame('proses', $this->statusTrx(8));
        $this->assertSame(1, $hasil['diubah']);
        $this->assertArrayHasKey(8, $hasil['dilewati']);
    }

    public function testIdempoten(): void
    {
        $model = new \App\Models\TransaksiModel();
        $model->autoSelesaikanLunasLama(7);
        $kedua = $model->autoSelesaikanLunasLama(7);

        $this->assertSame(0, $kedua['diubah']);
    }

    public function testHariTidakValidDitolak(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new \App\Models\TransaksiModel())->autoSelesaikanLunasLama(0);
    }
}
