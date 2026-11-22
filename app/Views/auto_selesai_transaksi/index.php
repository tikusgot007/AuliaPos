<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-circle-check"></i> Auto-Selesai Transaksi</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            Mengubah transaksi berstatus <strong>PROSES</strong> yang sudah <strong>LUNAS</strong>
            dan berumur <strong><?= (int) $hari ?> hari atau lebih</strong> (dihitung dari tanggal
            transaksi) menjadi <strong>SELESAI</strong>. Transaksi belum lunas / DP, mangkrak, batal,
            dan yang sudah digabung <strong>tidak disentuh</strong>. Pembayaran diperiksa ulang
            per transaksi sebelum diubah. Sebelum data diubah, daftar id dan status lamanya
            disimpan ke file backup CSV di server.
        </div>

        <table class="table table-sm table-bordered" style="max-width: 420px;">
            <tbody>
                <tr><th style="width: 240px;">Kandidat saat ini</th><td id="jmlKandidat"><?= (int) $kandidat ?></td></tr>
                <tr><th>Sudah diubah (sesi ini)</th><td id="jmlDiubah">0</td></tr>
                <tr><th>Dilewati (sesi ini)</th><td id="jmlDilewati">0</td></tr>
                <tr><th>File backup terakhir</th><td id="namaBackup">-</td></tr>
            </tbody>
        </table>

        <button type="button" class="btn btn-outline-dark" id="btnSegarkan">
            <i class="fas fa-rotate"></i> Hitung ulang
        </button>
        <button type="button" class="btn btn-success" id="btnJalankan" <?= $kandidat > 0 ? '' : 'disabled' ?>>
            <i class="fas fa-circle-check"></i> Selesaikan sekarang
        </button>
    </div>
</div>

<script>
(function () {
    function el(id) { return document.getElementById(id); }

    const btnJalankan = el('btnJalankan');
    const btnSegarkan = el('btnSegarkan');
    if (!btnJalankan) return;

    let totalDiubah = 0;
    let totalDilewati = 0;

    function setKandidat(n) {
        el('jmlKandidat').textContent = n;
        btnJalankan.disabled = n < 1;
    }

    async function post(url) {
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        return res.json();
    }

    btnSegarkan.addEventListener('click', async function () {
        btnSegarkan.disabled = true;
        try {
            const json = await post('<?= base_url('/auto-selesai-transaksi/preview') ?>');
            if (json.status !== 'success') {
                showToast(json.message || 'Gagal menghitung.', 'error');
                return;
            }
            setKandidat(json.data.kandidat);
        } catch (e) {
            showToast('Gagal menghitung: ' + e.message, 'error');
        } finally {
            btnSegarkan.disabled = false;
        }
    });

    btnJalankan.addEventListener('click', async function () {
        const jumlah = el('jmlKandidat').textContent;
        const lanjut = await konfirmasi(
            'Ini akan mengubah sampai ' + jumlah + ' transaksi LUNAS berstatus PROSES menjadi SELESAI. ' +
            'Backup id dan status lama ditulis dulu. Lanjutkan?',
            { title: 'Konfirmasi Auto-Selesai', okText: 'Ya, Selesaikan', okClass: 'btn-success', cancelText: 'Batal' }
        );
        if (!lanjut) return;

        const isiAsli = btnJalankan.innerHTML;
        btnJalankan.disabled = true;
        btnSegarkan.disabled = true;
        btnJalankan.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';

        try {
            // Ulangi per batch sampai habis atau tidak ada kemajuan.
            while (true) {
                const json = await post('<?= base_url('/auto-selesai-transaksi/jalankan') ?>');
                if (json.status !== 'success') {
                    showToast(json.message || 'Auto-selesai gagal.', 'error');
                    return;
                }
                const d = json.data;
                totalDiubah += d.diubah;
                totalDilewati += d.dilewati;
                el('jmlDiubah').textContent = totalDiubah;
                el('jmlDilewati').textContent = totalDilewati;
                el('jmlKandidat').textContent = d.sisa;
                if (d.backup) el('namaBackup').textContent = d.backup;

                if (d.sisa < 1 || d.diubah < 1) break;
            }
            showToast('Selesai: ' + totalDiubah + ' transaksi diubah ke SELESAI.', 'success');
        } catch (e) {
            showToast('Auto-selesai gagal: ' + e.message, 'error');
        } finally {
            btnJalankan.innerHTML = isiAsli;
            btnSegarkan.disabled = false;
            btnJalankan.disabled = Number(el('jmlKandidat').textContent) < 1;
        }
    });
})();
</script>
