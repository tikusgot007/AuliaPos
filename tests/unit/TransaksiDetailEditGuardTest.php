<?php

use App\Controllers\Transaksi;
use App\Models\DetailTransaksiModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-BL26: updateTransaksi() must not commit a partial edit. simpanDetailEdit()
 * has to fail loudly when the detail table cannot be rewritten, so the surrounding
 * DB transaction rolls back instead of silently losing rows.
 *
 * The guards are checked with model doubles (no database writes) that force
 * delete()/insert() to return false — the shape a query failure takes when
 * DBDebug is off in production. Before the fix the result was ignored, so no
 * exception was raised and the bug went unnoticed; these tests fail if the
 * guards are removed.
 *
 * @internal
 */
final class TransaksiDetailEditGuardTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Establish the (SQLite :memory:) test connection so the guard's
        // $model->db->error() call is safe; no query is executed by the doubles.
        db_connect()->connect();
    }

    public function testThrowsWhenDetailDeleteFails(): void
    {
        $double = new class extends DetailTransaksiModel {
            public function delete($id = null, bool $purge = false)
            {
                return false;
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gagal menghapus detail transaksi lama');

        $this->invoke($double, 1, [$this->row()]);
    }

    public function testThrowsWhenDetailInsertFails(): void
    {
        $double = new class extends DetailTransaksiModel {
            public function delete($id = null, bool $purge = false)
            {
                return true;
            }

            public function insert($row = null, bool $returnID = true)
            {
                return false;
            }
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gagal menyimpan detail transaksi');

        $this->invoke($double, 1, [$this->row()]);
    }

    public function testPassesThroughWhenDeleteAndInsertSucceed(): void
    {
        $double = new class extends DetailTransaksiModel {
            /** @var array<int, array<string, mixed>> */
            public array $inserted = [];

            public function delete($id = null, bool $purge = false)
            {
                return true;
            }

            public function insert($row = null, bool $returnID = true)
            {
                $this->inserted[] = $row;

                return 99;
            }
        };

        $this->invoke($double, 7, [$this->row()]);

        $this->assertCount(1, $double->inserted);
        $this->assertSame(7, $double->inserted[0]['transaksi_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'produk_id'    => 1,
            'nama_produk'  => 'Item',
            'kategori_id'  => 1,
            'jumlah'       => 1,
            'harga_satuan' => 1000,
            'subtotal'     => 1000,
            'catatan'      => '',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function invoke(DetailTransaksiModel $model, int $id, array $items): void
    {
        $method = new ReflectionMethod(Transaksi::class, 'simpanDetailEdit');
        $method->setAccessible(true);

        $controller = (new ReflectionClass(Transaksi::class))->newInstanceWithoutConstructor();

        $method->invoke($controller, $model, $id, $items);
    }
}
