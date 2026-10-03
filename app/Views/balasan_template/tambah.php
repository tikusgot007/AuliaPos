<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-plus"></i> Tambah Template Balasan</h5>
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
        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger"><?= session()->getFlashdata('error') ?></div>
        <?php endif; ?>

        <form action="<?= base_url('/balasan-template/simpan') ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="nama" class="form-label">Nama Template *</label>
                <input type="text" class="form-control" id="nama" name="nama"
                    value="<?= old('nama') ?>" required>
                <small class="text-muted">Contoh: QRIS Pembayaran, Jam Buka, Info Ongkir.</small>
            </div>

            <div class="mb-3">
                <label for="teks" class="form-label">Teks</label>
                <textarea class="form-control" id="teks" name="teks" rows="4"><?= old('teks') ?></textarea>
            </div>

            <div class="mb-3">
                <label for="gambar" class="form-label">Gambar</label>
                <input type="file" class="form-control" id="gambar" name="gambar" accept="image/jpeg,image/png,image/webp">
            </div>

            <small class="text-muted d-block mb-3">Isi teks atau gambar, minimal salah satu.</small>

            <button type="submit" class="btn btn-success">Simpan</button>
            <a href="<?= base_url('/balasan-template') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>
