<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Nota Transaksi</title>
    <style>
        @page {
            size: A6 landscape;
            margin: 5mm;
        }

        /* STATUS */
        .status {
            text-align: center;
            padding: 2px;
            margin: 2px 0;
            font-weight: bold;
            font-size: 9pt;
            border-radius: 2px;
        }

        .status-lunas {
            color: #155724;
            background: #d4edda;
            border: 1px solid #c3e6cb;
        }

        .status-belum {
            color: #721c24;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        /* TOMBOL PRINT (HANYA DI WEB) */
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

        /* FOOTER */
        .footer {
            text-align: center;
            border-top: 1px dashed #000;
            padding-top: 2px;
            margin-top: 2px;
            font-size: 6pt;
        }

        .footer .warning {
            color: #dc3545;
            font-weight: bold;
            font-size: 7pt;
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
            }

            .header .logo img {
                max-height: 35px;
            }
        }
    </style>

</head>

<body>
    <table width="700" border="0">
        <tr>
            <td width="279" rowspan="4">
                <div align="left"><img src="<?= base_url() ?>logo-no-background.png" width="200" /></div>
            </td>
            <td width="82">&nbsp;</td>
            <td width="159">
                <div align="right">No.invoice :</div>
            </td>
            <td width="133"><strong><?= $transaksi['kode_invoice'] ?></strong></td>




        </tr>

        <tr>
            <td>&nbsp;</td>
            <td>
                <div align="right">No Order :</div>
            </td>
            <td><strong><?= $transaksi['no_order'] ? format_no_order((int)$transaksi['no_order']) : '-' ?></strong></td>
        </tr>

        <tr>
            <td>&nbsp;</td>
            <td>
                <div align="right">Tanggal :</div>
            </td>
            <td><strong><?= date('d/m/Y H:i', strtotime($transaksi['tanggal'])) ?></strong></td>
        </tr>
        <tr>
            <td>&nbsp;</td>
            <td>
                <div align="right">Nama Pelanggan :</div>
            </td>
            <td><strong><?= $pelanggan['nama'] ?? '-' ?></strong></td>
        </tr>
        <tr>
            <td colspan="2" nowrap="nowrap" style="font-size: 14px;">Jl. Trunojoyo 73 Bangkalan. Telp 0851 551 05633</td>
            <td>
                <div align="right">No. Telp :</div>
            </td>
            <td><strong><?= $pelanggan['no_hp'] ?? '-' ?></strong></td>
        </tr>
    </table>
    <hr align="left" width="700" />
    <table width="700" border="1px" cellpadding="3" cellspacing="0">
        <tr>
            <th>No.</th>
            <th>Nama Item</th>
            <th>Qty</th>
            <th>Harga</th>
            <th>Sub Total</th>
        </tr>
        <?php
        $no = 1;

        foreach ($detail as $item) {
        ?>


            <tr>
                <td>
                    <div align="center"><?= $no; ?></div>
                </td>
                <td><?= $item['nama_produk'] ?></td>
                <td>
                    <div align="center"><?= number_format($item['jumlah']) ?></div>
                </td>
                <td>
                    <div align="center"><?= number_format($item['harga_satuan']) ?></div>
                </td>
                <td>
                    <div align="right"><?= number_format($item['subtotal']) ?></div>
                </td>
            </tr>
        <?php
            $no++;
        } ?>
        <tr>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>
            <td>&nbsp;</td>

        </tr>
        <tr>
            <td colspan="3" rowspan="4" align="left" valign="top">


                <?php if ($sisa > 0): ?>
                    <strong>⚠️ BELUM LUNAS (Sisa: Rp <?= number_format($sisa, 0, ',', '.') ?>)</strong>
                <?php else: ?>
                    <strong>✅ LUNAS</strong>
                <?php endif; ?>

            </td>


            <td align="right"><strong>Total</strong></td>
            <td align="right"><strong>
                    <?= number_format($transaksi['subtotal'], 0, ',', '.') ?>
                </strong></td>
        </tr>
        <?php if ($transaksi['diskon'] > 0): ?>
            <tr>
                <td align="right"><strong>Bayar</strong></td>
                <td align="right"><strong>
                <td align="right">-<?= number_format($transaksi['diskon'], 0, ',', '.') ?>
                    </strong></td>
            </tr>
        <?php endif; ?>

        <tr>
            <td align="right"><strong>Grand Total</strong></td>
            <td align="right"> <strong>
                    <?= number_format($transaksi['grand_total'], 0, ',', '.') ?>
                </strong></td>
        </tr>
        <tr>
            <td align="right"><strong>Dibayar</strong></td>
            <td align="right"><?= number_format($total_dibayar, 0, ',', '.') ?></td>
        </tr>
        <?php if ($sisa > 0): ?>
            <tr>
                <td align="right" class="sisa"><strong>Sisa Tagihan</strong></td>
                <td align="right" class="sisa"><?= number_format($sisa, 0, ',', '.') ?></td>
            </tr>
        <?php endif; ?>
    </table>
    <p>&nbsp;</p>
    <p>&nbsp;</p>


    <!-- ========================================== -->
    <!-- TOMBOL PRINT (HANYA DI WEB)                -->
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