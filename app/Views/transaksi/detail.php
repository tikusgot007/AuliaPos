<div class="row">
    <div class="col-md-8">
        <!-- Detail Transaksi -->
        <div class="card mb-3">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-file-invoice"></i> Detail Transaksi</h5>
                <div>
                    <?php if (!empty($dariArchive)): ?>
                        <span class="badge bg-secondary" title="Data historis, sudah dipindahkan ke database archive">
                            <i class="fas fa-box-archive"></i> Archive (read-only)
                        </span>
                    <?php endif; ?>
                    <span class="badge bg-<?= [
                                                'proses' => 'warning',
                                                'selesai' => 'primary',
                                                'batal' => 'secondary',
                                                'mangkrak' => 'dark',
                                            ][$transaksi['status']] ?? 'success' ?>">
                        <?= strtoupper($transaksi['status']) ?>
                    </span>
                    <span class="badge bg-<?= [
                                                'belum_bayar' => 'danger',
                                                'dp' => 'warning',
                                                'lunas' => 'success'
                                            ][$transaksi['status_pembayaran']] ?? 'secondary' ?>">
                        <?= strtoupper(str_replace('_', ' ', $transaksi['status_pembayaran'])) ?>
                    </span>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Invoice:</strong> <?= $transaksi['kode_invoice'] ?></p>
                        <p><strong>No Order:</strong> <?= $transaksi['no_order'] ? format_no_order($transaksi['no_order']) : '-' ?></p>
                        <p><strong>Tanggal:</strong> <?= date('d/m/Y H:i', strtotime($transaksi['tanggal'])) ?></p>
                        <p><strong>Kasir:</strong> <?= $transaksi['kasir_nama'] ?? '-' ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Pelanggan:</strong> <?= $pelanggan['nama'] ?? '-' ?></p>
                        <p><strong>Total Belanja:</strong> <strong><?= number_format($transaksi['grand_total'], 0, ',', '.') ?></strong></p>
                        <p><strong>Total Dibayar:</strong> <?= number_format($total_dibayar, 0, ',', '.') ?></p>
                        <p><strong>Sisa Tagihan:</strong> <span class="<?= $sisa_tagihan > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($sisa_tagihan, 0, ',', '.') ?>
                            </span></p>
                        <?php if (($kelebihan_bayar ?? 0) > 0): ?>
                            <p>
                                <strong>Kelebihan Bayar:</strong>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Rp <?= number_format($kelebihan_bayar, 0, ',', '.') ?>
                                </span>
                                <br>
                                <small class="text-muted">
                                    Refund fisik (jika perlu) dicatat manual lewat
                                    Kas Keluar &rarr; kategori "Refund Penjualan".
                                </small>
                            </p>
                        <?php endif; ?>
                        <?php if ($transaksi['merged_into']): ?>
                            <p class="text-muted small">
                                <i class="fas fa-link"></i> Digabung ke:
                                <a href="<?= base_url('/transaksi/detail/' . $transaksi['merged_into']) ?>">
                                    #<?= $transaksi['merged_into'] ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detail Item -->
        <div class="card mb-3">
            <div class="card-header bg-secondary text-white">
                <h6 class="mb-0"><i class="fas fa-list"></i> Item Transaksi</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Produk</th>
                                <th class="text-end">Harga</th>
                                <th class="text-end">Jumlah</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detail_items as $i => $item): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= $item['nama_produk'] ?? 'Produk #' . $item['produk_id'] ?></td>
                                    <td class="text-end"><?= number_format($item['harga_satuan'], 0, ',', '.') ?></td>
                                    <td class="text-end"><?= $item['jumlah'] ?></td>
                                    <td class="text-end"><?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Subtotal</th>
                                <th class="text-end"><?= number_format($transaksi['subtotal'], 0, ',', '.') ?></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">Diskon</th>
                                <th class="text-end"><?= number_format($transaksi['diskon'], 0, ',', '.') ?></th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">Grand Total</th>
                                <th class="text-end"><strong><?= number_format($transaksi['grand_total'], 0, ',', '.') ?></strong></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <!-- Riwayat Pembayaran -->
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="fas fa-credit-card"></i> Riwayat Pembayaran</h6>
            </div>
            <div class="card-body">
                <?php if (!empty($pembayaran)): ?>
                    <?php foreach ($pembayaran as $p): ?>
                        <?php
                        // Indikator halus "dicatat belakangan": hanya tampil jika
                        // tanggal pembayaran jauh lebih awal dari created_at
                        // (toleransi 5 menit untuk proses normal/latensi).
                        $tglBayar = strtotime($p['tanggal'] ?? '');
                        $tglDicatat = strtotime($p['created_at'] ?? '');
                        $dicatatBelakangan = $tglBayar && $tglDicatat && ($tglDicatat - $tglBayar) > 300;
                        ?>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <div>
                                <small><?= date('d/m/Y H:i', strtotime($p['tanggal'])) ?></small>
                                <?php if ($dicatatBelakangan): ?>
                                    <span class="badge bg-secondary" title="Dicatat pada <?= date('d/m/Y H:i', $tglDicatat) ?>">
                                        <i class="fas fa-history"></i> Dicatat belakangan
                                    </span>
                                <?php endif; ?>
                                <br>
                                <span class="badge bg-<?= $p['metode'] == 'tunai' ? 'primary' : ($p['metode'] == 'qris' ? 'success' : 'info') ?>">
                                    <?= strtoupper($p['metode']) ?>
                                </span>
                                <?php if (!empty($p['kasir_nama']) || !empty($p['kasir_username'])): ?>
                                    <small class="text-muted">
                                        <i class="fas fa-user"></i>
                                        <?= esc($p['kasir_nama'] ?: $p['kasir_username']) ?>
                                    </small>
                                <?php endif; ?>
                                <?php if ($p['keterangan']): ?>
                                    <small class="text-muted">
                                        <?= (!empty($p['kasir_nama']) || !empty($p['kasir_username'])) ? ' · ' : '' ?><?= esc($p['keterangan']) ?>
                                    </small>
                                <?php endif; ?>
                                <br>
                                <?php if ($transaksi['status'] != 'batal'): ?>
                                    <button
                                        type="button"
                                        class="btn btn-outline-warning btn-sm mt-1"
                                        onclick="bukaKoreksiPembayaran(
                                            <?= (int) $p['id'] ?>,
                                            <?= (int) $transaksi['id'] ?>,
                                            '<?= esc($p['metode'], 'js') ?>',
                                            <?= (float) $p['jumlah'] ?>
                                        )">
                                        <i class="fas fa-exchange-alt"></i> Koreksi Metode
                                    </button>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <strong>+ <?= number_format($p['jumlah'], 0, ',', '.') ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="d-flex justify-content-between border-top pt-2 mt-2">
                        <strong>Total Dibayar</strong>
                        <strong><?= number_format($total_dibayar, 0, ',', '.') ?></strong>
                    </div>
                    <?php if ($sisa_tagihan > 0 && $transaksi['status'] != 'batal'): ?>
                        <div class="d-flex justify-content-between text-danger">
                            <strong>Sisa Tagihan</strong>
                            <strong><?= number_format($sisa_tagihan, 0, ',', '.') ?></strong>
                        </div>
                    <?php endif; ?>
                    <?php if (($kelebihan_bayar ?? 0) > 0): ?>
                        <div class="d-flex justify-content-between align-items-center border-top pt-2 mt-2">
                            <strong class="text-warning">
                                <i class="fas fa-exclamation-triangle"></i> Kelebihan Bayar
                            </strong>
                            <strong class="text-warning">
                                <?= number_format($kelebihan_bayar, 0, ',', '.') ?>
                            </strong>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted text-center">Belum ada pembayaran.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- 🔥 TOMBOL AKSI                             -->
        <!-- ========================================== -->
        <div class="mt-3">

            <?php if (!empty($dariArchive)): ?>

                <div class="alert alert-secondary mb-0">
                    <i class="fas fa-box-archive"></i>
                    Transaksi ini sudah dipindahkan ke database archive (data historis).
                    Hanya bisa dilihat -- tidak bisa diedit, dibayar, diselesaikan, atau
                    dibatalkan lewat halaman ini.
                </div>

            <?php else: ?>

            <!-- TOMBOL EDIT: HANYA TRANSAKSI PROSES -->
            <?php if (($transaksi['status'] ?? '') === 'proses'): ?>
                <a href="javascript:void(0)" onclick="window.location.href='<?= base_url('/transaksi/edit/' . $transaksi['id']) ?>'" class="btn btn-warning w-100 mb-2">
                    <i class="fas fa-edit"></i> Edit Transaksi
                </a>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- ALUR STATUS: PROSES → SELESAI / BATAL -->
            <!-- ========================================== -->

            <?php if (($transaksi['status'] ?? '') === 'proses' && session()->get('role') === 'admin'): ?>
                <button class="btn btn-primary w-100 mb-2" onclick="selesaikanTransaksi(<?= $transaksi['id'] ?>, '<?= esc($transaksi['status_pembayaran'], 'js') ?>')">
                    <i class="fas fa-check"></i> Selesai
                </button>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- 🔥 TOMBOL PEMBAYARAN (JIKA BELUM LUNAS)   -->
            <!-- Tidak muncul untuk status MANGKRAK -- harus     -->
            <!-- diaktifkan kembali ke PROSES dulu (lihat tombol -->
            <!-- "Aktifkan Kembali" di bawah), supaya pembayaran -->
            <!-- transaksi yang sedang "dilepas" dari radar aktif -->
            <!-- tetap melalui alur normal, bukan jalan pintas.  -->
            <!-- ========================================== -->
            <?php if ($sisa_tagihan > 0 && !in_array($transaksi['status'] ?? '', ['batal', 'mangkrak'], true)): ?>


                <button
                    class="btn btn-success w-100 mb-2"
                    onclick="bukaPaymentDetail(<?= $transaksi['id'] ?>, <?= $sisa_tagihan ?>)">

                    <i class="fas fa-hand-holding-usd"></i>
                    Bayar Sekarang

                </button>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- 🔥 TOMBOL MANGKRAK / AKTIFKAN KEMBALI     -->
            <!-- ========================================== -->
            <!-- Beda dari Batalkan: transaksi ini BENERAN terjadi
                 (ada order, mungkin sudah ada DP/pekerjaan berjalan)
                 tapi macet tanpa kejelasan -- belum dibayar, tidak
                 diambil, dst. Menandai mangkrak melepas transaksi ini
                 dari Tagihan/badge/reminder (radar aktif) TANPA
                 menganggapnya tidak pernah terjadi seperti Batalkan --
                 dan tetap ikut proses Archive normal setelah 6 bulan
                 seperti transaksi lain (lihat docs Section 28).

                 ADMIN-ONLY (baik menandai maupun mengaktifkan
                 kembali) -- lihat TransaksiModel::ubahStatus(). Muncul
                 untuk PROSES, atau untuk SELESAI yang belum lunas
                 (bisa terjadi kalau pembayarannya di-reversal lewat
                 koreksi setelah transaksi sempat ditandai selesai). -->
            <?php $isAdminUser = session()->get('role') === 'admin'; ?>
            <?php
                $bolehMangkrakDariProses = $isAdminUser && ($transaksi['status'] ?? '') === 'proses';
                $bolehMangkrakDariSelesai = $isAdminUser
                    && ($transaksi['status'] ?? '') === 'selesai'
                    && ($transaksi['status_pembayaran'] ?? '') !== 'lunas';
            ?>
            <?php if ($bolehMangkrakDariProses || $bolehMangkrakDariSelesai): ?>
                <button class="btn btn-outline-dark w-100 mb-2"
                    onclick="(async () => {
                        if (await konfirmasi(
                            <?= $bolehMangkrakDariSelesai
                                ? "'Transaksi ini sudah SELESAI tapi belum lunas (kemungkinan pembayarannya sempat dikoreksi/dibatalkan). Menandai MANGKRAK akan melepasnya dari Tagihan/notifikasi tanpa mengubah histori pengerjaannya. Lanjutkan?'"
                                : "'Transaksi ini akan ditandai MANGKRAK -- hilang dari Tagihan/notifikasi, tapi datanya tetap ada dan bisa diaktifkan kembali kapan saja. Beda dari Batalkan (yang menganggap transaksi tidak pernah terjadi). Lanjutkan?'" ?>,
                            { title: 'Tandai Mangkrak', okText: 'Ya, Tandai Mangkrak', okClass: 'btn-dark' }
                        )) { kirimUbahStatusAjax(<?= $transaksi['id'] ?>, 'mangkrak'); }
                    })()">
                    <i class="fas fa-box"></i> Tandai Mangkrak (khusus admin)
                </button>
            <?php endif; ?>

            <?php if ($isAdminUser && ($transaksi['status'] ?? '') === 'mangkrak'): ?>
                <div class="alert alert-dark mb-2">
                    <i class="fas fa-box"></i>
                    Transaksi ini ditandai <strong>MANGKRAK</strong> -- tidak muncul di Tagihan/
                    notifikasi. Aktifkan kembali kalau pelanggan akhirnya muncul lagi.
                </div>
                <button class="btn btn-outline-primary w-100 mb-2"
                    onclick="(async () => {
                        if (await konfirmasi('Aktifkan kembali transaksi ini ke status PROSES?', { okText: 'Ya, Aktifkan Kembali', okClass: 'btn-primary' })) {
                            kirimUbahStatusAjax(<?= $transaksi['id'] ?>, 'proses');
                        }
                    })()">
                    <i class="fas fa-rotate-left"></i> Aktifkan Kembali (khusus admin)
                </button>
            <?php elseif (($transaksi['status'] ?? '') === 'mangkrak'): ?>
                <!-- Non-admin yang buka transaksi mangkrak: cuma info,
                     tidak ada tombol aksi apa pun (sesuai keputusan
                     admin-only). -->
                <div class="alert alert-dark mb-2">
                    <i class="fas fa-box"></i>
                    Transaksi ini ditandai <strong>MANGKRAK</strong>. Hubungi admin untuk
                    mengaktifkannya kembali.
                </div>
            <?php endif; ?>

            <!-- BATAL adalah status terminal; tidak ada tombol aktifkan kembali. -->
            <!-- ========================================== -->
            <!-- 🔥 TOMBOL BATAL                           -->
            <!-- Muncul untuk 'proses' (semua role) dan     -->
            <!-- 'selesai' (backend menolak jika bukan admin) -->
            <!-- MANGKRAK tidak bisa langsung ke Batal -- harus  -->
            <!-- diaktifkan kembali ke PROSES dulu.              -->
            <!-- ========================================== -->
            <?php if (in_array($transaksi['status'] ?? '', ['proses', 'selesai'], true)): ?>
                <button class="btn btn-danger w-100 mb-2" onclick="(async () => { if (await konfirmasi('Yakin ingin membatalkan transaksi ini?', { okText: 'Ya, Batalkan' })) { kirimUbahStatusAjax(<?= $transaksi['id'] ?>, 'batal'); } })()">
                    <i class="fas fa-times"></i> Batalkan
                    <?= ($transaksi['status'] ?? '') === 'selesai' ? '(khusus admin)' : '' ?>
                </button>
            <?php endif; ?>

            <?php endif; ?>


        </div>

        <!-- ========================================== -->
        <!-- 🔥 TOMBOL LUNASI (dari halaman Tagihan)    -->
        <!-- ========================================== -->
        <?php if (isset($dariTagihan) && $dariTagihan && $sisa_tagihan > 0): ?>
            <button class="btn btn-success w-100 mb-2" onclick="prosesLunasi(<?= $transaksi['id'] ?>, <?= $sisa_tagihan ?>)">
                <i class="fas fa-hand-holding-usd"></i> Lunasi (Rp <?= number_format($sisa_tagihan, 0, ',', '.') ?>)
            </button>
        <?php endif; ?>
    </div>
</div>
<!-- ========================================== -->
<!-- 🔥 NAVIGASI & CETAK                       -->
<!-- ========================================== -->
<div class="card mt-3 mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">

            <!-- Kembali -->
            <a href="<?= (isset($dariTagihan) && $dariTagihan)
                            ? base_url('/tagihan')
                            : base_url('/transaksi') ?>"
                class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Kembali
            </a>

            <!-- Tombol Cetak -->
            <!-- Tidak tersedia untuk transaksi archive: Cetak.php baca
                 langsung dari DB utama, dan reprint archive sengaja
                 TIDAK diimplementasikan (dikonfirmasi tidak diperlukan
                 saat audit fitur Archive Transaksi). -->
            <?php if ($transaksi['status'] != 'batal' && empty($dariArchive)): ?>

                <div class="d-flex flex-wrap gap-2">

                    <button
                        type="button"
                        class="btn btn-outline-dark"
                        onclick="cetakTicket(<?= $transaksi['id'] ?>)">

                        <i class="fas fa-id-card"></i>
                        Cetak Ticket

                    </button>

                    <div class="btn-group">
                        <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-file-pdf"></i>
                            Cetak Nota
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="#" onclick="cetakNotaLangsung(<?= $transaksi['id'] ?>); return false;">
                                    <i class="fas fa-bolt"></i> Cetak Langsung
                                    <small class="text-muted d-block">Epson L300</small>
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="#" onclick="cetakNota(<?= $transaksi['id'] ?>); return false;">
                                    <i class="fas fa-sliders-h"></i> Pilih Printer
                                    <small class="text-muted d-block">Tentukan sendiri lewat dialog print</small>
                                </a>
                            </li>
                        </ul>
                    </div>


                    <button
                        type="button"
                        class="btn btn-success"
                        onclick="cetakThermal(<?= $transaksi['id'] ?>)">

                        <i class="fas fa-print"></i>
                        Cetak Thermal

                    </button>

                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<!-- Komponen payment reusable dimuat saat diperlukan. -->
<div id="paymentModalContainer"></div>

<!-- Modal Koreksi Metode Pembayaran -->
<div class="modal fade" id="koreksiPembayaranModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exchange-alt"></i> Koreksi Metode Pembayaran
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="koreksiPembayaranId">
                <input type="hidden" id="koreksiTransaksiId">
                <input type="hidden" id="koreksiJumlah">

                <div class="mb-3">
                    <label class="form-label">Metode Lama</label>
                    <input type="text" id="koreksiMetodeLama" class="form-control" readonly>
                </div>

                <div class="mb-3">
                    <label for="koreksiMetodeBaru" class="form-label">Metode Baru</label>
                    <select id="koreksiMetodeBaru" class="form-select">
                        <option value="tunai">TUNAI</option>
                        <option value="qris">QRIS</option>
                        <option value="transfer">TRANSFER</option>
                    </select>
                </div>

                <div class="mb-3" id="koreksiUangDiterimaGroup" style="display:none;">
                    <label for="koreksiUangDiterima" class="form-label">Uang Diterima</label>
                    <input
                        type="number"
                        id="koreksiUangDiterima"
                        class="form-control"
                        min="0"
                        step="0.01">
                    <small class="text-muted">
                        Untuk koreksi menjadi TUNAI. Jika sama dengan nominal pembayaran, kembalian Rp0.
                    </small>
                </div>

                <div class="mb-3">
                    <label for="koreksiKeterangan" class="form-label">Keterangan</label>
                    <textarea
                        id="koreksiKeterangan"
                        class="form-control"
                        rows="2"
                        placeholder="Contoh: Seharusnya tunai, salah pilih QRIS"></textarea>
                </div>

                <div class="alert alert-warning mb-0">
                    Pembayaran lama tidak dihapus. Sistem akan menandainya sebagai
                    <strong>reversed</strong> dan membuat pembayaran pengganti.
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    Batal
                </button>
                <button type="button" class="btn btn-warning" onclick="submitKoreksiPembayaran()">
                    <i class="fas fa-save"></i> Simpan Koreksi
                </button>
            </div>
        </div>
    </div>
</div>



<!-- ========================================== -->
<!-- 🔥 SCRIPT (IKUTI POLA KASIR/INDEX)         -->
<!-- ========================================== -->
<script>
    // ================================================================
    // BAGIAN 1: DEKLARASI VARIABEL GLOBAL
    // ================================================================



    function bukaPaymentDetail(id, sisa) {

        bukaPaymentModal({
            mode: 'existing',
            transaksiId: id,
            total: sisa,
            sisa: sisa,
            allowPartialNonCash: true,

            onSuccess: function(response) {

                showToast(
                    response.message ||
                    'Pembayaran berhasil diproses.',
                    'success'
                );

                setTimeout(function() {
                    location.reload();
                }, 1000);
            }
        }).catch(function(error) {

            showToast(
                error.message ||
                'Gagal membuka pembayaran.',
                'danger'
            );

        });

    }
    // ================================================================
    // KOREKSI METODE PEMBAYARAN
    // ================================================================

    function bukaKoreksiPembayaran(pembayaranId, transaksiId, metodeLama, jumlah) {
        $('#koreksiPembayaranId').val(pembayaranId);
        $('#koreksiTransaksiId').val(transaksiId);
        $('#koreksiJumlah').val(jumlah);
        $('#koreksiMetodeLama').val(metodeLama.toUpperCase());
        $('#koreksiMetodeBaru').val(metodeLama === 'tunai' ? 'qris' : 'tunai');
        $('#koreksiUangDiterima').val(jumlah);
        $('#koreksiKeterangan').val('');

        updateKoreksiUangDiterima();

        const modalElement = document.getElementById('koreksiPembayaranModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
    }

    function updateKoreksiUangDiterima() {
        const metodeBaru = $('#koreksiMetodeBaru').val();
        const jumlah = parseFloat($('#koreksiJumlah').val()) || 0;

        if (metodeBaru === 'tunai') {
            $('#koreksiUangDiterimaGroup').show();

            const uangDiterima = parseFloat($('#koreksiUangDiterima').val()) || 0;
            if (uangDiterima < jumlah) {
                $('#koreksiUangDiterima').val(jumlah);
            }
        } else {
            $('#koreksiUangDiterimaGroup').hide();
        }
    }

    async function submitKoreksiPembayaran() {
        const pembayaranId = parseInt($('#koreksiPembayaranId').val(), 10);
        const transaksiId = parseInt($('#koreksiTransaksiId').val(), 10);
        const metodeLama = $('#koreksiMetodeLama').val().toLowerCase();
        const metodeBaru = $('#koreksiMetodeBaru').val();
        const jumlah = parseFloat($('#koreksiJumlah').val()) || 0;
        const uangDiterima = parseFloat($('#koreksiUangDiterima').val()) || 0;
        const keterangan = $('#koreksiKeterangan').val().trim();

        if (!pembayaranId || !transaksiId || !jumlah) {
            showToast('Data pembayaran tidak valid.', 'danger');
            return;
        }

        if (metodeBaru === metodeLama) {
            showToast('Metode baru harus berbeda dari metode lama.', 'danger');
            return;
        }

        if (metodeBaru === 'tunai' && uangDiterima < jumlah) {
            showToast('Uang diterima tidak boleh kurang dari jumlah pembayaran.', 'danger');
            return;
        }

        if (!(await konfirmasi(
                'Koreksi pembayaran ' +
                metodeLama.toUpperCase() +
                ' menjadi ' +
                metodeBaru.toUpperCase() +
                ' sebesar Rp ' +
                new Intl.NumberFormat('id-ID').format(jumlah) +
                '?',
                { okText: 'Ya, Koreksi', okClass: 'btn-warning' }
            ))) {
            return;
        }

        const modalElement = document.getElementById('koreksiPembayaranModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);

        $.ajax({
            url: '<?= base_url('/api/koreksi-pembayaran') ?>',
            type: 'POST',
            data: JSON.stringify({
                transaksi_id: transaksiId,
                pembayaran_id: pembayaranId,
                metode_baru: metodeBaru,
                uang_diterima: metodeBaru === 'tunai' ? uangDiterima : null,
                keterangan: keterangan
            }),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    modal.hide();

                    showToast(
                        response.message || 'Metode pembayaran berhasil dikoreksi.',
                        'success'
                    );

                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    showToast(
                        response.message || 'Gagal mengoreksi pembayaran.',
                        'danger'
                    );
                }
            },
            error: function(xhr) {
                let message = 'Gagal mengoreksi pembayaran.';

                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }

                showToast(message, 'danger');
            }
        });
    }

    $('#koreksiMetodeBaru').on('change', updateKoreksiUangDiterima);

    // ================================================================
    // BAGIAN 2: FUNGSI CETAK
    // ================================================================

    function cetakNota(id) {
        window.open('<?= base_url('/cetak/nota/') ?>' + id, '_blank', 'width=700');
    }

    function cetakNotaLangsung(id) {
        showToast('⏳ Mencetak nota (Epson L300)...', 'info');

        $.ajax({
            url: '<?= base_url('/cetak/nota-langsung/') ?>' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    showToast('✅ ' + (response.message || 'Nota berhasil dicetak.'), 'success');
                } else {
                    showToast('❌ ' + (response.message || 'Nota gagal dikirim ke printer.'), 'danger');
                }
            },
            error: function(xhr) {
                let message = 'Nota gagal dikirim ke printer.';

                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }

                showToast('❌ ' + message, 'danger');
            }
        });
    }

    function cetakTicket(id) {
        showToast('⏳ Mencetak ticket...', 'info');

        $.ajax({
            url: '<?= base_url('/cetak/ticket/') ?>' + id,
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    showToast('✅ ' + (response.message || 'Ticket berhasil dicetak.'), 'success');
                } else {
                    showToast('❌ ' + (response.message || 'Gagal mencetak ticket.'), 'danger');
                }
            },
            error: function(xhr) {
                let message = 'Gagal mencetak ticket.';

                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }

                showToast('❌ ' + message, 'danger');
            }
        });
    }

    function cetakThermal(id) {
        showToast('⏳ Mencetak thermal...', 'info');

        $.ajax({
            url: '<?= base_url('/cetak/thermal/') ?>' + id,
            type: 'GET',
            dataType: 'json',

            success: function(response) {

                if (response.status === 'success') {

                    showToast(
                        '✅ ' + (
                            response.message ||
                            'Struk thermal berhasil dicetak.'
                        ),
                        'success'
                    );

                } else {

                    showToast(
                        '❌ ' + (
                            response.message ||
                            'Gagal mencetak thermal.'
                        ),
                        'danger'
                    );
                }
            },

            error: function(xhr) {

                let message =
                    'Gagal mencetak thermal.';

                /*
                 * Coba ambil pesan error dari JSON
                 * yang dikirim controller.
                 */
                if (
                    xhr.responseJSON &&
                    xhr.responseJSON.message
                ) {
                    message =
                        xhr.responseJSON.message;
                }

                showToast(
                    '❌ ' + message,
                    'danger'
                );
            }
        });
    }

    // ================================================================
    // BAGIAN 3: FUNGSI UBAH STATUS
    // ================================================================

    // Helper bersama: kirim perubahan status ke backend.
    // Backend (TransaksiModel::ubahStatus) adalah satu-satunya sumber
    // kebenaran validasi; fungsi ini hanya mengirim & menampilkan hasil.
    function kirimUbahStatusAjax(id, status) {
        $.ajax({
            url: '<?= base_url('/api/ubah-status') ?>',
            type: 'POST',
            data: JSON.stringify({
                id: id,
                status: status
            }),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    showToast('✅ ' + response.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    // Pesan dari backend (mis. alasan pembayaran belum
                    // lunas beserta sisa tagihan) selalu diprioritaskan
                    // di atas pesan generik.
                    showToast('❌ ' + (response.message || 'Transaksi belum dapat diubah statusnya.'), 'danger');
                }
            },
            error: function(xhr) {
                const message = xhr.responseJSON?.message || 'Gagal mengubah status transaksi.';
                showToast('❌ ' + message, 'danger');
            }
        });
    }

    // Dipakai untuk transisi selain SELESAI (mis. Batal). Behavior
    // tidak berubah dari sebelumnya.
    async function ubahStatus(id, status) {
        if (!(await konfirmasi('Ubah status transaksi menjadi ' + status.toUpperCase() + '?', { okText: 'Ya, Ubah' }))) return;

        kirimUbahStatusAjax(id, status);
    }

    // Khusus tombol "Selesai Dikerjakan" (hanya tampil untuk admin).
    // statusPembayaran dikirim dari server (nilai saat halaman
    // dirender) semata-mata untuk memilih teks konfirmasi yang tepat.
    // Keputusan akhir tetap divalidasi ulang oleh backend.
    async function selesaikanTransaksi(id, statusPembayaran) {
        if (statusPembayaran === 'lunas') {
            const pesanKonfirmasi = 'Pembayaran sudah lunas.\n\n' +
                'Pastikan garapan benar-benar sudah selesai sebelum ' +
                'menandai transaksi sebagai SELESAI.\n\n' +
                'Lanjutkan menandai SELESAI?';

            if (!(await konfirmasi(pesanKonfirmasi, { okText: 'Ya, Selesaikan', okClass: 'btn-success' }))) {
                return;
            }
        }
        // Jika belum lunas, tidak ada yang perlu dikonfirmasi di sini —
        // backend akan menolak dan alasannya (termasuk sisa tagihan)
        // akan tampil lewat toast di bawah.

        kirimUbahStatusAjax(id, 'selesai');
    }

    // ================================================================
    // BAGIAN 4: FUNGSI PEMBAYARAN
    // ================================================================



    // Tagihan detail tetap memakai endpoint pelunasan khusus.
    function prosesLunasi(id, sisa) {
        bukaPaymentModal({
            mode: 'existing',
            transaksiId: id,
            total: sisa,
            sisa: sisa,
            existingFlow: 'tagihan-lunasi',
            allowDp: false,
            onSuccess: function(response) {
                showToast(response.message || 'Pelunasan berhasil diproses.', 'success');
                setTimeout(function() {
                    location.reload();
                }, 1000);
            }
        }).catch(function(error) {
            console.error('Payment modal error:', error);
            showToast(error.message || 'Gagal membuka modal pembayaran.', 'danger');
        });
    }
</script>

<script>
    window.paymentModalConfig = {
        modalUrl: '<?= base_url('/api/modal/pembayaran') ?>',
        kasirTransactionUrl: '<?= base_url('/api/simpan-transaksi') ?>',
        existingPaymentUrl: '<?= base_url('/api/tambah-pembayaran') ?>',
        tagihanLunasiUrl: '<?= base_url('/tagihan/lunasi/:id') ?>',
        kasirListUrl: '<?= base_url('/api/kasir-list') ?>',
        isAdmin: <?= session()->get('role') === 'admin' ? 'true' : 'false' ?>
    };
</script>
<script src="<?= base_url('assets/js/payment.js') ?>"></script>