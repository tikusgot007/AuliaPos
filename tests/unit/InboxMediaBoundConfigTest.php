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
        $prevUpload   = env('inbox.maxMediaUploadMb');
        $prevDownload = env('inbox.maxMediaDownloadMb');
        $prevPrefetch = env('inbox.maxMediaPrefetchMb');

        // REQ-901/TEST-802: isolasi env ambien (.env pengembang) di TIGA kanal
        // ($_ENV/$_SERVER/getenv) supaya asersi default tidak rapuh.
        unset(
            $_ENV['inbox.maxMediaUploadMb'],
            $_SERVER['inbox.maxMediaUploadMb'],
            $_ENV['inbox.maxMediaDownloadMb'],
            $_SERVER['inbox.maxMediaDownloadMb'],
            $_ENV['inbox.maxMediaPrefetchMb'],
            $_SERVER['inbox.maxMediaPrefetchMb']
        );
        putenv('inbox.maxMediaUploadMb');
        putenv('inbox.maxMediaDownloadMb');
        putenv('inbox.maxMediaPrefetchMb');

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
        $prevUpload   = env('inbox.maxMediaUploadMb');
        $prevDownload = env('inbox.maxMediaDownloadMb');

        unset(
            $_ENV['inbox.maxMediaUploadMb'],
            $_SERVER['inbox.maxMediaUploadMb'],
            $_ENV['inbox.maxMediaDownloadMb'],
            $_SERVER['inbox.maxMediaDownloadMb']
        );
        putenv('inbox.maxMediaUploadMb');
        putenv('inbox.maxMediaDownloadMb');

        $_ENV['inbox.maxMediaDownloadMb'] = '42';

        try {
            $config = new InboxConfig();

            $this->assertSame(42, $config->maxMediaDownloadMb, 'Env inbox.maxMediaDownloadMb dibaca.');
            $this->assertSame(15, $config->maxMediaUploadMb, 'Menaikkan batas unduh TIDAK boleh mengubah batas unggah.');
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
        }
    }

    public function testDefaultPrefetchBoundTerkunciDanTerpisah(): void
    {
        $prevUpload   = env('inbox.maxMediaUploadMb');
        $prevDownload = env('inbox.maxMediaDownloadMb');
        $prevPrefetch = env('inbox.maxMediaPrefetchMb');

        // REQ-901/TEST-802: isolasi env ambien di TIGA kanal supaya asersi
        // default prefetch tidak rapuh.
        unset(
            $_ENV['inbox.maxMediaUploadMb'],
            $_SERVER['inbox.maxMediaUploadMb'],
            $_ENV['inbox.maxMediaDownloadMb'],
            $_SERVER['inbox.maxMediaDownloadMb'],
            $_ENV['inbox.maxMediaPrefetchMb'],
            $_SERVER['inbox.maxMediaPrefetchMb']
        );
        putenv('inbox.maxMediaUploadMb');
        putenv('inbox.maxMediaDownloadMb');
        putenv('inbox.maxMediaPrefetchMb');

        try {
            $config = new InboxConfig();

            $this->assertSame(15, $config->maxMediaPrefetchMb, 'REQ-801: batas ingest prefetch default 15MB.');
            $this->assertLessThan(
                $config->maxMediaDownloadMb,
                $config->maxMediaPrefetchMb,
                'REQ-801: batas ingest WAJIB lebih ketat dari batas unduh/tampilan (sumbu terpisah).'
            );
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    /**
     * REQ-901/TEST-905: bukti isolasi bekerja DUA arah — env override
     * tetap dihormati saat diset, dan default 15 kembali saat env bersih.
     */
    public function testDefaultPrefetchTetapTerkunciWalauEnvMengOverride(): void
    {
        $prevUpload   = env('inbox.maxMediaUploadMb');
        $prevDownload = env('inbox.maxMediaDownloadMb');
        $prevPrefetch = env('inbox.maxMediaPrefetchMb');

        unset(
            $_ENV['inbox.maxMediaUploadMb'],
            $_SERVER['inbox.maxMediaUploadMb'],
            $_ENV['inbox.maxMediaDownloadMb'],
            $_SERVER['inbox.maxMediaDownloadMb'],
            $_ENV['inbox.maxMediaPrefetchMb'],
            $_SERVER['inbox.maxMediaPrefetchMb']
        );
        putenv('inbox.maxMediaUploadMb');
        putenv('inbox.maxMediaDownloadMb');
        putenv('inbox.maxMediaPrefetchMb');

        try {
            $_ENV['inbox.maxMediaPrefetchMb'] = '7';

            $config = new InboxConfig();

            $this->assertSame(7, $config->maxMediaPrefetchMb, 'Env override inbox.maxMediaPrefetchMb MEMANG terbaca.');

            unset($_ENV['inbox.maxMediaPrefetchMb'], $_SERVER['inbox.maxMediaPrefetchMb']);
            putenv('inbox.maxMediaPrefetchMb');

            $config = new InboxConfig();

            $this->assertSame(15, $config->maxMediaPrefetchMb, 'Setelah env bersih, default prefetch 15 kembali.');
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    public function testEnvMengubahBatasPrefetchTanpaMenyentuhSumbuLain(): void
    {
        $prevUpload   = env('inbox.maxMediaUploadMb');
        $prevDownload = env('inbox.maxMediaDownloadMb');
        $prevPrefetch = env('inbox.maxMediaPrefetchMb');

        unset(
            $_ENV['inbox.maxMediaUploadMb'],
            $_SERVER['inbox.maxMediaUploadMb'],
            $_ENV['inbox.maxMediaDownloadMb'],
            $_SERVER['inbox.maxMediaDownloadMb'],
            $_ENV['inbox.maxMediaPrefetchMb'],
            $_SERVER['inbox.maxMediaPrefetchMb']
        );
        putenv('inbox.maxMediaUploadMb');
        putenv('inbox.maxMediaDownloadMb');
        putenv('inbox.maxMediaPrefetchMb');

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

    /**
     * SEC-901/TEST-906: nilai env batas media yang tidak sah (0, negatif,
     * raksasa) JATUH ke default, bukan diam-diam melumpuhkan/menonaktifkan
     * kontrol.
     */
    public function testEnvBatasMediaTidakSahJatuhKeDefault(): void
    {
        $prevUpload   = env('inbox.maxMediaUploadMb');
        $prevDownload = env('inbox.maxMediaDownloadMb');
        $prevPrefetch = env('inbox.maxMediaPrefetchMb');

        try {
            foreach (['0', '-5'] as $nilai) {
                unset(
                    $_ENV['inbox.maxMediaUploadMb'],
                    $_SERVER['inbox.maxMediaUploadMb'],
                    $_ENV['inbox.maxMediaDownloadMb'],
                    $_SERVER['inbox.maxMediaDownloadMb'],
                    $_ENV['inbox.maxMediaPrefetchMb'],
                    $_SERVER['inbox.maxMediaPrefetchMb']
                );
                putenv('inbox.maxMediaUploadMb');
                putenv('inbox.maxMediaDownloadMb');
                putenv('inbox.maxMediaPrefetchMb');

                $_ENV['inbox.maxMediaPrefetchMb'] = $nilai;
                $_ENV['inbox.maxMediaUploadMb']   = $nilai;
                $_ENV['inbox.maxMediaDownloadMb'] = $nilai;

                $config = new InboxConfig();

                $this->assertSame(15, $config->maxMediaPrefetchMb, "SEC-901: prefetch env {$nilai} -> default 15.");
                $this->assertSame(15, $config->maxMediaUploadMb, "SEC-901: upload env {$nilai} -> default 15.");
                $this->assertSame(100, $config->maxMediaDownloadMb, "SEC-901: unduh env {$nilai} -> default 100.");
            }

            unset(
                $_ENV['inbox.maxMediaUploadMb'],
                $_SERVER['inbox.maxMediaUploadMb'],
                $_ENV['inbox.maxMediaDownloadMb'],
                $_SERVER['inbox.maxMediaDownloadMb'],
                $_ENV['inbox.maxMediaPrefetchMb'],
                $_SERVER['inbox.maxMediaPrefetchMb']
            );
            putenv('inbox.maxMediaUploadMb');
            putenv('inbox.maxMediaDownloadMb');
            putenv('inbox.maxMediaPrefetchMb');

            $_ENV['inbox.maxMediaUploadMb']   = '100000';
            $_ENV['inbox.maxMediaPrefetchMb'] = '100000';

            $config = new InboxConfig();

            $this->assertSame(100000, $config->maxMediaUploadMb, 'SEC-901: upload tanpa batas atas -> diterima apa adanya.');
            $this->assertSame(100, $config->maxMediaDownloadMb, 'SEC-901: batas unduh default tak tersentuh.');
            $this->assertSame(15, $config->maxMediaPrefetchMb, 'SEC-901: prefetch 100000 > unduh 100 -> default 15.');
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    /**
     * RISK-901: clamp tidak boleh menolak nilai sah. Prefetch 150 hanya
     * sah bila batas unduh dinaikkan ke 200 (rentang atas mengikuti
     * `maxMediaDownloadMb`).
     */
    public function testPrefetchSahDihormatiDanBatasAtasIkutBatasUnduh(): void
    {
        $prevUpload   = env('inbox.maxMediaUploadMb');
        $prevDownload = env('inbox.maxMediaDownloadMb');
        $prevPrefetch = env('inbox.maxMediaPrefetchMb');

        try {
            $_ENV['inbox.maxMediaPrefetchMb'] = '7';

            $config = new InboxConfig();

            $this->assertSame(7, $config->maxMediaPrefetchMb, 'SEC-901: nilai sah tetap dihormati.');

            unset($_ENV['inbox.maxMediaPrefetchMb'], $_SERVER['inbox.maxMediaPrefetchMb']);
            putenv('inbox.maxMediaPrefetchMb');

            $_ENV['inbox.maxMediaDownloadMb'] = '200';
            $_ENV['inbox.maxMediaPrefetchMb'] = '150';

            $config = new InboxConfig();

            $this->assertSame(150, $config->maxMediaPrefetchMb, 'RISK-901: prefetch 150 sah saat unduh 200.');
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    /**
     * REQ-901: pulihkan env ambien batas media di TIGA kanal yang dibaca
     * `env()` CI4 (`$_ENV`, `$_SERVER`, `getenv()`); `$nilai` adalah
     * snapshot kanonik dari `env()` sebelum isolasi.
     *
     * @param string|false|null $nilai
     */
    private function pulihkanEnv(string $kunci, $nilai): void
    {
        if ($nilai === null || $nilai === false) {
            unset($_ENV[$kunci], $_SERVER[$kunci]);
            putenv($kunci);

            return;
        }

        $nilai = (string) $nilai;

        $_ENV[$kunci]    = $nilai;
        $_SERVER[$kunci] = $nilai;
        putenv($kunci . '=' . $nilai);
    }
}
