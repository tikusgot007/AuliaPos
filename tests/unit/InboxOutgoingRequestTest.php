<?php

use App\Libraries\InboxOutgoingRequest;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PRN-303: unit test untuk value object request kirim keluar.
 *
 * Fokus: factory mengangkut field dengan benar, dan konstruktor menolak
 * kombinasi `quoted` + `forward` (CON-001) di SATU tempat.
 *
 * @internal
 */
final class InboxOutgoingRequestTest extends CIUnitTestCase
{
    public function testTeksMembangunRequestTanpaMedia(): void
    {
        $request = InboxOutgoingRequest::teks('6281@s.whatsapp.net', 'halo');

        $this->assertSame('6281@s.whatsapp.net', $request->chatId);
        $this->assertSame('halo', $request->text);
        $this->assertNull($request->mediaType);
        $this->assertNull($request->operationId);
        $this->assertNull($request->quoted);
        $this->assertFalse($request->forward);
    }

    public function testTeksMembawaOperationIdKutipanDanForward(): void
    {
        $quoted  = ['wa_message_id' => 'x', 'fromMe' => false];
        $request = InboxOutgoingRequest::teks('6281@s.whatsapp.net', 'halo', 'op-1', $quoted, false);

        $this->assertSame('op-1', $request->operationId);
        $this->assertSame($quoted, $request->quoted);
        $this->assertFalse($request->forward);
    }

    public function testMediaMembawaAtributLengkap(): void
    {
        $request = InboxOutgoingRequest::media(
            '6281@s.whatsapp.net',
            'image',
            'BASE64',
            'image/jpeg',
            'foto.jpg',
            'caption',
            'op-2',
            null,
            true
        );

        $this->assertSame('image', $request->mediaType);
        $this->assertSame('BASE64', $request->mediaBase64);
        $this->assertSame('image/jpeg', $request->mimetype);
        $this->assertSame('foto.jpg', $request->fileName);
        $this->assertSame('caption', $request->caption);
        $this->assertSame('op-2', $request->operationId);
        $this->assertTrue($request->forward);
    }

    public function testTeksMenolakKutipanBersamaanForward(): void
    {
        // CON-001: satu request tidak boleh menjawab-dengan-kutipan SEKALIGUS
        // meneruskan -- ditegakkan value object, bukan ingatan pemanggil.
        $this->expectException(InvalidArgumentException::class);

        InboxOutgoingRequest::teks('6281@s.whatsapp.net', 'halo', null, ['wa_message_id' => 'x'], true);
    }

    public function testMediaMenolakKutipanBersamaanForward(): void
    {
        $this->expectException(InvalidArgumentException::class);

        InboxOutgoingRequest::media('6281@s.whatsapp.net', 'image', 'BASE64', null, null, '', null, ['wa_message_id' => 'x'], true);
    }
}
