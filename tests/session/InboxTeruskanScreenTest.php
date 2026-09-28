<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Teruskan (Tahap 4, TASK-003) -- screen part: membuktikan halaman Inbox
 * mengirimkan potongan UI yang dibutuhkan JS Teruskan
 * (REQ-004/REQ-005/REQ-008, AC-001/AC-002).
 *
 * Proyek ini tidak punya runner JS, jadi test ini HANYA membuktikan halaman
 * merender bagian yang dibutuhkan JS. Klik, toast, dan perpindahan thread
 * diperiksa manual di browser (checklist TASK-004) -- batasan dan kejujuran
 * cakupan yang sama dengan InboxBalasPesanScreenTest.
 *
 * @internal
 */
final class InboxTeruskanScreenTest extends CIUnitTestCase
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

        return (string) $response->getBody();
    }

    /** Isi satu elemen `<div class="modal fade" id="...">` utuh (termasuk pembungkusnya). */
    private function blokModal(string $body, string $id): string
    {
        $pola = '/<div class="modal fade" id="' . preg_quote($id, '/') . '".*?<!-- =+ -->/s';

        $this->assertSame(1, preg_match($pola, $body, $m), "Modal {$id} harus ada di halaman.");

        return $m[0];
    }

    /** Badan satu fungsi JS, dari `function nama(` sampai penutup `\n    }`. */
    private function badanFungsi(string $body, string $nama): string
    {
        $pola = '/function ' . preg_quote($nama, '/') . '\(.*?\n    \}/s';

        $this->assertSame(1, preg_match($pola, $body, $m), "Fungsi {$nama} harus ada di halaman.");

        return $m[0];
    }

    // ------------------------------------------------------------------
    // REQ-004/AC-002: tombol per bubble
    // ------------------------------------------------------------------

    public function testTombolTeruskanDirenderLewatFungsiKhusus(): void
    {
        $body = $this->halamanInbox();

        $this->assertStringContainsString('renderAksiTeruskan', $body);
        $this->assertStringContainsString('renderAksiPesan', $body, 'Balas dan Teruskan satu blok aksi.');
        $this->assertStringContainsString('bukaPemilihTeruskan(', $body);
        $this->assertMatchesRegularExpression(
            '/fa-share[^\n]*\bTeruskan\b/',
            $body,
            'Label tombol memakai kata "Teruskan" (CONTEXT.md).'
        );
    }

    public function testCatatanInternalDanOutgoingBelumTerkirimTidakPunyaTombolTeruskan(): void
    {
        // Aturan yang sama dengan tombol Balas: catatan internal bukan pesan
        // pelanggan, dan outgoing yang belum terkirim tidak pernah sampai.
        $body = $this->halamanInbox();
        $fungsi = $this->badanFungsi($body, 'bolehDiteruskan');

        $this->assertStringContainsString('m.is_internal', $fungsi);
        $this->assertStringContainsString("m.direction === 'outgoing' && m.send_status !== 'sent'", $fungsi);
        $this->assertStringContainsString('return !internal && !belumTerkirim;', $fungsi);
    }

    public function testAudioVideoTetapDirenderDisabledDenganLabelAlasan(): void
    {
        // AC-002/GH-016: opsi Teruskan TETAP TAMPIL tapi tidak bisa diklik,
        // beserta alasan yang bisa dibaca kasir -- bukan dihilangkan.
        //
        // CATATAN: response HTML meng-encode karakter non-ASCII (em dash pada
        // label jadi `&#8212;`), jadi asersi memakai bagian teks yang pasti
        // bertahan apa adanya; kelengkapan label dicek manual di browser
        // (checklist TASK-004). Pola yang sama dipakai InboxBalasPesanScreenTest.
        $body   = $this->halamanInbox();
        $fungsi = $this->badanFungsi($body, 'renderAksiTeruskan');

        $this->assertStringContainsString("m.message_type === 'audio' || m.message_type === 'video'", $fungsi);
        $this->assertStringContainsString('disabled', $fungsi, 'Tombol audio/video harus dalam keadaan disabled.');
        $this->assertStringContainsString('audio/video tidak dapat diteruskan', $fungsi, 'Label alasan wajib dirender (AC-002).');
        // Alasan yang sama juga jadi tooltip supaya tetap terbaca saat disabled.
        $this->assertStringContainsString('title="Teruskan ', $fungsi);
    }

    public function testBubbleMenyediakanTombolTeruskanBersamaBalas(): void
    {
        // Satu blok aksi menyatukan Balas dan Teruskan; wadahnya tidak
        // dirender kalau kedua tombol tidak tersedia.
        $body   = $this->halamanInbox();
        $fungsi = $this->badanFungsi($body, 'renderAksiPesan');

        $this->assertStringContainsString('renderAksiBalas(m) + renderAksiTeruskan(m)', $fungsi);
        $this->assertStringContainsString("if (tombol === '') return '';", $fungsi);
        // Tanpa bergantung pada '<': response HTML tidak mempertahankan
        // markup di dalam string JS apa adanya (lihat catatan admin screen test).
        $this->assertStringContainsString('bubble-aksi', $fungsi);
        $this->assertStringContainsString("' + tombol + '", $fungsi);
    }

    // ------------------------------------------------------------------
    // REQ-005: pemilih percakapan tujuan yang sudah ada
    // ------------------------------------------------------------------

    public function testModalPemilihTujuanAdaDenganPencarianKeEndpointPercakapan(): void
    {
        $body = $this->halamanInbox();
        $modal = $this->blokModal($body, 'modalTeruskan');

        $this->assertStringContainsString('id="daftarTujuanTeruskan"', $modal);
        $this->assertStringContainsString('id="cariTujuanTeruskan"', $modal);
        $this->assertStringContainsString('jalankanPencarianTujuanTeruskan()', $modal);
        $this->assertStringContainsString('id="btnKirimTeruskan"', $modal);

        // Pencarian memakai endpoint percakapan yang sudah ada.
        $fungsi = $this->badanFungsi($body, 'muatDaftarTujuanTeruskan');
        $this->assertStringContainsString("/inbox/api/conversations", $fungsi);
        $this->assertStringContainsString("'&q=' + encodeURIComponent(kataKunci)", $fungsi, 'Kata kunci dikirim ke endpoint, bukan difilter di layar.');
    }

    public function testPemilihTidakPernahMenawarkanBuatPercakapanBaru(): void
    {
        // REQ-005: hanya percakapan yang SUDAH ADA. Modal Teruskan tidak boleh
        // memuat jalan apa pun ke alur "Chat Baru".
        $body  = $this->halamanInbox();
        $modal = $this->blokModal($body, 'modalTeruskan');

        $this->assertStringNotContainsString('mulai-percakapan', $modal);
        $this->assertStringNotContainsString('Chat Baru', $modal);
        $this->assertStringNotContainsString('modalChatBaru', $modal);

        // Baris pilihan dibangun dari daftar percakapan hasil endpoint saja.
        $fungsi = $this->badanFungsi($body, 'muatDaftarTujuanTeruskan');
        $this->assertStringContainsString('json.conversations.map(', $fungsi);
        $this->assertStringContainsString('teruskan-tujuan-item', $fungsi);
    }

    public function testPercakapanTujuanYangSamaDitandaiJelas(): void
    {
        // Section 12: tujuan boleh sama dengan sumber, dan harus terbaca jelas.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('percakapan ini', $body);
        $this->assertStringContainsString("String(c.id) === String(conversationAktif)", $body);
    }

    public function testComposerDinonaktifkanSelamaPemilihTerbuka(): void
    {
        // TASK-003 (c): isi pesan sumber tidak boleh diedit saat diteruskan.
        $body   = $this->halamanInbox();
        $buka   = $this->badanFungsi($body, 'bukaPemilihTeruskan');
        $tutup  = $this->badanFungsi($body, 'tutupPemilihTeruskan');

        $this->assertStringContainsString("document.getElementById('teksBalasan').disabled = true", $buka);
        $this->assertStringContainsString("document.getElementById('btnLampirkanMedia').disabled = true", $buka);

        // Composer dipulihkan lewat event modal, bukan hanya tombol Batal.
        $this->assertStringContainsString("document.getElementById('teksBalasan').disabled = false", $tutup);
        $this->assertStringContainsString("addEventListener('hidden.bs.modal'", $body);
    }

    // ------------------------------------------------------------------
    // Request kirim: memakai endpoint kirim yang ada, tanpa text browser
    // ------------------------------------------------------------------

    public function testRequestTeruskanMemuatForwardFromMessageIdTanpaText(): void
    {
        // Section 4.3 + Section 9: browser hanya mengirim ID pesan sumber;
        // isi pesan diambil server dari DB, jadi `text` TIDAK ikut dikirim.
        $body   = $this->halamanInbox();
        $fungsi = $this->badanFungsi($body, 'teruskanPesan');

        $this->assertStringContainsString('inbox/kirim', $body);
        $this->assertStringContainsString("'&forward_from_message_id=' + encodeURIComponent(pesanTeruskanId)", $fungsi);
        $this->assertStringContainsString("'&operation_id=' + encodeURIComponent(operationId)", $fungsi);
        $this->assertStringNotContainsString("'&text='", $fungsi, 'Isi pesan TIDAK pernah dikirim browser (Section 9).');
        $this->assertStringNotContainsString('&quoted_message_id=', $fungsi, 'CON-001: Teruskan tidak pernah sekaligus mengutip.');
    }

    public function testTeruskanMemakaiKunciIdempotensiTerpisahDariBalasan(): void
    {
        // REQ-010: mekanisme operation_id yang sama, tapi kuncinya hidup pada
        // form sendiri supaya tidak terbaca sebagai replay balasan.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('ambilOperationIdTeruskan()', $body);
        $this->assertStringContainsString('buangOperationIdTeruskan()', $body);
        $this->assertStringContainsString("document.getElementById('formTeruskan')", $body);
    }

    public function testPindahKeThreadTujuanSetelahSukses(): void
    {
        $body   = $this->halamanInbox();
        $fungsi = $this->badanFungsi($body, 'teruskanPesan');

        $this->assertStringContainsString('pilihConversation(tujuan)', $fungsi);
        $this->assertStringContainsString('muatUlangDaftarConversation()', $fungsi);
    }

    // ------------------------------------------------------------------
    // REQ-008/AC-008: label "Diteruskan" berdiri sendiri
    // ------------------------------------------------------------------

    public function testLabelDiteruskanDibangunDariKolomIsForwarded(): void
    {
        $body   = $this->halamanInbox();
        $fungsi = $this->badanFungsi($body, 'renderLabelDiteruskan');

        $this->assertStringContainsString('m.is_forwarded', $fungsi, 'REQ-008: label dari kolom is_forwarded.');
        $this->assertStringContainsString('Diteruskan', $fungsi);
        $this->assertStringContainsString('inbox-forward-label', $body, 'Gaya label ada di halaman.');
    }

    public function testLabelDiteruskanBerdiriDiBarisnyaSendiri(): void
    {
        // Defect uji manual 2026-09-28: dengan `display: inline-block` label
        // mengalir sebaris dengan isi pesan sehingga terbaca
        // "DiteruskanHalo, ..." di bubble. Label WAJIB block-level supaya
        // selalu di barisnya sendiri, di atas isi pesan.
        $body = $this->halamanInbox();

        $this->assertMatchesRegularExpression(
            '/\.inbox-forward-label\s*\{[^}]*display:\s*block;/s',
            $body,
            'Label "Diteruskan" harus block-level, bukan inline-block (regresi known bug).'
        );
    }

    public function testLabelTidakPernahBergantungPadaForwardMarkerApplied(): void
    {
        // AC-008/REQ-008: metode penanda yang dipakai Gateway (native vs
        // fallback teks) TIDAK BOLEH dipakai UI kasir -- label AuliaPos
        // berdiri sendiri. Fungsi labelnya sama sekali tidak menyentuh field
        // itu, dan halaman tidak pernah MEMBACA-nya dari respons kirim.
        $body   = $this->halamanInbox();
        $fungsi = $this->badanFungsi($body, 'renderLabelDiteruskan');

        $this->assertStringNotContainsString('forward_marker_applied', $fungsi);
        $this->assertStringNotContainsString('m.forward_marker_applied', $body);
        $this->assertStringNotContainsString('json.forward_marker_applied', $body);
    }

    public function testLabelDirenderPadaBubbleHasilRenderMaupunKirimBaru(): void
    {
        $body = $this->halamanInbox();

        // Kedua jalur render bubble (polling & kirim langsung) memakai
        // komponen label yang sama.
        $this->assertSame(
            2,
            substr_count($body, 'renderLabelDiteruskan(m) +'),
            'Label harus dipakai di kedua jalur render bubble.'
        );
    }
}
