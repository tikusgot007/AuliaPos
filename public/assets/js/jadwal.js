(function () {
    'use strict';

    const cfg = window.JADWAL_CONFIG || {};
    const HARI_LABEL = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

    const state = {
        mingguAwal: cfg.mingguAwal,
        matrix: cfg.matrixAwal,
        swapMode: false,
        swapSelected: [],
        masterId: null,
        calendarInitialized: false,
        calendarObj: null,
        sortKey: null,
        sortDir: 'asc',
    };

    // Urutan shift untuk sort kolom hari. Tanpa jadwal ('-') selalu
    // ditaruh paling akhir, apa pun arah sortnya.
    const SHIFT_URUTAN = { P: 1, S: 2, PM: 3, L: 4 };

    // Divisi yang ditampilkan di ringkasan Ketersediaan -- harus sama
    // persis dengan JadwalModel::DIVISI_KETERSEDIAAN di backend (lihat
    // getAvailability()), termasuk urutannya.
    const DIVISI_KETERSEDIAAN = ['Wanita', 'Pria', 'Banner'];

    // Urutan & label shift untuk baris Ketersediaan (dikelompokkan
    // per shift, bukan per divisi -- lihat renderHeaderMatrix()).
    const SHIFT_KETERSEDIAAN = [
        { key: 'P', label: 'Pagi' },
        { key: 'S', label: 'Siang' },
        { key: 'PM', label: 'PM' },
    ];

    // Ikon per divisi untuk baris Ketersediaan (hemat ruang). Urutan
    // tampil tetap ikut DIVISI_KETERSEDIAAN.
    const DIVISI_ICON = { Wanita: '👩🏻', Pria: '👧🏻', Banner: '📇' };

    // ================================================================
    // HELPER
    // ================================================================

    function el(id) {
        return document.getElementById(id);
    }

    function tanggalPlus(tanggal, hariOffset) {
        // PENTING: jangan pakai toISOString() di sini -- itu mengonversi
        // ke UTC, dan di timezone WIB (UTC+7) tengah malam lokal jatuh
        // ke tanggal SEBELUMNYA saat dikonversi, menyebabkan seluruh
        // perhitungan tanggal (navigasi minggu, lookup kolom Matrix)
        // bergeser mundur 1 hari. Di sini murni pakai komponen tanggal
        // lokal (getFullYear/getMonth/getDate), tidak pernah menyentuh
        // representasi UTC sama sekali.
        const [tahun, bulan, hari] = tanggal.split('-').map(Number);
        const d = new Date(tahun, bulan - 1, hari);
        d.setDate(d.getDate() + hariOffset);

        const yy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return yy + '-' + mm + '-' + dd;
    }

    function formatTanggalIndo(tanggal) {
        const d = new Date(tanggal + 'T00:00:00');
        return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // Format pendek untuk header kolom Matrix, mis. "07 Sep 2026"
    // (day 2-digit, beda dari formatTanggalIndo yang tanpa leading zero).
    function formatTanggalPendek(tanggal) {
        const d = new Date(tanggal + 'T00:00:00');
        return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    // Cari tanggal SENIN dari minggu yang memuat `tanggal`, murni pakai
    // komponen tanggal lokal (sama seperti tanggalPlus() -- lihat
    // catatan bug timezone di atas, JANGAN pakai toISOString()/UTC).
    // Dipakai oleh fitur "Cari Tanggal" (jump ke minggu tertentu).
    function awalMingguDariTanggal(tanggal) {
        const [tahun, bulan, hari] = tanggal.split('-').map(Number);
        const d = new Date(tahun, bulan - 1, hari);
        const jsDay = d.getDay(); // 0=Minggu..6=Sabtu
        const isoDay = jsDay === 0 ? 7 : jsDay; // 1=Senin..7=Minggu

        return tanggalPlus(tanggal, -(isoDay - 1));
    }

    // Tanggal HARI INI dalam format 'Y-m-d', murni pakai komponen
    // lokal (bukan toISOString()/UTC) -- sama alasan seperti di atas.
    function tanggalHariIniLokal() {
        const d = new Date();
        const yy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return yy + '-' + mm + '-' + dd;
    }

    // Index kolom (0=Senin..6=Minggu) yang mewakili hari ini di dalam
    // minggu yang sedang ditampilkan, atau -1 kalau hari ini tidak
    // ada di rentang minggu tsb (mis. admin sedang lihat minggu lain).
    // Dipakai untuk default sort & highlight kolom "hari ini".
    function todayIndexDalamMinggu(mingguAwal, mingguAkhir) {
        const hariIni = tanggalHariIniLokal();

        if (hariIni < mingguAwal || hariIni > mingguAkhir) return -1;

        for (let i = 0; i < 7; i++) {
            if (tanggalPlus(mingguAwal, i) === hariIni) return i;
        }

        return -1;
    }

    function labelShift(shift) {
        return { P: 'Pagi', S: 'Siang', PM: 'PM', L: 'Libur' }[shift] || shift;
    }

    async function apiGet(url) {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        return res.json();
    }

    async function apiPost(url, body, method = 'POST') {
        const res = await fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
        });
        return res.json();
    }

    function openModal(id) {
        const modalEl = el(id);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function closeModal(id) {
        const modalEl = el(id);
        const instance = bootstrap.Modal.getInstance(modalEl);
        if (instance) instance.hide();
    }

    // ================================================================
    // TAB SWITCHING
    // ================================================================

    function initTabs() {
        document.querySelectorAll('[data-jadwal-mode]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-jadwal-mode]').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');

                document.querySelectorAll('.jadwal-tab-pane').forEach((p) => p.classList.add('d-none'));

                const mode = btn.dataset.jadwalMode;
                el('jadwalTab' + capitalize(mode)).classList.remove('d-none');

                if (mode === 'kalender' && !state.calendarInitialized) {
                    initCalendar();
                }
                if (mode === 'master') {
                    loadMaster();
                }
                if (mode === 'analisis' && !el('analisisStart').value) {
                    el('analisisStart').value = state.mingguAwal;
                    el('analisisEnd').value = tanggalPlus(state.mingguAwal, 6);
                }
            });
        });
    }

    function capitalize(s) {
        return s.charAt(0).toUpperCase() + s.slice(1);
    }

    // ================================================================
    // MATRIX
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

                // Belum dijadwalkan ('-') selalu di akhir, tidak peduli arah sort.
                if (uA === 99 && uB !== 99) return 1;
                if (uA !== 99 && uB === 99) return -1;

                return (uA - uB) * factor;
            }

            return 0;
        });

        return arr;
    }

    function updateSortIndicator() {
        document.querySelectorAll('#tabelMatrix .sortable-header').forEach(function (th) {
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

    function renderBarisMatrix(data, karyawanList, todayIdx) {
        const tbody = el('tabelMatrixBody');
        tbody.innerHTML = '';

        if (!karyawanList.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Tidak ada karyawan yang cocok dengan filter.</td></tr>';
            return;
        }

        karyawanList.forEach(function (k) {
            const tr = document.createElement('tr');

            let baris = '<td>' + escapeHtml(k.nama) +
                (k.is_active == 0 ? ' <span class="badge bg-secondary">Nonaktif</span>' : '') +
                '<br><small class="text-muted">' + escapeHtml(k.inisial || '') + ' · ' + escapeHtml(k.divisi || '-') + '</small></td>';

            for (let i = 0; i < 7; i++) {
                const tanggal = tanggalPlus(data.minggu_awal, i);
                const shift = (data.peta[k.id] || {})[tanggal];
                const scheduleId = (data.peta_id[k.id] || {})[tanggal] || '';
                const kelas = (shift ? 'shift-' + shift : 'shift-kosong') + (i === todayIdx ? ' matrix-today-col' : '');
                const isi = shift || '-';

                baris += '<td class="jadwal-cell ' + kelas + '" ' +
                    'data-karyawan-id="' + k.id + '" data-nama="' + escapeHtml(k.nama) + '" ' +
                    'data-tanggal="' + tanggal + '" data-shift="' + (shift || '') + '" ' +
                    'data-schedule-id="' + scheduleId + '">' + isi + '</td>';
            }

            tr.innerHTML = baris;
            tbody.appendChild(tr);
        });
    }

    function renderMatrix(data) {
        state.matrix = data;
        state.mingguAwal = data.minggu_awal;

        el('labelMinggu').textContent =
            formatTanggalIndo(data.minggu_awal) + ' – ' + formatTanggalIndo(data.minggu_akhir);

        const todayIdx = todayIndexDalamMinggu(data.minggu_awal, data.minggu_akhir);

        // Default sort: kalau belum pernah ada sort manual sama sekali
        // (state.sortKey masih null, cuma terjadi di render PERTAMA)
        // dan hari ini ada di minggu yang tampil, langsung urutkan
        // berdasarkan kolom hari ini. Setelah state.sortKey terisi
        // (apa pun nilainya), blok ini tidak pernah jalan lagi --
        // pilihan sort manual user selanjutnya tidak pernah ditimpa.
        if (state.sortKey === null && todayIdx !== -1) {
            state.sortKey = 'hari:' + todayIdx;
            state.sortDir = 'asc';
        }

        renderHeaderMatrix(data, todayIdx);

        const karyawanUrut = sortKaryawan(data.karyawan, state.sortKey, state.sortDir, data);
        renderBarisMatrix(data, karyawanUrut, todayIdx);

        bindCellClicks();
        updateSortIndicator();
    }

    function terapkanSortMatrix(sortKey) {
        if (state.sortKey === sortKey) {
            state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            state.sortKey = sortKey;
            state.sortDir = 'asc';
        }

        renderMatrix(state.matrix);
    }

    // Event delegation di elemen <thead> itu sendiri (bukan bind
    // per-<th>) -- perlu karena seluruh isi <thead> digenerate ulang
    // tiap renderMatrix() (lihat renderHeaderMatrix()), jadi node
    // <th> lama selalu dibuang & diganti. Dengan delegation, listener
    // cukup dipasang SEKALI di elemen induk yang tidak pernah diganti.
    function bindSortHeaders() {
        const thead = el('tabelMatrixHead');
        if (!thead || thead.dataset.sortBound) return;

        thead.addEventListener('click', function (e) {
            const th = e.target.closest('.sortable-header');
            if (!th || !thead.contains(th)) return;
            terapkanSortMatrix(th.dataset.sortKey);
        });

        thead.dataset.sortBound = '1';
    }

    // Baris 1: nama hari + tanggal aktual minggu yang sedang tampil,
    // kolom "hari ini" (kalau ada di minggu yang tampil) diberi
    // highlight (lihat CSS .matrix-today-col).
    // Baris 2: ringkasan Ketersediaan, dikelompokkan PER SHIFT
    // (Pagi/Siang/PM), tiap baris menampilkan jumlah per divisi
    // (Wanita/Pria/Banner) dengan ikon -- menggantikan weekly summary
    // lama di bawah tabel, sekarang jadi bagian <thead> supaya
    // otomatis ikut sticky bersama header tanggal.
    function renderHeaderMatrix(data, todayIdx) {
        const availability = data.availability || {};

        const baris1 = ['<tr class="matrix-date-header">',
            '<th class="sortable-header matrix-sticky-th" data-sort-key="divisi">Karyawan ' +
            '<i class="fas fa-sort sort-icon"></i></th>'];

        const baris2 = ['<tr class="matrix-availability-header">',
            '<th class="matrix-sticky-th">Ketersediaan</th>'];

        for (let i = 0; i < 7; i++) {
            const tanggal = tanggalPlus(data.minggu_awal, i);
            const kelasHariIni = i === todayIdx ? ' matrix-today-col' : '';

            baris1.push(
                '<th class="text-center sortable-header matrix-sticky-th' + kelasHariIni + '" data-sort-key="hari:' + i + '">' +
                HARI_LABEL[i] + '<br><small>' + formatTanggalPendek(tanggal) + '</small> ' +
                '<i class="fas fa-sort sort-icon"></i></th>'
            );

            const hariAvail = availability[tanggal] || {};

            // Dikelompokkan per shift (bukan per divisi lagi) --
            // tiap baris: "<Shift> <ikon divisi 1>(n) - <ikon 2>(n) - <ikon 3>(n)".
            const isiPerShift = SHIFT_KETERSEDIAAN.map(function (s) {
                const bagianDivisi = DIVISI_KETERSEDIAAN.map(function (d) {
                    const v = hariAvail[d] || { P: 0, S: 0, PM: 0 };
                    const ikon = DIVISI_ICON[d] || d;

                    return '<span title="' + escapeHtml(d) + '">' + ikon + '(' + v[s.key] + ')</span>';
                }).join(' - ');

                return '<span class="avail-line">' + escapeHtml(s.label) + ' ' + bagianDivisi + '</span>';
            }).join('');

            baris2.push('<th class="text-center matrix-sticky-th matrix-availability-cell' + kelasHariIni + '">' +
                isiPerShift + '</th>');
        }

        baris1.push('</tr>');
        baris2.push('</tr>');

        el('tabelMatrixHead').innerHTML = baris1.join('') + baris2.join('');

        bindSortHeaders();
        updateStickyOffsets();
    }

    // Jadikan wrapper tabel Matrix scroll container LOKAL (tinggi
    // terbatas, overflow-y:auto) supaya `position: sticky; top: 0`
    // pada <th> reliable lintas browser -- lihat catatan revisi di
    // <style> jadwal/index.php untuk alasan kenapa pendekatan
    // page-level sticky sebelumnya tidak dipakai.
    //
    // Tinggi maksimum dihitung dari SISA ruang viewport di bawah
    // posisi wrapper saat ini (window.innerHeight - jarak wrapper ke
    // atas viewport - buffer), bukan angka hardcode -- supaya tetap
    // pas walau tinggi elemen di atasnya (top-header, judul, tab,
    // filter) berubah. Minimum 240px dijaga supaya di layar sangat
    // pendek tabel tetap punya ruang scroll yang wajar, bukan
    // terjepit ke ~0px.
    function updateStickyOffsets() {
        const wrapper = document.querySelector('.matrix-table-responsive');
        if (!wrapper) return;

        const BUFFER_BAWAH = 24;
        const rect = wrapper.getBoundingClientRect();
        const tinggiMax = Math.max(240, Math.floor(window.innerHeight - rect.top - BUFFER_BAWAH));
        wrapper.style.maxHeight = tinggiMax + 'px';

        // top:0 relatif ke wrapper itu sendiri (scroll container lokal)
        // untuk baris tanggal. Baris Ketersediaan menumpuk persis di
        // bawah baris tanggal, offsetnya = tinggi baris tanggal.
        const dateRow = document.querySelector('#tabelMatrixHead .matrix-date-header');
        const availRow = document.querySelector('#tabelMatrixHead .matrix-availability-header');

        if (dateRow) {
            dateRow.querySelectorAll('th').forEach(function (th) {
                th.style.top = '0px';
            });
        }

        if (availRow) {
            const dateRowHeight = dateRow ? Math.ceil(dateRow.getBoundingClientRect().height) : 0;

            availRow.querySelectorAll('th').forEach(function (th) {
                th.style.top = dateRowHeight + 'px';
            });
        }
    }

    function escapeHtml(str) {
        const d = document.createElement('div');
        d.textContent = str == null ? '' : String(str);
        return d.innerHTML;
    }

    async function muatMatrix() {
        const params = new URLSearchParams({
            minggu: state.mingguAwal,
            divisi: el('filterDivisiMatrix').value,
            shift: el('filterShiftMatrix').value,
            search: el('filterSearchMatrix').value,
        });

        const data = await apiGet(cfg.urls.matrixData + '?' + params.toString());
        if (data.status === 'success') {
            renderMatrix(data);
        }
    }

    function bindCellClicks() {
        document.querySelectorAll('#tabelMatrixBody .jadwal-cell').forEach(function (cell) {
            cell.addEventListener('click', function () {
                if (state.swapMode) {
                    handleSwapClick(cell);
                    return;
                }
                bukaModalCell(cell);
            });
        });
    }

    function bukaModalCell(cell) {
        const karyawanId = cell.dataset.karyawanId;
        const nama = cell.dataset.nama;
        const tanggal = cell.dataset.tanggal;
        const shift = cell.dataset.shift;
        const scheduleId = cell.dataset.scheduleId;

        el('cellNamaKaryawan').textContent = nama;
        el('cellTanggal').textContent = formatTanggalIndo(tanggal);
        el('cellKaryawanId').value = karyawanId;
        el('cellTanggalRaw').value = tanggal;
        el('cellScheduleId').value = scheduleId;
        el('cellShift').value = shift || 'P';
        el('btnHapusCell').classList.toggle('d-none', !shift);

        openModal('modalCell');
    }

    el('btnSimpanCell') && el('btnSimpanCell').addEventListener('click', async function () {
        const karyawanId = el('cellKaryawanId').value;
        const tanggal = el('cellTanggalRaw').value;
        const shift = el('cellShift').value;

        const res = await apiPost(cfg.urls.simpan, {
            karyawan_id: [karyawanId],
            tanggal: tanggal,
            shift: shift,
        });

        if (res.status === 'success') {
            showToast(res.message, 'success');
            closeModal('modalCell');
            muatMatrix();
        } else {
            showToast(res.message || 'Gagal menyimpan.', 'danger');
        }
    });

    el('btnHapusCell') && el('btnHapusCell').addEventListener('click', async function () {
        const id = el('cellScheduleId').value;

        if (!id) {
            showToast('Tidak ada schedule untuk dihapus.', 'danger');
            return;
        }

        if (!(await konfirmasi('Hapus schedule ini?', { okText: 'Ya, Hapus' }))) return;

        const res = await apiPost(cfg.urls.hapus + '/' + id, {}, 'DELETE');

        if (res.status === 'success') {
            showToast(res.message, 'success');
            closeModal('modalCell');
            muatMatrix();
        } else {
            showToast(res.message || 'Gagal menghapus.', 'danger');
        }
    });

    // ---- SWAP MODE ----

    el('btnModeSwap') && el('btnModeSwap').addEventListener('click', function () {
        state.swapMode = !state.swapMode;
        state.swapSelected = [];
        document.querySelectorAll('.swap-selected').forEach((c) => c.classList.remove('swap-selected'));
        el('swapHint').classList.toggle('d-none', !state.swapMode);
        el('btnModeSwap').classList.toggle('btn-warning', state.swapMode);
        el('btnModeSwap').classList.toggle('btn-outline-warning', !state.swapMode);
    });

    async function handleSwapClick(cell) {
        cell.classList.add('swap-selected');
        state.swapSelected.push(cell);

        if (state.swapSelected.length < 2) return;

        const [c1, c2] = state.swapSelected;
        const id1 = c1.dataset.scheduleId;
        const id2 = c2.dataset.scheduleId;

        if (!id1 || !id2) {
            showToast('Kedua cell harus sudah memiliki schedule untuk ditukar.', 'danger');
            resetSwapSelection();
            return;
        }

        if (!(await konfirmasi('Tukar schedule ' + c1.dataset.nama + ' (' + formatTanggalIndo(c1.dataset.tanggal) + ') dengan ' + c2.dataset.nama + ' (' + formatTanggalIndo(c2.dataset.tanggal) + ')?', { okText: 'Ya, Tukar', okClass: 'btn-primary' }))) {
            resetSwapSelection();
            return;
        }

        const res = await apiPost(cfg.urls.swap, { schedule_id_a: id1, schedule_id_b: id2 });

        if (res.status === 'success') {
            showToast(res.message, 'success');
            muatMatrix();
        } else {
            showToast(res.message || 'Swap gagal.', 'danger');
        }

        resetSwapSelection();
    }

    function resetSwapSelection() {
        state.swapSelected.forEach((c) => c.classList.remove('swap-selected'));
        state.swapSelected = [];
    }

    // ---- NAV MINGGU & FILTER ----

    el('btnMingguSebelum') && el('btnMingguSebelum').addEventListener('click', function () {
        state.mingguAwal = tanggalPlus(state.mingguAwal, -7);
        muatMatrix();
    });

    el('btnMingguBerikutnya') && el('btnMingguBerikutnya').addEventListener('click', function () {
        state.mingguAwal = tanggalPlus(state.mingguAwal, 7);
        muatMatrix();
    });

    // ---- CARI TANGGAL (jump ke minggu yang memuat tanggal terpilih) ----
    // Reuse muatMatrix() & endpoint matrix-data existing -- tidak ada
    // endpoint baru. Filter Divisi/Shift/Search yang sedang aktif
    // otomatis tetap terpakai karena muatMatrix() selalu membaca nilai
    // filter langsung dari elemen form saat dipanggil.
    el('btnCariTanggalMatrix') && el('btnCariTanggalMatrix').addEventListener('click', function () {
        const tanggal = el('cariTanggalMatrix').value;

        if (!tanggal) {
            showToast('Pilih tanggal terlebih dahulu.', 'danger');
            return;
        }

        state.mingguAwal = awalMingguDariTanggal(tanggal);
        muatMatrix();
    });

    ['filterDivisiMatrix', 'filterShiftMatrix'].forEach(function (id) {
        el(id) && el(id).addEventListener('change', muatMatrix);
    });

    el('filterSearchMatrix') && el('filterSearchMatrix').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') muatMatrix();
    });

    el('btnResetFilterMatrix') && el('btnResetFilterMatrix').addEventListener('click', function () {
        el('filterDivisiMatrix').value = '';
        el('filterShiftMatrix').value = '';
        el('filterSearchMatrix').value = '';
        muatMatrix();
    });

    // ---- TAMBAH JADWAL (multi karyawan) ----

    el('btnTambahJadwal') && el('btnTambahJadwal').addEventListener('click', async function () {
        el('tambahTanggal').value = state.mingguAwal;
        await muatDaftarKaryawanAktif();
        openModal('modalTambah');
    });

    el('tambahFilterDivisi') && el('tambahFilterDivisi').addEventListener('change', muatDaftarKaryawanAktif);

    async function muatDaftarKaryawanAktif() {
        const divisi = el('tambahFilterDivisi').value;
        const params = new URLSearchParams({ divisi: divisi });
        const res = await apiGet(cfg.urls.karyawanAktif + '?' + params.toString());
        const box = el('tambahKaryawanList');

        if (res.status !== 'success' || !res.data.length) {
            box.innerHTML = '<em class="text-muted">Tidak ada karyawan aktif.</em>';
            return;
        }

        box.innerHTML = res.data.map(function (k) {
            return '<div class="form-check"><input class="form-check-input tambah-karyawan-check" type="checkbox" value="' + k.id + '" id="tk' + k.id + '">' +
                '<label class="form-check-label" for="tk' + k.id + '">' + escapeHtml(k.nama) + ' (' + escapeHtml(k.inisial || '') + ') — ' + escapeHtml(k.divisi || '-') + '</label></div>';
        }).join('');
    }

    el('btnSimpanTambah') && el('btnSimpanTambah').addEventListener('click', async function () {
        const ids = Array.from(document.querySelectorAll('.tambah-karyawan-check:checked')).map((c) => c.value);

        if (!ids.length) {
            showToast('Pilih minimal satu karyawan.', 'danger');
            return;
        }

        const res = await apiPost(cfg.urls.simpan, {
            karyawan_id: ids,
            tanggal: el('tambahTanggal').value,
            shift: el('tambahShift').value,
        });

        if (res.status === 'success') {
            showToast(res.message, 'success');
            closeModal('modalTambah');
            muatMatrix();
        } else {
            showToast(res.message || 'Gagal menyimpan.', 'danger');
        }
    });

    // ---- HAPUS RANGE ----

    el('btnHapusRange') && el('btnHapusRange').addEventListener('click', function () {
        el('hapusRangeStart').value = state.mingguAwal;
        el('hapusRangeEnd').value = tanggalPlus(state.mingguAwal, 6);
        openModal('modalHapusRange');
    });

    el('btnKonfirmasiHapusRange') && el('btnKonfirmasiHapusRange').addEventListener('click', async function () {
        const start = el('hapusRangeStart').value;
        const end = el('hapusRangeEnd').value;

        if (!start || !end) {
            showToast('Rentang tanggal wajib diisi.', 'danger');
            return;
        }

        if (!(await konfirmasi('Hapus SEMUA jadwal dari ' + formatTanggalIndo(start) + ' sampai ' + formatTanggalIndo(end) + '? Aksi ini tidak bisa dibatalkan.', { okText: 'Ya, Hapus Semua' }))) {
            return;
        }

        const res = await apiPost(cfg.urls.hapusRange, { start: start, end: end }, 'DELETE');

        if (res.status === 'success') {
            showToast(res.message, 'success');
            closeModal('modalHapusRange');
            muatMatrix();
        } else {
            showToast(res.message || 'Gagal menghapus.', 'danger');
        }
    });

    // ================================================================
    // KALENDER
    // ================================================================

    function initCalendar() {
        state.calendarInitialized = true;

        state.calendarObj = new FullCalendar.Calendar(el('calendarJadwal'), {
            initialView: 'dayGridWeek',
            locale: 'id',
            firstDay: 1,
            height: 'auto',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,dayGridWeek' },
            events: function (info, success, failure) {
                const params = new URLSearchParams({
                    start: info.startStr,
                    end: info.endStr,
                    divisi: el('filterDivisiKalender').value,
                    shift: el('filterShiftKalender').value,
                    search: el('filterSearchKalender').value,
                });

                apiGet(cfg.urls.calendarEvents + '?' + params.toString())
                    .then(success)
                    .catch(failure);
            },
            eventClick: function (info) {
                const p = info.event.extendedProps;
                alert(
                    'Karyawan: ' + p.nama + '\n' +
                    'Divisi: ' + p.divisi + '\n' +
                    'Shift: ' + labelShift(p.shift) + '\n' +
                    '(Edit detail lewat tab Matrix)'
                );
            },
        });

        state.calendarObj.render();
    }

    el('btnFilterKalender') && el('btnFilterKalender').addEventListener('click', function () {
        if (state.calendarObj) state.calendarObj.refetchEvents();
    });

    // ================================================================
    // MASTER JADWAL
    // ================================================================

    async function loadMaster() {
        const res = await apiGet(cfg.urls.master);
        if (res.status !== 'success') return;

        state.masterId = res.master.id;

        const tbody = el('tabelMasterBody');
        tbody.innerHTML = '';

        if (!res.karyawan_aktif.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">Belum ada karyawan aktif.</td></tr>';
            return;
        }

        res.karyawan_aktif.forEach(function (k) {
            const detailKaryawan = res.detail[k.id] || { hari: {} };
            let baris = '<td>' + escapeHtml(k.nama) + '<br><small class="text-muted">' + escapeHtml(k.inisial || '') + '</small></td>';

            for (let h = 1; h <= 7; h++) {
                const shift = detailKaryawan.hari[h];
                const kelas = shift ? 'shift-' + shift : 'shift-kosong';
                baris += '<td class="jadwal-cell ' + kelas + '" data-karyawan-id="' + k.id + '" data-nama="' + escapeHtml(k.nama) + '" data-hari="' + h + '" data-shift="' + (shift || '') + '">' + (shift || '-') + '</td>';
            }

            const tr = document.createElement('tr');
            tr.innerHTML = baris;
            tbody.appendChild(tr);
        });

        document.querySelectorAll('#tabelMasterBody .jadwal-cell').forEach(function (cell) {
            cell.addEventListener('click', function () {
                bukaEditMasterCell(cell);
            });
        });
    }

    function bukaEditMasterCell(cell) {
        el('masterCellNamaKaryawan').textContent = cell.dataset.nama;
        el('masterCellHariLabel').textContent = HARI_LABEL[cell.dataset.hari - 1];
        el('masterCellKaryawanId').value = cell.dataset.karyawanId;
        el('masterCellHari').value = cell.dataset.hari;
        el('masterCellShift').value = cell.dataset.shift || 'P';
        el('btnHapusMasterCell').classList.toggle('d-none', !cell.dataset.shift);

        openModal('modalMasterCell');
    }

    el('btnSimpanMasterCell') && el('btnSimpanMasterCell').addEventListener('click', async function () {
        const res = await apiPost(cfg.urls.masterSimpanCell, {
            master_id: state.masterId,
            karyawan_id: el('masterCellKaryawanId').value,
            hari: el('masterCellHari').value,
            shift: el('masterCellShift').value,
        });

        if (res.status === 'success') {
            closeModal('modalMasterCell');
            loadMaster();
        } else {
            showToast(res.message || 'Gagal menyimpan.', 'danger');
        }
    });

    el('btnHapusMasterCell') && el('btnHapusMasterCell').addEventListener('click', async function () {
        const res = await apiPost(cfg.urls.masterHapusCell, {
            master_id: state.masterId,
            karyawan_id: el('masterCellKaryawanId').value,
            hari: el('masterCellHari').value,
        }, 'DELETE');

        if (res.status === 'success') {
            closeModal('modalMasterCell');
            loadMaster();
        } else {
            showToast(res.message || 'Gagal menghapus.', 'danger');
        }
    });

    el('btnApplyMaster') && el('btnApplyMaster').addEventListener('click', function () {
        el('applyConflictResult').innerHTML = '';
        openModal('modalApplyMaster');
    });

    el('btnKonfirmasiApplyMaster') && el('btnKonfirmasiApplyMaster').addEventListener('click', async function () {
        const start = el('applyStartMinggu').value;
        const jumlah = parseInt(el('applyJumlahMinggu').value, 10) || 1;
        const overwrite = el('applyOverwrite').checked;

        if (!start) {
            showToast('Tanggal mulai wajib diisi.', 'danger');
            return;
        }

        const res = await apiPost(cfg.urls.masterApply, {
            master_id: state.masterId,
            start_minggu: start,
            jumlah_minggu: jumlah,
            overwrite: overwrite,
        });

        if (res.status !== 'success') {
            showToast(res.message || 'Gagal menerapkan master.', 'danger');
            return;
        }

        let html = '<div class="alert alert-info">Diisi: ' + res.diisi + ' · Dilewati (conflict): ' + res.dilewati + ' · Ditimpa: ' + res.ditimpa + '</div>';

        if (res.conflict && res.conflict.length) {
            html += '<table class="table table-sm table-bordered"><thead><tr><th>Tanggal</th><th>Karyawan</th><th>Master</th><th>Existing</th></tr></thead><tbody>';
            res.conflict.forEach(function (c) {
                html += '<tr><td>' + formatTanggalIndo(c.tanggal) + '</td><td>' + escapeHtml(c.nama) + '</td><td>' + labelShift(c.master_shift) + '</td><td>' + labelShift(c.existing_shift) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        el('applyConflictResult').innerHTML = html;
        showToast('Master berhasil diterapkan.', 'success');
        muatMatrix();
    });

    // ================================================================
    // ANALISIS
    // ================================================================

    el('btnJalankanAnalisis') && el('btnJalankanAnalisis').addEventListener('click', async function () {
        const params = new URLSearchParams({
            start: el('analisisStart').value,
            end: el('analisisEnd').value,
            divisi: el('filterDivisiAnalisis').value,
        });

        const res = await apiGet(cfg.urls.analisisData + '?' + params.toString());

        if (res.status !== 'success') {
            showToast('Gagal memuat analisis.', 'danger');
            return;
        }

        let html = '<h6>Pernah Bekerja Bersama</h6>';
        html += '<table class="table table-sm table-bordered"><thead><tr><th>Karyawan 1</th><th>Karyawan 2</th><th>Divisi</th><th>Jumlah</th></tr></thead><tbody>';
        (res.pernah_ketemu || []).forEach(function (p) {
            html += '<tr><td>' + escapeHtml(p.a) + '</td><td>' + escapeHtml(p.b) + '</td><td>' + escapeHtml(p.divisi) + '</td><td>' + p.jumlah + '</td></tr>';
        });
        html += '</tbody></table>';

        html += '<h6 class="mt-3">Belum Pernah Bekerja Bersama (divisi sama)</h6>';
        html += '<table class="table table-sm table-bordered"><thead><tr><th>Karyawan 1</th><th>Karyawan 2</th><th>Divisi</th></tr></thead><tbody>';
        (res.belum_pernah || []).forEach(function (p) {
            html += '<tr><td>' + escapeHtml(p.a) + '</td><td>' + escapeHtml(p.b) + '</td><td>' + escapeHtml(p.divisi) + '</td></tr>';
        });
        html += '</tbody></table>';

        el('hasilAnalisis').innerHTML = html;
    });

    // ================================================================
    // INIT
    // ================================================================

    // Tinggi `.top-header` bisa berubah saat resize (mis. breakpoint
    // mobile yang menumpuk elemen header jadi beberapa baris) --
    // hitung ulang offset sticky Matrix supaya tetap presisi. Di-
    // debounce ringan supaya tidak menghitung ulang di setiap event
    // resize yang sangat sering ditembak browser.
    let resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(updateStickyOffsets, 150);
    });

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        // bindSortHeaders() TIDAK dipanggil di sini -- header Matrix
        // digenerate sepenuhnya oleh renderHeaderMatrix() (dipanggil
        // dari renderMatrix() di bawah), yang sudah membind sort
        // listener via delegation setiap kali dijalankan.
        if (state.matrix) renderMatrix(state.matrix);
    });
})();
