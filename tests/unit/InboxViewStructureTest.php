<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * TODO-Q5: guards the inbox view refactor without booting the framework.
 *
 * - The conversation list has exactly one renderer (JS renderDaftarConversation());
 *   the PHP first-paint duplicate must not come back.
 * - Inbox CSS lives in public/assets/css/inbox.css, not inline.
 * - The UI restyle kept every element id that the inline script and
 *   inbox-thread.js look up with getElementById().
 *
 * @internal
 */
final class InboxViewStructureTest extends CIUnitTestCase
{
    private string $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = (string) file_get_contents(APPPATH . 'Views/inbox/index.php');
    }

    public function testConversationListIsNotRenderedByPhp(): void
    {
        $this->assertDoesNotMatchRegularExpression('/foreach\s*\(\s*\$conversations\b/', $this->view);
        $this->assertMatchesRegularExpression('/<div class="inbox-list-panel" id="inboxListPanel"><\/div>/', $this->view);
        $this->assertSame(1, substr_count($this->view, 'function renderDaftarConversation('));
    }

    public function testCssIsExternal(): void
    {
        $this->assertStringNotContainsString('<style', $this->view);
        $this->assertStringContainsString("base_url('assets/css/inbox.css')", $this->view);
        $this->assertFileExists(FCPATH . 'assets/css/inbox.css');
    }

    public function testElementIdsUsedByScriptsStillExist(): void
    {
        $ids = [
            'inboxListPanel', 'inputCariConversation', 'threadHeader', 'threadMessages',
            'formBalas', 'teksBalasan', 'btnKirimBalasan', 'btnLampirkanMedia',
            'btnTemplateBalasan', 'inputMediaBalasan', 'kutipanAktif', 'previewMediaBalasan',
            'statusKirimBalasan', 'nudgeAmbilBanner', 'btnModePilih', 'btnGulungBaru',
            'barPilihUnduh', 'perluDibalasBadge', 'gatewayStatusBadge', 'realtimeStatusBadge',
            'btnFilterBelum_diambil', 'btnFilterOpen', 'btnFilterMenunggu',
            'btnFilterDitunda', 'btnFilterSelesai', 'btnFilterGrup',
        ];

        foreach ($ids as $id) {
            $this->assertSame(1, substr_count($this->view, 'id="' . $id . '"'), "id {$id} must exist exactly once");
        }
    }
}
