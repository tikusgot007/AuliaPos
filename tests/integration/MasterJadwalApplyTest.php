<?php

use App\Models\JadwalModel;
use App\Models\MasterJadwalModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL21: applying a master schedule must
 *  - skip INACTIVE employees and report them separately from conflicts,
 *  - run in one DB transaction (a failure mid-way leaves NO partial rows),
 *  - keep the existing fill / skip-conflict / overwrite behaviour intact.
 *
 * Runs against the `tests` SQLite group (:memory:); no MySQL is touched.
 *
 * @internal
 */
final class MasterJadwalApplyTest extends CIUnitTestCase
{
    /** Monday. */
    private const SENIN = '2026-11-02';

    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();

        $forge->addField([
            'id'        => ['type' => 'INTEGER', 'auto_increment' => true],
            'nama'      => ['type' => 'TEXT'],
            'inisial'   => ['type' => 'TEXT', 'null' => true],
            'divisi'    => ['type' => 'TEXT', 'null' => true],
            'is_active' => ['type' => 'INTEGER', 'default' => 1],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('users', true);

        $forge->addField([
            'id'         => ['type' => 'INTEGER', 'auto_increment' => true],
            'nama'       => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('master_jadwal', true);

        $forge->addField([
            'id'               => ['type' => 'INTEGER', 'auto_increment' => true],
            'master_jadwal_id' => ['type' => 'INTEGER'],
            'karyawan_id'      => ['type' => 'INTEGER'],
            'hari'             => ['type' => 'INTEGER'],
            'shift'            => ['type' => 'TEXT'],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('master_jadwal_detail', true);

        $forge->addField([
            'id'          => ['type' => 'INTEGER', 'auto_increment' => true],
            'karyawan_id' => ['type' => 'INTEGER'],
            'tanggal'     => ['type' => 'DATE'],
            'shift'       => ['type' => 'TEXT'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey(['karyawan_id', 'tanggal']);
        $forge->createTable('jadwal', true);

        $db = db_connect();
        $db->table('users')->insert(['id' => 1, 'nama' => 'Aktif Satu', 'is_active' => 1]);
        $db->table('users')->insert(['id' => 2, 'nama' => 'Aktif Dua', 'is_active' => 1]);
        $db->table('users')->insert(['id' => 3, 'nama' => 'Non Aktif', 'is_active' => 0]);
        $db->table('master_jadwal')->insert(['id' => 1, 'nama' => 'Master Uji']);

        // Hari 1 (Senin) = P, hari 2 (Selasa) = S for each employee.
        foreach ([1, 2, 3] as $uid) {
            $db->table('master_jadwal_detail')->insert(['master_jadwal_id' => 1, 'karyawan_id' => $uid, 'hari' => 1, 'shift' => 'P']);
            $db->table('master_jadwal_detail')->insert(['master_jadwal_id' => 1, 'karyawan_id' => $uid, 'hari' => 2, 'shift' => 'S']);
        }
    }

    protected function tearDown(): void
    {
        // A forced rollback leaves the shared `tests` connection flagged as
        // failed; reset so later tests are unaffected.
        db_connect()->resetTransStatus();

        // Drop the JadwalModel double injected by some tests (Factories is global).
        \CodeIgniter\Config\Factories::reset('models');

        $forge = \Config\Database::forge();
        $forge->dropTable('jadwal', true);
        $forge->dropTable('master_jadwal_detail', true);
        $forge->dropTable('master_jadwal', true);
        $forge->dropTable('users', true);

        parent::tearDown();
    }

    public function testInactiveEmployeeIsSkippedAndReportedSeparately(): void
    {
        $hasil = (new MasterJadwalModel())->applyMaster(1, self::SENIN, 2);

        // 2 active employees x 2 days x 2 weeks = 8 rows; none for user 3.
        $this->assertSame(8, $hasil['diisi']);
        $this->assertSame(0, $hasil['dilewati']);
        $this->assertSame([], $hasil['conflict']);

        $this->assertSame(0, (int) db_connect()->table('jadwal')->where('karyawan_id', 3)->countAllResults());

        // 2 cells x 2 weeks, reported once by name (not duplicated per week).
        $this->assertSame(4, $hasil['dilewati_nonaktif']);
        $this->assertCount(1, $hasil['nonaktif']);
        $this->assertSame(3, $hasil['nonaktif'][0]['karyawan_id']);
        $this->assertSame('Non Aktif', $hasil['nonaktif'][0]['nama']);
    }

    public function testExistingConflictAndOverwriteBehaviourUnchanged(): void
    {
        $model = new MasterJadwalModel();
        model(JadwalModel::class)->simpanJadwal(1, self::SENIN, 'L'); // conflicts with master 'P'

        $tanpaOverwrite = $model->applyMaster(1, self::SENIN, 1, false);
        $this->assertSame(1, $tanpaOverwrite['dilewati']);
        $this->assertCount(1, $tanpaOverwrite['conflict']);
        $this->assertSame('L', $tanpaOverwrite['conflict'][0]['existing_shift']);
        $this->assertSame('L', model(JadwalModel::class)->getJadwalHariIni(1, self::SENIN)['shift']);

        $denganOverwrite = $model->applyMaster(1, self::SENIN, 1, true);
        $this->assertSame(1, $denganOverwrite['ditimpa']);
        $this->assertSame('P', model(JadwalModel::class)->getJadwalHariIni(1, self::SENIN)['shift']);
    }

    public function testAllInactiveMasterFillsNothing(): void
    {
        db_connect()->table('users')->update(['is_active' => 0]);

        $hasil = (new MasterJadwalModel())->applyMaster(1, self::SENIN, 1);

        $this->assertSame(0, $hasil['diisi']);
        $this->assertSame(6, $hasil['dilewati_nonaktif']);
        $this->assertCount(3, $hasil['nonaktif']);
        $this->assertSame(0, (int) db_connect()->table('jadwal')->countAllResults());
    }

    public function testFailureMidWayRollsBackEveryRow(): void
    {
        // Register a JadwalModel double that fails on the 3rd save, so earlier
        // rows must NOT remain in the table after the apply is aborted.
        $double = new class extends JadwalModel {
            public int $calls = 0;

            public function simpanJadwal(int $karyawanId, string $tanggal, string $shift): int
            {
                $this->calls++;

                if ($this->calls === 3) {
                    throw new \RuntimeException('simulated failure');
                }

                return parent::simpanJadwal($karyawanId, $tanggal, $shift);
            }
        };
        \CodeIgniter\Config\Factories::injectMock('models', JadwalModel::class, $double);

        try {
            (new MasterJadwalModel())->applyMaster(1, self::SENIN, 2);
            $this->fail('Expected the apply to abort with an exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated failure', $e->getMessage());
        }

        $this->assertSame(
            0,
            (int) db_connect()->table('jadwal')->countAllResults(),
            'a failed apply must leave no partial schedule rows'
        );
    }

    public function testSilentSaveFailureAbortsInsteadOfCountingFilled(): void
    {
        // simpanJadwal() returns 0 when an insert fails with DBDebug=false.
        $double = new class extends JadwalModel {
            public function simpanJadwal(int $karyawanId, string $tanggal, string $shift): int
            {
                return 0;
            }
        };
        \CodeIgniter\Config\Factories::injectMock('models', JadwalModel::class, $double);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gagal menyimpan jadwal');

        (new MasterJadwalModel())->applyMaster(1, self::SENIN, 1);
    }
}
