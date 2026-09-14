<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Closing Kas</h1>
        <a href="<?= base_url('/cash') ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
        </a>
    </div>

    <!-- Month picker -->
    <div class="card mb-4">
        <div class="card-body">
            <form class="form-inline" id="formBulan">
                <div class="form-group mr-2">
                    <label for="bulanPicker" class="mr-1">Bulan</label>
                    <input type="month" class="form-control" id="bulanPicker" name="bulan" value="<?= esc($bulan) ?>">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Tampilkan</button>
            </form>
        </div>
    </div>

    <!-- Tabel Closing Kas -->
    <div class="card">
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Saldo Sistem</th>
                        <th>Kas Fisik</th>
                        <th>Selisih</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody id="tabelClosingBody">
                    <!-- diisi JS: renderBaris() -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Tambah/Edit Closing Kas -->
<div class="modal fade" id="modalClosing" tabindex="-1" role="dialog" aria-labelledby="modalClosingLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="modalClosingLabel">
                    <i class="fas fa-clipboard-check"></i> Closing Kas &mdash; <span id="modalClosingTanggal"></span>
                </h6>
                <button type="button" class="close" id="btnCloseModalClosing" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body py-2">
                <div id="closingLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"><span class="sr-only">Loading...</span></div>
                    <p class="mb-0 small">Menghitung saldo sistem...</p>
                </div>

                <form id="formClosing" style="display:none;">
                    <input type="hidden" id="closingTanggalInput">

                    <!-- Saldo sistem & opname kasir terakhir: ringkasan -->
                    <div class="d-flex justify-content-between align-items-center bg-light rounded px-3 py-2 mb-2 small">
                        <span>Saldo Sistem: <strong>Rp <span id="closingSaldoSistemDisplay">0</span></strong></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center bg-light rounded px-3 py-2 mb-3 small">
                        <span>Opname Kasir Terakhir: <strong id="closingOpnameDisplay">&mdash;</strong></span>
                        <span class="text-muted">Selisih thd Opname: <strong id="closingSelisihOpnameDisplay">&mdash;</strong></span>
                    </div>

                    <div class="row">
                        <!-- KIRI: input pecahan -->
                        <div class="col-md-7">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="mb-0 font-weight-bold small">Kas Fisik</label>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="btnResetPecahanClosing">
                                    <i class="fas fa-undo"></i> Reset
                                </button>
                            </div>
                            <div class="row" id="pecahanGridClosing">
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
                                                class="form-control pecahan-jumlah-closing text-center"
                                                data-nominal="<?= $nominal ?>"
                                                min="0" step="1" value="0" inputmode="numeric">
                                        </div>
                                        <small class="text-muted pecahan-total-closing" data-nominal-total="<?= $nominal ?>">Rp 0</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- KANAN: ringkasan -->
                        <div class="col-md-5">
                            <div class="alert alert-secondary mb-2 py-2" id="ringkasanClosing">
                                <div class="d-flex justify-content-between small">
                                    <span>Saldo Sistem</span><strong id="ringkasanClosingSaldoSistem">Rp 0</strong>
                                </div>
                                <div class="d-flex justify-content-between small mt-1">
                                    <span>Kas Fisik</span><strong id="totalUangFisikClosing">Rp 0</strong>
                                </div>
                                <hr class="my-1">
                                <div class="d-flex justify-content-between">
                                    <span>Selisih</span><strong id="ringkasanClosingSelisih">Rp 0</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary" id="btnBatalModalClosing">Batal</button>
                <button type="button" class="btn btn-primary" id="btnSimpanClosing" disabled>
                    <i class="fas fa-save"></i> Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        var SEMUA_PECAHAN = <?= json_encode($semuaPecahan) ?>;
        var baseUrl = '<?= base_url() ?>';
        var barisData = <?= json_encode($baris) ?>;
        var currentTanggal = null;

        function formatRupiah(angka) {
            if (angka === undefined || angka === null || isNaN(angka)) {
                return '0';
            }
            return new Intl.NumberFormat('id-ID').format(Math.round(angka));
        }

        function renderBaris() {
            var $tbody = $('#tabelClosingBody');
            $tbody.empty();

            if (!barisData.length) {
                $tbody.append('<tr><td colspan="6" class="text-center text-muted py-3">Tidak ada data.</td></tr>');
                return;
            }

            barisData.forEach(function(row) {
                var status = row.sudah_closing
                    ? '<span class="badge badge-success">Sudah Closing</span>'
                    : '<span class="badge badge-warning">Belum Closing</span>';

                var aksiLabel = row.sudah_closing ? 'Edit' : 'Tambah';
                var aksiIcon = row.sudah_closing ? 'fa-edit' : 'fa-plus';
                var disabled = row.is_masa_depan_atau_hari_ini ? 'disabled' : '';

                var tr = $('<tr>').attr('data-tanggal', row.tanggal);
                tr.append($('<td>').text(row.tanggal));
                tr.append($('<td>').text(row.sudah_closing ? 'Rp ' + formatRupiah(row.saldo_sistem) : '—'));
                tr.append($('<td>').text(row.sudah_closing ? 'Rp ' + formatRupiah(row.saldo_fisik) : '—'));
                tr.append($('<td>').text(row.sudah_closing ? 'Rp ' + formatRupiah(row.selisih) : '—'));
                tr.append($('<td>').html(status));
                tr.append($('<td>').html(
                    '<button type="button" class="btn btn-sm btn-primary btn-buka-closing" data-tanggal="' + row.tanggal + '" ' + disabled + '>' +
                    '<i class="fas ' + aksiIcon + '"></i> ' + aksiLabel + '</button>'
                ));
                $tbody.append(tr);
            });
        }

        function updateRow(tanggal, data) {
            var idx = barisData.findIndex(function(r) { return r.tanggal === tanggal; });
            if (idx === -1) return;
            barisData[idx].sudah_closing = true;
            barisData[idx].saldo_sistem = data.saldo_sistem;
            barisData[idx].saldo_fisik = data.saldo_fisik;
            barisData[idx].selisih = data.selisih;
            renderBaris();
        }

        renderBaris();

        // Ganti bulan (tanpa reload halaman)
        $('#formBulan').on('submit', function(e) {
            e.preventDefault();
            var bulan = $('#bulanPicker').val();
            if (!bulan) return;

            $.ajax({
                url: baseUrl + '/cash/closing/data',
                type: 'GET',
                data: { bulan: bulan },
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        barisData = response.baris;
                        renderBaris();
                        history.replaceState(null, '', baseUrl + '/cash/closing?bulan=' + bulan);
                    } else {
                        alert('❌ ' + response.message);
                    }
                },
                error: function() {
                    alert('Terjadi kesalahan saat mengambil data.');
                }
            });
        });

        function hitungUangFisikClosing() {
            var totalFisik = 0;

            $('.pecahan-jumlah-closing').each(function() {
                var input = $(this);
                var nominal = parseInt(input.data('nominal')) || 0;
                var jumlah = parseInt(input.val()) || 0;

                if (jumlah < 0) {
                    jumlah = 0;
                    input.val(0);
                }

                var subtotal = nominal * jumlah;
                totalFisik += subtotal;

                $('[data-nominal-total="' + nominal + '"].pecahan-total-closing')
                    .text('Rp ' + formatRupiah(subtotal));
            });

            $('#totalUangFisikClosing').text('Rp ' + formatRupiah(totalFisik));

            var saldoSistem = parseFloat($('#closingSaldoSistemDisplay').data('raw')) || 0;
            var selisih = totalFisik - saldoSistem;
            $('#ringkasanClosingSelisih').text('Rp ' + formatRupiah(selisih));

            return totalFisik;
        }

        $(document).on('input', '.pecahan-jumlah-closing', hitungUangFisikClosing);

        $('#btnResetPecahanClosing').on('click', function() {
            $('.pecahan-jumlah-closing').val(0);
            hitungUangFisikClosing();
        });

        function closeModalClosing() {
            var modalEl = document.getElementById('modalClosing');
            if (typeof $('#modalClosing').modal === 'function') {
                $('#modalClosing').modal('hide');
            } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            } else {
                modalEl.style.display = 'none';
                modalEl.classList.remove('show');
                document.body.classList.remove('modal-open');
                var backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) backdrop.remove();
            }
        }

        $('#btnCloseModalClosing, #btnBatalModalClosing').on('click', function(e) {
            e.preventDefault();
            closeModalClosing();
        });

        function resetModalClosing() {
            $('.pecahan-jumlah-closing').val(0);
            $('#ringkasanClosingSelisih').text('Rp 0');
            $('#totalUangFisikClosing').text('Rp 0');
            $('#btnSimpanClosing').prop('disabled', true);
        }

        function openModalClosing(tanggal) {
            currentTanggal = tanggal;
            resetModalClosing();
            $('#modalClosingTanggal').text(tanggal);
            $('#closingTanggalInput').val(tanggal);
            $('#formClosing').hide();
            $('#closingLoading').show();

            if (typeof $('#modalClosing').modal === 'function') {
                $('#modalClosing').modal('show');
            } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                new bootstrap.Modal(document.getElementById('modalClosing')).show();
            }

            $.ajax({
                url: baseUrl + '/cash/closing/detail',
                type: 'GET',
                data: { tanggal: tanggal },
                dataType: 'json',
                success: function(response) {
                    $('#closingLoading').hide();

                    if (response.status !== 'success') {
                        alert('❌ ' + response.message);
                        closeModalClosing();
                        return;
                    }

                    $('#formClosing').show();
                    $('#closingSaldoSistemDisplay').text(formatRupiah(response.saldo_sistem)).data('raw', response.saldo_sistem);
                    $('#ringkasanClosingSaldoSistem').text('Rp ' + formatRupiah(response.saldo_sistem));

                    if (response.opname_terakhir) {
                        $('#closingOpnameDisplay').text('Rp ' + formatRupiah(response.opname_terakhir.saldo_fisik) + ' (' + response.opname_terakhir.tanggal + ')');
                    } else {
                        $('#closingOpnameDisplay').text('—');
                        $('#closingSelisihOpnameDisplay').text('—');
                    }

                    $('#btnSimpanClosing').prop('disabled', false);
                    hitungUangFisikClosing();
                    updateSelisihOpname();
                },
                error: function() {
                    $('#closingLoading').hide();
                    alert('Terjadi kesalahan saat menghubungi server.');
                    closeModalClosing();
                }
            });
        }

        function updateSelisihOpname() {
            var opnameText = $('#closingOpnameDisplay').text();
            if (opnameText === '—') return;
            var opnameNominal = parseFloat(opnameText.replace(/[^\d]/g, '')) || 0;
            var totalFisik = parseFloat($('#totalUangFisikClosing').text().replace(/[^\d]/g, '')) || 0;
            $('#closingSelisihOpnameDisplay').text('Rp ' + formatRupiah(totalFisik - opnameNominal));
        }

        $(document).on('input', '.pecahan-jumlah-closing', updateSelisihOpname);

        $(document).on('click', '.btn-buka-closing', function() {
            if ($(this).prop('disabled')) return;
            openModalClosing($(this).data('tanggal').toString());
        });

        $('#btnSimpanClosing').on('click', function() {
            var saldoFisik = hitungUangFisikClosing();

            $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Menyimpan...');

            $.ajax({
                url: baseUrl + '/cash/closing/simpan',
                type: 'POST',
                data: JSON.stringify({ tanggal: currentTanggal, saldo_fisik: saldoFisik }),
                contentType: 'application/json',
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        updateRow(response.tanggal, response);
                        closeModalClosing();
                    } else {
                        alert('❌ ' + response.message);
                        $('#btnSimpanClosing').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan');
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
                    $('#btnSimpanClosing').prop('disabled', false).html('<i class="fas fa-save"></i> Simpan');
                }
            });
        });
    })();
</script>
