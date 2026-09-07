<div class="card">
    <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Daftar Tagihan Belum Lunas</h5>
        <span class="badge bg-dark"><?= count($tagihan) ?> tagihan</span>
    </div>
    <div class="card-body">
        <?php if (service('request')->getGet('saya') == '1'): ?>
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
                            if ($sisa <= 0) {
                                $statusPembayaran = 'lunas';
                                $paymentClass = 'success';
                            } elseif ($totalDibayar > 0 && $sisa > 0) {
                                $statusPembayaran = 'dp';      // atau 'cicilan'
                                $paymentClass = 'warning';
                            } else {
                                $statusPembayaran = 'belum_bayar';
                                $paymentClass = 'danger';
                            }

                            // 🔥 Format No Order
                            $noOrderDisplay = !empty($t['no_order']) ? format_no_order($t['no_order']) : '-';
                        ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= $t['kode_invoice'] ?? $t['invoice'] ?? '-' ?></strong></td>
                                <td><?= $noOrderDisplay ?></td>
                                <td data-order="<?= strtotime($t['tanggal']) ?>">
                                    <?= date('d/m/Y', strtotime($t['tanggal'])) ?>
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
                                            <?= strtoupper(str_replace('_', ' ', $statusPembayaran)) ?>
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
            // INIT DATATABLES
            // ==========================================
            $(document).ready(function() {
                $('#tableTagihan').DataTable({
                    responsive: true,
                    processing: true,
                    serverSide: false,
                    pageLength: 25,
                    order: [
                        [2, 'desc']
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