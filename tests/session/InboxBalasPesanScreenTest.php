<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Balas Pesan (Tahap 3, TASK-003) -- screen part: prove the Inbox page ships
 * the quote UI the JS needs (spec REQ-004/REQ-005/REQ-006/REQ-008/REQ-013).
 *
 * This project has no JS test runner, so these tests only prove the page
 * renders the pieces the JS needs. Click, toast, and polling behaviour is
 * checked by hand in the browser (TASK-004 checklist) -- same limitation and
 * same honest scope as OperationalInboxScreenTest.
 *
 * @internal
 */
final class InboxBalasPesanScreenTest extends CIUnitTestCase
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

    public function testAreaKutipanAktifAdaDiAtasKotakKetik(): void
    {
        // REQ-005: selecting "Balas" shows the active-quote area above the
        // composer; it starts hidden so ordinary replies look unchanged.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('id="kutipanAktif"', $body);
        $this->assertStringContainsString('id="kutipanAktifJudul"', $body);
        $this->assertStringContainsString('id="kutipanAktifSnippet"', $body);
        $this->assertStringContainsString('batalkanKutipan()', $body, 'REQ-005: tombol batal tersedia.');
    }

    public function testTombolBalasPerPesanDirender(): void
    {
        // REQ-004: a per-message "Balas" action.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('renderAksiBalas', $body);
        $this->assertStringContainsString('pilihKutipan(', $body);
        // Label tombol dibangun di dalam string JS renderAksiBalas(). View
        // memproses markup di dalam string itu sebagai HTML (elemen penutupnya
        // ikut dirender), jadi assertion sengaja tidak bergantung pada '<' --
        // cukup membuktikan kata "Balas" muncul di baris ikon reply yang sama.
        $this->assertMatchesRegularExpression(
            '/fa-reply[^\n]*\bBalas\b/',
            $body,
            'Label tombol harus memakai kata "Balas" (CONTEXT.md, _Avoid_ Reply/Quote).'
        );
    }

    public function testKotakKutipanTerpasangPadaBubble(): void
    {
        // REQ-005/REQ-013: one shared component renders the quote box on
        // both outgoing and incoming bubbles.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('renderKotakKutipan', $body);
        $this->assertStringContainsString('class="inbox-kutipan"', $body);
    }

    public function testPlaceholderMediaTidakTersediaDirender(): void
    {
        // REQ-008/AC-005: snapshot 0 shows "[Media tidak tersedia]"
        // instead of a broken image, without rewriting the database.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('[Media tidak tersedia]', $body);
    }

    public function testLabelPesanTidakDitemukanHanyaDariSenderLabel(): void
    {
        // F-B: the "not found" state is decided ONLY by a null
        // `quoted_sender_label`; the UI must not sniff the snippet text
        // against generic labels.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('tidakDitemukan', $body);
        $this->assertStringContainsString('Pesan tidak ditemukan', $body);
    }

    public function testPenandaTerkirimTanpaKutipanAda(): void
    {
        // REQ-006 (reaksi a): the cashier must see that the quote did not
        // reach the recipient. The marker is deliberately ephemeral
        // (ASSUMPTION-008) -- kept in a JS Set, never a database column.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('Terkirim tanpa kutipan', $body);
        $this->assertStringContainsString('quote_applied', $body);
        $this->assertStringContainsString('pesanTerkirimTanpaKutipan', $body);
    }

    public function testKirimBalasanMengirimQuotedMessageId(): void
    {
        // ALT-002/section 4.3: the browser sends only the local message id;
        // the server rebuilds the snapshot from its own database.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("'&quoted_message_id='", $body);
        $this->assertStringContainsString('kutipanAktif ? kutipanAktif.id : null', $body);
    }

    public function testGayaKotakKutipanDidefinisikan(): void
    {
        $body = $this->halamanInbox();

        $this->assertStringContainsString('.inbox-kutipan {', $body, 'Styling kotak kutipan harus ada di halaman.');
        $this->assertStringContainsString('.kutipan-aktif {', $body);
        $this->assertStringContainsString('.penanda-tanpa-kutipan {', $body);
    }

    // ------------------------------------------------------------------
    // Phase 2 (TASK-007): balas-dengan-lampiran sambil mengutip
    // ------------------------------------------------------------------

    public function testKirimMediaBalasanMenyertakanQuotedMessageId(): void
    {
        // REQ-001a/AC-003b: jalur media harus ikut mengirim quoted_message_id
        // -- hanya ID lokal; isi kutipan tetap diambil server (ALT-002).
        $body = $this->halamanInbox();

        $this->assertStringContainsString("formData.append('quoted_message_id', quotedMessageId)", $body);
        $this->assertStringContainsString('kutipanAktif ? kutipanAktif.id : null', $body);
    }

    public function testKirimMediaBalasanMenanganiQuoteAppliedFalse(): void
    {
        // Reaksi (a) REQ-006 harus sama persis di jalur media: media tetap
        // terkirim, penanda "Terkirim tanpa kutipan" muncul.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('Media terkirim, TAPI tanpa kutipan', $body);
        $this->assertStringContainsString('pesanTerkirimTanpaKutipan.add(json.message.id)', $body);
    }

    public function testKotakKutipanJugaTampilPadaBubbleMedia(): void
    {
        // Komponen yang sama dipakai untuk media (TASK-007), bukan komponen
        // terpisah.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('renderKotakKutipan(m) +', $body);
        $this->assertStringContainsString('renderKotakKutipan', $body);
    }

    // ------------------------------------------------------------------
    // Phase 2 balas pesan (TASK-201b/202): fallback tampilan media AC-005 v1.6
    // ------------------------------------------------------------------

    public function testCabangAMediaAvailableNolTampilLangsungTanpaLiveFetch(): void
    {
        // AC-005 (a): snapshot `quoted_media_available = 0` -> placeholder
        // ditampilkan langsung; cabang ini TIDAK memanggil live-fetch.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("angkaMedia === '0'", $body);
        $this->assertStringContainsString('mediaTidakTersedia', $body);
        $this->assertStringContainsString('[Media tidak tersedia]', $body);
    }

    public function testCabangBLiveFetchMediaKutipanMemakaiIdLokalSumber(): void
    {
        // AC-005 (b): snapshot 1 + `quoted_source_message_id` terisi ->
        // live-fetch endpoint media existing lewat ID lokal, bukan wa_message_id.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("angkaMedia === '1'", $body);
        $this->assertStringContainsString('m.quoted_source_message_id', $body);
        // URL dibangun dari ID lokal sumber lewat endpoint media existing.
        $this->assertStringContainsString("urlMediaKutipan = '", $body);
        $this->assertStringContainsString("/inbox/media/' + sumberId", $body);
        $this->assertStringContainsString('inbox-kutipan-media', $body);
    }

    public function testCabangBLiveFetchGagalMenampilkanMediaTidakTersedia(): void
    {
        // AC-005 (b) kegagalan: 404/410/error pada <img> wajib jatuh ke
        // placeholder yang SAMA, tanpa menulis ulang DB.
        $body = $this->halamanInbox();

        $this->assertMatchesRegularExpression(
            '/onerror="[^"]*inbox-kutipan-tak-ada[^"]*\[Media tidak tersedia\]/',
            $body,
            'onerror <img> harus mengganti ke "[Media tidak tersedia]" (AC-005 b).'
        );
    }

    public function testCabangCSourceMessageIdNullMelewatiLiveFetch(): void
    {
        // AC-005 (c): `quoted_source_message_id` NULL (legacy / sumber tidak
        // ditemukan) -> guard menolak live-fetch; pakai snapshot apa adanya.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("sumberId !== null && sumberId !== undefined && sumberId !== ''", $body);
        $this->assertStringContainsString("m.quoted_snippet || 'Pesan tidak ditemukan'", $body);
    }

    // ------------------------------------------------------------------
    // Phase 2 review-2 (TASK-202/203): fidelitas tipe media AC-005 v1.7
    // ------------------------------------------------------------------

    public function testCabangBImageDanStickerMemakaiImgLiveFetch(): void
    {
        // AC-005 (b) v1.7: hanya image/sticker yang di-live-fetch jadi <img>;
        // dispatch memakai `quoted_media_type`, bukan menebak dari snippet.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("tipeMedia === 'image' || tipeMedia === 'sticker'", $body);
        $this->assertStringContainsString('inbox-kutipan-sticker', $body, 'Sticker punya kelas thumbnail sendiri.');
    }

    public function testCabangCDocumentMenampilkanTautanBukanImg(): void
    {
        // AC-005 (c) v1.7: dokumen = tautan, tidak ada <img>, tanpa fetch.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("tipeMedia === 'document'", $body);
        $this->assertStringContainsString('inbox-kutipan-dokumen', $body);
        $this->assertStringContainsString("'[Dokumen]'", $body, 'Label default dokumen saat caption kosong.');
    }

    public function testCabangDAudioVideoMenampilkanLabelTanpaFetch(): void
    {
        // AC-005 (d) v1.7: audio/video cukup label jenis, tanpa fetch/player.
        $body = $this->halamanInbox();

        $this->assertStringContainsString("tipeMedia === 'audio' || tipeMedia === 'video'", $body);
        $this->assertStringContainsString("tipeMedia === 'audio' ? '[Audio]' : '[Video]'", $body);
    }

    public function testCabangETipeMediaNullMelewatiLiveFetch(): void
    {
        // AC-005 (e) v1.7: `quoted_media_type` NULL (legacy / sumber teks)
        // -> TANPA live-fetch; hanya cabang fallback cuplikan yang dirender.
        // Asersi menempel ke EKSPRESI GUARD UTUH (bukan token lepas), supaya
        // syarat `tipeMedia !== null` benar-benar terbukti bagian dari kondisi
        // fetch, plus syarat isi fallback-nya -- keduanya bisa gagal kalau
        // tipe-null keliru jatuh ke <img>.
        $body = $this->halamanInbox();

        $this->assertStringContainsString('m.quoted_media_type', $body);
        $this->assertStringContainsString(
            "adaSumberMedia = (angkaMedia === '1') && adaSumberId && (tipeMedia !== null)",
            $body,
            'Guard fetch wajib mensyaratkan tipe media non-NULL (AC-005 cabang e).'
        );
        $this->assertStringContainsString(
            "m.quoted_snippet || 'Pesan tidak ditemukan'",
            $body,
            'Cabang (e) merender cuplikan apa adanya, bukan percobaan fetch.'
        );
    }

    public function testKegagalanKutipanMemakaiKunciMemoriTerpisahDariMediaPesan(): void
    {
        // PERF-001: kegagalan live-fetch kutipan diingat supaya tidak di-fetch
        // ulang tiap polling; kuncinya WAJIB dipisah dari `m.id` mentah milik
        // renderIsiPesan() supaya media pesan itu sendiri tidak ikut ditandai
        // gagal (satu pesan bisa punya media sendiri SEKALIGUS kutipan).
        $body = $this->halamanInbox();

        $this->assertStringContainsString("kunciKutipanGagal = 'kutipan:' + m.id", $body);
        $this->assertStringContainsString('mediaGagal.add', $body);
        $this->assertStringContainsString('mediaGagal.has(kunciKutipanGagal)', $body);
    }

    public function testMediaGagalMedianPesanSendiriMemakaiKunciString(): void
    {
        // Bug lama (Tahap E tidak efektif): `numberNative=false` membuat `m.id`
        // berupa STRING, tetapi `onerror` menulis `mediaGagal.add(900010)`
        // (angka) sementara pemeriksaannya `mediaGagal.has("900010")` (string)
        // -- memori gagal tidak pernah cocok sehingga polling 4 detik terus
        // meminta ulang media yang sudah gagal (melanggar PERF-001). Kuncinya
        // WAJIB string, selaras dengan `mediaGagal.has(m.id)`.
        $body = $this->halamanInbox();

        $this->assertStringNotContainsString(
            "mediaGagal.add(' + m.id + ')",
            $body,
            'Kunci media pesan sendiri tidak boleh berupa angka mentah.'
        );
        $this->assertStringContainsString('mediaGagal.has(m.id)', $body, 'Pemeriksaannya tetap ada.');
    }
}
