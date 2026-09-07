<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Laporan</h5>
    </div>
    <div class="card-body">

        <!-- TABS -->
        <ul class="nav nav-tabs" id="laporanTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-harian" data-bs-toggle="tab" data-bs-target="#harian" type="button" role="tab">
                    <i class="fas fa-calendar-day"></i> Harian
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-bulanan" data-bs-toggle="tab" data-bs-target="#bulanan" type="button" role="tab">
                    <i class="fas fa-calendar-alt"></i> Bulanan
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-periode" data-bs-toggle="tab" data-bs-target="#periode" type="button" role="tab">
                    <i class="fas fa-calendar-range"></i> Periode
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-kategori" data-bs-toggle="tab" data-bs-target="#kategori" type="button" role="tab">
                    <i class="fas fa-tags"></i> Per Kategori
                </button>
            </li>
        </ul>

        <div class="tab-content pt-3" id="laporanTabsContent">

            <!-- ========================================== -->
            <!-- TAB HARIAN                                 -->
            <!-- ========================================== -->
            <div class="tab-pane fade show active" id="harian" role="tabpanel">
                <div class="row g-3 mb-3">
                    <div class="col-md-5">
                        <label class="form-label">Tanggal</label>
                        <input type="text" class="form-control" id="filterHarian" placeholder="Pilih tanggal" autocomplete="off">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary" onclick="loadLaporan('harian')">
                            <i class="fas fa-search"></i> Tampilkan
                        </button>
                    </div>
                </div>

                <!-- 🔥 KARTU RINGKASAN KPI -->
                <div id="kpiHarian" class="row g-3 mb-3"></div>

                <!-- 🔥 GRAFIK: distribusi kategori & metode pembayaran -->
                <div class="row g-3 mb-3" id="chartRowHarian" style="display: none;">
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header py-2"><small class="text-muted fw-bold">DISTRIBUSI KATEGORI</small></div>
                            <div class="card-body">
                                <canvas id="chartKategoriHarian" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header py-2"><small class="text-muted fw-bold">METODE PEMBAYARAN</small></div>
                            <div class="card-body">
                                <canvas id="chartMetodeHarian" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="resultHarian"></div>
            </div>

            <!-- ========================================== -->
            <!-- TAB BULANAN                                -->
            <!-- ========================================== -->
            <div class="tab-pane fade" id="bulanan" role="tabpanel">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Bulan</label>
                        <select class="form-select" id="filterBulananBulan">
                            <?php
                            $namaBulan = [
                                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                            ];
                            $bulanSekarang = (int) date('n');
                            foreach ($namaBulan as $angka => $nama):
                            ?>
                                <option value="<?= $angka ?>" <?= $angka === $bulanSekarang ? 'selected' : '' ?>><?= $nama ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tahun</label>
                        <select class="form-select" id="filterBulananTahun">
                            <?php
                            $tahunSekarang = (int) date('Y');
                            for ($t = $tahunSekarang - 3; $t <= $tahunSekarang + 1; $t++):
                            ?>
                                <option value="<?= $t ?>" <?= $t === $tahunSekarang ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary" onclick="loadLaporan('bulanan')">
                            <i class="fas fa-search"></i> Tampilkan
                        </button>
                    </div>
                </div>
                <div id="resultBulanan"></div>
            </div>

            <!-- ========================================== -->
            <!-- TAB PERIODE                                -->
            <!-- ========================================== -->
            <div class="tab-pane fade" id="periode" role="tabpanel">
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Rentang Tanggal</label>
                        <input type="text" class="form-control" id="filterPeriode" placeholder="Pilih rentang tanggal">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary" onclick="loadLaporan('periode')">
                            <i class="fas fa-search"></i> Tampilkan
                        </button>
                    </div>
                </div>
                <div id="resultPeriode"></div>
            </div>

            <!-- ========================================== -->
            <!-- TAB PER KATEGORI                           -->
            <!-- ========================================== -->
            <div class="tab-pane fade" id="kategori" role="tabpanel">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Rentang Tanggal</label>
                        <input type="text" class="form-control" id="filterKategori" placeholder="Pilih rentang tanggal">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Kategori</label>
                        <select class="form-control" id="filterKategoriId">
                            <option value="">Semua Kategori</option>
                            <?php foreach ($kategori as $k): ?>
                                <option value="<?= $k['id'] ?>"><?= esc($k['nama']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary" onclick="loadLaporan('kategori')">
                            <i class="fas fa-search"></i> Tampilkan
                        </button>
                    </div>
                </div>
                <div id="resultKategori"></div>
            </div>

        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- SCRIPTS                                    -->
<!-- ========================================== -->
<?= $this->section('scripts') ?>

<!-- Date Range Picker -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">
<script src="https://cdn.jsdelivr.net/npm/moment/min/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
    const LAPORAN_KATEGORI = <?= json_encode(
                                    array_values($kategori ?? []),
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                ) ?>;
</script>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<!-- 3. Dependencies untuk Ekspor (JSZip & PDFMake) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<!-- 4. DataTables Buttons CORE & Export Plugins -->
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<!-- 5. Integration Libraries (HARUS PALING AKHIR) -->
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script>
    $(document).ready(function() {
        // ==========================================
        // INIT DATE RANGE PICKER
        // ==========================================

        // Harian = rekap pemasukan untuk SATU tanggal kerja (bukan
        // rentang) -- sesuai definisi laporan harian yang sebenarnya.
        $('#filterHarian').daterangepicker({
            locale: {
                format: 'DD/MM/YYYY',
                applyLabel: 'Terapkan',
                cancelLabel: 'Batal',
                daysOfWeek: ['Mg', 'Sn', 'Sl', 'Rb', 'Km', 'Jm', 'Sb'],
                monthNames: [
                    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
                ]
            },
            singleDatePicker: true,
            showDropdowns: true,
            autoApply: true,
            opens: 'left',
            startDate: moment()
        });

        // Isi nilai awal = hari ini, supaya langsung terlihat terisi
        // saat halaman dibuka (bukan kosong).
        $('#filterHarian').val(moment().format('DD/MM/YYYY'));

        $('#filterHarian').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('DD/MM/YYYY'));
        });

        // Bulanan sekarang pakai dropdown Bulan + Tahun langsung
        // (bukan date-picker) -- lihat parsing di loadLaporan().

        // Periode (pilih rentang)
        $('#filterPeriode').daterangepicker({
            locale: {
                format: 'DD/MM/YYYY'
            },
            autoApply: true,
            opens: 'left',
            ranges: {
                'Hari Ini': [moment(), moment()],
                '7 Hari Terakhir': [moment().subtract(6, 'days'), moment()],
                '30 Hari Terakhir': [moment().subtract(29, 'days'), moment()],
                'Bulan Ini': [moment().startOf('month'), moment().endOf('month')]
            }
        });

        // Kategori (pilih rentang)
        $('#filterKategori').daterangepicker({
            locale: {
                format: 'DD/MM/YYYY'
            },
            autoApply: true,
            opens: 'left',
            ranges: {
                'Hari Ini': [moment(), moment()],
                '7 Hari Terakhir': [moment().subtract(6, 'days'), moment()],
                '30 Hari Terakhir': [moment().subtract(29, 'days'), moment()],
                'Bulan Ini': [moment().startOf('month'), moment().endOf('month')]
            }
        });

        // ==========================================
        // LOAD DEFAULT
        // ==========================================
        loadLaporan('harian');
    });

    // ==========================================
    // LOAD LAPORAN
    // ==========================================

    function loadLaporan(jenis) {
        let tanggalAwal, tanggalAkhir, kategoriId = null;
        let containerId = 'result' + jenis.charAt(0).toUpperCase() + jenis.slice(1);

        switch (jenis) {
            case 'harian':
                var val = $('#filterHarian').val();
                if (val) {
                    var parts = val.split('/');

                    if (parts.length === 3) {
                        tanggalAwal = parts[2] + '-' + parts[1] + '-' + parts[0];
                        tanggalAkhir = tanggalAwal;
                    }
                }

                if (!tanggalAwal || !tanggalAkhir) {
                    tanggalAwal = moment().format('YYYY-MM-DD');
                    tanggalAkhir = tanggalAwal;
                }
                break;

            case 'bulanan':
                var bulanDipilih = $('#filterBulananBulan').val() || (moment().month() + 1);
                var tahunDipilih = $('#filterBulananTahun').val() || moment().year();
                var bulanPadded = String(bulanDipilih).padStart(2, '0');

                tanggalAwal = tahunDipilih + '-' + bulanPadded + '-01';
                tanggalAkhir = moment(tahunDipilih + '-' + bulanPadded, 'YYYY-MM').endOf('month').format('YYYY-MM-DD');
                break;

            case 'periode':
                var val = $('#filterPeriode').val();
                if (val) {
                    var parts = val.split(' - ');
                    var start = parts[0].split('/');
                    var end = parts[1].split('/');
                    tanggalAwal = start[2] + '-' + start[1] + '-' + start[0];
                    tanggalAkhir = end[2] + '-' + end[1] + '-' + end[0];
                } else {
                    tanggalAwal = moment().startOf('month').format('YYYY-MM-DD');
                    tanggalAkhir = moment().format('YYYY-MM-DD');
                }
                break;

            case 'kategori':
                var val = $('#filterKategori').val();
                if (val) {
                    var parts = val.split(' - ');
                    var start = parts[0].split('/');
                    var end = parts[1].split('/');
                    tanggalAwal = start[2] + '-' + start[1] + '-' + start[0];
                    tanggalAkhir = end[2] + '-' + end[1] + '-' + end[0];
                } else {
                    tanggalAwal = moment().startOf('month').format('YYYY-MM-DD');
                    tanggalAkhir = moment().format('YYYY-MM-DD');
                }
                kategoriId = $('#filterKategoriId').val();
                break;

            default:
                return;
        }

        // Tampilkan loading
        $('#' + containerId).html(`
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p>Memuat data...</p>
        </div>
    `);

        // Ajax request
        $.ajax({
            url: '<?= base_url('/laporan/get-data') ?>',
            type: 'POST',
            data: JSON.stringify({
                jenis: jenis,
                tanggal_awal: tanggalAwal,
                tanggal_akhir: tanggalAkhir,
                kategori_id: kategoriId
            }),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    renderLaporan(jenis, response.data, response.summary, containerId);
                } else {
                    $('#' + containerId).html('<div class="alert alert-warning">' + (response.message || 'Gagal memuat data') + '</div>');
                }
            },
            error: function() {
                $('#' + containerId).html('<div class="alert alert-danger">Gagal memuat data. Silakan coba lagi.</div>');
            }
        });
    }

    // ================================================================
    // FORMAT ANGKA (TANPA RUPIAH)
    // ================================================================

    function fmt(num) {
        if (num === undefined || num === null || isNaN(num)) {
            return '0,00';
        }

        var formatted = Number(num).toLocaleString('id-ID', {

        });

        return formatted;
    }

    // ================================================================
    // FORMAT ANGKA BIASA (TANPA RUPIAH)
    // ================================================================



    // ==========================================
    // DASHBOARD HARIAN (kartu KPI + chart)
    // ==========================================
    // Murni presentasi -- tidak mengubah/menambah perhitungan apa
    // pun di backend. Satu-satunya nilai turunan di sini adalah
    // "Tunai" = Total - Non-Tunai, dihitung dari angka yang sudah
    // benar dari backend.

    let _chartKategoriHarian = null;
    let _chartMetodeHarian = null;

    function fmtRupiahSingkat(n) {
        n = Number(n) || 0;
        if (Math.abs(n) >= 1000000000) return 'Rp ' + (n / 1000000000).toFixed(1).replace('.0', '') + 'M';
        if (Math.abs(n) >= 1000000) return 'Rp ' + (n / 1000000).toFixed(1).replace('.0', '') + 'jt';
        return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 0 });
    }

    function fmtRupiahPenuh(n) {
        return 'Rp ' + (Number(n) || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    }

    function kartuKpi(icon, warna, label, value, keterangan) {
        return `
        <div class="col-6 col-md-3">
            <div class="card border-${warna} h-100 shadow-sm">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <i class="fas ${icon} text-${warna}"></i>
                        <small class="text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">${label}</small>
                    </div>
                    <div class="fs-5 fw-bold text-truncate" title="${value}">${value}</div>
                    ${keterangan ? `<small class="text-muted">${keterangan}</small>` : ''}
                </div>
            </div>
        </div>`;
    }

    function renderDashboardHarian(data, summary) {
        if (!summary) {
            $('#kpiHarian').html('');
            $('#chartRowHarian').hide();
            return;
        }

        const totalPendapatan = parseFloat(summary.total) || 0;
        const jumlahTransaksi = parseInt(summary.jumlah_transaksi) || 0;
        const rataRata = jumlahTransaksi > 0 ? totalPendapatan / jumlahTransaksi : 0;
        const totalNonTunai = parseFloat(summary.total_non_tunai) || 0;
        const totalTunai = Math.max(0, totalPendapatan - totalNonTunai);
        const persenNonTunai = totalPendapatan > 0 ? Math.round((totalNonTunai / totalPendapatan) * 100) : 0;

        let htmlKpi = '';
        htmlKpi += kartuKpi('fa-wallet', 'primary', 'Total Pendapatan', fmtRupiahPenuh(totalPendapatan));
        htmlKpi += kartuKpi('fa-receipt', 'success', 'Jumlah Transaksi', jumlahTransaksi.toLocaleString('id-ID'));
        htmlKpi += kartuKpi('fa-calculator', 'info', 'Rata-rata / Transaksi', fmtRupiahSingkat(rataRata));
        htmlKpi += kartuKpi('fa-credit-card', 'warning', 'Non-Tunai', fmtRupiahSingkat(totalNonTunai), persenNonTunai + '% dari total');

        $('#kpiHarian').html(htmlKpi);

        // ----- CHART -----
        if (!data || data.length === 0 || typeof Chart === 'undefined') {
            $('#chartRowHarian').hide();
            return;
        }

        $('#chartRowHarian').show();

        const kategoriLabels = LAPORAN_KATEGORI.map(k => k.nama);
        const kategoriTotals = LAPORAN_KATEGORI.map(function(k) {
            const key = 'kategori_' + k.id;
            return data.reduce((sum, row) => sum + (parseFloat(row[key]) || 0), 0);
        });

        if (_chartKategoriHarian) {
            _chartKategoriHarian.destroy();
        }

        const ctxKategori = document.getElementById('chartKategoriHarian');
        if (ctxKategori) {
            _chartKategoriHarian = new Chart(ctxKategori, {
                type: 'doughnut',
                data: {
                    labels: kategoriLabels,
                    datasets: [{
                        data: kategoriTotals,
                        backgroundColor: ['#0d6efd', '#6610f2', '#198754', '#fd7e14', '#20c997', '#d63384', '#0dcaf0']
                    }]
                },
                options: {
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } },
                        tooltip: { callbacks: { label: (ctx) => ctx.label + ': ' + fmtRupiahPenuh(ctx.parsed) } }
                    }
                }
            });
        }

        if (_chartMetodeHarian) {
            _chartMetodeHarian.destroy();
        }

        const ctxMetode = document.getElementById('chartMetodeHarian');
        if (ctxMetode) {
            _chartMetodeHarian = new Chart(ctxMetode, {
                type: 'bar',
                data: {
                    labels: ['Tunai', 'QRIS', 'Transfer'],
                    datasets: [{
                        label: 'Nominal',
                        data: [totalTunai, parseFloat(summary.qris) || 0, parseFloat(summary.transfer) || 0],
                        backgroundColor: ['#198754', '#0dcaf0', '#6610f2']
                    }]
                },
                options: {
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: (ctx) => fmtRupiahPenuh(ctx.parsed.y) } }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: (v) => fmtRupiahSingkat(v) } }
                    }
                }
            });
        }
    }

    // ==========================================
    // RENDER LAPORAN
    // ==========================================

    function renderLaporan(jenis, data, summary, containerId) {
        // 🔥 Dashboard (kartu KPI + chart) khusus tab Harian.
        // Dipanggil SEBELUM early-return data-kosong di bawah, supaya
        // tetap tampil (sebagai 0) walau periode yang dipilih kosong.
        if (jenis === 'harian') {
            renderDashboardHarian(data, summary);
        } else {
            $('#kpiHarian').html('');
            $('#chartRowHarian').hide();
        }

        if (!data || data.length === 0) {
            $('#' + containerId).html(`
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Tidak ada data untuk periode ini.
            </div>
        `);
            return;
        }

        function fmt(num) {
            if (num === undefined || num === null || isNaN(num)) {
                return '0';
            }
            return Number(num).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        let html = '';

        // === TABEL ===
        html += `<div class="table-responsive">`;
        html += `<table class="table table-striped table-bordered" id="table_${containerId}">`;

        // Headers
        html += `<thead><tr>`;
        let headers = getHeaders(jenis);
        headers.forEach(h => {
            html += `<th>${h}</th>`;
        });
        html += `</tr></thead>`;

        // Body
        html += `<tbody>`;
        data.forEach(row => {
            html += `<tr>`;
            let rowData = getRowData(jenis, row);
            rowData.forEach((val, index) => {
                let isAngka = false;

                if (jenis === 'harian') {
                    isAngka = index >= 1;
                } else if (jenis === 'periode') {
                    isAngka = (index >= 4 && index <= 7);
                } else if (jenis === 'bulanan') {
                    isAngka = (index >= 1 && index <= 9);
                } else if (jenis === 'kategori') {
                    isAngka = (index >= 1 && index <= 3);
                }

                if (isAngka) {
                    html += `<td class="text-end">${fmt(val)}</td>`;
                } else {
                    html += `<td>${val || '-'}</td>`;
                }
            });
            html += `</tr>`;
        });
        html += `</tbody>`;

        // TOTAL ROW - UNTUK HARIAN
        if (jenis === 'harian') {

            html += `<tfoot>`;
            html += `<tr class="table-primary fw-bold">`;

            let totalTanggal = 'TOTAL';

            html += `<td>${totalTanggal}</td>`;

            LAPORAN_KATEGORI.forEach(function(k) {

                const key = 'kategori_' + k.id;

                const totalKategori =
                    data.reduce(
                        (sum, row) =>
                        sum + (
                            parseFloat(row[key]) ||
                            0
                        ),
                        0
                    );

                html +=
                    `<td class="text-end">${fmt(totalKategori)}</td>`;
            });

            const totalGantiEdit =
                data.reduce(
                    (sum, row) =>
                    sum + (
                        parseFloat(row.ganti_edit) ||
                        0
                    ),
                    0
                );

            const totalQris =
                data.reduce(
                    (sum, row) =>
                    sum + (
                        parseFloat(row.qris) ||
                        0
                    ),
                    0
                );

            const totalTransfer =
                data.reduce(
                    (sum, row) =>
                    sum + (
                        parseFloat(row.transfer) ||
                        0
                    ),
                    0
                );

            const totalNonTunai =
                data.reduce(
                    (sum, row) =>
                    sum + (
                        parseFloat(row.total_non_tunai) ||
                        0
                    ),
                    0
                );

            const grandTotal =
                data.reduce(
                    (sum, row) =>
                    sum + (
                        parseFloat(row.total) ||
                        0
                    ),
                    0
                );

            const totalTunai = Math.max(0, grandTotal - totalNonTunai);

            html +=
                `<td class="text-end">${fmt(totalGantiEdit)}</td>`;

            html +=
                `<td class="text-end">${fmt(totalTunai)}</td>`;

            html +=
                `<td class="text-end">${fmt(totalQris)}</td>`;

            html +=
                `<td class="text-end">${fmt(totalTransfer)}</td>`;

            html +=
                `<td class="text-end">${fmt(totalNonTunai)}</td>`;

            html +=
                `<td class="text-end">${fmt(grandTotal)}</td>`;

            html += `</tr>`;
            html += `</tfoot>`;
        }

        // 🔥 TOTAL ROW (FOOTER) - Untuk Bulanan
        // Pakai summary yang sudah dihitung backend (satu sumber
        // kebenaran, bukan re-sum manual di JS) -- supaya tidak ada
        // risiko footer beda hasil dengan getLaporanBulananData().
        if (jenis === 'bulanan' && summary) {
            html += `<tfoot>`;
            html += `<tr class="table-primary fw-bold">`;
            html += `<td>TOTAL</td>`;
            html += `<td class="text-end">${fmt(summary.penjualan)}</td>`;
            html += `<td class="text-end">${fmt(summary.fotokopi)}</td>`;
            html += `<td class="text-end">${fmt(summary.minuman)}</td>`;
            html += `<td class="text-end">${fmt(summary.digital_foto)}</td>`;
            html += `<td class="text-end">${fmt(summary.digital_printing)}</td>`;
            html += `<td class="text-end">${fmt(summary.ganti_bg)}</td>`;
            html += `<td class="text-end">${fmt(summary.total)}</td>`;
            html += `<td class="text-end">${fmt(summary.tf_qris)}</td>`;
            html += `<td class="text-end">${fmt(summary.uang_keluar)}</td>`;
            html += `</tr>`;
            html += `</tfoot>`;
        }

        html += `</table></div>`;

        $('#' + containerId).html(html);

        // 🔥 Init DataTables dengan Buttons
        setTimeout(function() {
            // 🔥 Hancurkan DataTables sebelumnya jika ada
            if ($.fn.dataTable.isDataTable('#table_' + containerId)) {
                $('#table_' + containerId).DataTable().destroy();
            }

            // 🔥 Buat DataTables baru dengan buttons

            var table = $('#table_' + containerId).DataTable({
                responsive: true,
                // Bulanan: semua tanggal (maks 31 baris) tampil sekaligus
                // dalam 1 halaman, tidak perlu next. Tab lain tetap
                // paginasi seperti biasa (Periode bisa punya banyak baris).
                paging: jenis !== 'bulanan',
                pageLength: 25,
                dom: 'Bfrtip',
                buttons: [{
                        extend: 'copyHtml5',
                        text: '<i class="fas fa-copy"></i> Copy',
                        className: 'btn btn-secondary btn-sm',
                        exportOptions: {
                            columns: ':visible'
                        }
                    },
                    {
                        extend: 'csvHtml5',
                        text: '<i class="fas fa-file-csv"></i> CSV',
                        className: 'btn btn-info btn-sm',
                        fieldSeparator: ';',
                        title: 'Laporan_' + new Date().toISOString().slice(0, 10),
                        exportOptions: {
                            columns: ':visible',
                            format: {
                                body: function(data, row, column, node) {
                                    // 🔥 Hapus titik pemisah ribuan, ubah koma jadi titik (format angka)
                                    if (typeof data === 'string' && data.includes('.') && !isNaN(parseFloat(data.replace(/\./g, '')))) {
                                        // Hapus titik, ubah koma jadi titik
                                        return data.replace(/\./g, '').replace(/,/g, '.');
                                    }
                                    return data;
                                }
                            }
                        }
                    },
                    {
                        extend: 'excelHtml5',
                        text: '<i class="fas fa-file-excel"></i> Excel',
                        className: 'btn btn-success btn-sm',
                        title: 'Laporan_' + new Date().toISOString().slice(0, 10),
                        exportOptions: {
                            columns: ':visible',
                            format: {
                                body: function(data, row, column, node) {
                                    // 🔥 Hapus titik pemisah ribuan
                                    if (typeof data === 'string' && data.includes('.') && !isNaN(parseFloat(data.replace(/\./g, '')))) {
                                        // Hapus titik, ubah koma jadi titik
                                        return data.replace(/\./g, '').replace(/,/g, '.');
                                    }
                                    return data;
                                }
                            }
                        }
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '<i class="fas fa-file-pdf"></i> PDF',
                        className: 'btn btn-danger btn-sm',
                        title: 'Laporan_' + new Date().toISOString().slice(0, 10),
                        orientation: 'landscape',
                        pageSize: 'A4'
                    },
                    {
                        extend: 'print',
                        text: '<i class="fas fa-print"></i> Print',
                        className: 'btn btn-primary btn-sm'
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

            // 🔥 Paksa tombol untuk muncul (jika masih tidak muncul)
            // PENTING: scoped ke containerId tab ini SAJA. Selector
            // global '.dt-buttons' menempel ke SEMUA tab yang pernah
            // dirender (Bootstrap tab hanya sembunyikan via CSS,
            // tidak menghapus DOM) -- itu penyebab tombol export
            // terlihat berlipat-lipat setelah beberapa kali pindah tab.
            if (table.buttons) {
                table.buttons().container().appendTo('#' + containerId + ' .dt-buttons');
            }

        }, 100);
    }
    // ==========================================
    // GET HEADERS
    // ==========================================

    function getHeaders(jenis) {
        switch (jenis) {
            case 'harian':
                return [
                    'Tanggal',
                    ...LAPORAN_KATEGORI.map(k => k.nama),
                    'Ganti/Edit',
                    'Tunai',
                    'QRIS',
                    'Transfer',
                    'QRIS + Transfer',
                    'Total'
                ];
            case 'periode':
                return ['Tanggal', 'Invoice', 'No Order', 'Pelanggan', 'Subtotal', 'Diskon', 'Grand Total', 'Sisa Tagihan', 'Status'];
            case 'bulanan':
                return ['Tanggal', 'Penjualan', 'Fotokopi', 'Minuman', 'Digital Foto', 'Digital Printing', 'Ganti BG', 'Total', 'TF + QRIS', 'Uang Keluar'];
            case 'kategori':
                return ['Kategori', 'Total Kotor', 'Total Diskon', 'Total Bersih', 'Total Transaksi'];
            default:
                return [];
        }
    }

    function getRowData(jenis, row) {
        switch (jenis) {
            case 'harian': {
                const values = [row.tanggal || ''];

                LAPORAN_KATEGORI.forEach(function(k) {
                    const key = 'kategori_' + k.id;
                    values.push(parseFloat(row[key]) || 0);
                });

                const totalHarian = parseFloat(row.total) || 0;
                const nonTunaiHarian = parseFloat(row.total_non_tunai) || 0;
                const tunaiHarian = Math.max(0, totalHarian - nonTunaiHarian);

                values.push(parseFloat(row.ganti_edit) || 0);
                values.push(tunaiHarian);
                values.push(parseFloat(row.qris) || 0);
                values.push(parseFloat(row.transfer) || 0);
                values.push(nonTunaiHarian);
                values.push(totalHarian);

                return values;
            }

            case 'periode':
                return [
                    row.tanggal || '',
                    row.invoice || '',
                    row.no_order || '',
                    row.pelanggan || '',
                    parseFloat(row.subtotal) || 0,
                    parseFloat(row.diskon) || 0,
                    parseFloat(row.grand_total) || 0,
                    parseFloat(row.sisa_tagihan) || 0,
                    row.status_pembayaran || ''
                ];

            case 'bulanan':
                return [
                    row.tanggal || '',
                    parseFloat(row.penjualan) || 0,
                    parseFloat(row.fotokopi) || 0,
                    parseFloat(row.minuman) || 0,
                    parseFloat(row.digital_foto) || 0,
                    parseFloat(row.digital_printing) || 0,
                    parseFloat(row.ganti_bg) || 0,
                    parseFloat(row.total) || 0,
                    parseFloat(row.tf_qris) || 0,
                    parseFloat(row.uang_keluar) || 0
                ];

            case 'kategori':
                return [
                    row.kategori_nama || '',
                    parseFloat(row.total_kotor) || 0,
                    parseFloat(row.total_diskon) || 0,
                    parseFloat(row.total_bersih) || 0,
                    row.total_transaksi || 0
                ];

            default:
                return [];
        }
    }

    // ==========================================
    // EXPORT EXCEL
    // ==========================================
</script>

<?= $this->endSection() ?>