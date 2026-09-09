<style>
    /* =========================================
       CONTAINER DAFTAR PRODUK
       ========================================= */
    .produk-scroll-container {
        max-height: 620px;
        overflow-y: auto;
        overflow-x: hidden;

        padding: 6px 10px 6px 4px;
    }

    /* =========================================
       GRID PRODUK
       ========================================= */
    .produk-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;

        align-items: stretch;
    }

    /* =========================================
       CARD WRAPPER
       ========================================= */
    .produk-card-wrap {
        width: auto !important;
        max-width: none !important;
        min-width: 0;

        padding: 0 !important;
        margin: 0 !important;

        display: flex;
    }

    .produk-card-wrap .card {
        width: 100%;
        min-height: 108px;
        margin: 0;

        display: flex;
    }

    .produk-card-wrap .card-body {
        width: 100%;
        overflow: hidden;

        padding: 8px !important;

        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    /* =========================================
       FONT CARD
       ========================================= */
    .produk-card-wrap .card-title {
        font-size: 0.98rem;
        font-weight: 600;
        line-height: 1.25;

        margin-bottom: 4px !important;

        overflow-wrap: anywhere;
    }

    .produk-card-wrap small {
        font-size: 0.8rem;
    }

    .produk-card-wrap .badge {
        font-size: 0.72rem;
        align-self: center;
    }

    /* =========================================
       DESKTOP
       4 KOLOM
       ========================================= */
    @media (min-width: 900px) {
        .produk-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }
    }

    /* =========================================
       TABLET
       3 KOLOM
       ========================================= */
    @media (min-width: 576px) and (max-width: 899.98px) {
        .produk-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    /* =========================================
       HP
       2 KOLOM
       ========================================= */
    @media (max-width: 575.98px) {
        .produk-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .produk-card-wrap .card {
            min-height: 96px;
        }

        .produk-card-wrap .card-title {
            font-size: 0.9rem;
        }

        .produk-card-wrap small {
            font-size: 0.75rem;
        }

        .produk-card-wrap .badge {
            font-size: 0.68rem;
        }
    }
</style>

<!-- DATA PELANGGAN DAN NO ORDER -->
<div class="row g-2 mb-3">

    <!-- Baris 1 -->
    <div class="col-md-6">
        <div class="input-group">
            <span class="input-group-text">
                <i class="fas fa-user"></i>
            </span>

            <input
                type="text"
                id="namaPelanggan"
                class="form-control"
                autocomplete="off"
                placeholder="Cari atau masukkan pelanggan baru"
                oninput="handleCustomerNameInput(this.value)">
        </div>

        <small class="text-muted">Pelanggan baru akan disimpan saat transaksi berhasil.</small>

        <div
            id="listPelanggan"
            class="list-group mt-1"
            style="display:none; position:absolute; z-index:1000; width:100%; max-height:200px; overflow-y:auto;">
        </div>
    </div>

    <div class="col-md-3">
        <div class="input-group">
            <input
                type="text"
                id="telpPelanggan"
                class="form-control"
                placeholder="No. Telp (opsional)"
                readonly>

            <button
                type="button"
                id="btnEditTelpPelanggan"
                class="btn btn-outline-secondary"
                style="display:none;"
                onclick="editTelpPelanggan()"
                title="Edit data pelanggan">
                Edit
            </button>
        </div>
    </div>

    <!-- Sisa ruang -->
    <div class="col-md-3"></div>

    <!-- Baris 2 -->
    <div class="col-md-9">
        <div class="row g-2 align-items-center">

            <!-- Pilih No Order -->
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-hashtag"></i>
                    </span>

                    <select
                        name="no_order_input2"
                        id="no_order_input2"
                        class="form-select"
                        onchange="gantiNo()">

                        <option value="">Pilih No. Order</option>

                        <?php foreach ($available_no_orders as $orderNumber): ?>
                            <option
                                value="<?= $orderNumber ?>"
                                <?= ($orderNumber == $recommended_no_order)
                                    ? 'style="background-color:pink; font-weight:bold;"'
                                    : '' ?>>
                                <?= format_no_order($orderNumber) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>

                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        onclick="generateNewOrder()"
                        title="Buat No. Order Baru">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>

            <!-- No Order Manual -->
            <div class="col-md-4">
                <input
                    type="text"
                    id="noOrderInput"
                    class="form-control"
                    placeholder="No. Order manual"
                    disabled
                    autocomplete="off">

                <input
                    type="hidden"
                    id="noOrderAsli"
                    value="">
            </div>

            <!-- Checkbox -->
            <div class="col-md-3">
                <div class="form-check">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="manual_order_checkbox"
                        onchange="toggleManualOrder()">

                    <label
                        class="form-check-label"
                        for="manual_order_checkbox">
                        Input No. Order Manual
                    </label>
                </div>
            </div>

        </div>
    </div>

</div>

<div class="row">

    <div class="col-md-8">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <h5 class="mb-0"><i class="fas fa-boxes"></i> Pilih Produk</h5>
            <div class="d-flex gap-1 flex-wrap" id="filterKategoriProduk">
                <button class="btn btn-sm btn-filter-kategori btn-primary active"
                    type="button" data-kategori="" onclick="filterProdukByKategori('')">
                    <i class="fas fa-th"></i> Semua
                </button>
                <?php foreach ($kategori as $k): ?>
                    <button class="btn btn-sm btn-filter-kategori btn-outline-secondary"
                        type="button" data-kategori="<?= $k['id'] ?>" onclick="filterProdukByKategori('<?= $k['id'] ?>')">
                        <?= esc($k['nama']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- AKSI KHUSUS (bukan produk — jangan dimasukkan -->
        <!-- ke #produkList / SEMUA_PRODUK_KASIR)          -->
        <!-- ========================================== -->
        <div class="mb-3">
            <h6 class="text-muted mb-2"><i class="fas fa-bolt"></i> Aksi Khusus</h6>
            <div id="aksiKhususList" class="produk-grid">
                <div class="produk-card-wrap">
                    <div class="card card-kasir p-2 text-center border-primary h-100"
                        onclick="bukaModalBanner()"
                        style="cursor:pointer; background-color:#e8f4fd;">
                        <div class="card-body p-1 d-flex flex-column justify-content-center">
                            <h6 class="card-title mb-1">
                                <i class="fas fa-ruler-combined text-primary"></i> Banner
                            </h6>
                            <small class="text-muted">Input ukuran banner</small>
                            <span class="badge bg-primary mt-1">📐 Banner</span>
                        </div>
                    </div>
                </div>

                <div class="produk-card-wrap">
                    <div class="card card-kasir p-2 text-center border-warning h-100"
                        onclick="showManualInput()"
                        style="cursor:pointer; background-color:#fff8e7;">
                        <div class="card-body p-1 d-flex flex-column justify-content-center">
                            <h6 class="card-title mb-1">
                                <i class="fas fa-pencil-alt text-warning"></i> Manual Input
                            </h6>
                            <small class="text-muted">Input total harga</small>
                            <span class="badge bg-warning text-dark mt-1">✏️ Manual</span>
                        </div>
                    </div>
                </div>

                <div class="produk-card-wrap">
                    <div class="card card-kasir p-2 text-center border-danger h-100"
                        onclick="showModalCustomSize()"
                        style="cursor:pointer; background-color:#fde8e8;">
                        <div class="card-body p-1 d-flex flex-column justify-content-center">
                            <h6 class="card-title mb-1">
                                <i class="fas fa-vector-square text-success"></i> Ukuran Custom
                            </h6>
                            <small class="text-muted">Hitung harga</small>
                            <span class="badge bg-success mt-1">Custom</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-12">
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" id="searchProduk" class="form-control"
                        placeholder="Cari nama atau barcode..."
                        autocomplete="off">
                    <button class="btn btn-primary" type="button" onclick="terapkanFilterProduk()" title="Cari produk">
                        Cari
                    </button>
                    <button class="btn btn-outline-secondary d-none" id="resetSearchProduk" type="button" onclick="resetFilterProduk()" title="Reset filter">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
        <h6 class="text-muted mb-2"><i class="fas fa-boxes"></i> Daftar Produk</h6>
        <div id="produkScroll" class="produk-scroll-container">
            <div id="produkList" class="produk-grid">
            </div>
        </div>

        <div id="produkKosong" class="alert alert-warning text-center d-none mt-2">
            Produk tidak ditemukan.
        </div>

        <div class="small text-muted mt-1" id="produkInfo"></div>
    </div>

    <!-- KERANJANG DAN TOTAL -->
    <div class="col-md-4">
        <div class="card card-kasir">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-shopping-cart"></i> Keranjang
                </span>

                <div>
                    <span class="badge bg-light text-dark" id="jumlahItem">0</span>

                    <button
                        class="btn btn-danger btn-sm ms-2"
                        onclick="clearKeranjang()"
                        title="Kosongkan Keranjang">
                        <i class="fas fa-trash"></i>
                    </button>

                    <button
                        class="btn btn-info btn-sm ms-2"
                        type="button"
                        onclick="kelolaBanner()"
                        title="Kelola Banner">
                        <i class="fas fa-ruler-combined"></i>
                    </button>
                </div>
            </div>
            <div class="card-body" style="height: 500px; overflow-y: auto;" id="keranjangContainer">
                <p class="text-muted text-center" id="keranjangKosong">Belum ada item</p>
                <div id="keranjangList"></div>
            </div>
            <div class="card-footer bg-light">
                <div id="detailPembulatan" style="display: none;">

                    <div class="d-flex justify-content-between text-muted" style="font-size: 0.9rem;">
                        <span>Subtotal</span>
                        <span id="previewSubtotal">Rp 0</span>
                    </div>

                    <!-- 🔥 DISKON PELANGGAN OTOMATIS -->
                    <!-- Tersembunyi sampai pelanggan dgn diskon > 0
                         terpilih (lihat terapkanUIDiskonPelanggan() di
                         kasir-shared.js). Reuse field diskonInput/
                         diskonTipe di bawah -- BUKAN sistem diskon
                         terpisah. -->
                    <div class="d-flex justify-content-between align-items-center mt-1"
                        id="diskonPelangganBox" style="display: none; font-size: 0.85rem;">
                        <label class="d-flex align-items-center gap-1 mb-0" style="cursor: pointer;">
                            <input type="checkbox" id="diskonPelangganCheckbox" onchange="toggleDiskonPelanggan()">
                            <span class="text-muted">Diskon Pelanggan</span>
                        </label>
                        <span id="diskonPelangganPersenLabel" class="text-muted">0%</span>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-1" style="font-size: 0.9rem;">
                        <span class="text-muted">Diskon</span>
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" id="diskonInput" class="form-control form-control-sm"
                                style="width: 80px; font-size: 0.9rem; height: 28px;" placeholder="0" min="0" max="100" step="10" value="0"
                                oninput="hitungDiskon()">
                            <select id="diskonTipe" class="form-select form-select-sm" style="width: 55px; font-size: 0.9rem; height: 28px;" onchange="hitungDiskon()">
                                <option value="percent">%</option>
                                <option value="nominal">Rp</option>
                            </select>
                            <button class="btn btn-outline-danger btn-sm" type="button" onclick="resetDiskon()" title="Reset Diskon" style="font-size: 0.9rem; padding: 0px 6px; height: 28px;">
                                <i class="fas fa-times"></i>
                            </button>
                            <span id="diskonInfo" class="text-danger" style="min-width: 70px; text-align: right; font-size: 0.9rem;">Rp 0</span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between text-danger" id="previewPembulatanRow" style="font-size: 0.9rem;">
                        <span>Pembulatan</span>
                        <span id="previewPembulatan">-Rp 0</span>
                    </div>

                    <hr class="my-1">
                </div>

                <div class="d-flex justify-content-between" style="font-size: 0.9rem;">
                    <strong>Total:</strong>
                    <span id="totalBayar">Rp 0</span>
                </div>
                <div class="d-grid gap-2 mt-2">
                    <button class="btn btn-success" onclick="prosesPembayaran()" style="font-size: 0.9rem;">
                        <i class="fas fa-credit-card"></i> Proses Pembayaran
                    </button>
                    <button class="btn btn-warning" onclick="prosesPiutang()" style="font-size: 0.9rem;">
                        <i class="fas fa-hand-holding-usd"></i> BM (Belum Membayar)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->include('kasir/modal_banner') ?>

<?= $this->include('kasir/modal_produk') ?>

<!-- PAYMENT MODAL CONTAINER -->
<div id="paymentModalContainer"></div>

<!-- MODAL MANUAL INPUT -->
<div class="modal fade" id="modalManualInput" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-pencil-alt"></i> Input Penjualan Manual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Gunakan fitur ini jika Anda lupa detail item yang dijual. Cukup masukkan total harga yang diterima dari customer.
                </div>
                <div class="mb-3">
                    <label class="form-label">Kategori</label>
                    <select class="form-control" id="manualKategori" onchange="ubahNamaManualOtomatis()">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($kategori as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= $k['nama'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nama Item / Deskripsi</label>
                    <input type="text" class="form-control" id="manualNama" placeholder="Contoh: ATK Campuran, Paket Fotokopi, dll" value="ATK Campuran">
                </div>
                <div class="mb-3">
                    <label class="form-label">Total Harga (Rp) *</label>
                    <input type="number" class="form-control" id="manualTotal" placeholder="Masukkan total harga" required>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-warning" onclick="tambahManual()">
                    <i class="fas fa-plus"></i> Tambahkan ke Keranjang
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL SUKSES TRANSAKSI -->
<div class="modal fade" id="modalSukses" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-check-circle"></i> Transaksi Berhasil!</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalSuksesBody">

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<?= $this->section('scripts') ?>

<!--
    PETA KODE KASIR
    ----------------------------------------------------------------
    Sejak Tahap 4, logic yang dipakai bareng oleh kasir/index.php dan
    kasir/edit.php (Edit Transaksi) sudah diekstrak ke
    public/assets/js/kasir-shared.js:

        1. State & helper umum       5. Katalog produk
        2. No Order                  6. Pelanggan
        3. Keranjang                 7. Banner
        4. Diskon & pembulatan

    Halaman ini (kasir/index.php) HANYA berisi:
        - Konfigurasi untuk kasir-shared.js (SEMUA_PRODUK_KASIR, KASIR_CONFIG)
        - 8. Pembayaran, piutang & cetak (khusus transaksi baru,
          TIDAK dipakai oleh kasir/edit.php karena updateTransaksi()
          di controller tidak menerima data pembayaran)
        - 9. Konfigurasi & pemuatan payment.js

    Catatan:
    - Fungsi tetap global karena dipanggil oleh partial modal dan atribut
      event di view/partial lain.
    - Tidak ada perubahan pada rumus, alur transaksi, atau payload.

    Fungsi yang dipanggil oleh partial modal tetap berada di global scope agar
    atribut event pada modal_banner.php dan modal_produk.php tetap kompatibel.
-->
<script>
    // ================================================================
    // KONFIGURASI UNTUK kasir-shared.js
    // ================================================================
    // Semua produk dikirim sekali dari server; filter dilakukan di browser.
    const SEMUA_PRODUK_KASIR = <?= json_encode(array_values(array_filter($produk, function ($p) {
                                    return (int)$p['id'] !== 1 && (int)$p['id'] !== 2;
                                })), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const KASIR_CONFIG = {
        // Cart kasir dipersist ke sessionStorage supaya tidak hilang
        // saat berpindah tab/reload sebelum transaksi disimpan.
        cartStorageKey: 'aulia_kasir_cart_v1',
        urls: {
            searchPelanggan: '<?= base_url('/api/search-pelanggan') ?>',
            editPelanggan: '<?= base_url('/pelanggan/edit/') ?>',
            generateOrder: '<?= base_url('/api/generate-order') ?>',
            availableNoOrders: '<?= base_url('/api/get-available-no-orders') ?>'
        }
    };
</script>
<script src="<?= base_url('assets/js/kasir-shared.js') ?>"></script>

<script>
    // ================================================================
    // 8. PEMBAYARAN, PIUTANG & CETAK (khusus halaman Kasir / transaksi baru)
    // ================================================================
    // Fungsi di bawah ini TIDAK dipindah ke kasir-shared.js karena
    // kasir/edit.php (edit transaksi) tidak melakukan proses pembayaran;
    // updateTransaksi() di controller hanya mengubah item/diskon/no_order/
    // pelanggan, tidak menerima data pembayaran sama sekali.
    function prosesPembayaran() {
        if (getCartItemCount() === 0) {
            showToast('Keranjang kosong. Tambahkan produk terlebih dahulu.', 'warning');
            return;
        }
        if (!cekKategoriDanNoOrder()) {
            return;
        }

        const total = hitungPembulatan(
            getCartTotal() - (Number(diskonValue) || 0)
        ).grand_total_setelah;

        bukaPaymentModal({
            mode: 'kasir',
            total: total,
            cart: window.auliaCart || [],
            kasirPayload: buildKasirPaymentPayload,
            onSuccess: handleKasirPaymentSuccess
        }).catch(function(error) {
            console.error('Payment modal error:', error);
            showToast(error.message || 'Gagal membuka modal pembayaran.', 'danger');
        });
    }

    function buildKasirPaymentPayload(metode, extras) {
        const noOrderAsli = document.getElementById('noOrderAsli')?.value || null;
        let pelangganId = null;
        let pelangganNama = null;
        let pelangganTelp = null;

        if (pelangganTerpilih && pelangganTerpilih.id) {
            pelangganId = pelangganTerpilih.id;
        } else {
            pelangganNama = document.getElementById('namaPelanggan').value.trim();
            pelangganTelp = document.getElementById('telpPelanggan').value.trim();
            if (pelangganNama) pelangganId = 'new';
        }

        return {
            keranjang: window.auliaCart || [],
            metode: metode,
            pelanggan: pelangganId,
            pelanggan_nama: pelangganNama,
            pelanggan_telp: pelangganTelp,
            no_order: noOrderAsli,
            diskon: diskonValue || 0,
            diskon_pelanggan_aktif: !!(document.getElementById('diskonPelangganCheckbox')?.checked),
            ...extras
        };
    }

    async function prosesPiutang() {
        if (getCartItemCount() === 0) {
            showToast('Keranjang kosong. Tambahkan produk terlebih dahulu.', 'warning');
            return;
        }

        if (!cekKategoriDanNoOrder()) return;

        const customerPayload = buildKasirPaymentPayload('piutang', {});

        if (!customerPayload.pelanggan) {
            const namaInput = document.getElementById('namaPelanggan');
            showToast('❌ Nama pelanggan wajib diisi untuk transaksi Piutang!', 'warning');
            namaInput?.focus();
            if (namaInput) namaInput.style.borderColor = 'red';
            setTimeout(() => {
                if (namaInput) namaInput.style.borderColor = '';
            }, 3000);
            return;
        }

        const total = hitungPembulatan(
            getCartTotal() - (Number(diskonValue) || 0)
        ).grand_total_setelah;
        if (total === 0 && !(await konfirmasi('Total belanja Rp 0. Lanjutkan transaksi?', { okText: 'Ya, Lanjutkan', okClass: 'btn-primary' }))) return;

        showToast('⏳ Memproses piutang...', 'info');

        $.ajax({
            url: '<?= base_url('/api/simpan-transaksi') ?>',
            type: 'POST',
            data: JSON.stringify(customerPayload),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    handleKasirPaymentSuccess(response, {
                        kembalian: 0
                    });
                } else {
                    showToast('❌ ' + response.message, 'danger');
                }
            },
            error: function(xhr, status, error) {
                console.error('Piutang error:', error);
                console.error('Response:', xhr.responseText);
                showToast('❌ Gagal menyimpan transaksi piutang.', 'danger');
            }
        });
    }

    function handleKasirPaymentSuccess(response, payment) {
        showModalSukses(response, payment.kembalian || 0);
        resetCartAfterTransaction();
        pelangganTerpilih = null;
        terapkanUIDiskonPelanggan();

        const namaInput = document.getElementById('namaPelanggan');
        const telpInput = document.getElementById('telpPelanggan');
        const btnEdit = document.getElementById('btnEditTelpPelanggan');

        if (namaInput) {
            namaInput.value = '';
        }

        if (telpInput) {
            telpInput.value = '';
            telpInput.readOnly = false;
        }

        if (btnEdit) {
            btnEdit.style.display = 'none';
        }
        resetDiskon();
        refreshNoOrderDropdown();
        resetNoOrder();
    }

    function showModalSukses(response, kembalian = 0) {
        $('.toast').toast('hide');

        const status = response.status_pembayaran || 'belum_bayar';
        const statusLabel = status === 'lunas' ? '✅ LUNAS' : '⚠️ ' + status.toUpperCase();
        const statusColor = status === 'lunas' ? 'text-success' : 'text-danger';

        let html = `
        <div class="text-center mb-3">
            <h4 class="${statusColor}">${statusLabel}</h4>
            <p><strong>Invoice:</strong> ${escapeHtmlKasir(response.invoice)}</p>
            <p><strong>Total:</strong> ${formatRupiah(response.grand_total)}</p>
            ${response.total_dibayar > 0 ? `<p><strong>Dibayar:</strong> ${formatRupiah(response.total_dibayar)}</p>` : ''}
            ${response.sisa_tagihan > 0 ? `<p class="text-danger"><strong>Sisa Tagihan:</strong> ${formatRupiah(response.sisa_tagihan)}</p>` : ''}
    `;

        if (kembalian > 0) {
            html += `
            <div class="alert alert-success py-2 mt-2">
                <strong><i class="fas fa-money-bill-wave"></i> Kembalian:</strong>
                <span style="font-size: 1.3rem;">${formatRupiah(kembalian)}</span>
            </div>
        `;
        }

        const transaksiId = Number(response.transaksi_id) || 0;
        html += `
        </div>
        <div class="d-grid gap-2">
            <button class="btn btn-outline-dark" onclick="cetakTicket(${transaksiId})">
                <i class="fas fa-id-card"></i> Ticket
            </button>
            <button class="btn btn-primary" onclick="cetakNota(${transaksiId})">
                <i class="fas fa-print"></i> Nota
            </button>
            <button class="btn btn-success" onclick="cetakThermal(${transaksiId})">
                <i class="fas fa-receipt"></i> Thermal
            </button>
        </div>
    `;

        document.getElementById('modalSuksesBody').innerHTML = html;
        $('#modalSukses').modal('show');

        resetNoOrder();
    }

    // ---------------------------------------------------------------
    // CETAK NOTA, THERMAL, & TICKET
    // ---------------------------------------------------------------
    function cetakNota(id) {
        window.open('<?= base_url('/cetak/nota/') ?>' + id, '_blank', 'width=700');
    }

    // 🔥 Ticket (handover antar-karyawan) -- BUKAN transaksi/pembayaran
    // baru, murni cetak langsung ke printer thermal (server-side,
    // reuse infrastructure yang sama dengan cetakThermal()) untuk ID
    // transaksi yang beneran baru saja tersimpan. Klik berkali-kali/
    // double-click aman -- tidak ada request yang menulis apa pun
    // ke DB, cuma kirim ulang perintah cetak.
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
                // Sama seperti cetakThermal() -- request GAGAL di level
                // HTTP (status bukan 2xx) walau body-nya tetap JSON valid
                // berisi pesan asli dari controller. jQuery menganggap
                // status non-2xx sebagai error TANPA mem-parsing body ke
                // `success`, jadi pesan aslinya harus diambil manual dari
                // xhr.responseJSON di sini -- kalau tidak, yang tampil
                // cuma fallback generik dan penyebab sebenarnya (mis.
                // gagal konek ke printer) jadi tidak kelihatan.
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
</script>

<script>
    window.paymentModalConfig = {
        modalUrl: '<?= base_url('/api/modal/pembayaran') ?>',
        kasirTransactionUrl: '<?= base_url('/api/simpan-transaksi') ?>',
        existingPaymentUrl: '<?= base_url('/api/tambah-pembayaran') ?>',
        tagihanLunasiUrl: '<?= base_url('/tagihan/lunasi/:id') ?>'
    };
</script>
<script src="<?= base_url('assets/js/payment.js') ?>"></script>

<?php if (($reminderTagihan['show'] ?? false)): ?>
<script>
    // Reminder tagihan 3 hari terakhir milik kasir yang login.
    // Lihat Kasir::getReminderTagihanSaya() untuk kriteria & jeda 15
    // menitnya, dan docs/aturan-bisnis-AULIA.md Section 25.
    //
    // SENGAJA pakai modal konfirmasi() (dua tombol eksplisit: Tutup /
    // Lihat), BUKAN showToast() -- toast auto-hilang setelah beberapa
    // detik meski tipe warning, sedangkan reminder ini harus tetap
    // ada sampai kasir benar-benar meresponnya secara sadar (klik
    // salah satu tombol), bukan hilang sendiri sambil terlewat.
    document.addEventListener('DOMContentLoaded', function() {
        const jumlah = <?= (int) ($reminderTagihan['count'] ?? 0) ?>;
        const pesan = 'Ada ' + jumlah + ' tagihan dari 3 hari terakhir yang perlu dicek lagi.';

        konfirmasi(pesan, {
            title: 'Reminder Tagihan',
            okText: 'Lihat',
            okClass: 'btn-warning',
            cancelText: 'Tutup',
        }).then(function(lihat) {
            if (lihat) {
                window.open('<?= base_url('/tagihan?saya=1') ?>', '_blank');
            }
        });
    });
</script>
<?php endif; ?>