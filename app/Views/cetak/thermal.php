<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">

    <title><?= esc($title ?? 'Struk Thermal') ?></title>

    <style>
        @page {
            size: 58mm auto;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: 58mm;
            background: #fff;
            color: #000;
            font-family: "Courier New", monospace;
            font-size: 11px;
        }

        body {
            padding: 3mm;
        }

        .struk {
            width: 100%;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .bold {
            font-weight: bold;
        }

        .line {
            border-top: 1px dashed #000;
            margin: 4px 0;
        }

        .header-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .small {
            font-size: 9px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 4px;
        }

        .item {
            margin: 4px 0;
        }

        .item-name {
            font-weight: bold;
            word-break: break-word;
        }

        .item-detail {
            display: flex;
            justify-content: space-between;
            gap: 4px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
        }

        .grand-total {
            font-size: 13px;
            font-weight: bold;
        }

        .footer {
            margin-top: 8px;
            text-align: center;
            font-size: 9px;
        }

        @media print {

            html,
            body {
                width: 58mm;
            }

            body {
                padding: 3mm;
            }
        }
    </style>
</head>

<body>

    <div class="struk">

        <div class="center">
            <div class="header-title">AULIA</div>
            <div class="small">Nota Transaksi</div>
        </div>

        <div class="line"></div>

        <div class="small">
            <div>No. Invoice: <?= esc($transaksi['kode_invoice'] ?? '-') ?></div>

            <?php if (!empty($transaksi['no_order'])): ?>
                <div>No. Order: <?= esc($transaksi['no_order']) ?></div>
            <?php endif; ?>

            <div>
                Tanggal:
                <?= !empty($transaksi['tanggal'])
                    ? date('d/m/Y H:i', strtotime($transaksi['tanggal']))
                    : '-'
                ?>
            </div>

            <div>
                Kasir: <?= esc($transaksi['kasir_nama'] ?? '-') ?>
            </div>

            <?php if (!empty($pelanggan)): ?>
                <div>
                    Pelanggan:
                    <?= esc($pelanggan['nama'] ?? '-') ?>
                </div>

                <?php if (!empty($pelanggan['telepon'])): ?>
                    <div>
                        Telp: <?= esc($pelanggan['telepon']) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="line"></div>

        <?php foreach ($detail as $item): ?>

            <div class="item">

                <div class="item-name">
                    <?= esc($item['nama_produk'] ?? '-') ?>
                </div>

                <div class="item-detail">
                    <span>
                        <?= esc($item['jumlah'] ?? 0) ?>
                        <?= esc($item['satuan'] ?? '') ?>
                        x
                        <?= number_format(
                            (float) ($item['harga_satuan'] ?? 0),
                            0,
                            ',',
                            '.'
                        ) ?>
                    </span>

                    <span>
                        <?= number_format(
                            (float) ($item['subtotal'] ?? 0),
                            0,
                            ',',
                            '.'
                        ) ?>
                    </span>
                </div>

                <?php if (!empty($item['catatan'])): ?>
                    <div class="small">
                        <?= esc($item['catatan']) ?>
                    </div>
                <?php endif; ?>

            </div>

        <?php endforeach; ?>

        <div class="line"></div>

        <div class="total-row">
            <span>Subtotal</span>
            <span>
                <?= number_format(
                    (float) ($transaksi['subtotal'] ?? 0),
                    0,
                    ',',
                    '.'
                ) ?>
            </span>
        </div>

        <?php if ((float) ($transaksi['diskon'] ?? 0) > 0): ?>
            <div class="total-row">
                <span>Diskon</span>
                <span>
                    -<?= number_format(
                            (float) $transaksi['diskon'],
                            0,
                            ',',
                            '.'
                        ) ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ((float) ($transaksi['pajak'] ?? 0) > 0): ?>
            <div class="total-row">
                <span>Pajak</span>
                <span>
                    <?= number_format(
                        (float) $transaksi['pajak'],
                        0,
                        ',',
                        '.'
                    ) ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if ((float) ($transaksi['selisih_pembulatan'] ?? 0) != 0): ?>
            <div class="total-row">
                <span>Pembulatan</span>
                <span>
                    -<?= number_format(
                            (float) $transaksi['selisih_pembulatan'],
                            0,
                            ',',
                            '.'
                        ) ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="total-row grand-total">
            <span>TOTAL</span>
            <span>
                <?= number_format(
                    (float) ($transaksi['grand_total'] ?? 0),
                    0,
                    ',',
                    '.'
                ) ?>
            </span>
        </div>

        <div class="line"></div>

        <?php if (!empty($pembayaran)): ?>

            <div class="bold">PEMBAYARAN</div>

            <?php foreach ($pembayaran as $bayar): ?>
                <div class="total-row">
                    <span>
                        <?= esc(strtoupper($bayar['metode'] ?? '')) ?>
                    </span>

                    <span>
                        <?= number_format(
                            (float) ($bayar['jumlah'] ?? 0),
                            0,
                            ',',
                            '.'
                        ) ?>
                    </span>
                </div>
            <?php endforeach; ?>

            <div class="total-row bold">
                <span>Dibayar</span>
                <span>
                    <?= number_format(
                        (float) $total_dibayar,
                        0,
                        ',',
                        '.'
                    ) ?>
                </span>
            </div>

            <?php if ((float) $sisa > 0): ?>
                <div class="total-row bold">
                    <span>Sisa</span>
                    <span>
                        <?= number_format(
                            (float) $sisa,
                            0,
                            ',',
                            '.'
                        ) ?>
                    </span>
                </div>
            <?php endif; ?>

        <?php endif; ?>

        <div class="line"></div>

        <div class="footer">
            Terima kasih<br>
            AULIA
        </div>

    </div>

    <script>
        window.onload = function() {
            window.print();
        };
    </script>

</body>

</html>