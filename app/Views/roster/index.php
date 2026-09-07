<style>
    .roster-cell {
        text-align: center;
        min-width: 56px;
        font-weight: 600;
        border-radius: 4px;
        padding: 6px 4px;
    }

    .roster-cell.shift-P { background-color: #fff3cd; color: #856404; }
    .roster-cell.shift-S { background-color: #cce5ff; color: #004085; }
    .roster-cell.shift-PM { background-color: #d4edda; color: #155724; }
    .roster-cell.shift-L { background-color: #f8d7da; color: #721c24; }
    .roster-cell.shift-kosong { background-color: #f8f9fa; color: #adb5bd; }

    .roster-hariini-box {
        border-radius: 8px;
        padding: 12px;
        min-height: 90px;
    }

    .roster-hariini-box h6 {
        font-weight: 700;
        margin-bottom: 8px;
    }

    .roster-orang.saya {
        font-weight: 700;
    }

    .roster-orang.saya::before {
        content: "★ ";
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
    <span class="badge bg-secondary">Tampilan baca-saja</span>
</div>

<!-- ============================================================ -->
<!-- RINGKASAN HARI INI -->
<!-- ============================================================ -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <strong>Jadwal Hari Ini</strong>
        <span id="rosterHariIniTanggalLabel"></span>
    </div>
    <div class="card-body">
        <div class="row g-2" id="rosterHariIniGrid">
            <div class="col-6 col-md-2">
                <div class="roster-hariini-box" style="background:#fff3cd;">
                    <h6>Pagi <small class="fw-normal">(08:00–15:00)</small></h6>
                    <div id="rosterHariIniPagi"></div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="roster-hariini-box" style="background:#cce5ff;">
                    <h6>Siang <small class="fw-normal">(13:30–20:30)</small></h6>
                    <div id="rosterHariIniSiang"></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="roster-hariini-box" style="background:#d4edda;">
                    <h6>PM <small class="fw-normal">(08:00–12:30 & 18:00–20:30)</small></h6>
                    <div id="rosterHariIniPM"></div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="roster-hariini-box" style="background:#f8d7da;">
                    <h6>Libur</h6>
                    <div id="rosterHariIniLibur"></div>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="roster-hariini-box" style="background:#f8f9fa;">
                    <h6>Belum Dijadwalkan</h6>
                    <div id="rosterHariIniBelum"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- TAB MINGGUAN / BULANAN -->
<!-- ============================================================ -->
<ul class="nav nav-tabs mb-3" id="rosterModeTabs">
    <li class="nav-item"><button class="nav-link active" type="button" data-roster-mode="mingguan">Mingguan</button></li>
    <li class="nav-item"><button class="nav-link" type="button" data-roster-mode="bulanan">Bulanan</button></li>
</ul>

<div class="row g-2 mb-3">
    <div class="col-md-3">
        <select class="form-select form-select-sm" id="rosterFilterDivisi">
            <option value="">Semua Divisi</option>
            <?php foreach ($divisiList as $d): ?>
                <option value="<?= esc($d) ?>"><?= esc($d) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <select class="form-select form-select-sm" id="rosterFilterShift">
            <option value="">Semua Shift</option>
            <option value="P">Pagi (P)</option>
            <option value="S">Siang (S)</option>
            <option value="PM">PM</option>
            <option value="L">Libur (L)</option>
        </select>
    </div>
    <div class="col-md-4">
        <input type="text" class="form-control form-control-sm" id="rosterFilterSearch" placeholder="Cari karyawan...">
    </div>
    <div class="col-md-2">
        <button class="btn btn-outline-secondary btn-sm w-100" type="button" id="rosterBtnReset">Reset</button>
    </div>
</div>

<!-- MINGGUAN -->
<div id="rosterTabMingguan">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="btn-group">
            <button class="btn btn-outline-secondary" type="button" id="rosterBtnMingguSebelum">
                <i class="fas fa-chevron-left"></i> Minggu Sebelumnya
            </button>
            <span class="btn btn-light disabled" id="rosterLabelMinggu"></span>
            <button class="btn btn-outline-secondary" type="button" id="rosterBtnMingguBerikutnya">
                Minggu Berikutnya <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle" id="tabelRosterMingguan">
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
            <tbody id="rosterMingguanBody"></tbody>
        </table>
    </div>
</div>

<!-- BULANAN -->
<div id="rosterTabBulanan" class="d-none">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="btn-group">
            <button class="btn btn-outline-secondary" type="button" id="rosterBtnBulanSebelum">
                <i class="fas fa-chevron-left"></i> Bulan Sebelumnya
            </button>
            <span class="btn btn-light disabled" id="rosterLabelBulan"></span>
            <button class="btn btn-outline-secondary" type="button" id="rosterBtnBulanBerikutnya">
                Bulan Berikutnya <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>
    <div class="table-responsive" style="max-height: 70vh;">
        <table class="table table-bordered table-sm align-middle">
            <thead class="table-light" id="rosterBulananHead"></thead>
            <tbody id="rosterBulananBody"></tbody>
        </table>
    </div>
</div>

<script>
    window.ROSTER_CONFIG = {
        mingguAwal: <?= json_encode($minggu_awal) ?>,
        matrixAwal: {
            minggu_awal: <?= json_encode($minggu_awal) ?>,
            minggu_akhir: <?= json_encode($minggu_akhir) ?>,
            karyawan: <?= json_encode($karyawan) ?>,
            peta: <?= json_encode($peta) ?>
        },
        hariIniAwal: <?= json_encode($hariIni) ?>,
        userIdLogin: <?= (int) (session()->get('id_user') ?? 0) ?>,
        urls: {
            matrixData: '<?= base_url('/roster/matrix-data') ?>',
            bulanData: '<?= base_url('/roster/bulan-data') ?>',
            hariIni: '<?= base_url('/roster/hari-ini') ?>'
        }
    };
</script>
<script src="<?= base_url('assets/js/roster.js') ?>"></script>
