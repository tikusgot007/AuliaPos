<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-database"></i> Migrasi Database (Manual)</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="fas fa-triangle-exclamation"></i>
            Halaman ini menjalankan perubahan struktur database secara langsung.
            Pastikan sudah <strong>backup database</strong> sebelum menekan tombol Jalankan.
            Setelah migration yang dibutuhkan berhasil, sebaiknya halaman ini
            dinonaktifkan lagi (hapus route-nya) agar tidak menyala permanen.
        </div>

        <?php $log = session()->getFlashdata('migrasi_log'); ?>
        <?php if (!empty($log)): ?>
            <div class="mb-3">
                <strong>Log proses migrasi:</strong>
                <pre class="bg-light p-3 border rounded" style="white-space: pre-wrap; font-size: 0.85rem;"><?php foreach ($log as $baris): ?><?= esc($baris) ?>
<?php endforeach; ?></pre>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorBaca)): ?>
            <div class="alert alert-danger"><?= esc($errorBaca) ?></div>
        <?php endif; ?>

        <h6>Daftar migration terdeteksi</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>Versi</th>
                        <th>Class</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($tersedia)): ?>
                        <?php foreach ($tersedia as $m): ?>
                            <?php
                            $key = $m['versi'] . '_' . $m['class'];
                            $sudah = in_array($key, $sudahJalan, true);
                            ?>
                            <tr>
                                <td><code><?= esc($m['versi']) ?></code></td>
                                <td><?= esc($m['class']) ?></td>
                                <td>
                                    <?php if ($sudah): ?>
                                        <span class="badge bg-success">Sudah dijalankan</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted">Tidak ada migration terdeteksi.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <form action="<?= base_url('/migrasi-manual/jalankan') ?>" method="post"
            data-confirm-message="Yakin jalankan migration ke versi terbaru? Pastikan database sudah di-backup." data-confirm-ok-text="Ya, Jalankan">
            <?= csrf_field() ?>
            <div class="mb-3" style="max-width: 320px;">
                <label for="konfirmasi" class="form-label">
                    Ketik <strong>JALANKAN</strong> untuk konfirmasi
                </label>
                <input type="text" class="form-control" id="konfirmasi" name="konfirmasi" required
                    placeholder="JALANKAN" autocomplete="off">
            </div>
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-play"></i> Jalankan Migrasi ke Versi Terbaru
            </button>
        </form>
    </div>
</div>
