<div class="card">
    <div class="card-header bg-warning text-white">
        <h5 class="mb-0"><i class="fas fa-key"></i> Ganti Password</h5>
    </div>
    <div class="card-body">
        <form action="<?= base_url('/auth/proses-ganti-password') ?>" method="post">
            <?= csrf_field() ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="password_lama" class="form-label">Password Lama *</label>
                        <input type="password" class="form-control" id="password_lama" name="password_lama" required>
                    </div>
                    <div class="mb-3">
                        <label for="password_baru" class="form-label">Password Baru *</label>
                        <input type="password" class="form-control" id="password_baru" name="password_baru" required>
                        <small class="text-muted">Minimal 6 karakter</small>
                    </div>
                    <div class="mb-3">
                        <label for="konfirmasi_password" class="form-label">Konfirmasi Password Baru *</label>
                        <input type="password" class="form-control" id="konfirmasi_password" name="konfirmasi_password" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Tips Keamanan:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Gunakan kombinasi huruf besar, huruf kecil, angka, dan simbol</li>
                            <li>Jangan gunakan password yang sama dengan akun lain</li>
                            <li>Setelah ganti password, Anda akan logout dan harus login ulang</li>
                        </ul>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-warning">
                <i class="fas fa-save"></i> Ganti Password
            </button>
            <a href="<?= base_url('/kasir') ?>" class="btn btn-secondary">Batal</a>
        </form>
    </div>
</div>