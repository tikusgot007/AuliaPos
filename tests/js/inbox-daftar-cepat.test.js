// Tests for muatUlangDaftarCepat() in public/assets/js/inbox-page.js: the
// one-request list refresh used by message-driven triggers (message.created,
// reply sent) instead of a full 25-page round.
//
// Run: node --test tests/js/inbox-daftar-cepat.test.js
//
// inbox-page.js cannot be loaded whole (timers, WebSocket, DOM), so the block
// under test is sliced out of the REAL file between two stable markers.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-page.js'), 'utf8');
const START = 'const CONVERSATIONS_PER_PAGE = 50;';
const END = '// Whitespace-only counts as empty';

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

const baris = (id, tanda) => ({ id, tanda: tanda || 'lama' });
const deret = (dari, jumlah, tanda) => Array.from({ length: jumlah }, (_, i) => baris(dari + i, tanda));

/**
 * Builds the page context. Every fetch is recorded and stays pending until
 * the test resolves it, so interleavings can be driven explicitly.
 */
function buatHalaman(daftarAwal) {
    const panggilan = [];
    const ctx = {
        INBOX_URL: { conversations: '/inbox/api/conversations' },
        render: 0,
        renderDaftarConversation() { ctx.render++; },
        renderThreadHeader() {},
        fetch(url) {
            return new Promise((resolve, reject) => {
                panggilan.push({
                    url,
                    jawab: (conversations) => resolve({ json: () => ({ status: 'success', conversations }) }),
                    gagal: () => reject(new Error('network')),
                });
            });
        },
        encodeURIComponent,
    };
    vm.createContext(ctx);
    vm.runInContext('let daftarConversation = ' + JSON.stringify(daftarAwal) + ';\n' + blok(), ctx);

    return {
        panggilan,
        ctx,
        jalankan: (kode) => vm.runInContext(kode, ctx),
        daftar: () => JSON.parse(vm.runInContext('JSON.stringify(daftarConversation)', ctx)),
    };
}

test('satu request page=1; hasilnya digabung, baris lama di luar halaman 1 dipertahankan', async () => {
    const h = buatHalaman(deret(1, 120));
    h.jalankan('muatUlangDaftarCepat()');

    assert.equal(h.panggilan.length, 1);
    assert.equal(h.panggilan[0].url, '/inbox/api/conversations?page=1');

    // Conversation 999 is new, 70 moved to the top with fresh data.
    const halaman = [baris(999, 'baru'), baris(70, 'baru')].concat(deret(1, 48, 'baru'));
    h.panggilan[0].jawab(halaman);
    await flush();

    const daftar = h.daftar();
    assert.equal(h.panggilan.length, 1, 'tidak boleh lanjut ke page=2');
    assert.equal(daftar.length, 121);
    assert.deepEqual(daftar.slice(0, 2), [baris(999, 'baru'), baris(70, 'baru')]);
    assert.equal(daftar.filter((c) => c.id === 70).length, 1, 'tidak boleh dobel');
    assert.equal(daftar.find((c) => c.id === 100).tanda, 'lama');
    assert.equal(h.ctx.render, 1);
});

test('halaman 1 yang tidak penuh adalah daftar lengkap: baris yang hilang ikut dibuang', async () => {
    const h = buatHalaman(deret(1, 5));
    h.jalankan('muatUlangDaftarCepat()');
    h.panggilan[0].jawab([baris(2, 'baru'), baris(1, 'baru')]);
    await flush();

    assert.deepEqual(h.daftar(), [baris(2, 'baru'), baris(1, 'baru')]);
});

test('pencarian aktif: jatuh ke muat penuh dengan q, tanpa gabung', async () => {
    const h = buatHalaman(deret(1, 3));
    h.jalankan('kataKunciAktif = "budi"; muatUlangDaftarCepat()');

    assert.equal(h.panggilan.length, 1);
    assert.equal(h.panggilan[0].url, '/inbox/api/conversations?page=1&q=budi');
    assert.equal(h.jalankan('putaranDaftarBerjalan'), true);
});

test('muat penuh sedang berjalan: ditunda, lalu dijalankan setelah putaran selesai', async () => {
    const h = buatHalaman(deret(1, 3));
    h.jalankan('muatUlangDaftarConversation()');
    h.jalankan('muatUlangDaftarCepat(); muatUlangDaftarCepat()');
    assert.equal(h.panggilan.length, 1, 'refresh cepat tidak boleh jalan bersamaan dengan putaran penuh');

    h.panggilan[0].jawab([baris(1, 'penuh')]);
    await flush();

    assert.equal(h.panggilan.length, 2, 'tepat satu refresh cepat susulan');
    assert.equal(h.panggilan[1].url, '/inbox/api/conversations?page=1');

    h.panggilan[1].jawab([baris(7, 'cepat'), baris(1, 'cepat')]);
    await flush();
    assert.deepEqual(h.daftar(), [baris(7, 'cepat'), baris(1, 'cepat')]);
    assert.equal(h.panggilan.length, 2);
});

test('pemicu beruntun saat refresh cepat berjalan: digabung jadi satu susulan', async () => {
    const h = buatHalaman(deret(1, 3));
    h.jalankan('muatUlangDaftarCepat(); muatUlangDaftarCepat(); muatUlangDaftarCepat()');
    assert.equal(h.panggilan.length, 1);

    h.panggilan[0].jawab([baris(1, 'a')]);
    await flush();
    assert.equal(h.panggilan.length, 2);

    h.panggilan[1].jawab([baris(1, 'b')]);
    await flush();
    assert.equal(h.panggilan.length, 2);
    assert.deepEqual(h.daftar(), [baris(1, 'b')]);
});

test('hasil basi dibuang bila putaran penuh mulai setelah refresh cepat', async () => {
    const h = buatHalaman(deret(1, 3));
    h.jalankan('muatUlangDaftarCepat()');
    h.jalankan('muatUlangDaftarConversation()');
    assert.equal(h.panggilan.length, 2);

    h.panggilan[1].jawab([baris(1, 'penuh')]);
    await flush();
    h.panggilan[0].jawab([baris(1, 'cepat-basi')]);
    await flush();

    assert.deepEqual(h.daftar(), [baris(1, 'penuh')]);
});

test('request gagal: daftar lama dipakai dan refresh berikutnya tetap bisa jalan', async () => {
    const h = buatHalaman(deret(1, 3));
    h.jalankan('muatUlangDaftarCepat()');
    h.panggilan[0].gagal();
    await flush();

    assert.deepEqual(h.daftar(), deret(1, 3));
    assert.equal(h.ctx.render, 0);

    h.jalankan('muatUlangDaftarCepat()');
    assert.equal(h.panggilan.length, 2);
});
