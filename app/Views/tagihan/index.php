<div class="card">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Daftar Tagihan Belum Lunas</h5>
        <span class="badge bg-dark"><?= count($tagihan) ?> tagihan</span>
    </div>
    <div class="card-body">
        <!-- ========================================== -->
        <!-- FILTER                                     -->
        <!-- Default (halaman baru dibuka): TANPA batas tanggal -->
        <!-- Rentang tanggal pakai date range picker (satu input),  -->
        <!-- lihat #filterTanggal & init-nya di section scripts.    -->
        <?php $sayaAktif = service('request')->getGet('saya') == '1'; ?>
        <form method="get" class="row g-2 mb-3 align-items-end flex-nowrap overflow-auto">
            <?php if ($sayaAktif): ?>
                <input type="hidden" name="saya" value="1">
            <?php endif; ?>
            <input type="hidden" name="tanggal_awal" id="tanggalAwalHidden" value="<?= esc($tanggal_awal, 'attr') ?>">
            <input type="hidden" name="tanggal_akhir" id="tanggalAkhirHidden" value="<?= esc($tanggal_akhir, 'attr') ?>">
            <div class="col-auto" style="min-width: 220px">
                <label class="form-label mb-1">Rentang Tanggal</label>
                <input type="text" id="filterTanggal" class="form-control form-control-sm"
                    placeholder="Semua tanggal"
                    value="<?= ($tanggal_awal && $tanggal_akhir) ? date('d/m/Y', strtotime($tanggal_awal)) . ' - ' . date('d/m/Y', strtotime($tanggal_akhir)) : '' ?>">
            </div>
            <div class="col-auto" style="min-width: 160px">
                <label class="form-label mb-1">Kasir</label>
                <select name="kasir_id" class="form-select form-select-sm">
                    <option value="">Semua Kasir</option>
                    <?php foreach ($daftar_kasir as $k): ?>
                        <option value="<?= $k['id'] ?>" <?= $kasir_id_filter === (int) $k['id'] ? 'selected' : '' ?>>
                            <?= esc($k['nama'] ?? $k['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto form-check mb-2">
                <input type="checkbox" name="hanya_terlambat" value="1" id="hanyaTerlambat"
                    class="form-check-input" <?= $hanya_terlambat ? 'checked' : '' ?>>
                <label class="form-check-label" for="hanyaTerlambat">Hanya terlambat</label>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <?php
                    $tujuhHariAwal  = date('Y-m-d', strtotime('-7 days'));
                    $tujuhHariAkhir = date('Y-m-d');
                    $tujuhHariAktif = $tanggal_awal === $tujuhHariAwal && $tanggal_akhir === $tujuhHariAkhir;
                    $tujuhHariQuery = http_build_query(array_filter([
                        'saya'          => $sayaAktif ? '1' : null,
                        'tanggal_awal'  => $tujuhHariAwal,
                        'tanggal_akhir' => $tujuhHariAkhir,
                    ]));
                ?>
                <a href="<?= base_url('/tagihan?' . $tujuhHariQuery) ?>"
                    class="btn btn-sm <?= $tujuhHariAktif ? 'btn-warning' : 'btn-outline-warning' ?>">
                    <i class="fas fa-bolt"></i> 7 Hari Terakhir
                </a>
                <a href="<?= base_url('/tagihan' . ($sayaAktif ? '?saya=1' : '')) ?>"
                    class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>

        <?php if ($sayaAktif): ?>
            <div class="alert alert-secondary d-flex justify-content-between align-items-center py-2">
                <span><i class="fas fa-filter"></i> Menampilkan tagihan atas nama Anda saja.</span>
                <a href="<?= base_url('/tagihan') ?>" class="btn btn-sm btn-outline-secondary">Lihat Semua Tagihan</a>
            </div>
        <?php endif; ?>
        <!-- ========================================== -->
        <!-- TABEL TAGIHAN                              -->
        <!-- ========================================== -->
        <div class="table-responsive">
            <table class="table table-striped table-bordered" id="tableTagihan">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Invoice</th>
                        <th>No Order</th> <!-- 🔥 SAMA DENGAN TRANSAKSI -->
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
                    <?php if (!empty($tagihan)): ?>
                        <?php foreach ($tagihan as $i => $t):
                            // Pastikan semua field tersedia, beri default jika null
                            $totalDibayar = $t['total_dibayar'] ?? $t['dibayar'] ?? 0;
                            $grandTotal   = $t['grand_total'] ?? $t['total'] ?? 0;
                            $sisa         = $grandTotal - $totalDibayar;

                            // Status di halaman Tagihan mengikuti kondisi pembayaran.
                            // Status pekerjaan transaksi (proses/selesai/batal) ditentukan di modul transaksi.
                            // Logic sama dengan TransaksiModel::sinkronkanPembayaran() -- dipusatkan di service.
                            $statusPembayaran = \App\Services\KalkulasiStatusPembayaran::hitung(
                                (float) $totalDibayar,
                                (float) $grandTotal
                            );
                            $paymentClass = status_pembayaran_badge_class($statusPembayaran);

                            // 🔥 Format No Order
                            $noOrderDisplay = !empty($t['no_order']) ? format_no_order($t['no_order']) : '-';

                            // 🔥 Format tanggal singkat "29 Sep 2026" (helper order_helper.php).
                            // $ts tetap dipakai untuk data-order (sorting DataTables).
                            $ts = strtotime($t['tanggal']);
                            $tanggalDisplay = tanggal_singkat($t['tanggal']);
                        ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= $t['kode_invoice'] ?? $t['invoice'] ?? '-' ?></strong></td>
                                <td><?= $noOrderDisplay ?></td>
                                <td data-order="<?= $ts ?>">
                                    <?php if ($t['is_overdue']): ?>
                                        <span class="badge bg-danger fs-6 fw-normal" title="Jatuh tempo <?= tanggal_singkat($t['jatuh_tempo']) ?>"><?= $tanggalDisplay ?></span>
                                    <?php else: ?>
                                        <?= $tanggalDisplay ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= $t['pelanggan_nama'] ?? $t['nama_pelanggan'] ?? '-' ?></td>
                                <td><?= $t['kasir_nama'] ?? $t['nama_kasir'] ?? '-' ?></td>
                                <td class="text-end"><?= number_format($grandTotal, 0, ',', '.') ?></td>
                                <td class="text-end"><?= number_format($totalDibayar, 0, ',', '.') ?></td>
                                <td class="text-end <?= $sisa > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= number_format($sisa, 0, ',', '.') ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <span class="badge bg-<?= $paymentClass ?>">
                                            <?= status_pembayaran_label($statusPembayaran) ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <!-- Tombol Detail -->
                                    <a href="<?= base_url('tagihan/detail/' . $t['id']) ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>

                                    <!-- Tombol Bayar hanya untuk tagihan yang belum lunas. -->
                                    <?php if ($statusPembayaran != 'lunas'): ?>
                                        <button class="btn btn-sm btn-success"
                                            onclick="lunasiTagihan(<?= $t['id'] ?>, <?= $grandTotal ?>, <?= $sisa ?>, '<?= $t['kode_invoice'] ?? $t['invoice'] ?>')">
                                            <i class="fas fa-hand-holding-usd"></i>
                                        </button>
                                    <?php endif; ?>

                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Komponen payment reusable dimuat saat diperlukan. -->
        <div id="paymentModalContainer"></div>



        <?= $this->section('scripts') ?>

        <!-- Konfigurasi payment modal untuk halaman tagihan -->
        <script>
            // Override konfigurasi untuk halaman tagihan
            window.paymentModalConfig = {
                modalUrl: '<?= base_url('/api/modal/pembayaran') ?>',
                kasirTransactionUrl: '<?= base_url('/api/simpan-transaksi') ?>',
                existingPaymentUrl: '<?= base_url('/tagihan/lunasi/:id') ?>', // 🔥 arahkan ke endpoint tagihan
                tagihanLunasiUrl: '<?= base_url('/tagihan/lunasi/:id') ?>', // opsional, tidak dipakai
                kasirListUrl: '<?= base_url('/api/kasir-list') ?>',
                isAdmin: <?= session()->get('role') === 'admin' ? 'true' : 'false' ?>
            };
        </script>
        <script src="<?= base_url('assets/js/payment.js') ?>"></script>

        <!-- Date Range Picker -- pola sama seperti transaksi/index.php -->
        <script src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>

        <script>
            /**
             * Fungsi untuk membuka modal pembayaran dan melunasi tagihan.
             */
            function lunasiTagihan(id, grandTotal, sisa, invoice) {
                if (sisa <= 0) {
                    showToast('Tagihan ini sudah lunas.', 'warning');
                    return;
                }

                bukaPaymentModal({
                    mode: 'existing',
                    existingFlow: 'tagihan-lunasi',
                    transaksiId: id,
                    total: sisa,
                    invoice: invoice,
                    onSuccess: function(response) {
                        location.reload();
                    },
                    onError: function(error) {
                        showToast(error.message || 'Gagal melunasi tagihan.', 'danger');
                    }
                });
            }

            /**
             * Helper showToast dengan fallback.
             */


            /**
             * Format Rupiah (jika diperlukan di tempat lain).
             */
            function formatRupiah(angka) {
                return 'Rp ' + new Intl.NumberFormat('id-ID').format(angka);
            }

            // ==========================================
            // INIT DATE RANGE PICKER
            // ==========================================
            $(document).ready(function() {
                // Tanggal awal hidden field non-kosong dipakai sebagai
                // posisi awal kalender; kalau tidak ada filter aktif,
                // kalender tetap dibuka di 7 hari terakhir tanpa
                // otomatis menerapkan filter apa pun (submit hanya
                // terjadi lewat event 'apply' di bawah).
                var awalHidden = $('#tanggalAwalHidden').val();
                var akhirHidden = $('#tanggalAkhirHidden').val();
                var startDate = awalHidden ? moment(awalHidden) : moment().subtract(6, 'days');
                var endDate = akhirHidden ? moment(akhirHidden) : moment();

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
                    startDate: startDate,
                    endDate: endDate,
                    autoUpdateInput: !!(awalHidden && akhirHidden),
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

                // Terapkan langsung saat rentang dipilih -- isi hidden
                // field (dikonsumsi Tagihan::getRentangTanggal()) lalu
                // submit form yang sama supaya filter kasir/hanya
                // terlambat yang sedang aktif ikut terbawa.
                $('#filterTanggal').on('apply.daterangepicker', function(ev, picker) {
                    $('#tanggalAwalHidden').val(picker.startDate.format('YYYY-MM-DD'));
                    $('#tanggalAkhirHidden').val(picker.endDate.format('YYYY-MM-DD'));
                    $(this).closest('form').trigger('submit');
                });
            });

            // ==========================================
            // INIT DATATABLES
            // ==========================================
            $(document).ready(function() {
                $('#tableTagihan').DataTable({
                    responsive: true,
                    processing: true,
                    serverSide: false,
                    pageLength: 25,
                    // Kolom 3 = Tanggal. ASC supaya tagihan paling lama
                    // menunggak (paling perlu ditagih) tampil paling atas.
                    order: [
                        [3, 'asc']
                    ],
                    columnDefs: [{
                            orderable: false,
                            targets: [0, 9]
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
            });
        </script>

        <?= $this->endSection() ?>