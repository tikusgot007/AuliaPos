// Tests for the "dari Inbox" hand-off in public/assets/js/kasir-shared.js
// (AC-12 of docs/requirements/2026-10-18-buat-pesanan-konfirmasi-nama-dan-tab-utama.md).
//
// Run: node --test tests/js/kasir-dari-inbox.test.js
//
// kasir-shared.js boots the whole cashier page, so the two blocks under test
// (the cart priming IIFE and restorePelangganFromUrl) are sliced out of the
// REAL file between stable markers.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/kasir-shared.js'), 'utf8');

function potong(awal, akhir) {
    const a = SOURCE.indexOf(awal);
    const b = SOURCE.indexOf(akhir);
    assert.ok(a !== -1, `penanda awal hilang dari kasir-shared.js: ${awal}`);
    assert.ok(b > a, `penanda akhir hilang dari kasir-shared.js: ${akhir}`);
    return SOURCE.slice(a, b);
}

const BLOK_CART = potong('(function primeCartFromStorage() {', 'window.auliaCart = cart;');
const BLOK_RESTORE = potong('function restorePelangganFromUrl() {', 'function pilihPelanggan(');

const KUNCI = 'aulia_kasir_cart_v1';

/** Simulasi satu kali buka halaman Kasir dengan query tertentu. */
function bukaKasir(opsi = {}) {
    const search = opsi.search === undefined ? '' : opsi.search;
    const tersimpan = opsi.tersimpan === undefined
        ? JSON.stringify([{ id: 5, nama: 'Foto 4R', jumlah: 2 }])
        : opsi.tersimpan;

    const penyimpanan = new Map();
    if (tersimpan !== null) penyimpanan.set(KUNCI, tersimpan);

    const dipanggil = { pilihPelanggan: [], replaceState: [] };

    const ctx = vm.createContext({
        KASIR_CONFIG: { cartStorageKey: opsi.cartStorageKey === undefined ? KUNCI : opsi.cartStorageKey },
        window: {
            location: { search, pathname: '/kasir', hash: '' },
            history: {
                replaceState: (_state, _judul, url) => dipanggil.replaceState.push(url),
            },
        },
        document: { title: 'Kasir', getElementById: () => null },
        sessionStorage: {
            getItem: (k) => (penyimpanan.has(k) ? penyimpanan.get(k) : null),
            setItem: (k, v) => penyimpanan.set(k, v),
            removeItem: (k) => penyimpanan.delete(k),
        },
        URLSearchParams,
        console,
        JSON, Array, Object, String,
    });

    // `cart` dideklarasikan sebelum IIFE di file asli.
    vm.runInContext('const cart = [];', ctx);
    vm.runInContext(BLOK_CART, ctx);

    // restorePelangganFromUrl membutuhkan pilihPelanggan(); tangkap panggilannya.
    ctx.pilihPelanggan = (id, nama, telp, diskon) => {
        dipanggil.pilihPelanggan.push([id, nama, telp, diskon]);
    };
    vm.runInContext(BLOK_RESTORE, ctx);
    vm.runInContext('restorePelangganFromUrl();', ctx);

    return {
        cart: vm.runInContext('cart', ctx),
        dipanggil,
        sisaPenyimpanan: penyimpanan.has(KUNCI) ? penyimpanan.get(KUNCI) : null,
    };
}

// ---------------------------------------------------------------------
// AC-12: dari_inbox=1 membuang keranjang lama
// ---------------------------------------------------------------------

test('K-1 (AC-12): dari_inbox=1 -> keranjang tersimpan dibuang, cart kosong', () => {
    const h = bukaKasir({ search: '?pelanggan_id=9&pelanggan_nama=RULY+7890&dari_inbox=1' });

    assert.equal(h.cart.length, 0, 'keranjang lama tidak boleh ikut');
    assert.equal(h.sisaPenyimpanan, null, 'kunci sessionStorage harus dihapus');
});

test('K-2: tanpa dari_inbox -> keranjang lama tetap dipulihkan (perilaku lama)', () => {
    const h = bukaKasir({ search: '?pelanggan_id=9&pelanggan_nama=RULY+7890' });

    assert.equal(h.cart.length, 1);
    assert.equal(h.cart[0].nama, 'Foto 4R');
    assert.notEqual(h.sisaPenyimpanan, null, 'penyimpanan tidak disentuh');
});

test('K-3: dari_inbox bukan "1" (mis. 0) -> keranjang tetap dipulihkan', () => {
    const h = bukaKasir({ search: '?dari_inbox=0' });

    assert.equal(h.cart.length, 1);
});

test('K-4: kasir/edit (cartStorageKey null) -> IIFE keluar tanpa menyentuh apa pun', () => {
    const h = bukaKasir({ search: '?dari_inbox=1', cartStorageKey: null });

    assert.equal(h.cart.length, 0);
    assert.notEqual(h.sisaPenyimpanan, null, 'kunci bukan milik halaman edit, jangan dihapus');
});

// ---------------------------------------------------------------------
// AC-12: URL dibersihkan setelah dipakai
// ---------------------------------------------------------------------

test('K-5 (AC-12): pelanggan terisi dan dari_inbox dibuang dari URL', () => {
    const h = bukaKasir({ search: '?pelanggan_id=9&pelanggan_nama=RULY+7890&dari_inbox=1' });

    assert.equal(h.dipanggil.pilihPelanggan.length, 1);
    assert.deepEqual(h.dipanggil.pilihPelanggan[0], ['9', 'RULY 7890', '', 0]);
});

test('K-6 (AC-12): parameter pelanggan lengkap -> URL dibersihkan seluruhnya', () => {
    const h = bukaKasir({ search: '?pelanggan_id=9&pelanggan_nama=RULY+7890&dari_inbox=1' });

    assert.equal(h.dipanggil.replaceState.length, 1);
    assert.equal(h.dipanggil.replaceState[0], '/kasir');
});

test('K-7: pelanggan tidak lengkap tetapi dari_inbox ada -> penanda tetap dibuang', () => {
    const h = bukaKasir({ search: '?dari_inbox=1' });

    assert.equal(h.dipanggil.pilihPelanggan.length, 0);
    assert.equal(h.dipanggil.replaceState.length, 1);
    assert.equal(h.dipanggil.replaceState[0], '/kasir');
});

test('K-8: tanpa dari_inbox -> tidak ada replaceState tambahan dari penanda', () => {
    const h = bukaKasir({ search: '?pelanggan_id=9&pelanggan_nama=RULY+7890' });

    // URL tetap dibersihkan setelah pelanggan dipakai (perilaku lama), tetapi
    // penanda dari_inbox tidak ikut muncul lagi.
    assert.equal(h.dipanggil.replaceState.length, 1);
    assert.equal(h.dipanggil.replaceState[0], '/kasir');
});
