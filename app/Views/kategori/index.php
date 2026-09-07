<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-tags"></i> Daftar Kategori</h5>
        <a href="<?= base_url('/kategori/tambah') ?>" class="btn btn-light btn-sm">
            <i class="fas fa-plus"></i> Tambah Kategori
        </a>
    </div>
    <div class="card-body">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama Kategori</th>
                    <th>Induk</th>
                    <th>Level</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($kategori)): ?>
                    <?php
                    $no = 1;
                    $indukNama = [];
                    foreach ($kategori as $k) {
                        if ($k['parent_id'] === null) {
                            $indukNama[$k['id']] = '-';
                        }
                    }
                    foreach ($kategori as $k):
                        // Cari nama induk
                        $namaInduk = '-';
                        if ($k['parent_id'] !== null) {
                            foreach ($kategori as $induk) {
                                if ($induk['id'] == $k['parent_id']) {
                                    $namaInduk = $induk['nama'];
                                    break;
                                }
                            }
                        }
                        $level = $k['parent_id'] === null ? 'Induk' : 'Anak';
                    ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= $k['nama'] ?></td>
                            <td><?= $namaInduk ?></td>
                            <td><span class="badge <?= $level == 'Induk' ? 'bg-primary' : 'bg-secondary' ?>"><?= $level ?></span></td>
                            <td>
                                <a href="<?= base_url('/kategori/edit/' . $k['id']) ?>" class="btn btn-sm btn-warning">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= base_url('/kategori/hapus/' . $k['id']) ?>" class="btn btn-sm btn-danger"
                                    data-confirm-message="Yakin ingin menghapus kategori ini?" data-confirm-ok-text="Ya, Hapus">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">Belum ada kategori.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>