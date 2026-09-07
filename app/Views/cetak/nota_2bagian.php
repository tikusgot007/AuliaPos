<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>Nota Transaksi</title>
    <style>
        @page {
            size: A6 landscape;
            margin: 3mm;
        }

        body {
            font-family: 'Courier New', monospace;
            font-size: 7pt;
            margin: 0;
            padding: 0;
        }

        .container {
            display: flex;
            flex-direction: row;
            gap: 3mm;
            padding: 2mm;
            width: 100%;
            box-sizing: border-box;
        }

        /* ========================================== */
        /* KIRI: NOTA UNTUK PELANGGAN (PENGAMBILAN)   */
        /* ========================================== */
        .nota-customer {
            flex: 1;
            border-right: 2px dashed #000;
            padding-right: 3mm;
            min-height: 100%;
        }

        .nota-customer .header {
            text-align: center;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            margin-bottom: 2px;
        }

        .nota-customer .header h2 {
            margin: 0;
            font-size: 11pt;
            letter-spacing: 1px;
        }

        .nota-customer .header small {
            font-size: 6pt;
        }

        .nota-customer .status {
            text-align: center;
            padding: 2px;
            margin: 2px 0;
            font-weight: bold;
            font-size: 8pt;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 2px;
        }

        .nota-customer .info {
            margin-bottom: 2px;
            font-size: 7pt;
        }

        .nota-customer .info p {
            margin: 1px 0;
        }

        .nota-customer .info .label {
            display: inline-block;
            width: 45px;
            font-weight: bold;
        }

        .nota-customer .items {
            width: 100%;
            border-collapse: collapse;
            margin: 2px 0;
            font-size: 6.5pt;
        }

        .nota-customer .items th {
            background: #f2f2f2;
            border: 1px solid #000;
            padding: 1px 2px;
            text-align: center;
            font-size: 6pt;
        }

        .nota-customer .items td {
            border: 1px solid #000;
            padding: 1px 2px;
        }

        .nota-customer .items .text-center {
            text-align: center;
        }

        .nota-customer .items .text-right {
            text-align: right;
        }

        .nota-customer .total {
            margin-top: 2px;
            padding-top: 2px;
            border-top: 1px solid #000;
            text-align: right;
            font-size: 7pt;
        }

        .nota-customer .total p {
            margin: 1px 0;
        }

        .nota-customer .total .sisa {
            color: #dc3545;
            font-weight: bold;
            font-size: 8pt;
        }

        .nota-customer .footer {
            text-align: center;
            margin-top: 2px;
            font-size: 6pt;
        }

        .nota-customer .footer .warning {
            color: #dc3545;
            font-weight: bold;
        }

        /* ========================================== */
        /* KIRI: NOTA UNTUK PELANGGAN (PENGAMBILAN)   */
        /* ========================================== */
        .noota-kanan {
            flex: 1;

            padding-right: 3mm;
            min-height: 100%;
        }

        .noota-kanan .header {
            text-align: center;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
            margin-bottom: 2px;
        }

        .noota-kanan .header h2 {
            margin: 0;
            font-size: 11pt;
            letter-spacing: 1px;
        }

        .noota-kanan .header small {
            font-size: 6pt;
        }

        .noota-kanan .status {
            text-align: center;
            padding: 2px;
            margin: 2px 0;
            font-weight: bold;
            font-size: 8pt;
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 2px;
        }

        .noota-kanan .info {
            margin-bottom: 2px;
            font-size: 7pt;
        }

        .noota-kanan .info p {
            margin: 1px 0;
        }

        .noota-kanan .info .label {
            display: inline-block;
            width: 45px;
            font-weight: bold;
        }

        .noota-kanan .items {
            width: 100%;
            border-collapse: collapse;
            margin: 2px 0;
            font-size: 6.5pt;
        }

        .noota-kanan .items th {
            background: #f2f2f2;
            border: 1px solid #000;
            padding: 1px 2px;
            text-align: center;
            font-size: 6pt;
        }

        .noota-kanan .items td {
            border: 1px solid #000;
            padding: 1px 2px;
        }

        .noota-kanan .items .text-center {
            text-align: center;
        }

        .noota-kanan .items .text-right {
            text-align: right;
        }

        .noota-kanan .total {
            margin-top: 2px;
            padding-top: 2px;
            border-top: 1px solid #000;
            text-align: right;
            font-size: 7pt;
        }

        .noota-kanan .total p {
            margin: 1px 0;
        }

        .noota-kanan .total .sisa {
            color: #dc3545;
            font-weight: bold;
            font-size: 8pt;
        }

        .noota-kanan .footer {
            text-align: center;
            margin-top: 2px;
            font-size: 6pt;
        }

        .noota-kanan .footer .warning {
            color: #dc3545;
            font-weight: bold;
        }

        /* ========================================== */
        /* TOMBOL PRINT                               */
        /* ========================================== */
        .no-print {
            display: block;
            text-align: center;
            margin-top: 10px;
        }

        .btn-print {
            padding: 8px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12pt;
        }

        .btn-print:hover {
            background: #0056b3;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                margin: 0;
                padding: 0;
            }

            .container {
                padding: 2mm;
                gap: 2mm;
            }

            .nota-customer {
                border-right: 2px dashed #000;
                padding-right: 2mm;
            }
        }
    </style>
</head>

<body>

    <div class="container">

        <!-- ========================================== -->
        <!-- KIRI: NOTA UNTUK PELANGGAN (PENGAMBILAN)   -->
        <!-- ========================================== -->
        <div class="nota-customer">

            <div class="header">
                <h2>AULIA FOTO</h2>
                <small>Jl. Trunojoyo 73 Bangkalan</small><br>
                <small>0851 551 05633</small>
            </div>

            <div class="status">
                ⚠️ BELUM LUNAS
            </div>

            <div class="info">
                <p><span class="label">Invoice</span> : <?= $transaksi['kode_invoice'] ?></p>
                <p><span class="label">No Order</span> : <?= $transaksi['no_order'] ? format_no_order($transaksi['no_order']) : '-' ?></p>
                <p><span class="label">Tanggal</span> : <?= date('d/m/Y H:i', strtotime($transaksi['tanggal'])) ?></p>
                <p><span class="label">Pelanggan</span> : <?= $pelanggan['nama'] ?? '-' ?></p>
                <p><span class="label">Telp</span> : <?= $pelanggan['no_hp'] ?? '-' ?></p>
            </div>

            <table class="items">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; ?>
                    <?php foreach ($detail as $item): ?>
                        <tr>
                            <td class="text-center"><?= $no++; ?></td>
                            <td><?= $item['nama_produk'] ?></td>
                            <td class="text-center"><?= number_format($item['jumlah']) ?></td>
                            <td class="text-right"><?= number_format($item['harga_satuan']) ?></td>
                            <td class="text-right"><?= number_format($item['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="total">
                <p><strong>Total</strong> : <?= number_format($transaksi['grand_total'], 0, ',', '.') ?></p>
                <p><strong>Dibayar</strong> : <?= number_format($total_dibayar, 0, ',', '.') ?></p>
                <p class="sisa"><strong>Sisa</strong> : <?= number_format($sisa, 0, ',', '.') ?></p>
            </div>

            <div class="footer">
                <p class="warning">📌 Sisa tagihan dilunasi saat ambil</p>
                <p style="font-size: 5pt;">Nota bukti pengambilan barang</p>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- KANAN: NOTA UNTUK PRODUK (TEMPEK DI BARANG)-->
        <!-- ========================================== -->
        <div class="noota-kanan">

            <div class="header">
                <h2>AULIA FOTO</h2>
                <small>Jl. Trunojoyo 73 Bangkalan</small><br>
                <small>0851 551 05633</small>
            </div>

            <div class="status">
                ⚠️ BELUM LUNAS
            </div>

            <div class="info">
                <p><span class="label">Invoice</span> : <?= $transaksi['kode_invoice'] ?></p>
                <p><span class="label">No Order</span> : <?= $transaksi['no_order'] ? format_no_order($transaksi['no_order']) : '-' ?></p>
                <p><span class="label">Tanggal</span> : <?= date('d/m/Y H:i', strtotime($transaksi['tanggal'])) ?></p>
                <p><span class="label">Pelanggan</span> : <?= $pelanggan['nama'] ?? '-' ?></p>
                <p><span class="label">Telp</span> : <?= $pelanggan['no_hp'] ?? '-' ?></p>
            </div>

            <table class="items">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; ?>
                    <?php foreach ($detail as $item): ?>
                        <tr>
                            <td class="text-center"><?= $no++; ?></td>
                            <td><?= $item['nama_produk'] ?></td>
                            <td class="text-center"><?= number_format($item['jumlah']) ?></td>
                            <td class="text-right"><?= number_format($item['harga_satuan']) ?></td>
                            <td class="text-right"><?= number_format($item['subtotal']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="total">
                <p><strong>Total</strong> : <?= number_format($transaksi['grand_total'], 0, ',', '.') ?></p>
                <p><strong>Dibayar</strong> : <?= number_format($total_dibayar, 0, ',', '.') ?></p>
                <p class="sisa"><strong>Sisa</strong> : <?= number_format($sisa, 0, ',', '.') ?></p>
            </div>

            <div class="footer">
                <p class="warning">📌 Sisa tagihan dilunasi saat ambil</p>
                <p style="font-size: 5pt;">Nota bukti pengambilan barang</p>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TOMBOL PRINT                               -->
    <!-- ========================================== -->
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> Cetak Nota
        </button>
        <button class="btn-print" style="background: #6c757d;" onclick="window.close()">
            <i class="fas fa-times"></i> Tutup
        </button>
    </div>

</body>

</html>