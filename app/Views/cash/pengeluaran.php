<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Kas Keluar (Pengeluaran)</h1>
        <div>
            <button type="button" class="btn btn-primary" id="btnTambahPengeluaran">
                <i class="fas fa-plus"></i> Tambah Pengeluaran
            </button>
            <a href="<?= base_url('/cash') ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Total Pengeluaran -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Pengeluaran</h5>
                    <h2 class="display-4">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></h2>
                    <small>Periode: <?= date('d/m/Y', strtotime($tanggal_awal)) ?> - <?= date('d/m/Y', strtotime($tanggal_akhir)) ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="form-inline">
                <div class="form-group mr-2">
                    <label for="tanggal_awal" class="mr-1">Dari</label>
                    <input type="date" class="form-control" id="tanggal_awal" name="tanggal_awal" value="<?= $tanggal_awal ?>">
                </div>
                <div class="form-group mr-2">
                    <label for="tanggal_akhir" class="mr-1">Sampai</label>
                    <input type="date" class="form-control" id="tanggal_akhir" name="tanggal_akhir" value="<?= $tanggal_akhir ?>">
                </div>
                <div class="form-group mr-2">
                    <label for="kategori" class="mr-1">Kategori</label>
                    <select class="form-control" id="kategori" name="kategori">
                        <option value="">Semua</option>
                        <?php foreach ($kategori_list as $kat): ?>
                            <option value="<?= $kat ?>" <?= $kategori_filter === $kat ? 'selected' : '' ?>>
                                <?= ucfirst(str_replace('_', ' ', $kat)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="<?= base_url('/cash/pengeluaran') ?>" class="btn btn-secondary ml-2">Reset</a>
            </form>
        </div>
    </div>

    <!-- Tabel Pengeluaran -->
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Nominal</th>
                        <th>Keterangan</th>
                        <th>Penerima</th>
                        <th>Dicatat Oleh</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pengeluaran as $row): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($row['tanggal'])) ?></td>
                            <td><span class="badge badge-warning"><?= ucfirst(str_replace('_', ' ', $row['kategori'])) ?></span></td>
                            <td class="text-danger"><strong>Rp <?= number_format($row['nominal'], 0, ',', '.') ?></strong></td>
                            <td><?= esc($row['keterangan']) ?></td>
                            <td><?= esc($row['penerima'] ?? '-') ?></td>
                            <td><?= esc($row['user_nama'] ?? '-') ?></td>
                            <td>
                                <button class="btn btn-sm btn-warning btn-edit" data-id="<?= $row['id'] ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger btn-hapus" data-id="<?= $row['id'] ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pengeluaran)): ?>
                        <tr>
                            <td colspan="7" class="text-center">Belum ada pengeluaran.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL TAMBAH / EDIT PENGELUARAN -->
<!-- ========================================== -->
<div class="modal fade" id="modalPengeluaran" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPengeluaranLabel">
                    <i class="fas fa-money-bill-wave"></i> <span id="formPengeluaranTitle">Tambah Pengeluaran</span>
                </h5>
                <button type="button" class="close" id="btnCloseModalPengeluaran">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="formPengeluaran">
                    <?= csrf_field() ?>
                    <input type="hidden" id="pengeluaranId" name="id" value="0">

                    <div class="form-group">
                        <label for="pengeluaranTanggal">Tanggal <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" id="pengeluaranTanggal" name="tanggal" required>
                    </div>

                    <div class="form-group">
                        <label for="pengeluaranNominal">Nominal <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">Rp</span>
                            </div>
                            <input type="number" class="form-control" id="pengeluaranNominal" name="nominal" step="100" min="0" required placeholder="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="pengeluaranKeterangan">Keterangan <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="pengeluaranKeterangan" name="keterangan" required placeholder="Contoh: Beli ATK, Bayar Listrik, dll">
                    </div>

                    <div class="form-group">
                        <label for="pengeluaranPenerima">Penerima (Opsional)</label>
                        <input type="text" class="form-control" id="pengeluaranPenerima" name="penerima" placeholder="Nama penerima uang">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="btnBatalModalPengeluaran">Batal</button>
                <button type="button" class="btn btn-success" id="btnSimpanPengeluaran">
                    <i class="fas fa-save"></i> Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- JAVASCRIPT -->
<!-- ========================================== -->
<script>
    $(document).ready(function() {
        var editId = 0;
        var isEdit = false;

        // ==========================================
        // FUNGSI TUTUP MODAL
        // ==========================================
        function closeModalPengeluaran() {
            var modal = document.getElementById('modalPengeluaran');
            if (typeof $(modal).modal === 'function') {
                $(modal).modal('hide');
            } else {
                modal.style.display = 'none';
                modal.classList.remove('show');
                document.body.classList.remove('modal-open');
                var backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) backdrop.remove();
            }
        }

        // ==========================================
        // BUKA MODAL TAMBAH
        // ==========================================
        $('#btnTambahPengeluaran').on('click', function() {
            isEdit = false;
            editId = 0;
            $('#formPengeluaranTitle').text('Tambah Pengeluaran');
            $('#pengeluaranId').val(0);
            $('#formPengeluaran')[0].reset();
            $('#pengeluaranTanggal').val(getLocalDateTime());
            $('#btnSimpanPengeluaran').html('<i class="fas fa-save"></i> Simpan');

            // Tampilkan modal
            if (typeof $('#modalPengeluaran').modal === 'function') {
                $('#modalPengeluaran').modal('show');
            } else {
                document.getElementById('modalPengeluaran').style.display = 'block';
                document.getElementById('modalPengeluaran').classList.add('show');
                document.body.classList.add('modal-open');
            }
        });

        function getLocalDateTime() {
            const now = new Date();

            const offset = now.getTimezoneOffset() * 60000;
            const localDate = new Date(now.getTime() - offset);

            return localDate.toISOString().slice(0, 16);
        }

        $('#pengeluaranTanggal').val(getLocalDateTime());
        // ==========================================
        // BUKA MODAL EDIT
        // ==========================================
        $(document).on('click', '.btn-edit', function() {
            var id = $(this).data('id');
            isEdit = true;
            editId = id;

            // Ambil data via AJAX
            $.ajax({
                url: '<?= base_url('/cash/edit-pengeluaran') ?>/' + id,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        var data = response.data;
                        $('#formPengeluaranTitle').text('Edit Pengeluaran');
                        $('#pengeluaranId').val(data.id);

                        // 🔥 Format tanggal untuk input datetime-local
                        var tanggal = data.tanggal.replace(' ', 'T');
                        $('#pengeluaranTanggal').val(tanggal.slice(0, 16));

                        // 🔥 Pastikan nominal terisi dengan benar
                        var nominal = parseFloat(data.nominal) || 0;
                        $('#pengeluaranNominal').val(nominal);

                        $('#pengeluaranKeterangan').val(data.keterangan || '');
                        $('#pengeluaranPenerima').val(data.penerima || '');
                        $('#btnSimpanPengeluaran').html('<i class="fas fa-edit"></i> Update');

                        // Tampilkan modal
                        if (typeof $('#modalPengeluaran').modal === 'function') {
                            $('#modalPengeluaran').modal('show');
                        } else {
                            document.getElementById('modalPengeluaran').style.display = 'block';
                            document.getElementById('modalPengeluaran').classList.add('show');
                            document.body.classList.add('modal-open');
                        }
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function(xhr) {
                    var msg = 'Gagal mengambil data. ';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        msg += resp.message || '';
                    } catch (e) {
                        msg += xhr.statusText;
                    }
                    alert('❌ ' + msg);
                }
            });
        });


        // ==========================================
        // SIMPAN PENGELUARAN
        // ==========================================
        $('#btnSimpanPengeluaran').on('click', function() {
            var data = {
                tanggal: $('#pengeluaranTanggal').val(),
                nominal: parseFloat($('#pengeluaranNominal').val()) || 0,
                keterangan: $('#pengeluaranKeterangan').val().trim(),
                penerima: $('#pengeluaranPenerima').val().trim(),
            };

            if (data.nominal <= 0) {
                alert('Masukkan nominal yang valid.');
                return;
            }
            if (data.keterangan === '') {
                alert('Keterangan wajib diisi.');
                return;
            }

            var url = isEdit ? '<?= base_url('/cash/edit-pengeluaran') ?>/' + editId : '<?= base_url('/cash/tambah-pengeluaran') ?>';
            var btn = $('#btnSimpanPengeluaran');
            btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Menyimpan...');

            $.ajax({
                url: url,
                type: isEdit ? 'POST' : 'POST',
                data: JSON.stringify(data),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        alert('✅ ' + response.message);
                        closeModalPengeluaran();
                        location.reload();
                    } else {
                        alert('❌ ' + response.message);
                        btn.prop('disabled', false).html(isEdit ? '<i class="fas fa-edit"></i> Update' : '<i class="fas fa-save"></i> Simpan');
                    }
                },
                error: function(xhr) {
                    var msg = 'Terjadi kesalahan. ';
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        msg += resp.message || '';
                    } catch (e) {
                        msg += xhr.statusText;
                    }
                    alert('❌ ' + msg);
                    btn.prop('disabled', false).html(isEdit ? '<i class="fas fa-edit"></i> Update' : '<i class="fas fa-save"></i> Simpan');
                }
            });
        });

        // ==========================================
        // HAPUS PENGELUARAN
        // ==========================================
        $(document).on('click', '.btn-hapus', async function() {
            var id = $(this).data('id');
            if (!(await konfirmasi('Yakin ingin menghapus pengeluaran ini?', { okText: 'Ya, Hapus' }))) return;

            $.ajax({
                url: '<?= base_url('/cash/hapus-pengeluaran') ?>/' + id,
                type: 'DELETE',
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        alert('✅ ' + response.message);
                        location.reload();
                    } else {
                        alert('❌ ' + response.message);
                    }
                },
                error: function() {
                    alert('Gagal menghapus data.');
                }
            });
        });

        // ==========================================
        // TUTUP MODAL
        // ==========================================
        $('#btnCloseModalPengeluaran, #btnBatalModalPengeluaran').on('click', function() {
            closeModalPengeluaran();
        });

        // ==========================================
        // ENTER KEY UNTUK SUBMIT
        // ==========================================
        $('#pengeluaranNominal, #pengeluaranKeterangan, #pengeluaranPenerima').keypress(function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#btnSimpanPengeluaran').click();
            }
        });
    });
</script>