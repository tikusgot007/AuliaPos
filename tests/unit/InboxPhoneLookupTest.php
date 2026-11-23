<?php

use App\Libraries\InboxPhoneLookup;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tombol WA di detail transaksi/tagihan: nomor kosong/tidak valid -> tidak ada
 * tombol; view memakai window 'AuliaInbox' dan fallback wa.me. Tidak
 * menyentuh DB (lookup percakapan sendiri diuji manual terhadap DB lokal).
 *
 * @internal
 */
final class InboxPhoneLookupTest extends CIUnitTestCase
{
    public function testNomorKosongAtauTidakValidTidakMenghasilkanTombol(): void
    {
        $this->assertNull(InboxPhoneLookup::untukNomor(null));
        $this->assertNull(InboxPhoneLookup::untukNomor(''));
        $this->assertNull(InboxPhoneLookup::untukNomor('abc'));
        $this->assertNull(InboxPhoneLookup::untukNomor('123'));
    }

    public function testViewDetailMemakaiLookupDanWindowInboxYangSama(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/transaksi/detail.php');

        $this->assertStringContainsString('InboxPhoneLookup::untukNomor', $view);
        $this->assertStringContainsString("'AuliaInbox'", $view);
        $this->assertStringContainsString('btnWaInbox', $view);
        $this->assertStringContainsString('btnWaBiasa', $view);
        $this->assertStringContainsString('noopener', $view);
    }
}
