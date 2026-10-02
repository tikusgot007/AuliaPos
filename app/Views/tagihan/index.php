<div class="card">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Daftar Tagihan Belum Lunas</h5>
        <span class="badge bg-dark"><span id="badgeJumlahTagihan">0</span> tagihan</span>
    </div>
    <div class="card-body">
        <!-- ========================================== -->
        <!-- FILTER                                     -->
        <!-- Default (halaman baru dibuka): TANPA batas tanggal -->
        <!-- Rentang tanggal pakai date range picker (satu input),  -->
        <!-- lihat #filterTanggal & init-nya di section scripts.    -->
        <?php $sayaAktif = service('request')->getGet('saya') == '1'; ?>
        <form id="formFilterTagihan" method="get" class="row g-2 mb-3 align-items-end flex-nowrap overflow-auto">
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
                            <?= esc($k['inisial'] ?: ($k['nama'] ?? $k['username'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto form-check mb-2">
                <input type="checkbox" name="hanya_terlambat" value="1" id="hanyaTerlambat"
                    class="form-check-input" <?= $hanya_terlambat ? 'checked' : '' ?>>
                <label class="form-check-label" for="hanyaTerlambat">Seminggu Lebih</label>
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
                </tbody>
            </table>
        </div>

        <!-- Komponen payment reusable dimuat saat diperlukan. -->
        <div id="paymentModalContainer"></div>



        <?= $this->section('scripts') ?>

        <!-- Konfigurasi payment modal untuk halaman tagihan -->
        <?php $isShiftLeaderUser = \App\Services\Authority::isCurrentShiftLeader((int) session()->get('id_user')); ?>
        <script>
            // Override konfigurasi untuk halaman tagihan
            window.paymentModalConfig = {
                modalUrl: '<?= base_url('/api/modal/pembayaran') ?>',
                kasirTransactionUrl: '<?= base_url('/api/simpan-transaksi') ?>',
                existingPaymentUrl: '<?= base_url('/tagihan/lunasi/:id') ?>', // 🔥 arahkan ke endpoint tagihan
                tagihanLunasiUrl: '<?= base_url('/tagihan/lunasi/:id') ?>', // opsional, tidak dipakai
                kasirListUrl: '<?= base_url('/api/kasir-list') ?>',
                isAdmin: <?= session()->get('role') === 'admin' ? 'true' : 'false' ?>,
                isShiftLeader: <?= $isShiftLeaderUser ? 'true' : 'false' ?>
            };
        </script>
        <script src="<?= base_url('assets/js/payment.js') ?>"></script>

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
                    locale: AuliaDateRange.locale(),
                    ranges: AuliaDateRange.ranges(),
                    startDate: startDate,
                    endDate: endDate,
                    autoUpdateInput: !!(awalHidden && akhirHidden),
                    opens: 'left',
                    showDropdowns: true
                });

                // Terapkan langsung saat rentang dipilih -- isi hidden
                // field (dikonsumsi Tagihan::getRentangTanggal()) lalu
                // submit form yang sama supaya filter kasir/hanya
                // terlambat yang sedang aktif ikut terbawa.
                $('#filterTanggal').on('apply.daterangepicker', function(ev, picker) {
                    $('#tanggalAwalHidden').val(picker.startDate.format('YYYY-MM-DD'));
                    $('#tanggalAkhirHidden').val(picker.endDate.format('YYYY-MM-DD'));
                    $('#tableTagihan').DataTable().ajax.reload();
                });
            });

            // ==========================================
            // INIT DATATABLES
            // ==========================================
            $(document).ready(function() {
                const table = $('#tableTagihan').DataTable({
                    responsive: true,
                    processing: true,
                    serverSide: true,
                    pageLength: 25,
                    ajax: {
                        url: '<?= base_url('tagihan/data') ?>',
                        data: function(d) {
                            d.tanggal_awal = $('#tanggalAwalHidden').val();
                            d.tanggal_akhir = $('#tanggalAkhirHidden').val();
                            d.kasir_id = $('[name="kasir_id"]').val();
                            d.hanya_terlambat = $('#hanyaTerlambat').is(':checked') ? '1' : '';
                            d.saya = $('[name="saya"]').val() || '';
                        }
                    },
                    // Kolom 3 = Tanggal. ASC supaya tagihan paling lama
                    // menunggak (paling perlu ditagih) tampil paling atas.
                    order: [
                        [3, 'asc']
                    ],
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
                            render: function(d) {
                                return '<strong>' + d + '</strong>';
                            }
                        },
                        { data: 'no_order_display' },
                        {
                            data: 'tanggal_ts',
                            render: function(d, type, row) {
                                if (row.is_overdue) {
                                    return '<span class="badge bg-danger fs-6 fw-normal" title="Jatuh tempo ' +
                                        row.jatuh_tempo_display + '">' + row.tanggal_display + '</span>';
                                }
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
                            render: function(d) {
                                return '<span class="' + (Number(d) > 0 ? 'text-danger' : 'text-success') +
                                    '">' + Number(d).toLocaleString('id-ID') + '</span>';
                            }
                        },
                        {
                            data: 'status_label',
                            render: function(d, type, row) {
                                return '<span class="badge bg-' + row.status_class + '">' + d + '</span>';
                            }
                        },
                        {
                            data: 'id',
                            orderable: false,
                            render: function(d, type, row) {
                                let html = '<a href="<?= base_url('tagihan/detail/') ?>' + d +
                                    '" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>';
                                if (row.status_pembayaran !== 'lunas') {
                                    const invoice = String(row.kode_invoice).replace(/'/g, "\\'");
                                    html += ' <button class="btn btn-sm btn-success" onclick="lunasiTagihan(' +
                                        d + ', ' + row.grand_total + ', ' + row.sisa + ', \'' + invoice + '\')">' +
                                        '<i class="fas fa-hand-holding-usd"></i></button>';
                                }
                                return html;
                            }
                        }
                    ],
                    drawCallback: function() {
                        const json = this.api().ajax.json() || {};
                        $('#badgeJumlahTagihan').text(Number(json.recordsFiltered || 0).toLocaleString('id-ID'));
                    },
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

                $('#formFilterTagihan').on('submit', function(e) {
                    e.preventDefault();
                    table.ajax.reload();
                });
            });
        </script>

        <?= $this->endSection() ?>