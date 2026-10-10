<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-clock-rotate-left"></i> Log Audit Kas (Edit/Hapus Pengeluaran)</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-circle-info"></i>
            Riwayat setiap <strong>edit</strong> dan <strong>hapus</strong> pengeluaran kas
            (TODO-BL10). Nilai "Sebelum" dan "Sesudah" adalah snapshot lengkap baris
            pengeluaran pada saat kejadian. Halaman ini murni <strong>read-only</strong>.
        </div>

        <div class="row g-2 mb-3">
            <div class="col-auto">
                <label class="form-label small mb-0">Dari tanggal</label>
                <input type="date" class="form-control form-control-sm" id="filterTanggalMulai">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0">Sampai tanggal</label>
                <input type="date" class="form-control form-control-sm" id="filterTanggalSampai">
            </div>
            <div class="col-auto d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnFilterLogAuditKas">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover align-middle" id="tableLogAuditKas">
                <thead class="table-light">
                    <tr>
                        <th>Waktu</th>
                        <th>Kasir</th>
                        <th>Aksi</th>
                        <th>Kategori</th>
                        <th>Tanggal Transaksi</th>
                        <th>Nominal (Sebelum → Sesudah)</th>
                        <th>Keterangan (Sebelum → Sesudah)</th>
                    </tr>
                </thead>
                <tbody id="bodyLogAuditKas">
                    <tr><td colspan="7" class="text-center text-muted">Memuat...</td></tr>
                </tbody>
            </table>
        </div>

        <nav>
            <ul class="pagination pagination-sm" id="paginasiLogAuditKas"></ul>
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

    function renderPerubahan(sebelum, sesudah, formatter) {
        formatter = formatter || function (x) { return escHtml(x); };
        if (sebelum === sesudah || sesudah === undefined) {
            return formatter(sebelum);
        }
        return '<span class="text-muted text-decoration-line-through">' + formatter(sebelum) + '</span>'
            + ' &rarr; <strong>' + formatter(sesudah) + '</strong>';
    }

    function renderBaris(rows) {
        const tbody = el('bodyLogAuditKas');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Tidak ada data.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(function (row) {
            const badgeAksi = row.aksi === 'delete'
                ? '<span class="badge bg-danger">Hapus</span>'
                : '<span class="badge bg-warning text-dark">Edit</span>';

            return '<tr>'
                + '<td>' + escHtml(formatWaktu(row.created_at)) + '</td>'
                + '<td>' + escHtml(row.kasir) + '</td>'
                + '<td>' + badgeAksi + '</td>'
                + '<td>' + escHtml(row.kategori || '-') + '</td>'
                + '<td>' + escHtml(row.tanggal_transaksi ? String(row.tanggal_transaksi).slice(0, 10) : '-') + '</td>'
                + '<td>' + renderPerubahan(row.nominal_sebelum, row.nominal_sesudah, formatRupiah) + '</td>'
                + '<td style="max-width:280px;">' + renderPerubahan(row.keterangan_sebelum, row.keterangan_sesudah) + '</td>'
                + '</tr>';
        }).join('');
    }

    function renderPaginasi(halaman, perHalaman, total) {
        const totalHalaman = Math.max(1, Math.ceil(total / perHalaman));
        const ul = el('paginasiLogAuditKas');
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
        const tbody = el('bodyLogAuditKas');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Memuat...</td></tr>';

        const params = new URLSearchParams({ halaman: halamanAktif });
        const tanggalMulai = el('filterTanggalMulai').value;
        const tanggalSampai = el('filterTanggalSampai').value;
        if (tanggalMulai) params.set('tanggal_mulai', tanggalMulai);
        if (tanggalSampai) params.set('tanggal_sampai', tanggalSampai);

        try {
            const res = await fetch('<?= base_url('/log-audit-kas/data') ?>?' + params.toString());
            const json = await res.json();
            if (json.status !== 'success') {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Gagal memuat data.</td></tr>';
                return;
            }
            renderBaris(json.data);
            renderPaginasi(json.halaman, json.per_halaman, json.total);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Gagal memuat data: ' + escHtml(e.message) + '</td></tr>';
        }
    }

    el('btnFilterLogAuditKas').addEventListener('click', function () {
        halamanAktif = 1;
        muatData();
    });

    muatData();
})();
</script>
