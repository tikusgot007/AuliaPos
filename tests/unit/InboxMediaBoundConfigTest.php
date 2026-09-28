<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Inbox as InboxConfig;

/**
 * PRN-701/CON-703/RISK-703: batas unduh/tampilan media masuk
 * (`maxMediaDownloadMb`) adalah sumbu tersendiri, TERPISAH dari batas
 * unggah keluar (`maxMediaUploadMb`). Test ini mengunci default keduanya
 * supaya keputusan owner "100MB" tidak pernah diterapkan ke batas kirim
 * (yang tetap 15MB, wajib <= default Gateway 20MB).
 *
 * @internal
 */
final class InboxMediaBoundConfigTest extends CIUnitTestCase
{
    public function testDefaultUnduhDanUnggahTerpisah(): void
    {
        $prevUpload   = $_ENV['inbox.maxMediaUploadMb'] ?? null;
        $prevDownload = $_ENV['inbox.maxMediaDownloadMb'] ?? null;
        $prevPrefetch = $_ENV['inbox.maxMediaPrefetchMb'] ?? null;

        // TEST-802: isolasi env ambien (.env pengembang) supaya asersi
        // default tidak rapuh.
        unset(
            $_ENV['inbox.maxMediaUploadMb'],
            $_ENV['inbox.maxMediaDownloadMb'],
            $_ENV['inbox.maxMediaPrefetchMb']
        );

        try {
            $config = new InboxConfig();

            $this->assertSame(15, $config->maxMediaUploadMb, 'CON-703: batas unggah keluar tetap 15MB.');
            $this->assertSame(100, $config->maxMediaDownloadMb, 'PRN-701: batas unduh/tampilan default 100MB.');
            $this->assertSame(15, $config->maxMediaPrefetchMb, 'REQ-801: batas ingest prefetch default 15MB.');
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    public function testEnvMenaikkanBatasUnduhTanpaMengubahBatasUnggah(): void
    {
        $prevUpload   = $_ENV['inbox.maxMediaUploadMb'] ?? null;
        $prevDownload = $_ENV['inbox.maxMediaDownloadMb'] ?? null;

        unset($_ENV['inbox.maxMediaUploadMb']);
        $_ENV['inbox.maxMediaDownloadMb'] = '42';

        try {
            $config = new InboxConfig();

            $this->assertSame(42, $config->maxMediaDownloadMb, 'Env inbox.maxMediaDownloadMb dibaca.');
            $this->assertSame(15, $config->maxMediaUploadMb, 'Menaikkan batas unduh TIDAK boleh mengubah batas unggah.');
        } finally {
            if ($prevDownload === null) {
                unset($_ENV['inbox.maxMediaDownloadMb']);
            } else {
                $_ENV['inbox.maxMediaDownloadMb'] = $prevDownload;
            }

            if ($prevUpload === null) {
                unset($_ENV['inbox.maxMediaUploadMb']);
            } else {
                $_ENV['inbox.maxMediaUploadMb'] = $prevUpload;
            }
        }
    }

    public function testDefaultPrefetchBoundTerkunciDanTerpisah(): void
    {
        $config = new InboxConfig();

        $this->assertSame(15, $config->maxMediaPrefetchMb, 'REQ-801: batas ingest prefetch default 15MB.');
        $this->assertLessThan(
            $config->maxMediaDownloadMb,
            $config->maxMediaPrefetchMb,
            'REQ-801: batas ingest WAJIB lebih ketat dari batas unduh/tampilan (sumbu terpisah).'
        );
    }

    public function testEnvMengubahBatasPrefetchTanpaMenyentuhSumbuLain(): void
    {
        $prevUpload   = $_ENV['inbox.maxMediaUploadMb'] ?? null;
        $prevDownload = $_ENV['inbox.maxMediaDownloadMb'] ?? null;
        $prevPrefetch = $_ENV['inbox.maxMediaPrefetchMb'] ?? null;

        unset($_ENV['inbox.maxMediaUploadMb'], $_ENV['inbox.maxMediaDownloadMb']);
        $_ENV['inbox.maxMediaPrefetchMb'] = '7';

        try {
            $config = new InboxConfig();

            $this->assertSame(7, $config->maxMediaPrefetchMb, 'Env inbox.maxMediaPrefetchMb dibaca.');
            $this->assertSame(15, $config->maxMediaUploadMb, 'Mengubah batas ingest TIDAK boleh mengubah batas unggah.');
            $this->assertSame(100, $config->maxMediaDownloadMb, 'Mengubah batas ingest TIDAK boleh mengubah batas unduh.');
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    /** @param string|null $nilai */
    private function pulihkanEnv(string $kunci, $nilai): void
    {
        if ($nilai === null) {
            unset($_ENV[$kunci]);

            return;
        }

        $_ENV[$kunci] = $nilai;
    }
}
