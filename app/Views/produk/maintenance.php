<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1"><i class="bi bi-tools"></i> Maintenance Master Barang</h4>
            <small class="text-muted">Khusus admin. Rapikan data produk secara berkala tanpa mengorbankan histori transaksi.</small>
        </div>
        <a href="<?= base_url('produk') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Kembali ke Produk
        </a>
    </div>

    <!-- LANGKAH-LANGKAH -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h6 class="mb-3">Alur kerja:</h6>
            <ol class="mb-0 ps-3">
                <li>Download CSV audit di bawah — berisi semua produk beserta statistik pemakaiannya.</li>
                <li>Buka di Excel, isi kolom <strong>Aksi</strong> di baris yang perlu diubah: <code>NONAKTIF</code>, <code>HAPUS</code>, <code>PERTAHANKAN</code>, atau kosongkan (sama artinya dengan PERTAHANKAN — field lain tetap boleh diubah).</li>
                <li>Untuk barang baru: tambah baris baru, kosongkan kolom <strong>ID</strong>, isi <strong>Aksi = INSERT</strong>.</li>
                <li><strong>Simpan sebagai CSV</strong> (bukan .xlsx) sebelum upload di sini.</li>
                <li>Upload → cek ringkasan &amp; rincian per baris → baru klik "Konfirmasi &amp; Jalankan" kalau sudah yakin.</li>
            </ol>
        </div>
    </div>

    <!-- STEP 1: EXPORT -->
    <div class="card shadow-sm mb-3">
        <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h6 class="mb-1">1. Download Data Audit</h6>
                <small class="text-muted">CSV berisi seluruh produk + statistik pemakaian dari histori transaksi.</small>
            </div>
            <a href="<?= base_url('produk/export-audit') ?>" class="btn btn-success">
                <i class="bi bi-download"></i> Download CSV Audit
            </a>
        </div>
    </div>

    <!-- STEP 2: UPLOAD -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <h6 class="mb-2">2. Upload CSV yang Sudah Ditandai</h6>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <input type="file" id="fileCsv" accept=".csv" class="form-control" style="max-width: 400px;">
                <button type="button" class="btn btn-primary" id="btnPreview">
                    <i class="bi bi-eye"></i> Preview Perubahan
                </button>
            </div>
            <div class="mt-2" id="previewLoading" style="display: none;">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <small class="text-muted">Memvalidasi setiap baris ke database...</small>
            </div>
        </div>
    </div>

    <!-- STEP 3: RINGKASAN + PREVIEW -->
    <div class="card shadow-sm mb-3" id="cardRingkasan" style="display: none;">
        <div class="card-body">
            <h6 class="mb-3">3. Ringkasan Perubahan</h6>

            <div class="row g-2 mb-3" id="ringkasanBadges"></div>

            <div id="blokirInfo" class="alert alert-warning py-2" style="display: none;"></div>

            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-sm table-hover" id="tabelPreview">
                    <thead class="table-light" style="position: sticky; top: 0;">
                        <tr>
                            <th>#</th>
                            <th>ID</th>
                            <th>Nama</th>
                            <th>Aksi</th>
                            <th>Status</th>
                            <th>Keterangan</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                <small class="text-muted">
                    Backup otomatis dibuat sebelum eksekusi. Baris berstatus <span class="badge bg-warning text-dark">Diblokir</span>
                    atau <span class="badge bg-danger">Error</span> tidak akan dieksekusi.
                </small>
                <button type="button" class="btn btn-danger" id="btnEksekusi">
                    <i class="bi bi-check-circle"></i> Konfirmasi &amp; Jalankan
                </button>
            </div>
        </div>
    </div>

    <!-- STEP 4: HASIL EKSEKUSI -->
    <div class="card shadow-sm mb-3" id="cardHasil" style="display: none;">
        <div class="card-body">
            <h6 class="mb-3">4. Hasil Eksekusi</h6>
            <div id="hasilEksekusiBody"></div>
        </div>
    </div>

</div>

<?= $this->section('scripts') ?>
<script>
    let currentToken = null;

    document.getElementById('btnPreview').addEventListener('click', function() {
        const fileInput = document.getElementById('fileCsv');
        const file = fileInput.files[0];

        if (!file) {
            showToast('Pilih file CSV terlebih dahulu.', 'warning');
            return;
        }

        const formData = new FormData();
        formData.append('file_csv', file);

        document.getElementById('previewLoading').style.display = 'block';
        document.getElementById('cardRingkasan').style.display = 'none';
        document.getElementById('cardHasil').style.display = 'none';
        currentToken = null;

        $.ajax({
            url: '<?= base_url('produk/preview-import') ?>',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(response) {
                document.getElementById('previewLoading').style.display = 'none';

                if (response.status !== 'success') {
                    showToast('❌ ' + (response.message || 'Gagal memproses CSV.'), 'danger');
                    return;
                }

                currentToken = response.token;
                tampilkanRingkasan(response.ringkasan);
                tampilkanTabelPreview(response.baris);

                document.getElementById('cardRingkasan').style.display = 'block';
                showToast('✅ Preview berhasil dibuat. Periksa sebelum konfirmasi.', 'success');
            },
            error: function(xhr) {
                document.getElementById('previewLoading').style.display = 'none';
                const message = xhr.responseJSON?.message || 'Gagal memproses CSV.';
                showToast('❌ ' + message, 'danger');
            }
        });
    });

    function tampilkanRingkasan(r) {
        const badges = [{
                label: 'Total Baris',
                value: r.total_baris,
                class: 'bg-secondary'
            },
            {
                label: 'Akan Update',
                value: r.akan_update,
                class: 'bg-primary'
            },
            {
                label: 'Akan Nonaktif',
                value: r.akan_nonaktif,
                class: 'bg-warning text-dark'
            },
            {
                label: 'Akan Dihapus',
                value: r.akan_hapus,
                class: 'bg-danger'
            },
            {
                label: 'Akan Ditambahkan',
                value: r.akan_insert,
                class: 'bg-success'
            },
            {
                label: 'Diblokir',
                value: r.diblokir,
                class: 'bg-warning text-dark'
            },
            {
                label: 'Error',
                value: r.error,
                class: 'bg-danger'
            },
        ];

        const container = document.getElementById('ringkasanBadges');
        container.innerHTML = badges.map(b => `
            <div class="col-auto">
                <div class="border rounded px-3 py-2 text-center">
                    <div class="fs-5 fw-bold">${b.value}</div>
                    <small class="text-muted">${b.label}</small>
                </div>
            </div>
        `).join('');

        const blokirInfo = document.getElementById('blokirInfo');
        if (r.diblokir > 0) {
            blokirInfo.style.display = 'block';
            blokirInfo.innerHTML = `
                <i class="bi bi-exclamation-triangle"></i>
                <strong>${r.diblokir} baris ditandai HAPUS tapi diblokir sistem:</strong>
                Pernah dipakai (${r.blokir_pernah_dipakai}),
                Terkunci/is_locked (${r.blokir_locked}),
                ID khusus Banner/Manual/Custom (${r.blokir_id_khusus}),
                Tidak ditemukan (${r.blokir_tidak_ditemukan}).
            `;
        } else {
            blokirInfo.style.display = 'none';
        }
    }

    function tampilkanTabelPreview(baris) {
        const statusBadge = {
            ok: '<span class="badge bg-success">OK</span>',
            blocked: '<span class="badge bg-warning text-dark">Diblokir</span>',
            error: '<span class="badge bg-danger">Error</span>'
        };

        const tbody = document.querySelector('#tabelPreview tbody');
        tbody.innerHTML = baris.map(b => `
            <tr>
                <td>${b.baris_ke}</td>
                <td>${b.id ?? '<em class="text-muted">baru</em>'}</td>
                <td>${escapeHtmlMaintenance(b.nama)}</td>
                <td>${b.aksi || '-'}</td>
                <td>${statusBadge[b.status] || b.status}</td>
                <td class="small">${escapeHtmlMaintenance(b.keterangan)}</td>
            </tr>
        `).join('');
    }

    function escapeHtmlMaintenance(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    document.getElementById('btnEksekusi').addEventListener('click', async function() {
        if (!currentToken) {
            showToast('Belum ada hasil preview. Upload ulang CSV.', 'warning');
            return;
        }

        if (!(await konfirmasi('Backup otomatis akan dibuat, lalu perubahan dijalankan. Lanjutkan?', { okText: 'Ya, Lanjutkan', okClass: 'btn-primary' }))) {
            return;
        }

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Memproses...';

        $.ajax({
            url: '<?= base_url('produk/eksekusi-import') ?>',
            type: 'POST',
            data: {
                token: currentToken
            },
            dataType: 'json',
            success: function(response) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Konfirmasi & Jalankan';

                if (response.status !== 'success') {
                    showToast('❌ ' + (response.message || 'Gagal menjalankan import.'), 'danger');
                    tampilkanHasilError(response);
                    return;
                }

                showToast('✅ Import berhasil dijalankan.', 'success');
                tampilkanHasilSukses(response);
                currentToken = null;
            },
            error: function(xhr) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle"></i> Konfirmasi & Jalankan';
                const message = xhr.responseJSON?.message || 'Gagal menjalankan import.';
                showToast('❌ ' + message, 'danger');
            }
        });
    });

    function tampilkanHasilSukses(response) {
        const e = response.eksekusi;
        document.getElementById('hasilEksekusiBody').innerHTML = `
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> ${escapeHtmlMaintenance(response.message)}
            </div>
            <ul class="mb-2">
                <li>${e.update} produk di-update</li>
                <li>${e.nonaktif} produk dinonaktifkan</li>
                <li>${e.hapus} produk dihapus</li>
                <li>${e.insert} produk baru ditambahkan</li>
            </ul>
            <small class="text-muted">Backup tersimpan di server: <code>${escapeHtmlMaintenance(response.backup)}</code></small>
        `;
        document.getElementById('cardHasil').style.display = 'block';
    }

    function tampilkanHasilError(response) {
        document.getElementById('hasilEksekusiBody').innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-x-circle"></i> ${escapeHtmlMaintenance(response.message)}
            </div>
            ${response.backup ? `<small class="text-muted">Backup tetap dibuat sebelum error: <code>${escapeHtmlMaintenance(response.backup)}</code></small>` : ''}
        `;
        document.getElementById('cardHasil').style.display = 'block';
    }
</script>
<?= $this->endSection() ?>