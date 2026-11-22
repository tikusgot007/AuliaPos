// Tests for segarkanPercakapanSetelahAksi() / segarkanSetelahAksi() in
// public/assets/js/inbox-page.js: after a successful user action the open
// conversation is refreshed at once from one request, instead of waiting for
// the slow full list round.
//
// Run: node --test tests/js/inbox-segarkan-aksi.test.js
//
// inbox-page.js cannot be loaded whole (timers, WebSocket, DOM), so the block
// under test is sliced out of the REAL file between two stable markers.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-page.js'), 'utf8');
const START = '// ---- segarkan setelah aksi (mulai) ----';
const END = '// ---- segarkan setelah aksi (selesai) ----';

function blok() {
    const a = SOURCE.indexOf(START);
    const b = SOURCE.indexOf(END);
    assert.ok(a !== -1, `penanda awal hilang dari inbox-page.js: ${START}`);
    assert.ok(b > a, `penanda akhir hilang dari inbox-page.js: ${END}`);
    return SOURCE.slice(a, b);
}

const flush = async () => {
    for (let i = 0; i < 10; i++) await new Promise((resolve) => setImmediate(resolve));
};

function buatHalaman({ aktif = 5, daftar = [{ id: 5, assigned_to: null }, { id: 6, assigned_to: null }] } = {}) {
    const panggilan = [];
    const log = [];
    const ctx = {
        INBOX_URL: { conversations: '/inbox/api/conversations' },
        conversationAktif: aktif,
        salinanConversationAktif: null,
        daftarConversation: daftar,
        renderDaftarConversation() { log.push('daftar'); },
        renderThreadHeader() { log.push('header'); },
        terimaPesanTerbaru(id, json, paksa) { log.push(['thread', id, paksa]); },
        muatUlangDaftarConversation() { log.push('penuh'); },
        fetch(url) {
            return new Promise((resolve, reject) => {
                panggilan.push({
                    url,
                    jawab: (body) => resolve({ json: () => body }),
                    gagal: () => reject(new Error('network')),
                });
            });
        },
    };
    vm.createContext(ctx);
    vm.runInContext(blok(), ctx);
    return { ctx, panggilan, log, jalankan: (kode) => vm.runInContext(kode, ctx) };
}

const sukses = (conv, extra = {}) => ({ status: 'success', conversation: conv, messages: [], timeline: [], has_more: false, ...extra });

test('aksi sukses: satu request ke endpoint pesan, entri daftar diganti, header + thread digambar ulang', async () => {
    const h = buatHalaman();
    h.jalankan('segarkanPercakapanSetelahAksi(5)');

    assert.equal(h.panggilan.length, 1);
    assert.equal(h.panggilan[0].url, '/inbox/api/conversations/5/messages');

    h.panggilan[0].jawab(sukses({ id: 5, assigned_to: 3 }));
    await flush();

    assert.equal(h.jalankan('daftarConversation[0].assigned_to'), 3);
    assert.equal(h.jalankan('daftarConversation[1].assigned_to'), null, 'percakapan lain tidak berubah');
    assert.deepEqual(h.log.slice(0, 2), ['daftar', 'header']);
    assert.deepEqual(JSON.parse(JSON.stringify(h.log[2])), ['thread', 5, false]);
});

test('sla_color (hanya dihitung endpoint daftar) dan match_snippet dipertahankan dari entri lama', async () => {
    const h = buatHalaman({ daftar: [{ id: 5, assigned_to: null, sla_color: 'merah', match_snippet: { text: 'cocok' } }] });
    h.jalankan('segarkanPercakapanSetelahAksi(5)');
    h.panggilan[0].jawab(sukses({ id: 5, assigned_to: 3 }));
    await flush();

    assert.equal(h.jalankan('daftarConversation[0].assigned_to'), 3, 'data baru dipakai');
    assert.equal(h.jalankan('daftarConversation[0].sla_color'), 'merah', 'titik SLA tidak hilang');
    assert.equal(h.jalankan('daftarConversation[0].match_snippet.text'), 'cocok');
});

test('percakapan sudah pindah saat respons datang: daftar diperbarui, thread yang sedang dibuka tidak ditimpa', async () => {
    const h = buatHalaman();
    h.jalankan('segarkanPercakapanSetelahAksi(5)');
    h.ctx.conversationAktif = 6;
    h.panggilan[0].jawab(sukses({ id: 5, assigned_to: 3 }));
    await flush();

    assert.equal(h.jalankan('daftarConversation[0].assigned_to'), 3);
    assert.ok(!h.log.some((x) => Array.isArray(x) && x[0] === 'thread'), 'thread percakapan lain tidak boleh digambar ulang');
});

test('percakapan yang tidak ada di daftar (hasil pencarian) tetap dipakai header lewat salinan', async () => {
    const h = buatHalaman({ aktif: 9, daftar: [{ id: 5 }] });
    h.jalankan('segarkanPercakapanSetelahAksi(9)');
    h.panggilan[0].jawab(sukses({ id: 9, assigned_to: 4 }));
    await flush();

    assert.equal(h.jalankan('daftarConversation.length'), 1, 'tidak menambah baris ke daftar');
    assert.equal(h.jalankan('salinanConversationAktif.assigned_to'), 4);
});

test('respons error atau jaringan gagal: tidak melempar, daftar lama utuh', async () => {
    const h = buatHalaman();
    const hasil1 = h.jalankan('segarkanPercakapanSetelahAksi(5)');
    h.panggilan[0].jawab({ status: 'error', message: 'x' });
    assert.equal(await hasil1, false);

    const hasil2 = h.jalankan('segarkanPercakapanSetelahAksi(5)');
    h.panggilan[1].gagal();
    assert.equal(await hasil2, false);
    assert.equal(h.jalankan('daftarConversation[0].assigned_to'), null);
});

test('segarkanSetelahAksi: segar dulu, lalu rekonsiliasi daftar penuh', async () => {
    const h = buatHalaman();
    h.jalankan('segarkanSetelahAksi(5)');
    h.panggilan[0].jawab(sukses({ id: 5, assigned_to: 3 }));
    await flush();

    assert.equal(h.log.indexOf('penuh'), h.log.length - 1, 'daftar penuh jalan terakhir');
    assert.ok(h.log.indexOf('header') < h.log.indexOf('penuh'));
});

test('segarkanSetelahAksi tetap menjalankan daftar penuh walau segar gagal', async () => {
    const h = buatHalaman();
    h.jalankan('segarkanSetelahAksi(5)');
    h.panggilan[0].gagal();
    await flush();
    assert.ok(h.log.includes('penuh'));
});

test('tanpa percakapan aktif dan tanpa id: tidak ada request', async () => {
    const h = buatHalaman({ aktif: null });
    const hasil = await h.jalankan('segarkanPercakapanSetelahAksi()');
    assert.equal(hasil, false);
    assert.equal(h.panggilan.length, 0);
});

// ---- handler memakai segarkan, bukan lagi muat penuh langsung ----------
function badanFungsi(nama) {
    const a = SOURCE.indexOf(`function ${nama}(`);
    assert.ok(a !== -1, `fungsi ${nama} tidak ditemukan`);
    const b = SOURCE.indexOf('\nfunction ', a + 10);
    return SOURCE.slice(a, b === -1 ? undefined : b);
}

for (const nama of ['ambilPercakapan', 'lepasPercakapan', 'tandaiDibacaAktif', 'snoozePercakapanAktif', 'kirimHandoff', 'nudgeAmbilSekarang']) {
    test(`${nama}: setelah sukses memanggil segarkanSetelahAksi`, () => {
        const badan = badanFungsi(nama);
        assert.match(badan, /segarkanSetelahAksi\(/);
        assert.ok(!/\bmuatUlangDaftarConversation\(\);/.test(badan), `${nama} tidak boleh lagi hanya memuat daftar penuh`);
    });
}
