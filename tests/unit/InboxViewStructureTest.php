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
        $js = (string) file_get_contents(FCPATH . 'assets/js/inbox-page.js');
        $this->assertSame(1, substr_count($js, 'function renderDaftarConversation('));
    }

    public function testCssIsExternal(): void
    {
        $this->assertStringNotContainsString('<style', $this->view);
        $this->assertStringContainsString("base_url('assets/css/inbox.css')", $this->view);
        $this->assertFileExists(FCPATH . 'assets/css/inbox.css');
    }

    /** TODO-Q5c: page logic lives in public/assets/js/inbox-page.js; the view only renders config. */
    public function testPageScriptIsExternalAndPhpFree(): void
    {
        $this->assertStringContainsString("base_url('assets/js/inbox-page.js')", $this->view);
        $this->assertStringContainsString('window.INBOX_PAGE_CONFIG', $this->view);

        preg_match_all('#<script>(.*?)</script>#s', $this->view, $blok);
        foreach ($blok[1] as $isi) {
            $this->assertLessThan(40, substr_count($isi, "\n"), 'inline <script> blocks must stay config-only');
        }

        $js = (string) file_get_contents(FCPATH . 'assets/js/inbox-page.js');
        $this->assertStringNotContainsString('<?', $js);
        $this->assertStringNotContainsString('?>', $js);
    }

    /** Every inline handler in the view must be defined by one of the inbox scripts. */
    public function testInlineHandlersAreDefinedInScripts(): void
    {
        $sumber = '';
        foreach (['inbox-page.js', 'inbox-thread.js', 'inbox-template.js', 'inbox-emoji.js'] as $file) {
            $sumber .= (string) file_get_contents(FCPATH . 'assets/js/' . $file);
        }

        preg_match_all('/\son(?:click|submit|change|input)="(?:return\s+)?(?:event\.stopPropagation\(\);\s*)?([A-Za-z_]\w*)\(/', $this->view, $m);
        $handler = array_diff(array_unique($m[1]), ['document']);
        $this->assertNotEmpty($handler);

        foreach ($handler as $nama) {
            $this->assertMatchesRegularExpression('/function\s+' . $nama . '\s*\(/', $sumber, "handler {$nama}() is not defined");
        }
    }

    /** AC-EP: inbox-emoji.js must load before inbox-page.js, which calls tutupEmojiPanel(). */
    public function testEmojiScriptLoadsBeforePageScript(): void
    {
        $emoji = strpos($this->view, "base_url('assets/js/inbox-emoji.js')");
        $page  = strpos($this->view, "base_url('assets/js/inbox-page.js')");
        $this->assertNotFalse($emoji);
        $this->assertLessThan($page, $emoji);
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
            'btnEmojiBalasan', 'panelEmoji',
        ];

        foreach ($ids as $id) {
            $this->assertSame(1, substr_count($this->view, 'id="' . $id . '"'), "id {$id} must exist exactly once");
        }
    }
}
