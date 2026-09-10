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
     * besar. Persis `strtoupper(str_replace('_', ' ', $status))` yang
     * sebelumnya diulang di tiga view. Tidak ada special-case untuk
     * status tak dikenal (mengikuti perilaku existing). Escaping tetap
     * tanggung jawab view.
     */
    function status_pembayaran_label(string $status): string
    {
        return strtoupper(str_replace('_', ' ', $status));
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
