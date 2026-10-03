<div class="card">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fas fa-edit"></i> Edit Template Balasan</h5>
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

        <form action="<?= base_url('/balasan-template/update/' . $template['id']) ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="nama" class="form-label">Nama Template *</label>
                <input type="text" class="form-control" id="nama" name="nama"
                    value="<?= old('nama') ?? $template['nama'] ?>" required>
            </div>

            <div class="mb-3">
                <label for="teks" class="form-label">Teks</label>
                <textarea class="form-control" id="teks" name="teks" rows="4"><?= old('teks') ?? $template['teks'] ?></textarea>
            </div>

            <?php if ($template['gambar_filename']): ?>
                <div class="mb-3">
                    <label class="form-label d-block">Gambar saat ini</label>
                    <img src="<?= base_url('/balasan-template/foto/' . $template['gambar_filename']) ?>"
                        alt="<?= $template['nama'] ?>" style="max-width:150px;max-height:150px;object-fit:cover;" class="mb-2 d-block">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="hapus_gambar" name="hapus_gambar" value="1">
                        <label class="form-check-label" for="hapus_gambar">Hapus gambar ini</label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-3">
                <label for="gambar" class="form-label">Ganti Gambar</label>
                <input type="file" class="form-control" id="gambar" name="gambar" accept="image/jpeg,image/png,image/webp">
            </div>

            <small class="text-muted d-block mb-3">Isi teks atau gambar, minimal salah satu.</small>

            <button type="submit" class="btn btn-warning">Update</button>
            <a href="<?= base_url('/balasan-template') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>
