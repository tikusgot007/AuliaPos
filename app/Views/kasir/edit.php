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

<!-- INFO TRANSAKSI YANG SEDANG DIEDIT -->
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <i class="fas fa-edit"></i>
        Mengedit transaksi <strong><?= esc($transaksi['kode_invoice'] ?? ('#' . $transaksi_id)) ?></strong>
        &mdash; status pembayaran saat ini:
        <strong class="text-uppercase"><?= esc($transaksi['status_pembayaran'] ?? 'belum_bayar') ?></strong>,
        sudah dibayar <strong>Rp <?= number_format((float) ($transaksi['total_dibayar'] ?? 0), 0, ',', '.') ?></strong>.
        Jika total baru lebih kecil dari yang sudah dibayar, selisihnya tercatat sebagai
        <strong>kelebihan bayar</strong> pada transaksi ini (bukan direfund otomatis).
        Refund fisik ke pelanggan, kalau memang diperlukan, dicatat manual lewat
        <strong>Kas Keluar &rarr; kategori "Refund Penjualan"</strong>.
    </div>
    <a href="<?= base_url('/transaksi/detail/' . $transaksi_id) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left"></i> Kembali ke Detail
    </a>
</div>

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
                value="<?= esc($pelanggan['nama'] ?? '') ?>"
                placeholder="Cari atau masukkan pelanggan baru"
                oninput="handleCustomerNameInput(this.value)">
        </div>

        <small class="text-muted">Pelanggan baru akan disimpan saat perubahan disimpan.</small>

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
                value="<?= esc($pelanggan['no_hp'] ?? '') ?>"
                placeholder="No. Telp (opsional)"
                readonly>

            <button
                type="button"
                id="btnEditTelpPelanggan"
                class="btn btn-outline-secondary"
                style="display:<?= !empty($pelanggan) ? 'inline-block' : 'none' ?>;"
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
                                <?= ($orderNumber == $selected_no_order) ? 'selected' : '' ?>>
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
                    value="<?= !empty($selected_no_order) ? (int) $selected_no_order : '' ?>">
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
            <div class="card-header bg-warning d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-shopping-cart"></i> Item Transaksi
                </span>

                <div>
                    <span class="badge bg-light text-dark" id="jumlahItem">0</span>

                    <button
                        class="btn btn-danger btn-sm ms-2"
                        onclick="clearKeranjang()"
                        title="Kosongkan Semua Item">
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

                    <!-- 🔥 DISKON PELANGGAN OTOMATIS (lihat kasir/index.php) -->
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
                                style="width: 80px; font-size: 0.9rem; height: 28px;" placeholder="0" min="0"
                                value="<?= (float) ($transaksi['diskon'] ?? 0) ?>"
                                oninput="hitungDiskon()">
                            <select id="diskonTipe" class="form-select form-select-sm" style="width: 55px; font-size: 0.9rem; height: 28px;" onchange="hitungDiskon()">
                                <option value="percent">%</option>
                                <option value="nominal" selected>Rp</option>
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
                    <button class="btn btn-success" onclick="simpanPerubahanTransaksi()" style="font-size: 0.9rem;">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= base_url('/transaksi/detail/' . $transaksi_id) ?>" style="font-size: 0.9rem;">
                        <i class="fas fa-times"></i> Batal
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?= $this->include('kasir/modal_banner') ?>

<?= $this->include('kasir/modal_produk') ?>

<?= $this->section('scripts') ?>

<!--
    PETA KODE EDIT TRANSAKSI
    ----------------------------------------------------------------
    Halaman ini memakai logic bersama dari public/assets/js/kasir-shared.js
    (state umum, no order, keranjang, diskon, katalog produk, pelanggan,
    banner) — sama seperti kasir/index.php.

    Yang khusus di halaman ini:
    - Prefill keranjang dari $detail_items (item transaksi yang sudah
      tersimpan), termasuk rekonstruksi item Banner dari nama produk.
    - Prefill pelanggan & no_order dari $transaksi.
    - simpanPerubahanTransaksi(): submit ke Transaksi::updateTransaksi(),
      BUKAN ke Api::simpanTransaksi(). Tidak ada modal pembayaran karena
      updateTransaksi() tidak menerima data pembayaran sama sekali.
-->
<script>
    // ================================================================
    // KONFIGURASI UNTUK kasir-shared.js
    // ================================================================
    const SEMUA_PRODUK_KASIR = <?= json_encode(array_values(array_filter($produk, function ($p) {
                                    return (int)$p['id'] !== 1 && (int)$p['id'] !== 2;
                                })), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const KASIR_CONFIG = {
        // Item transaksi datang dari database (bukan sessionStorage),
        // jadi cart di halaman ini TIDAK dipersist ke sessionStorage.
        cartStorageKey: null,
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
    // PREFILL KERANJANG DARI DETAIL TRANSAKSI TERSIMPAN
    // ================================================================
    // detail_transaksi tidak menyimpan flag is_banner/is_custom/detail{p,l};
    // untuk item Banner, nama produknya punya format baku
    // "Banner PxLm (luas m²)" (lihat buatItemBannerDariModal di
    // kasir-shared.js), jadi kita bisa rekonstruksi detail-nya di sini
    // supaya tombol "Kelola Banner" tetap berfungsi normal.
    const RAW_DETAIL_ITEMS = <?= json_encode($detail_items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function rekonstruksiDetailBanner(nama, harga, qty) {
        const pattern = /Banner\s+([\d.]+)mx([\d.]+)m\s*\(([\d.]+)\s*m²\)/;
        const match = String(nama || '').match(pattern);

        if (!match) return null;

        const p = parseFloat(match[1]) * 100; // meter -> cm
        const l = parseFloat(match[2]) * 100;
        const luas = parseFloat(match[3]);
        const hargaPerM2 = luas > 0 ? Math.round(harga / luas) : 22000;

        return {
            p,
            l,
            luas,
            qty,
            harga_per_m2: hargaPerM2
        };
    }

    (function primeCartFromTransaksi() {
        RAW_DETAIL_ITEMS.forEach(function(item) {
            const qty = Number(item.jumlah) || 1;
            const harga = Number(item.harga_satuan) || 0;
            const subtotal = Number(item.subtotal) || (harga * qty);
            const kategoriId = Number(item.kategori_id) || 0;
            const namaProduk = item.nama_produk || '';

            const detailBanner = rekonstruksiDetailBanner(namaProduk, subtotal, qty);
            const isBanner = detailBanner !== null;

            cart.push({
                id: 'existing_' + item.id,
                produk_id: Number(item.produk_id) || 1,
                kategori_id: kategoriId,
                nama: namaProduk,
                harga: harga,
                satuan: 'pcs',
                jumlah: qty,
                subtotal: subtotal,
                is_custom: false,
                is_banner: isBanner,
                is_manual: false,
                is_edit: true,
                is_cetak: kategoriId === 16,
                detail: detailBanner
            });
        });
    })();

    // ================================================================
    // PREFILL PELANGGAN
    // ================================================================
    <?php if (!empty($pelanggan)): ?>
        pilihPelanggan(
            <?= (int) $pelanggan['id'] ?>,
            <?= json_encode($pelanggan['nama'] ?? '') ?>,
            <?= json_encode($pelanggan['no_hp'] ?? '') ?>,
            <?= (float) ($pelanggan['diskon'] ?? 0) ?>,
            false
        );

        // pilihPelanggan() di atas otomatis meng-CENTANG checkbox kalau
        // pelanggan SAAT INI punya diskon > 0 -- tapi transaksi ini
        // mungkin dulu dibuat TANPA diskon pelanggan (manual/tidak
        // dicentang), atau pelanggan.diskon sudah berubah sejak saat
        // itu. Paksa state checkbox mengikuti nilai yang BENERAN
        // tersimpan di transaksi ini (diskon_pelanggan_persen),
        // supaya reopen halaman edit tidak diam-diam mengubah dasar
        // perhitungan diskonnya.
        (function () {
            const persenTersimpan = <?= $transaksi['diskon_pelanggan_persen'] !== null
                ? (float) $transaksi['diskon_pelanggan_persen']
                : 'null' ?>;
            const checkbox = document.getElementById('diskonPelangganCheckbox');
            const box = document.getElementById('diskonPelangganBox');
            const label = document.getElementById('diskonPelangganPersenLabel');

            if (!checkbox) return;

            if (persenTersimpan !== null) {
                // Diskon pelanggan memang aktif saat transaksi ini
                // dibuat -- percayai nilai yang TERSIMPAN (bukan
                // diskon pelanggan SAAT INI, yang bisa saja sudah
                // berubah di master sejak transaksi dibuat). Paksa
                // box tetap terlihat & interaktif meski pelanggan
                // saat ini kebetulan diskon-nya 0 (tetap transparan
                // ke kasir bahwa transaksi ini pakai diskon pelanggan).
                if (box) box.style.display = 'flex';
                if (label) label.textContent = persenTersimpan + '%';
                checkbox.dataset.persen = String(persenTersimpan);
                checkbox.checked = true;
                toggleDiskonPelanggan();
            } else {
                // Diskon pelanggan TIDAK aktif saat itu -- biarkan
                // diskonInput tetap seperti nilai nominal Rp yang
                // sudah di-prefill lewat atribut value="" di HTML,
                // JANGAN dipanggil toggleDiskonPelanggan() di sini
                // (itu akan mereset field ke 0, menghapus diskon
                // manual yang sudah tersimpan).
                checkbox.checked = false;
            }
        })();
    <?php endif; ?>

    // ================================================================
    // SIMPAN PERUBAHAN
    // ================================================================
    const EDIT_TRANSAKSI_ID = <?= (int) $transaksi_id ?>;
    const UPDATE_TRANSAKSI_URL = '<?= base_url('/transaksi/update-transaksi/' . $transaksi_id) ?>';
    const DETAIL_TRANSAKSI_URL = '<?= base_url('/transaksi/detail/' . $transaksi_id) ?>';

    function buildUpdatePayload() {
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
            pelanggan: pelangganId,
            pelanggan_nama: pelangganNama,
            pelanggan_telp: pelangganTelp,
            no_order: noOrderAsli,
            diskon: diskonValue || 0,
            diskon_pelanggan_aktif: !!(document.getElementById('diskonPelangganCheckbox')?.checked)
        };
    }

    function simpanPerubahanTransaksi() {
        if (getCartItemCount() === 0) {
            showToast('Item transaksi kosong. Tidak bisa disimpan.', 'warning');
            return;
        }

        if (!cekKategoriDanNoOrder()) {
            return;
        }

        const payload = buildUpdatePayload();

        showToast('⏳ Menyimpan perubahan...', 'info');

        $.ajax({
            url: UPDATE_TRANSAKSI_URL,
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            dataType: 'json',
            success: function(response) {
                if (response.status !== 'success') {
                    showToast('❌ ' + (response.message || 'Gagal menyimpan perubahan.'), 'danger');
                    return;
                }

                const adaKelebihan = Number(response.kelebihan_bayar) > 0;

                // Kalau ada kelebihan bayar, pakai gaya 'warning' (lebih menonjol)
                // supaya kasir tidak melewatkan info ini sebelum diarahkan ke detail.
                showToast('✅ ' + response.message, adaKelebihan ? 'warning' : 'success');

                setTimeout(function() {
                    window.location.href = response.redirect || DETAIL_TRANSAKSI_URL;
                }, adaKelebihan ? 1800 : 900);
            },
            error: function(xhr) {
                console.error('Update transaksi error:', xhr.responseText);
                let message = 'Gagal menyimpan perubahan transaksi.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                showToast('❌ ' + message, 'danger');
            }
        });
    }
</script>

<?= $this->endSection() ?>