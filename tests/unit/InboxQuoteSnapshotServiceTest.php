<?php

use App\Services\InboxQuoteSnapshotService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Balas Pesan (Tahap 3, TASK-001) -- unit test untuk pembentukan snapshot
 * kutipan: pemotongan cuplikan, label media, dan aturan ketersediaan media
 * tiga-nilai (spec REQ-007/REQ-008, AC-001/AC-005, ASSUMPTION-009).
 *
 * Kelas target murni (tidak menyentuh DB/session/request), jadi seluruh aturan
 * bisa diuji tanpa bootstrap database -- inilah alasan logikanya
 * diekstrak ke service, bukan dibiarkan di dalam controller besar.
 *
 * @internal
 */
final class InboxQuoteSnapshotServiceTest extends CIUnitTestCase
{
    private InboxQuoteSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InboxQuoteSnapshotService();
    }

    public function testTeksPendekDikembalikanUtuhTanpaElipsis(): void
    {
        $this->assertSame('Halo, apakah pesanan saya sudah dikirim?', $this->service->potongSnippet('Halo, apakah pesanan saya sudah dikirim?'));
    }

    public function testTeksKosongAtauNullMenghasilkanNull(): void
    {
        $this->assertNull($this->service->potongSnippet(null));
        $this->assertNull($this->service->potongSnippet(''));
        $this->assertNull($this->service->potongSnippet("   \n\t "));
    }

    public function testTeksPanjangDipotongPadaBatasKarakterDenganElipsis(): void
    {
        $teks = str_repeat('a', 500);
        $hasil = $this->service->potongSnippet($teks);

        $this->assertNotNull($hasil);
        $this->assertSame(InboxQuoteSnapshotService::MAKS_KARAKTER + 1, mb_strlen($hasil), '200 karakter + 1 elipsis.');
        $this->assertStringStartsWith(str_repeat('a', 200), $hasil);
        $this->assertStringEndsWith("\u{2026}", $hasil);
    }

    public function testPemotonganMultibyteAman(): void
    {
        // Emoji Survivor memakai 4 byte per karakter; pemotongan berbasis
        // byte (substr/strlen) akan memecah karakter jadi data rusak.
        $teks = str_repeat('😀', 300);
        $hasil = $this->service->potongSnippet($teks);

        $this->assertNotNull($hasil);
        $this->assertSame(201, mb_strlen($hasil));
        $this->assertSame(str_repeat('😀', 200) . "\u{2026}", $hasil);
        $this->assertTrue(mb_check_encoding($hasil, 'UTF-8'));
    }

    public function testSpasiBertumpukDanBarisBaruDipadatkan(): void
    {
        $this->assertSame('satu dua tiga', $this->service->potongSnippet("satu   dua\n\t\t tiga  "));
    }

    public function testTeksTepatPadaBatasTidakDipotong(): void
    {
        $teks = str_repeat('b', InboxQuoteSnapshotService::MAKS_KARAKTER);
        $this->assertSame($teks, $this->service->potongSnippet($teks), 'Tepat pada batas: utuh, tanpa elipsis.');
    }

    public function testSnippetPesanTeksMengutamakanTeks(): void
    {
        $hasil = $this->service->snippetDari([
            'message_type' => 'text',
            'text'         => 'Kapan pesanan saya dikirim?',
        ]);

        $this->assertSame('Kapan pesanan saya dikirim?', $hasil);
    }

    public function testSnippetPesanMediaTanpaCaptionMenggunakanLabelJenis(): void
    {
        $this->assertSame('[Foto]', $this->service->snippetDari(['message_type' => 'image', 'text' => null]));
        $this->assertSame('[Dokumen]', $this->service->snippetDari(['message_type' => 'document', 'text' => '']));
        $this->assertSame('[Stiker]', $this->service->snippetDari(['message_type' => 'sticker', 'text' => null]));
        $this->assertSame('[Audio]', $this->service->snippetDari(['message_type' => 'audio', 'text' => null]));
        $this->assertSame('[Video]', $this->service->snippetDari(['message_type' => 'video', 'text' => null]));
    }

    public function testCaptionMediaLebihDiutamakanDaripadaLabel(): void
    {
        $hasil = $this->service->snippetDari([
            'message_type' => 'image',
            'text'         => 'Ini fotonya ya',
        ]);

        $this->assertSame('Ini fotonya ya', $hasil, 'Caption lebih informatif daripada label jenis media.');
    }

    public function testPesanTanpaTipeDikenalMenghasilkanSnippetNull(): void
    {
        $this->assertNull($this->service->snippetDari(['message_type' => 'location', 'text' => null]));
    }

    public function testKetersediaanMediaNullUntukPesanTeks(): void
    {
        $this->assertNull(
            $this->service->ketersediaanMedia(['message_type' => 'text']),
            'REQ-008: sumber bukan media -> NULL.'
        );
    }

    public function testKetersediaanMediaSatuSaatBelumConfirmedGone(): void
    {
        $hasil = $this->service->ketersediaanMedia([
            'message_type'             => 'image',
            'media_local_filename'     => null,
            'media_confirmed_gone_at'  => null,
        ]);

        $this->assertSame(1, $hasil, 'REQ-008: media-typed & belum confirmed-gone -> 1 (heuristik).');
    }

    public function testKetersediaanMediaNolHanyaSaatTanpaFileLokalDanSudahGone(): void
    {
        $hasil = $this->service->ketersediaanMedia([
            'message_type'             => 'image',
            'media_local_filename'     => null,
            'media_confirmed_gone_at'  => '2026-09-20 10:00:00',
        ]);

        $this->assertSame(0, $hasil, 'REQ-008: dua kondisi terpenuhi -> 0 (pasti hilang).');
    }

    public function testFileLokalSelaluMenangDlamaConfirmedGone(): void
    {
        // Kasus HDD dicabut: live-fetch mengembalikan 410 dan mengisi
        // media_confirmed_gone_at sementara file lokal masih ada. Snapshot
        // harus tetap 1 -- file lokal adalah bukti paling kuat.
        $hasil = $this->service->ketersediaanMedia([
            'message_type'             => 'image',
            'media_local_filename'     => '20260920-abc123.png',
            'media_confirmed_gone_at'  => '2026-09-20 10:00:00',
        ]);

        $this->assertSame(1, $hasil, 'REQ-008: file lokal menang atas confirmed-gone.');
    }

    public function testRakitSnapshotMenggabungkanKelimaNilai(): void
    {
        $snapshot = $this->service->rakitSnapshot([
            'id'                      => 42,
            'wa_message_id'           => '3EB0XXXX',
            'message_type'            => 'text',
            'text'                    => 'Kapan pesanan saya dikirim?',
            'media_local_filename'    => null,
            'media_confirmed_gone_at' => null,
        ], '628123456789');

        $this->assertSame('3EB0XXXX', $snapshot['quoted_wa_message_id']);
        $this->assertSame('628123456789', $snapshot['quoted_sender_label']);
        $this->assertSame('Kapan pesanan saya dikirim?', $snapshot['quoted_snippet']);
        $this->assertNull($snapshot['quoted_media_available'], 'Teks -> NULL.');
        // v1.6 (REQ-008b): ID lokal sumber diambil apa adanya dari baris.
        $this->assertSame(42, $snapshot['quoted_source_message_id']);
    }

    public function testRakitSnapshotTanpaIdMenghasilkanSourceMessageIdNull(): void
    {
        // REQ-008b: baris sumber tanpa `id` (mis. diminta dari seam yang tidak
        // menyertakan kolom itu) tidak boleh menulis ID palsu.
        $snapshot = $this->service->rakitSnapshot([
            'wa_message_id' => '3EB0ZZZZ',
            'message_type'  => 'text',
            'text'          => 'halo',
        ], '628123456789');

        $this->assertNull($snapshot['quoted_source_message_id']);
    }

    public function testRakitSnapshotMenyimpanLabelNullApaAdanya(): void
    {
        // F-B: label null adalah penanda TUNGGAL "tidak ditemukan"; service
        // tidak boleh memaksa menjadi fallback. Yang memaksa fallback adalah
        // pemanggil (Inbox::labelPengirimKutipan), hanya saat sumber ditemukan.
        $snapshot = $this->service->rakitSnapshot([
            'wa_message_id' => '3EB0YYYY',
            'message_type'  => 'text',
            'text'          => 'pesan',
        ], null);

        $this->assertNull($snapshot['quoted_sender_label'], 'F-B: null label berarti status tidak ditemukan.');
    }

    // ------------------------------------------------------------------
    // v1.7 (REQ-008c): fidelitas tipe media kutipan
    // ------------------------------------------------------------------

    public function testTipeMediaSumberMengembalikanTipeMediaYangDikenal(): void
    {
        foreach (['image', 'document', 'sticker', 'audio', 'video'] as $tipe) {
            $this->assertSame(
                $tipe,
                $this->service->tipeMediaSumber(['message_type' => $tipe]),
                "REQ-008c: tipe media '{$tipe}' harus disimpan apa adanya."
            );
        }
    }

    public function testTipeMediaSumberNullUntukTeksDanTipeTakDikenal(): void
    {
        // REQ-008c: NULL untuk sumber teks, tipe tak dikenal, atau baris tanpa
        // `message_type` -- UI melewati live-fetch (AC-005 cabang e).
        $this->assertNull($this->service->tipeMediaSumber(['message_type' => 'text']));
        $this->assertNull($this->service->tipeMediaSumber(['message_type' => 'location']));
        $this->assertNull($this->service->tipeMediaSumber([]));
    }

    public function testRakitSnapshotMenyimpanTipeMediaUntukSumberMedia(): void
    {
        $snapshot = $this->service->rakitSnapshot([
            'id'                      => 77,
            'wa_message_id'           => '3EB0MEDIA',
            'message_type'            => 'image',
            'text'                    => null,
            'media_local_filename'    => '20260927-foto.png',
            'media_confirmed_gone_at' => null,
        ], '628123456789');

        $this->assertSame('image', $snapshot['quoted_media_type'], 'REQ-008c: tipe media sumber disimpan.');
        $this->assertSame(1, $snapshot['quoted_media_available']);
        $this->assertSame(77, $snapshot['quoted_source_message_id']);
    }

    public function testRakitSnapshotMenyimpanTipeMediaNullUntukSumberTeks(): void
    {
        $snapshot = $this->service->rakitSnapshot([
            'id'           => 78,
            'wa_message_id' => '3EB0TEKS',
            'message_type'  => 'text',
            'text'          => 'halo',
        ], '628123456789');

        $this->assertNull($snapshot['quoted_media_type'], 'REQ-008c: sumber teks -> NULL.');
    }
}
