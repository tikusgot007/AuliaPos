/**
 * kasir-shared.js
 * ------------------------------------------------------------------
 * Logic bersama untuk halaman Kasir (kasir/index.php) dan
 * Edit Transaksi (kasir/edit.php).
 *
 * Sengaja TIDAK dibungkus IIFE / module: semua fungsi & variabel di
 * sini harus tetap ada di global scope karena dipanggil lewat atribut
 * event (onclick, oninput, dst.) di modal_banner.php, modal_produk.php,
 * dan halaman kasir itu sendiri.
 *
 * KONTRAK dengan halaman pemanggil (index.php / edit.php):
 * ------------------------------------------------------------------
 * Sebelum <script src="kasir-shared.js">, halaman WAJIB mendefinisikan:
 *
 *   const SEMUA_PRODUK_KASIR = [...]; // hasil json_encode($produk)
 *   const KASIR_CONFIG = {
 *       cartStorageKey: 'aulia_kasir_cart_v1' | null,
 *       // null artinya cart TIDAK dipersist ke sessionStorage
 *       // (dipakai di edit.php, karena cart datang dari data transaksi)
 *       urls: {
 *           searchPelanggan:   '<?= base_url('/api/search-pelanggan') ?>',
 *           editPelanggan:     '<?= base_url('/pelanggan/edit/') ?>',
 *           generateOrder:     '<?= base_url('/api/generate-order') ?>',
 *           availableNoOrders: '<?= base_url('/api/get-available-no-orders') ?>',
 *       }
 *   };
 *
 * Setelah <script src="kasir-shared.js">, halaman boleh menambah item
 * awal ke keranjang (mis. edit.php mengisi dari $detail_items) dengan:
 *
 *   cart.push(...itemAwal);
 *
 * karena tampilan awal keranjang baru dirender saat $(document).ready
 * (loadKeranjang), yang dijamin berjalan setelah seluruh <script> di
 * halaman selesai dieksekusi.
 * ------------------------------------------------------------------
 */

// ================================================================
// 1. STATE & HELPER UMUM
// ================================================================
let diskonValue = 0;
let diskonTipe = 'percent';
let subtotalAsli = 0;

let pelangganTerpilih = null;

// State keranjang sengaja berupa satu array agar referensi yang dipakai
// payment.js (di kasir) dan modal produk selalu menunjuk data yang sama.
const cart = [];

(function primeCartFromStorage() {
    if (typeof KASIR_CONFIG === 'undefined' || !KASIR_CONFIG.cartStorageKey) {
        return;
    }

    try {
        const savedCart = JSON.parse(sessionStorage.getItem(KASIR_CONFIG.cartStorageKey) || 'null');
        if (Array.isArray(savedCart)) {
            cart.push(...savedCart);
        }
    } catch (e) {
        console.warn('Cart sessionStorage tidak dapat dibaca:', e);
    }
})();

window.auliaCart = cart;
window.keranjangData = cart;

function formatRupiah(angka) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(angka);
}

// ---------------------------------------------------------------
// 2. NO ORDER
// ---------------------------------------------------------------
function toggleManualOrder() {
    const checkbox = document.getElementById('manual_order_checkbox');
    const select = document.getElementById('no_order_input2');
    const input = document.getElementById('noOrderInput');
    const raw = document.getElementById('noOrderAsli');

    if (!checkbox || !select || !input || !raw) {
        return;
    }

    if (checkbox.checked) {
        // Mode manual
        select.disabled = true;

        input.disabled = false;
        input.focus();
        input.select();

        select.value = '';
        raw.value = input.value.trim();
    } else {
        // Kembali ke mode pilihan
        select.disabled = false;
        input.disabled = true;

        // Ambil No Order terpilih dari dropdown
        const selectedValue = select.value;

        // Jika masih ada pilihan, tampilkan kembali
        if (selectedValue) {
            input.value = formatNoOrder(selectedValue);
            raw.value = selectedValue;
        } else {
            input.value = '';
            raw.value = '';
        }
    }
}

function gantiNo() {
    const select = document.getElementById('no_order_input2');
    const input = document.getElementById('noOrderInput');
    const raw = document.getElementById('noOrderAsli');

    if (!select || !input || !raw) return;

    const value = select.value;

    raw.value = value;
    input.value = value ? formatNoOrder(value) : '';
}
document.getElementById('noOrderInput')?.addEventListener('input', function () {
    if (!document.getElementById('manual_order_checkbox')?.checked) return;

    const value = this.value.trim();
    document.getElementById('noOrderAsli').value = value;
});

function generateNewOrder() {
    $.ajax({
        url: KASIR_CONFIG.urls.generateOrder,
        type: 'GET',
        dataType: 'json',
        success: function (response) {
            if (response.status !== 'success') {
                showToast(response.message || 'Gagal membuat No. Order.', 'danger');
                return;
            }

            const noOrder = String(response.no_order || '');
            const select = document.getElementById('no_order_input2');
            const input = document.getElementById('noOrderInput');
            const raw = document.getElementById('noOrderAsli');

            if (!select || !input || !raw) return;

            const option = Array.from(select.options).find(
                item => item.value === noOrder
            );

            if (option) {
                select.value = noOrder;
            } else {
                const newOption = document.createElement('option');
                newOption.value = noOrder;
                newOption.textContent = noOrder;
                select.appendChild(newOption);
                select.value = noOrder;
            }

            input.value = formatNoOrder(noOrder);
            raw.value = noOrder;
        },
        error: function (xhr, status, error) {
            console.error('Generate No Order:', error);
            showToast('Gagal membuat No. Order baru.', 'danger');
        }
    });
}

function refreshNoOrderDropdown() {
    $.ajax({
        url: KASIR_CONFIG.urls.availableNoOrders,
        type: 'GET',
        dataType: 'json',
        success: function (response) {
            if (response.status !== 'success') return;

            const select = document.getElementById('no_order_input2');
            if (!select) return;

            select.innerHTML = '<option value="">Pilih No. Order</option>';
            response.available.forEach(function (no) {
                const option = document.createElement('option');
                option.value = no;
                option.textContent = formatNoOrder(no) + (no == response.recommended ? '' : '');
                if (no == response.recommended) option.className = 'fw-bold';
                select.appendChild(option);
            });

            const raw = document.getElementById('noOrderAsli');
            if (raw) raw.value = '';
        }
    });
}

function cekKategoriDanNoOrder() {
    const keranjang = window.keranjangData || [];
    const noOrderAsli = document.getElementById('noOrderAsli')?.value || '';
    const noOrderInput = document.getElementById('noOrderInput');

    const hasFotoStudio = keranjang.some(item => {
        const kategoriId = parseInt(item.kategori_id);
        return kategoriId === 16 || item.is_cetak === true || (item.is_custom === true && item.is_cetak === true);
    });

    if (hasFotoStudio && !noOrderAsli) {
        showToast('❌ Transaksi mengandung produk Studio/Foto. No Order wajib diisi!', 'danger');
        noOrderInput?.focus();
        return false;
    }

    if (!hasFotoStudio && noOrderAsli) {
        showToast('❌ Transaksi tidak mengandung produk Studio/Foto. No Order harus dikosongkan!', 'danger');
        if (noOrderInput) noOrderInput.value = '';
        document.getElementById('noOrderAsli').value = '';
        return false;
    }

    return true;
}

function formatNoOrder(noOrder) {
    const ambang = 100000;
    const siklus = 9999;
    if (noOrder < 1) return String(noOrder);
    if (noOrder <= ambang) return String(noOrder);

    const posisi = noOrder - ambang;
    const indexSiklus = Math.floor((posisi - 1) / siklus);
    const nomorDalamSiklus = ((posisi - 1) % siklus) + 1;
    return angkaKeHuruf(indexSiklus) + String(nomorDalamSiklus).padStart(String(siklus).length, '0');
}

function angkaKeHuruf(num) {
    num++;
    let huruf = '';
    while (num > 0) {
        num--;
        huruf = String.fromCharCode(65 + (num % 26)) + huruf;
        num = Math.floor(num / 26);
    }
    return huruf;
}

function resetNoOrder() {
    const checkbox = document.getElementById('manual_order_checkbox');
    const select = document.getElementById('no_order_input2');
    const input = document.getElementById('noOrderInput');
    const raw = document.getElementById('noOrderAsli');

    if (checkbox) checkbox.checked = false;

    if (select) {
        select.disabled = false;
        select.value = '';
    }

    if (input) {
        input.disabled = true;
        input.value = '';
    }

    if (raw) {
        raw.value = '';
    }
}

// ================================================================
// 3. KERANJANG
// ================================================================
function persistCart() {
    if (KASIR_CONFIG.cartStorageKey) {
        sessionStorage.setItem(KASIR_CONFIG.cartStorageKey, JSON.stringify(cart));
    }
    window.keranjangData = cart;
}

function getCartTotal() {
    return cart.reduce(function (total, item) {
        return total + (Number(item.subtotal) || 0);
    }, 0);
}

function getCartItemCount() {
    return cart.length;
}

function renderLocalCart() {
    window.keranjangData = cart;
    tampilkanKeranjang(cart);
}

function resetCartAfterTransaction() {
    cart.splice(0, cart.length);
    persistCart();
    renderLocalCart();
}

$(document).ready(function () {
    loadKeranjang();
});

function loadKeranjang() {
    renderLocalCart();
    hitungDiskon();
}

function tampilkanKeranjang(keranjang) {
    window.keranjangData = keranjang;

    const keranjangKosong = document.getElementById('keranjangKosong');
    const keranjangList = document.getElementById('keranjangList');
    const jumlahItemEl = document.getElementById('jumlahItem');
    const totalBayarEl = document.getElementById('totalBayar');
    const detailPembulatan = document.getElementById('detailPembulatan');

    if (!keranjangKosong || !keranjangList) {
        console.error('Element keranjang tidak ditemukan!');
        return;
    }

    let html = '';
    if (keranjang.length === 0) {
        keranjangKosong.style.display = 'block';
        keranjangList.innerHTML = '';
        if (detailPembulatan) detailPembulatan.style.display = 'none';
        if (jumlahItemEl) jumlahItemEl.textContent = '0';
        if (totalBayarEl) totalBayarEl.textContent = formatRupiah(0);
        return;
    } else {
        keranjangKosong.style.display = 'none';
        keranjang.forEach(function (item) {
            const itemKey = item.id;
            const isCustom = item.is_custom || false;
            const isBanner = item.is_banner || false;
            const isManual = item.is_manual || false;

            const disabledAttr = (isBanner || isManual) ? 'disabled' : '';
            const disabledClass = (isBanner || isManual) ? 'opacity-50' : '';

            const badgeCustom = isCustom ? '<span class="badge bg-warning text-dark ms-1" style="font-size: 0.5rem;">Custom</span>' : '';
            const badgeBanner = isBanner ? '<span class="badge bg-info text-white ms-1" style="font-size: 0.5rem;">Banner</span>' : '';
            const badgeManual = isManual ? '<span class="badge bg-warning text-dark ms-1" style="font-size: 0.5rem;">Manual</span>' : '';

            const hargaPerUnit = item.jumlah > 0 ? Math.round(item.subtotal / item.jumlah) : item.harga;

            html += `
                <div class="d-flex justify-content-between align-items-center border-bottom py-1" style="font-size: 0.75rem;">
                    <div class="flex-grow-1" style="min-width: 0;">
                        <div class="d-flex align-items-center">
                            <strong style="font-size: 0.9rem; word-wrap: break-word; line-height: 1.2;">${escapeHtmlKasir(item.nama)}</strong>

                            ${badgeCustom}
                            ${badgeBanner}
                            ${badgeManual}
                        </div>
                        <div class="d-flex align-items-center mt-1">
                            <button class="btn btn-sm btn-outline-secondary" onclick="updateJumlah('${itemKey}', ${item.jumlah - 1})" ${disabledAttr} style="padding: 0px 4px; font-size: 0.6rem; line-height: 1.2;">-</button>
                            <input type="number" class="form-control form-control-sm text-center mx-1 ${disabledClass}"
                                   style="width: 40px; font-size: 0.7rem; padding: 1px 2px; height: 20px;"
                                   value="${item.jumlah}"
                                   min="1"
                                   data-key="${itemKey}"
                                   data-harga="${hargaPerUnit}"
                                   onfocus="this.select()"
                                   onblur="updateJumlahDariInput(this)"
                                   ${disabledAttr}>
                            <button class="btn btn-sm btn-outline-secondary" onclick="updateJumlah('${itemKey}', ${item.jumlah + 1})" ${disabledAttr} style="padding: 0px 4px; font-size: 0.6rem; line-height: 1.2;">+</button>
                            <span class="ms-1 text-muted" style="font-size: 0.9rem;">@ ${formatRupiah(hargaPerUnit)}</span>
                            <span class="ms-1 text-primary" style="font-size: 0.9rem; font-weight: bold;">${formatRupiah(item.subtotal)}</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">

                        <button class="btn btn-sm btn-danger" onclick="hapusDariKeranjang('${itemKey}')" style="padding: 0px 4px; font-size: 0.6rem; line-height: 1.2;">
                            <i class="fas fa-times" style="font-size: 0.6rem;"></i>
                        </button>
                    </div>
                </div>
            `;
        });
        keranjangList.innerHTML = html;
    }

    if (jumlahItemEl) jumlahItemEl.textContent = keranjang.length;

    if (detailPembulatan) {
        detailPembulatan.style.display = keranjang.length > 0 ? 'block' : 'none';
    }

    hitungDiskon();
}

function tambahKeKeranjang(id, nama, harga, kategoriId) {
    tampilkanModalUbahHarga(id, nama, harga, kategoriId);
}

function tambahItemKeCart(item) {
    const qty = Number(item.jumlah) || 1;
    const harga = Number(item.harga) || 0;

    const cartItem = {
        id: item.id || `item_${Date.now()}`,
        produk_id: Number(item.produk_id),
        kategori_id: Number(item.kategori_id),
        nama: item.nama || '',
        harga,
        satuan: item.satuan || 'pcs',
        jumlah: qty,
        subtotal: Number(item.subtotal ?? harga * qty),
        is_custom: item.is_custom === true,
        is_banner: item.is_banner === true,
        is_manual: item.is_manual === true,
        is_edit: item.is_edit === true,
        is_cetak: item.is_cetak === true,
        detail: item.detail || null
    };

    cart.push(cartItem);

    persistCart();
    renderLocalCart();

    return cartItem;
}

function updateJumlah(id, jumlahBaru) {
    const index = cart.findIndex(item => String(item.id) === String(id));
    if (index === -1) {
        showToast('Item tidak ditemukan di keranjang.', 'danger');
        return;
    }

    const qty = Number(jumlahBaru);
    if (!Number.isFinite(qty) || qty <= 0) {
        cart.splice(index, 1);
    } else {
        cart[index].jumlah = qty;
        cart[index].subtotal = qty * (Number(cart[index].harga) || 0);
    }

    persistCart();
    renderLocalCart();
}

function updateJumlahDariInput(input) {
    const key = input.dataset.key;
    let jumlahBaru = parseInt(input.value, 10) || 1;

    if (jumlahBaru < 1) {
        jumlahBaru = 1;
        input.value = 1;
    }

    if (jumlahBaru > 9999) {
        jumlahBaru = 9999;
        input.value = 9999;
        showToast('Jumlah maksimal 9999.', 'warning');
    }

    updateJumlah(key, jumlahBaru);
}

function hapusDariKeranjang(id) {
    const index = cart.findIndex(item => String(item.id) === String(id));
    if (index === -1) {
        showToast('Item tidak ditemukan di keranjang.', 'danger');
        return;
    }

    cart.splice(index, 1);
    persistCart();
    renderLocalCart();
    showToast('Item berhasil dihapus.', 'success');
}

async function clearKeranjang() {
    if (!(await konfirmasi('Yakin ingin mengosongkan keranjang?', { okText: 'Ya, Kosongkan' }))) return;
    resetCartAfterTransaction();
    showToast('Keranjang telah dikosongkan.', 'success');
}

// ---------------------------------------------------------------
// 4. DISKON & PEMBULATAN
// ---------------------------------------------------------------
function hitungDiskon() {
    const inputVal = parseFloat(document.getElementById('diskonInput').value) || 0;
    diskonTipe = document.getElementById('diskonTipe').value;

    subtotalAsli = 0;
    const keranjang = window.keranjangData || [];
    keranjang.forEach(item => {
        subtotalAsli += item.subtotal;
    });

    if (keranjang.length === 0) {
        document.getElementById('previewSubtotal').textContent = 'Rp 0';
        document.getElementById('previewPembulatan').textContent = 'Rp 0';
        document.getElementById('previewPembulatanRow').style.display = 'none';
        document.getElementById('diskonInfo').textContent = 'Rp 0';
        document.getElementById('totalBayar').textContent = 'Rp 0';
        return;
    }

    let diskonRp = 0;
    if (diskonTipe === 'percent') {
        diskonRp = (subtotalAsli * inputVal) / 100;
    } else {
        diskonRp = inputVal;
    }
    if (diskonRp > subtotalAsli) diskonRp = subtotalAsli;
    diskonRp = Math.round(diskonRp);
    diskonValue = diskonRp;

    const totalSetelahDiskon = subtotalAsli - diskonRp;

    const hasilPembulatan = hitungPembulatan(totalSetelahDiskon);
    const totalFinal = hasilPembulatan.grand_total_setelah;
    const selisihPembulatan = hasilPembulatan.selisih_pembulatan;

    document.getElementById('previewSubtotal').textContent = formatRupiah(subtotalAsli);
    document.getElementById('diskonInfo').textContent = formatRupiah(diskonRp);

    const previewPembulatan = document.getElementById('previewPembulatan');
    const previewPembulatanRow = document.getElementById('previewPembulatanRow');

    if (hasilPembulatan.ada_pembulatan) {
        previewPembulatan.textContent = '-' + formatRupiah(selisihPembulatan);
        previewPembulatanRow.style.display = 'flex';
    } else {
        previewPembulatan.textContent = 'Rp 0';
        previewPembulatanRow.style.display = 'none';
    }

    document.getElementById('totalBayar').textContent = formatRupiah(totalFinal);
}

function hitungPembulatan(subtotal, diskon = 0) {
    const grandTotalSebelum = subtotal - diskon;
    const grandTotalSetelah = Math.floor(grandTotalSebelum / 100) * 100;
    const selisih = grandTotalSebelum - grandTotalSetelah;

    return {
        subtotal: subtotal,
        diskon: diskon,
        grand_total_sebelum: grandTotalSebelum,
        grand_total_setelah: grandTotalSetelah,
        selisih_pembulatan: selisih,
        ada_pembulatan: selisih > 0
    };
}

function resetDiskon() {
    const checkbox = document.getElementById('diskonPelangganCheckbox');
    if (checkbox) {
        checkbox.checked = false;
    }

    document.getElementById('diskonInput').value = 0;
    document.getElementById('diskonInput').disabled = false;
    document.getElementById('diskonTipe').value = 'percent';
    document.getElementById('diskonTipe').disabled = false;
    diskonValue = 0;
    hitungDiskon();
}

// ================================================================
// 5. KATALOG PRODUK & 6. PELANGGAN
// ================================================================
let kategoriProdukAktif = '';
let keywordProdukAktif = '';

function escapeHtmlKasir(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatHargaKasir(value) {
    return new Intl.NumberFormat('id-ID').format(Number(value) || 0);
}

function getTampilanKategoriKasir(kategoriId) {
    switch (Number(kategoriId)) {
        case 1:
            return {
                border: 'border-warning', bg: '#fff8e7', badge: 'bg-warning text-dark', icon: 'fa-box'
            };
        case 2:
            return {
                border: 'border-secondary', bg: '#f8f9fa', badge: 'bg-secondary', icon: 'fa-copy'
            };
        case 3:
            return {
                border: 'border-success', bg: '#f0fff4', badge: 'bg-success', icon: 'fa-coffee'
            };
        case 4:
            return {
                border: 'border-primary', bg: '#e8f4fd', badge: 'bg-primary', icon: 'fa-print'
            };
        case 16:
            return {
                border: 'border-danger', bg: '#fde8e8', badge: 'bg-danger', icon: 'fa-camera'
            };
        default:
            return {
                border: 'border-secondary', bg: '#f8f9fa', badge: 'bg-secondary', icon: 'fa-tag'
            };
    }
}

function renderProdukKasir() {
    const container = document.getElementById('produkList');
    if (!container) return;

    const keyword = keywordProdukAktif.toLowerCase();
    const kategori = String(kategoriProdukAktif || '');

    const hasil = SEMUA_PRODUK_KASIR.filter(function (p) {
        const cocokKategori = !kategori || String(p.kategori_id) === kategori;
        if (!cocokKategori) return false;

        if (!keyword) return true;

        const nama = String(p.nama || '').toLowerCase();
        const barcode = String(p.barcode || '').toLowerCase();
        return nama.includes(keyword) || barcode.includes(keyword);
    });

    /*
     * #produkList HANYA berisi kartu produk (hasil filter di atas).
     * Aksi Khusus (Banner/Manual Input/Ukuran Custom) berada di
     * section terpisah (#aksiKhususList) di luar container ini,
     * jadi tidak perlu di-preserve/di-render ulang di sini.
     */
    container.innerHTML = '';

    const fragment = document.createDocumentFragment();

    hasil.forEach(function (p) {
        const tampilan = getTampilanKategoriKasir(p.kategori_id);
        const wrap = document.createElement('div');
        wrap.className = 'produk-card-wrap';

        wrap.innerHTML = `
                <div class="card card-kasir p-2 text-center border ${tampilan.border} h-100"
                    style="cursor:pointer; background-color:${tampilan.bg};">
                    <div class="card-body p-1 d-flex flex-column justify-content-center">
                        <h6 class="card-title mb-1">
                            <i class="fas ${tampilan.icon} me-1"></i>${escapeHtmlKasir(p.nama)}
                        </h6>
                        <small class="text-muted">${formatHargaKasir(p.harga_jual)}</small>
                        <span class="badge ${tampilan.badge} mt-1">${escapeHtmlKasir(p.satuan)}</span>
                    </div>
                </div>`;

        const card = wrap.querySelector('.card');
        card.addEventListener('click', function () {
            tambahKeKeranjang(
                Number(p.id),
                String(p.nama || ''),
                Number(p.harga_jual) || 0,
                Number(p.kategori_id) || 0
            );
        });

        fragment.appendChild(wrap);
    });

    container.appendChild(fragment);

    const kosong = document.getElementById('produkKosong');
    const info = document.getElementById('produkInfo');
    const reset = document.getElementById('resetSearchProduk');

    if (kosong) kosong.classList.toggle('d-none', hasil.length > 0);
    if (info) {
        info.textContent = hasil.length + ' produk ditampilkan';
    }
    if (reset) {
        reset.classList.toggle('d-none', !kategoriProdukAktif && !keywordProdukAktif);
    }

    document.querySelectorAll('#filterKategoriProduk .btn-filter-kategori').forEach(function (button) {
        const aktif = String(button.dataset.kategori || '') === kategoriProdukAktif;
        button.classList.toggle('btn-primary', aktif);
        button.classList.toggle('active', aktif);
        button.classList.toggle('btn-outline-secondary', !aktif);
    });
}

function filterProdukByKategori(kategoriId) {
    kategoriProdukAktif = String(kategoriId || '');
    renderProdukKasir();
}

function terapkanFilterProduk() {
    const input = document.getElementById('searchProduk');
    keywordProdukAktif = (input?.value || '').trim();
    renderProdukKasir();
}

function resetFilterProduk() {
    kategoriProdukAktif = '';
    keywordProdukAktif = '';
    const input = document.getElementById('searchProduk');
    if (input) input.value = '';
    renderProdukKasir();
    input?.focus();
}

document.addEventListener('DOMContentLoaded', function () {
    restorePelangganFromUrl();
    const search = document.getElementById('searchProduk');
    if (!search) return;

    let timer = null;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            terapkanFilterProduk();
        }, 100);
    });

    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            terapkanFilterProduk();
        }
    });

    renderProdukKasir();
});

// ---------------------------------------------------------------
// 6. PELANGGAN: pencarian, pemilihan, dan kembali dari master
// ---------------------------------------------------------------
let customerSearchRequest = null;
let customerSearchSequence = 0;

function handleCustomerNameInput(keyword) {
    // Ketika nama diubah manual,
    // pelanggan lama tidak lagi dianggap terpilih.
    pelangganTerpilih = null;
    terapkanUIDiskonPelanggan();

    const telp = document.getElementById('telpPelanggan');
    const btnEdit = document.getElementById('btnEditTelpPelanggan');

    if (telp) {
        telp.value = '';
        telp.readOnly = false;
    }

    if (btnEdit) {
        btnEdit.style.display = 'none';
    }

    searchPelanggan(keyword);
}

function searchPelanggan(keyword) {
    const list = document.getElementById('listPelanggan');

    if (keyword.length < 2) {
        list.style.display = 'none';
        return;
    }

    if (customerSearchRequest) {
        customerSearchRequest.abort();
    }

    const searchSequence = ++customerSearchSequence;
    customerSearchRequest = $.ajax({
        url: KASIR_CONFIG.urls.searchPelanggan,
        type: 'GET',
        data: {
            keyword: keyword
        },
        dataType: 'json',
        success: function (response) {
            if (searchSequence !== customerSearchSequence) return;

            list.replaceChildren();
            if (response.status === 'success' && response.data.length > 0) {
                const namaInput = document.getElementById('namaPelanggan').value
                    .trim()
                    .toLowerCase();

                // Jika nama yang diketik PERSIS sama dengan pelanggan yang ditemukan,
                // otomatis pilih pelanggan tersebut tanpa harus klik list.
                const exact = response.data.find(function (p) {
                    return String(p.nama || '').trim().toLowerCase() === namaInput;
                });

                if (exact) {
                    pilihPelanggan(
                        exact.id,
                        exact.nama || '',
                        document.getElementById('telpPelanggan').value || exact.no_hp || '',
                        exact.diskon || 0
                    );

                    list.style.display = 'none';
                    return;
                }
                response.data.forEach(function (p) {
                    const option = document.createElement('a');
                    option.href = '#';
                    option.className = 'list-group-item list-group-item-action';

                    const name = document.createElement('strong');
                    name.textContent = p.nama || '';
                    option.appendChild(name);

                    if (p.no_hp) {
                        option.appendChild(document.createElement('br'));
                        const phone = document.createElement('small');
                        phone.textContent = p.no_hp;
                        option.appendChild(phone);
                    }

                    option.addEventListener('click', function (event) {
                        event.preventDefault();
                        pilihPelanggan(p.id, p.nama || '', p.no_hp || '', p.diskon || 0);
                    });
                    list.appendChild(option);
                });
            } else {
                const hint = document.createElement('div');
                hint.className = 'list-group-item text-muted small';
                list.appendChild(hint);
            }
            list.style.display = 'block';
        },
        error: function (xhr, status) {
            if (status !== 'abort') {
                list.style.display = 'none';
                showToast('Gagal mencari pelanggan.', 'danger');
            }
        }
    });
}

async function editTelpPelanggan() {
    if (!pelangganTerpilih || !pelangganTerpilih.id) {
        showToast('Pilih pelanggan terlebih dahulu.', 'warning');
        return;
    }

    const nama = pelangganTerpilih.nama || 'pelanggan';

    const lanjut = await konfirmasi(
        `Edit data pelanggan "${nama}"?\n\nAnda akan diarahkan ke halaman Master Pelanggan.`,
        { okText: 'Ya, Edit', okClass: 'btn-primary' }
    );

    if (!lanjut) {
        return;
    }

    window.location.href =
        KASIR_CONFIG.urls.editPelanggan +
        pelangganTerpilih.id +
        '?return=kasir';
}

function restorePelangganFromUrl() {
    const params = new URLSearchParams(window.location.search);

    const pelangganId = params.get('pelanggan_id');
    const pelangganNama = params.get('pelanggan_nama');
    const pelangganTelp = params.get('pelanggan_telp');
    const pelangganDiskon = params.get('pelanggan_diskon');

    if (!pelangganId || !pelangganNama) {
        return;
    }

    pilihPelanggan(
        pelangganId,
        pelangganNama,
        pelangganTelp || '',
        pelangganDiskon || 0
    );

    // Bersihkan parameter URL setelah berhasil dipakai.
    const cleanUrl =
        window.location.pathname +
        window.location.hash;

    window.history.replaceState({}, document.title, cleanUrl);
}

function pilihPelanggan(id, nama, no_hp, diskon, autoTerapkan) {
    customerSearchSequence++;

    if (customerSearchRequest) {
        customerSearchRequest.abort();
    }

    customerSearchRequest = null;

    const namaInput = document.getElementById('namaPelanggan');
    const telpInput = document.getElementById('telpPelanggan');
    const btnEdit = document.getElementById('btnEditTelpPelanggan');
    const list = document.getElementById('listPelanggan');

    if (namaInput) {
        namaInput.value = nama;
    }

    if (telpInput) {
        telpInput.value = no_hp || '';
        telpInput.readOnly = true;
    }

    if (btnEdit) {
        btnEdit.style.display = 'inline-block';
    }

    if (list) {
        list.style.display = 'none';
    }

    pelangganTerpilih = {
        id: parseInt(id),
        nama: nama,
        no_hp: no_hp || '',
        // 🔥 Diskon Pelanggan -- persen dari master pelanggan.diskon,
        // dipakai untuk mengisi checkbox "Diskon Pelanggan" secara
        // otomatis (lihat terapkanUIDiskonPelanggan()). Nilai final
        // yang benar-benar dipakai transaksi TETAP diresolusi ulang
        // di backend dari DB saat disimpan -- ini murni untuk UI/preview.
        diskon: parseFloat(diskon) || 0
    };

    // autoTerapkan=false (dipakai kasir/edit.php saat prefill) berarti
    // cuma tampilkan/sembunyikan box sesuai diskon pelanggan SAAT INI,
    // TANPA memaksa checkbox & field diskonInput -- supaya state
    // checkbox/nilai yang SUDAH tersimpan di transaksi (mungkin beda
    // dari diskon pelanggan saat ini) tidak keburu tertimpa sebelum
    // sempat di-set manual oleh halaman pemanggil.
    terapkanUIDiskonPelanggan(autoTerapkan === undefined ? true : autoTerapkan);
}

// ---------------------------------------------------------------
// 6b. DISKON PELANGGAN OTOMATIS
// ---------------------------------------------------------------
// Reuse mekanisme diskon manual yang sudah ada (diskonInput/
// diskonTipe/hitungDiskon()) -- checkbox ini cuma MODE baru untuk
// field yang sama, BUKAN sistem diskon kedua. Dua mode TIDAK PERNAH
// aktif bersamaan (mencegah double discount):
//   - checkbox ON  -> diskonInput dikunci ke persen pelanggan
//   - checkbox OFF -> diskonInput manual seperti sebelum fitur ini ada
function terapkanUIDiskonPelanggan(autoTerapkan) {
    if (autoTerapkan === undefined) autoTerapkan = true;

    const box = document.getElementById('diskonPelangganBox');
    const checkbox = document.getElementById('diskonPelangganCheckbox');
    const label = document.getElementById('diskonPelangganPersenLabel');

    if (!box || !checkbox) return;

    const persen = pelangganTerpilih ? (parseFloat(pelangganTerpilih.diskon) || 0) : 0;

    if (persen > 0) {
        box.style.display = 'flex';
        checkbox.dataset.persen = String(persen);
        if (label) label.textContent = persen + '%';
        // Default aktif begitu pelanggan dgn diskon dipilih (sesuai
        // spesifikasi: "Checkbox menjadi aktif/default sesuai nilai
        // diskon pelanggan") -- HANYA kalau autoTerapkan (lihat
        // catatan di pilihPelanggan()).
        if (autoTerapkan) checkbox.checked = true;
    } else {
        box.style.display = 'none';
        if (autoTerapkan) checkbox.checked = false;
        checkbox.dataset.persen = '';
    }

    if (autoTerapkan) toggleDiskonPelanggan();
}

function toggleDiskonPelanggan() {
    const checkbox = document.getElementById('diskonPelangganCheckbox');
    const diskonInput = document.getElementById('diskonInput');
    const diskonTipe = document.getElementById('diskonTipe');

    if (!diskonInput || !diskonTipe) return;

    const aktif = !!(checkbox && checkbox.checked);

    if (aktif) {
        const persen = parseFloat(checkbox.dataset.persen || '0') || 0;
        diskonInput.value = persen;
        diskonTipe.value = 'percent';
        diskonInput.disabled = true;
        diskonTipe.disabled = true;
    } else {
        diskonInput.value = 0;
        diskonInput.disabled = false;
        diskonTipe.disabled = false;
    }

    hitungDiskon();
}
$(document).on('click', function (e) {
    if (!$(e.target).closest('#namaPelanggan, #listPelanggan').length) {
        document.getElementById('listPelanggan').style.display = 'none';
    }
});

// ================================================================
// 7. BANNER
// ================================================================
function kelolaBanner() {
    const banners = cart.filter(item => item.is_banner === true);

    if (banners.length === 0) {
        showToast('Belum ada banner di keranjang.', 'warning');
        return;
    }

    bannerRows = [];
    bannerRowCounter = 0;
    totalLuasBanner = 0;

    const tableBody = document.getElementById('bannerTableBody');
    if (!tableBody) return;

    tableBody.innerHTML = '';

    const hargaPerM2 = Number(
        banners[0]?.detail?.harga_per_m2 || HARGA_STANDAR || 22000
    );

    document.getElementById('bannerHargaPerM2').value = hargaPerM2;

    banners.forEach((banner) => {
        const detail = banner.detail || {};

        tambahBarisBannerWithData(
            Number(detail.p || 100),
            Number(detail.l || 100),
            Number(detail.qty || banner.jumlah || 1),
            Number(detail.harga_per_m2 || hargaPerM2)
        );
    });

    const modalTitle = document.querySelector('#modalBanner .modal-title');

    if (modalTitle) {
        modalTitle.innerHTML =
            '<i class="fas fa-edit"></i> Kelola Banner';
    }

    const btnSubmit = document.querySelector(
        '#modalBanner .modal-footer .btn-info'
    );

    if (btnSubmit) {
        btnSubmit.innerHTML =
            '<i class="fas fa-save"></i> Simpan Banner';

        btnSubmit.onclick = prosesBanner;
    }

    hitungSemuaBanner();

    $('#modalBanner').modal('show');
}

function buatItemBannerDariModal() {
    const items = [];
    let hasError = false;

    bannerRows.forEach((id) => {
        const p = parseFloat(
            document.getElementById('bannerP_' + id)?.value
        ) || 0;

        const l = parseFloat(
            document.getElementById('bannerL_' + id)?.value
        ) || 0;

        const qty = parseInt(
            document.getElementById('bannerQty_' + id)?.value,
            10
        ) || 1;

        const subtotalText =
            document.getElementById('bannerSubtotal_' + id)?.value || '0';

        const subtotal =
            parseInt(
                subtotalText.replace(/[^0-9]/g, ''),
                10
            ) || 0;

        if (p <= 0 || l <= 0 || qty <= 0 || subtotal <= 0) {
            hasError = true;
            return;
        }

        const luas = (p * l) / 10000;

        items.push({
            id: 'banner_' +
                Date.now() +
                '_' +
                id +
                '_' +
                Math.random().toString(36).slice(2, 7),

            produk_id: 1,
            kategori_id: 4,

            nama: `Banner ${(p / 100).toFixed(1)}mx` +
                `${(l / 100).toFixed(1)}m (${luas.toFixed(2)} m²)`,

            harga: Math.round(subtotal / qty),
            subtotal: subtotal,
            jumlah: qty,
            satuan: 'm²',

            is_banner: true,
            is_custom: false,
            is_manual: false,
            is_edit: false,
            is_cetak: false,

            detail: {
                p,
                l,
                luas,
                qty,
                harga_per_m2: parseInt(
                    document.getElementById('bannerHargaPerM2').value,
                    10
                ) || 22000
            }
        });
    });

    return hasError ? null : items;
}

function prosesBanner() {
    hitungSemuaBanner();

    const items = buatItemBannerDariModal();

    if (!items || items.length === 0) {
        showToast('Tambahkan minimal 1 banner.', 'warning');
        return;
    }

    for (let i = cart.length - 1; i >= 0; i--) {
        if (cart[i].is_banner === true) {
            cart.splice(i, 1);
        }
    }

    cart.push(...items);

    persistCart();
    renderLocalCart();

    $('#modalBanner').modal('hide');

    showToast(
        items.length + ' banner berhasil disimpan ke keranjang.',
        'success'
    );
}

// ---------------------------------------------------------------
// UX kecil: Enter di input qty keranjang tidak submit form apapun
// ---------------------------------------------------------------
$(document).on('keydown', '#keranjangList input[type="number"]', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        this.blur();
    }
});
