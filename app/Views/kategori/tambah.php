<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-plus"></i> Tambah Kategori</h5>
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

        <form action="<?= base_url('/kategori/simpan') ?>" method="post">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="nama" class="form-label">Nama Kategori *</label>
                        <input type="text" class="form-control" id="nama" name="nama"
                            value="<?= old('nama') ?>" required>
                        <small class="text-muted">Contoh: ATK, Minuman, Digital Printing, dll.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Kategori Induk</label>
                        <select class="form-control" id="parent_id" name="parent_id">
                            <option value="">-- Tidak Ada (Induk Utama) --</option>
                            <?php foreach ($induk as $i): ?>
                                <option value="<?= $i['id'] ?>" <?= old('parent_id') == $i['id'] ? 'selected' : '' ?>>
                                    <?= $i['nama'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Pilih jika ini adalah sub-kategori dari kategori lain.</small>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-success">Simpan</button>
            <a href="<?= base_url('/kategori') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>