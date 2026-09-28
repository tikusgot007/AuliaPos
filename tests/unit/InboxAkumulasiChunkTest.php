<?php

use App\Controllers\Inbox;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * TEST-701/TEST-703: unit murni untuk `Inbox::akumulasiChunk()` -- logika
 * batas byte yang dipakai `CURLOPT_WRITEFUNCTION` saat mentransfer media.
 * Karena fungsinya murni, batas bisa diuji langsung tanpa cURL maupun
 * loopback: tepat pada batas lolos, lebih 1 byte overflow, dan satu chunk
 * besar overflow.
 *
 * @internal
 */
final class InboxAkumulasiChunkTest extends CIUnitTestCase
{
    /** @return array{body: string, overflow: bool} */
    private function panggil(string $body, string $chunk, int $maxBytes): array
    {
        $method = (new \ReflectionClass(Inbox::class))->getMethod('akumulasiChunk');
        $method->setAccessible(true);

        return $method->invoke(null, $body, $chunk, $maxBytes);
    }

    public function testTepatPadaBatasLolos(): void
    {
        $hasil = $this->panggil('', str_repeat('a', 1024), 1024);

        $this->assertFalse($hasil['overflow']);
        $this->assertSame(1024, strlen($hasil['body']));
    }

    public function testLebihSatuByteOverflowTanpaMenambahBody(): void
    {
        $hasil = $this->panggil('', str_repeat('a', 1025), 1024);

        $this->assertTrue($hasil['overflow'], 'Batas + 1 byte harus overflow.');
        $this->assertSame('', $hasil['body'], 'Chunk yang overflow TIDAK ditambahkan.');
    }

    public function testAkumulasiBeberapaChunkSampaiBatas(): void
    {
        $hasil = $this->panggil('aaaaa', 'bbbbb', 10);
        $this->assertFalse($hasil['overflow']);
        $this->assertSame('aaaaabbbbb', $hasil['body']);

        // Satu byte lagi melewati batas -> overflow, body tidak berubah.
        $hasil2 = $this->panggil($hasil['body'], 'c', 10);
        $this->assertTrue($hasil2['overflow']);
        $this->assertSame('aaaaabbbbb', $hasil2['body']);
    }

    public function testChunkBesarTunggalOverflow(): void
    {
        $hasil = $this->panggil('abcdef', str_repeat('z', 4096), 1024);

        $this->assertTrue($hasil['overflow']);
        $this->assertSame('abcdef', $hasil['body'], 'Chunk besar tunggal tidak boleh ter-buffer.');
    }

    public function testChunkKosongTidakMengubahApaPun(): void
    {
        $hasil = $this->panggil('abc', '', 10);

        $this->assertFalse($hasil['overflow']);
        $this->assertSame('abc', $hasil['body']);
    }
}
