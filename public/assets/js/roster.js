(function () {
    'use strict';

    const cfg = window.ROSTER_CONFIG || {};

    const state = {
        mingguAwal: cfg.mingguAwal,
        bulan: null,
        mode: 'mingguan',
        sortKey: null,
        sortDir: 'asc',
    };

    // Sama persis dengan jadwal.js -- konsisten di seluruh modul.
    const SHIFT_URUTAN = { P: 1, S: 2, PM: 3, L: 4 };

    function el(id) {
        return document.getElementById(id);
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    // Sama persis dengan fix di jadwal.js -- JANGAN pakai toISOString(),
    // itu mengonversi ke UTC dan menggeser tanggal mundur 1 hari di
    // timezone WIB (UTC+7). Murni pakai komponen tanggal lokal.
    function tanggalPlus(tanggal, hariOffset) {
        const [tahun, bulan, hari] = tanggal.split('-').map(Number);
        const d = new Date(tahun, bulan - 1, hari);
        d.setDate(d.getDate() + hariOffset);
        const yy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return yy + '-' + mm + '-' + dd;
    }

    function formatTanggalIndo(tanggal) {
        const [tahun, bulan, hari] = tanggal.split('-').map(Number);
        const d = new Date(tahun, bulan - 1, hari);
        return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    async function apiGet(url) {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        return res.json();
    }

    // ================================================================
    // RINGKASAN HARI INI
    // ================================================================

    function renderHariIni(data) {
        el('rosterHariIniTanggalLabel').textContent = ' — ' + formatTanggalIndo(data.tanggal);

        const renderList = (containerId, list) => {
            const box = el(containerId);
            if (!list.length) {
                box.innerHTML = '<small class="text-muted">Tidak ada</small>';
                return;
            }
            box.innerHTML = list.map(function (o) {
                return '<div class="roster-orang' + (o.saya ? ' saya' : '') + '">' + escapeHtml(o.nama) + '</div>';
            }).join('');
        };

        renderList('rosterHariIniPagi', data.pagi);
        renderList('rosterHariIniSiang', data.siang);
        renderList('rosterHariIniPM', data.pm);
        renderList('rosterHariIniLibur', data.libur);
        renderList('rosterHariIniBelum', data.belum_dijadwalkan);
    }

    // ================================================================
    // MINGGUAN
    // ================================================================

    function sortKaryawan(karyawanList, sortKey, sortDir, data) {
        if (!sortKey) return karyawanList;

        const arr = karyawanList.slice();
        const factor = sortDir === 'desc' ? -1 : 1;

        arr.sort(function (a, b) {
            if (sortKey === 'divisi') {
                const va = (a.divisi || '').toLowerCase();
                const vb = (b.divisi || '').toLowerCase();
                if (!va && vb) return 1;
                if (va && !vb) return -1;
                if (!va && !vb) return 0;
                return va < vb ? -1 * factor : va > vb ? 1 * factor : 0;
            }

            if (sortKey.indexOf('hari:') === 0) {
                const i = parseInt(sortKey.split(':')[1], 10);
                const tanggal = tanggalPlus(data.minggu_awal, i);
                const shiftA = (data.peta[a.id] || {})[tanggal] || '';
                const shiftB = (data.peta[b.id] || {})[tanggal] || '';
                const uA = SHIFT_URUTAN[shiftA] || 99;
                const uB = SHIFT_URUTAN[shiftB] || 99;

                if (uA === 99 && uB !== 99) return 1;
                if (uA !== 99 && uB === 99) return -1;

                return (uA - uB) * factor;
            }

            return 0;
        });

        return arr;
    }

    function updateSortIndicatorMingguan() {
        document.querySelectorAll('#tabelRosterMingguan .sortable-header').forEach(function (th) {
            const icon = th.querySelector('.sort-icon');
            if (th.dataset.sortKey === state.sortKey) {
                th.classList.add('sort-active');
                icon.className = 'fas sort-icon ' + (state.sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
            } else {
                th.classList.remove('sort-active');
                icon.className = 'fas fa-sort sort-icon';
            }
        });
    }

    function renderMingguan(data) {
        state.mingguAwal = data.minggu_awal;
        state.matrixTerakhir = data;

        el('rosterLabelMinggu').textContent =
            formatTanggalIndo(data.minggu_awal) + ' – ' + formatTanggalIndo(data.minggu_akhir);

        const tbody = el('rosterMingguanBody');
        tbody.innerHTML = '';

        const karyawanUrut = sortKaryawan(data.karyawan, state.sortKey, state.sortDir, data);

        if (!karyawanUrut.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Tidak ada karyawan yang cocok dengan filter.</td></tr>';
            updateSortIndicatorMingguan();
            return;
        }

        karyawanUrut.forEach(function (k) {
            const saya = Number(k.id) === Number(cfg.userIdLogin);
            let baris = '<td' + (saya ? ' class="roster-orang saya"' : '') + '>' + escapeHtml(k.nama) +
                (k.is_active == 0 ? ' <span class="badge bg-secondary">Nonaktif</span>' : '') +
                '<br><small class="text-muted">' + escapeHtml(k.inisial || '') + ' · ' + escapeHtml(k.divisi || '-') + '</small></td>';

            for (let i = 0; i < 7; i++) {
                const tanggal = tanggalPlus(data.minggu_awal, i);
                const shift = (data.peta[k.id] || {})[tanggal];
                const kelas = shift ? 'shift-' + shift : 'shift-kosong';
                baris += '<td class="roster-cell ' + kelas + '">' + (shift || '-') + '</td>';
            }

            const tr = document.createElement('tr');
            tr.innerHTML = baris;
            tbody.appendChild(tr);
        });

        updateSortIndicatorMingguan();
    }

    function terapkanSortMingguan(sortKey) {
        if (state.sortKey === sortKey) {
            state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sortKey = sortKey;
            state.sortDir = 'asc';
        }

        if (state.matrixTerakhir) renderMingguan(state.matrixTerakhir);
    }

    function bindSortHeadersMingguan() {
        document.querySelectorAll('#tabelRosterMingguan .sortable-header').forEach(function (th) {
            th.addEventListener('click', function () {
                terapkanSortMingguan(th.dataset.sortKey);
            });
        });
    }

    async function muatMingguan() {
        const params = new URLSearchParams({
            minggu: state.mingguAwal,
            divisi: el('rosterFilterDivisi').value,
            shift: el('rosterFilterShift').value,
            search: el('rosterFilterSearch').value,
        });

        const data = await apiGet(cfg.urls.matrixData + '?' + params.toString());
        if (data.status === 'success') renderMingguan(data);
    }

    // ================================================================
    // BULANAN
    // ================================================================

    function renderBulanan(data) {
        state.bulan = data.bulan;

        const [tahun, bulanNum] = data.bulan.split('-').map(Number);
        const namaBulan = new Date(tahun, bulanNum - 1, 1).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
        el('rosterLabelBulan').textContent = namaBulan;

        const thead = el('rosterBulananHead');
        let headRow = '<tr><th>Karyawan</th>';
        for (let d = 1; d <= data.jumlah_hari; d++) {
            headRow += '<th class="text-center">' + d + '</th>';
        }
        headRow += '</tr>';
        thead.innerHTML = headRow;

        const tbody = el('rosterBulananBody');
        tbody.innerHTML = '';

        if (!data.karyawan.length) {
            tbody.innerHTML = '<tr><td colspan="' + (data.jumlah_hari + 1) + '" class="text-center text-muted py-3">Tidak ada karyawan yang cocok dengan filter.</td></tr>';
            return;
        }

        data.karyawan.forEach(function (k) {
            const saya = Number(k.id) === Number(cfg.userIdLogin);
            let baris = '<td' + (saya ? ' class="roster-orang saya"' : '') + ' style="white-space:nowrap;">' + escapeHtml(k.nama) + '</td>';

            for (let d = 1; d <= data.jumlah_hari; d++) {
                const tanggal = data.awal_bulan.slice(0, 8) + String(d).padStart(2, '0');
                const shift = (data.peta[k.id] || {})[tanggal];
                const kelas = shift ? 'shift-' + shift : 'shift-kosong';
                baris += '<td class="roster-cell ' + kelas + '">' + (shift || '-') + '</td>';
            }

            const tr = document.createElement('tr');
            tr.innerHTML = baris;
            tbody.appendChild(tr);
        });
    }

    async function muatBulanan() {
        const params = new URLSearchParams({
            bulan: state.bulan || new Date().toISOString().slice(0, 7),
            divisi: el('rosterFilterDivisi').value,
            shift: el('rosterFilterShift').value,
            search: el('rosterFilterSearch').value,
        });

        const data = await apiGet(cfg.urls.bulanData + '?' + params.toString());
        if (data.status === 'success') renderBulanan(data);
    }

    // ================================================================
    // TAB SWITCH
    // ================================================================

    document.querySelectorAll('[data-roster-mode]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('[data-roster-mode]').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');

            state.mode = btn.dataset.rosterMode;

            el('rosterTabMingguan').classList.toggle('d-none', state.mode !== 'mingguan');
            el('rosterTabBulanan').classList.toggle('d-none', state.mode !== 'bulanan');

            if (state.mode === 'bulanan' && !state.bulan) {
                state.bulan = new Date().toISOString().slice(0, 7);
                muatBulanan();
            }
        });
    });

    // ================================================================
    // NAV & FILTER
    // ================================================================

    el('rosterBtnMingguSebelum').addEventListener('click', function () {
        state.mingguAwal = tanggalPlus(state.mingguAwal, -7);
        muatMingguan();
    });

    el('rosterBtnMingguBerikutnya').addEventListener('click', function () {
        state.mingguAwal = tanggalPlus(state.mingguAwal, 7);
        muatMingguan();
    });

    el('rosterBtnBulanSebelum').addEventListener('click', function () {
        const [t, b] = state.bulan.split('-').map(Number);
        const d = new Date(t, b - 2, 1);
        state.bulan = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
        muatBulanan();
    });

    el('rosterBtnBulanBerikutnya').addEventListener('click', function () {
        const [t, b] = state.bulan.split('-').map(Number);
        const d = new Date(t, b, 1);
        state.bulan = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
        muatBulanan();
    });

    ['rosterFilterDivisi', 'rosterFilterShift'].forEach(function (id) {
        el(id).addEventListener('change', function () {
            if (state.mode === 'mingguan') muatMingguan();
            else muatBulanan();
        });
    });

    el('rosterFilterSearch').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            if (state.mode === 'mingguan') muatMingguan();
            else muatBulanan();
        }
    });

    el('rosterBtnReset').addEventListener('click', function () {
        el('rosterFilterDivisi').value = '';
        el('rosterFilterShift').value = '';
        el('rosterFilterSearch').value = '';
        if (state.mode === 'mingguan') muatMingguan();
        else muatBulanan();
    });

    // ================================================================
    // INIT
    // ================================================================

    document.addEventListener('DOMContentLoaded', function () {
        bindSortHeadersMingguan();
        if (cfg.hariIniAwal) renderHariIni(cfg.hariIniAwal);
        if (cfg.matrixAwal) renderMingguan(cfg.matrixAwal);
    });
})();
