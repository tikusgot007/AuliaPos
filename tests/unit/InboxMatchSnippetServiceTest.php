<?php

use App\Services\InboxMatchSnippetService;
use PHPUnit\Framework\TestCase;

/**
 * InboxMatchSnippetService is a pure cutter (no DB, session, or request
 * access), so plain PHPUnit is enough and no CodeIgniter bootstrap state is
 * needed.
 *
 * Contract under test (Spec M3 4.4 / CL-019, plan Fase 1e TASK-023, AC-014g):
 * - every run of whitespace, new lines included, collapses into one space,
 *   then the text is trimmed;
 * - `null`, empty, or whitespace-only text returns `null`;
 * - text of at most 120 characters (`mb_strlen`) comes back as is, with no
 *   ellipsis;
 * - longer text yields a 120-character window that starts 40 characters
 *   before the first case-insensitive occurrence of the keyword (clamped at
 *   0), with the ellipsis added only on the side(s) really cut;
 * - when the keyword is not found in PHP (e.g. the database matched "e" for
 *   an accented letter), the window starts at the beginning of the text;
 * - every length/cut uses `mb_*`, so multi-byte letters and emoji are never
 *   split.
 *
 * @internal
 */
final class InboxMatchSnippetServiceTest extends TestCase
{
    private const ELIPSIS = "\u{2026}";

    private InboxMatchSnippetService $service;

    protected function setUp(): void
    {
        $this->service = new InboxMatchSnippetService();
    }

    public function testTeksNullMenghasilkanNull(): void
    {
        $this->assertNull($this->service->potong(null, 'saerah'));
    }

    public function testTeksKosongMenghasilkanNull(): void
    {
        $this->assertNull($this->service->potong('', 'saerah'));
    }

    public function testTeksHanyaSpasiDanBarisBaruMenghasilkanNull(): void
    {
        $this->assertNull($this->service->potong(" \n\t\r\n  ", 'saerah'));
    }

    public function testSpasiBerurutanDanBarisBaruDiringkasJadiSatuSpasi(): void
    {
        $teks = "Halo\r\n\r\nkak   ini    pesan\n\natas nama Saerah";

        $this->assertSame('Halo kak ini pesan atas nama Saerah', $this->service->potong($teks, 'saerah'));
    }

    public function testTeksTepat120KarakterDikirimUtuhTanpaElipsis(): void
    {
        $teks = str_repeat('a', 60) . 'Saerah' . str_repeat('b', 54);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertSame(120, mb_strlen($teks));
        $this->assertSame($teks, $hasil);
        $this->assertStringNotContainsString(self::ELIPSIS, $hasil);
    }

    public function testTeks121KarakterDipotongDanDiberiElipsisDiAkhir(): void
    {
        $teks = 'Saerah' . str_repeat('b', 115);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertSame(121, mb_strlen($teks));
        $this->assertSame(121, mb_strlen($hasil));
        $this->assertStringStartsWith('Saerah', $hasil);
        $this->assertStringEndsWith(self::ELIPSIS, $hasil);
    }

    /**
     * CL-019: the 120-character window starts 40 characters before the match.
     */
    public function testJendelaDimulai40KarakterSebelumKecocokan(): void
    {
        $teks = str_repeat('x', 60) . 'Saerah' . str_repeat('y', 200);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertStringStartsWith(self::ELIPSIS, $hasil);
        $this->assertStringEndsWith(self::ELIPSIS, $hasil);
        // Past the leading ellipsis: the 40 characters right before the match,
        // then the keyword itself.
        $this->assertSame(str_repeat('x', 40) . 'Saerah', mb_substr($hasil, 1, 46));
        $this->assertSame(122, mb_strlen($hasil));
    }

    public function testKecocokanTidakMembedakanHurufBesarKecilMenentukanJendela(): void
    {
        $teks = str_repeat('x', 60) . 'SAERAH' . str_repeat('y', 200);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertStringContainsString('SAERAH', $hasil);
        $this->assertSame(str_repeat('x', 40), mb_substr($hasil, 1, 40));
    }

    public function testKecocokanDiAwalTeksHanyaDiberiElipsisDiAkhir(): void
    {
        $teks = 'Saerah' . str_repeat('z', 300);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertStringStartsWith('Saerah', $hasil);
        $this->assertStringEndsWith(self::ELIPSIS, $hasil);
        $this->assertSame(121, mb_strlen($hasil));
    }

    public function testKecocokanDiAkhirTeksHanyaDiberiElipsisDiAwal(): void
    {
        $teks = str_repeat('z', 300) . 'Saerah';

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertStringStartsWith(self::ELIPSIS, $hasil);
        $this->assertStringEndsWith('Saerah', $hasil);
        $this->assertSame(47, mb_strlen($hasil));
    }

    public function testKataKunciTidakDitemukanJendelaMulaiDariAwalTeks(): void
    {
        $teks = str_repeat('a', 200);

        $hasil = $this->service->potong($teks, 'tidak-ada-di-pesan-ini');

        $this->assertSame(str_repeat('a', 120) . self::ELIPSIS, $hasil);
    }

    /**
     * AC-014g: a 500+ character message holding the keyword in the middle and
     * several new lines -> at most 122 characters, contains the keyword, no
     * new line, and starts AND ends with the ellipsis.
     */
    public function testPesanPanjangDipotongDenganElipsisDiKeduaSisi(): void
    {
        $teks = str_repeat('a', 250) . "\n\n" . 'Pesan atas nama Saerah' . "\n" . str_repeat('b', 250);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertGreaterThanOrEqual(500, mb_strlen($teks));
        $this->assertStringContainsString('Saerah', $hasil);
        $this->assertStringNotContainsString("\n", $hasil);
        $this->assertStringNotContainsString("\r", $hasil);
        $this->assertStringStartsWith(self::ELIPSIS, $hasil);
        $this->assertStringEndsWith(self::ELIPSIS, $hasil);
        $this->assertSame(122, mb_strlen($hasil));
    }

    /**
     * Multi-byte safety: the cut window must count characters, not bytes, so
     * no accented letter is split in half.
     */
    public function testHurufBeraksenTidakTerbelah(): void
    {
        $teks = str_repeat('é', 60) . 'Saerah' . str_repeat('ü', 200);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertTrue(mb_check_encoding($hasil, 'UTF-8'));
        $this->assertSame(122, mb_strlen($hasil));
        $this->assertSame(40, substr_count($hasil, 'é'));
        $this->assertSame(74, substr_count($hasil, 'ü'));
    }

    /**
     * Emoji safety: a 4-byte emoji must survive the cut as a whole character.
     */
    public function testEmojiTidakTerbelah(): void
    {
        $teks = str_repeat("\u{1F600}", 100) . 'saerah' . str_repeat("\u{1F389}", 200);

        $hasil = $this->service->potong($teks, 'saerah');

        $this->assertTrue(mb_check_encoding($hasil, 'UTF-8'));
        $this->assertSame(122, mb_strlen($hasil));
        $this->assertStringContainsString('saerah', $hasil);
        $this->assertSame(40, substr_count($hasil, "\u{1F600}"));
        $this->assertSame(74, substr_count($hasil, "\u{1F389}"));
    }
}

