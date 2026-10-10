<?php

namespace Tests\Unit;

use App\Controllers\Inbox as InboxController;
use App\Libraries\InboxOutgoingRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * TODO-Q3: kontrak bentuk payload JSON yang dikirim CI4 -> Gateway
 * (evolution-gateway, `C:\Projects\evolution-gateway\src\evolution\ci4Routes.js`).
 *
 * Diverifikasi di sini: key wajib SELALU ada, key opsional HANYA ada saat
 * diminta (operation_id/quoted/forward), dan nama+tipe key sesuai yang
 * diterima `ci4Routes.js` (lihat docs riset sesi TODO-Q3). Test ini
 * memanggil method `protected` builder (`buildSendPayload()` dkk, lihat
 * `Inbox.php`) langsung lewat Reflection -- method itu sendiri murni
 * (tidak menyentuh DB/HTTP/session), jadi tidak perlu bootstrap CodeIgniter.
 *
 * TIDAK menguji `/media/download` (respons binary, bukan JSON) dan TIDAK
 * menguji HTTP asli (timeout/cURL) -- itu tetap jadi gap yang didokumentasikan
 * terpisah (lihat sesi TODO-Q3, bukan scope test ini).
 */
final class InboxGatewayPayloadContractTest extends TestCase
{
    private function invoke(string $method, array $args)
    {
        $controller = new InboxController();
        $ref        = new ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($controller, $args);
    }

    // --- POST /send ------------------------------------------------------

    public function testSendPayloadMinimalHasOnlyChatIdAndText(): void
    {
        $request = InboxOutgoingRequest::teks('6281900000001@s.whatsapp.net', 'Halo');

        $payload = $this->invoke('buildSendPayload', [$request]);

        $this->assertSame(
            ['chat_id' => '6281900000001@s.whatsapp.net', 'text' => 'Halo'],
            $payload
        );
    }

    public function testSendPayloadIncludesOperationIdOnlyWhenProvided(): void
    {
        $request = InboxOutgoingRequest::teks('6281900000001@s.whatsapp.net', 'Halo', 'op-abc123');

        $payload = $this->invoke('buildSendPayload', [$request]);

        $this->assertArrayHasKey('operation_id', $payload);
        $this->assertSame('op-abc123', $payload['operation_id']);
    }

    public function testSendPayloadIncludesQuotedOnlyWhenProvided(): void
    {
        $quoted = [
            'wa_message_id' => 'AC123',
            'sender_jid'    => '6281900000002@s.whatsapp.net',
            'message_type'  => 'text',
            'fromMe'        => false,
            'text'          => 'pesan asal',
        ];
        $request = InboxOutgoingRequest::teks('6281900000001@s.whatsapp.net', 'Balasan', null, $quoted);

        $payload = $this->invoke('buildSendPayload', [$request]);

        $this->assertArrayHasKey('quoted', $payload);
        $this->assertSame($quoted, $payload['quoted']);
        $this->assertArrayNotHasKey('forward', $payload);
    }

    public function testSendPayloadIncludesForwardOnlyWhenTrue(): void
    {
        $request = InboxOutgoingRequest::teks('6281900000001@s.whatsapp.net', 'Teks diteruskan', null, null, true);

        $payload = $this->invoke('buildSendPayload', [$request]);

        $this->assertArrayHasKey('forward', $payload);
        $this->assertTrue($payload['forward']);
        $this->assertArrayNotHasKey('quoted', $payload);
    }

    public function testSendPayloadOmitsOptionalKeysWhenAbsent(): void
    {
        $request = InboxOutgoingRequest::teks('6281900000001@s.whatsapp.net', 'Halo');

        $payload = $this->invoke('buildSendPayload', [$request]);

        $this->assertArrayNotHasKey('operation_id', $payload);
        $this->assertArrayNotHasKey('quoted', $payload);
        $this->assertArrayNotHasKey('forward', $payload);
    }

    // --- POST /send-media --------------------------------------------------

    public function testSendMediaPayloadHasAllRequiredKeysIncludingEmptyCaption(): void
    {
        $request = InboxOutgoingRequest::media(
            '6281900000001@s.whatsapp.net',
            'image',
            'base64data==',
            'image/jpeg',
            null,
            ''
        );

        $payload = $this->invoke('buildSendMediaPayload', [$request]);

        $this->assertSame([
            'chat_id'      => '6281900000001@s.whatsapp.net',
            'media_type'   => 'image',
            'media_base64' => 'base64data==',
            'mimetype'     => 'image/jpeg',
            'file_name'    => null,
            'caption'      => '',
        ], $payload);
    }

    public function testSendMediaPayloadCaptionIsAlwaysStringNeverNull(): void
    {
        $request = InboxOutgoingRequest::media(
            '6281900000001@s.whatsapp.net',
            'document',
            'base64data==',
            'application/pdf',
            'nota.pdf',
            'Nota pembelian'
        );

        $payload = $this->invoke('buildSendMediaPayload', [$request]);

        $this->assertIsString($payload['caption']);
        $this->assertSame('Nota pembelian', $payload['caption']);
        $this->assertSame('nota.pdf', $payload['file_name']);
    }

    public function testSendMediaPayloadIncludesForwardOnlyWhenTrue(): void
    {
        $request = InboxOutgoingRequest::media(
            '6281900000001@s.whatsapp.net',
            'image',
            'base64data==',
            'image/jpeg',
            null,
            '',
            null,
            null,
            true
        );

        $payload = $this->invoke('buildSendMediaPayload', [$request]);

        $this->assertArrayHasKey('forward', $payload);
        $this->assertTrue($payload['forward']);
        $this->assertArrayNotHasKey('quoted', $payload);
    }

    // --- POST /delete --------------------------------------------------

    public function testDeletePayloadOmitsOperationIdWhenNull(): void
    {
        $payload = $this->invoke('buildDeletePayload', ['6281900000001@s.whatsapp.net', 'AC999', null]);

        $this->assertSame([
            'chat_id'       => '6281900000001@s.whatsapp.net',
            'wa_message_id' => 'AC999',
        ], $payload);
    }

    public function testDeletePayloadIncludesOperationIdWhenProvided(): void
    {
        $payload = $this->invoke('buildDeletePayload', ['6281900000001@s.whatsapp.net', 'AC999', 'op-del-1']);

        $this->assertSame('op-del-1', $payload['operation_id']);
    }

    // --- POST /edit --------------------------------------------------

    public function testEditPayloadAlwaysIncludesOperationIdKeyEvenWhenNull(): void
    {
        // Kontrak gateway (ci4Routes.js:592-599) MEWAJIBKAN operation_id untuk
        // /edit -- beda dari /send & /delete yang menjadikannya opsional.
        // Key harus tetap ada (walau null) supaya cacat pemanggil (lupa
        // mengisi operation_id) terdeteksi sebagai 400 dari Gateway, bukan
        // silently hilang dari payload.
        $payload = $this->invoke('buildEditPayload', ['6281900000001@s.whatsapp.net', 'AC999', 'Teks baru', null]);

        $this->assertArrayHasKey('operation_id', $payload);
        $this->assertNull($payload['operation_id']);
        $this->assertSame('Teks baru', $payload['new_text']);
    }

    public function testEditPayloadIncludesProvidedOperationId(): void
    {
        $payload = $this->invoke('buildEditPayload', ['6281900000001@s.whatsapp.net', 'AC999', 'Teks baru', 'op-edit-1']);

        $this->assertSame('op-edit-1', $payload['operation_id']);
    }

    // --- POST /read --------------------------------------------------

    public function testMarkReadPayloadReindexesMessageIdsAsList(): void
    {
        // array_values() dipakai di kode asli supaya array asosiatif (mis.
        // hasil array_unique() dengan key non-sekuensial) tetap ter-encode
        // sebagai JSON array, bukan object -- Gateway (ci4Routes.js) menolak
        // wa_message_ids berbentuk object.
        $sparse = [2 => 'AC1', 5 => 'AC2'];

        $payload = $this->invoke('buildMarkReadPayload', ['6281900000001@s.whatsapp.net', $sparse]);

        $this->assertSame(['AC1', 'AC2'], $payload['wa_message_ids']);
        $this->assertSame('6281900000001@s.whatsapp.net', $payload['chat_id']);
    }
}
