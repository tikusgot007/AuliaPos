<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * M3 Phase 3 (Fase 1c) -- screen part of Internal Note, SLA Timer and
 * search (plan rev 1.1 TASK-015..017, spec AC-010..AC-012).
 *
 * This project has no JS test runner, so these tests only prove the
 * page renders the pieces the JS needs. Click, toast and polling
 * behavior is checked by hand in the browser (TASK-018 b).
 *
 * @internal
 */
final class OperationalInboxScreenTest extends CIUnitTestCase
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

    private function sesi(string $role, int $idUser): array
    {
        return [
            'isLoggedIn'    => true,
            'role'          => $role,
            'id_user'       => $idUser,
            'nama'          => 'Test ' . $role,
            'last_activity' => time(),
        ];
    }

    private function halamanInbox(): string
    {
        $response = $this->withSession($this->sesi('kasir', 7))->get('inbox');

        $response->assertOK();

        // assertSee() takes a CSS selector as its second argument, so the
        // body is read directly (same approach as InboxHandoffTest G05).
        return (string) $response->getBody();
    }

    public function testHalamanInboxMerenderDialogCatatanInternal(): void
    {
        // AC-010: the dialog exists and saves through the note endpoint,
        // not through kirimBalasan()/Gateway (CON-002).
        $body = $this->halamanInbox();

        $this->assertStringContainsString('id="modalCatatanInternal"', $body);
        $this->assertStringContainsString('id="catatanInternalTeks"', $body);
        $this->assertStringContainsString('id="btnSimpanCatatanInternal"', $body);
        // The header button, not the function definition.
        $this->assertStringContainsString('onclick="bukaModalCatatanInternal()"', $body);
    }

    public function testHalamanInboxMembacaSlaColorDariServer(): void
    {
        // AC-011: the dot comes from the server value c.sla_color only.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('renderTitikSla(c.sla_color)', $body);
        $this->assertStringContainsString('const SLA_WARNA = {', $body);
        $this->assertStringContainsString('.inbox-sla-dot {', $body);
        $this->assertStringContainsString('class="inbox-sla-dot ', $body);
    }

    public function testHalamanInboxMerenderKotakPencarianYangMengirimQ(): void
    {
        // AC-012: the search box exists, is limited to 255 characters
        // (CL-009), and the list request sends the keyword as `q`.
        $body = $this->halamanInbox();

        $this->assertMatchesRegularExpression('/<input[^>]*id="inputCariConversation"[^>]*maxlength="255"/', $body);
        $this->assertStringContainsString("'&q=' + encodeURIComponent(kataKunci)", $body);
    }
}
