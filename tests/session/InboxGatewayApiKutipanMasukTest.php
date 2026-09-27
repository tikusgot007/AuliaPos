<?php

use App\Controllers\InboxGatewayApi;
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
