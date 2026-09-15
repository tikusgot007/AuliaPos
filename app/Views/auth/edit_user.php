<div class="card">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fas fa-user-edit"></i> Edit User</h5>
    </div>
    <div class="card-body">
        <form action="<?= base_url('/auth/update-user/' . $user['id']) ?>" method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-3 text-center mb-3">
                    <?php if (!empty($user['profile_photo'])): ?>
                        <img src="<?= base_url('/foto-profil/' . $user['profile_photo']) ?>" alt="Foto profil"
                            class="rounded-circle mb-2" style="width: 100px; height: 100px; object-fit: cover; border: 2px solid #e9ecef;">
                        <div class="form-check d-flex justify-content-center align-items-center gap-2 mb-2">
                            <input type="checkbox" class="form-check-input" id="hapus_foto" name="hapus_foto" value="1">
                            <label class="form-check-label" for="hapus_foto">Hapus foto</label>
                        </div>
                    <?php else: ?>
                        <div class="rounded-circle mb-2 mx-auto d-flex align-items-center justify-content-center"
                            style="width: 100px; height: 100px; background: #0d6efd; color: #fff; font-size: 2rem; font-weight: 700;">
                            <?= strtoupper(substr($user['nama'] ?: $user['username'], 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control form-control-sm" name="foto" accept="image/jpeg,image/png,image/webp">
                    <small class="text-muted">Ganti foto (opsional)</small>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username *</label>
                        <input type="text" class="form-control" id="username" name="username"
                            value="<?= old('username') ?? $user['username'] ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password (kosongkan jika tidak diubah)</label>
                        <input type="password" class="form-control" id="password" name="password">
                        <small class="text-muted">Minimal 6 karakter jika diisi</small>
                    </div>
                    <div class="mb-3">
                        <label for="nama" class="form-label">Nama</label>
                        <input type="text" class="form-control" id="nama" name="nama" value="<?= old('nama') ?? $user['nama'] ?? '' ?>">
                    </div>
                    <div class="mb-3">
                        <label for="no_hp" class="form-label">No. HP</label>
                        <input type="text" class="form-control" id="no_hp" name="no_hp" value="<?= old('no_hp') ?? $user['no_hp'] ?? '' ?>">
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="mb-3">
                        <label for="role" class="form-label">Role *</label>
                        <select class="form-control" id="role" name="role" required>
                            <option value="">-- Pilih Role --</option>
                            <option value="admin" <?= (old('role') ?? $user['role']) == 'admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="kasir" <?= (old('role') ?? $user['role']) == 'kasir' ? 'selected' : '' ?>>Kasir</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="inisial" class="form-label">Inisial</label>
                        <input type="text" class="form-control" id="inisial" name="inisial"
                            value="<?= old('inisial') ?? $user['inisial'] ?? '' ?>" placeholder="Dipakai di Jadwal Karyawan">
                    </div>
                    <div class="mb-3">
                        <label for="divisi" class="form-label">Divisi</label>
                        <input type="text" class="form-control" id="divisi" name="divisi" value="<?= old('divisi') ?? $user['divisi'] ?? '' ?>">
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                            <?= (old('is_active') ?? $user['is_active']) ? 'checked' : '' ?>
                            <?= (int) $user['id'] === (int) session()->get('id_user') ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="is_active">Status Aktif</label>
                        <?php if ((int) $user['id'] === (int) session()->get('id_user')): ?>
                            <input type="hidden" name="is_active" value="1">
                            <small class="text-muted d-block">Tidak bisa menonaktifkan akun sendiri.</small>
                        <?php endif; ?>
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

            <button type="submit" class="btn btn-warning">
                <i class="fas fa-save"></i> Update
            </button>
            <a href="<?= base_url('/user-management') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>