<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Detail Opname</h1>
        <a href="<?= base_url('/cash/riwayat') ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Riwayat
        </a>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr>
                            <th style="width: 40%;">Tanggal Opname</th>
                            <td><?= date('d/m/Y H:i:s', strtotime($opname['tanggal'])) ?></td>
                        </tr>
                        <tr>
                            <th>Saldo Sistem</th>
                            <td><strong>Rp <?= number_format($opname['saldo_sistem'], 0, ',', '.') ?></strong></td>
                        </tr>
                        <tr>
                            <th>Saldo Fisik</th>
                            <td><strong>Rp <?= number_format($opname['saldo_fisik'], 0, ',', '.') ?></strong></td>
                        </tr>
                        <tr>
                            <th>Selisih</th>
                            <td>
                                <span class="badge badge-<?= $opname['status_selisih'] === 'sesuai' ? 'success' : ($opname['status_selisih'] === 'lebih' ? 'warning' : 'danger') ?>">
                                    <?= strtoupper($opname['status_selisih']) ?>
                                </span>
                                <span class="ml-2">Rp <?= number_format(abs($opname['selisih']), 0, ',', '.') ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr>
                            <th style="width: 40%;">Diisi Oleh</th>
                            <td><?= esc($opname['user_nama'] ?? '-') ?></td>
                        </tr>
                        <tr>
                            <th>Kas Awal Hari</th>
                            <td>Rp <?= number_format($opname['saldo_awal_hari'] ?? 0, 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <th>Pemasukan Tunai</th>
                            <td>Rp <?= number_format($opname['pemasukan_tunai'] ?? 0, 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <th>Pengeluaran Tunai</th>
                            <td>Rp <?= number_format($opname['pengeluaran_tunai'] ?? 0, 0, ',', '.') ?></td>
                        </tr>
                        <tr>
                            <th>Alasan Selisih</th>
                            <td><?= nl2br(esc($opname['alasan_selisih'] ?? '-')) ?></td>
                        </tr>
                        <tr>
                            <th>Catatan</th>
                            <td><?= nl2br(esc($opname['catatan'] ?? '-')) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Tombol Kembali -->
    <div class="mt-3">
        <a href="<?= base_url('/cash/riwayat') ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Riwayat
        </a>
        <a href="<?= base_url('/cash') ?>" class="btn btn-primary">
            <i class="fas fa-tachometer-alt"></i> Dashboard Kas
        </a>
    </div>
</div>