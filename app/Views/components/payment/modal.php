<!--
    Reusable payment modal markup.
    JavaScript behaviour is intentionally kept out of this view; it will be
    provided by public/assets/js/payment.js in Tahap 3.
-->
<div id="paymentModalRoot">
    <div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="paymentModalTitle">Konfirmasi Pembayaran</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" id="paymentTotalLabel">Total Belanja</label>
                        <h3 id="paymentTotal" class="text-primary">Rp 0</h3>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Metode Pembayaran</label>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary" data-payment-method="tunai">
                                <i class="fas fa-money-bill-wave"></i> Tunai
                            </button>
                            <button type="button" class="btn btn-outline-success" data-payment-method="qris">
                                <i class="fas fa-qrcode"></i> QRIS
                            </button>
                            <button type="button" class="btn btn-outline-info" data-payment-method="transfer">
                                <i class="fas fa-university"></i> Transfer
                            </button>
                            <div data-payment-dp-divider>
                                <hr>
                            </div>
                            <button type="button" class="btn btn-outline-warning" data-payment-action="open-dp">
                                <i class="fas fa-hand-holding-usd"></i> Bayar DP
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="paymentCashModal" tabindex="-1" aria-labelledby="paymentCashModalTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="paymentCashModalTitle"><i class="fas fa-money-bill-wave"></i> Pembayaran Tunai</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" id="paymentCashTotalLabel">Total Belanja</label>
                        <h4 id="paymentCashTotal" class="text-primary">Rp 0</h4>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label fw-bold mb-0" for="paymentCashReceived">Uang Diterima (Rp)</label>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-payment-action="reset-cash" title="Reset uang diterima">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                        <input type="text" class="form-control form-control-lg" id="paymentCashReceived" placeholder="Rp 0" inputmode="numeric" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Pilih Pecahan Uang</label>
                        <div class="row g-2">
                            <?php foreach ([100000, 50000, 20000, 10000, 5000, 2000] as $nominal): ?>
                                <div class="col-4">
                                    <button type="button" class="btn btn-outline-success btn-uang w-100 py-2" data-payment-cash="<?= $nominal ?>">
                                        <small>Rp <?= number_format($nominal, 0, ',', '.') ?></small>
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary w-100 py-2" data-payment-action="exact-cash">
                            <i class="fas fa-check-circle"></i> Uang Pas
                        </button>
                    </div>
                    <div class="mb-3" id="paymentChangeContainer" style="display: none;">
                        <label class="form-label fw-bold">Kembalian</label>
                        <h3 id="paymentChange" class="text-success">Rp 0</h3>
                    </div>
                    <div class="alert alert-danger small" id="paymentCashWarning" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i> Uang yang diterima kurang dari total belanja!
                    </div>
                    <!--
                        Backdate / pembayaran diterima sebelumnya.
                        Hanya ditampilkan untuk admin & transaksi existing
                        (diatur JS, lihat payment.js). Tidak dicentang
                        secara default; behavior normal tidak berubah
                        jika tidak dicentang.
                    -->
                    <div class="mb-3 d-none" id="paymentBackdateSectionCash">
                        <hr>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="paymentBackdateCheckCash">
                            <label class="form-check-label small" for="paymentBackdateCheckCash">
                                Pembayaran diterima sebelumnya
                            </label>
                        </div>
                        <div class="mt-2 d-none" id="paymentBackdateFieldsCash">
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="paymentBackdateTanggalCash">Tanggal pembayaran</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="paymentBackdateTanggalCash">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="paymentBackdateKasirCash">Kasir penerima</label>
                                <select class="form-select form-select-sm" id="paymentBackdateKasirCash">
                                    <option value="">Pilih kasir...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="paymentSubmitCash" data-payment-action="submit-cash" disabled>
                        <i class="fas fa-check"></i> Bayar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="paymentDpModal" tabindex="-1" aria-labelledby="paymentDpModalTitle" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="paymentDpModalTitle"><i class="fas fa-hand-holding-usd"></i> Bayar DP</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <span id="paymentDpTotalLabel">Total belanja</span>: <strong id="paymentDpTotal">Rp 0</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pilih Metode Input DP</label>
                        <div class="btn-group w-100" role="group" aria-label="Metode input DP">
                            <input type="radio" class="btn-check" name="paymentDpMode" id="paymentDpNominal" value="nominal" checked>
                            <label class="btn btn-outline-primary" for="paymentDpNominal">Nominal</label>
                            <input type="radio" class="btn-check" name="paymentDpMode" id="paymentDpPercent" value="persen">
                            <label class="btn btn-outline-primary" for="paymentDpPercent">Persentase</label>
                        </div>
                    </div>
                    <div class="mb-3" id="paymentDpNominalGroup">
                        <label class="form-label" for="paymentDpNominalInput">Jumlah DP (Rp)</label>
                        <input type="text" inputmode="numeric" class="form-control" id="paymentDpNominalInput" placeholder="Masukkan nominal DP" autocomplete="off">
                    </div>
                    <div class="mb-3 d-none" id="paymentDpPercentGroup">
                        <label class="form-label" for="paymentDpPercentInput">Persentase DP (%)</label>
                        <input type="number" class="form-control" id="paymentDpPercentInput" placeholder="Masukkan persentase DP" min="0" max="100" value="50">
                        <small class="text-muted">Contoh: 50 = 50% dari total belanja</small>
                    </div>
                    <div class="mb-3">
                        <div class="mb-3">
                            <label class="form-label fw-bold">
                                Metode Pembayaran DP
                            </label>

                            <div
                                class="d-grid gap-2"
                                style="grid-template-columns: repeat(3, 1fr);">

                                <button
                                    type="button"
                                    class="btn btn-outline-primary"
                                    data-payment-dp-method="tunai">

                                    <i class="fas fa-money-bill-wave"></i>
                                    <br>
                                    Tunai

                                </button>

                                <button
                                    type="button"
                                    class="btn btn-outline-success"
                                    data-payment-dp-method="qris">

                                    <i class="fas fa-qrcode"></i>
                                    <br>
                                    QRIS

                                </button>

                                <button
                                    type="button"
                                    class="btn btn-outline-info"
                                    data-payment-dp-method="transfer">

                                    <i class="fas fa-university"></i>
                                    <br>
                                    Transfer

                                </button>

                            </div>

                            <!-- Nilai metode yang sedang dipilih -->
                            <input
                                type="hidden"
                                id="paymentDpMethod"
                                value="tunai">

                        </div>
                    </div>
                    <div class="alert alert-secondary">
                        <strong>Ringkasan:</strong><br>
                        <span id="paymentDpSummaryTotalLabel">Total Belanja</span>: <span id="paymentDpSummaryTotal">Rp 0</span><br>
                        DP Dibayar: <span id="paymentDpSummaryPaid">Rp 0</span><br>
                        <span class="text-danger">Sisa Tagihan: <span id="paymentDpSummaryRemaining">Rp 0</span></span>
                    </div>
                    <!--
                        Backdate / pembayaran diterima sebelumnya.
                        Hanya untuk admin & transaksi existing, hanya
                        relevan untuk DP tunai (submit langsung dari
                        modal ini); DP via QRIS/Transfer melalui modal
                        konfirmasi yang punya bloknya sendiri.
                    -->
                    <div class="mb-3 d-none" id="paymentBackdateSectionDp">
                        <hr>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="paymentBackdateCheckDp">
                            <label class="form-check-label small" for="paymentBackdateCheckDp">
                                Pembayaran diterima sebelumnya
                            </label>
                        </div>
                        <div class="mt-2 d-none" id="paymentBackdateFieldsDp">
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="paymentBackdateTanggalDp">Tanggal pembayaran</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="paymentBackdateTanggalDp">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="paymentBackdateKasirDp">Kasir penerima</label>
                                <select class="form-select form-select-sm" id="paymentBackdateKasirDp">
                                    <option value="">Pilih kasir...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-warning" data-payment-action="submit-dp">
                        <i class="fas fa-check"></i> Bayar DP
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="paymentConfirmModal"
        tabindex="-1"
        aria-labelledby="paymentConfirmModalTitle"
        aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered">

            <div class="modal-content">

                <div class="modal-header bg-warning">

                    <h5
                        class="modal-title"
                        id="paymentConfirmModalTitle">

                        <i class="fas fa-shield-alt"></i>
                        Konfirmasi Pembayaran

                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Tutup">
                    </button>

                </div>

                <div class="modal-body">

                    <div class="text-center mb-3">

                        <div
                            class="small text-muted mb-1"
                            id="paymentConfirmDetail">
                            Pembayaran transaksi
                        </div>

                        <div
                            id="paymentConfirmMethod"
                            class="fs-4 fw-bold text-primary">
                            QRIS
                        </div>

                        <div class="fs-3 fw-bold mt-2">

                            <span id="paymentConfirmAmount">
                                Rp 0
                            </span>

                        </div>

                    </div>

                    <div class="alert alert-warning">

                        <div class="fw-bold mb-2">
                            Pastikan pembayaran sudah benar-benar diterima.
                        </div>

                        <ol class="mb-0 ps-3">

                            <li>
                                Pastikan transaksi berhasil.
                            </li>

                            <li>
                                Dokumentasikan / simpan bukti pembayaran.
                            </li>

                            <li>
                                Laporkan bukti pembayaran sesuai prosedur.
                            </li>

                        </ol>

                    </div>

                    <div class="text-center fw-semibold">
                        Sudah memastikan pembayaran ini berhasil?
                    </div>

                    <!--
                        Backdate / pembayaran diterima sebelumnya.
                        Hanya untuk admin & transaksi existing.
                    -->
                    <div class="mb-3 mt-3 d-none" id="paymentBackdateSectionConfirm">
                        <hr>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="paymentBackdateCheckConfirm">
                            <label class="form-check-label small" for="paymentBackdateCheckConfirm">
                                Pembayaran diterima sebelumnya
                            </label>
                        </div>
                        <div class="mt-2 d-none" id="paymentBackdateFieldsConfirm">
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="paymentBackdateTanggalConfirm">Tanggal pembayaran</label>
                                <input type="datetime-local" class="form-control form-control-sm" id="paymentBackdateTanggalConfirm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small mb-1" for="paymentBackdateKasirConfirm">Kasir penerima</label>
                                <select class="form-select form-select-sm" id="paymentBackdateKasirConfirm">
                                    <option value="">Pilih kasir...</option>
                                </select>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal">

                        <i class="fas fa-arrow-left"></i>
                        Kembali

                    </button>

                    <button
                        type="button"
                        class="btn btn-success"
                        data-payment-confirm="yes">

                        <i class="fas fa-check-circle"></i>
                        Sudah Dibayar & Simpan

                    </button>

                </div>

            </div>

        </div>

    </div>

</div>