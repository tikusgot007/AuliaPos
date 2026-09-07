<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-id-card"></i> Profil Saya</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <!-- Foto Profil -->
            <div class="col-md-3 text-center mb-4">
                <?php if (!empty($user['profile_photo'])): ?>
                    <img src="<?= base_url('/foto-profil/' . $user['profile_photo']) ?>"
                        alt="Foto profil"
                        class="rounded-circle mb-3"
                        style="width: 140px; height: 140px; object-fit: cover; border: 3px solid #e9ecef;">
                <?php else: ?>
                    <div class="rounded-circle mb-3 mx-auto d-flex align-items-center justify-content-center"
                        style="width: 140px; height: 140px; background: #0d6efd; color: #fff; font-size: 2.5rem; font-weight: 700;">
                        <?= esc(strtoupper(substr($user['nama'] ?? $user['username'], 0, 1))) ?>
                    </div>
                <?php endif; ?>

                <form action="<?= base_url('/profil/foto/upload') ?>" method="post" enctype="multipart/form-data" class="mb-2">
                    <?= csrf_field() ?>
                    <input type="file" name="foto" id="fotoInput" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm mb-2" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                        <i class="fas fa-upload"></i> <?= !empty($user['profile_photo']) ? 'Ganti Foto' : 'Upload Foto' ?>
                    </button>
                </form>

                <?php if (!empty($user['profile_photo'])): ?>
                    <form action="<?= base_url('/profil/foto/hapus') ?>" method="post"
                        data-confirm-message="Hapus foto profil? Avatar akan kembali ke inisial default." data-confirm-ok-text="Ya, Hapus">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                            <i class="fas fa-trash"></i> Hapus Foto
                        </button>
                    </form>
                <?php endif; ?>

                <small class="text-muted d-block mt-2">JPG/PNG/WEBP, maks. 2 MB</small>
            </div>

            <!-- Data Profil -->
            <div class="col-md-9">
                <form action="<?= base_url('/profil/update') ?>" method="post">
                    <?= csrf_field() ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="nama" class="form-label">Nama *</label>
                                <input type="text" class="form-control" id="nama" name="nama"
                                    value="<?= old('nama') ?? esc($user['nama'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="no_hp" class="form-label">No. HP</label>
                                <input type="text" class="form-control" id="no_hp" name="no_hp"
                                    value="<?= old('no_hp') ?? esc($user['no_hp'] ?? '') ?>"
                                    placeholder="Contoh: 08123456789">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-control" value="<?= esc($user['username']) ?>" disabled>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Inisial</label>
                                <input type="text" class="form-control" value="<?= esc($user['inisial'] ?? '-') ?>" disabled>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Divisi</label>
                                <input type="text" class="form-control" value="<?= esc($user['divisi'] ?? '-') ?>" disabled>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <label class="form-label">Role</label>
                                    <input type="text" class="form-control" value="<?= esc(strtoupper($user['role'])) ?>" disabled>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">Status</label>
                                    <input type="text" class="form-control"
                                        value="<?= (int) $user['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>" disabled>
                                </div>
                            </div>
                        </div>
                    </div>

                    <small class="text-muted d-block mb-3">
                        <i class="fas fa-info-circle"></i>
                        Username, inisial, divisi, role, dan status aktif hanya bisa diubah oleh admin.
                    </small>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
