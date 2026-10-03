<?php

namespace Tests\Unit;

use App\Libraries\InboxMediaDownload;
use PHPUnit\Framework\TestCase;

final class InboxMediaDownloadTest extends TestCase
{
    public function testFilenameFallsBackToMediaIdWithExtensionFromMime(): void
    {
        $this->assertSame('media-12.jpg', InboxMediaDownload::filename(null, 'image/jpeg', 12));
        $this->assertSame('media-12.webp', InboxMediaDownload::filename('', 'image/webp; codecs=x', 12));
        $this->assertSame('media-12', InboxMediaDownload::filename(null, 'application/x-unknown', 12));
    }

    public function testFilenameKeepsOriginalNameAndAddsMissingExtensionOnly(): void
    {
        $this->assertSame('nota.pdf', InboxMediaDownload::filename('nota.pdf', 'application/pdf', 1));
        $this->assertSame('nota.pdf', InboxMediaDownload::filename('nota', 'application/pdf', 1));
        $this->assertSame('laporan.docx', InboxMediaDownload::filename('laporan.docx', 'application/pdf', 1));
    }

    public function testFilenameStripsPathAndControlCharacters(): void
    {
        $this->assertSame('passwd', InboxMediaDownload::filename('../../etc/passwd', null, 1));
        $this->assertSame('a.pdf', InboxMediaDownload::filename("C:\\x\\a\r\n.pdf", null, 1));
        $this->assertSame('media-3', InboxMediaDownload::filename('..', null, 3));
        $this->assertStringNotContainsString('"', InboxMediaDownload::filename('a"b.txt', null, 1));
    }

    public function testDispositionInlineOnlyForNonSvgImagesWithoutForce(): void
    {
        $this->assertSame('inline', InboxMediaDownload::disposition('image/jpeg', 'image', false));
        $this->assertSame('inline', InboxMediaDownload::disposition('image/webp', 'sticker', false));
        $this->assertSame('attachment', InboxMediaDownload::disposition('image/jpeg', 'image', true));
        $this->assertSame('attachment', InboxMediaDownload::disposition('image/svg+xml', 'image', false));
        $this->assertSame('attachment', InboxMediaDownload::disposition('application/pdf', 'document', false));
        $this->assertSame('attachment', InboxMediaDownload::disposition('image/png', 'document', false));
        $this->assertSame('attachment', InboxMediaDownload::disposition(null, 'image', false));
    }

    public function testContentDispositionEncodesNonAsciiNames(): void
    {
        $header = InboxMediaDownload::contentDisposition('attachment', 'Nota Pesanan é.pdf');

        $this->assertSame(
            'attachment; filename="Nota Pesanan _.pdf"; filename*=UTF-8\'\'Nota%20Pesanan%20%C3%A9.pdf',
            $header
        );
        $this->assertStringNotContainsString("\n", $header);
    }
}
