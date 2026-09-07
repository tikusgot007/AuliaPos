<div class="container-fluid py-4">

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="mb-1">
                <i class="fas fa-search-dollar"></i>
                Cek Ganti / Edit
            </h5>
            <small class="text-muted">
                Memeriksa pekerjaan Ganti/Edit dan kapan transaksinya dibayar.
            </small>
        </div>

        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="tanggal_mulai" class="form-label fw-semibold">
                        Dari Tanggal Transaksi
                    </label>
                    <input
                        type="date"
                        id="tanggal_mulai"
                        name="tanggal_mulai"
                        value="<?= esc($tanggal_mulai) ?>"
                        class="form-control">
                </div>

                <div class="col-md-3">
                    <label for="tanggal_sampai" class="form-label fw-semibold">
                        Sampai Tanggal Transaksi
                    </label>
                    <input
                        type="date"
                        id="tanggal_sampai"
                        name="tanggal_sampai"
                        value="<?= esc($tanggal_sampai) ?>"
                        class="form-control">
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                        Tampilkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-3">

            <div class="card border-secondary h-100">

                <div class="card-body">

                    <div class="small text-muted">
                        Total Detail Ganti/Edit
                    </div>

                    <div class="fs-4 fw-bold">
                        <?= number_format(
                            $jumlahDetail,
                            0,
                            ',',
                            '.'
                        ) ?>
                        detail
                    </div>

                    <div class="small text-secondary mt-1">
                        Rp <?= number_format(
                                $nominalTotalGantiEdit,
                                0,
                                ',',
                                '.'
                            ) ?>
                    </div>

                </div>

            </div>

        </div>

        <div class="col-md-3">
            <div class="card border-success h-100">
                <div class="card-body">
                    <div class="small text-muted">Dibayar Hari yang Sama</div>
                    <div class="fs-4 fw-bold text-success">
                        <?= number_format($jumlahBayarHariSama, 0, ',', '.') ?> detail
                    </div>
                    <div class="small text-success mt-1">
                        Rp <?= number_format($nominalBayarHariSama, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-warning h-100">
                <div class="card-body">
                    <div class="small text-muted">DP Hari yang Sama</div>
                    <div class="fs-4 fw-bold text-warning">
                        <?= number_format($jumlahDpHariSama, 0, ',', '.') ?> detail
                    </div>
                    <div class="small text-warning mt-1">
                        Rp <?= number_format($nominalDpHariSama, 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-danger h-100">
                <div class="card-body">
                    <div class="small text-muted">Belum Bayar</div>
                    <div class="fs-4 fw-bold text-danger">
                        <?= number_format($jumlahBelumBayar, 0, ',', '.') ?> detail
                    </div>
                    <div class="small text-danger mt-1">
                        Rp <?= number_format($nominalBelumBayar, 0, ',', '.') ?>
                    </div>
                    <?php if ($jumlahBayarBelakangan > 0): ?>
                        <div class="small text-info mt-2">
                            Bayar belakangan:
                            <?= number_format($jumlahBayarBelakangan, 0, ',', '.') ?> detail
                            · Rp <?= number_format($nominalBayarBelakangan, 0, ',', '.') ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <strong>Detail Ganti / Edit</strong>
            <div class="small text-muted">
                Detail diambil jika nama produk mengandung “ganti” atau “edit”.
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Invoice</th>
                            <th>Produk</th>
                            <th>Catatan</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">Proporsi</th>
                            <th>Pembayaran</th>
                            <th class="text-end">Total Dibayar</th>
                            <th class="text-end">Bayar Hari Transaksi</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                    Tidak ada detail Ganti/Edit pada periode tersebut.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <?= date('d-m-Y H:i', strtotime($row['tanggal_transaksi'])) ?>
                                    </td>

                                    <td class="text-nowrap">
                                        <strong><?= esc($row['invoice']) ?></strong>
                                        <div class="small text-muted">
                                            ID <?= esc($row['transaksi_id']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= esc($row['nama_produk']) ?>
                                    </td>

                                    <td>
                                        <?php if (trim((string) $row['catatan']) !== ''): ?>
                                            <?= esc($row['catatan']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        Rp <?= number_format($row['subtotal_detail'], 0, ',', '.') ?>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        <?= number_format($row['proporsi'], 2, ',', '.') ?>%
                                    </td>

                                    <td style="min-width: 280px;">
                                        <?php if (empty($row['payments'])): ?>
                                            <span class="text-danger">
                                                Belum ada pembayaran
                                            </span>
                                        <?php else: ?>
                                            <?php foreach ($row['payments'] as $payment): ?>
                                                <div class="border-bottom py-1">
                                                    <div class="text-nowrap">
                                                        <?= date('d-m-Y H:i', strtotime($payment['tanggal'])) ?>
                                                    </div>

                                                    <div>
                                                        <span class="badge bg-secondary">
                                                            <?= esc(strtoupper($payment['metode'])) ?>
                                                        </span>
                                                        <strong>
                                                            Rp <?= number_format($payment['jumlah'], 0, ',', '.') ?>
                                                        </strong>
                                                    </div>

                                                    <?php if (trim((string) $payment['keterangan']) !== ''): ?>
                                                        <div class="small text-muted">
                                                            <?= esc($payment['keterangan']) ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="small text-primary">
                                                        Alokasi item:
                                                        Rp <?= number_format($payment['alokasi_ke_detail'], 0, ',', '.') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        Rp <?= number_format($row['total_dibayar'], 0, ',', '.') ?>
                                    </td>

                                    <td class="text-end text-nowrap">
                                        <?php if ($row['dibayar_hari_transaksi'] > 0): ?>
                                            <strong class="text-success">
                                                Rp <?= number_format($row['dibayar_hari_transaksi'], 0, ',', '.') ?>
                                            </strong>
                                        <?php else: ?>
                                            <span class="text-muted">Rp 0</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-nowrap">
                                        <?php
                                        switch ($row['status_cek']) {
                                            case 'DIBAYAR HARI YANG SAMA':
                                                $badge = 'bg-success';
                                                break;
                                            case 'DP HARI YANG SAMA':
                                                $badge = 'bg-warning text-dark';
                                                break;
                                            case 'DIBAYAR BELAKANGAN':
                                                $badge = 'bg-info text-dark';
                                                break;
                                            default:
                                                $badge = 'bg-danger';
                                                break;
                                        }
                                        ?>

                                        <span class="badge <?= $badge ?>">
                                            <?= esc($row['status_cek']) ?>
                                        </span>

                                        <?php if ($row['pembayaran_pertama']): ?>
                                            <div class="small text-muted mt-1">
                                                Bayar pertama:<br>
                                                <?= date('d-m-Y H:i', strtotime($row['pembayaran_pertama'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-info mt-3 mb-0">
        <i class="fas fa-info-circle"></i>
        <strong>Keterangan:</strong>
        pembayaran transaksi ditampilkan lengkap. Nilai “Alokasi item”
        adalah bagian pembayaran yang menjadi porsi detail Ganti/Edit
        berdasarkan subtotal detail terhadap seluruh subtotal detail transaksi.
    </div>

</div>