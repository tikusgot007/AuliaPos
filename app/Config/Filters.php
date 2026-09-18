<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        'auth'          => \App\Filters\AuthFilter::class, // 🔥 TAMBAHKAN INI
        'gatewaytoken'  => \App\Filters\GatewayTokenFilter::class, // Bearer token untuk endpoint machine-to-machine Gateway WA
    ];

    /**
     * List of special required filters.
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps',
            'pagecache',
        ],
        'after' => [
            'pagecache',
            'performance',
            // 'toolbar',
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // 'honeypot',
            // 'csrf',
            // 'invalidchars',
        ],
        'after' => [
            // 'honeypot',
            // 'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [
        // 🔥 TAMBAHKAN INI - Proteksi semua route
        'auth' => [
            'before' => [
                '/',
                'kasir',
                'kasir/*',
                'cash',
                'cash/*',
                'produk',
                'produk/*',
                'kategori',
                'kategori/*',
                'api',
                'api/*',
                'pelanggan',
                'pelanggan/*',
                'transaksi',
                'transaksi/*',
                'laporan',
                'laporan/*',
                'tagihan',
                'tagihan/*',
                'nota',
                'nota/*',
                'cetak',
                'cetak/*',
                'ganti-password',
                'user-management',
                'auth/tambah-user',
                'auth/edit-user/*',
                'auth/hapus-user/*',
            ],
            'except' => [
                'login',
                'auth/proses-login',
                'assets/*',
                'favicon.ico',
                // Endpoint machine-to-machine untuk WhatsApp Gateway --
                // TIDAK PERNAH punya session (Gateway bukan browser
                // kasir yang login), diproteksi lewat filter
                // 'gatewaytoken' (Bearer token) di route-nya sendiri,
                // bukan lewat session. Lihat app/Filters/GatewayTokenFilter.php
                // dan docs/aturan-bisnis-AULIA.md Section 28.
                'api/inbox/gateway/*',
            ]
        ],
    ];
}
