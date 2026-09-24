<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * M1 Wave 2 Phase 4 (TASK-019) -- reply form render evidence for AC-046.
 *
 * This project has no JS test runner (see tests/js/*.check.js for the
 * assert-based JS checks), so this test only proves the page ships the
 * pieces the keyboard/cashier flow depends on: the client-owned
 * operation_id slot on the reply form, the persistent "hasil belum pasti"
 * status element, the UUID generator with its legacy fallback, and the
 * Gateway rejection branches (SEND_IN_PROGRESS/SEND_UNRESOLVED and
 * OPERATION_ID_REUSED).
 *
 * @internal
 */
final class InboxOutgoingIdempotencyScreenTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use DatabaseTestTrait;

    protected $namespace = 'Tests\\Support';
    protected $migrate   = true;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversation_identities')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    private function halamanInbox(): string
    {
        $response = $this->withSession([
            'isLoggedIn'    => true,
            'role'          => 'kasir',
            'id_user'       => 7,
            'nama'          => 'Test Kasir',
            'last_activity' => time(),
        ])->get('inbox');

        $response->assertOK();

        return (string) $response->getBody();
    }

    public function testFormBalasMembawaSlotOperationIdMilikFrontend(): void
    {
        // AC-046/REQ-039: kunci hidup di state composer (data-operation-id),
        // bukan dibuat di server.
        $body = $this->halamanInbox();

        $this->assertMatchesRegularExpression(
            '/<form id="formBalas" data-operation-id=""/',
            $body
        );
        $this->assertStringContainsString("form.setAttribute('data-operation-id', kunci)", $body);
        $this->assertStringContainsString("form.setAttribute('data-operation-id', '')", $body);
    }

    public function testOperationIdDibuatDenganRandomUuidDanFallbackHex(): void
    {
        $body = $this->halamanInbox();

        $this->assertStringContainsString('window.crypto.randomUUID', $body);
        $this->assertStringContainsString('Math.floor(Math.random() * 16).toString(16)', $body);
    }

    public function testKirimBalasanMengirimOperationIdKeGateway(): void
    {
        $body = $this->halamanInbox();

        // Teks: masuk ke body urlencoded; media: masuk ke FormData.
        $this->assertStringContainsString("'&operation_id=' + encodeURIComponent(operationId)", $body);
        $this->assertStringContainsString("formData.append('operation_id', operationId)", $body);
    }

    public function testStatusHasilBelumPastiDanKunciBaruDirender(): void
    {
        // AC-046: elemen status persisten + kedua cabang penolakan Gateway.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('id="statusKirimBalasan"', $body);
        $this->assertStringContainsString('Hasil belum pasti, jangan kirim ulang dulu.', $body);
        $this->assertStringContainsString("json.error_code === 'SEND_IN_PROGRESS'", $body);
        $this->assertStringContainsString("json.error_code === 'SEND_UNRESOLVED'", $body);
        $this->assertStringContainsString("json.error_code === 'OPERATION_ID_REUSED'", $body);
    }
}
