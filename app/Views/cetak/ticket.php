<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Ticket Transaksi</title>

    <style>
        /*
         * Lebar dibuat sempit (mirip kertas thermal 58-80mm) supaya
         * "cocok untuk printer thermal" sesuai spesifikasi, tapi tetap
         * dicetak lewat dialog print browser (mekanisme yang sama
         * dengan cetak/nota.php) -- bukan ESC/POS langsung, supaya
         * bisa dicetak ke printer apa pun yang sedang jadi default di
         * komputer kasir, thermal maupun bukan.
         */
        @page {
            size: 80mm auto;
            margin: 4mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 16px;
            background: #eee;
            color: #111;
            font-family: 'Courier New', Courier, monospace;
        }

        .ticket {
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
            padding: 14px 12px;
            background: #fff;
            border: 1px solid #ccc;
        }

        .brand {
            text-align: center;
            font-weight: bold;
            font-size: 14px;
            letter-spacing: 1px;
        }

        .subtitle {
            text-align: center;
            font-size: 11px;
            letter-spacing: 2px;
            margin-top: 2px;
            margin-bottom: 10px;
            color: #444;
        }

        .garis {
            border-top: 1px dashed #999;
            margin: 10px 0;
        }

        /* ==========================================
           NO. INVOICE -- HARUS PALING MENONJOL
        ========================================== */
        .invoice-label {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: center;
        }

        .invoice-value {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 4px 0 12px;
            word-break: break-all;
        }

        .baris {
            display: table;
            width: 100%;
            font-size: 12px;
            margin-bottom: 6px;
        }

        .baris .label {
            display: table-cell;
            color: #555;
            width: 40%;
        }

        .baris .value {
            display: table-cell;
            font-weight: bold;
            width: 60%;
        }

        .footer-note {
            text-align: center;
            font-size: 10px;
            color: #777;
            margin-top: 12px;
        }

        /* ==========================================
           TOMBOL WEB (sama pola dengan cetak/nota.php)
        ========================================== */
        .no-print {
            text-align: center;
            margin-top: 15px;
        }

        .btn-print {
            padding: 8px 18px;
            margin: 0 3px;
            background: #333;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .btn-close {
            background: #777;
        }

        .btn-print:hover {
            opacity: 0.85;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
                background: #fff;
            }

            .ticket {
                max-width: none;
                border: none;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="ticket">

        <div class="brand">AULIA POS</div>
        <div class="subtitle">TICKET / HANDOVER</div>

        <div class="garis"></div>

        <div class="invoice-label">No. Invoice</div>
        <div class="invoice-value"><?= esc($transaksi['kode_invoice']) ?></div>

        <?php if (!empty($transaksi['no_order'])): ?>
            <div class="baris">
                <div class="label">No. Order</div>
                <div class="value"><?= esc(format_no_order($transaksi['no_order'])) ?></div>
            </div>
        <?php endif; ?>

        <div class="garis"></div>

        <div class="baris">
            <div class="label">Tanggal</div>
            <div class="value"><?= date('d-m-Y', strtotime($transaksi['tanggal'])) ?></div>
        </div>

        <div class="baris">
            <div class="label">Pelanggan</div>
            <div class="value"><?= esc($pelanggan['nama'] ?? '-') ?></div>
        </div>

        <?php if (!empty($jumlah_item)): ?>
            <div class="baris">
                <div class="label">Jumlah Item</div>
                <div class="value"><?= (int) $jumlah_item ?></div>
            </div>
        <?php endif; ?>

        <div class="baris">
            <div class="label">Total</div>
            <div class="value">Rp <?= number_format((float) $transaksi['grand_total'], 0, ',', '.') ?></div>
        </div>

        <?php if (!empty($transaksi['kasir_nama'])): ?>
            <div class="baris">
                <div class="label">Kasir</div>
                <div class="value"><?= esc($transaksi['kasir_nama']) ?></div>
            </div>
        <?php endif; ?>

        <div class="garis"></div>

        <div class="footer-note">
            Tunjukkan ticket ini untuk melanjutkan transaksi.
        </div>

    </div>

    <div class="no-print">
        <button type="button" class="btn-print" onclick="window.print()">
            Cetak Ticket
        </button>
        <button type="button" class="btn-print btn-close" onclick="window.close()">
            Tutup
        </button>
    </div>

</body>

</html>
