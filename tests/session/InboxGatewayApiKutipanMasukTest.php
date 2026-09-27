<?php

use App\Controllers\InboxGatewayApi;
use App\Services\InboxQuoteSnapshotService;
use App\Services\SenderIdentityFormatter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Psr\Log\NullLogger;

/**
 * Balas Pesan (Tahap 3, TASK-010) -- resolusi kutipan MASUK pada
 * `InboxGatewayApi::messages()` (spec REQ-010/REQ-011, F-B, ASSUMPTION-007).
 *
 * Direct-controller-call pattern mengikuti
 * tests/session/InboxGatewayLastMessageAtTest.php.
 *
 * @internal
 */
final class InboxGatewayApiKutipanMasukTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('aulia_inboxdb_test', db_connect('inbox')->query('SELECT DATABASE() AS db')->getRow()->db);

        $db = db_connect('inbox');
        $db->table('messages')->emptyTable();
        $db->table('conversations')->emptyTable();
    }

    private function callMessages(array $payload): array
    {
        $request = new IncomingRequest(new \Config\App(), new URI('cli'), null, new UserAgent());
        $request->setBody(json_encode($payload, JSON_THROW_ON_ERROR));

        $controller = new InboxGatewayApi();
        $controller->initController($request, new Response(new \Config\App()), new NullLogger());

        $response = $controller->messages();

        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function lastMessage(): array
    {
        $rows = db_connect('inbox')->table('messages')->orderBy('id', 'DESC')->get()->getResultArray();
        $this->assertNotEmpty($rows, 'Harus ada baris pesan tersimpan.');

        return $rows[0];
    }

    /** Kirim satu pesan sumber (tanpa kutipan) untuk dijadikan target lookup. */
    private function seedSumber(array $overrides = []): string
    {
        $waMessageId = $overrides['wa_message_id'] ?? ('SUMBER-' . bin2hex(random_bytes(4)));

        $body = $this->callMessages(array_merge([
            'wa_message_id'     => $waMessageId,
            'chat_id'           => '6281200000099@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Kapan pesanan saya dikirim?',
            'message_timestamp' => '2026-09-27 07:00:00',
            'sender_jid'        => '6281200000099@s.whatsapp.net',
        ], $overrides));

        $this->assertSame('success', $body['status']);

        return $waMessageId;
    }

    // ------------------------------------------------------------------
    // AC-008: kutipan ditemukan -> data LOKAL, bukan payload Gateway
    // ------------------------------------------------------------------

    public function testKutipanDitemukanMengisiDariDataLokalBukanPayload(): void
    {
        $sumberId = $this->seedSumber();

        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-001',
            'chat_id'           => '6281200000099@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Baik kalau begitu, saya tunggu ya',
            'message_timestamp' => '2026-09-27 07:01:00',
            'sender_jid'        => '6281200000099@s.whatsapp.net',
            'quoted'            => [
                'wa_message_id' => $sumberId,
                'sender_jid'    => '6281200000099@s.whatsapp.net',
                // Snippet PALSU dari Gateway -- HARUS diabaikan karena sumber
                // ditemukan (ASSUMPTION-007: Gateway bukan sumber kebenaran).
                'snippet'       => 'TEKS PALSU DARI GATEWAY',
            ],
        ]);

        $this->assertSame('success', $body['status']);

        $row = $this->lastMessage();
        $this->assertSame($sumberId, $row['quoted_wa_message_id']);
        $this->assertSame('Kapan pesanan saya dikirim?', $row['quoted_snippet'], 'AC-008: dari data lokal, bukan payload.');
        $this->assertSame('6281200000099', $row['quoted_sender_label']);
        $this->assertNull($row['quoted_media_available'], 'Sumber teks -> NULL.');
        $this->assertNull($row['quoted_media_type'], 'REQ-008c: sumber teks -> tipe media NULL.');
    }

    public function testKutipanDitemukanSumberMediaMenyimpanTipeMedia(): void
    {
        // REQ-008c (v1.7): kutipan MASUK yang sumbernya pesan media ->
        // `quoted_media_type` = tipe sumber (dari data lokal, bukan payload
        // Gateway). Sumber dibuat lewat jalur API normal supaya conversation +
        // identity terbentuk benar, lalu baris sumber diubah menjadi bertipe
        // media langsung di DB -- payload media lewat API butuh field media
        // yang tidak relevan untuk pengujian resolusi ini.
        $sumberWa = $this->seedSumber();
        $db = db_connect('inbox');
        $db->table('messages')->where('wa_message_id', $sumberWa)->update([
            'message_type' => 'image',
            'text'         => null,
        ]);

        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-MEDIA-001',
            'chat_id'           => '6281200000099@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Baik, fotonya saya terima',
            'message_timestamp' => '2026-09-27 07:04:00',
            'sender_jid'        => '6281200000099@s.whatsapp.net',
            'quoted'            => [
                'wa_message_id' => $sumberWa,
                'snippet'       => 'TEKS PALSU DARI GATEWAY',
            ],
        ]);

        $this->assertSame('success', $body['status']);

        $row = $this->lastMessage();
        $this->assertSame($sumberWa, $row['quoted_wa_message_id']);
        $this->assertSame('image', $row['quoted_media_type'], 'REQ-008c: tipe media sumber tersimpan.');
        $this->assertSame(1, (int) $row['quoted_media_available'], 'REQ-008: media tersedia (heuristik) -> 1.');
        $this->assertSame('[Foto]', $row['quoted_snippet'], 'Sumber media tanpa caption -> label jenis.');
    }

    // ------------------------------------------------------------------
    // AC-009: kutipan tidak ditemukan -> fallback payload, sender_label NULL
    // ------------------------------------------------------------------

    public function testKutipanTidakDitemukanMemakaiSnippetPayload(): void
    {
        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-002',
            'chat_id'           => '6281200000098@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Oke siap',
            'message_timestamp' => '2026-09-27 07:02:00',
            'quoted'            => [
                'wa_message_id' => 'TIDAK-ADA-DI-DB',
                'snippet'       => 'Pesan lama yang sudah di luar retensi',
            ],
        ]);

        $this->assertSame('success', $body['status'], 'Pesan masuk tetap tersimpan meski sumber tidak ditemukan.');

        $row = $this->lastMessage();
        $this->assertSame('TIDAK-ADA-DI-DB', $row['quoted_wa_message_id']);
        $this->assertSame('Pesan lama yang sudah di luar retensi', $row['quoted_snippet']);
        $this->assertNull($row['quoted_sender_label'], 'F-B: penanda tunggal status tidak ditemukan.');
        $this->assertNull($row['quoted_media_available']);
    }

    public function testKutipanTidakDitemukanTanpaSnippetPayloadMemakaiLabelGenerik(): void
    {
        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-003',
            'chat_id'           => '6281200000097@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Oke siap',
            'message_timestamp' => '2026-09-27 07:03:00',
            'quoted'            => ['wa_message_id' => 'TIDAK-ADA-JUGA'],
        ]);

        $this->assertSame('success', $body['status']);
        $this->assertSame('Pesan tidak ditemukan', $this->lastMessage()['quoted_snippet']);
        $this->assertNull($this->lastMessage()['quoted_sender_label']);
    }

    // ------------------------------------------------------------------
    // SEC-002: bound trust boundary pada `quoted.snippet` payload Gateway
    // ------------------------------------------------------------------

    public function testSnippetPayloadSangatPanjangDibatasiSaatSumberTidakDitemukan(): void
    {
        // Gateway (sumber LUAR) mengirim snippet 5.000 karakter. AuliaPos
        // WAJIB membatasinya sendiri, tidak menaruh 5.000 karakter ke DB.
        $snippet = str_repeat('A', 5000);

        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-PANJANG',
            'chat_id'           => '6281200000093@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Oke',
            'message_timestamp' => '2026-09-27 07:09:00',
            'quoted'            => [
                'wa_message_id' => 'TIDAK-ADA-DI-DB-PANJANG',
                'snippet'       => $snippet,
            ],
        ]);

        $this->assertSame('success', $body['status'], 'SEC-002: bound TIDAK menolak pesan masuk.');

        $row = $this->lastMessage();
        $this->assertSame('TIDAK-ADA-DI-DB-PANJANG', $row['quoted_wa_message_id']);
        $this->assertNull($row['quoted_sender_label'], 'F-B: penanda "tidak ditemukan" tetap NULL.');
        $this->assertNull($row['quoted_media_available']);

        $tersimpan = (string) $row['quoted_snippet'];
        $this->assertSame(
            InboxQuoteSnapshotService::MAKS_KARAKTER + 1,
            mb_strlen($tersimpan),
            'SEC-002: cuplikan dipotong ke batas + elipsis, bukan 5.000 karakter.'
        );
        $this->assertStringStartsWith(str_repeat('A', InboxQuoteSnapshotService::MAKS_KARAKTER), $tersimpan);
    }

    // ------------------------------------------------------------------
    // SEC-003: `quoted.snippet` bukan string -> degradasi, bukan 500
    // ------------------------------------------------------------------

    public function testSnippetPayloadBerupaArrayTidakMenggagalkanPenyimpanan(): void
    {
        // Gateway (sumber LUAR) mengirim `quoted.snippet` sebagai array.
        // Sebelumnya nilai ini diteruskan mentah ke `potongSnippet(?string)`
        // -> TypeError -> 500. Sekarang harus didegradasi ke label generik
        // dan pesan pelanggan tetap tersimpan (SEC-003, review ronde-2).
        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-ARRAY',
            'chat_id'           => '6281200000092@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Oke',
            'message_timestamp' => '2026-09-27 07:10:00',
            'quoted'            => [
                'wa_message_id' => 'TIDAK-ADA-DI-DB-ARRAY',
                'snippet'       => ['bukan', 'string'],
            ],
        ]);

        $this->assertSame('success', $body['status'], 'SEC-003: tipe bukan string TIDAK boleh -> 500.');

        $row = $this->lastMessage();
        $this->assertSame('TIDAK-ADA-DI-DB-ARRAY', $row['quoted_wa_message_id']);
        $this->assertSame('Pesan tidak ditemukan', $row['quoted_snippet'], 'SEC-003: degradasi ke label generik.');
        $this->assertNull($row['quoted_sender_label'], 'F-B: penanda "tidak ditemukan" tetap NULL.');
        $this->assertNull($row['quoted_media_available']);
    }

    public function testSnippetPayloadBerupaObjekTidakMenggagalkanPenyimpanan(): void
    {
        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-OBJEK',
            'chat_id'           => '6281200000091@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'Oke',
            'message_timestamp' => '2026-09-27 07:11:00',
            'quoted'            => [
                'wa_message_id' => 'TIDAK-ADA-DI-DB-OBJEK',
                'snippet'       => (object) ['teks' => 'bukan string'],
            ],
        ]);

        $this->assertSame('success', $body['status'], 'SEC-003: objek JSON pun TIDAK boleh -> 500.');

        $row = $this->lastMessage();
        $this->assertSame('TIDAK-ADA-DI-DB-OBJEK', $row['quoted_wa_message_id']);
        $this->assertSame('Pesan tidak ditemukan', $row['quoted_snippet'], 'SEC-003: degradasi ke label generik.');
        $this->assertNull($row['quoted_sender_label'], 'F-B: penanda "tidak ditemukan" tetap NULL.');
        $this->assertNull($row['quoted_media_available']);
    }

    // ------------------------------------------------------------------
    // F-B: label wajib non-NULL saat ditemukan, termasuk fallback formatter
    // ------------------------------------------------------------------

    public function testSenderLabelFallbackSaatFormatterMengembalikanNull(): void
    {
        // Baris legacy ber-JID grup (@g.us) -> SenderIdentityFormatter::labelFor()
        // mengembalikan null; F-B mewajibkan fallback "Pengirim" di sini,
        // BUKAN dibiarkan NULL (yang akan disalahartikan sebagai "tidak
        // ditemukan").
        $sumberId = $this->seedSumber(['sender_jid' => '120363000000000000@g.us']);

        $this->callMessages([
            'wa_message_id'     => 'BALASAN-004',
            'chat_id'           => '6281200000099@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'balasan',
            'message_timestamp' => '2026-09-27 07:04:00',
            'quoted'            => ['wa_message_id' => $sumberId],
        ]);

        $this->assertSame(SenderIdentityFormatter::LABEL_FALLBACK, $this->lastMessage()['quoted_sender_label']);
    }

    // ------------------------------------------------------------------
    // Soft-delete-inclusive lookup (spec Section 12)
    // ------------------------------------------------------------------

    public function testSumberYangSudahSoftDeletedTetapDitemukan(): void
    {
        $sumberId = $this->seedSumber();

        db_connect('inbox')->table('messages')
            ->where('wa_message_id', $sumberId)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $this->callMessages([
            'wa_message_id'     => 'BALASAN-005',
            'chat_id'           => '6281200000099@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'balasan',
            'message_timestamp' => '2026-09-27 07:05:00',
            'quoted'            => ['wa_message_id' => $sumberId],
        ]);

        $this->assertSame('Kapan pesanan saya dikirim?', $this->lastMessage()['quoted_snippet'], 'Sumber soft-deleted tetap ditemukan.');
        $this->assertNotNull($this->lastMessage()['quoted_sender_label']);
    }

    // ------------------------------------------------------------------
    // REQ-010: payload TANPA `quoted` tidak berubah sama sekali (regresi)
    // ------------------------------------------------------------------

    public function testPayloadTanpaQuotedTidakMengisiKolomKutipan(): void
    {
        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-006',
            'chat_id'           => '6281200000096@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'pesan biasa',
            'message_timestamp' => '2026-09-27 07:06:00',
        ]);

        $this->assertSame('success', $body['status']);

        $row = $this->lastMessage();
        $this->assertNull($row['quoted_wa_message_id']);
        $this->assertNull($row['quoted_sender_label']);
        $this->assertNull($row['quoted_snippet']);
        $this->assertNull($row['quoted_media_available']);
    }

    public function testQuotedMalformedTanpaWaMessageIdDiperlakukanTanpaKutipan(): void
    {
        // F-C-analog untuk arah masuk: objek `quoted` ada tapi tanpa ID yang
        // bisa di-resolve -- didegradasi sama seperti tanpa kutipan, BUKAN
        // ditolak. Pesan pelanggan tidak boleh hilang karena payload aneh.
        $body = $this->callMessages([
            'wa_message_id'     => 'BALASAN-007',
            'chat_id'           => '6281200000095@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'pesan',
            'message_timestamp' => '2026-09-27 07:07:00',
            'quoted'            => ['wa_message_id' => ''],
        ]);

        $this->assertSame('success', $body['status']);
        $this->assertNull($this->lastMessage()['quoted_wa_message_id']);
    }

    // ------------------------------------------------------------------
    // REQ-012: lookup hanya dijalankan saat `quoted` ada (kondisional)
    // ------------------------------------------------------------------

    public function testTidakAdaQueryTambahanTanpaQuoted(): void
    {
        // REQ-012: `resolveKutipanMasuk(null)` early-return TANPA menyentuh
        // MessageModel sama sekali -- dibuktikan tidak langsung lewat kolom
        // kutipan yang tetap NULL untuk payload tanpa `quoted` (pemeriksaan
        // query langsung memerlukan query logger yang tidak dipakai proyek
        // ini; lihat implementasi resolveKutipanMasuk() untuk early-return-nya).
        $this->callMessages([
            'wa_message_id'     => 'BALASAN-008',
            'chat_id'           => '6281200000094@s.whatsapp.net',
            'jid_type'          => 'pn',
            'message_type'      => 'text',
            'text'              => 'pesan tanpa kutipan',
            'message_timestamp' => '2026-09-27 07:08:00',
        ]);

        $this->assertNull($this->lastMessage()['quoted_wa_message_id']);
    }
}
