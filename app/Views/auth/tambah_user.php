<div class="card">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-user-plus"></i> Tambah User</h5>
    </div>
    <div class="card-body">
        <form action="<?= base_url('/auth/simpan-user') ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username"
                            value="<?= old('username') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password *</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <small class="text-muted">Minimal 6 karakter</small>
                    </div>
                    <div class="mb-3">
                        <label for="nama" class="form-label">Nama</label>
                        <input type="text" class="form-control" id="nama" name="nama" value="<?= old('nama') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="no_hp" class="form-label">No. HP</label>
                        <input type="text" class="form-control" id="no_hp" name="no_hp" value="<?= old('no_hp') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="foto" class="form-label">Foto Profil</label>
                        <input type="file" class="form-control" id="foto" name="foto" accept="image/jpeg,image/png,image/webp">
                        <small class="text-muted">Opsional. JPG/PNG/WEBP, maks. 2 MB</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="role" class="form-label">Role *</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="">-- Pilih Role --</option>
                            <option value="admin" <?= old('role') == 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="kasir" <?= old('role') == 'kasir' ? 'selected' : '' ?>>Kasir</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="inisial" class="form-label">Inisial</label>
                        <input type="text" class="form-control" id="inisial" name="inisial" value="<?= old('inisial') ?>"
                            placeholder="Dipakai di Jadwal Karyawan">
                    </div>
                    <div class="mb-3">
                        <label for="divisi" class="form-label">Divisi</label>
                        <input type="text" class="form-control" id="divisi" name="divisi" value="<?= old('divisi') ?>">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                            <?= old('is_active') === null || old('is_active') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Status Aktif</label>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Role:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>Admin</strong> - Akses penuh (kasir, produk, laporan, manajemen user)</li>
                            <li><strong>Kasir</strong> - Akses terbatas (kasir, transaksi, tagihan)</li>
                        </ul>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-success">
                <i class="fas fa-save"></i> Simpan
            </button>
            <a href="<?= base_url('/user-management') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>