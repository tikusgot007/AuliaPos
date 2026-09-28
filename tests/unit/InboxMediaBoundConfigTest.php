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
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');

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
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
     * REQ-1001/TEST-1003: saat env prefetch TIDAK diset, nilai fallback
     * WAJIB tetap di-clamp ke `maxMediaDownloadMb`. Batas unduh 10 (nilai
     * sah) harus menahan prefetch di 10, bukan jatuh ke default deklarasi 15
     * yang diam-diam melebihi batas unduh dan menonaktifkan kontrol DoS.
     */
    public function testPrefetchFallbackIkutDibatasiOlehBatasUnduh(): void
    {
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
            $_ENV['inbox.maxMediaDownloadMb'] = '10';

            $config = new InboxConfig();

            $this->assertSame(10, $config->maxMediaDownloadMb, 'Batas unduh 10 dibaca.');
            $this->assertSame(
                10,
                $config->maxMediaPrefetchMb,
                'REQ-1001: fallback prefetch di-clamp ke batas unduh (10), bukan default 15.'
            );
            $this->assertLessThanOrEqual(
                $config->maxMediaDownloadMb,
                $config->maxMediaPrefetchMb,
                'REQ-1001: invarian maxMediaPrefetchMb <= maxMediaDownloadMb wajib berlaku.'
            );
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    /**
     * REQ-901/TEST-905 (CLN-1004): bukti isolasi bekerja DUA arah — env
     * override MEMANG dihormati saat diset (7), lalu default 15 kembali saat
     * env bersih. Nama lama (`...TetapTerkunciWalauEnvMengOverride`)
     * menyesatkan: asersinya justru membuktikan override DIBACA.
     */
    public function testOverridePrefetchDihormatiLaluDefaultPulihSaatEnvBersih(): void
    {
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
     * RISK-901: clamp tidak boleh menolak nilai sah. Test ini mengisolasi
     * KETIGA kunci env ambien lebih dulu (snapshot via `env()`, setel
     * `maxMediaDownloadMb` eksplisit) supaya `.env` pengembang tidak dapat
     * membuatnya merah; prefetch 150 hanya sah bila batas unduh 200
     * (rentang atas mengikuti `maxMediaDownloadMb`).
     */
    public function testPrefetchSahDihormatiDanBatasAtasIkutBatasUnduh(): void
    {
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
            $_ENV['inbox.maxMediaDownloadMb'] = '100';
            $_ENV['inbox.maxMediaPrefetchMb'] = '7';

            $config = new InboxConfig();

            $this->assertSame(7, $config->maxMediaPrefetchMb, 'SEC-901: nilai sah tetap dihormati.');

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
     * TEST-1008/SEC-901: cabang nilai-tidak-sah (`$nilai < 1`) pada
     * `batasiEnvMb()` juga WAJIB memakai clamp fallback `min($default,
     * $maks)` -- bukan hanya cabang `$nilai > $maks`. Env prefetch `0`
     * (tidak sah) dengan batas unduh 10 harus menghasilkan prefetch 10,
     * membuktikan invarian prefetch <= unduh berlaku di KEDUA cabang.
     */
    public function testPrefetchEnvTidakSahJatuhKeBatasUnduh(): void
    {
        $prevUpload   = $this->rekamEnv('inbox.maxMediaUploadMb');
        $prevDownload = $this->rekamEnv('inbox.maxMediaDownloadMb');
        $prevPrefetch = $this->rekamEnv('inbox.maxMediaPrefetchMb');

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
            $_ENV['inbox.maxMediaDownloadMb'] = '10';
            $_ENV['inbox.maxMediaPrefetchMb'] = '0';

            $config = new InboxConfig();

            $this->assertSame(10, $config->maxMediaDownloadMb, 'Batas unduh 10 dibaca.');
            $this->assertSame(
                10,
                $config->maxMediaPrefetchMb,
                'TEST-1008: env prefetch tidak sah (0) -> fallback min(default 15, batas unduh 10) = 10.'
            );
            $this->assertLessThanOrEqual(
                $config->maxMediaDownloadMb,
                $config->maxMediaPrefetchMb,
                'TEST-1008: invarian maxMediaPrefetchMb <= maxMediaDownloadMb berlaku di cabang invalid-value.'
            );
        } finally {
            $this->pulihkanEnv('inbox.maxMediaUploadMb', $prevUpload);
            $this->pulihkanEnv('inbox.maxMediaDownloadMb', $prevDownload);
            $this->pulihkanEnv('inbox.maxMediaPrefetchMb', $prevPrefetch);
        }
    }

    /**
     * REQ-901/TEST-1002 (CS-04): rekam nilai env ambien PER KANAL yang dibaca
     * `env()` CI4 (`$_ENV`, `$_SERVER`, `getenv()`) supaya `pulihkanEnv()`
     * dapat memulihkannya persis ke kanal asalnya -- bukan menulis ulang ke
     * ketiga kanal dan mengubah distribusi kanal.
     *
     * @return array{env: string|null, server: string|null, getenv: string|false}
     */
    private function rekamEnv(string $kunci): array
    {
        return [
            'env'    => $_ENV[$kunci] ?? null,
            'server' => $_SERVER[$kunci] ?? null,
            'getenv' => getenv($kunci),
        ];
    }

    /**
     * @param array{env: string|null, server: string|null, getenv: string|false} $snapshot
     */
    private function pulihkanEnv(string $kunci, array $snapshot): void
    {
        if ($snapshot['env'] === null) {
            unset($_ENV[$kunci]);
        } else {
            $_ENV[$kunci] = $snapshot['env'];
        }

        if ($snapshot['server'] === null) {
            unset($_SERVER[$kunci]);
        } else {
            $_SERVER[$kunci] = $snapshot['server'];
        }

        if ($snapshot['getenv'] === false) {
            putenv($kunci);
        } else {
            putenv($kunci . '=' . $snapshot['getenv']);
        }
    }
}
