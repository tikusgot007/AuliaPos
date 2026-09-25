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

    public function testHalamanInboxMerenderBarisMatchSnippetYangDiEscape(): void
    {
        // AC-015a/c (Fase 1e): the list reads `c.match_snippet` and puts
        // the text through escapeHtmlInbox() only -- the snippet is
        // untrusted customer text, never raw HTML.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('renderSnippetCocok(c.match_snippet)', $body);
        $this->assertStringContainsString('class="list-snippet"', $body);
        $this->assertStringContainsString('escapeHtmlInbox(snippet.text)', $body);
        $this->assertStringContainsString('.inbox-list-item .list-snippet {', $body);
    }

    public function testHalamanInboxMemberiLabelInternalPadaSnippet(): void
    {
        // AC-015a: is_internal = true is preceded by an "Internal" label
        // that reuses the thread Internal Note label style (AC-010a).
        // is_internal may arrive as true/1/"1" through json_encode, the
        // same handling as renderPesan() uses for m.is_internal.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('snippet.is_internal === true || snippet.is_internal === 1', $body);
        $this->assertStringContainsString("'<span class=\"inbox-internal-label\">", $body);
    }

    public function testHalamanInboxTanpaSnippetTidakMenambahBaris(): void
    {
        // AC-015b: a null snippet (no `q`, or an identity-column hit per
        // CL-018) renders nothing extra, so the Fase 1d list markup
        // stays exactly as it was.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("if (!snippet || !snippet.text) return '';", $body);
    }

    public function testHalamanInboxMengunciPutaranDaftarSampaiSelesai(): void
    {
        // AC-015d / REQ-017: one list round at a time. The flag is set
        // when a round starts and released in .finally(), so it clears on
        // success and on failure alike.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('let putaranDaftarBerjalan = false;', $body);
        $this->assertStringContainsString('putaranDaftarBerjalan = true;', $body);
        $this->assertStringContainsString('.finally(function() {', $body);
        $this->assertStringContainsString('putaranDaftarBerjalan = false;', $body);
        // The 6-second polling tick skips a round while one is running,
        // and so does a search that repeats the active keyword, while a
        // NEW keyword is still sent (REQ-017c).
        $this->assertStringContainsString('if (putaranDaftarBerjalan) return;', $body);
        $this->assertStringContainsString('if (putaranDaftarBerjalan && baru === kataKunciSebelumnya) return;', $body);
    }
}
