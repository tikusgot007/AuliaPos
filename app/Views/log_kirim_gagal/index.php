<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-triangle-exclamation"></i> Log Kiriman Gagal (WhatsApp)</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-circle-info"></i>
            Daftar kiriman keluar (teks/media/edit) dari Inbox yang <strong>gagal</strong>
            diteruskan Gateway WhatsApp. Pratinjau isi (<code>preview_text</code>,
            metadata media) hanya tersedia untuk kejadian <strong>setelah 2026-10-10</strong>;
            baris lebih lama tidak punya pratinjau. Halaman ini murni <strong>read-only</strong>.
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
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnFilterLogKirimGagal">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover align-middle" id="tableLogKirimGagal">
                <thead class="table-light">
                    <tr>
                        <th>Waktu</th>
                        <th>Kasir</th>
                        <th>Percakapan</th>
                        <th>Aksi</th>
                        <th>Isi</th>
                        <th>Status</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody id="bodyLogKirimGagal">
                    <tr><td colspan="7" class="text-center text-muted">Memuat...</td></tr>
                </tbody>
            </table>
        </div>

        <nav>
            <ul class="pagination pagination-sm" id="paginasiLogKirimGagal"></ul>
        </nav>
    </div>
</div>

<script>
(function () {
    const CONTEXT_LABEL = {
        kirimKeConversation: 'Kirim teks',
        kirimTeruskanTeks: 'Teruskan teks',
        kirimMedia: 'Kirim media',
        editPesan: 'Edit pesan',
        hapusPesan: 'Hapus pesan',
    };
    const OUTCOME_BADGE = {
        definitive: 'bg-danger',
        unresolved: 'bg-warning text-dark',
        network: 'bg-secondary',
    };

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

    function renderIsi(row) {
        if (row.media_type) {
            const ukuran = row.media_size ? (Math.round(row.media_size / 1024) + ' KB') : '';
            return '<span class="badge bg-light text-dark border">' + escHtml(row.media_type) + '</span> '
                + escHtml(row.media_file_name || '(tanpa nama)') + (ukuran ? ' · ' + ukuran : '')
                + (row.preview_text ? '<br><span class="text-muted small">Caption: ' + escHtml(row.preview_text) + '</span>' : '');
        }
        if (row.preview_text) {
            return escHtml(row.preview_text);
        }
        return '<span class="text-muted fst-italic">tidak ada pratinjau</span>';
    }

    function renderBaris(rows) {
        const tbody = el('bodyLogKirimGagal');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Tidak ada data.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(function (row) {
            const kontak = row.conversation_id
                ? '<a href="' + baseUrlInbox(row.conversation_id) + '" target="_blank">' + escHtml(row.kontak) + '</a>'
                + (row.phone ? '<br><span class="text-muted small">' + escHtml(row.phone) + '</span>' : '')
                : '<span class="text-muted">(percakapan tidak diketahui)</span>';

            const badgeClass = OUTCOME_BADGE[row.outcome_kind] || 'bg-secondary';

            return '<tr>'
                + '<td>' + escHtml(formatWaktu(row.created_at)) + '</td>'
                + '<td>' + escHtml(row.kasir) + '</td>'
                + '<td>' + kontak + '</td>'
                + '<td>' + escHtml(CONTEXT_LABEL[row.context] || row.context || '-') + '</td>'
                + '<td style="max-width:320px;">' + renderIsi(row) + '</td>'
                + '<td><span class="badge ' + badgeClass + '">' + escHtml(row.outcome_kind) + '</span></td>'
                + '<td><code>' + escHtml(row.error_code || '-') + '</code><br><span class="small text-muted">' + escHtml(row.error_message || '') + '</span></td>'
                + '</tr>';
        }).join('');
    }

    function baseUrlInbox(conversationId) {
        return '<?= base_url('/inbox') ?>?conversation_id=' + encodeURIComponent(conversationId);
    }

    function renderPaginasi(halaman, perHalaman, total) {
        const totalHalaman = Math.max(1, Math.ceil(total / perHalaman));
        const ul = el('paginasiLogKirimGagal');
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
        const tbody = el('bodyLogKirimGagal');
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Memuat...</td></tr>';

        const params = new URLSearchParams({ halaman: halamanAktif });
        const tanggalMulai = el('filterTanggalMulai').value;
        const tanggalSampai = el('filterTanggalSampai').value;
        if (tanggalMulai) params.set('tanggal_mulai', tanggalMulai);
        if (tanggalSampai) params.set('tanggal_sampai', tanggalSampai);

        try {
            const res = await fetch('<?= base_url('/log-kirim-gagal/data') ?>?' + params.toString());
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

    el('btnFilterLogKirimGagal').addEventListener('click', function () {
        halamanAktif = 1;
        muatData();
    });

    muatData();
})();
</script>
