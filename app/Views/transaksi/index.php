<?php
// Effective Shift Leader saat ini. Nilai ini berlaku untuk SELURUH baris
// tabel (sama untuk semua baris dalam satu render) -- tidak per baris.
// Backend (TransaksiModel::ubahStatus) tetap sumber kebenaran validasi;
// pengecekan di sini murni untuk tampilan, dikirim ke JS lewat
// window.paymentModalConfig.isShiftLeader (lihat blok konfigurasi di bawah).
$isShiftLeaderUser = \App\Services\Authority::isCurrentShiftLeader((int) session()->get('id_user'));
?>
<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Daftar Transaksi</h5>
        <a href="<?= base_url('/kasir') ?>" class="btn btn-light btn-sm">
            <i class="fas fa-plus"></i> Transaksi Baru
        </a>
    </div>
    <div class="card-body">
        <!-- ========================================== -->
        <!-- 🔥 FILTER                                 -->
        <!-- ========================================== -->
        <div class="row g-3 mb-3">
            <!-- Filter Tanggal (Date Range Picker) -->
            <div class="col-md-3">
                <label class="form-label">Rentang Tanggal</label>
                <input type="text" class="form-control" id="filterTanggal"
                    placeholder="Pilih rentang tanggal"
                    value="<?= $tanggal_awal ?> - <?= $tanggal_akhir ?>">
                <input type="hidden" id="tanggalAwalHidden" value="<?= esc($tanggal_awal, 'attr') ?>">
                <input type="hidden" id="tanggalAkhirHidden" value="<?= esc($tanggal_akhir, 'attr') ?>">
            </div>

            <!-- Filter Status Transaksi -->
            <div class="col-md-2">
                <label class="form-label">Status Transaksi</label>
                <select
                    class="form-control"
                    id="filterStatusTransaksi">

                    <?php foreach ($status_transaksi_list as $st): ?>

                        <?php
                        $label = match ($st) {
                            '' => 'Semua',
                            'aktif' => 'Aktif',
                            'tidak_aktif' => 'Tidak Aktif',
                            default => ucfirst(
                                str_replace(
                                    '_',
                                    ' ',
                                    $st
                                )
                            ),
                        };
                        ?>

                        <option
                            value="<?= esc($st) ?>"
                            <?= ($status_transaksi === $st) ? 'selected' : '' ?>>

                            <?= esc($label) ?>

                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <!-- Filter Status Pembayaran -->
            <div class="col-md-2">
                <label class="form-label">Status Pembayaran</label>
                <select class="form-control" id="filterStatusPembayaran">

                    <?php foreach ($status_pembayaran_list as $sp): ?>

                        <?php
                        $labelSp = match ($sp) {
                            '' => 'Semua',
                            'belum_lunas' => 'Belum Lunas',
                            'lunas' => 'Lunas',
                            default => ucfirst(
                                str_replace(
                                    '_',
                                    ' ',
                                    $sp
                                )
                            ),
                        };
                        ?>

                        <option
                            value="<?= esc($sp) ?>"
                            <?= ($status_pembayaran === $sp) ? 'selected' : '' ?>>

                            <?= esc($labelSp) ?>

                        </option>

                    <?php endforeach; ?>

                </select>
            </div>

            <!-- Filter Karyawan -->
            <div class="col-md-3">
                <label class="form-label">Karyawan</label>
                <select class="form-control" id="filterKaryawan">
                    <option value="">Semua Karyawan</option>
                    <?php if (!empty($daftar_kasir)): ?>
                        <?php foreach ($daftar_kasir as $k): ?>
                            <option value="<?= $k['id'] ?>" <?= ($kasir_id_filter ?? '') == $k['id'] ? 'selected' : '' ?>>
                                <?= esc($k['inisial'] ?: ($k['nama'] ?? $k['username'])) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- Tombol Filter -->
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-primary me-2" onclick="applyFilter()">
                    <i class="fas fa-search"></i> Filter
                </button>
                <button type="button" class="btn btn-secondary" onclick="resetFilter()">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>

            <!--
                Nilai filter status "efektif" hasil parsing server. Dipakai
                supaya link lama dengan nilai individual (mis. ?status_transaksi=proses
                atau ?status_pembayaran=belum_bayar) tetap terfilter walau
                dropdown tidak punya opsi untuk nilai itu. Di-overwrite dari
                dropdown saat user menekan Filter / memilih rentang tanggal.
            -->
            <input type="hidden" id="statusTransaksiEfektif" value="<?= esc($status_transaksi, 'attr') ?>">
            <input type="hidden" id="statusPembayaranEfektif" value="<?= esc($status_pembayaran, 'attr') ?>">
        </div>

        <!-- ========================================== -->
        <!-- 🔥 KEYWORD SEARCH (jika ada)                -->
        <!-- ========================================== -->
        <?php if (!empty($keyword)): ?>
            <div class="alert alert-info mb-3">
                <i class="fas fa-search"></i>
                Hasil pencarian untuk: <strong>"<?= esc($keyword) ?>"</strong>
                <a href="<?= base_url('/transaksi') ?>" class="btn btn-sm btn-outline-secondary float-end">
                    <i class="fas fa-times"></i> Hapus Filter
                </a>
            </div>
        <?php endif; ?>

        <!-- ========================================== -->
        <!-- 🔥 TABEL TRANSAKSI                         -->
        <!-- ========================================== -->
        <div class="table-responsive">
            <table class="table table-striped table-bordered" id="tableTransaksi">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Invoice</th>
                        <th>No Order</th> <!-- 🔥 TAMBAHKAN KOLOM NO ORDER -->
                        <th>Tanggal</th>
                        <th>Pelanggan</th>
                        <th>Kasir</th>
                        <th>Total</th>
                        <th>Dibayar</th>
                        <th>Sisa</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Baris dirender oleh DataTables (server-side /transaksi/data). -->
                </tbody>
            </table>
        </div>

    </div>
</div>


<!-- ========================================== -->
<!-- PAYMENT MODAL CONTAINER (satu saja)        -->
<!-- ========================================== -->
<div id="paymentModalContainer"></div>

</div><!-- /.card-body -->
</div><!-- /.card -->

<!-- ========================================== -->
<!-- SCRIPTS SECTION                            -->
<!-- ========================================== -->
<?= $this->section('scripts') ?>

<script>
    // ================================================================
    // STATUS TRANSAKSI
    // ================================================================

    // Helper bersama: kirim perubahan status ke backend.
    // Backend (TransaksiModel::ubahStatus) adalah satu-satunya sumber
    // kebenaran validasi; fungsi ini hanya mengirim & menampilkan hasil.
    function kirimUbahStatusAjax(id, status) {
        $.ajax({
            url: '<?= base_url('/api/ubah-status') ?>',
            type: 'POST',
            data: JSON.stringify({
                id: id,
                status: status
            }),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    showToast(response.message || 'Status berhasil diubah.', 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 700);
                } else {
                    // Pesan dari backend (mis. alasan pembayaran belum
                    // lunas beserta sisa tagihan) selalu diprioritaskan
                    // di atas pesan generik.
                    showToast(response.message || 'Gagal mengubah status.', 'danger');
                }
            },
            error: function(xhr) {
                const message = xhr.responseJSON?.message || 'Gagal mengubah status transaksi.';
                showToast(message, 'danger');
            }
        });
    }

    // Dipakai untuk transisi selain SELESAI (mis. Batal). Behavior
    // tidak berubah dari sebelumnya.
    async function ubahStatus(id, status) {
        const label = status.toUpperCase();

        if (!(await konfirmasi('Ubah status transaksi menjadi ' + label + '?', {
                okText: 'Ya, Ubah'
            }))) {
            return;
        }

        kirimUbahStatusAjax(id, status);
    }

    // Khusus tombol "Tandai Selesai" (hanya tampil untuk admin).
    // statusPembayaran dikirim dari server (nilai saat halaman
    // dirender) semata-mata untuk memilih teks konfirmasi yang tepat.
    // Keputusan akhir tetap divalidasi ulang oleh backend.
    async function selesaikanTransaksi(id, statusPembayaran) {
        if (statusPembayaran === 'lunas') {
            const pesanKonfirmasi = 'Pembayaran sudah lunas.\n\n' +
                'Pastikan garapan benar-benar sudah selesai sebelum ' +
                'menandai transaksi sebagai SELESAI.\n\n' +
                'Lanjutkan menandai SELESAI?';

            if (!(await konfirmasi(pesanKonfirmasi, {
                    okText: 'Ya, Selesaikan',
                    okClass: 'btn-success'
                }))) {
                return;
            }
        }
        // Jika belum lunas, tidak ada yang perlu dikonfirmasi di sini —
        // backend akan menolak dan alasannya (termasuk sisa tagihan)
        // akan tampil lewat toast di bawah.

        kirimUbahStatusAjax(id, 'selesai');
    }
</script>

<!-- ========================================== -->
<!-- KONFIGURASI PAYMENT MODAL                   -->
<!-- ========================================== -->
<script>
    window.paymentModalConfig = {
        modalUrl: '<?= base_url('/api/modal/pembayaran') ?>',
        kasirTransactionUrl: '<?= base_url('/api/simpan-transaksi') ?>',
        existingPaymentUrl: '<?= base_url('/api/tambah-pembayaran') ?>',
        tagihanLunasiUrl: '<?= base_url('/tagihan/lunasi/:id') ?>',
        kasirListUrl: '<?= base_url('/api/kasir-list') ?>',
        isAdmin: <?= session()->get('role') === 'admin' ? 'true' : 'false' ?>,
        isShiftLeader: <?= $isShiftLeaderUser ? 'true' : 'false' ?>
    };
</script>
<script src="<?= base_url('assets/js/payment.js') ?>"></script>

<!-- ========================================== -->
<!-- FUNGSI UTAMA & HELPER                      -->
<!-- ========================================== -->
<script>
    /**
     * Fungsi untuk membuka modal pembayaran dan melakukan pembayaran tambahan.
     * Dipanggil dari tombol "Bayar" di tabel.
     */
    function bayarTransaksi(id, grandTotal, sisa, invoice) {
        if (sisa <= 0) {
            showToast('Transaksi ini sudah lunas.', 'warning');
            return;
        }

        bukaPaymentModal({
            mode: 'existing', // pembayaran tambahan untuk transaksi yang sudah ada
            transaksiId: id,
            total: sisa, // sisa tagihan yang harus dibayar
            invoice: invoice || 'Transaksi',
            onSuccess: function(response) {
                showToast('✅ Pembayaran berhasil!', 'success');
                location.reload();
            },
            onError: function(error) {
                showToast(error.message || 'Gagal memproses pembayaran.', 'danger');
            }
        }).catch(function(err) {
            showToast(err.message || 'Gagal membuka modal pembayaran.', 'danger');
        });
    }



    /**
     * Format Rupiah (jika diperlukan di tempat lain).
     */
    function formatRupiah(angka) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(angka);
    }
</script>

<!-- ========================================== -->
<!-- INIT DATATABLES & FILTER                   -->
<!-- ========================================== -->
<script>
    // ============================================================
    // DataTables server-side + filter
    // ============================================================
    $(document).ready(function() {

        // ---- Date range picker --------------------------------
        var startDate = '<?= $tanggal_awal ?>' || moment().startOf('month').format('YYYY-MM-DD');
        var endDate = '<?= $tanggal_akhir ?>' || moment().format('YYYY-MM-DD');

        $('#filterTanggal').daterangepicker({
            locale: AuliaDateRange.locale(),
            ranges: AuliaDateRange.ranges(),
            startDate: moment(startDate),
            endDate: moment(endDate),
            opens: 'left',
            showDropdowns: true
        });

        // ---- DataTables ---------------------------------------
        var tableTransaksi = $('#tableTransaksi').DataTable({
            responsive: true,
            processing: true,
            serverSide: true,
            // Pencarian memakai kotak search global di header (keyword) yang
            // mencakup live + arsip; kotak "Cari:" bawaan DataTables dimatikan
            // agar tidak memberi kesan filter yang tidak dijalankan server.
            searching: false,
            pageLength: 25,
            order: [
                [3, 'desc']
            ],
            ajax: {
                url: '<?= base_url('/transaksi/data') ?>',
                data: function(d) {
                    d.tanggal_awal = $('#tanggalAwalHidden').val() || '';
                    d.tanggal_akhir = $('#tanggalAkhirHidden').val() || '';
                    // Hidden "efektif" (bukan nilai dropdown) supaya nilai legacy
                    // dari URL tetap honored; syncFilterEfektif() mengisinya dari
                    // dropdown saat user berinteraksi.
                    d.status_pembayaran = $('#statusPembayaranEfektif').val();
                    d.status_transaksi = $('#statusTransaksiEfektif').val();
                    d.kasir_id = $('#filterKaryawan').val();
                    d.keyword = '<?= esc($keyword, 'js') ?>';
                }
            },
            columnDefs: [{
                orderable: false,
                targets: [0, 10]
            }],
            columns: [{
                    data: null,
                    orderable: false,
                    render: function(d, type, row, meta) {
                        return meta.row + meta.settings._iDisplayStart + 1;
                    }
                },
                {
                    data: 'kode_invoice',
                    render: function(d, type, row) {
                        var html = '<strong>' + d + '</strong>';
                        if (row.dari_archive) {
                            html += '<br><span class="badge bg-secondary" title="Data historis, sudah dipindahkan ke database archive">' +
                                '<i class="fas fa-box-archive"></i> Archive</span>';
                        }
                        return html;
                    }
                },
                { data: 'no_order_display' },
                {
                    data: 'tanggal_ts',
                    render: function(d, type, row) {
                        return row.tanggal_display;
                    }
                },
                { data: 'pelanggan_nama' },
                { data: 'kasir' },
                {
                    data: 'grand_total',
                    className: 'text-end',
                    render: function(d) {
                        return Number(d).toLocaleString('id-ID');
                    }
                },
                {
                    data: 'total_dibayar',
                    className: 'text-end',
                    render: function(d) {
                        return Number(d).toLocaleString('id-ID');
                    }
                },
                {
                    data: 'sisa',
                    className: 'text-end',
                    render: function(d, type, row) {
                        var html = '<span class="' + (Number(d) > 0 ? 'text-danger' : 'text-success') + '">' +
                            Number(d).toLocaleString('id-ID') + '</span>';
                        if (Number(row.kelebihan) > 0) {
                            html += '<br><span class="badge bg-warning text-dark" style="font-size: 0.65rem;" title="Kelebihan bayar">' +
                                '<i class="fas fa-exclamation-triangle"></i> +' +
                                Number(row.kelebihan).toLocaleString('id-ID') + '</span>';
                        }
                        return html;
                    }
                },
                {
                    data: 'status_pembayaran',
                    render: function(d, type, row) {
                        return '<div class="d-flex flex-wrap gap-1">' +
                            '<span class="badge bg-' + row.payment_class + '">' + row.payment_label + '</span>' +
                            '<span class="badge bg-' + row.status_class + '">' + row.status_label + '</span>' +
                            '</div>';
                    }
                },
                {
                    data: 'id',
                    orderable: false,
                    render: function(d, type, row) {
                        var html = '<a href="<?= base_url('/transaksi/detail/') ?>' + d +
                            '" class="btn btn-sm btn-info" title="Lihat Detail"><i class="fas fa-eye"></i></a>';

                        if (!row.dari_archive) {
                            var cfg = window.paymentModalConfig || {};
                            var invoiceJs = String(row.kode_invoice).replace(/'/g, "\\'");

                            if (row.status_pembayaran !== 'lunas' &&
                                row.status !== 'batal' && row.status !== 'mangkrak') {
                                html += ' <button class="btn btn-sm btn-success" title="Bayar" onclick="bayarTransaksi(' +
                                    d + ', ' + row.grand_total + ', ' + row.sisa + ', \'' + invoiceJs + '\')">' +
                                    '<i class="fas fa-hand-holding-usd"></i></button>';
                            }

                            if (row.status === 'proses' && (cfg.isAdmin || cfg.isShiftLeader)) {
                                html += ' <button type="button" class="btn btn-sm btn-primary" title="Tandai Selesai" onclick="selesaikanTransaksi(' +
                                    d + ', \'' + String(row.status_pembayaran).replace(/'/g, "\\'") + '\')">' +
                                    '<i class="fas fa-check"></i></button>';
                            }

                            if ((row.status === 'proses' || row.status === 'selesai') && (cfg.isAdmin || cfg.isShiftLeader)) {
                                html += ' <button type="button" class="btn btn-sm btn-danger" title="Batalkan Transaksi" onclick="ubahStatus(' +
                                    d + ', \'batal\')"><i class="fas fa-times"></i></button>';
                            }
                        }

                        return html;
                    }
                }
            ],
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

        // ---- Fungsi filter ------------------------------------
        // Salin pilihan dropdown ke hidden "efektif". Dipanggil saat user
        // menekan Filter atau memilih rentang, sehingga filter yang benar-
        // benar dipakai selalu berasal dari interaksi user terakhir.
        function syncFilterEfektif() {
            $('#statusTransaksiEfektif').val($('#filterStatusTransaksi').val());
            $('#statusPembayaranEfektif').val($('#filterStatusPembayaran').val());
        }

        window.applyFilter = function() {
            syncFilterEfektif();
            tableTransaksi.ajax.reload();
        };
        window.resetFilter = function() {
            window.location.href = '<?= base_url('/transaksi') ?>';
        };

        // Rentang tanggal: simpan ke hidden lalu reload.
        $('#filterTanggal').on('apply.daterangepicker', function(ev, picker) {
            $('#tanggalAwalHidden').val(picker.startDate.format('YYYY-MM-DD'));
            $('#tanggalAkhirHidden').val(picker.endDate.format('YYYY-MM-DD'));
            syncFilterEfektif();
            tableTransaksi.ajax.reload();
        });
    });
</script>

<?= $this->endSection() ?>