<?php
// app/Helpers/order_helper.php

if (!function_exists('format_no_order')) {
    /**
     * Mengubah no_order integer (naik terus) menjadi format siklus huruf,
     * dijangkarkan mulai tepat dari $ambang supaya nomor terasa intuitif
     * (langsung mencerminkan selisih dari titik ambang), bukan melanjutkan
     * hitungan siklus dari 1 (yang menyebabkan offset ganjil).
     *
     * Contoh dengan ambang=100000, siklus=9999:
     *   100000 dan di bawahnya -> ditampilkan apa adanya (tanpa huruf)
     *   100001 -> A0001
     *   100018 -> A0018
     *   109999 -> A9999
     *   110000 -> B0001
     *
     * @param int $no_order Nomor order asli dari database (integer, terus naik)
     * @param int $siklus Jumlah nomor per siklus sebelum ganti huruf (default 9999)
     * @param int $ambang Batas bawah; di bawah/sama dengan ini tidak dikonversi (default 100000)
     * @return string Format tampilan, misal "A0018"
     */
    function format_no_order(int $no_order, int $siklus = 9999, int $ambang = 100000): string
    {
        if ($no_order < 1) {
            return (string) $no_order; // jaga-jaga kalau ada nilai tidak wajar
        }

        // Transaksi lama (<= ambang) ditampilkan apa adanya, tanpa konversi huruf
        if ($no_order <= $ambang) {
            return (string) $no_order;
        }

        $posisi = $no_order - $ambang; // 1, 2, 3, ... dihitung ulang dari titik ambang

        $indexSiklus = intdiv($posisi - 1, $siklus);       // 0, 1, 2, ...
        $nomorDalamSiklus = (($posisi - 1) % $siklus) + 1; // 1 s/d $siklus

        $huruf = angka_ke_huruf($indexSiklus);
        $panjangDigit = strlen((string) $siklus); // otomatis menyesuaikan lebar padding

        return $huruf . str_pad((string) $nomorDalamSiklus, $panjangDigit, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('angka_ke_huruf')) {
    /**
     * Konversi angka index (0-based) menjadi huruf ala penomoran kolom Excel.
     * 0 -> A, 1 -> B, ..., 25 -> Z, 26 -> AA, 27 -> AB, dst.
     *
     * @param int $num Index siklus, dimulai dari 0
     * @return string
     */
    function angka_ke_huruf(int $num): string
    {
        $num++; // konversi ke basis 1
        $huruf = '';
        while ($num > 0) {
            $num--;
            $huruf = chr(65 + ($num % 26)) . $huruf;
            $num = intdiv($num, 26);
        }
        return $huruf;
    }
}

if (!function_exists('status_pembayaran_badge_class')) {
    /**
     * Kelas warna Bootstrap untuk badge status_pembayaran.
     * Presentation-only -- business rule (total, dibayar) -> status ada
     * di App\Services\KalkulasiStatusPembayaran, bukan di sini.
     *
     * Status tak dikenal -> 'secondary' (persis perilaku existing di
     * transaksi/index.php, transaksi/detail.php, tagihan/index.php yang
     * memakai map + `?? 'secondary'`).
     */
    function status_pembayaran_badge_class(string $status): string
    {
        return [
            'belum_bayar' => 'danger',
            'dp'          => 'warning',
            'lunas'       => 'success',
        ][$status] ?? 'secondary';
    }
}

if (!function_exists('status_pembayaran_label')) {
    /**
     * Label tampilan status_pembayaran: underscore jadi spasi, huruf
     * besar (`strtoupper(str_replace('_', ' ', $status))`), KECUALI
     * 'belum_bayar' yang disingkat 'BM' atas permintaan. Escaping tetap
     * tanggung jawab view.
     */
    function status_pembayaran_label(string $status): string
    {
        if ($status === 'belum_bayar') {
            return 'BM';
        }

        return strtoupper(str_replace('_', ' ', $status));
    }
}

if (!function_exists('status_transaksi_badge_class')) {
    /**
     * Kelas warna Bootstrap untuk badge status transaksi
     * (proses/selesai/batal/mangkrak). Presentation-only.
     *
     * Daftar status domain yang valid tetap milik
     * App\Models\TransaksiModel::STATUS -- map di sini SENGAJA eksplisit
     * (bukan turunan constant itu) karena warna adalah metadata tampilan,
     * bukan bagian dari daftar status.
     *
     * Status tak dikenal -> 'secondary' (mengikuti perilaku existing di
     * transaksi/index.php; transaksi/detail.php sebelumnya memakai
     * 'success' sebagai fallback untuk status non-domain -- disatukan ke
     * 'secondary' sebagai keputusan presentation yang disengaja).
     */
    function status_transaksi_badge_class(string $status): string
    {
        return [
            'proses'   => 'warning',
            'selesai'  => 'primary',
            'batal'    => 'secondary',
            'mangkrak' => 'dark',
        ][$status] ?? 'secondary';
    }
}

if (!function_exists('status_transaksi_label')) {
    /**
     * Label tampilan status transaksi. Empat status domain memakai map
     * Title Case eksplisit (seperti di transaksi/index.php); status tak
     * dikenal jatuh ke `strtoupper(str_replace('_', ' ', $status))`.
     *
     * Catatan: transaksi/detail.php sebelumnya selalu `strtoupper($status)`
     * (mis. "PROSES"); setelah refactor ikut memakai bentuk Title Case
     * ("Proses") -- perubahan presentation yang disengaja demi konsistensi
     * dengan daftar transaksi. Escaping tetap tanggung jawab view.
     */
    function status_transaksi_label(string $status): string
    {
        return [
            'proses'   => 'Proses',
            'selesai'  => 'Selesai',
            'batal'    => 'Batal',
            'mangkrak' => 'Mangkrak',
        ][$status] ?? strtoupper(str_replace('_', ' ', $status));
    }
}

if (!function_exists('tanggal_singkat')) {
    /**
     * Tanggal singkat Indonesia untuk tampilan: "29 Sep 2026" (format
     * `d M Y` dengan nama bulan 3 huruf). Presentation-only, pure --
     * tidak menyentuh DB/session/request, tidak mengubah timezone
     * (memakai default `date()` yang sama dengan pemakaian inline
     * sebelumnya di transaksi/index.php & tagihan/index.php).
     *
     * Menerima datetime/date string (di-`strtotime()` seperti kode lama)
     * atau Unix timestamp integer (dipakai apa adanya).
     *
     * Nama bulan SELALU 3 huruf:
     *   Jan Feb Mar Apr Mei Jun Jul Agt Sep Okt Nov Des
     */
    function tanggal_singkat(string|int $tanggal): string
    {
        static $bulan = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];

        $ts = is_int($tanggal) ? $tanggal : (int) strtotime($tanggal);

        return date('d', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }
}

if (!function_exists('parse_no_order')) {
    /**
     * Kebalikan dari format_no_order(): mengubah "B0001" kembali ke integer asli.
     * Berguna kalau suatu saat perlu pencarian transaksi berdasarkan format tampilan.
     *
     * @param string $formatted Contoh: "B0001"
     * @param int $siklus Harus sama dengan yang dipakai saat format_no_order()
     * @return int|null null jika format tidak valid
     */
    /**
     * Kebalikan dari format_no_order(): mengubah "A0018" kembali ke integer asli.
     * Berguna kalau suatu saat perlu pencarian transaksi berdasarkan format tampilan.
     *
     * @param string $formatted Contoh: "A0018" atau angka polos "71103"
     * @param int $siklus Harus sama dengan yang dipakai saat format_no_order()
     * @param int $ambang Harus sama dengan yang dipakai saat format_no_order()
     * @return int|null null jika format tidak valid
     */
    function parse_no_order(string $formatted, int $siklus = 9999, int $ambang = 100000): ?int
    {
        $formatted = trim($formatted);

        // Kalau berupa angka polos saja (transaksi lama, tidak diformat), kembalikan langsung
        if (ctype_digit($formatted)) {
            return (int) $formatted;
        }

        if (!preg_match('/^([A-Z]+)(\d+)$/', strtoupper($formatted), $m)) {
            return null;
        }

        [$full, $hurufStr, $angkaStr] = $m;
        $nomorDalamSiklus = (int) $angkaStr;

        // Konversi huruf (basis 26) kembali ke index siklus (0-based)
        $indexSiklus = 0;
        foreach (str_split($hurufStr) as $char) {
            $indexSiklus = $indexSiklus * 26 + (ord($char) - 64); // A=1, B=2, ...
        }
        $indexSiklus -= 1; // balik ke 0-based

        $posisi = ($indexSiklus * $siklus) + $nomorDalamSiklus;

        return $ambang + $posisi;
    }
}
