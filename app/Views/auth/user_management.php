<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-users-cog"></i> Manajemen User</h5>
        <a href="<?= base_url('/auth/tambah-user') ?>" class="btn btn-light btn-sm">
            <i class="fas fa-plus"></i> Tambah User
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>#</th>
                        <th></th>
                        <th>Username</th>
                        <th>Nama</th>
                        <th>Divisi</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users)): ?>
                        <?php foreach ($users as $i => $u): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <?php if (!empty($u['profile_photo'])): ?>
                                        <img src="<?= base_url('/foto-profil/' . $u['profile_photo']) ?>" alt=""
                                            class="rounded-circle" style="width: 32px; height: 32px; object-fit: cover;">
                                    <?php else: ?>
                                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center"
                                            style="width: 32px; height: 32px; background: #0d6efd; color: #fff; font-size: 0.75rem; font-weight: 700;">
                                            <?= strtoupper(substr($u['nama'] ?: $u['username'], 0, 1)) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= esc($u['username']) ?></strong></td>
                                <td><?= esc($u['nama'] ?? '-') ?></td>
                                <td><?= esc($u['divisi'] ?? '-') ?></td>
                                <td>
                                    <span class="badge <?= $u['role'] == 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                                        <?= strtoupper($u['role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= (int) $u['is_active'] === 1 ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= (int) $u['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?>
                                    </span>
                                </td>
                                <td><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></td>
                                <td>
                                    <a href="<?= base_url('/auth/edit-user/' . $u['id']) ?>" class="btn btn-sm btn-warning">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($u['id'] != session()->get('id_user')): ?>
                                        <a href="<?= base_url('/auth/hapus-user/' . $u['id']) ?>" class="btn btn-sm btn-danger"
                                            data-confirm-message="Yakin ingin menghapus user ini?" data-confirm-ok-text="Ya, Hapus">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center">Belum ada user.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>