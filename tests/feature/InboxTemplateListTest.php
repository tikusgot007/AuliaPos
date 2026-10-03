<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /inbox/api/balasan-template (AC-8, AC-12 of
 * docs/requirements/2026-10-03-template-balasan-cepat.md).
 *
 * Runs against `aulia_inboxdb_test` (see tests/_support/bootstrap-feature.php),
 * same as the other feature tests. Not yet run in this session -- no
 * test database is reachable here (see docs/TODO.md, TODO-U2).
 *
 * @internal
 */
final class InboxTemplateListTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private $inbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inbox = db_connect('inbox');
        $this->inbox->table('balasan_template')->emptyTable();
    }

    private function fetch(string $role = 'kasir')
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 7, 'role' => $role])
            ->get('/inbox/api/balasan-template');
    }

    public function testReturnsEmptyStateNotAnError(): void
    {
        $response = $this->fetch();
        $response->assertStatus(200);

        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame([], $json['templates']);
    }

    public function testReturnsTemplatesSortedByNamaWithBuiltGambarUrl(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('balasan_template')->insert([
            'nama' => 'Zebra', 'teks' => null, 'gambar_filename' => 'abc123deadbeef00112233445566ff00.png',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->inbox->table('balasan_template')->insert([
            'nama' => 'Ayam', 'teks' => 'halo', 'gambar_filename' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $response = $this->fetch();
        $json = json_decode($response->getJSON(), true);

        $this->assertSame(['Ayam', 'Zebra'], array_column($json['templates'], 'nama'));

        $zebra = $json['templates'][1];
        $this->assertStringContainsString('/balasan-template/foto/abc123deadbeef00112233445566ff00.png', $zebra['gambar_url']);

        $ayam = $json['templates'][0];
        $this->assertNull($ayam['gambar_url']);
        $this->assertSame('halo', $ayam['teks']);
    }

    public function testKasirCanReadTheListEvenThoughCrudIsAdminOnly(): void
    {
        $response = $this->fetch('kasir');

        $response->assertStatus(200);
    }
}
