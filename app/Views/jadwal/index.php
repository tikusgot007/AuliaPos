<style>
    .jadwal-cell {
        cursor: pointer;
        text-align: center;
        min-width: 64px;
        font-weight: 600;
        border-radius: 4px;
        padding: 6px 4px;
    }

    .jadwal-cell.shift-P {
        background-color: #fff3cd;
        color: #856404;
    }

    .jadwal-cell.shift-S {
        background-color: #cce5ff;
        color: #004085;
    }

    .jadwal-cell.shift-PM {
        background-color: #d4edda;
        color: #155724;
    }

    .jadwal-cell.shift-L {
        background-color: #f8d7da;
        color: #721c24;
    }

    .jadwal-cell.shift-kosong {
        background-color: #f8f9fa;
        color: #adb5bd;
    }

    .jadwal-cell.swap-selected {
        outline: 3px solid #000;
    }

    .jadwal-tab-pane {
        min-height: 300px;
    }

    #calendarJadwal {
        max-width: 100%;
    }

    .sortable-header {
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
    }

    .sortable-header:hover {
        background-color: #e9ecef;
    }

    .sort-icon {
        font-size: 0.75rem;
        color: #adb5bd;
        margin-left: 4px;
    }

    .sortable-header.sort-active .sort-icon {
        color: #212529;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="fas fa-calendar-alt"></i> Jadwal Karyawan</h4>
</div>

<ul class="nav nav-tabs mb-3" id="jadwalModeTabs">
    <li class="nav-item">
        <button class="nav-link active" type="button" data-jadwal-mode="matrix">Matrix</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" type="button" data-jadwal-mode="kalender">Kalender</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" type="button" data-jadwal-mode="master">Master Jadwal</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" type="button" data-jadwal-mode="analisis">Analisis</button>
    </li>
</ul>

<!-- ============================================================ -->
<!-- TAB: MATRIX (default, server-rendered untuk minggu berjalan)  -->
<!-- ============================================================ -->
<div id="jadwalTabMatrix" class="jadwal-tab-pane">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="btn-group">
            <button class="btn btn-outline-secondary" type="button" id="btnMingguSebelum">
                <i class="fas fa-chevron-left"></i> Minggu Sebelumnya
            </button>
            <span class="btn btn-light disabled" id="labelMinggu"></span>
            <button class="btn btn-outline-secondary" type="button" id="btnMingguBerikutnya">
                Minggu Berikutnya <i class="fas fa-chevron-right"></i>
            </button>
        </div>

        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="button" id="btnTambahJadwal">
                <i class="fas fa-plus"></i> Tambah Jadwal
            </button>
            <button class="btn btn-outline-warning btn-sm" type="button" id="btnModeSwap">
                <i class="fas fa-random"></i> Mode Swap
            </button>
            <button class="btn btn-outline-danger btn-sm" type="button" id="btnHapusRange">
                <i class="fas fa-trash"></i> Hapus Rentang
            </button>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="filterDivisiMatrix">
                <option value="">Semua Divisi</option>
                <?php foreach ($divisiList as $d): ?>
                    <option value="<?= esc($d) ?>"><?= esc($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="filterShiftMatrix">
                <option value="">Semua Shift</option>
                <option value="P">Pagi (P)</option>
                <option value="S">Siang (S)</option>
                <option value="PM">PM</option>
                <option value="L">Libur (L)</option>
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" id="filterSearchMatrix" placeholder="Cari karyawan...">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary btn-sm w-100" type="button" id="btnResetFilterMatrix">Reset</button>
        </div>
    </div>

    <div id="swapHint" class="alert alert-warning d-none py-2">
        Mode Swap aktif — klik 2 cell yang mau ditukar (harus karyawan divisi sama).
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle" id="tabelMatrix">
            <thead class="table-light">
                <tr>
                    <th class="sortable-header" data-sort-key="divisi">Karyawan <i class="fas fa-sort sort-icon"></i></th>
                    <th class="text-center sortable-header" data-sort-key="hari:0">Sen <i class="fas fa-sort sort-icon"></i></th>
                    <th class="text-center sortable-header" data-sort-key="hari:1">Sel <i class="fas fa-sort sort-icon"></i></th>
                    <th class="text-center sortable-header" data-sort-key="hari:2">Rab <i class="fas fa-sort sort-icon"></i></th>
                    <th class="text-center sortable-header" data-sort-key="hari:3">Kam <i class="fas fa-sort sort-icon"></i></th>
                    <th class="text-center sortable-header" data-sort-key="hari:4">Jum <i class="fas fa-sort sort-icon"></i></th>
                    <th class="text-center sortable-header" data-sort-key="hari:5">Sab <i class="fas fa-sort sort-icon"></i></th>
                    <th class="text-center sortable-header" data-sort-key="hari:6">Min <i class="fas fa-sort sort-icon"></i></th>
                </tr>
            </thead>
            <tbody id="tabelMatrixBody"></tbody>
        </table>
    </div>

    <div class="row g-2 mt-2" id="statistikMatrix"></div>
</div>

<!-- ============================================================ -->
<!-- TAB: KALENDER (lazy load) -->
<!-- ============================================================ -->
<div id="jadwalTabKalender" class="jadwal-tab-pane d-none">
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="filterDivisiKalender">
                <option value="">Semua Divisi</option>
                <?php foreach ($divisiList as $d): ?>
                    <option value="<?= esc($d) ?>"><?= esc($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="filterShiftKalender">
                <option value="">Semua Shift</option>
                <option value="P">Pagi (P)</option>
                <option value="S">Siang (S)</option>
                <option value="PM">PM</option>
                <option value="L">Libur (L)</option>
            </select>
        </div>
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" id="filterSearchKalender" placeholder="Cari karyawan...">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary btn-sm w-100" type="button" id="btnFilterKalender">Terapkan</button>
        </div>
    </div>
    <div id="calendarJadwal"></div>
</div>

<!-- ============================================================ -->
<!-- TAB: MASTER JADWAL (lazy load) -->
<!-- ============================================================ -->
<div id="jadwalTabMaster" class="jadwal-tab-pane d-none">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <p class="text-muted mb-0">Template mingguan — tidak memengaruhi jadwal aktual sampai di-<strong>Terapkan</strong>.</p>
        <button class="btn btn-success btn-sm" type="button" id="btnApplyMaster">
            <i class="fas fa-calendar-check"></i> Terapkan ke Minggu Depan
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle" id="tabelMaster">
            <thead class="table-light">
                <tr>
                    <th>Karyawan</th>
                    <th class="text-center">Sen</th>
                    <th class="text-center">Sel</th>
                    <th class="text-center">Rab</th>
                    <th class="text-center">Kam</th>
                    <th class="text-center">Jum</th>
                    <th class="text-center">Sab</th>
                    <th class="text-center">Min</th>
                </tr>
            </thead>
            <tbody id="tabelMasterBody"></tbody>
        </table>
    </div>
</div>

<!-- ============================================================ -->
<!-- TAB: ANALISIS (lazy load) -->
<!-- ============================================================ -->
<div id="jadwalTabAnalisis" class="jadwal-tab-pane d-none">
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <input type="date" class="form-control form-control-sm" id="analisisStart">
        </div>
        <div class="col-md-3">
            <input type="date" class="form-control form-control-sm" id="analisisEnd">
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="filterDivisiAnalisis">
                <option value="">Semua Divisi</option>
                <?php foreach ($divisiList as $d): ?>
                    <option value="<?= esc($d) ?>"><?= esc($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-primary btn-sm w-100" type="button" id="btnJalankanAnalisis">Analisis</button>
        </div>
    </div>
    <div id="hasilAnalisis"></div>
</div>

<!-- ============================================================ -->
<!-- MODAL: EDIT / TAMBAH SATU CELL -->
<!-- ============================================================ -->
<div class="modal fade" id="modalCell" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Schedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong>Karyawan:</strong> <span id="cellNamaKaryawan"></span></p>
                <p class="mb-3"><strong>Tanggal:</strong> <span id="cellTanggal"></span></p>
                <input type="hidden" id="cellKaryawanId">
                <input type="hidden" id="cellTanggalRaw">
                <input type="hidden" id="cellScheduleId">
                <label class="form-label">Shift</label>
                <select class="form-select" id="cellShift">
                    <option value="P">Pagi (08:00–15:00)</option>
                    <option value="S">Siang (13:30–20:30)</option>
                    <option value="PM">PM (08:00–12:30 & 18:00–20:30)</option>
                    <option value="L">Libur</option>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger d-none" id="btnHapusCell">Hapus</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" id="btnSimpanCell">Simpan</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: TAMBAH JADWAL (multi karyawan) -->
<!-- ============================================================ -->
<div class="modal fade" id="modalTambah" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Tambah Jadwal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Tanggal</label>
                    <input type="date" class="form-control" id="tambahTanggal">
                </div>
                <div class="mb-2">
                    <label class="form-label">Shift</label>
                    <select class="form-select" id="tambahShift">
                        <option value="P">Pagi (08:00–15:00)</option>
                        <option value="S">Siang (13:30–20:30)</option>
                        <option value="PM">PM (08:00–12:30 & 18:00–20:30)</option>
                        <option value="L">Libur</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label">Filter Divisi</label>
                    <select class="form-select form-select-sm" id="tambahFilterDivisi">
                        <option value="">Semua Divisi</option>
                        <?php foreach ($divisiList as $d): ?>
                            <option value="<?= esc($d) ?>"><?= esc($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="form-label">Karyawan (hanya aktif)</label>
                <div id="tambahKaryawanList" class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                    <em class="text-muted">Memuat...</em>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" id="btnSimpanTambah">Simpan</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: HAPUS RANGE -->
<!-- ============================================================ -->
<div class="modal fade" id="modalHapusRange" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Hapus Jadwal (Rentang Tanggal)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> Aksi ini hanya menghapus jadwal aktual, tidak menghapus master. Tidak bisa dibatalkan.</p>
                <div class="mb-2">
                    <label class="form-label">Dari Tanggal</label>
                    <input type="date" class="form-control" id="hapusRangeStart">
                </div>
                <div class="mb-2">
                    <label class="form-label">Sampai Tanggal</label>
                    <input type="date" class="form-control" id="hapusRangeEnd">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="btnKonfirmasiHapusRange">Hapus</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: APPLY MASTER -->
<!-- ============================================================ -->
<div class="modal fade" id="modalApplyMaster" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Terapkan Master Jadwal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">Mulai Minggu (harus hari Senin)</label>
                    <input type="date" class="form-control" id="applyStartMinggu">
                </div>
                <div class="mb-2">
                    <label class="form-label">Jumlah Minggu</label>
                    <input type="number" class="form-control" id="applyJumlahMinggu" min="1" max="12" value="1">
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="applyOverwrite">
                    <label class="form-check-label" for="applyOverwrite">
                        Timpa jadwal existing (default: isi yang kosong saja)
                    </label>
                </div>
                <div id="applyConflictResult" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-success" id="btnKonfirmasiApplyMaster">Terapkan</button>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
    window.JADWAL_CONFIG = {
        mingguAwal: <?= json_encode($matrix['minggu_awal']) ?>,
        matrixAwal: <?= json_encode($matrix) ?>,
        urls: {
            matrixData: '<?= base_url('/jadwal/matrix-data') ?>',
            calendarEvents: '<?= base_url('/jadwal/calendar-events') ?>',
            analisisData: '<?= base_url('/jadwal/analisis-data') ?>',
            karyawanAktif: '<?= base_url('/jadwal/karyawan-aktif') ?>',
            simpan: '<?= base_url('/jadwal/simpan') ?>',
            hapus: '<?= base_url('/jadwal/hapus') ?>',
            hapusRange: '<?= base_url('/jadwal/hapus-range') ?>',
            swap: '<?= base_url('/jadwal/swap') ?>',
            master: '<?= base_url('/jadwal/master') ?>',
            masterSimpanCell: '<?= base_url('/jadwal/master/simpan-cell') ?>',
            masterHapusCell: '<?= base_url('/jadwal/master/hapus-cell') ?>',
            masterApply: '<?= base_url('/jadwal/master/apply') ?>'
        }
    };
</script>
<script src="<?= base_url('assets/js/jadwal.js') ?>"></script>
