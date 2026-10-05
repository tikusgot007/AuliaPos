<?php

use App\Controllers\Transaksi;
use App\Models\TransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regression coverage for TODO-BL08.
 *
 * The controller's edit path must enforce the same Studio/Foto ↔ no_order
 * contract as the create path, and an edit must reject an active no_order
 * owned by another transaction while allowing its own number and numbers
 * that only exist on cancelled history.
 *
 * Runs on the testing SQLite connection and does not touch live databases.
 *
 * @internal
 */
final class TransaksiNoOrderEditTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    public function testStudioRequiresNoOrderOnEdit(): void
    {
        $this->assertSame(
            'Transaksi mengandung produk Studio/Foto. No Order WAJIB diisi!',
            $this->validate(null, true)
        );
        $this->assertNull($this->validate(123, true));
    }

    public function testNonStudioRejectsNoOrderOnEdit(): void
    {
        $this->assertSame(
            'Transaksi TIDAK mengandung produk Studio/Foto. No Order harus dikosongkan!',
            $this->validate(123, false)
        );
        $this->assertNull($this->validate(null, false));
    }

    public function testActiveNoOrderBelongingToAnotherTransactionIsRejected(): void
    {
        $this->insertTransaction(10, 123, 'proses');

        $model = new TransaksiModel();

        $this->assertTrue($this->noOrderConflict($model, 20, 123));
        $this->assertFalse($this->noOrderConflict($model, 10, 123));
    }

    public function testCancelledHistoryDoesNotBlockNoOrderReuse(): void
    {
        $this->insertTransaction(10, 123, 'batal');

        $model = new TransaksiModel();

        $this->assertFalse($this->noOrderConflict($model, 20, 123));
    }

    private function validate(?int $noOrder, bool $hasKategori16): ?string
    {
        $method = new ReflectionMethod(Transaksi::class, 'validasiNoOrderEdit');
        $method->setAccessible(true);

        /** @var string|null $result */
        $result = $method->invoke(new Transaksi(), $noOrder, $hasKategori16);

        return $result;
    }

    private function noOrderConflict(
        TransaksiModel $model,
        int $id,
        int $noOrder
    ): bool {
        $method = new ReflectionMethod(Transaksi::class, 'noOrderDipakaiTransaksiAktifLain');
        $method->setAccessible(true);

        return (bool) $method->invoke(new Transaksi(), $model, $id, $noOrder);
    }

    private function insertTransaction(int $id, int $noOrder, string $status): void
    {
        db_connect()->table('transaksi')->insert([
            'id'       => $id,
            'no_order' => $noOrder,
            'status'   => $status,
        ]);
    }

    private function createSchema(): void
    {
        $forge = \Config\Database::forge();

        $forge->addField([
            'id'       => ['type' => 'INTEGER', 'constraint' => 11],
            'no_order' => ['type' => 'INTEGER', 'null' => true],
            'status'   => ['type' => 'VARCHAR', 'constraint' => 20],
        ]);
        $forge->addKey('id', true);
        $forge->createTable('transaksi', true);
    }

    private function dropSchema(): void
    {
        \Config\Database::forge()->dropTable('transaksi', true);
    }
}
