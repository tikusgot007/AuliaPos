<?php

use App\Services\SenderIdentityFormatter;
use PHPUnit\Framework\TestCase;

/**
 * SenderIdentityFormatter -- aturan label identitas pengirim grup
 * (Grup Tahap 2, REQ-008/AC-002; diekstrak dari controller, temuan ARCH-01).
 *
 * Pure: DB/session/request independent, jadi cukup PHPUnit biasa.
 * Invarian: TIDAK PERNAH mengembalikan JID mentah ke UI.
 *
 * @internal
 */
final class SenderIdentityFormatterTest extends TestCase
{
    private SenderIdentityFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new SenderIdentityFormatter();
    }

    /**
     * SEC-001: label yang dikirim ke UI tidak boleh mengandung `@` (JID
     * mentah) dan harus sama persis dengan harapan. `null` berarti "tanpa
     * identitas", sehingga tidak ada label untuk diperiksa.
     */
    private function assertLabelSafe(?string $expected, ?string $jid): void
    {
        $label = $this->formatter->labelFor($jid);

        $this->assertSame($expected, $label, 'Label untuk ' . var_export($jid, true));

        if ($label !== null) {
            $this->assertStringNotContainsString('@', $label, 'Label tidak boleh mengandung JID mentah: ' . $label);
        }
    }

    public function testNomorBersihTanpaSufiksDevice(): void
    {
        // TASK-301/CORR-03: `:NN` adalah sufiks device, bukan bagian nomor.
        $this->assertSame('6281234567890', $this->formatter->labelFor('6281234567890@s.whatsapp.net'));
        $this->assertSame('6281234567890', $this->formatter->labelFor('6281234567890:12@s.whatsapp.net'));
        $this->assertSame('6281234567890', $this->formatter->labelFor('6281234567890:99@s.whatsapp.net'));
    }

    public function testLidDanDomainBerakhiranLid(): void
    {
        $this->assertSame('LID', $this->formatter->labelFor('999888777666@lid'));
        $this->assertSame('LID', $this->formatter->labelFor('123456@hosted.lid'));
    }

    public function testDomainCaseInsensitiveDiklasifikasikanBenar(): void
    {
        // REQ-002 (TASK-102): hostname tidak case-sensitive.
        $this->assertLabelSafe('6281234567890', '6281234567890@S.WHATSAPP.NET');
        $this->assertLabelSafe('LID', '999@LID');
        $this->assertLabelSafe('LID', '999@hosted.LID');
        $this->assertLabelSafe(null, '120363@G.US');
    }

    public function testCraftedLocalPartBerAtTidakPernahJidMentah(): void
    {
        // REQ-001 (TASK-101): local part `s.whatsapp.net` ber-`@` tidak boleh
        // lolos sebagai label -- dulu mengembalikan `120363@g.us` mentah.
        $this->assertLabelSafe('Pengirim', '120363@g.us@s.whatsapp.net');
        $this->assertLabelSafe('Pengirim', '@g.us@s.whatsapp.net');
    }

    public function testFallbackAmanDanTidakPernahJidMentah(): void
    {
        $kasus = [
            'aneh@hosted.example',
            'tanpa-at',
            '@tanpa-local',
            'local@',
            ':12@s.whatsapp.net',
            '',
            null,
        ];

        foreach ($kasus as $jid) {
            // Oracle langsung pada properti: label fallback tidak boleh
            // mengandung `@` maupun sama dengan input mentah.
            $this->assertLabelSafe('Pengirim', $jid);
            $this->assertNotSame($jid, $this->formatter->labelFor($jid), 'JID mentah tidak boleh dirender sebagai label.');
        }
    }

    public function testGroupJidMengembalikanTanpaIdentitas(): void
    {
        // REQ-003/REQ-011/AC-012 (spec v1.4): JID grup `@g.us` bukan identitas
        // anggota -> tanpa label (null), JID mentah tidak pernah dikembalikan.
        // Termasuk domain case-insensitive dan local kosong (`@g.us`).
        $this->assertLabelSafe(null, '120363012345678901@g.us');
        $this->assertLabelSafe(null, '120363@g.us');
        $this->assertLabelSafe(null, '120363@G.US');
        $this->assertLabelSafe(null, '@g.us');
    }
}
