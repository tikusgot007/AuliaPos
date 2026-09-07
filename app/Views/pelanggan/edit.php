<div class="card">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fas fa-edit"></i> Edit Pelanggan</h5>
    </div>
    <div class="card-body">
        <?php if (session()->getFlashdata('errors')): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach (session()->getFlashdata('errors') as $error): ?>
                        <li><?= $error ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('/pelanggan/update/' . $pelanggan['id']) ?>" method="post">
            <?= csrf_field() ?>

            <input
                type="hidden"
                name="return"
                value="<?= esc($_GET['return'] ?? '') ?>">

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="nama" class="form-label">Nama Pelanggan *</label>
                        <input type="text" class="form-control" id="nama" name="nama"
                            value="<?= old('nama') ?? $pelanggan['nama'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="no_hp" class="form-label">No. HP</label>
                        <input type="text" class="form-control" id="no_hp" name="no_hp"
                            value="<?= old('no_hp') ?? $pelanggan['no_hp'] ?>" placeholder="Contoh: 081234567890">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="alamat" class="form-label">Alamat</label>
                        <textarea class="form-control" id="alamat" name="alamat" rows="3"><?= old('alamat') ?? $pelanggan['alamat'] ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="diskon" class="form-label">Diskon (%)</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="diskon" name="diskon"
                                value="<?= old('diskon') ?? $pelanggan['diskon'] ?? 0 ?>" min="0" max="100" step="1">
                            <span class="input-group-text">%</span>
                        </div>
                        <small class="text-muted">Diskon khusus untuk pelanggan ini (0-100%)</small>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-warning">Update</button>
            <a href="<?= base_url('/pelanggan') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>