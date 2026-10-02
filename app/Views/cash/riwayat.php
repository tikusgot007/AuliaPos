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
                    <label for="filterRiwayat" class="mr-1">Rentang Tanggal</label>
                    <input type="text" class="form-control" id="filterRiwayat" placeholder="Pilih rentang tanggal" readonly autocomplete="off">
                </div>
                <input type="hidden" name="tanggal_awal" id="tanggal_awal" value="<?= esc($tanggal_awal) ?>">
                <input type="hidden" name="tanggal_akhir" id="tanggal_akhir" value="<?= esc($tanggal_akhir) ?>">
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

<script>
    $(document).ready(function() {
        var $input = $('#filterRiwayat');
        var $awal = $('#tanggal_awal');
        var $akhir = $('#tanggal_akhir');

        $input.daterangepicker({
            locale: AuliaDateRange.locale(),
            ranges: AuliaDateRange.ranges(),
            startDate: moment($awal.val(), 'YYYY-MM-DD'),
            endDate: moment($akhir.val(), 'YYYY-MM-DD'),
            opens: 'left',
            showDropdowns: true
        });

        AuliaDateRange.setRange('#filterRiwayat', $awal.val(), $akhir.val());

        $input.on('apply.daterangepicker', function(ev, picker) {
            $awal.val(picker.startDate.format('YYYY-MM-DD'));
            $akhir.val(picker.endDate.format('YYYY-MM-DD'));
        });
    });
</script>