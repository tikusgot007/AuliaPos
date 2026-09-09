<div class="card">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0"><i class="fas fa-box-archive"></i> Archive Transaksi</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning">
            <i class="fas fa-triangle-exclamation"></i>
            Archive memindahkan transaksi lama secara <strong>permanen</strong> dari
            database utama ke database archive terpisah (data tidak hilang, tetap
            bisa dicari &amp; dipakai untuk laporan histori, tapi tidak lagi muncul
            di Tagihan/badge notifikasi). Prosesnya tervalidasi lengkap dulu sebelum
            data dihapus dari database utama -- tetap disarankan backup database
            utama secara berkala di luar fitur ini.
        </div>

        <h6>Pilih bulan yang akan di-archive</h6>
        <p class="text-muted small">
            Bulan <strong>eligible</strong> (bisa dipilih, tombol berwarna) adalah
            bulan yang sudah berlalu minimal 6 bulan penuh dari bulan berjalan.
            Bulan yang lebih baru ditampilkan sebagai konteks saja dan tidak bisa
            dipilih.
        </p>

        <?php if (empty($daftar_bulan)): ?>
            <div class="alert alert-secondary">Belum ada data transaksi sama sekali di database utama.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-2 mb-4" id="daftarBulanArchive">
                <?php foreach ($daftar_bulan as $b): ?>
                    <?php $idChk = 'chkBulan-' . esc($b['ym']); ?>
                    <div>
                        <!--
                            PENTING: Bootstrap .btn-check mengandalkan CSS
                            adjacent-sibling selector (.btn-check:checked + .btn),
                            jadi <input> dan <label class="btn"> WAJIB jadi
                            SIBLING (bertetangga langsung), BUKAN <input> di-nest
                            di dalam <label> -- kalau di-nest, selector "+" tidak
                            pernah match dan tombol tidak pernah terlihat
                            "terpilih" walau checkbox-nya sebenarnya tercentang.
                        -->
                        <input type="checkbox" class="btn-check chk-bulan-archive" id="<?= $idChk ?>"
                            value="<?= esc($b['ym']) ?>" <?= $b['eligible'] ? '' : 'disabled' ?> autocomplete="off">
                        <label class="btn btn-outline-<?= $b['eligible'] ? 'primary' : 'secondary' ?>" for="<?= $idChk ?>"
                            style="cursor: <?= $b['eligible'] ? 'pointer' : 'not-allowed' ?>;">
                            <?= esc($b['label']) ?>
                            <span class="badge bg-light text-dark ms-1"><?= (int) $b['jumlah'] ?> transaksi</span>
                            <?php if (!$b['eligible']): ?>
                                <br><small>belum eligible</small>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <button type="button" class="btn btn-outline-dark" id="btnPreviewArchive">
            <i class="fas fa-eye"></i> Preview
        </button>

        <div id="hasilPreviewArchive" class="mt-4" style="display:none;">
            <hr>
            <h6>Preview</h6>
            <table class="table table-sm table-bordered" style="max-width: 480px;">
                <tbody>
                    <tr><th style="width: 220px;">Periode</th><td id="pvPeriode"></td></tr>
                    <tr><th>Jumlah transaksi</th><td id="pvTransaksi"></td></tr>
                    <tr><th>Jumlah detail transaksi</th><td id="pvDetail"></td></tr>
                    <tr><th>Jumlah pembayaran</th><td id="pvPembayaran"></td></tr>
                    <tr><th>Total penjualan</th><td id="pvTotalTransaksi"></td></tr>
                    <tr><th>Total pembayaran</th><td id="pvTotalPembayaran"></td></tr>
                    <tr><th>Belum bayar / DP / Lunas / Batal</th><td id="pvPerStatus"></td></tr>
                </tbody>
            </table>

            <div class="mb-3" style="max-width: 320px;">
                <label for="konfirmasiArchive" class="form-label">
                    Ketik <strong>ARCHIVE</strong> untuk mengaktifkan tombol Archive
                </label>
                <input type="text" class="form-control" id="konfirmasiArchive"
                    placeholder="ARCHIVE" autocomplete="off">
            </div>

            <button type="button" class="btn btn-secondary" id="btnBatalkanArchive">Batalkan</button>
            <button type="button" class="btn btn-danger" id="btnJalankanArchive" disabled>
                <i class="fas fa-box-archive"></i> Archive
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    function el(id) { return document.getElementById(id); }

    function bulanTerpilih() {
        return Array.from(document.querySelectorAll('.chk-bulan-archive:checked')).map(function (c) { return c.value; });
    }

    function formatRupiah(n) {
        return 'Rp' + Number(n || 0).toLocaleString('id-ID', { maximumFractionDigits: 0 });
    }

    // Format tanggal lokal (bukan toISOString/UTC) -- pola yang sama
    // dipakai di seluruh modul Jadwal/Roster untuk hindari bug
    // timezone.
    function formatTanggalIndo(tgl) {
        const bagian = tgl.split('-').map(Number);
        const dt = new Date(bagian[0], bagian[1] - 1, bagian[2]);
        return dt.toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' });
    }

    const btnPreview = el('btnPreviewArchive');
    const btnJalankan = el('btnJalankanArchive');
    const inputKonfirmasi = el('konfirmasiArchive');
    const boxPreview = el('hasilPreviewArchive');

    if (!btnPreview) return;

    btnPreview.addEventListener('click', async function () {
        const bulan = bulanTerpilih();

        if (!bulan.length) {
            showToast('Pilih minimal satu bulan yang eligible.', 'warning');
            return;
        }

        btnPreview.disabled = true;

        try {
            const res = await fetch('<?= base_url('/archive-transaksi/preview') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bulan: bulan })
            });
            const json = await res.json();

            if (json.status !== 'success') {
                showToast(json.message || 'Gagal memuat preview.', 'error');
                return;
            }

            const d = json.data;

            el('pvPeriode').textContent = formatTanggalIndo(d.tanggal_awal) + ' s/d ' + formatTanggalIndo(d.tanggal_akhir);
            el('pvTransaksi').textContent = d.jumlah_transaksi;
            el('pvDetail').textContent = d.jumlah_detail;
            el('pvPembayaran').textContent = d.jumlah_pembayaran;
            el('pvTotalTransaksi').textContent = formatRupiah(d.total_transaksi);
            el('pvTotalPembayaran').textContent = formatRupiah(d.total_pembayaran);
            el('pvPerStatus').textContent =
                d.per_status.belum_bayar + ' / ' + d.per_status.dp + ' / ' +
                d.per_status.lunas + ' / ' + d.per_status.batal;

            boxPreview.dataset.bulan = JSON.stringify(bulan);
            inputKonfirmasi.value = '';
            btnJalankan.disabled = true;
            boxPreview.style.display = 'block';
            boxPreview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (e) {
            showToast('Gagal memuat preview: ' + e.message, 'error');
        } finally {
            btnPreview.disabled = false;
        }
    });

    el('btnBatalkanArchive').addEventListener('click', function () {
        boxPreview.style.display = 'none';
    });

    // Tombol Archive sengaja tetap disabled sampai admin mengetik
    // "ARCHIVE" -- lapisan pengaman tambahan di atas modal
    // konfirmasi() untuk aksi yang menghapus data dari DB utama,
    // pola yang sama dipakai migrasi-manual/index.php.
    inputKonfirmasi.addEventListener('input', function () {
        btnJalankan.disabled = this.value.trim().toUpperCase() !== 'ARCHIVE';
    });

    btnJalankan.addEventListener('click', async function () {
        const bulan = JSON.parse(boxPreview.dataset.bulan || '[]');

        const lanjut = await konfirmasi(
            'Ini akan MENGHAPUS ' + bulan.length + ' bulan transaksi dari database utama ' +
            'setelah tersalin & tervalidasi lengkap di archive. Lanjutkan?',
            { title: 'Konfirmasi Archive', okText: 'Ya, Archive Sekarang', okClass: 'btn-danger', cancelText: 'Batal' }
        );

        if (!lanjut) return;

        const btn = btnJalankan;
        const isiAsli = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';

        try {
            const res = await fetch('<?= base_url('/archive-transaksi/jalankan') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bulan: bulan })
            });
            const json = await res.json();

            if (json.status !== 'success') {
                showToast(json.message || 'Archive gagal.', 'error');
                btn.disabled = false;
                btn.innerHTML = isiAsli;
                return;
            }

            showToast(
                'Archive berhasil: ' + json.data.jumlah_transaksi + ' transaksi dipindahkan ke archive.',
                'success'
            );

            setTimeout(function () { window.location.reload(); }, 1500);
        } catch (e) {
            showToast('Archive gagal: ' + e.message, 'error');
            btn.disabled = false;
            btn.innerHTML = isiAsli;
        }
    });
})();
</script>
