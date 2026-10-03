<?php

use App\Models\BalasanTemplateModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * BalasanTemplateModel::$validationRules (AC-2, AC-3 of
 * docs/requirements/2026-10-03-template-balasan-cepat.md).
 *
 * Lives under tests/feature/ (not tests/unit/) because
 * Model::validate() needs the `validation` service, which requires the
 * full CodeIgniter bootstrap (tests/_support/bootstrap-feature.php) --
 * the plain `composer test` bootstrap (phpunit.xml) is deliberately
 * framework-free (see InboxMediaDownloadTest.php). This test itself
 * does NOT touch the database (no insert/find/is_unique), but
 * bootstrap-feature.php still performs a hard DB-reachability check at
 * bootstrap time for the whole feature suite -- see docs/TODO.md
 * (TODO-U2) for the same long-standing limitation on other feature
 * tests. Not yet run in this session -- no test database is reachable
 * here.
 *
 * @internal
 */
final class BalasanTemplateModelTest extends CIUnitTestCase
{
    public function testRejectsWhenBothTeksAndGambarAreEmpty(): void
    {
        $model = new BalasanTemplateModel();

        $ok = $model->validate(['nama' => 'QRIS', 'teks' => null, 'gambar_filename' => null]);

        $this->assertFalse($ok);
        $this->assertArrayHasKey('teks', $model->errors());
    }

    public function testAcceptsTeksOnly(): void
    {
        $model = new BalasanTemplateModel();

        $ok = $model->validate(['nama' => 'Jam Buka', 'teks' => 'Kami buka 08:00-20:00', 'gambar_filename' => null]);

        $this->assertTrue($ok, json_encode($model->errors()));
    }

    public function testAcceptsGambarOnly(): void
    {
        $model = new BalasanTemplateModel();

        $ok = $model->validate(['nama' => 'QRIS', 'teks' => null, 'gambar_filename' => 'abc123.png']);

        $this->assertTrue($ok, json_encode($model->errors()));
    }

    public function testRejectsWhenNamaMissing(): void
    {
        $model = new BalasanTemplateModel();

        $ok = $model->validate(['nama' => '', 'teks' => 'ada isi', 'gambar_filename' => null]);

        $this->assertFalse($ok);
        $this->assertArrayHasKey('nama', $model->errors());
    }
}
