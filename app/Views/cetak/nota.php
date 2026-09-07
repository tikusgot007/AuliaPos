<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">

    <title>Nota Transaksi</title>

    <style>
        /* ==========================================
           PENGATURAN HALAMAN CETAK
        ========================================== */

        @page {
            size: A6 landscape;
            margin: 5mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 15px;
            background: #f3f4f6;
            color: #222;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
        }

        /* ==========================================
           CONTAINER NOTA
        ========================================== */

        .nota {
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
            padding: 12px;
            background: #ffffff;
        }

        /* ==========================================
           HEADER
        ========================================== */

        .header {
            display: table;
            width: 100%;
            border-bottom: 2px solid #333;
            padding-bottom: 8px;
            margin-bottom: 8px;
        }

        .header-left,
        .header-right {
            display: table-cell;
            vertical-align: top;
        }

        .header-left {
            width: 55%;
        }

        .header-right {
            width: 45%;
            padding-left: 15px;
        }

        .logo {
            margin-bottom: 4px;
        }

        .logo img {
            max-width: 100;
            max-height: 30px;
            height: auto;
        }

        .alamat {
            font-size: 10px;
            line-height: 1.5;
            color: #444;
        }

        /* ==========================================
           INFO TRANSAKSI
        ========================================== */

        .info-transaksi {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .info-transaksi td {
            padding: 1px 0;
            vertical-align: top;
        }

        .info-transaksi .label {
            width: 42%;
            text-align: right;
            padding-right: 5px;
            color: #555;
        }

        .info-transaksi .nilai {
            font-weight: bold;
            white-space: nowrap;
        }

        /* ==========================================
           TABEL ITEM
        ========================================== */

        .item-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 10px;
        }

        .item-table th {
            padding: 5px 4px;
            background: #e9ecef;
            border-top: 1px solid #777;
            border-bottom: 1px solid #777;
            text-align: center;
            font-weight: bold;
        }

        .item-table td {
            padding: 4px;
            border-bottom: 1px solid #ddd;
        }

        .item-table tbody tr:last-child td {
            border-bottom: 1px solid #777;
        }

        .col-no {
            width: 6%;
            text-align: center;
        }

        .col-item {
            width: 50%;
        }

        .col-qty {
            width: 10%;
            text-align: center;
        }

        .col-harga {
            width: 17%;
            text-align: right;
        }

        .col-subtotal {
            width: 17%;
            text-align: right;
        }

        /* ==========================================
           BAGIAN BAWAH
        ========================================== */

        .summary-wrapper {
            display: table;
            width: 100%;
            margin-top: 8px;
        }

        .payment-status,
        .summary {
            display: table-cell;
            vertical-align: top;
        }

        .payment-status {
            width: 55%;
            padding-right: 10px;
        }

        .summary {
            width: 45%;
        }

        /* STATUS LUNAS */

        .status {
            display: inline-block;
            padding: 5px 8px;
            border: 1px solid;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
        }

        .status-lunas {
            color: #155724;
            background: #d4edda;
            border-color: #b8ddc2;
        }

        .status-belum {
            color: #721c24;
            background: #f8d7da;
            border-color: #efbfc5;
        }

        /* CATATAN STATUS */

        .status-info {
            margin-top: 5px;
            font-size: 9px;
            color: #555;
        }

        /* TABEL TOTAL */

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        .summary-table td {
            padding: 3px 5px;
        }

        .summary-table .label {
            text-align: right;
            font-weight: bold;
            width: 55%;
        }

        .summary-table .value {
            text-align: right;
            width: 45%;
        }

        /* GRAND TOTAL */

        .summary-table .grand-total td {
            padding-top: 5px;
            padding-bottom: 5px;
            background: #ffeeee;
            color: #000000;
            font-size: 12px;
            font-weight: bold;
        }

        /* SISA TAGIHAN */

        .summary-table .sisa td {
            color: #b02a37;
            font-weight: bold;
            border-top: 1px dashed #999;
        }

        /* ==========================================
           FOOTER
        ========================================== */

        .footer {
            margin-top: 10px;
            padding-top: 5px;
            border-top: 1px dashed #999;
            text-align: center;
            font-size: 8px;
            color: #666;
        }

        .footer .thank-you {
            font-weight: bold;
            color: #444;
            margin-bottom: 2px;
        }

        /* ==========================================
           TOMBOL WEB
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

        /* ==========================================
           MODE PRINT
        ========================================== */

        @media print {

            body {
                margin: 0;
                padding: 0;
                background: #fff;
            }

            .nota {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .no-print {
                display: none !important;
            }

            .header {
                margin-bottom: 5px;
                padding-bottom: 5px;
            }

            .item-table {
                margin-top: 5px;
            }

            .summary-wrapper {
                margin-top: 5px;
            }

            .footer {
                margin-top: 6px;
            }

        }
    </style>

</head>

<body>

    <div class="nota">

        <!-- ==========================================
         HEADER
    ========================================== -->

        <div class="header">

            <div class="header-left">

                <div class="logo">
                    <img
                        src="<?= base_url('logo-no-background.png') ?>"
                        alt="Logo AULIA">
                </div>

                <div class="alamat">
                    Jl. Trunojoyo 73 Bangkalan<br>
                    Telp. 0851 551 05633
                </div>

            </div>


            <div class="header-right">

                <table class="info-transaksi">

                    <tr>
                        <td class="label">No. Invoice :</td>
                        <td class="nilai">
                            <?= htmlspecialchars($transaksi['kode_invoice'] ?? '-') ?>
                        </td>
                    </tr>

                    <tr>
                        <td class="label">No. Order :</td>
                        <td class="nilai">
                            <?= !empty($transaksi['no_order'])
                                ? format_no_order((int) $transaksi['no_order'])
                                : '-' ?>
                        </td>
                    </tr>

                    <tr>
                        <td class="label">Tanggal :</td>
                        <td class="nilai">
                            <?= !empty($transaksi['tanggal'])
                                ? date('d/m/Y H:i', strtotime($transaksi['tanggal']))
                                : '-' ?>
                        </td>
                    </tr>

                    <tr>
                        <td class="label">Pelanggan :</td>
                        <td class="nilai">
                            <?= htmlspecialchars($pelanggan['nama'] ?? '-') ?>
                        </td>
                    </tr>

                    <tr>
                        <td class="label">No. Telp :</td>
                        <td class="nilai">
                            <?= htmlspecialchars($pelanggan['no_hp'] ?? '-') ?>
                        </td>
                    </tr>

                </table>

            </div>

        </div>


        <!-- ==========================================
         TABEL ITEM
    ========================================== -->

        <table class="item-table">

            <thead>

                <tr>
                    <th class="col-no">No.</th>
                    <th class="col-item">Nama Item</th>
                    <th class="col-qty">Qty</th>
                    <th class="col-harga">Harga</th>
                    <th class="col-subtotal">Subtotal</th>
                </tr>

            </thead>

            <tbody>

                <?php if (!empty($detail)): ?>

                    <?php $no = 1; ?>

                    <?php foreach ($detail as $item): ?>

                        <tr>

                            <td class="col-no">
                                <?= $no ?>
                            </td>

                            <td class="col-item">
                                <?= htmlspecialchars($item['nama_produk'] ?? '-') ?>
                            </td>

                            <td class="col-qty">
                                <?= number_format($item['jumlah'] ?? 0, 0, ',', '.') ?>
                            </td>

                            <td class="col-harga">
                                Rp <?= number_format($item['harga_satuan'] ?? 0, 0, ',', '.') ?>
                            </td>

                            <td class="col-subtotal">
                                Rp <?= number_format($item['subtotal'] ?? 0, 0, ',', '.') ?>
                            </td>

                        </tr>

                        <?php $no++; ?>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td colspan="5" style="text-align: center; padding: 10px;">
                            Tidak ada item transaksi
                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>


        <!-- ==========================================
         STATUS DAN RINGKASAN PEMBAYARAN
    ========================================== -->

        <div class="summary-wrapper">


            <!-- STATUS PEMBAYARAN -->

            <div class="payment-status">

                <?php if ($sisa > 0): ?>

                    <div class="status status-belum">
                        BELUM LUNAS
                    </div>

                    <div class="status-info">
                        Sisa tagihan:
                        <strong>
                            Rp <?= number_format($sisa, 0, ',', '.') ?>
                        </strong>
                    </div>

                <?php else: ?>

                    <div class="status status-lunas">
                        ✓ LUNAS
                    </div>

                    <div class="status-info">
                        Pembayaran telah diterima.
                    </div>

                <?php endif; ?>

            </div>


            <!-- RINGKASAN TOTAL -->

            <div class="summary">

                <table class="summary-table">

                    <tr>
                        <td class="label">
                            Total
                        </td>

                        <td class="value">
                            Rp <?= number_format($transaksi['subtotal'] ?? 0, 0, ',', '.') ?>
                        </td>
                    </tr>


                    <?php if (!empty($transaksi['diskon']) && $transaksi['diskon'] > 0): ?>

                        <tr>

                            <td class="label">
                                Diskon
                            </td>

                            <td class="value">
                                - Rp <?= number_format($transaksi['diskon'], 0, ',', '.') ?>
                            </td>

                        </tr>

                    <?php endif; ?>


                    <tr class="grand-total">

                        <td class="label">
                            GRAND TOTAL
                        </td>

                        <td class="value">
                            Rp <?= number_format($transaksi['grand_total'] ?? 0, 0, ',', '.') ?>
                        </td>

                    </tr>


                    <tr>

                        <td class="label">
                            Dibayar
                        </td>

                        <td class="value">
                            Rp <?= number_format($total_dibayar ?? 0, 0, ',', '.') ?>
                        </td>

                    </tr>


                    <?php if ($sisa > 0): ?>

                        <tr class="sisa">

                            <td class="label">
                                Sisa Tagihan
                            </td>

                            <td class="value">
                                Rp <?= number_format($sisa, 0, ',', '.') ?>
                            </td>

                        </tr>

                    <?php endif; ?>

                </table>

            </div>

        </div>


        <!-- ==========================================
         FOOTER
    ========================================== -->

        <div class="footer">

            <div class="thank-you">
                Terima kasih atas kepercayaan Anda
            </div>

            <div>
                Simpan nota ini sebagai bukti transaksi.
            </div>

        </div>

    </div>


    <!-- ==========================================
     TOMBOL WEB
========================================== -->

    <div class="no-print">

        <button
            type="button"
            class="btn-print"
            onclick="window.print()">
            Cetak Nota
        </button>

        <button
            type="button"
            class="btn-print btn-close"
            onclick="window.close()">
            Tutup
        </button>

    </div>

</body>

</html>