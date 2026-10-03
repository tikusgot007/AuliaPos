<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-comment-dots"></i> Template Balasan</h5>
        <a href="<?= base_url('/balasan-template/tambah') ?>" class="btn btn-light btn-sm">
            <i class="fas fa-plus"></i> Tambah Template
        </a>
    </div>
    <div class="card-body">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nama</th>
                    <th>Gambar</th>
                    <th>Teks</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($template)): ?>
                    <?php $no = 1; foreach ($template as $t): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><?= $t['nama'] ?></td>
                            <td>
                                <?php if ($t['gambar_filename']): ?>
                                    <img src="<?= base_url('/balasan-template/foto/' . $t['gambar_filename']) ?>"
                                        alt="<?= $t['nama'] ?>" style="max-width:60px;max-height:60px;object-fit:cover;">
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $t['teks'] ? nl2br($t['teks']) : '<span class="text-muted">-</span>' ?></td>
                            <td>
                                <a href="<?= base_url('/balasan-template/edit/' . $t['id']) ?>" class="btn btn-sm btn-warning">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= base_url('/balasan-template/hapus/' . $t['id']) ?>" class="btn btn-sm btn-danger"
                                    data-confirm-message="Yakin ingin menghapus template balasan ini?" data-confirm-ok-text="Ya, Hapus">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center">Belum ada template balasan.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
