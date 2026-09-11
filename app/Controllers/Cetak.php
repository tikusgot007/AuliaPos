<?php

namespace App\Controllers;

use App\Models\TransaksiModel;
use App\Models\DetailTransaksiModel;
use App\Models\PembayaranModel;
use App\Models\PelangganModel;
use Dompdf\Dompdf;
use Dompdf\Options;
use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

class Cetak extends BaseController
{
    private const THERMAL_WIDTH = 32;
    public function index($id)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $pembayaranModel = new PembayaranModel();
        $pelangganModel = new PelangganModel();

        $transaksi = $transaksiModel->select('transaksi.*, users.username as kasir_nama')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->find($id);

        if (!$transaksi) {
            return redirect()->back()->with('error', 'Transaksi tidak ditemukan.');
        }

        $detail = $detailModel->where('transaksi_id', $id)->findAll();
        $pembayaran = $pembayaranModel
            ->where('transaksi_id', $id)
            ->where('status', 'aktif')
            ->findAll();
        $pelanggan = $transaksi['pelanggan_id'] ? $pelangganModel->find($transaksi['pelanggan_id']) : null;

        $totalDibayar = array_sum(array_column($pembayaran, 'jumlah'));
        $sisa = $transaksi['grand_total'] - $totalDibayar;

        $data = [
            'transaksi' => $transaksi,
            'detail'    => $detail,
            'pelanggan' => $pelanggan,
            'total_dibayar' => $totalDibayar,
            'sisa' => $sisa,
            'title' => 'Nota Transaksi'
        ];
        return view('cetak/nota', $data);
    }

    /**
     * CETAK LANGSUNG -- server-side, tanpa dialog print browser, ke
     * printer yang SUDAH DITENTUKAN SERVER (App\Config\PrintNota),
     * BUKAN dari request browser (lihat audit keamanan di docs
     * Section 30). Reuse penuh: data & view yang SAMA dengan index()
     * ("Pilih Printer" / Nota biasa), cuma dikonversi ke PDF di server
     * (Dompdf, sudah dipakai di tempat lain di controller ini) lalu
     * dikirim lewat command-line tool -- bukan template/sumber data
     * kedua.
     *
     * Read-only murni terhadap transaksi (SELECT saja). Kalau
     * generate PDF atau kirim ke printer gagal, transaksi yang SUDAH
     * tersimpan TIDAK terpengaruh sama sekali -- endpoint ini
     * dipanggil SETELAH transaksi disimpan, tidak pernah menulis ke
     * tabel transaksi/detail_transaksi/pembayaran.
     */
    public function notaLangsung($id)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $pembayaranModel = new PembayaranModel();
        $pelangganModel = new PelangganModel();

        $transaksi = $transaksiModel->select('transaksi.*, users.username as kasir_nama')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->find($id);

        if (!$transaksi) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        $detail = $detailModel->where('transaksi_id', $id)->findAll();
        $pembayaran = $pembayaranModel
            ->where('transaksi_id', $id)
            ->where('status', 'aktif')
            ->findAll();
        $pelanggan = $transaksi['pelanggan_id'] ? $pelangganModel->find($transaksi['pelanggan_id']) : null;

        $totalDibayar = array_sum(array_column($pembayaran, 'jumlah'));
        $sisa = $transaksi['grand_total'] - $totalDibayar;

        // Data & view SAMA PERSIS dengan index() -- satu sumber
        // kebenaran tampilan nota untuk kedua mode cetak.
        //
        // logoSrc SENGAJA dibedakan dari jalur browser (index()):
        // di sana logo dimuat lewat base_url() (URL HTTP, wajar untuk
        // <img> di browser sungguhan). Dompdf di jalur INI sengaja
        // di-set isRemoteEnabled=false (keamanan), dan ternyata
        // pendekatan path/file:// juga masih bermasalah (kemungkinan
        // resolusi path Windows di dalam Dompdf) -- jadi logo di sini
        // di-EMBED LANGSUNG sebagai data:base64 di dalam HTML. Ini
        // menghilangkan SEMUA masalah resolusi path/URL sekaligus:
        // Dompdf tidak perlu "mengambil" file dari mana pun, PHP yang
        // baca file-nya sendiri (baca file lokal, bukan Dompdf yang
        // baca), datanya sudah nempel langsung di HTML sebagai teks.
        $logoPath = FCPATH . 'logo-no-background.png';
        $logoSrc = base_url('logo-no-background.png'); // fallback kalau file tidak ketemu

        if (is_file($logoPath)) {
            $logoBinary = file_get_contents($logoPath);

            if ($logoBinary !== false) {
                $logoSrc = 'data:image/png;base64,' . base64_encode($logoBinary);
            } else {
                log_message('warning', 'notaLangsung: file logo ada tapi gagal dibaca: ' . $logoPath);
            }
        } else {
            log_message('warning', 'notaLangsung: file logo tidak ditemukan di: ' . $logoPath);
        }

        $data = [
            'transaksi' => $transaksi,
            'detail'    => $detail,
            'pelanggan' => $pelanggan,
            'total_dibayar' => $totalDibayar,
            'sisa' => $sisa,
            'title' => 'Nota Transaksi',
            'logoSrc' => $logoSrc,
        ];

        try {
            $pdfBinary = $this->generateNotaPdfBinary($data);
        } catch (\Throwable $e) {
            log_message('error', 'notaLangsung: gagal generate PDF transaksi #' . $id . ': ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Gagal membuat PDF nota: ' . $e->getMessage()
            ]);
        }

        try {
            $this->kirimPdfKePrinter($pdfBinary, $transaksi['kode_invoice']);
        } catch (\Throwable $e) {
            log_message('error', 'notaLangsung: gagal cetak transaksi #' . $id . ': ' . $e->getMessage());

            return $this->response->setStatusCode(500)->setJSON([
                'status' => 'error',
                'message' => 'Nota gagal dikirim ke printer: ' . $e->getMessage()
            ]);
        }

        return $this->response->setJSON([
            'status' => 'success',
            'message' => 'Nota berhasil dikirim ke printer.'
        ]);
    }

    /**
     * Render view cetak/nota (SAMA dengan yang dipakai index()) lalu
     * konversi ke PDF A6 landscape via Dompdf -- Dompdf & Options
     * di-import di atas dipakai di sini. Ini satu-satunya generator
     * PDF nota di controller; jalur cetaknya lewat notaLangsung().
     */
    private function generateNotaPdfBinary(array $data): string
    {
        // Dompdf butuh ekstensi GD (atau Imagick) untuk memproses
        // gambar (logo PNG di nota ini termasuk) -- tanpa ini, PDF
        // tetap bisa dibuat tapi gambar gagal dirender walau data
        // base64-nya sudah benar, biasanya tanpa pesan error yang
        // jelas. Dicek eksplisit di sini supaya kalau GD ke-nonaktif
        // lagi suatu saat (mis. setelah update PHP/server), errornya
        // langsung jelas -- bukan gagal diam-diam.
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            throw new \Exception(
                'Ekstensi PHP "gd" (atau "imagick") tidak aktif -- diperlukan Dompdf ' .
                    'untuk merender gambar (logo) di PDF. Aktifkan lewat php.ini ' .
                    '(uncomment/tambahkan "extension=gd") lalu restart web server.'
            );
        }

        $html = view('cetak/nota', $data);

        $options = new Options();
        $options->set('defaultFont', 'Courier');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        // 🔥 PENTING: tanpa ini, Dompdf render pakai CSS mode "screen"
        // (default), jadi aturan `@media print { .no-print { display:
        // none; } }` yang sudah ada di cetak/nota.php (untuk sembunyikan
        // tombol "Cetak Nota"/"Tutup" saat dicetak beneran) TIDAK ikut
        // diterapkan -- tombol-tombol itu ikut tercetak di PDF. Browser
        // print (window.print(), dipakai jalur "Pilih Printer") selalu
        // otomatis pakai mode print, makanya cuma jalur "Cetak Langsung"
        // (lewat Dompdf) yang kena masalah ini. `defaultMediaType`
        // memaksa Dompdf berperilaku sama seperti browser print: pakai
        // aturan @media print, bukan @media screen.
        $options->set('defaultMediaType', 'print');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A6', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Simpan PDF ke file sementara lalu kirim ke printer lewat tool
     * command-line (default: SumatraPDF `-print-to <printer> -silent
     * <file>`, lihat App\Config\PrintNota). Printer & tool SELALU
     * dari konfigurasi server -- parameter ini TIDAK PERNAH menerima
     * input dari request/browser.
     *
     * proc_open() dipakai (bukan shell_exec/exec) supaya argumen
     * dilewatkan sebagai array literal (tidak lewat shell sama
     * sekali) dan exit code + stdout/stderr bisa ditangkap untuk
     * logging/troubleshooting, sesuai permintaan spesifikasi.
     */
    private function kirimPdfKePrinter(string $pdfBinary, string $namaFileHint): void
    {
        $config = config('PrintNota');

        if (!is_file($config->sumatraPdfPath)) {
            throw new \Exception(
                'Tool cetak tidak ditemukan di "' . $config->sumatraPdfPath . '". ' .
                'Periksa konfigurasi App\\Config\\PrintNota (bisa di-override lewat .env).'
            );
        }

        $dir = WRITEPATH . 'uploads/nota_print';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $namaAman = preg_replace('/[^A-Za-z0-9_-]/', '', $namaFileHint) ?: 'nota';
        $path = $dir . '/' . $namaAman . '_' . uniqid() . '.pdf';

        if (file_put_contents($path, $pdfBinary) === false) {
            throw new \Exception('Gagal menyimpan file PDF sementara sebelum dicetak.');
        }

        $cmd = [
            $config->sumatraPdfPath,
            '-print-to', $config->printerLangsung,
            '-print-settings', $config->printSettings,
            '-silent',
            $path,
        ];

        // Dikonfirmasi bekerja (2026-09-09) dengan target printer
        // berupa UNC path yang valid, mis. \\aan-pc\L3210 (printer
        // lokal yang di-share) atau \\AULIA-DP1\L300. Kegagalan
        // sebelumnya ("ParseFlags") ternyata bukan karena flag ini
        // tidak dikenali SumatraPDF, tapi karena path/nama printer
        // yang dicoba waktu itu belum benar -- bukan bug di flag ini.

        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            @unlink($path);
            throw new \Exception('Gagal menjalankan proses cetak (tool: ' . $config->sumatraPdfPath . ').');
        }

        fclose($pipes[0]);

        // Batas waktu sederhana: kalau proses belum selesai sampai
        // timeout, paksa hentikan supaya request AJAX tidak menggantung
        // selamanya kalau tool/printer hang.
        $mulai = time();
        $status = proc_get_status($process);

        while ($status['running'] && (time() - $mulai) < $config->timeoutDetik) {
            usleep(200000); // 0.2 detik
            $status = proc_get_status($process);
        }

        if ($status['running']) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink($path);

            throw new \Exception(
                'Proses cetak melebihi batas waktu (' . $config->timeoutDetik . ' detik) -- ' .
                'kemungkinan printer/tool tidak merespons.'
            );
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // File sementara selalu dihapus setelah proses selesai
        // (berhasil maupun gagal) -- detail error sudah di-log
        // lengkap di bawah untuk troubleshooting, filenya sendiri
        // tidak perlu dipertahankan.
        @unlink($path);

        log_message(
            'info',
            'notaLangsung print: cmd=' . implode(' ', $cmd) .
                ' | exit=' . $exitCode .
                ' | stdout=' . trim((string) $stdout) .
                ' | stderr=' . trim((string) $stderr)
        );

        if ($exitCode !== 0) {
            throw new \Exception(
                'Command cetak gagal (exit code ' . $exitCode . '): ' .
                    trim($stderr ?: $stdout ?: 'tidak ada detail dari tool cetak.')
            );
        }
    }

    /**
     * TICKET / HANDOVER -- bukan invoice/struk lengkap. Tujuannya
     * murni identifikasi transaksi saat pelanggan pindah tangan
     * antar-karyawan (lihat docs/aturan-bisnis-AULIA.md). Sengaja
     * hanya cetak info minimal (invoice paling menonjol, tanggal,
     * pelanggan) -- BUKAN daftar item/pembayaran.
     *
     * Cetak LANGSUNG ke printer thermal (server-side ESC/POS) --
     * REUSE PENUH infrastructure yang sama dengan thermal(): connector
     * SMB yang sama, class Printer yang sama, helper garisThermal()/
     * formatUang() yang sama. Bukan sistem cetak baru, cuma isi yang
     * jauh lebih ringkas. Read-only murni (SELECT saja, tidak ada
     * write ke DB) -- aman dipanggil berkali-kali/double-click.
     */
    public function ticket($id)
    {
        $transaksiModel = new TransaksiModel();
        $pelangganModel = new PelangganModel();

        $transaksi = $transaksiModel
            ->select('transaksi.*, users.username as kasir_nama')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->find($id);

        if (!$transaksi) {
            return $this->response->setStatusCode(404)->setJSON([
                'status' => 'error',
                'message' => 'Transaksi tidak ditemukan.'
            ]);
        }

        $pelanggan = $transaksi['pelanggan_id']
            ? $pelangganModel->find($transaksi['pelanggan_id'])
            : null;

        try {
            $connector = new WindowsPrintConnector("smb://guest@aulia6/POS-58");
            $printer = new Printer($connector);

            $this->cetakTicketThermal($printer, $transaksi, $pelanggan);

            $printer->close();

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Ticket berhasil dicetak.'
            ]);
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
        }
    }

    private function cetakTicketThermal(
        Printer $printer,
        array $transaksi,
        ?array $pelanggan
    ): void {
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        $printer->setEmphasis(true);
        $printer->text("AULIA POS\n");
        $printer->text("TICKET / HANDOVER\n");
        $printer->setEmphasis(false);
        $printer->feed();

        $printer->text($this->garisThermal());

        // No. Invoice -- HARUS paling menonjol (spesifikasi eksplisit
        // minta ini), jadi dibesarkan + emphasis, satu-satunya elemen
        // di ticket ini yang diperlakukan begitu.
        $printer->setTextSize(2, 2);
        $printer->setEmphasis(true);
        $printer->text(($transaksi['kode_invoice'] ?? '-') . "\n");
        $printer->setEmphasis(false);
        $printer->setTextSize(1, 1);

        $printer->text($this->garisThermal());

        $printer->setJustification(Printer::JUSTIFY_LEFT);

        if (!empty($transaksi['no_order'])) {
            $printer->text(
                $this->lineThermal('Order', (string) $transaksi['no_order']) . "\n"
            );
        }

        $printer->text(
            $this->lineThermal('Tanggal', date('d-m-Y', strtotime($transaksi['tanggal']))) . "\n"
        );

        $printer->text(
            $this->lineThermal('Pelanggan', $pelanggan['nama'] ?? '-') . "\n"
        );

        $printer->text(
            $this->lineThermal('Total', $this->formatUang($transaksi['grand_total'] ?? 0)) . "\n"
        );

        if (!empty($transaksi['kasir_nama'])) {
            $printer->text(
                $this->lineThermal('Kasir', $transaksi['kasir_nama']) . "\n"
            );
        }

        $printer->text($this->garisThermal());

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->text("Tunjukkan ticket ini untuk\n");
        $printer->text("melanjutkan transaksi\n");

        $printer->feed(2);
        $printer->cut();
    }

    // ==========================================
    // CETAK THERMAL (via server)
    // ==========================================

    public function thermal($id)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $pembayaranModel = new PembayaranModel();
        $pelangganModel = new PelangganModel();

        $transaksi = $transaksiModel
            ->select('transaksi.*, users.username as kasir_nama')
            ->join('users', 'users.id = transaksi.kasir_id', 'left')
            ->find($id);

        if (!$transaksi) {
            return redirect()->back()->with(
                'error',
                'Transaksi tidak ditemukan.'
            );
        }

        $detail = $detailModel
            ->where('transaksi_id', $id)
            ->findAll();

        $pembayaran = $pembayaranModel
            ->where('transaksi_id', $id)
            ->where('status', 'aktif')
            ->findAll();

        $pelanggan = $transaksi['pelanggan_id']
            ? $pelangganModel->find($transaksi['pelanggan_id'])
            : null;

        $totalDibayar = array_sum(
            array_column($pembayaran, 'jumlah')
        );

        $sisa = max(
            0,
            (float) $transaksi['grand_total'] - (float) $totalDibayar
        );

        try {
            $connector = new WindowsPrintConnector("smb://guest@aulia6/POS-58");
            $printer = new Printer($connector);

            $this->cetakHeaderThermal(
                $printer,
                $transaksi,
                $pelanggan
            );

            $this->cetakDetailThermal(
                $printer,
                $detail
            );

            $this->cetakRingkasanThermal(
                $printer,
                $transaksi
            );

            $this->cetakPembayaranThermal(
                $printer,
                $pembayaran,
                $totalDibayar,
                $sisa
            );

            $this->cetakFooterThermal($printer);

            $printer->close();

            return $this->response->setJSON([
                'status' => 'success',
                'message' => 'Struk berhasil dicetak.'
            ]);
        } catch (\Throwable $e) {
            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
        }
    }
    private function cetakHeaderThermal(
        Printer $printer,
        array $transaksi,
        ?array $pelanggan
    ): void {
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        $printer->setEmphasis(true);
        $printer->text("AULIA\n");
        $printer->setEmphasis(false);

        $printer->text("NOTA TRANSAKSI\n");
        $printer->feed();

        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text(
            "Invoice : " .
                ($transaksi['kode_invoice'] ?? '-') .
                "\n"
        );

        if (!empty($transaksi['no_order'])) {
            $printer->text(
                "Order   : " .
                    $transaksi['no_order'] .
                    "\n"
            );
        }
        $printer->text(
            "Tanggal : " .
                date(
                    'd-m-Y H:i',
                    strtotime($transaksi['tanggal'])
                ) .
                "\n"
        );

        $printer->text(
            "Kasir   : " .
                ($transaksi['kasir_nama'] ?? '-') .
                "\n\n"
        );

        if ($pelanggan) {
            $printer->setEmphasis(true);
            $printer->setTextSize(2, 2);

            $printer->text(
                ($pelanggan['nama'] ?? '-') .
                    "\n"
            );

            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);

            $printer->text(
                "No. Telp  : " .
                    ($pelanggan['no_hp'] ?? '-') .
                    "\n"
            );
        }

        $printer->text($this->garisThermal());
    }

    private function cetakDetailThermal(
        Printer $printer,
        array $detail
    ): void {
        foreach ($detail as $item) {

            $namaProduk = $item['nama_produk'] ?? '-';

            // Nama produk otomatis turun baris
            $namaBaris = $this->wrapThermal(
                $namaProduk,
                self::THERMAL_WIDTH
            );

            foreach ($namaBaris as $baris) {
                $printer->text($baris . "\n");
            }

            $jumlah = $item['jumlah'] ?? 0;
            $harga = (float) ($item['harga_satuan'] ?? 0);
            $subtotal = (float) ($item['subtotal'] ?? 0);

            $kiri =
                $jumlah .
                " x " .
                $this->formatUang($harga);

            $kanan = $this->formatUang($subtotal);

            $printer->text(
                $this->lineThermal($kiri, $kanan) .
                    "\n"
            );
        }

        $printer->text($this->garisThermal());
    }

    private function cetakRingkasanThermal(
        Printer $printer,
        array $transaksi
    ): void {
        $subtotal = (float) ($transaksi['subtotal'] ?? 0);
        $diskon = (float) ($transaksi['diskon'] ?? 0);
        $grandTotal = (float) ($transaksi['grand_total'] ?? 0);

        $printer->text(
            $this->lineThermal(
                'Subtotal',
                $this->formatUang($subtotal)
            ) . "\n"
        );

        if ($diskon > 0) {
            $printer->text(
                $this->lineThermal(
                    'Diskon',
                    '-' . $this->formatUang($diskon)
                ) . "\n"
            );
        }

        $printer->text($this->garisThermal());

        $printer->setEmphasis(true);

        $printer->text(
            $this->lineThermal(
                'TOTAL',
                $this->formatUang($grandTotal)
            ) . "\n"
        );

        $printer->setEmphasis(false);

        $printer->text($this->garisThermal());
    }

    private function cetakPembayaranThermal(
        Printer $printer,
        array $pembayaran,
        float $totalDibayar,
        float $sisa
    ): void {
        foreach ($pembayaran as $bayar) {
            $metode = strtoupper(
                $bayar['metode'] ?? 'PEMBAYARAN'
            );

            $jumlah = (float) ($bayar['jumlah'] ?? 0);

            $printer->text(
                $this->lineThermal(
                    $metode,
                    $this->formatUang($jumlah)
                ) . "\n"
            );
        }

        $printer->text(
            $this->lineThermal(
                'Dibayar',
                $this->formatUang($totalDibayar)
            ) . "\n"
        );

        if ($sisa > 0) {
            $printer->setEmphasis(true);

            $printer->text(
                $this->lineThermal(
                    'Sisa',
                    $this->formatUang($sisa)
                ) . "\n"
            );

            $printer->setEmphasis(false);
        }
    }

    private function cetakFooterThermal(
        Printer $printer
    ): void {
        $printer->feed(1);

        $printer->setJustification(
            Printer::JUSTIFY_CENTER
        );

        $printer->text("Terima kasih\n\n");

        $printer->feed(2);

        $printer->cut();
    }
    private function garisThermal(): string
    {
        return str_repeat('-', self::THERMAL_WIDTH) . "\n";
    }

    private function formatUang($nominal): string
    {
        return number_format(
            (float) $nominal,
            0,
            ',',
            '.'
        );
    }

    private function lineThermal(
        string $kiri,
        string $kanan,
        ?int $width = null
    ): string {
        $width = $width ?? self::THERMAL_WIDTH;

        $panjangKiri = strlen($kiri);
        $panjangKanan = strlen($kanan);

        $spasi = $width - $panjangKiri - $panjangKanan;

        if ($spasi < 1) {
            return substr(
                $kiri,
                0,
                $width - $panjangKanan - 1
            ) . ' ' . $kanan;
        }

        return $kiri . str_repeat(' ', $spasi) . $kanan;
    }

    private function wrapThermal(
        string $text,
        int $width = self::THERMAL_WIDTH
    ): array {
        $text = trim($text);

        if ($text === '') {
            return ['-'];
        }

        return wordwrap(
            $text,
            $width,
            "\n",
            true
        ) !== false
            ? explode("\n", wordwrap($text, $width, "\n", true))
            : [$text];
    }
}
