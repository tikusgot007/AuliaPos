<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'AULIA KASIR' ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- 🔥 DataTables & Buttons CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">

    <!-- 🔥 Date Range Picker CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
    <!-- ========================================== -->
    <!-- SCRIPTS (JS)                               -->
    <!-- ========================================== -->

    <!-- 1. JQUERY & BOOTSTRAP -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>


    <style>
        .no-order-wrapper {
            position: relative;
        }

        .no-order-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            width: 240px;
            max-height: 260px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: .375rem;
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15);
            z-index: 99999;
            padding: .35rem;
        }

        .no-order-option {
            display: flex;
            width: 100%;
            align-items: center;
            justify-content: space-between;
            border: 0;
            background: transparent;
            padding: .45rem .65rem;
            border-radius: .25rem;
            text-align: left;
        }

        .no-order-option:hover {
            background: #f8f9fa;
        }

        .no-order-option.recommended {
            background: #fff3cd;
            font-weight: 600;
        }

        /* ========================================== */
        /* TOMBOL FILTER KATEGORI                     */
        /* ========================================== */

        .btn-filter-kategori {
            transition: all 0.2s ease;
            border-radius: 20px;
            font-size: 0.75rem;
            padding: 4px 12px;
        }

        .btn-filter-kategori.active {
            background-color: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }

        .btn-filter-kategori:hover {
            transform: translateY(-1px);
        }

        .btn-filter-kategori:active {
            transform: scale(0.95);
        }

        /* ========================================== */
        /* VALIDASI INPUT - HIGHLIGHT ERROR           */
        /* ========================================== */

        .input-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
            animation: shake 0.3s ease-in-out;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-10px);
            }

            75% {
                transform: translateX(10px);
            }
        }

        /* ========================================== */
        /* KERANJANG - TAMPILAN COMPACT               */
        /* ========================================== */

        #keranjangContainer {
            scroll-behavior: smooth;
        }

        #keranjangContainer .border-bottom {
            border-bottom: 1px solid #e9ecef !important;
        }

        #keranjangContainer .form-control-sm {
            font-size: 0.65rem;
        }

        /* Scrollbar yang lebih tipis */
        #keranjangContainer::-webkit-scrollbar {
            width: 4px;
        }

        #keranjangContainer::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        #keranjangContainer::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }

        #keranjangContainer::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }

        /* ========================================== */
        /* EFEK HOVER PER KATEGORI                     */
        /* ========================================== */

        /* Digital Printing - Biru */
        .card.border-primary:hover {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3) !important;
            transform: translateY(-2px);
        }

        /* Penjualan Umum - Kuning */
        .card.border-warning:hover {
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3) !important;
            transform: translateY(-2px);
        }

        /* Fotokopi - Abu-abu */
        .card.border-secondary:hover {
            box-shadow: 0 4px 12px rgba(156, 163, 175, 0.3) !important;
            transform: translateY(-2px);
        }

        /* Minuman - Hijau */
        .card.border-success:hover {
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3) !important;
            transform: translateY(-2px);
        }

        /* Foto - Merah */
        .card.border-danger:hover {
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3) !important;
            transform: translateY(-2px);
        }

        /* Efek transisi */
        .card.card-kasir {
            transition: all 0.2s ease-in-out;
        }

        .card.card-kasir:hover {
            transform: translateY(-3px);
        }

        /* Badge Tagihan */
        #badgeTagihan {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 20px;
            margin-top: 3px;
            transition: all 0.3s ease;
        }

        #badgeTagihan.bg-danger {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Diskon di keranjang */
        #detailPembulatan .form-control-sm {
            font-size: 0.75rem;
            padding: 0.1rem 0.3rem;
            height: 24px;
        }

        #detailPembulatan .form-select-sm {
            font-size: 0.7rem;
            padding: 0.1rem 0.3rem;
            height: 24px;
            min-height: 24px;
        }

        #detailPembulatan .btn-sm {
            padding: 0.1rem 0.3rem;
            font-size: 0.7rem;
            height: 24px;
        }

        #detailPembulatan .small {
            font-size: 0.75rem;
        }

        .badge.bg-info {
            background-color: #17a2b8 !important;
            color: white;
            font-size: 0.65rem;
        }

        /* Preview pembulatan */
        #detailPembulatan {
            background-color: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        #previewPembulatan {
            color: #dc3545;
            font-weight: bold;
        }

        /* Toast Notification */
        #liveToast {
            min-width: 250px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            font-size: 14px;
        }

        .toast-success {
            background-color: #28a745;
            color: white;
        }

        .toast-danger {
            background-color: #dc3545;
            color: white;
        }

        .toast-warning {
            background-color: #ffc107;
            color: #212529;
        }

        .toast-info {
            background-color: #17a2b8;
            color: white;
        }

        body {
            background-color: #f8f9fa;
        }

        /* ========================================== */
        /* SIDEBAR - AULIA DARK / GREEN THEME         */
        /* ========================================== */

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #02241c 0%, #022a14 100%);
            position: sticky;
            top: 0;
        }

        .sidebar .brand {
            font-size: 1.5rem;
            font-weight: bold;
            color: #ffffff;
            padding: 20px;
            border-bottom: 0px solid #2a2a2a;
            text-align: center;
        }

        .sidebar .brand small {
            display: block;
            font-size: 0.7rem;
            color: #9ca3af;
            font-weight: normal;
        }

        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #b8b8b8;
            padding: 12px 20px;
            border-radius: 8px;
            margin: 2px 8px;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link:hover {
            color: #ffffff;
            background: #222222;
        }

        .sidebar .nav-link.active {
            background: linear-gradient(90deg, rgba(22, 133, 107, 0.95), rgba(16, 107, 87, 0.95));
            color: #ffffff;
            border-left: 3px solid #1da47f;
        }

        .sidebar .nav-link i {
            width: 24px;
            text-align: center;
            color: #9ca3af;
        }

        .sidebar .nav-link:hover i,
        .sidebar .nav-link.active i {
            color: #1da47f;
        }

        /* SUBMENU */

        .sidebar .submenu {
            margin-left: 18px;
            padding-left: 8px;
            border-left: 2px solid rgba(29, 164, 127, 0.25);
        }

        .sidebar .submenu .nav-link {
            padding: 8px 12px;
            font-size: 14px;
        }

        .sidebar .nav-link .fa-chevron-down {
            transition: transform .25s ease;
            color: #8a8a8a;
        }

        .sidebar .nav-link[aria-expanded="true"] .fa-chevron-down {
            transform: rotate(180deg);
            color: #1da47f;
        }

        .sidebar .nav-link[aria-expanded="false"] .fa-chevron-down {
            transform: rotate(0deg);
        }

        /* Logout tetap merah */

        .sidebar a[href*="logout"]:hover {
            color: #ff6b6b !important;
            background: rgba(220, 53, 69, 0.10);
        }

        /* Responsive sidebar */

        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                position: relative;
            }
        }

        /* ========================================== */
        /* MAIN CONTENT - TETAP SAMA                  */
        /* ========================================== */

        .main-content {
            padding: 25px;
            background-color: #f8f9fa;
            min-height: 100vh;
        }

        /* CARD KASIR */
        .card-kasir {
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }

        .card-kasir:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        /* ========================================== */
        /* TOP HEADER - AULIA DARK / GREEN THEME      */
        /* ========================================== */

        .top-header {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: #02241c !important;
            border-bottom: 1px solid #2a2a2a !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18) !important;
            /* .main-content (pembungkus <header> ini) punya
               padding: 25px di SEMUA sisi, termasuk atas -- itu yang
               bikin ada celah putih di atas header. Margin negatif ini
               menembus padding atas tsb (berlaku di semua ukuran
               layar, karena padding 25px itu juga tidak bergantung
               breakpoint), supaya header benar-benar nempel ke ujung
               atas viewport. margin-bottom sebaliknya SENGAJA
               ditambahkan (bukan menembus apa pun) supaya ada jarak
               napas antara header dan konten halaman di bawahnya. */
            margin-top: -25px;
            margin-bottom: 20px;
        }

        /* Header berada di dalam <main class="px-md-4">, yang punya
           padding kiri-kanan 1.5rem mulai breakpoint md. Padding itu
           membuat header seolah "mengambang", terpisah dari sidebar
           (ada celah terang di antara keduanya). Margin negatif ini
           menembus padding tsb persis di breakpoint yang sama, supaya
           header nempel rata ke tepi sidebar -- jadi kelihatan sebagai
           satu navbar menyatu, bukan 2 kotak terpisah. Konten di
           dalam header tetap punya jarak yang wajar karena
           container-fluid/row di dalamnya sudah punya padding sendiri. */
        @media (min-width: 768px) {
            .top-header {
                margin-left: -1.5rem;
                margin-right: -1.5rem;
            }
        }

        .top-header .dropdown-menu {
            min-width: 180px;
            font-size: 0.85rem;
            background: #1b1b1b;
            border: 1px solid #2f2f2f;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }

        .top-header .dropdown-item {
            padding: 8px 16px;
            color: #d1d5db;
        }

        .top-header .dropdown-item:hover {
            background: #242424;
            color: #ffffff;
        }

        .top-header .badge {
            font-size: 0.6rem;
            padding: 2px 6px;
        }

        /* Search */

        .top-header .search-box .form-control {
            background: #222222 !important;
            border: 1px solid #333333 !important;
            color: #ffffff !important;
        }

        .top-header .search-box .form-control::placeholder {
            color: #8f8f8f !important;
        }

        .top-header .search-box .form-control:focus {
            background: #242424 !important;
            border-color: #16856b !important;
            box-shadow: 0 0 0 0.2rem rgba(22, 133, 107, 0.18) !important;
        }

        .top-header .search-box button {
            color: #1da47f !important;
        }

        /* Icon header */

        .top-header .nav-icon {
            color: #b8b8b8 !important;
        }

        .top-header .nav-icon:hover {
            color: #1da47f !important;
        }

        /* Nama user dan teks header */

        .top-header .text-dark {
            color: #f3f4f6 !important;
        }

        .top-header .text-muted {
            color: #9ca3af !important;
        }

        /* Avatar user */

        .top-header .user-avatar {
            background: #16856b !important;
            color: #ffffff !important;
        }

        /* Pemisah header */

        .top-header hr,
        .top-header .border-bottom {
            border-color: #2a2a2a !important;
        }

        /* Responsive header */

        @media (max-width: 768px) {
            .top-header .container-fluid .row {
                flex-direction: column;
                text-align: center;
            }

            .top-header .col-md-4 {
                width: 100%;
                margin-bottom: 5px;
            }

            .top-header .text-end {
                text-align: center !important;
            }
        }
    </style>
</head>

<body>


    <div class="container-fluid p-0">
        <div class="row g-0">

            <!-- ========================================== --> <!-- SIDEBAR / MENU (KIRI) --> <!-- ========================================== -->
            <nav class="col-md-2 d-none d-md-block sidebar p-0">
                <div class="brand"> <img src="<?= base_url('AuliaPos.png') ?>" alt="Logo" style="max-height: 60px; width: auto; margin-right: 8px;" onerror="this.style.display='none'"> <small>Kasir System V2.0</small> </div>
                <?php
                $isCashMenu = strpos(current_url(), '/cash') !== false;

                $isTransaksiMenu =
                    strpos(current_url(), '/transaksi') !== false;

                $isMasterMenu =
                    strpos(current_url(), '/produk') !== false ||
                    strpos(current_url(), '/kategori') !== false ||
                    strpos(current_url(), '/pelanggan') !== false ||
                    strpos(current_url(), '/ukuran') !== false;

                $isAdminMenu =
                    strpos(current_url(), '/user-management') !== false ||
                    strpos(current_url(), '/ganti-password') !== false ||
                    strpos(current_url(), '/archive-transaksi') !== false;

                $isLaporanMenu =
                    strpos(current_url(), '/laporan') !== false;
                ?>

                <ul class="nav flex-column mt-3">

                    <!-- KASIR -->
                    <li class="nav-item">
                        <a class="nav-link <?= (current_url() == base_url('/') || current_url() == base_url('/kasir')) ? 'active' : '' ?>"
                            href="<?= base_url('/') ?>">
                            <i class="fas fa-cash-register"></i>
                            <span>Kasir</span>
                        </a>
                    </li>

                    <!-- ================================= -->
                    <!-- TRANSAKSI -->
                    <!-- ================================= -->

                    <li class="nav-item">

                        <a class="nav-link d-flex align-items-center"
                            data-bs-toggle="collapse"
                            href="#menuTransaksi"
                            role="button"
                            aria-expanded="<?= $isTransaksiMenu ? 'true' : 'false' ?>">

                            <i class="fas fa-file-invoice"></i>
                            <span>Transaksi</span>

                            <i class="fas fa-chevron-down ms-auto"></i>

                        </a>

                        <div id="menuTransaksi"
                            class="collapse <?= $isTransaksiMenu ? 'show' : '' ?>">

                            <ul class="nav flex-column submenu">

                                <li class="nav-item">
                                    <a class="nav-link <?= current_url() == base_url('/transaksi') ? 'active' : '' ?>"
                                        href="<?= base_url('/transaksi') ?>">
                                        <i class="fas fa-file-invoice"></i>
                                        Transaksi
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link <?= strpos(current_url(), '/transaksi/hari-ini') !== false ? 'active' : '' ?>"
                                        href="<?= base_url('/transaksi/hari-ini') ?>">
                                        <i class="fas fa-list"></i>
                                        Transaksi Hari Ini
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link <?= strpos(current_url(), '/laporan-pembayaran') !== false ? 'active' : '' ?>"
                                        href="<?= base_url('/laporan-pembayaran') ?>">
                                        <i class="fas fa-money-check-alt"></i>
                                        Laporan Pembayaran
                                    </a>
                                </li>

                            </ul>

                        </div>

                    </li>


                    <!-- TAGIHAN -->
                    <li class="nav-item">
                        <a class="nav-link <?= strpos(current_url(), '/tagihan') !== false ? 'active' : '' ?>"
                            href="<?= base_url('/tagihan') ?>">
                            <i class="fas fa-file-invoice"></i>
                            <span>Tagihan</span>
                            <span class="badge bg-warning text-dark ms-auto" id="badgeTagihan">0</span>
                        </a>
                    </li>


                    <!-- ================================= -->
                    <!-- KEUANGAN -->
                    <!-- ================================= -->

                    <li class="nav-item">

                        <a class="nav-link d-flex align-items-center"
                            data-bs-toggle="collapse"
                            href="#menuKeuangan"
                            role="button"
                            aria-expanded="<?= $isCashMenu ? 'true' : 'false' ?>">

                            <i class="fas fa-wallet"></i>
                            <span>Keuangan</span>

                            <i class="fas fa-chevron-down ms-auto"></i>
                        </a>

                        <div id="menuKeuangan"
                            class="collapse <?= $isCashMenu ? 'show' : '' ?>">

                            <ul class="nav flex-column submenu">

                                <li class="nav-item">
                                    <a class="nav-link <?= current_url() == base_url('/cash') ? 'active' : '' ?>"
                                        href="<?= base_url('/cash') ?>">
                                        <i class="fas fa-wallet"></i>
                                        Kas
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link <?= strpos(current_url(), '/cash/pengeluaran') !== false ? 'active' : '' ?>"
                                        href="<?= base_url('/cash/pengeluaran') ?>">
                                        <i class="fas fa-money-bill-wave"></i>
                                        Kas Keluar
                                    </a>
                                </li>

                            </ul>
                        </div>
                    </li>


                    <!-- ================================= -->
                    <!-- MASTER DATA -->
                    <!-- ================================= -->

                    <li class="nav-item">

                        <a class="nav-link d-flex align-items-center"
                            data-bs-toggle="collapse"
                            href="#menuMaster"
                            role="button"
                            aria-expanded="<?= $isMasterMenu ? 'true' : 'false' ?>">

                            <i class="fas fa-database"></i>
                            <span>Master Data</span>

                            <i class="fas fa-chevron-down ms-auto"></i>
                        </a>

                        <div id="menuMaster"
                            class="collapse <?= $isMasterMenu ? 'show' : '' ?>">

                            <ul class="nav flex-column submenu">

                                <?php if (session()->get('role') == 'admin'): ?>
                                <li class="nav-item">
                                    <a class="nav-link <?= strpos(current_url(), '/produk') !== false ? 'active' : '' ?>"
                                        href="<?= base_url('/produk') ?>">
                                        <i class="fas fa-boxes"></i>
                                        Produk
                                    </a>
                                </li>

                                <li class="nav-item">
                                    <a class="nav-link <?= strpos(current_url(), '/kategori') !== false ? 'active' : '' ?>"
                                        href="<?= base_url('/kategori') ?>">
                                        <i class="fas fa-tags"></i>
                                        Kategori
                                    </a>
                                </li>
                                <?php endif; ?>

                                <li class="nav-item">
                                    <a class="nav-link <?= strpos(current_url(), '/pelanggan') !== false ? 'active' : '' ?>"
                                        href="<?= base_url('/pelanggan') ?>">
                                        <i class="fas fa-users"></i>
                                        Pelanggan
                                    </a>
                                </li>

                            </ul>
                        </div>
                    </li>


                    <!-- ================================= -->
                    <!-- LAPORAN -->
                    <!-- ================================= -->

                    <?php if (session()->get('role') == 'admin'): ?>

                        <li class="nav-item">
                            <a class="nav-link d-flex align-items-center"
                                data-bs-toggle="collapse"
                                href="#menuLaporan"
                                role="button"
                                aria-expanded="<?= $isLaporanMenu ? 'true' : 'false' ?>">
                                <i class="fas fa-chart-bar"></i>
                                <span>Laporan</span>
                                <i class="fas fa-chevron-down ms-auto"></i>
                            </a>

                            <div id="menuLaporan"
                                class="collapse <?= $isLaporanMenu ? 'show' : '' ?>">
                                <ul class="nav flex-column submenu">

                                    <li class="nav-item">
                                        <a class="nav-link <?= current_url() == base_url('/laporan') ? 'active' : '' ?>"
                                            href="<?= base_url('/laporan') ?>">
                                            <i class="fas fa-chart-bar"></i>
                                            Laporan
                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link <?= strpos(current_url(), '/laporan/item-harian') !== false ? 'active' : '' ?>"
                                            href="<?= base_url('/laporan/item-harian') ?>">
                                            <i class="fas fa-list-alt"></i>
                                            Laporan Item Harian
                                        </a>
                                    </li>

                                </ul>
                            </div>
                        </li>

                    <?php endif; ?>


                    <!-- ADMIN -->
                    <!-- ================================= -->

                    <?php if (session()->get('isLoggedIn') && session()->get('role') == 'admin'): ?>

                        <li class="nav-item">

                            <a class="nav-link d-flex align-items-center"
                                data-bs-toggle="collapse"
                                href="#menuAdmin"
                                role="button"
                                aria-expanded="<?= $isAdminMenu ? 'true' : 'false' ?>">

                                <i class="fas fa-user-shield"></i>
                                <span>Administrasi</span>

                                <i class="fas fa-chevron-down ms-auto"></i>

                            </a>

                            <div id="menuAdmin"
                                class="collapse <?= $isAdminMenu ? 'show' : '' ?>">

                                <ul class="nav flex-column submenu">

                                    <li class="nav-item">
                                        <a class="nav-link <?= strpos(current_url(), '/user-management') !== false ? 'active' : '' ?>"
                                            href="<?= base_url('/user-management') ?>">

                                            <i class="fas fa-users-cog"></i>
                                            Manajemen User

                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link <?= strpos(current_url(), '/ganti-password') !== false ? 'active' : '' ?>"
                                            href="<?= base_url('/ganti-password') ?>">

                                            <i class="fas fa-key"></i>
                                            Ganti Password

                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link <?= strpos(current_url(), '/migrasi-manual') !== false ? 'active' : '' ?>"
                                            href="<?= base_url('/migrasi-manual') ?>">

                                            <i class="fas fa-database"></i>
                                            Migrasi Database

                                        </a>
                                    </li>

                                    <li class="nav-item">
                                        <a class="nav-link <?= strpos(current_url(), '/archive-transaksi') !== false ? 'active' : '' ?>"
                                            href="<?= base_url('/archive-transaksi') ?>">

                                            <i class="fas fa-box-archive"></i>
                                            Archive Transaksi

                                        </a>
                                    </li>

                                </ul>
                            </div>

                        </li>

                    <?php endif; ?>


                    <!-- LOGOUT -->
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger"
                            href="<?= base_url('/logout') ?>">

                            <i class="fas fa-sign-out-alt"></i>
                            Logout

                        </a>
                    </li>

                </ul>
            </nav>

            <!-- ========================================== -->
            <!-- MAIN CONTENT (KANAN) - KONTEN DINAMIS      -->
            <!-- ========================================== -->
            <main class="col-md-10 ms-sm-auto px-md-4 main-content">

                <!-- ========================================== -->
                <!-- TOP HEADER (HANYA DI ATAS KONTEN)          -->
                <!-- ========================================== -->
                <header class="top-header">
                    <div class="container-fluid">
                        <div class="row align-items-center py-2 g-2 px-3">



                            <!-- Search Box -->
                            <div class="search-box" style="max-width: 400px; position: relative; margin: 0 auto;">

                                <form id="searchForm" action="<?= base_url('/transaksi') ?>" method="get" style="margin: 0;">
                                    <input
                                        type="text"
                                        id="globalSearchInput"
                                        name="keyword"
                                        class="form-control"
                                        placeholder="Cari invoice / no order / pelanggan..."
                                        value="<?= esc(service('request')->getGet('keyword') ?? '') ?>">
                                    <button type="submit" style="position: absolute; right: 5px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #0d6efd; padding: 4px 8px;">
                                        <i class="fas fa-arrow-right"></i>
                                    </button>
                                </form>
                            </div>
                            <!-- KANAN: User Info & Notifikasi -->
                            <div class="col-md-4 col-6 text-end">
                                <div class="d-flex justify-content-end align-items-center gap-2 gap-md-3">

                                    <!-- Skala Ukuran -->
                                    <a href="<?= base_url('/ukuran') ?>" class="nav-icon text-decoration-none"
                                        style="color: #495057; font-size: 1.1rem;"
                                        data-bs-toggle="tooltip" data-bs-placement="bottom" title="Skala Ukuran">
                                        <i class="fas fa-ruler-combined"></i>
                                    </a>

                                    <!-- Jadwal Karyawan (admin -> /jadwal, kasir -> /roster) -->
                                    <a href="<?= base_url(session()->get('role') === 'admin' ? '/jadwal' : '/roster') ?>"
                                        class="nav-icon text-decoration-none"
                                        style="color: #495057; font-size: 1.1rem;"
                                        data-bs-toggle="tooltip" data-bs-placement="bottom" title="Jadwal Karyawan">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>

                                    <!-- Preview Banner -->
                                    <a href="<?= base_url('/preview-banner') ?>" class="nav-icon text-decoration-none"
                                        style="color: #495057; font-size: 1.1rem;"
                                        data-bs-toggle="tooltip" data-bs-placement="bottom" title="Preview Banner">
                                        <i class="fas fa-image"></i>
                                    </a>

                                    <!-- Tagihan -->
                                    <a href="<?= base_url('/tagihan') ?>" class="nav-icon position-relative text-decoration-none" style="color: #495057; font-size: 1.1rem;">
                                        <i class="fas fa-file-invoice"></i>
                                        <span class="badge bg-danger" id="headerBadgeTagihan" style="font-size: 0.55rem; padding: 2px 6px; border-radius: 20px; position: absolute; top: -6px; right: -8px; display: none;">0</span>
                                    </a>

                                    <!-- User Dropdown -->
                                    <?php
                                    // Sengaja query langsung di sini (bukan disimpan ke
                                    // session) supaya foto profil selalu up-to-date begitu
                                    // diganti/dihapus, tanpa menyimpan salinan data profil
                                    // di session (lihat docs/aturan-bisnis-AULIA.md
                                    // Section 24). Query kecil (by primary key), dampak
                                    // performa minimal untuk aplikasi seukuran ini.
                                    $__headerFoto = null;
                                    if (session()->get('id_user')) {
                                        $__headerFoto = (new \App\Models\UserModel())
                                            ->select('profile_photo')
                                            ->find(session()->get('id_user'))['profile_photo'] ?? null;
                                    }
                                    ?>
                                    <div class="dropdown">
                                        <a href="#" class="text-decoration-none text-dark d-flex align-items-center" data-bs-toggle="dropdown">
                                            <?php if ($__headerFoto): ?>
                                                <img src="<?= base_url('/foto-profil/' . $__headerFoto) ?>" alt="Foto profil"
                                                    class="user-avatar" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                            <?php else: ?>
                                                <span class="user-avatar" style="width: 32px; height: 32px; border-radius: 50%; background: #0d6efd; color: white; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem;">
                                                    <?= strtoupper(substr(session()->get('username') ?? 'A', 0, 1)) ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="ms-2 d-none d-md-inline">
                                                <span class="fw-semibold" style="font-size: 0.85rem;"><?= session()->get('username') ?? 'Admin' ?></span>
                                                <small class="d-block text-muted" style="font-size: 0.55rem; margin-top: -2px;">
                                                    <?= session()->get('role') == 'admin' ? 'Administrator' : 'Kasir' ?>
                                                </small>
                                            </span>
                                            <i class="fas fa-chevron-down ms-1 text-muted" style="font-size: 0.6rem;"></i>
                                        </a>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="<?= base_url('/profil') ?>"><i class="fas fa-id-card me-2"></i> Profil Saya</a></li>
                                            <li><a class="dropdown-item" href="<?= base_url('/ganti-password') ?>"><i class="fas fa-key me-2"></i> Ganti Password</a></li>
                                            <?php if (session()->get('role') == 'admin'): ?>
                                                <li><a class="dropdown-item" href="<?= base_url('/user-management') ?>"><i class="fas fa-users-cog me-2"></i> Manajemen User</a></li>
                                            <?php endif; ?>
                                            <li>
                                                <hr class="dropdown-divider">
                                            </li>
                                            <li><a class="dropdown-item text-danger" href="<?= base_url('/logout') ?>"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </header>
                <?php if (session()->getFlashdata('success')): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            showToast(<?= json_encode(session()->getFlashdata('success')) ?>, 'success');
                        });
                    </script>
                <?php endif; ?>
                <?php if (session()->getFlashdata('error')): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            showToast(<?= json_encode(session()->getFlashdata('error')) ?>, 'danger');
                        });
                    </script>
                <?php endif; ?>
                <!-- 🔥 INI BAGIAN PENTING: Konten halaman di-include dari variabel $content -->
                <?= $this->include($content ?? 'kasir/index') ?>

            </main>
            <!-- ========================================== -->
            <!-- CONTAINER NOTIFIKASI TOAST (BAWAH KANAN)   -->
            <!-- ========================================== -->
            <div id="toastContainer" class="position-fixed p-3" style="z-index: 9999; top: 0; left: 50%; transform: translateX(-50%);">
                <div id="liveToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body" id="toastMessage">
                            <!-- Pesan akan diisi oleh JavaScript -->
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- MODAL KONFIRMASI (pengganti confirm() bawaan browser) -->
            <!-- ========================================== -->
            <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="confirmModalTitle">Konfirmasi</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="confirmModalBody" style="white-space: pre-line;">
                            Apakah kamu yakin?
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" id="confirmModalCancelBtn" data-bs-dismiss="modal">Batal</button>
                            <button type="button" class="btn btn-danger" id="confirmModalOkBtn">Ya, Lanjutkan</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>


    <!-- 2. DATATABLES CORE -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

    <!-- 3. DATATABLES BUTTONS CORE -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <!-- 4. DEPENDENCIES -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

    <!-- 5. DATATABLES BOOTSTRAP 5 -->
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>


    <script>
        $(document).ready(function() {
            updateBadgeTagihan();
        });

        // ==========================================
        // JAMIN TOAST SELALU RELATIF KE VIEWPORT
        // ==========================================
        // `position: fixed` seharusnya SELALU relatif ke layar penuh,
        // KECUALI salah satu ancestor-nya (parent, atau parent dari
        // parent, dst) punya CSS transform/filter/perspective/
        // will-change -- itu bikin browser bikin "containing block"
        // baru cuma seukuran ancestor tsb, bukan seukuran layar.
        //
        // Sudah dicek: tidak ada satu pun aturan begitu di CSS
        // custom aplikasi ini. Tapi untuk jaga-jaga terhadap CSS dari
        // library pihak ketiga (Bootstrap/DataTables/dll) yang bisa
        // berubah sewaktu-waktu tanpa kita sadari, container toast
        // dipindah jadi ANAK LANGSUNG <body> di sini -- posisi paling
        // "aman" yang mustahil punya ancestor bermasalah di antara
        // dirinya dan <body>.
        (function() {
            const toastContainer = document.getElementById('toastContainer');
            if (toastContainer && toastContainer.parentElement !== document.body) {
                document.body.appendChild(toastContainer);
            }
        })();

        // ==========================================
        // AKTIFKAN TOOLTIP BOOTSTRAP
        // ==========================================
        // Bootstrap 5 TIDAK otomatis mengaktifkan tooltip, harus
        // di-init manual. Dipakai oleh tombol ikon header (Skala
        // Ukuran, Jadwal Karyawan, Preview Banner) yang sengaja
        // hanya berupa ikon -- nama fiturnya muncul sebagai tooltip
        // saat di-hover.
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
            new bootstrap.Tooltip(el);
        });

        // ==========================================
        // SHOW TOAST NOTIFICATION
        // ==========================================
        // opsi.onClick (opsional): kalau diisi, toast body jadi bisa
        // diklik dan memanggil fungsi tsb. Dipakai misalnya oleh
        // reminder tagihan untuk membuka halaman Tagihan saat toast
        // diklik.
        function showToast(message, type = 'success', opsi = {}) {
            const toastEl = document.getElementById('liveToast');
            const toastBody = document.getElementById('toastMessage');

            toastEl.className = 'toast align-items-center border-0';

            // Durasi berbeda per tipe -- sukses/info singkat (tidak
            // mengganggu ritme kerja), warning sedikit lebih lama,
            // error/danger STICKY (tidak auto-hilang, harus di-close
            // manual) karena harus benar-benar dibaca.
            let delay = 3000;
            let autohide = true;

            if (type === 'success') {
                toastEl.classList.add('toast-success');
                delay = 3000;
            } else if (type === 'error' || type === 'danger') {
                toastEl.classList.add('toast-danger');
                autohide = false;
            } else if (type === 'warning') {
                toastEl.classList.add('toast-warning');
                delay = 4000;
            } else {
                toastEl.classList.add('toast-info');
                delay = 3000;
            }

            toastBody.innerHTML = message;

            // Reset handler klik dari pemanggilan sebelumnya supaya
            // tidak menumpuk / salah nyantol ke toast berikutnya.
            if (typeof opsi.onClick === 'function') {
                toastBody.style.cursor = 'pointer';
                toastBody.onclick = opsi.onClick;
            } else {
                toastBody.style.cursor = 'default';
                toastBody.onclick = null;
            }

            const toast = new bootstrap.Toast(toastEl, {
                delay: delay,
                autohide: autohide,
                animation: true
            });
            toast.show();
        }

        function showNotifikasi(title, message, type = 'success') {
            const fullMessage = `<strong>${title}</strong><br>${message}`;
            showToast(fullMessage, type);
        }

        // ==========================================
        // MODAL KONFIRMASI (pengganti confirm() bawaan browser)
        // ==========================================
        // Dipakai sebagai: konfirmasi('Yakin ingin menghapus ini?').then(ok => { if (ok) { ... } })
        // atau di dalam async function: if (!(await konfirmasi('Yakin?'))) return;
        //
        // Opsi tambahan (semua opsional):
        //   konfirmasi(pesan, { title: 'Judul', okText: 'Ya, Hapus', okClass: 'btn-danger', cancelText: 'Tutup' })
        function konfirmasi(pesan, opsi = {}) {
            return new Promise((resolve) => {
                const modalEl = document.getElementById('confirmModal');
                const titleEl = document.getElementById('confirmModalTitle');
                const bodyEl = document.getElementById('confirmModalBody');
                const okBtn = document.getElementById('confirmModalOkBtn');
                const cancelBtn = document.getElementById('confirmModalCancelBtn');

                titleEl.textContent = opsi.title || 'Konfirmasi';
                bodyEl.textContent = pesan;

                okBtn.className = 'btn ' + (opsi.okClass || 'btn-danger');
                okBtn.textContent = opsi.okText || 'Ya, Lanjutkan';
                // cancelText opsional -- default tetap "Batal" supaya semua
                // pemanggil existing (konfirmasi hapus/batalkan, dst) tidak
                // berubah tampilannya sama sekali. Tombol ini tidak perlu
                // di-clone seperti okBtn karena tidak pernah dipasangi
                // listener JS baru -- cukup mengandalkan `data-bs-dismiss`
                // bawaan Bootstrap di markup-nya.
                cancelBtn.textContent = opsi.cancelText || 'Batal';

                const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

                // Bersihkan handler lama supaya tidak menumpuk kalau
                // konfirmasi() dipanggil berkali-kali (mis. hapus item
                // berulang di halaman yang sama).
                const okBtnBaru = okBtn.cloneNode(true);
                okBtn.parentNode.replaceChild(okBtnBaru, okBtn);

                let sudahDijawab = false;

                okBtnBaru.addEventListener('click', () => {
                    sudahDijawab = true;
                    modal.hide();
                    resolve(true);
                });

                // Kalau modal ditutup tanpa klik tombol Ya (klik Batal,
                // tombol X, klik backdrop, atau tombol Esc), anggap batal.
                modalEl.addEventListener('hidden.bs.modal', function onHidden() {
                    modalEl.removeEventListener('hidden.bs.modal', onHidden);
                    if (!sudahDijawab) {
                        resolve(false);
                    }
                });

                modal.show();
            });
        }

        // Auto-intercept link dengan atribut data-confirm-message, supaya
        // markup existing yang dulu pakai onclick="return confirm('...')"
        // bisa diganti cukup dengan atribut data-confirm-message,
        // tanpa perlu tulis ulang JS di tiap halaman.
        //
        // Contoh: <a href="/hapus/1" data-confirm-message="Yakin ingin menghapus ini?">Hapus</a>
        document.addEventListener('click', function(e) {
            const el = e.target.closest('[data-confirm-message]');
            if (!el || el.tagName !== 'A') return;

            e.preventDefault();
            const pesan = el.getAttribute('data-confirm-message');
            const okText = el.getAttribute('data-confirm-ok-text') || undefined;

            konfirmasi(pesan, { okText }).then((ok) => {
                if (ok) {
                    window.location.href = el.getAttribute('href');
                }
            });
        });

        // Auto-intercept <form data-confirm-message="..."> — sama seperti
        // di atas tapi untuk form yang dulu pakai
        // onsubmit="return confirm('...')".
        document.addEventListener('submit', function(e) {
            const form = e.target.closest('form[data-confirm-message]');
            if (!form) return;

            // Setelah dikonfirmasi, submit dipanggil ulang secara
            // terprogram (lihat bawah) -- flag ini mencegah loop
            // interceptor menangkap submit yang kedua kalinya.
            if (form.dataset.confirmed === '1') return;

            e.preventDefault();
            const pesan = form.getAttribute('data-confirm-message');
            const okText = form.getAttribute('data-confirm-ok-text') || undefined;

            konfirmasi(pesan, { okText }).then((ok) => {
                if (ok) {
                    form.dataset.confirmed = '1';
                    form.submit();
                }
            });
        });

        // ==========================================
        // UPDATE BADGE TAGIHAN
        // ==========================================
        function updateBadgeTagihan() {
            $.ajax({
                url: '<?= base_url('/api/get-jumlah-tagihan') ?>',
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        const badge = document.getElementById('badgeTagihan');
                        if (badge) {
                            const jumlah = response.jumlah || 0;
                            badge.textContent = jumlah;

                            if (jumlah > 0) {
                                badge.className = 'badge bg-danger text-white float-end';
                            } else {
                                badge.className = 'badge bg-success text-white float-end';
                            }
                        }
                    }
                },
                error: function() {
                    console.error('Gagal update badge tagihan');
                }
            });
        }

        // 🔥 Update badge setiap 30 detik
        setInterval(updateBadgeTagihan, 30000);

        // ==========================================
        // STATUS JADWAL SAYA (khusus role KASIR)
        // ==========================================
        //
        // Murni notifikasi informasi (bukan attendance, bukan
        // authorization) -- lihat docs fitur Jadwal Kasir. Interval
        // dan pola AJAX sengaja sama persis dengan updateBadgeTagihan
        // di atas, reuse showToast() yang sudah ada, tidak ada
        // framework notifikasi baru.
        <?php if (session()->get('role') === 'kasir'): ?>
            // Disimpan di sessionStorage (bukan variabel biasa) supaya
            // nilainya BERTAHAN saat pindah halaman (setiap navigasi =
            // full page reload, variabel JS biasa akan ke-reset jadi
            // null lagi -- itu yang bikin toast ini muncul terus tiap
            // ganti halaman walau statusnya sebenarnya belum berubah).
            // sessionStorage otomatis kosong lagi kalau tab/browser
            // ditutup, jadi status tetap "fresh" di sesi login baru.
            let statusJadwalTerakhir = sessionStorage.getItem('statusJadwalTerakhir');

            function cekStatusJadwalSaya() {
                $.ajax({
                    url: '<?= base_url('/roster/status-saya') ?>',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.status !== 'success') return;

                        // Hanya toast kalau status BERUBAH dari pengecekan
                        // sebelumnya (termasuk pengecekan pertama saat
                        // halaman dibuka) -- supaya tidak spam toast
                        // setiap interval.
                        if (response.jadwal_status !== statusJadwalTerakhir) {
                            statusJadwalTerakhir = response.jadwal_status;
                            sessionStorage.setItem('statusJadwalTerakhir', response.jadwal_status);
                            showToast(response.pesan, response.toast);
                        }
                    },
                    error: function() {
                        console.error('Gagal cek status jadwal.');
                    }
                });
            }

            cekStatusJadwalSaya();
            setInterval(cekStatusJadwalSaya, 60000);
        <?php endif; ?>
        // ==========================================
        // UPDATE JAM REAL-TIME
        // ==========================================


        let searchTimeout;

        // ==========================================
        // SEARCH GLOBAL - LANGSUNG KE TRANSAKSI
        // ==========================================

        // 🔥 Enter key di search box langsung submit form
        document.getElementById('globalSearchInput')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('searchForm').submit();
            }
        });



        // Format No Order (mirip dengan format di PHP)
        function formatNoOrder(noOrder) {
            if (!noOrder) return '-';
            const ambang = 100000;
            const siklus = 9999;
            if (noOrder <= ambang) return String(noOrder);
            const posisi = noOrder - ambang;
            const indexSiklus = Math.floor((posisi - 1) / siklus);
            const nomorDalamSiklus = ((posisi - 1) % siklus) + 1;
            let huruf = '';
            let num = indexSiklus + 1;
            while (num > 0) {
                num--;
                huruf = String.fromCharCode(65 + (num % 26)) + huruf;
                num = Math.floor(num / 26);
            }
            const panjangDigit = String(siklus).length;
            return huruf + String(nomorDalamSiklus).padStart(panjangDigit, '0');
        }

        function parseNoOrder(formatted) {
            const ambang = 100000;
            const siklus = 9999;

            formatted = formatted.trim().toUpperCase();

            // 🔥 Jika input adalah angka murni
            if (/^\d+$/.test(formatted)) {
                return parseInt(formatted);
            }

            // 🔥 Jika input adalah format huruf + angka (contoh: A4295)
            const match = formatted.match(/^([A-Z]+)(\d+)$/);
            if (!match) return null;

            const hurufStr = match[1];
            const angkaStr = match[2];
            const nomorDalamSiklus = parseInt(angkaStr);

            // Konversi huruf ke angka (A=1, B=2, ...)
            let indexSiklus = 0;
            for (let char of hurufStr) {
                indexSiklus = indexSiklus * 26 + (char.charCodeAt(0) - 64);
            }
            indexSiklus -= 1; // Karena A = 0

            const posisi = (indexSiklus * siklus) + nomorDalamSiklus;
            return ambang + posisi;
        }
    </script>

    <!-- Script tambahan dari halaman (opsional) -->
    <!-- ========================================== -->
    <!-- SCRIPTS (JS) - URUTAN PALING AMAN          -->
    <!-- ========================================== -->

    <!-- 1. JQUERY & BOOTSTRAP -->



    <!-- 2. DATATABLES CORE + BOOTSTRAP INTEGRATION -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <!-- 3. DATATABLES BUTTONS CORE -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <!-- 4. BUTTONS BOOTSTRAP 5 INTEGRATION -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>

    <!-- 5. DEPENDENCIES (JSZip, PDFMake) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

    <?= $this->renderSection('scripts') ?>


    <!-- 6. BUTTONS BOOTSTRAP 5 -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>

    <!-- 6. RESPONSIVE (TERAKHIR) -->
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
</body>

</html>