<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-clock-rotate-left"></i> Log Revisi Closing Kas</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-circle-info"></i>
            Riwayat setiap <strong>perubahan</strong> closing kas yang sudah tersimpan
            (TODO-BL28). Nilai "Sebelum" dan "Sesudah" adalah snapshot lengkap baris
            closing pada saat revisi. Pembuatan closing pertama tidak dicatat di sini;
            nilai awalnya terlihat sebagai "Sebelum" pada revisi pertama. Halaman ini murni
            <strong>read-only</strong>.
        </div>

        <div class="row g-3 align-items-end mb-3">
            <div class="col-md-5">
                <label class="form-label fw-semibold">Rentang Tanggal Revisi</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                    <input type="text" id="filterLogClosingKas" class="form-control"
                        placeholder="Pilih rentang tanggal" readonly autocomplete="off">
                </div>
                <input type="hidden" name="tanggal_awal" id="tanggal_awal" value="">
                <input type="hidden" name="tanggal_akhir" id="tanggal_akhir" value="">
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-primary" id="btnFilterLogClosingKas">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <button type="button" class="btn btn-secondary ms-1" id="btnResetLogClosingKas">
                    <i class="fas fa-rotate-left"></i> Reset
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover align-middle" id="tableLogClosingKas">
                <thead class="table-light">
                    <tr>
                        <th>Waktu Revisi</th>
                        <th>Admin</th>
                        <th>Tanggal Closing</th>
                        <th>Saldo Sistem (Sebelum → Sesudah)</th>
                        <th>Saldo Fisik (Sebelum → Sesudah)</th>
                        <th>Selisih (Sebelum → Sesudah)</th>
                    </tr>
                </thead>
                <tbody id="bodyLogClosingKas">
                    <tr><td colspan="6" class="text-center text-muted">Memuat...</td></tr>
                </tbody>
            </table>
        </div>

        <nav>
            <ul class="pagination pagination-sm" id="paginasiLogClosingKas"></ul>
        </nav>
    </div>
</div>

<script>
(function () {
    let halamanAktif = 1;

    function el(id) { return document.getElementById(id); }

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function formatWaktu(iso) {
        if (!iso) return '-';
        return String(iso).replace('T', ' ').slice(0, 19);
    }

    function formatRupiah(n) {
        if (n === null || n === undefined) return '-';
        return 'Rp' + Number(n).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    }

    function renderPerubahan(sebelum, sesudah) {
        if (Number(sebelum) === Number(sesudah)) {
            return formatRupiah(sebelum);
        }
        return '<span class="text-muted text-decoration-line-through">' + formatRupiah(sebelum) + '</span>'
            + ' &rarr; <strong>' + formatRupiah(sesudah) + '</strong>';
    }

    function renderBaris(rows) {
        const tbody = el('bodyLogClosingKas');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Tidak ada data.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(function (row) {
            return '<tr>'
                + '<td>' + escHtml(formatWaktu(row.created_at)) + '</td>'
                + '<td>' + escHtml(row.admin) + '</td>'
                + '<td>' + escHtml(row.tanggal_closing ? String(row.tanggal_closing).slice(0, 10) : '-') + '</td>'
                + '<td>' + renderPerubahan(row.saldo_sistem_sebelum, row.saldo_sistem_sesudah) + '</td>'
                + '<td>' + renderPerubahan(row.saldo_fisik_sebelum, row.saldo_fisik_sesudah) + '</td>'
                + '<td>' + renderPerubahan(row.selisih_sebelum, row.selisih_sesudah) + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPaginasi(halaman, perHalaman, total) {
        const totalHalaman = Math.max(1, Math.ceil(total / perHalaman));
        const ul = el('paginasiLogClosingKas');
        let html = '';
        for (let i = 1; i <= totalHalaman; i++) {
            html += '<li class="page-item ' + (i === halaman ? 'active' : '') + '">'
                + '<a class="page-link" href="#" data-halaman="' + i + '">' + i + '</a></li>';
        }
        ul.innerHTML = html;
        ul.querySelectorAll('a[data-halaman]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                halamanAktif = parseInt(this.dataset.halaman, 10);
                muatData();
            });
        });
    }

    async function muatData() {
        const tbody = el('bodyLogClosingKas');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Memuat...</td></tr>';

        const params = new URLSearchParams({ halaman: halamanAktif });
        const tanggalAwal = el('tanggal_awal').value;
        const tanggalAkhir = el('tanggal_akhir').value;
        if (tanggalAwal) params.set('tanggal_awal', tanggalAwal);
        if (tanggalAkhir) params.set('tanggal_akhir', tanggalAkhir);

        try {
            const res = await fetch('<?= base_url('/log-closing-kas/data') ?>?' + params.toString());
            const json = await res.json();
            if (json.status !== 'success') {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Gagal memuat data.</td></tr>';
                return;
            }
            renderBaris(json.data);
            renderPaginasi(json.halaman, json.per_halaman, json.total);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Gagal memuat data: ' + escHtml(e.message) + '</td></tr>';
        }
    }

    // Pemilih rentang tanggal standar proyek (App\Config\DatePicker +
    // public/assets/js/date-range.js, dimuat global oleh layout).
    (function initPicker() {
        const $input = $('#filterLogClosingKas');
        const $awal = $('#tanggal_awal');
        const $akhir = $('#tanggal_akhir');
        if (!$input.length) return;

        $input.daterangepicker({
            startDate: $awal.val() ? moment($awal.val(), 'YYYY-MM-DD') : moment(),
            endDate: $akhir.val() ? moment($akhir.val(), 'YYYY-MM-DD') : moment(),
            locale: AuliaDateRange.locale(),
            ranges: AuliaDateRange.ranges(),
            showDropdowns: true,
            opens: 'left'
        });

        $input.on('apply.daterangepicker', function (ev, picker) {
            $awal.val(picker.startDate.format('YYYY-MM-DD'));
            $akhir.val(picker.endDate.format('YYYY-MM-DD'));
            $input.val(
                picker.startDate.format('DD/MM/YYYY') + ' - ' + picker.endDate.format('DD/MM/YYYY')
            );
        });

        if ($awal.val() && $akhir.val()) {
            $input.val(
                $awal.val().split('-').reverse().join('/') + ' - ' +
                $akhir.val().split('-').reverse().join('/')
            );
        }
    })();

    el('btnFilterLogClosingKas').addEventListener('click', function () {
        halamanAktif = 1;
        muatData();
    });

    el('btnResetLogClosingKas').addEventListener('click', function () {
        const $input = $('#filterLogClosingKas');
        $('#tanggal_awal').val('');
        $('#tanggal_akhir').val('');
        const drp = $input.data('daterangepicker');
        if (drp) {
            drp.setStartDate(moment());
            drp.setEndDate(moment());
        }
        $input.val('');
        halamanAktif = 1;
        muatData();
    });

    muatData();
})();
</script>
