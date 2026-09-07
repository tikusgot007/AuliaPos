<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Opname Kas</h1>
        <a href="<?= base_url('/cash') ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>

    <div class="row">
        <!-- Saldo Sistem -->
        <div class="col-md-6">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Saldo Sistem</h5>
                    <h2 class="display-4">Rp <?= number_format($saldo_sistem, 0, ',', '.') ?></h2>
                    <small>
                        Kas Awal: Rp <?= number_format($kas_awal, 0, ',', '.') ?> |
                        Pemasukan: Rp <?= number_format($pemasukan, 0, ',', '.') ?> |
                        Pengeluaran: Rp <?= number_format($pengeluaran, 0, ',', '.') ?>
                    </small>
                </div>
            </div>
        </div>

        <!-- Form Opname -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">Input Saldo Fisik</h6>
                </div>
                <div class="card-body">
                    <form action="<?= base_url('/cash/opname') ?>" method="post">
                        <?= csrf_field() ?>

                        <div class="form-group mb-3">
                            <label for="saldo_fisik">Saldo Fisik (Uang di Laci)</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text">Rp</span>
                                </div>
                                <input type="number"
                                    class="form-control form-control-lg"
                                    id="saldo_fisik"
                                    name="saldo_fisik"
                                    step="100"
                                    min="0"
                                    required
                                    autofocus
                                    placeholder="Masukkan jumlah uang fisik">
                            </div>
                            <small class="form-text text-muted">Masukkan total uang tunai yang ada di laci kas.</small>
                        </div>

                        <div class="form-group mb-3">
                            <label for="alasan_selisih">Alasan Selisih (Opsional)</label>
                            <input type="text" class="form-control" id="alasan_selisih" name="alasan_selisih" placeholder="Jika ada selisih, jelaskan alasannya">
                        </div>

                        <div class="form-group mb-3">
                            <label for="catatan">Catatan (Opsional)</label>
                            <textarea class="form-control" id="catatan" name="catatan" rows="2" placeholder="Catatan tambahan"></textarea>
                        </div>

                        <div class="form-group mb-3">
                            <input type="hidden" name="tanggal" value="<?= date('Y-m-d H:i:s') ?>">
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg btn-block">
                            <i class="fas fa-save"></i> Simpan Opname
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Info Opname Terakhir -->
    <?php if ($opname_terakhir): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Opname Terakhir Hari Ini</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Waktu:</strong> <?= date('d/m/Y H:i', strtotime($opname_terakhir['tanggal'])) ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Saldo Sistem:</strong> Rp <?= number_format($opname_terakhir['saldo_sistem'], 0, ',', '.') ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Saldo Fisik:</strong> Rp <?= number_format($opname_terakhir['saldo_fisik'], 0, ',', '.') ?>
                            </div>
                            <div class="col-md-3">
                                <strong>Selisih:</strong>
                                <span class="badge badge-<?= $opname_terakhir['status_selisih'] === 'sesuai' ? 'success' : ($opname_terakhir['status_selisih'] === 'lebih' ? 'warning' : 'danger') ?>">
                                    Rp <?= number_format(abs($opname_terakhir['selisih']), 0, ',', '.') ?>
                                    (<?= strtoupper($opname_terakhir['status_selisih']) ?>)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>