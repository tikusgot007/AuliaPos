<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * TODO-BL21: the server must enforce the 12-week cap on "apply master" (the UI
 * `max="12"` alone is not a guard), and keep the endpoint admin-only.
 *
 * Validation runs before any database access, so the cap is provable without
 * schema. A request within the cap reaches the model; with no schema it ends in
 * the controller's error response (not a 2xx success) -- which is enough to show
 * the cap is the thing that rejects 13, not a general failure.
 *
 * @internal
 */
final class JadwalMasterApplyBatasTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const SENIN = '2026-11-02';

    private function adminPost(array $body)
    {
        return $this->withSession(['isLoggedIn' => true, 'id_user' => 1, 'role' => 'admin'])
            ->withBodyFormat('json')
            ->post('/jadwal/master/apply', $body);
    }

    public function testMoreThan12WeeksIsRejected(): void
    {
        $response = $this->adminPost([
            'master_id'     => 1,
            'start_minggu'  => self::SENIN,
            'jumlah_minggu' => 13,
        ]);

        $response->assertStatus(200);
        $json = json_decode($response->getJSON(), true);

        $this->assertSame('error', $json['status']);
        $this->assertStringContainsString('maksimal 12', $json['message']);
    }

    public function testAbsurdWeekCountIsRejected(): void
    {
        $response = $this->adminPost([
            'master_id'     => 1,
            'start_minggu'  => self::SENIN,
            'jumlah_minggu' => 100000,
        ]);

        $json = json_decode($response->getJSON(), true);

        $this->assertSame('error', $json['status']);
        $this->assertStringContainsString('maksimal 12', $json['message']);
    }

    public function testExactly12WeeksPassesTheCap(): void
    {
        $response = $this->adminPost([
            'master_id'     => 1,
            'start_minggu'  => self::SENIN,
            'jumlah_minggu' => 12,
        ]);

        $json = json_decode($response->getJSON(), true);

        // Not rejected by the cap (it may fail later for lack of schema/data).
        $this->assertStringNotContainsString('maksimal 12', (string) ($json['message'] ?? ''));
    }

    public function testKasirCannotApplyMaster(): void
    {
        $this->withSession(['isLoggedIn' => true, 'id_user' => 2, 'role' => 'kasir'])
            ->withBodyFormat('json')
            ->post('/jadwal/master/apply', [
                'master_id'     => 1,
                'start_minggu'  => self::SENIN,
                'jumlah_minggu' => 1,
            ])
            ->assertRedirectTo('/kasir');
    }
}
