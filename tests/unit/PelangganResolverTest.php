<?php

use App\Libraries\PelangganResolver;
use App\Models\PelangganModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Pelanggan resolver (AC-3, 5 sebagian, 8, 10): nomor = identitas, nama tidak
 * pernah menebak pelanggan lama, 4 digit dari server, atas_nama hanya bila
 * beda dari nama dasar. Jalan di SQLite testing, tidak menyentuh DB live.
 *
 * @internal
 */
final class PelangganResolverTest extends CIUnitTestCase
{
    private PelangganModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $forge = \Config\Database::forge();
        $forge->dropTable('pelanggan', true);
        $forge->addField([
            'id'     => ['type' => 'INTEGER', 'auto_increment' => true],
            'nama'   => ['type' => 'TEXT'],
            'no_hp'  => ['type' => 'TEXT', 'null' => true],
            'alamat' => ['type' => 'TEXT', 'null' => true],
            'diskon' => ['type' => 'REAL', 'default' => 0],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('pelanggan', true);
        $this->model = new PelangganModel();
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('pelanggan', true);
        parent::tearDown();
    }

    private function seed(string $nama, ?string $noHp): int
    {
        return (int) $this->model->insert(['nama' => $nama, 'no_hp' => $noHp, 'diskon' => 0]);
    }

    private function jumlah(): int
    {
        return db_connect()->table('pelanggan')->countAllResults();
    }

    public function testNomorTerdaftarNamaBedaPakaiPemilikDenganAtasNama(): void
    {
        $id = $this->seed('SUHERMAN 1234', '081234561234');

        $r = PelangganResolver::resolve($this->model, 'new', 'Alby', '0812-3456-1234');

        $this->assertNull($r['error']);
        $this->assertSame($id, $r['pelanggan_id']);
        $this->assertSame('Alby', $r['atas_nama']);
        $this->assertSame(1, $this->jumlah());
        $this->assertSame('SUHERMAN 1234', $this->model->find($id)['nama']);
    }

    public function testNomorBelumTerdaftarBuatPelangganBaruDigitDariServer(): void
    {
        $this->seed('SUHERMAN 1234', '081234561234');

        $r = PelangganResolver::resolve($this->model, 'new', 'alby 9999', '0813-9999-5555');

        $this->assertNull($r['error']);
        $this->assertSame(2, $this->jumlah());
        $this->assertSame('ALBY 5555', $this->model->find($r['pelanggan_id'])['nama']);
        $this->assertNull($r['atas_nama']);
    }

    public function testTanpaNomorNamaSamaTidakMenebakPelangganLama(): void
    {
        $lama = $this->seed('SOLEHA', null);

        $r = PelangganResolver::resolve($this->model, 'new', 'Soleha', '');

        $this->assertNotSame($lama, $r['pelanggan_id']);
        $this->assertSame(2, $this->jumlah());
        $this->assertSame('SOLEHA', $this->model->find($r['pelanggan_id'])['nama']);
    }

    public function testPelangganTerpilihTanpaNomorDiisiNomorBaru(): void
    {
        $id = $this->seed('SOLEHA', null);

        $r = PelangganResolver::resolve($this->model, (string) $id, 'Soleha', '081234567890');

        $row = $this->model->find($id);
        $this->assertSame($id, $r['pelanggan_id']);
        $this->assertSame('SOLEHA 7890', $row['nama']);
        $this->assertSame('081234567890', $row['no_hp']);
        $this->assertSame(1, $this->jumlah());
    }

    public function testPelangganTerpilihTanpaNomorNomorMilikOrangLainPakaiPemilik(): void
    {
        $kosong  = $this->seed('SOLEHA', null);
        $pemilik = $this->seed('SUHERMAN 1234', '081234561234');

        $r = PelangganResolver::resolve($this->model, (string) $kosong, 'Soleha', '081234561234');

        $this->assertSame($pemilik, $r['pelanggan_id']);
        $this->assertNull($this->model->find($kosong)['no_hp']);
    }

    public function testPelangganTerpilihBerNomorTidakDitimpaKetikan(): void
    {
        $id = $this->seed('SUHERMAN 1234', '081234561234');

        $r = PelangganResolver::resolve($this->model, (string) $id, 'Suherman', '089999999999', 'Alby');

        $row = $this->model->find($id);
        $this->assertSame($id, $r['pelanggan_id']);
        $this->assertSame('081234561234', $row['no_hp']);
        $this->assertSame('SUHERMAN 1234', $row['nama']);
        $this->assertSame('Alby', $r['atas_nama']);
    }

    public function testAtasNamaSamaDenganNamaDasarTidakDisimpan(): void
    {
        $id = $this->seed('SUHERMAN 1234', '081234561234');

        $r = PelangganResolver::resolve($this->model, (string) $id, '', '', 'suherman');

        $this->assertNull($r['atas_nama']);
    }

    public function testAtasNamaDipotong100Karakter(): void
    {
        $id = $this->seed('SUHERMAN 1234', '081234561234');

        $r = PelangganResolver::resolve($this->model, (string) $id, '', '', str_repeat('A', 300));

        $this->assertSame(100, mb_strlen($r['atas_nama']));
    }

    public function testNomorTidakValidDitolakTanpaMenulis(): void
    {
        $r = PelangganResolver::resolve($this->model, 'new', 'Alby', '12ab');

        $this->assertNotNull($r['error']);
        $this->assertSame(0, $this->jumlah());
    }

    public function testIdTidakAdaDitolak(): void
    {
        $r = PelangganResolver::resolve($this->model, '9999', 'X', '');

        $this->assertNotNull($r['error']);
    }

    public function testKosongBerartiWalkIn(): void
    {
        $r = PelangganResolver::resolve($this->model, null, '', '');

        $this->assertNull($r['error']);
        $this->assertNull($r['pelanggan_id']);
        $this->assertSame(0, $this->jumlah());
    }

    public function testNamaBaruTerlaluPendekDitolak(): void
    {
        $r = PelangganResolver::resolve($this->model, 'new', 'A', '');

        $this->assertNotNull($r['error']);
        $this->assertSame(0, $this->jumlah());
    }

    public function testFindTanpaNomorByNamaHanyaSatuKandidatTanpaNomor(): void
    {
        $this->seed('BUDI', '');
        $this->assertNotNull($this->model->findTanpaNomorByNama('BUDI'));

        $this->seed('BUDI', null);
        $this->assertNull($this->model->findTanpaNomorByNama('BUDI'), 'ambigu -> null');

        $this->seed('AGUS 1234', '081234561234');
        $this->assertNull($this->model->findTanpaNomorByNama('AGUS 1234'), 'sudah punya nomor');
        $this->assertNull($this->model->findTanpaNomorByNama(''));
    }
}
