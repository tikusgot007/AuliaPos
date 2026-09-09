<?php
// Dipakai untuk visibility tombol khusus admin (mis. Selesai).
// Backend (TransaksiModel::ubahStatus) tetap sumber kebenaran validasi;
// pengecekan di sini murni untuk tampilan.
$isAdminUser = session()->get('role') === 'admin';
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
                            'proses' => 'Proses',
                            'selesai' => 'Selesai',
                            'batal' => 'Batal',
                            'mangkrak' => 'Mangkrak',
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
                    <option value="">Semua</option>
                    <option value="belum_bayar" <?= $status_pembayaran == 'belum_bayar' ? 'selected' : '' ?>>Belum Bayar</option>
                    <option value="dp" <?= $status_pembayaran == 'dp' ? 'selected' : '' ?>>DP</option>
                    <option value="lunas" <?= $status_pembayaran == 'lunas' ? 'selected' : '' ?>>Lunas</option>
                </select>
            </div>

            <!-- Filter Pelanggan -->
            <div class="col-md-3">
                <label class="form-label">Pelanggan</label>
                <select class="form-control" id="filterPelanggan">
                    <option value="">Semua Pelanggan</option>
                    <?php if (!empty($pelanggan_list)): ?>
                        <?php foreach ($pelanggan_list as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($pelanggan_filter ?? '') == $p['id'] ? 'selected' : '' ?>>
                                <?= esc($p['nama']) ?>
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
                    <?php if (!empty($transaksi)): ?>
                        <?php foreach ($transaksi as $i => $t):
                            $totalDibayar = $t['total_dibayar'] ?? 0;
                            $sisa = max(0, $t['grand_total'] - $totalDibayar);
                            $kelebihan = max(0, $totalDibayar - $t['grand_total']);

                            // Status pembayaran
                            $paymentClass = [
                                'belum_bayar' => 'danger',
                                'dp' => 'warning',
                                'lunas' => 'success'
                            ][$t['status_pembayaran']] ?? 'secondary';

                            // Status transaksi
                            $statusClass = [
                                'proses' => 'warning',
                                'selesai' => 'primary',
                                'batal' => 'secondary',
                                'mangkrak' => 'dark'
                            ][$t['status']] ?? 'secondary';

                            $statusLabel = [
                                'proses' => 'Proses',
                                'selesai' => 'Selesai',
                                'batal' => 'Batal',
                                'mangkrak' => 'Mangkrak'
                            ][$t['status']] ?? strtoupper($t['status']);

                            // 🔥 Format No Order
                            $noOrderDisplay = $t['no_order'] ? format_no_order($t['no_order']) : '-';

                            // Baris dari Archive (lihat App\Services\TransaksiArchiveService)
                            // -- read-only, cuma boleh dilihat, tidak boleh
                            // dibayar/diselesaikan/dibatalkan lewat operasi
                            // transaksi normal (poin 9 spesifikasi Archive).
                            $dariArchive = ($t['_sumber'] ?? 'aktif') === 'archive';
                        ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= $t['kode_invoice'] ?></strong>
                                    <?php if ($dariArchive): ?>
                                        <br><span class="badge bg-secondary" title="Data historis, sudah dipindahkan ke database archive">
                                            <i class="fas fa-box-archive"></i> Archive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $noOrderDisplay ?></td> <!-- 🔥 NO ORDER -->
                                <td data-order="<?= strtotime($t['tanggal']) ?>">
                                    <?= date('d/m/Y', strtotime($t['tanggal'])) ?>
                                </td>
                                <td><?= $t['pelanggan_nama'] ?? '-' ?></td>
                                <td><?= $t['kasir_nama'] ?? '-' ?></td>
                                <td class="text-end"><?= number_format($t['grand_total'], 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($totalDibayar, 0, ',', '.') ?></td>
                                <td class="text-end <?= $sisa > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($sisa, 0, ',', '.') ?>
                                    <?php if ($kelebihan > 0): ?>
                                        <br>
                                        <span class="badge bg-warning text-dark" style="font-size: 0.65rem;" title="Kelebihan bayar">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            +<?= number_format($kelebihan, 0, ',', '.') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <span class="badge bg-<?= $paymentClass ?>">
                                            <?= strtoupper(str_replace('_', ' ', $t['status_pembayaran'])) ?>
                                        </span>
                                        <span class="badge bg-<?= $statusClass ?>">
                                            <?= $statusLabel ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?= base_url('/transaksi/detail/' . $t['id']) ?>"
                                        class="btn btn-sm btn-info"
                                        title="Lihat Detail">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <?php if (!$dariArchive): ?>
                                        <!-- 🔥 TOMBOL BAYAR (HANYA UNTUK BELUM LUNAS, tidak untuk MANGKRAK -- lihat transaksi/detail.php) -->
                                        <?php if ($t['status_pembayaran'] != 'lunas' && !in_array($t['status'], ['batal', 'mangkrak'], true)): ?>
                                            <button class="btn btn-sm btn-success"
                                                onclick="bayarTransaksi(<?= $t['id'] ?>, <?= $t['grand_total'] ?>, <?= $sisa ?>, '<?= $t['kode_invoice'] ?>')">
                                                <i class="fas fa-hand-holding-usd"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($t['status'] === 'proses' && $isAdminUser): ?>
                                            <button type="button" class="btn btn-sm btn-primary"
                                                title="Tandai Selesai"
                                                onclick="selesaikanTransaksi(<?= $t['id'] ?>, '<?= esc($t['status_pembayaran'], 'js') ?>')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (in_array($t['status'], ['proses', 'selesai'], true)): ?>
                                            <button type="button" class="btn btn-sm btn-danger"
                                                title="<?= $t['status'] === 'selesai' ? 'Batalkan Transaksi (khusus admin)' : 'Batalkan Transaksi' ?>"
                                                onclick="ubahStatus(<?= $t['id'] ?>, 'batal')">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>

                    <?php endif; ?>
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

<!-- Date Range Picker -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
<script src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js"></script>
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

        if (!(await konfirmasi('Ubah status transaksi menjadi ' + label + '?', { okText: 'Ya, Ubah' }))) {
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

            if (!(await konfirmasi(pesanKonfirmasi, { okText: 'Ya, Selesaikan', okClass: 'btn-success' }))) {
                return;
            }
        }
        // Jika belum lunas, tidak ada yang perlu dikonfirmasi di sini —
        // backend akan menolak dan alasannya (termasuk sisa tagihan)
        // akan tampil lewat toast di bawah.

        kirimUbahStatusAjax(id, 'selesai');
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

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
        isAdmin: <?= session()->get('role') === 'admin' ? 'true' : 'false' ?>
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
    function initDataTable() {
        // Hancurkan instance lama jika ada
        if ($.fn.DataTable.isDataTable('#tableTransaksi')) {
            $('#tableTransaksi').DataTable().destroy();
        }

        // Inisialisasi ulang
        $('#tableTransaksi').DataTable({
            responsive: true,
            processing: true,
            serverSide: false,
            pageLength: 25,
            order: [
                [3, 'desc']
            ],

            columnDefs: [{
                    orderable: false,
                    targets: [0, 10]
                },
                {
                    type: 'num',
                    targets: [2]
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
    }

    $(document).ready(function() {
        // Inisialisasi DataTables
        initDataTable();

        // ==========================================
        // 2. INIT DATE RANGE PICKER
        // ==========================================
        var startDate = '<?= $tanggal_awal ?>' || moment().startOf('month').format('YYYY-MM-DD');
        var endDate = '<?= $tanggal_akhir ?>' || moment().format('YYYY-MM-DD');

        $('#filterTanggal').daterangepicker({
            locale: {
                format: 'DD/MM/YYYY',
                separator: ' - ',
                applyLabel: 'Terapkan',
                cancelLabel: 'Batal',
                fromLabel: 'Dari',
                toLabel: 'Sampai',
                customRangeLabel: 'Custom',
                weekLabel: 'M',
                daysOfWeek: ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'],
                monthNames: ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
                ],
                firstDay: 1
            },
            startDate: moment(startDate),
            endDate: moment(endDate),
            opens: 'left',
            showDropdowns: true,
            ranges: {
                'Hari Ini': [moment(), moment()],
                'Kemarin': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                '7 Hari Terakhir': [moment().subtract(6, 'days'), moment()],
                '30 Hari Terakhir': [moment().subtract(29, 'days'), moment()],
                'Bulan Ini': [moment().startOf('month'), moment().endOf('month')],
                'Bulan Lalu': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        });

        // ==========================================
        // 3. FUNGSI FILTER
        // ==========================================
        window.applyFilter = function() {

            var tanggalRange =
                $('#filterTanggal').val();

            var statusPembayaran =
                $('#filterStatusPembayaran').val();

            var statusTransaksi =
                $('#filterStatusTransaksi').val();

            var pelanggan =
                $('#filterPelanggan').val();


            var url =
                '<?= base_url('/transaksi') ?>?';

            var params = [];


            /*
            |--------------------------------------------------------------------------
            | TANGGAL
            |--------------------------------------------------------------------------
            */

            if (tanggalRange) {

                var parts =
                    tanggalRange.split(' - ');

                if (parts.length === 2) {

                    var start =
                        moment(
                            parts[0],
                            'DD/MM/YYYY'
                        ).format('YYYY-MM-DD');

                    var end =
                        moment(
                            parts[1],
                            'DD/MM/YYYY'
                        ).format('YYYY-MM-DD');


                    params.push(
                        'tanggal_awal=' +
                        encodeURIComponent(start)
                    );

                    params.push(
                        'tanggal_akhir=' +
                        encodeURIComponent(end)
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | STATUS PEMBAYARAN
            |--------------------------------------------------------------------------
            */

            if (statusPembayaran) {

                params.push(
                    'status_pembayaran=' +
                    encodeURIComponent(
                        statusPembayaran
                    )
                );
            }


            /*
            |--------------------------------------------------------------------------
            | STATUS TRANSAKSI
            |--------------------------------------------------------------------------
            */

            if (statusTransaksi) {

                params.push(
                    'status_transaksi=' +
                    encodeURIComponent(
                        statusTransaksi
                    )
                );
            }


            /*
            |--------------------------------------------------------------------------
            | PELANGGAN
            |--------------------------------------------------------------------------
            */

            if (pelanggan) {

                params.push(
                    'pelanggan=' +
                    encodeURIComponent(
                        pelanggan
                    )
                );
            }


            /*
            |--------------------------------------------------------------------------
            | REDIRECT
            |--------------------------------------------------------------------------
            */

            url += params.join('&');

            window.location.href = url;
        };
        window.resetFilter = function() {
            window.location.href = '<?= base_url('/transaksi') ?>';
        };

        // Filter otomatis saat date range di-apply
        $('#filterTanggal').on('apply.daterangepicker', function(ev, picker) {
            applyFilter();
        });
        // Filter otomatis saat enter di input search (jika ada)
        $('#filterTanggal').on('keydown', function(e) {
            if (e.key === 'Enter') {
                applyFilter();
            }
        });
    });
</script>

<?= $this->endSection() ?>