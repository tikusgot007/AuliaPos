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
            $label = $this->formatter->labelFor($jid);
            $this->assertSame('Pengirim', $label, 'Fallback untuk ' . var_export($jid, true));
            $this->assertNotSame($jid, $label, 'JID mentah tidak boleh dirender sebagai label.');
        }
    }

    public function testGroupJidMengembalikanTanpaIdentitas(): void
    {
        // REQ-011/AC-012 (spec v1.4): JID grup `@g.us` bukan identitas anggota
        // -> tanpa label (null), dan JID mentah tidak pernah dikembalikan.
        $kasus = [
            '120363012345678901@g.us',
            '120363@g.us',
            '120363@G.US',
        ];

        foreach ($kasus as $jid) {
            $this->assertNull($this->formatter->labelFor($jid), 'Tanpa identitas untuk ' . $jid);
        }
    }
}
