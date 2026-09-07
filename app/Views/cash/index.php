<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Dashboard Kas</h1>
        <a href="<?= base_url('/cash/riwayat') ?>" class="btn btn-info">
            <i class="fas fa-history"></i> Riwayat
        </a>
    </div>
    <!-- ========================================== -->
    <!-- KAS AWAL HARI -->
    <!-- ========================================== -->
    <?php
    $jam = date('H');
    $bolehInputKasAwal = ($jam < 9);
    $kasAwalSaatIni = $kas_awal; // dari controller
    ?>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="fas fa-coins"></i> Kas Awal Hari
                        <?php if ($kasAwalSaatIni > 0): ?>
                            <span class="badge badge-success ml-2">Sudah diisi</span>
                        <?php elseif ($bolehInputKasAwal): ?>
                            <span class="badge badge-warning ml-2">Perlu diisi</span>
                        <?php else: ?>
                            <span class="badge badge-secondary ml-2">Melewati jam 09:00</span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="card-body">
                    <?php if ($kasAwalSaatIni > 0): ?>
                        <!-- ✅ SUDAH ADA KAS AWAL → TAMPILKAN INFO, SEMBUNYIKAN FORM -->
                        <div class="alert alert-success mb-0">
                            <i class="fas fa-check-circle"></i>
                            Kas awal hari ini sudah tercatat sebesar
                            <strong>Rp <?= number_format($kasAwalSaatIni, 0, ',', '.') ?></strong>
                            <br>
                            <small>Status: <span class="badge badge-success">Terkunci</span> (tidak dapat diubah)</small>
                        </div>
                    <?php elseif ($bolehInputKasAwal): ?>
                        <!-- ⚠️ BELUM ADA KAS AWAL & MASIH SEBELUM JAM 9 → TAMPILKAN FORM -->
                        <form id="formKasAwal">
                            <?= csrf_field() ?>
                            <div class="row">
                                <div class="col-md-5">
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text">Rp</span>
                                        </div>
                                        <input type="number"
                                            class="form-control"
                                            id="kasAwalInput"
                                            name="nominal"
                                            step="1000"
                                            min="0"
                                            placeholder="Masukkan nominal kas awal"
                                            required>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <input type="text"
                                        class="form-control"
                                        id="kasAwalCatatan"
                                        name="catatan"
                                        placeholder="Catatan (opsional)">
                                </div>
                                <div class="col-md-3">
                                    <button type="button" id="btnSimpanKasAwal" class="btn btn-primary btn-block">
                                        <i class="fas fa-save"></i> Simpan Kas Awal
                                    </button>
                                </div>
                            </div>
                            <small class="form-text text-muted">
                                Kas awal hanya bisa diinput sebelum jam 09:00 pagi.
                                Saat ini belum ada kas awal yang tercatat.
                            </small>
                        </form>
                    <?php else: ?>
                        <!-- ❌ SUDAH LEWAT JAM 9 & BELUM ADA KAS AWAL -->
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-clock"></i>
                            <strong>Waktu input kas awal telah berakhir.</strong>
                            Kas awal hanya bisa diinput sebelum jam 09:00 pagi.
                            <br>Belum ada kas awal yang dicatat hari ini.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <!-- ========================================== -->
    <!-- CARD UTAMA: PENERIMAAN & TOMBOL OPNAME -->
    <!-- ========================================== -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h5 class="card-title">Penerimaan sampai saat ini</h5>
                            <h2 class="display-4">Rp <?= number_format($saldo_sistem, 0, ',', '.') ?></h2>

                            <!-- RINCIAN KOMPONEN -->
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <small>Kas Awal</small>
                                    <br>
                                    <span class="h6">Rp <?= number_format($kas_awal, 0, ',', '.') ?></span>
                                </div>
                                <div class="col-md-4">
                                    <small>Penjualan / Pemasukan</small>
                                    <br>
                                    <span class="h6">Rp <?= number_format($saldo_sistem - $kas_awal, 0, ',', '.') ?></span>
                                </div>
                                <div class="col-md-4">
                                    <small>Pengeluaran</small>
                                    <br>
                                    <span class="h6 text-warning">Rp <?= number_format($total_pengeluaran, 0, ',', '.') ?></span>
                                </div>
                            </div>

                            <small>Update: <?= date('d/m/Y H:i:s') ?></small>

                            <!-- ... sisanya (opname terakhir, dll) ... -->
                        </div>
                        <!-- Tombol Opname -->
                        <div>
                            <button type="button" class="btn btn-light btn-lg" id="btnBukaOpname">
                                <i class="fas fa-cash-register"></i> Opname Kas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- ========================================== -->
    <!-- RIWAYAT OPNAME (10 TERAKHIR) -->
    <!-- ========================================== -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-history"></i> Riwayat Opname (10 Terakhir)</h6>

                </div>
                <div class="card-body p-0">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Saldo Fisik</th>
                                <th>Saldo Sistem</th>
                                <th>Selisih</th>
                                <th>Status</th>
                                <th>Diisi Oleh</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($riwayat as $row): ?>
                                <tr>
                                    <td><?= date('d/m/Y H:i', strtotime($row['tanggal'])) ?></td>
                                    <td>Rp <?= number_format($row['saldo_fisik'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format($row['saldo_sistem'], 0, ',', '.') ?></td>
                                    <td>Rp <?= number_format(abs($row['selisih']), 0, ',', '.') ?></td>
                                    <td>
                                        <span class="badge badge-<?= $row['status_selisih'] === 'sesuai' ? 'success' : ($row['status_selisih'] === 'lebih' ? 'warning' : 'danger') ?>">
                                            <?= strtoupper($row['status_selisih']) ?>
                                        </span>
                                    </td>
                                    <td><?= esc($row['user_nama'] ?? '-') ?></td>
                                    <td>
                                        <a href="<?= base_url('/cash/detail/' . $row['id']) ?>" class="btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i> Detail
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($riwayat)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">Belum ada data opname.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <!-- ========================================== -->
    <!-- MODAL OPNAME KAS (SEDERHANA, SATU LANGKAH) -->
    <!-- ========================================== -->
    <div class="modal fade" id="modalOpname" tabindex="-1" role="dialog" aria-labelledby="modalOpnameLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title mb-0" id="modalOpnameLabel">
                        <i class="fas fa-cash-register"></i> Opname Kas
                    </h6>
                    <button type="button" class="close" id="btnCloseModal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body py-2">
                    <div id="opnameLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>
                        <p class="mb-0 small">Menghitung saldo sistem...</p>
                    </div>

                    <form id="formOpname" style="display:none;">
                        <?= csrf_field() ?>

                        <!-- Saldo sistem: 1 baris ringkas -->
                        <div class="d-flex justify-content-between align-items-center bg-light rounded px-3 py-2 mb-3 small">
                            <span>Saldo Sistem: <strong>Rp <span id="saldoSistemDisplay">0</span></strong></span>
                            <span class="text-muted">
                                Awal: Rp <span id="kasAwalDisplay">0</span> ·
                                Masuk: Rp <span id="pemasukanDisplay">0</span> ·
                                Keluar: Rp <span id="pengeluaranDisplay">0</span>
                            </span>
                        </div>

                        <div class="row">
                            <!-- KIRI: input pecahan (2x2 grid ringkas) -->
                            <div class="col-md-7">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="mb-0 font-weight-bold small">Uang Fisik</label>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="btnResetPecahan">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                </div>
                                <div class="row" id="pecahanGrid">
                                    <?php $semuaPecahan = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100]; ?>
                                    <?php foreach ($semuaPecahan as $nominal): ?>
                                        <div class="col-6 mb-2">
                                            <div class="input-group input-group-sm">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text" style="min-width:78px;">
                                                        <?= number_format($nominal, 0, ',', '.') ?>
                                                    </span>
                                                </div>
                                                <input type="number"
                                                    class="form-control pecahan-jumlah text-center"
                                                    data-nominal="<?= $nominal ?>"
                                                    min="0" step="1" value="0" inputmode="numeric">
                                            </div>
                                            <small class="text-muted pecahan-total" data-nominal-total="<?= $nominal ?>">Rp 0</small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- KANAN: ringkasan + alasan -->
                            <div class="col-md-5">
                                <div class="alert alert-secondary mb-2 py-2" id="ringkasanOpname">
                                    <div class="d-flex justify-content-between small">
                                        <span>Saldo Sistem</span><strong id="ringkasanSaldoSistem">Rp 0</strong>
                                    </div>
                                    <div class="d-flex justify-content-between small mt-1">
                                        <span>Saldo Fisik</span><strong id="ringkasanSaldoFisik">Rp 0</strong>
                                    </div>
                                    <hr class="my-1">
                                    <div class="d-flex justify-content-between">
                                        <span>Selisih</span><strong id="ringkasanSelisih">Rp 0</strong>
                                    </div>
                                </div>

                                <div id="formAlasan" style="display:none;">
                                    <div class="form-group mb-2">
                                        <label for="alasanSelisih" class="small mb-1">Alasan Selisih <span class="text-danger" id="alasanRequired">*</span></label>
                                        <input type="text" class="form-control form-control-sm" id="alasanSelisih" name="alasan_selisih" placeholder="Penyebab selisih kas">
                                    </div>
                                    <div class="form-group mb-0">
                                        <label for="catatanOpname" class="small mb-1">Catatan (Opsional)</label>
                                        <textarea class="form-control form-control-sm" id="catatanOpname" name="catatan" rows="2"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <input type="hidden" name="saldo_fisik" id="saldoFisikInput" value="0">
                        <input type="hidden" name="saldo_fisik_final" id="saldoFisikFinal" value="0">
                        <input type="hidden" name="tanggal" id="tanggalOpname" value="<?= date('Y-m-d H:i:s') ?>">
                    </form>
                </div>

                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" id="btnBatalModal">Batal</button>
                    <button type="button" id="btnSimpanOpname" class="btn btn-success btn-sm" disabled>
                        <i class="fas fa-save"></i> Simpan Opname
                    </button>
                </div>
            </div>
        </div>
    </div>
    <!-- ========================================== -->
    <!-- JAVASCRIPT UNTUK MODAL OPNAME -->
    <!-- ========================================== -->
    <script>
        $(document).ready(function() {
            var saldoSistem = 0;
            var saldoFisik = 0;
            var selisihValue = 0;
            var statusSelisih = '';

            // ==========================================
            // SAAT MODAL DIBUKA
            // ==========================================
            $('#modalOpname').on('show.bs.modal', function() {
                resetModal();

                $('#opnameLoading').show();
                $('#formOpname').hide();

                $.ajax({
                    url: '<?= base_url('/cash/get-saldo-sistem') ?>',
                    type: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            saldoSistem = response.saldo_sistem;

                            $('#saldoSistemDisplay').text(formatRupiah(saldoSistem));
                            $('#kasAwalDisplay').text(formatRupiah(response.kas_awal));
                            $('#pemasukanDisplay').text(formatRupiah(response.pemasukan));
                            $('#pengeluaranDisplay').text(formatRupiah(response.pengeluaran));

                            $('#opnameLoading').hide();
                            $('#formOpname').show();
                            $('#saldoFisikInput').focus();
                        } else {
                            alert('Gagal mengambil data saldo sistem.');
                            $('#modalOpname').modal('hide');
                        }
                    },
                    error: function() {
                        alert('Terjadi kesalahan saat menghubungi server.');
                        $('#modalOpname').modal('hide');
                    }
                });
            });
            // Event untuk membuka modal
            $('#btnBukaOpname').on('click', function(e) {
                e.preventDefault();
                // Coba panggil modal
                if (typeof $('#modalOpname').modal === 'function') {
                    $('#modalOpname').modal('show');
                } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var modal = new bootstrap.Modal(document.getElementById('modalOpname'));
                    modal.show();
                } else {
                    // Fallback: tampilkan modal secara manual
                    $('#modalOpname').fadeIn();
                    $('#modalOpname').addClass('show');
                    $('body').addClass('modal-open');
                    // Trigger event show untuk memuat data
                    $('#modalOpname').trigger('show.bs.modal');
                }
            });

            // ==========================================
            // SIMPAN KAS AWAL (VIA AJAX)
            // ==========================================
            $('#btnSimpanKasAwal').on('click', function() {
                var nominal = parseFloat($('#kasAwalInput').val()) || 0;
                if (nominal <= 0) {
                    alert('Masukkan nominal kas awal yang valid.');
                    $('#kasAwalInput').focus();
                    return;
                }

                var catatan = $('#kasAwalCatatan').val().trim();

                var data = {
                    nominal: nominal,
                    catatan: catatan
                };

                $('#btnSimpanKasAwal').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Menyimpan...');

                $.ajax({
                    url: '<?= base_url('/cash/simpan-kas-awal') ?>',
                    type: 'POST',
                    data: JSON.stringify(data),
                    contentType: 'application/json',
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            alert('✅ ' + response.message);
                            location.reload(); // reload untuk update tampilan
                        } else {
                            alert('❌ ' + response.message);
                            $('#btnSimpanKasAwal').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Kas Awal');
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
                        $('#btnSimpanKasAwal').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Kas Awal');
                    }
                });
            });

            // Enter key di input nominal untuk submit
            $('#kasAwalInput').keypress(function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#btnSimpanKasAwal').click();
                }
            });
            // ==========================================
            // TUTUP MODAL DENGAN JAVASCRIPT MANUAL
            // ==========================================
            function closeModal() {
                var modalEl = document.getElementById('modalOpname');

                if (typeof $('#modalOpname').modal === 'function') {
                    // Bootstrap 4
                    $('#modalOpname').modal('hide');
                } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    // Bootstrap 5
                    var modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                } else {
                    // Fallback manual
                    modalEl.style.display = 'none';
                    modalEl.classList.remove('show');
                    document.body.classList.remove('modal-open');
                    // Hapus backdrop manual jika ada
                    var backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) backdrop.remove();
                }
            }

            // Tombol Close (X)
            $('#btnCloseModal').on('click', function(e) {
                e.preventDefault();
                closeModal();
            });

            // Tombol Batal
            $('#btnBatalModal').on('click', function(e) {
                e.preventDefault();
                closeModal();
            });

            // Tutup modal saat klik di luar modal (backdrop)
            $(document).on('click', function(e) {
                var modal = document.getElementById('modalOpname');
                if (modal && modal.classList.contains('show')) {
                    // Cek apakah klik di luar modal-content
                    var content = modal.querySelector('.modal-content');
                    if (content && !content.contains(e.target) && !e.target.closest('.modal-content')) {
                        closeModal();
                    }
                }
            });
            // ==========================================
            // RESET MODAL
            // ==========================================
            function resetModal() {
                $('#saldoFisikInput').val('');
                $('#alasanSelisih').val('');
                $('#catatanOpname').val('');
                $('#hasilSelisih').hide();
                $('#formAlasan').hide();
                $('#btnSimpanOpname').prop('disabled', true).html('<i class="fas fa-save"></i> Simpan Opname');
                saldoFisik = 0;
                selisihValue = 0;
                statusSelisih = '';
            }

            // ==========================================
            // HITUNG SELISIH
            // ==========================================
            // ==========================================
            // HITUNG UANG FISIK BERDASARKAN PECAHAN
            // ==========================================

            $(document).on(
                'input',
                '.pecahan-jumlah',
                function() {

                    hitungUangFisik();

                }
            );
            /*
            |--------------------------------------------------------------------------
            | ENTER → PINDAH KE PECAHAN BERIKUTNYA
            |--------------------------------------------------------------------------
            */

            $(document).on(
                'keydown',
                '.pecahan-jumlah',
                function(e) {

                    if (e.key !== 'Enter') {
                        return;
                    }

                    e.preventDefault();

                    const inputs =
                        Array.from(
                            document.querySelectorAll(
                                '.pecahan-jumlah'
                            )
                        );

                    const currentIndex =
                        inputs.indexOf(this);

                    if (currentIndex === -1) {
                        return;
                    }


                    /*
                    |--------------------------------------------------------------------------
                    | Cari input berikutnya
                    |--------------------------------------------------------------------------
                    */

                    const nextInput =
                        inputs[currentIndex + 1];


                    if (nextInput) {

                        nextInput.focus();

                        /*
                         * Pilih isi input supaya langsung bisa
                         * diganti tanpa harus Ctrl+A.
                         */
                        nextInput.select();

                    } else {

                        /*
                        |--------------------------------------------------------------------------
                        | Jika sudah pecahan terakhir
                        |--------------------------------------------------------------------------
                        |
                        | Langsung fokus ke alasan jika selisih ada,
                        | atau tombol Simpan jika tidak ada.
                        |
                        */

                        if (
                            Math.abs(selisihValue) > 0.01
                        ) {

                            $('#alasanSelisih')
                                .focus();

                        } else {

                            $('#btnSimpanOpname')
                                .focus();
                        }
                    }
                }
            );

            function hitungUangFisik() {

                var totalFisik = 0;


                // ==========================================
                // HITUNG SETIAP PECAHAN
                // ==========================================

                $('.pecahan-jumlah').each(
                    function() {

                        var input =
                            $(this);

                        var nominal =
                            parseInt(
                                input.data('nominal')
                            ) || 0;

                        var jumlah =
                            parseInt(
                                input.val()
                            ) || 0;


                        /*
                         * Jangan izinkan angka negatif.
                         */
                        if (jumlah < 0) {

                            jumlah = 0;

                            input.val(0);
                        }


                        var subtotal =
                            nominal * jumlah;


                        totalFisik +=
                            subtotal;


                        /*
                         * Update total per pecahan.
                         */
                        $('[data-nominal-total="' + nominal + '"]')
                            .text(
                                formatRupiah(
                                    subtotal
                                )
                            );

                    }
                );


                // ==========================================
                // UPDATE TOTAL FISIK
                // ==========================================

                $('#totalUangFisik')
                    .text(
                        formatRupiah(
                            totalFisik
                        )
                    );


                // ==========================================
                // SIMPAN NILAI UNTUK POST
                // ==========================================

                saldoFisik =
                    totalFisik;


                $('#saldoFisikInput')
                    .val(totalFisik);


                $('#saldoFisikFinal')
                    .val(totalFisik);


                // ==========================================
                // UPDATE RINGKASAN
                // ==========================================

                $('#ringkasanSaldoSistem')
                    .text(
                        formatRupiah(
                            saldoSistem
                        )
                    );


                $('#ringkasanSaldoFisik')
                    .text(
                        formatRupiah(
                            totalFisik
                        )
                    );


                // ==========================================
                // HITUNG SELISIH
                // ==========================================

                selisihValue =
                    totalFisik -
                    saldoSistem;


                // ==========================================
                // TENTUKAN STATUS
                // ==========================================

                if (
                    Math.abs(
                        selisihValue
                    ) < 0.01
                ) {

                    statusSelisih =
                        'sesuai';

                } else if (
                    selisihValue < 0
                ) {

                    statusSelisih =
                        'kurang';

                } else {

                    statusSelisih =
                        'lebih';
                }


                // ==========================================
                // TAMPILKAN SELISIH
                // ==========================================

                var prefix = '';

                if (selisihValue > 0) {
                    prefix = '+';
                }


                $('#ringkasanSelisih')
                    .text(
                        prefix +
                        formatRupiah(
                            selisihValue
                        )
                    );


                // ==========================================
                // WARNA RINGKASAN
                // ==========================================

                $('#ringkasanOpname')
                    .removeClass(
                        'alert-secondary alert-success alert-warning alert-danger'
                    );


                if (
                    statusSelisih ===
                    'sesuai'
                ) {

                    $('#ringkasanOpname')
                        .addClass(
                            'alert-success'
                        );

                } else if (
                    statusSelisih ===
                    'lebih'
                ) {

                    $('#ringkasanOpname')
                        .addClass(
                            'alert-warning'
                        );

                } else {

                    $('#ringkasanOpname')
                        .addClass(
                            'alert-danger'
                        );
                }


                // ==========================================
                // FORM ALASAN
                // ==========================================

                if (Math.abs(selisihValue) > 0.01) {
                    $('#formAlasan').show();

                    $('#alasanSelisih')

                        .val('diisi oleh <?= esc(session()->get('username') ?? 'Kasir') ?>')
                        .prop('required', true)
                        .prop('readonly', true);
                } else {
                    $('#formAlasan').hide();

                    $('#alasanSelisih')
                        .val('')
                        .prop('required', false)
                        .prop('readonly', false);
                }

                // ==========================================
                // AKTIFKAN TOMBOL SIMPAN
                // ==========================================

                $('#btnSimpanOpname')
                    .prop(
                        'disabled',
                        totalFisik <= 0
                    );
            }

            // ==========================================
            // SIMPAN OPNAME
            // ==========================================
            $('#btnSimpanOpname').click(function() {
                // Validasi alasan jika selisih != 0
                if (Math.abs(selisihValue) > 0.01) {
                    var alasan = $('#alasanSelisih').val().trim();
                    if (alasan === '') {
                        alert('Alasan selisih wajib diisi karena terjadi selisih.');
                        $('#alasanSelisih').focus();
                        return;
                    }
                }

                var data = {
                    saldo_fisik: saldoFisik,
                    alasan_selisih: $('#alasanSelisih').val().trim(),
                    catatan: $('#catatanOpname').val().trim(),
                    tanggal: $('#tanggalOpname').val()
                };

                $('#btnSimpanOpname').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Menyimpan...');

                $.ajax({
                    url: '<?= base_url('/cash/simpan-opname') ?>',
                    type: 'POST',
                    data: JSON.stringify(data),
                    contentType: 'application/json',
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#modalOpname').modal('hide');
                            var msg = '✅ ' + response.message + '\n\n';
                            msg += 'Saldo Sistem: Rp ' + formatRupiah(response.saldo_sistem) + '\n';
                            msg += 'Saldo Fisik:  Rp ' + formatRupiah(response.saldo_fisik) + '\n';
                            msg += 'Selisih:      Rp ' + formatRupiah(Math.abs(response.selisih)) + ' (' + response.status_selisih.toUpperCase() + ')';
                            alert(msg);
                            location.reload();
                        } else {
                            alert('❌ Error: ' + response.message);
                            $('#btnSimpanOpname').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Opname');
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
                        $('#btnSimpanOpname').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Opname');
                    }
                });
            });

            // ==========================================
            // FUNGSI BANTU: Format Rupiah
            // ==========================================
            function formatRupiah(angka) {
                if (angka === undefined || angka === null || isNaN(angka)) {
                    return '0';
                }
                return new Intl.NumberFormat('id-ID').format(Math.round(angka));
            }

            // ==========================================
            // ENTER KEY UNTUK NAVIGASI
            // ==========================================
            function resetModal() {

                /*
                |--------------------------------------------------------------------------
                | Reset seluruh pecahan
                |--------------------------------------------------------------------------
                */

                $('.pecahan-jumlah')
                    .val(0);


                $('.pecahan-total')
                    .text('Rp 0');


                /*
                |--------------------------------------------------------------------------
                | Reset saldo fisik
                |--------------------------------------------------------------------------
                */

                $('#saldoFisikInput')
                    .val(0);

                $('#saldoFisikFinal')
                    .val(0);


                /*
                |--------------------------------------------------------------------------
                | Reset ringkasan
                |--------------------------------------------------------------------------
                */

                $('#totalUangFisik')
                    .text('Rp 0');

                $('#ringkasanSaldoSistem')
                    .text('Rp 0');

                $('#ringkasanSaldoFisik')
                    .text('Rp 0');

                $('#ringkasanSelisih')
                    .text('Rp 0');


                $('#ringkasanOpname')
                    .removeClass(
                        'alert-success alert-warning alert-danger'
                    )
                    .addClass(
                        'alert-secondary'
                    );


                /*
                |--------------------------------------------------------------------------
                | Reset alasan & catatan
                |--------------------------------------------------------------------------
                */

                $('#alasanSelisih')
                    .val('');

                $('#catatanOpname')
                    .val('');


                $('#hasilSelisih')
                    .hide();

                $('#formAlasan')
                    .hide();


                /*
                |--------------------------------------------------------------------------
                | Reset tombol
                |--------------------------------------------------------------------------
                */

                $('#btnSimpanOpname')
                    .prop(
                        'disabled',
                        true
                    )
                    .html(
                        '<i class="fas fa-save"></i> Simpan Opname'
                    );


                /*
                |--------------------------------------------------------------------------
                | Reset variabel
                |--------------------------------------------------------------------------
                */

                saldoFisik = 0;
                selisihValue = 0;
                statusSelisih = '';
            }
            $('#btnResetPecahan').on(
                'click',
                function() {

                    $('.pecahan-jumlah')
                        .val(0);

                    hitungUangFisik();

                    $('.pecahan-jumlah')
                        .first()
                        .focus();
                }
            );

        });
    </script>