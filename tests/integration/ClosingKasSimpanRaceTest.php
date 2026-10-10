<?php

use App\Models\ClosingKasModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL27: simpanClosing() must resolve the unique-index race on
 * `closing_kas.tanggal` precisely (insert → fall back to update) instead of a
 * blind retry at the controller, and it must never swallow a real failure.
 *
 * The real insert/update paths run against the `tests` SQLite group (:memory:).
 * The race and failure branches are driven with model doubles so the insert
 * failure can be forced deterministically (as it happens when DBDebug is off).
 *
 * @internal
 */
final class ClosingKasSimpanRaceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $forge = \Config\Database::forge();
        $forge->addField([
            'id'           => ['type' => 'INTEGER', 'constraint' => 11, 'auto_increment' => true],
            'tanggal'      => ['type' => 'DATETIME'],
            'saldo_sistem' => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'saldo_fisik'  => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'selisih'      => ['type' => 'DECIMAL', 'constraint' => '15,2', 'default' => 0],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_by'   => ['type' => 'INTEGER', 'default' => 0],
        ]);
        $forge->addKey('id', true);
        $forge->addUniqueKey('tanggal');
        $forge->createTable('closing_kas', true);
    }

    protected function tearDown(): void
    {
        \Config\Database::forge()->dropTable('closing_kas', true);

        parent::tearDown();
    }

    public function testInsertsNewClosing(): void
    {
        $model = new ClosingKasModel();

        $id = $model->simpanClosing('2026-03-15', 100000.0, 98000.0, 1);

        $this->assertNotFalse($id);

        $row = $model->getByTanggal('2026-03-15');
        $this->assertNotNull($row);
        $this->assertSame(100000.0, (float) $row['saldo_sistem']);
        $this->assertSame(98000.0, (float) $row['saldo_fisik']);
        $this->assertSame(-2000.0, (float) $row['selisih']);
    }

    public function testUpdatesExistingRowForSameDate(): void
    {
        $model = new ClosingKasModel();

        $id1 = $model->simpanClosing('2026-03-16', 100000.0, 100000.0, 1);
        $id2 = $model->simpanClosing('2026-03-16', 100000.0, 111000.0, 2);

        $this->assertSame((int) $id1, (int) $id2);

        $row = $model->getByTanggal('2026-03-16');
        $this->assertSame(111000.0, (float) $row['saldo_fisik']);
        $this->assertSame(11000.0, (float) $row['selisih']);
        $this->assertSame(2, (int) $row['updated_by']);
    }

    public function testRaceLosingInsertFallsBackToUpdate(): void
    {
        // First getByTanggal() (pre-insert check) reports nothing; the insert
        // then fails as if another request inserted the same date first; the
        // follow-up check finds the row and the same values are written as an update.
        $model = new class extends ClosingKasModel {
            public int $getCalls = 0;
            /** @var array<string, mixed>|null */
            public ?array $updatedWith = null;

            public function getByTanggal(string $tanggal)
            {
                $this->getCalls++;

                return $this->getCalls === 1 ? null : ['id' => 42];
            }

            public function insert($row = null, bool $returnID = true)
            {
                throw new DatabaseException('Duplicate entry for key uq_closing_kas_tanggal');
            }

            public function update($id = null, $row = null): bool
            {
                $this->updatedWith = ['id' => $id, 'row' => $row];

                return true;
            }
        };

        $id = $model->simpanClosing('2026-03-15', 100000.0, 105000.0, 7);

        $this->assertSame(42, $id);
        $this->assertNotNull($model->updatedWith);
        $this->assertSame(42, $model->updatedWith['id']);
        $this->assertSame(5000.0, (float) $model->updatedWith['row']['selisih']);
    }

    public function testRealFailureIsRethrown(): void
    {
        $model = new class extends ClosingKasModel {
            public function getByTanggal(string $tanggal)
            {
                return null; // no row anywhere -> not a race
            }

            public function insert($row = null, bool $returnID = true)
            {
                throw new DatabaseException('Connection lost');
            }
        };

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Connection lost');

        $model->simpanClosing('2026-03-15', 1.0, 1.0, 1);
    }

    public function testSilentInsertFailureReturnsFalseWhenNotARace(): void
    {
        $model = new class extends ClosingKasModel {
            public function getByTanggal(string $tanggal)
            {
                return null;
            }

            public function insert($row = null, bool $returnID = true)
            {
                return false; // DBDebug off: failed insert without exception
            }
        };

        $this->assertFalse($model->simpanClosing('2026-03-15', 1.0, 1.0, 1));
    }
}
