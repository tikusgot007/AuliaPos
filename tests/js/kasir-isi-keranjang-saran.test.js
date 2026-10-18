// Tests for isiKeranjangDariSaran() in public/assets/js/kasir-shared.js
// (AC-5..AC-8 of docs/requirements/2026-10-18-isi-keranjang-dari-saran-mara.md).
// Run: node --test tests/js/kasir-isi-keranjang-saran.test.js
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/kasir-shared.js'), 'utf8');
const a = SOURCE.indexOf('function isiKeranjangDariSaran() {');
const b = SOURCE.indexOf('function restorePelangganFromUrl() {');
assert.ok(a !== -1 && b > a, 'penanda isiKeranjangDariSaran / restorePelangganFromUrl hilang');
const BLOK = SOURCE.slice(a, b);

const ITEM = { id: 'saran_5_68', produk_id: 68, kategori_id: 16, nama: 'Dompet', harga: 1100, satuan: 'lembar', jumlah: 4, subtotal: 4400, is_custom: false, is_banner: false, is_manual: false, is_edit: false, is_cetak: true, detail: null };

function jalankan(search, keranjangSaran) {
    const toast = [];
    const ditambah = [];
    const ctx = vm.createContext({
        KASIR_CONFIG: { keranjangSaran },
        window: { location: { search } },
        URLSearchParams, JSON, Array, Number, console,
        tambahItemKeCart: (i) => ditambah.push(i),
        showToast: (pesan, tipe) => toast.push([pesan, tipe]),
    });
    vm.runInContext(BLOK, ctx);
    vm.runInContext('isiKeranjangDariSaran();', ctx);
    return { toast, ditambah };
}

test('S-1: dari_inbox=1 + item -> semua item masuk keranjang', () => {
    const h = jalankan('?dari_inbox=1&saran_id=5', { items: [ITEM, { ...ITEM, produk_id: 62 }], jumlah_gagal: 0 });
    assert.equal(h.ditambah.length, 2);
    assert.equal(h.toast.length, 0);
});

test('S-2: tanpa dari_inbox (refresh) -> tidak mengisi lagi', () => {
    const h = jalankan('?saran_id=5', { items: [ITEM], jumlah_gagal: 0 });
    assert.equal(h.ditambah.length, 0);
});

test('S-3: sebagian gagal -> item sah masuk + toast peringatan jumlah', () => {
    const h = jalankan('?dari_inbox=1&saran_id=5', { items: [ITEM], jumlah_gagal: 2 });
    assert.equal(h.ditambah.length, 1);
    assert.equal(h.toast.length, 1);
    assert.match(h.toast[0][0], /2 item tidak ditemukan/);
    assert.equal(h.toast[0][1], 'warning');
});

test('S-4: semua gagal -> keranjang kosong + toast', () => {
    const h = jalankan('?dari_inbox=1&saran_id=5', { items: [], jumlah_gagal: 3 });
    assert.equal(h.ditambah.length, 0);
    assert.match(h.toast[0][0], /3 item tidak ditemukan/);
});

test('S-5: config tidak ada / bentuk salah -> diam, tidak melempar', () => {
    assert.doesNotThrow(() => jalankan('?dari_inbox=1', undefined));
    assert.doesNotThrow(() => jalankan('?dari_inbox=1', { items: 'x', jumlah_gagal: 'y' }));
    assert.equal(jalankan('?dari_inbox=1', { items: 'x' }).ditambah.length, 0);
});

test('S-6: sumber memanggil isiKeranjangDariSaran sebelum restorePelangganFromUrl', () => {
    const i = SOURCE.split('\r\n').join('\n').indexOf('isiKeranjangDariSaran();\n    restorePelangganFromUrl();');
    assert.ok(i !== -1);
});
