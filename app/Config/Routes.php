<?php

namespace Config;

$routes = Services::routes();

// Default routes
if (is_file(SYSTEMPATH . 'Config/Routes.php')) {
    require SYSTEMPATH . 'Config/Routes.php';
}

$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Kasir');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();

// ==========================================
// ROUTE AUTH (LOGIN/LOGOUT) - TIDAK PERLU AUTH
// ==========================================

$routes->get('/login', 'Auth::login');
$routes->post('/auth/proses-login', 'Auth::prosesLogin');
$routes->get('/logout', 'Auth::logout');

// ==========================================
// ROUTE GATEWAY WHATSAPP INBOX (MACHINE-TO-MACHINE, BEARER TOKEN)
// ==========================================
// Dipanggil oleh Gateway Node.js/Baileys terpisah di LAN, BUKAN dari
// browser kasir -- sengaja dikecualikan dari filter session 'auth'
// (lihat app/Config/Filters.php), diproteksi filter 'gatewaytoken'
// sendiri. Lihat app/Controllers/InboxGatewayApi.php.
$routes->post('/api/inbox/gateway/messages', 'InboxGatewayApi::messages', ['filter' => 'gatewaytoken']);
$routes->post('/api/inbox/gateway/status', 'InboxGatewayApi::status', ['filter' => 'gatewaytoken']);

// Endpoint browser POS (kasir/admin, session-authenticated) untuk
// kirim balasan text -- Phase 3. UI utama + polling -- Phase 4.
$routes->get('/inbox', 'Inbox::index', ['filter' => 'auth']);
$routes->get('/inbox/api/conversations', 'Inbox::apiConversations', ['filter' => 'auth']);
$routes->get('/inbox/api/conversations/(:num)/messages', 'Inbox::apiMessages/$1', ['filter' => 'auth']);
$routes->get('/inbox/api/gateway-status', 'Inbox::apiGatewayStatus', ['filter' => 'auth']);
$routes->get('/inbox/media/(:num)', 'Inbox::media/$1', ['filter' => 'auth']);
$routes->get('/inbox/test', 'Inbox::testPage', ['filter' => 'auth']);
$routes->post('/inbox/kirim', 'Inbox::kirim', ['filter' => 'auth']);
$routes->post('/inbox/kirim-media', 'Inbox::kirimMedia', ['filter' => 'auth']);
$routes->post('/inbox/percakapan/(:num)/hapus', 'Inbox::hapusPercakapan/$1', ['filter' => 'auth']);
$routes->post('/inbox/mulai-percakapan', 'Inbox::mulaiPercakapan', ['filter' => 'auth']);

// ==========================================
// ROUTE GANTI PASSWORD - OTOMATIS KENA AUTH
// ==========================================

$routes->get('/ganti-password', 'Auth::gantiPassword', ['filter' => 'auth']);
$routes->post('/auth/proses-ganti-password', 'Auth::prosesGantiPassword', ['filter' => 'auth']);

// ==========================================
// ROUTE PROFIL SAYA (SEMUA ROLE YANG LOGIN) - OTOMATIS KENA AUTH
// ==========================================
// Prefix 'profil' & 'foto-profil' sengaja TIDAK match salah satu
// prefix di AuthFilter::$adminRoutes, jadi bisa diakses kasir & admin.

$routes->get('/profil', 'Profil::index', ['filter' => 'auth']);
$routes->post('/profil/update', 'Profil::update', ['filter' => 'auth']);
$routes->post('/profil/foto/upload', 'Profil::uploadFoto', ['filter' => 'auth']);
$routes->post('/profil/foto/hapus', 'Profil::hapusFoto', ['filter' => 'auth']);
$routes->get('/foto-profil/(:any)', 'Profil::foto/$1', ['filter' => 'auth']);

// ==========================================
// ROUTE PREVIEW BANNER (SEMUA ROLE YANG LOGIN)
// ==========================================
$routes->get('/preview-banner', 'PreviewBanner::index', ['filter' => 'auth']);

// ==========================================
// ROUTE MIGRASI MANUAL (ADMIN-ONLY) - alternatif php spark migrate
// untuk hosting tanpa akses CLI/SSH. Lihat catatan keamanan di
// app/Controllers/MigrasiManual.php.
// ==========================================
$routes->get('/migrasi-manual', 'MigrasiManual::index', ['filter' => 'auth']);
$routes->post('/migrasi-manual/jalankan', 'MigrasiManual::jalankan', ['filter' => 'auth']);

// ==========================================
// ROUTE ARCHIVE TRANSAKSI (ADMIN-ONLY) - pindahkan transaksi lama ke
// SQLite terpisah. Lihat App\Services\TransaksiArchiveService.
// ==========================================
$routes->get('/archive-transaksi', 'ArchiveTransaksi::index', ['filter' => 'auth']);
$routes->post('/archive-transaksi/preview', 'ArchiveTransaksi::preview', ['filter' => 'auth']);
$routes->post('/archive-transaksi/jalankan', 'ArchiveTransaksi::jalankan', ['filter' => 'auth']);

// ==========================================
// ROUTE MANAJEMEN USER (HANYA ADMIN) - OTOMATIS KENA AUTH
// ==========================================

$routes->get('/user-management', 'Auth::userManagement', ['filter' => 'auth']);
$routes->get('/auth/tambah-user', 'Auth::tambahUser', ['filter' => 'auth']);
$routes->post('/auth/simpan-user', 'Auth::simpanUser', ['filter' => 'auth']);
$routes->get('/auth/edit-user/(:num)', 'Auth::editUser/$1', ['filter' => 'auth']);
$routes->post('/auth/update-user/(:num)', 'Auth::updateUser/$1', ['filter' => 'auth']);
$routes->get('/auth/hapus-user/(:num)', 'Auth::hapusUser/$1', ['filter' => 'auth']);

// ==========================================
// ROUTE UNTUK APLIKASI KASIR AULIA - OTOMATIS KENA AUTH
// ==========================================

// Halaman Utama (Kasir)
$routes->get('/', 'Kasir::index', ['filter' => 'auth']);
$routes->get('/kasir', 'Kasir::index', ['filter' => 'auth']);

$routes->post('/kasir/proses', 'Kasir::prosesTransaksi', ['filter' => 'auth']);
$routes->post('/kasir/tambah-pembayaran', 'Kasir::tambahPembayaran', ['filter' => 'auth']);

// Route Produk (CRUD)
$routes->get('/produk', 'Produk::index', ['filter' => 'auth']);
$routes->get('/produk/tambah', 'Produk::tambah', ['filter' => 'auth']);
$routes->post('/produk/simpan', 'Produk::simpan', ['filter' => 'auth']);
$routes->get('/produk/edit/(:num)', 'Produk::edit/$1', ['filter' => 'auth']);
$routes->post('/produk/update/(:num)', 'Produk::update/$1', ['filter' => 'auth']);
$routes->get('/produk/hapus/(:num)', 'Produk::hapus/$1', ['filter' => 'auth']);
$routes->post('/produk/update-inline', 'Produk::updateInline', ['filter' => 'auth']);

// Route Kategori (CRUD)
$routes->get('/kategori', 'Kategori::index', ['filter' => 'auth']);
$routes->get('/kategori/tambah', 'Kategori::tambah', ['filter' => 'auth']);
$routes->post('/kategori/simpan', 'Kategori::simpan', ['filter' => 'auth']);
$routes->get('/kategori/edit/(:num)', 'Kategori::edit/$1', ['filter' => 'auth']);
$routes->post('/kategori/update/(:num)', 'Kategori::update/$1', ['filter' => 'auth']);
$routes->get('/kategori/hapus/(:num)', 'Kategori::hapus/$1', ['filter' => 'auth']);

// Route API Transaksi

$routes->post('/api/simpan-transaksi', 'Api::simpanTransaksi', ['filter' => 'auth']);
$routes->get('/api/modal/pembayaran', 'Api::modalPembayaran', ['filter' => 'auth']);
// Selesaikan transaksi dari workflow Kasir (POS) — kapabilitas khusus
// konteks, TERPISAH dari /api/ubah-status. Enforcement di controller +
// TransaksiModel::ubahStatus (syarat lunas).
$routes->post('/api/kasir/selesaikan-transaksi', 'Api::selesaikanTransaksiKasir', ['filter' => 'auth']);



// Route Pelanggan
$routes->get('/pelanggan', 'Pelanggan::index', ['filter' => 'auth']);
$routes->get('/pelanggan/tambah', 'Pelanggan::tambah', ['filter' => 'auth']);
$routes->post('/pelanggan/simpan', 'Pelanggan::simpan', ['filter' => 'auth']);
$routes->get('/pelanggan/edit/(:num)', 'Pelanggan::edit/$1', ['filter' => 'auth']);
$routes->post('/pelanggan/update/(:num)', 'Pelanggan::update/$1', ['filter' => 'auth']);
$routes->get('/pelanggan/hapus/(:num)', 'Pelanggan::hapus/$1', ['filter' => 'auth']);

// Route Transaksi (Riwayat)
$routes->get('/transaksi', 'Transaksi::index', ['filter' => 'auth']);
$routes->get('/transaksi/detail/(:num)', 'Transaksi::detail/$1', ['filter' => 'auth']);
$routes->get('/transaksi/batal/(:num)', 'Transaksi::batal/$1', ['filter' => 'auth']);
$routes->get('/transaksi/hari-ini', 'Transaksi::hariIni', ['filter' => 'auth']);

// Route Edit Transaksi
$routes->get('/transaksi/edit/(:num)', 'Transaksi::edit/$1', ['filter' => 'auth']);
$routes->post('/transaksi/update-transaksi/(:num)', 'Transaksi::updateTransaksi/$1', ['filter' => 'auth']);

// Route Laporan
$routes->get('/laporan', 'Laporan::index', ['filter' => 'auth']);
$routes->post('/laporan/get-data', 'Laporan::getData', ['filter' => 'auth']);
$routes->post('/laporan/export-excel', 'Laporan::exportExcel', ['filter' => 'auth']);

// Route Tagihan
$routes->get('/tagihan', 'Tagihan::index', ['filter' => 'auth']);
$routes->get('/tagihan/detail/(:num)', 'Tagihan::detail/$1', ['filter' => 'auth']);
$routes->post('/tagihan/lunasi/(:num)', 'Tagihan::lunasi/$1', ['filter' => 'auth']);

// Route API
$routes->post('/api/get-sisa-tagihan', 'Api::getSisaTagihan', ['filter' => 'auth']);
$routes->get('/api/get-jumlah-tagihan', 'Api::getJumlahTagihan', ['filter' => 'auth']);





$routes->post('/api/ubah-status', 'Api::ubahStatus', ['filter' => 'auth']);
$routes->post('/api/tambah-pembayaran', 'Api::tambahPembayaran', ['filter' => 'auth']);
$routes->get('/api/kasir-list', 'Api::kasirList', ['filter' => 'auth']);

// Route API No Order
$routes->get('/api/generate-order', 'Api::generateOrder', ['filter' => 'auth']);
$routes->get('/api/get-available-no-orders', 'Api::getAvailableNoOrders', ['filter' => 'auth']);

// Route API Pelanggan
$routes->get('/api/search-pelanggan', 'Api::searchPelanggan', ['filter' => 'auth']);
$routes->post('/api/tambah-pelanggan', 'Api::tambahPelanggan', ['filter' => 'auth']);

$routes->get('/api/get-produk-cetak', 'Api::getProdukCetak', ['filter' => 'auth']);
// Route Cetak Nota (alias)
$routes->get('/cetak/nota/(:num)', 'Cetak::index/$1', ['filter' => 'auth']);
$routes->get('/cetak/nota-langsung/(:num)', 'Cetak::notaLangsung/$1', ['filter' => 'auth']);
$routes->get('/cetak/thermal/(:num)', 'Cetak::thermal/$1', ['filter' => 'auth']);
$routes->get('/cetak/ticket/(:num)', 'Cetak::ticket/$1', ['filter' => 'auth']);
// Route API untuk cari atau buat produk (Manual Input)
$routes->post('/api/cari-atau-buat-produk', 'Api::cariAtauBuatProduk', ['filter' => 'auth']);
// Route API Search Global
$routes->get('/api/search-global', 'Api::searchGlobal', ['filter' => 'auth']);
// Skala Ukuran
$routes->get('ukuran', 'Ukuran::index', ['filter' => 'auth']);
$routes->get('ukuran/getList', 'Ukuran::getList', ['filter' => 'auth']);

// Route Cash (redesign)
$routes->get('/cash', 'Cash::index', ['filter' => 'auth']);
$routes->get('/cash/get-saldo-sistem', 'Cash::getSaldoSistem', ['filter' => 'auth']);
$routes->post('/cash/simpan-opname', 'Cash::simpanOpname', ['filter' => 'auth']);
$routes->get('/cash/riwayat', 'Cash::riwayat', ['filter' => 'auth']);
$routes->get('/cash/detail/(:num)', 'Cash::detail/$1', ['filter' => 'auth']);
$routes->post('/cash/simpan-kas-awal', 'Cash::simpanKasAwal', ['filter' => 'auth']);
// Route API untuk DataTables Produk
$routes->get('/produk/get-produk-data', 'Produk::getProdukData', ['filter' => 'auth']);
// Route Kas Keluar
$routes->get('/cash/pengeluaran', 'Cash::pengeluaran', ['filter' => 'auth']);
$routes->post('/cash/tambah-pengeluaran', 'Cash::tambahPengeluaran', ['filter' => 'auth']);
// GET - mengambil data pengeluaran
$routes->get(
    '/cash/edit-pengeluaran/(:num)',
    'Cash::getPengeluaran/$1',
    ['filter' => 'auth']
);

// POST - update pengeluaran
$routes->post(
    '/cash/edit-pengeluaran/(:num)',
    'Cash::updatePengeluaran/$1',
    ['filter' => 'auth']
);
$routes->delete('/cash/hapus-pengeluaran/(:num)', 'Cash::hapusPengeluaran/$1', ['filter' => 'auth']);

$routes->get(
    '/laporan/item-harian',
    'Laporan::itemHarian'
);

$routes->get(
    '/laporan-pembayaran',
    'Laporan::pembayaran',
    ['filter' => 'auth']
);
$routes->post(
    '/api/koreksi-pembayaran',
    'Api::koreksiPembayaran',
    ['filter' => 'auth']
);
$routes->get('/produk/maintenance', 'Produk::maintenance', ['filter' => 'auth']);
$routes->get('/produk/export-audit', 'Produk::exportAudit', ['filter' => 'auth']);
$routes->post('/produk/preview-import', 'Produk::previewImport', ['filter' => 'auth']);
$routes->post('/produk/eksekusi-import', 'Produk::eksekusiImport', ['filter' => 'auth']);

// ==========================================
// ROUTE JADWAL KARYAWAN (ADMIN-ONLY, lihat AuthFilter::$adminRoutes)
// ==========================================
$routes->get('/jadwal', 'Jadwal::index', ['filter' => 'auth']);
$routes->get('/jadwal/matrix-data', 'Jadwal::matrixData', ['filter' => 'auth']);
$routes->get('/jadwal/calendar-events', 'Jadwal::calendarEvents', ['filter' => 'auth']);
$routes->get('/jadwal/analisis-data', 'Jadwal::analisisData', ['filter' => 'auth']);
$routes->get('/jadwal/karyawan-aktif', 'Jadwal::karyawanAktif', ['filter' => 'auth']);
$routes->post('/jadwal/simpan', 'Jadwal::simpan', ['filter' => 'auth']);
$routes->delete('/jadwal/hapus/(:num)', 'Jadwal::hapus/$1', ['filter' => 'auth']);
$routes->delete('/jadwal/hapus-range', 'Jadwal::hapusRange', ['filter' => 'auth']);
$routes->post('/jadwal/swap', 'Jadwal::swap', ['filter' => 'auth']);
$routes->get('/jadwal/master', 'Jadwal::master', ['filter' => 'auth']);
$routes->post('/jadwal/master/simpan-cell', 'Jadwal::masterSimpanCell', ['filter' => 'auth']);
$routes->delete('/jadwal/master/hapus-cell', 'Jadwal::masterHapusCell', ['filter' => 'auth']);
$routes->post('/jadwal/master/apply', 'Jadwal::masterApply', ['filter' => 'auth']);

// ==========================================
// ROUTE ROSTER (READ-ONLY, untuk role KASIR -- juga bisa admin)
// ==========================================
// Sengaja prefix BEDA dari /jadwal supaya TIDAK ikut ter-blok oleh
// AuthFilter::$adminRoutes (yang melindungi seluruh /jadwal/* khusus
// admin). Cukup filter 'auth' biasa (harus login, role apa saja).
$routes->get('/roster', 'Jadwal::rosterIndex', ['filter' => 'auth']);
$routes->get('/roster/matrix-data', 'Jadwal::rosterMatrixData', ['filter' => 'auth']);
$routes->get('/roster/bulan-data', 'Jadwal::rosterBulanData', ['filter' => 'auth']);
$routes->get('/roster/hari-ini', 'Jadwal::rosterHariIni', ['filter' => 'auth']);
$routes->get('/roster/status-saya', 'Jadwal::statusJadwalSaya', ['filter' => 'auth']);
