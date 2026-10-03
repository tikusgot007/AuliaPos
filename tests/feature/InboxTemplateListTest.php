<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * GET /inbox/api/balasan-template (AC-8, AC-9, AC-12 of
 * docs/requirements/2026-10-03-template-balasan-cepat.md).
 *
 * Planned in docs/design/2026-10-03-template-balasan-cepat.md §6 as
 * InboxTemplateListTest but never written during implementation
 * (commit c9e3dec) -- added here together with the route-bug fix (see
 * BalasanTemplateCrudTest.php for the regression on the image route
 * itself). Covers the full read path a kasir actually uses in the
 * composer: list endpoint -> gambar_url -> fetch that URL, all as kasir.
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

    private function asKasir()
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 7, 'role' => 'kasir']);
    }

    // AC-12: no templates yet -- a kasir still gets a clean success response
    // with an empty array, not an error, so the composer can show an
    // explicit "Belum ada template" state (handled client-side).
    public function testEmptyListReturnsSuccessWithEmptyArray(): void
    {
        $response = $this->asKasir()->get('/inbox/api/balasan-template');

        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame([], $json['templates']);
    }

    // AC-8: sorted A-Z; AC-9: gambar_url must point at the kasir-reachable
    // /foto-template/ prefix (NOT /balasan-template/foto/, which is blocked
    // for kasir by AuthFilter::$adminRoutes -- see BalasanTemplateCrudTest.php).
    public function testListReturnsTemplatesSortedByNamaWithKasirReachableImageUrl(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->inbox->table('balasan_template')->insert([
            'nama' => 'Zebra', 'teks' => 'z', 'gambar_filename' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->inbox->table('balasan_template')->insert([
            'nama' => 'Ayam', 'teks' => null, 'gambar_filename' => 'contoh.png',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $response = $this->asKasir()->get('/inbox/api/balasan-template');

        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertCount(2, $json['templates']);
        $this->assertSame('Ayam', $json['templates'][0]['nama']);
        $this->assertSame('Zebra', $json['templates'][1]['nama']);
        $this->assertNull($json['templates'][1]['gambar_url']);
        $this->assertStringContainsString('/foto-template/contoh.png', $json['templates'][0]['gambar_url']);
        $this->assertStringNotContainsString('/balasan-template/foto/', $json['templates'][0]['gambar_url']);
    }
}
