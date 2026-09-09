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
        //  if ($transaksi['status_pembayaran'] != 'lunas') {
        //     return view('cetak/nota_2bagian', $data);
        // }
        return view('cetak/nota', $data);
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

    public function struk($id)
    {
        $transaksiModel = new TransaksiModel();
        $detailModel = new DetailTransaksiModel();
        $pembayaranModel = new PembayaranModel();
        $pelangganModel = new PelangganModel();

        // Ambil data transaksi
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

        // 🔥 Data untuk view
        $data = [
            'transaksi' => $transaksi,
            'detail'    => $detail,
            'pelanggan' => $pelanggan,
            'total_dibayar' => $totalDibayar,
            'sisa' => $sisa,
            'title' => 'Struk Transaksi'
        ];
        $view = ($transaksi['status_pembayaran'] != 'lunas')
            ? 'cetak/nota_2bagian'
            : 'cetak/nota';

        $html = view($view, $data);

        // 🔥 Generate PDF
        return $this->generatePDF($data);
    }

    private function generatePDF($data)
    {
        // 🔥 Load view struk
        $html = view('cetak/nota', $data);

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'Courier');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);

        // 🔥 UKURAN A6 LANDSCAPE
        $dompdf->setPaper('A6', 'landscape');

        $dompdf->render();
        $dompdf->stream('Nota_' . $transaksi['kode_invoice'] . '.pdf', [
            'Attachment' => false
        ]);
        exit;
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
