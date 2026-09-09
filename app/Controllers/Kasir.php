<?php

namespace App\Controllers;

use App\Models\ProdukModel;
use App\Models\TransaksiModel;
use App\Models\KategoriModel;
use App\Models\PelangganModel;

class Kasir extends BaseController
{
    public function index()
    {



        $produkModel = new ProdukModel();
        $transaksiModel = new TransaksiModel();
        $kategoriModel = new KategoriModel();
        $pelangganModel = new PelangganModel();


        // 🔥 AMBIL RECOMMENDED NO ORDER
        $recommendedNoOrder = $this->getRecommendedNoOrder();

        // 🔥 AMBIL DAFTAR NO ORDER YANG TERSEDIA (UNTUK DROPDOWN)
        $availableNoOrders = $this->getAvailableNoOrders($recommendedNoOrder);

        $produkFoto = $produkModel->where('kategori_id', 16)
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->findAll();

        // 🔥 AMBIL SEMUA KATEGORI UNTUK DROPDOWN MANUAL INPUT
        $semuaKategori = $kategoriModel->orderBy('nama', 'ASC')->findAll();

        // 🔥 AMBIL SEMUA PRODUK AKTIF SEKALI SAJA, diurutkan nama A -> Z.
        // Filter kategori dan pencarian dilakukan di browser (JavaScript) dan
        // mempertahankan urutan array ini, jadi "Semua"/filter kategori/hasil
        // search semuanya ikut alfabetis. Popularitas tidak lagi dipakai untuk
        // urutan produk kasir.
        $produk = $produkModel->getProdukAktif();

        $reminderTagihan = $this->getReminderTagihanSaya();

        $data = [
            'title'    => 'Kasir | AULIA',
            'content'  => 'kasir/index',
            'produk' => $produk,
            'tagihan'  => $transaksiModel->where('status_pembayaran !=', 'lunas')
                ->whereNotIn('status', ['batal', 'mangkrak'])
                ->orderBy('tanggal', 'DESC')
                ->limit(10)
                ->findAll(),
            'kategori' => $kategoriModel->where('parent_id IS NULL')->findAll(),
            'pelanggan' => $pelangganModel->orderBy('nama', 'ASC')->findAll(),
            'produkFoto' => $produkFoto,
            'semuaKategori' => $semuaKategori,
            'recommended_no_order' => $recommendedNoOrder,
            'available_no_orders' => $availableNoOrders,
            'reminderTagihan' => $reminderTagihan,
        ];

        return view('layout/main', $data);
    }

    /**
     * Reminder tagihan (belum lunas) 3 hari terakhir milik kasir yang
     * sedang login (kasir_id = dirinya sendiri). Dicek setiap kali
     * halaman /kasir dibuka -- lihat docs/aturan-bisnis-AULIA.md
     * Section 25 untuk aturan lengkapnya.
     *
     * Dibatasi jeda 15 menit (disimpan di session) supaya tidak
     * muncul berulang tiap kasir bolak-balik buka halaman ini di
     * antara transaksi.
     *
     * @return array{show: bool, count: int}
     */
    private function getReminderTagihanSaya(): array
    {
        $userId = (int) (session()->get('id_user') ?? 0);

        if (!$userId) {
            return ['show' => false, 'count' => 0];
        }

        $lastShown = session()->get('reminder_tagihan_last_shown');
        $now = time();

        // Belum waktunya cek lagi (masih dalam jeda 15 menit sejak
        // terakhir kali toast ini ditampilkan).
        if ($lastShown && ($now - (int) $lastShown) < 900) {
            return ['show' => false, 'count' => 0];
        }

        $transaksiModel = new TransaksiModel();
        $count = $transaksiModel
            ->where('kasir_id', $userId)
            ->whereIn('status_pembayaran', ['belum_bayar', 'dp'])
            ->whereNotIn('status', ['batal', 'mangkrak'])
            ->where('tanggal >=', date('Y-m-d H:i:s', strtotime('-3 days')))
            ->countAllResults();

        if ($count > 0) {
            // Timestamp hanya di-update kalau kita BENAR-BENAR
            // menampilkan toast-nya, supaya kalau saat ini belum ada
            // tagihan (count 0), begitu muncul tagihan baru nanti
            // tetap langsung diberitahu tanpa harus nunggu jeda 15
            // menit yang tidak relevan.
            session()->set('reminder_tagihan_last_shown', $now);
        }

        return ['show' => $count > 0, 'count' => $count];
    }

    /**
     * Mendapatkan recommended no order (highest today + 1)
     */
    private function getRecommendedNoOrder(): int
    {
        $transaksiModel = new TransaksiModel();
        $today = date('Y-m-d');

        // 🔥 1. Cari no_order tertinggi hari ini
        $highestToday = $transaksiModel
            ->where('tanggal >=', $today . ' 00:00:00')
            ->where('tanggal <=', $today . ' 23:59:59')
            ->where('no_order IS NOT NULL')
            ->where('no_order >', 0)
            ->orderBy('no_order', 'DESC')
            ->first();

        if ($highestToday && !empty($highestToday['no_order'])) {
            $result = (int)$highestToday['no_order'] + 1;

            return $result;
        }

        // 🔥 2. Tidak ada hari ini → cari overall
        $highestOverall = $transaksiModel->where('no_order IS NOT NULL')
            ->where('no_order >', 0)
            ->orderBy('no_order', 'DESC')
            ->first();

        if ($highestOverall && !empty($highestOverall['no_order'])) {
            $result = (int)$highestOverall['no_order'] + 1;

            return $result;
        }

        // 🔥 3. Tidak ada data sama sekali

        return 1;
    }

    /**
     * Mendapatkan daftar no_order yang tersedia (untuk dropdown)
     * Range: recommended - 50 sampai recommended + 10
     */
    private function getAvailableNoOrders(int $recommended): array
    {
        $transaksiModel = new TransaksiModel();
        $range = 50;
        $min = max(1, $recommended - $range);
        $max = $recommended + 10;

        // Ambil semua no_order yang sudah terpakai dalam range
        $usedOrders = $transaksiModel->select('no_order')
            ->where('no_order >=', $min)
            ->where('no_order <=', $max)
            ->findAll();

        $usedArray = array_map('intval', array_column($usedOrders, 'no_order'));

        // Buat daftar semua nomor dalam range
        $available = [];
        for ($i = $min; $i <= $max; $i++) {
            if (!in_array($i, $usedArray, true)) {
                $available[] = $i;
            }
        }

        return $available;
    }
}
