<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Riwayat Opname</h1>
        <a href="<?= base_url('/cash') ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
        </a>
    </div>

    <!-- Filter Tanggal -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="form-inline">
                <div class="form-group mr-2">
                    <label for="tanggal_awal" class="mr-1">Dari</label>
                    <input type="date" class="form-control" id="tanggal_awal" name="tanggal_awal" value="<?= $tanggal_awal ?>">
                </div>
                <div class="form-group mr-2">
                    <label for="tanggal_akhir" class="mr-1">Sampai</label>
                    <input type="date" class="form-control" id="tanggal_akhir" name="tanggal_akhir" value="<?= $tanggal_akhir ?>">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="<?= base_url('/cash/riwayat') ?>" class="btn btn-secondary ml-2">Reset</a>
            </form>
        </div>
    </div>

    <!-- Tabel Riwayat -->
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Saldo Fisik</th>
                        <th>Saldo Sistem</th>
                        <th>Selisih</th>
                        <th>Status</th>
                        <th>Diisi Oleh</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($opname as $row): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($row['tanggal'])) ?></td>
                            <td>Rp <?= number_format($row['saldo_fisik'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($row['saldo_sistem'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format(abs($row['selisih']), 0, ',', '.') ?></td>
                            <td>
                                <span class="badge badge-<?= $row['status_selisih'] === 'sesuai' ? 'success' : ($row['status_selisih'] === 'lebih' ? 'warning' : 'danger') ?>">
                                    <?= strtoupper($row['status_selisih']) ?>
                                </span>
                            </td>
                            <td><?= esc($row['user_nama'] ?? '-') ?></td>
                            <td>
                                <a href="<?= base_url('/cash/detail/' . $row['id']) ?>" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> Detail
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($opname)): ?>
                        <tr>
                            <td colspan="7" class="text-center">Belum ada data opname.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>