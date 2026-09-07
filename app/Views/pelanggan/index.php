<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-users"></i> Daftar Pelanggan</h5>
        <a href="<?= base_url('/pelanggan/tambah') ?>" class="btn btn-light btn-sm">
            <i class="fas fa-plus"></i> Tambah Pelanggan
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-bordered" id="tablePelanggan">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama</th>
                        <th>No. HP</th>
                        <th>Alamat</th>
                        <th>Diskon</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pelanggan)): ?>
                        <?php foreach ($pelanggan as $i => $p): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= esc($p['nama']) ?></td>
                                <td><?= $p['no_hp'] ?? '-' ?></td>
                                <td><?= $p['alamat'] ?? '-' ?></td>
                                <td>
                                    <?php if ($p['diskon'] > 0): ?>
                                        <span class="badge bg-success"><?= $p['diskon'] ?>%</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">0%</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= base_url('/pelanggan/edit/' . $p['id']) ?>" class="btn btn-sm btn-warning">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="<?= base_url('/pelanggan/hapus/' . $p['id']) ?>" class="btn btn-sm btn-danger"
                                        data-confirm-message="Yakin ingin menghapus pelanggan ini?" data-confirm-ok-text="Ya, Hapus">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center">Belum ada pelanggan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= $this->section('scripts') ?>
<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    $(document).ready(function() {
        $('#tablePelanggan').DataTable({
            responsive: true,
            pageLength: 25,
            language: {
                search: "Cari:",
                lengthMenu: "Tampilkan _MENU_ data per halaman",
                zeroRecords: "Data tidak ditemukan",
                info: "Menampilkan _START_ - _END_ dari _TOTAL_ data",
                infoEmpty: "Tidak ada data",
                infoFiltered: "(difilter dari _MAX_ total data)",
                paginate: {
                    first: "Pertama",
                    last: "Terakhir",
                    next: "→",
                    previous: "←"
                }
            }
        });
    });
</script>
<?= $this->endSection() ?>