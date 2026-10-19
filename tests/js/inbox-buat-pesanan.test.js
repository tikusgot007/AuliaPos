// Tests for buatPesanan() (Inbox -> Kasir) in public/assets/js/inbox-page.js.
//
// Maps docs/requirements/2026-10-18-buat-pesanan-konfirmasi-nama-dan-tab-utama.md
// AC-3 (Batal), AC-8..AC-12. Perilaku lama yang tidak berubah (CSRF, klik
// ganda, 403) tetap diuji di sini karena kode yang sama dipakai kedua fase.
//
// Run: node --test tests/js/inbox-buat-pesanan.test.js
//
// inbox-page.js cannot be loaded whole: it boots timers, a WebSocket and the
// whole page. The block under test is therefore sliced out of the REAL file
// between two stable markers (not copied), and the test fails loudly if either
// marker disappears.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-page.js'), 'utf8');
const START = 'let buatPesananBerjalan = false;';
const END = '// Menit dari sekarang sampai jam 08:00';

function blokBuatPesanan() {
    const a = SOURCE.indexOf(START);
    const b = SOURCE.indexOf(END);
    assert.ok(a !== -1, `penanda awal hilang dari inbox-page.js: ${START}`);
    assert.ok(b > a, `penanda akhir hilang dari inbox-page.js: ${END}`);
    return SOURCE.slice(a, b);
}

const flush = () => new Promise((resolve) => setImmediate(resolve));
const flush2 = async () => { await flush(); await flush(); await flush(); };

const CSRF_AWAL = { header: 'X-CSRF-TOKEN', token: 'tok-awal' };

/** Fake opener: tab utama POS (halaman yang membuka window Inbox). */
function fakeOpener(opsi = {}) {
    return {
        closed: false,
        location: { pathname: opsi.pathname === undefined ? '/kasir' : opsi.pathname, href: '' },
        auliaCart: opsi.cart === undefined ? [] : opsi.cart,
        fokus: 0,
        focus() { this.fokus++; },
    };
}

const SUKSES = {
    status: 'success', pelanggan_id: 7, pelanggan_baru: false,
    redirect_url: 'http://x/kasir?pelanggan_id=7&pelanggan_nama=BUDI+7890&pelanggan_telp=0812&pelanggan_diskon=0&dari_inbox=1',
    csrf: 'tok-baru',
};

const BUTUH_NAMA = {
    status: 'butuh_nama', pelanggan_id: null, nama_usulan: 'RULYAPPING', digit: '7890', csrf: 'tok-nama',
};

function load(opsi = {}) {
    // 'csrf' in opsi: an explicit undefined must reach the page config unchanged
    // (default-parameter syntax would silently replace it).
    const csrf = 'csrf' in opsi ? opsi.csrf : Object.assign({}, CSRF_AWAL);
    const opener = 'opener' in opsi ? opsi.opener : fakeOpener();
    const conversationAktif = 'conversationAktif' in opsi ? opsi.conversationAktif : 42;
    const calls = { fetch: [], toasts: [], render: 0, open: 0, modalShow: [], modalHide: [] };
    const tabBaru = { closed: false, opener: 'belum-diputus', location: { href: '' } };
    const elemen = Object.assign({}, opsi.elemen);
    let fetchImpl = () => Promise.reject(new Error('fetch tidak di-mock'));

    const ctx = vm.createContext({
        INBOX_PAGE_CONFIG: { csrf },
        INBOX_URL: { percakapan: '/inbox/percakapan/' },
        conversationAktif,
        renderThreadHeader: () => { calls.render++; },
        showToast: (teks, jenis) => calls.toasts.push([teks, jenis]),
        fetch: (url, opts) => { calls.fetch.push({ url, opts }); return fetchImpl(url, opts); },
        document: {
            getElementById: (id) => (elemen[id] === undefined ? null : elemen[id]),
        },
        bootstrap: {
            Modal: {
                getOrCreateInstance: (el) => ({
                    show: () => calls.modalShow.push(el.id),
                    hide: () => calls.modalHide.push(el.id),
                }),
            },
        },
        setTimeout: (fn) => fn(),
        window: {
            opener,
            open: (url) => {
                calls.open++;
                tabBaru.location.href = url;
                return opsi.popup === 'diblokir' ? null : tabBaru;
            },
            location: { href: 'http://halaman-inbox/' },
            close: () => { calls.windowClose = (calls.windowClose || 0) + 1; },
        },
        Promise, Object, String, Error, Array, console,
    });

    vm.runInContext(blokBuatPesanan(), ctx);

    return {
        ctx, calls, opener, tabBaru, elemen,
        setFetch: (fn) => { fetchImpl = fn; },
        berjalan: () => vm.runInContext('buatPesananBerjalan', ctx),
        token: () => vm.runInContext('CSRF_INBOX.token', ctx),
        tertunda: () => vm.runInContext('buatPesananTertunda', ctx),
        namaTertunda: () => vm.runInContext('namaPelangganTertunda', ctx),
    };
}

const jawab = (status, body) => () => Promise.resolve({
    status,
    json: () => (body === undefined ? Promise.reject(new SyntaxError('bukan JSON')) : Promise.resolve(body)),
});

/** Mock fetch yang menjawab berbeda untuk fase 1 dan fase 2. */
function jawabDuaFase(fase1, fase2) {
    let n = 0;
    return () => {
        const body = n++ === 0 ? fase1 : fase2;
        return Promise.resolve({ status: 200, json: () => Promise.resolve(body) });
    };
}

// ---------------------------------------------------------------------
// Fase 1 / fase 2
// ---------------------------------------------------------------------

test('N-1: fase 1 mengirim POST tanpa body nama', async () => {
    const t = load();
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.fetch.length, 1);
    const { url, opts } = t.calls.fetch[0];
    assert.equal(url, '/inbox/percakapan/42/buat-pesanan');
    assert.equal(opts.method, 'POST');
    assert.equal(opts.headers['X-CSRF-TOKEN'], 'tok-awal');
    assert.equal(opts.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(opts.body, undefined, 'fase 1 tidak boleh mengirim nama');
});

test('N-2: butuh_nama membuka modal nama dengan saran + digit terkunci', async () => {
    const t = load({
        elemen: {
            namaPelangganBaru: { value: '', focus() {}, select() {} },
            digitPelangganBaru: { value: '' },
            modalNamaPelanggan: { id: 'modalNamaPelanggan' },
        },
    });
    t.setFetch(jawab(200, BUTUH_NAMA));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.elemen.namaPelangganBaru.value, 'RULYAPPING');
    assert.equal(t.elemen.digitPelangganBaru.value, '7890');
    assert.deepEqual(t.calls.modalShow, ['modalNamaPelanggan']);
    assert.equal(t.token(), 'tok-nama');
    assert.equal(t.calls.open, 0, 'tidak boleh buka tab sebelum nama disimpan');
});

test('N-3 (AC-3): simpan mengirim nama ke fase 2 lalu mengarahkan opener', async () => {
    const t = load({
        elemen: {
            namaPelangganBaru: { value: 'Ruly', focus() {}, select() {} },
            digitPelangganBaru: { value: '7890' },
            modalNamaPelanggan: { id: 'modalNamaPelanggan' },
        },
    });
    t.setFetch(jawabDuaFase(BUTUH_NAMA, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    // Kasir menimpa saran dengan nama yang benar, lalu menyimpan.
    t.elemen.namaPelangganBaru.value = 'Ruly';
    t.ctx.simpanNamaPelanggan();
    await flush2();

    assert.equal(t.calls.fetch.length, 2);
    const fase2 = t.calls.fetch[1];
    assert.equal(fase2.opts.body, 'nama=Ruly');
    assert.match(fase2.opts.headers['Content-Type'], /x-www-form-urlencoded/);
    assert.equal(fase2.opts.headers['X-CSRF-TOKEN'], 'tok-nama', 'fase 2 memakai token dari fase 1');

    assert.equal(t.opener.location.href, SUKSES.redirect_url);
    assert.equal(t.opener.fokus, 1, 'tab utama difokuskan');
    assert.equal(t.calls.windowClose, 1, 'window Inbox ditutup supaya Kasir otomatis terfokus');
    assert.equal(t.calls.open, 0, 'tidak ada tab baru');
    assert.deepEqual(t.calls.modalHide, ['modalNamaPelanggan']);
});

test('N-4 (AC-3): Batal menutup modal tanpa request kedua', async () => {
    const t = load({
        elemen: {
            namaPelangganBaru: { value: '', focus() {}, select() {} },
            digitPelangganBaru: { value: '' },
            modalNamaPelanggan: { id: 'modalNamaPelanggan' },
        },
    });
    t.setFetch(jawab(200, BUTUH_NAMA));

    t.ctx.buatPesanan();
    await flush2();

    t.ctx.batalNamaPelanggan();
    await flush2();

    assert.equal(t.calls.fetch.length, 1, 'Batal tidak boleh menulis apa pun');
    assert.deepEqual(t.calls.modalHide, ['modalNamaPelanggan']);
    assert.equal(t.namaTertunda(), null);
});

test('N-5: nama < 2 karakter ditolak di browser tanpa request', async () => {
    const t = load({
        elemen: {
            namaPelangganBaru: { value: ' A ', focus() {}, select() {} },
            digitPelangganBaru: { value: '7890' },
            modalNamaPelanggan: { id: 'modalNamaPelanggan' },
        },
    });
    t.setFetch(jawabDuaFase(BUTUH_NAMA, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    t.elemen.namaPelangganBaru.value = ' A ';
    t.ctx.simpanNamaPelanggan();
    await flush2();

    assert.equal(t.calls.fetch.length, 1, 'tidak ada POST fase 2');
    assert.equal(t.calls.toasts.length, 1);
    assert.equal(t.calls.toasts[0][1], 'warning');
});

test('N-6: 409 dari fase 2 tidak menutup modal (kasir bisa memperbaiki)', async () => {
    const t = load({
        elemen: {
            namaPelangganBaru: { value: 'Ruly', focus() {}, select() {} },
            digitPelangganBaru: { value: '7890' },
            modalNamaPelanggan: { id: 'modalNamaPelanggan' },
        },
    });
    t.setFetch(jawabDuaFase(BUTUH_NAMA, {
        status: 'error', message: 'Sudah ada pelanggan bernama "RULY 7890" dengan nomor berbeda.', csrf: 'tok-409',
    }));

    t.ctx.buatPesanan();
    await flush2();

    t.elemen.namaPelangganBaru.value = 'Ruly';
    t.ctx.simpanNamaPelanggan();
    await flush2();

    assert.deepEqual(t.calls.modalHide, [], 'modal tetap terbuka');
    assert.equal(t.calls.toasts.length, 1);
    assert.match(t.calls.toasts[0][0], /Sudah ada pelanggan/);
    assert.equal(t.token(), 'tok-409');
    assert.equal(t.opener.location.href, '');
});

// ---------------------------------------------------------------------
// AC-8 / AC-10: pengarahan tab utama
// ---------------------------------------------------------------------

test('N-7 (AC-8): opener ada dan keranjang kosong -> langsung diarahkan, tanpa konfirmasi', async () => {
    const t = load();
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.opener.location.href, SUKSES.redirect_url);
    assert.equal(t.opener.fokus, 1);
    assert.equal(t.calls.open, 0);
    assert.deepEqual(t.calls.modalShow, [], 'tidak ada konfirmasi');
});

test('N-8 (AC-10): opener di halaman non-Kasir -> tetap diarahkan ke Kasir', async () => {
    const t = load({ opener: fakeOpener({ pathname: '/laporan' }) });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.opener.location.href, SUKSES.redirect_url);
});

// ---------------------------------------------------------------------
// AC-9: konfirmasi saat ada pekerjaan yang akan hilang
// ---------------------------------------------------------------------

test('N-9 (AC-9): keranjang berisi -> konfirmasi, belum ada request', async () => {
    const t = load({
        opener: fakeOpener({ cart: [{ id: 1 }, { id: 2 }, { id: 3 }] }),
        elemen: {
            pesanGantiKasir: { textContent: '' },
            modalGantiKasir: { id: 'modalGantiKasir' },
        },
    });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.fetch.length, 0, 'tidak ada request sebelum kasir memilih');
    assert.deepEqual(t.calls.modalShow, ['modalGantiKasir']);
    assert.match(t.elemen.pesanGantiKasir.textContent, /3 item/);
    assert.equal(t.tertunda().jumlah, 3);
});

test('N-10 (AC-9): Batal pada konfirmasi -> nol request, nol perubahan', async () => {
    const t = load({
        opener: fakeOpener({ cart: [{ id: 1 }] }),
        elemen: {
            pesanGantiKasir: { textContent: '' },
            modalGantiKasir: { id: 'modalGantiKasir' },
        },
    });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    t.ctx.batalGantiKasir();
    await flush2();

    assert.equal(t.calls.fetch.length, 0);
    assert.equal(t.tertunda(), null);
    assert.equal(t.opener.location.href, '');
    assert.deepEqual(t.calls.modalHide, ['modalGantiKasir']);
});

test('N-11 (AC-9): Ganti -> request jalan dan opener diarahkan', async () => {
    const t = load({
        opener: fakeOpener({ cart: [{ id: 1 }] }),
        elemen: {
            pesanGantiKasir: { textContent: '' },
            modalGantiKasir: { id: 'modalGantiKasir' },
        },
    });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    t.ctx.lanjutkanGantiKasir();
    await flush2();

    assert.equal(t.calls.fetch.length, 1);
    assert.equal(t.opener.location.href, SUKSES.redirect_url);
});

test('N-12 (AC-9): tab utama di kasir/edit -> SELALU konfirmasi walau keranjang kosong', async () => {
    const t = load({
        opener: fakeOpener({ pathname: '/kasir/edit/12', cart: [] }),
        elemen: {
            pesanGantiKasir: { textContent: '' },
            modalGantiKasir: { id: 'modalGantiKasir' },
        },
    });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.fetch.length, 0);
    assert.deepEqual(t.calls.modalShow, ['modalGantiKasir']);
    assert.match(t.elemen.pesanGantiKasir.textContent, /mengedit transaksi/);
});

// ---------------------------------------------------------------------
// AC-11: tanpa opener -> tab baru
// ---------------------------------------------------------------------

test('N-13 (AC-11): opener null -> tab baru dibuka', async () => {
    const t = load({ opener: null });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.open, 1);
    assert.equal(t.tabBaru.location.href, SUKSES.redirect_url);
    assert.equal(t.tabBaru.opener, null, 'tab baru diputus dari opener');
});

test('N-14 (AC-11): opener sudah ditutup -> tab baru', async () => {
    const t = load({ opener: { closed: true, location: { pathname: '/kasir' }, focus() {} } });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.open, 1);
    assert.equal(t.tabBaru.location.href, SUKSES.redirect_url);
});

test('N-15 (AC-11): opener beda origin (akses melempar) -> tab baru, tidak crash', async () => {
    const bedaOrigin = {
        closed: false,
        focus() {},
        get location() { throw new Error('SecurityError'); },
    };
    const t = load({ opener: bedaOrigin });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.open, 1);
    assert.equal(t.tabBaru.location.href, SUKSES.redirect_url);
});

test('N-16: tab baru diblokir popup blocker -> halaman Inbox sendiri yang pindah', async () => {
    const t = load({ opener: null, popup: 'diblokir' });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.ctx.window.location.href, SUKSES.redirect_url);
});

// ---------------------------------------------------------------------
// Perilaku lama yang harus tetap sama
// ---------------------------------------------------------------------

test('N-17: klik kedua saat request berjalan diabaikan; flag reset setelah selesai', async () => {
    const t = load();
    let lepas;
    t.setFetch(() => new Promise((resolve) => {
        lepas = () => resolve({ status: 200, json: () => Promise.resolve(SUKSES) });
    }));

    t.ctx.buatPesanan();
    await flush2();
    assert.equal(t.berjalan(), true);

    t.ctx.buatPesanan();
    await flush2();
    assert.equal(t.calls.fetch.length, 1);

    lepas();
    await flush2();
    assert.equal(t.berjalan(), false);
});

test('N-18: token CSRF diperbarui dari setiap balasan dan dipakai klik berikutnya', async () => {
    const t = load();
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.token(), 'tok-baru');

    t.ctx.buatPesanan();
    await flush2();
    assert.equal(t.calls.fetch[1].opts.headers['X-CSRF-TOKEN'], 'tok-baru');
});

test('N-19: 403 tanpa body JSON -> pesan muat ulang, tanpa navigasi', async () => {
    const t = load();
    t.setFetch(jawab(403));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.toasts.length, 1);
    assert.match(t.calls.toasts[0][0], /Muat ulang halaman Inbox/);
    assert.equal(t.opener.location.href, '');
    assert.equal(t.calls.open, 0);
});

test('N-20: 403 ber-JSON (ownership) memakai pesan server', async () => {
    const t = load();
    t.setFetch(jawab(403, { status: 'error', message: 'Percakapan ini dipegang kasir lain.' }));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.toasts[0][0], 'Percakapan ini dipegang kasir lain.');
});

test('N-21: gagal jaringan -> toast danger, tidak ada navigasi', async () => {
    const t = load();
    t.setFetch(() => Promise.reject(new Error('offline')));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.toasts.length, 1);
    assert.equal(t.calls.toasts[0][1], 'danger');
    assert.equal(t.opener.location.href, '');
});

test('N-22: pelanggan baru -> toast info sebelum diarahkan', async () => {
    const t = load();
    t.setFetch(jawab(200, Object.assign({}, SUKSES, { pelanggan_baru: true })));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.toasts.length, 1);
    assert.match(t.calls.toasts[0][0], /Pelanggan baru/);
    assert.equal(t.opener.location.href, SUKSES.redirect_url);
});

test('N-23: conversationId eksplisit menang; tanpa percakapan -> tidak ada request', async () => {
    const kosong = load({ conversationAktif: null });
    kosong.setFetch(jawab(200, SUKSES));
    kosong.ctx.buatPesanan();
    await flush2();
    assert.equal(kosong.calls.fetch.length, 0);
    assert.equal(kosong.calls.open, 0);

    const t = load({ conversationAktif: 42 });
    t.setFetch(jawab(200, SUKSES));
    t.ctx.buatPesanan(99);
    await flush2();
    assert.equal(t.calls.fetch[0].url, '/inbox/percakapan/99/buat-pesanan');
});

test('N-24: success tanpa redirect_url dianggap gagal (bukan navigasi kosong)', async () => {
    const t = load();
    t.setFetch(jawab(200, { status: 'success', pelanggan_id: 7, csrf: 'tok-baru' }));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.calls.toasts.length, 1);
    assert.equal(t.calls.toasts[0][1], 'danger');
    assert.equal(t.opener.location.href, '');
});

test('N-25: INBOX_PAGE_CONFIG.csrf hilang -> default aman, tidak melempar', () => {
    const t = load({ csrf: undefined });
    assert.equal(t.token(), '');
});

test('N-26: butuh_nama tanpa digit tetap membuka modal (digit dikosongkan)', async () => {
    const t = load({
        elemen: {
            namaPelangganBaru: { value: '', focus() {}, select() {} },
            digitPelangganBaru: { value: 'lama' },
            modalNamaPelanggan: { id: 'modalNamaPelanggan' },
        },
    });
    t.setFetch(jawab(200, Object.assign({}, BUTUH_NAMA, { digit: '' })));

    t.ctx.buatPesanan();
    await flush2();

    assert.equal(t.elemen.digitPelangganBaru.value, '');
    assert.deepEqual(t.calls.modalShow, ['modalNamaPelanggan']);
});

// ---- Tautkan ke pelanggan lama tanpa nomor (docs/requirements/2026-10-19-...) ----
const BUTUH_TAUTAN = {
    status: 'butuh_tautan', kandidat: { id: 11, nama: 'SOLEHA' },
    nama_usulan: 'SOLEHA', digit: '7890', csrf: 'tok-taut',
};

test('T-1: butuh_tautan membuka modal tautan, belum ada tab dibuka', async () => {
    const t = load({ elemen: { pesanTautPelanggan: { textContent: '' }, modalTautPelanggan: { id: 'modalTautPelanggan' } } });
    t.setFetch(jawab(200, BUTUH_TAUTAN));

    t.ctx.buatPesanan();
    await flush2();

    assert.deepEqual(t.calls.modalShow, ['modalTautPelanggan']);
    assert.match(t.elemen.pesanTautPelanggan.textContent, /SOLEHA/);
    assert.equal(t.calls.open, 0);
});

test('T-2: "Ya, orang yang sama" kirim tautkan_id + nama kandidat lalu buka Kasir', async () => {
    const t = load({ elemen: { pesanTautPelanggan: { textContent: '' }, modalTautPelanggan: { id: 'modalTautPelanggan' } } });
    t.setFetch(jawabDuaFase(BUTUH_TAUTAN, SUKSES));

    t.ctx.buatPesanan();
    await flush2();
    t.ctx.tautkanPelangganLama();
    await flush2();

    const fase2 = t.calls.fetch[1];
    assert.match(fase2.opts.body, /nama=SOLEHA/);
    assert.match(fase2.opts.body, /tautkan_id=11/);
    assert.equal(t.opener.location.href, SUKSES.redirect_url);
    assert.ok(t.calls.modalHide.includes('modalTautPelanggan'));
});

test('T-3: "Bukan, pelanggan baru" pindah ke modal nama, tanpa tautkan_id', async () => {
    const t = load({
        elemen: {
            pesanTautPelanggan: { textContent: '' }, modalTautPelanggan: { id: 'modalTautPelanggan' },
            namaPelangganBaru: { value: '', focus() {}, select() {} }, digitPelangganBaru: { value: '' },
            modalNamaPelanggan: { id: 'modalNamaPelanggan' },
        },
    });
    t.setFetch(jawabDuaFase(BUTUH_TAUTAN, SUKSES));

    t.ctx.buatPesanan();
    await flush2();
    t.ctx.tautPelangganBaru();
    await flush2();

    assert.ok(t.calls.modalShow.includes('modalNamaPelanggan'));
    assert.equal(t.calls.fetch.length, 1, 'belum ada request kedua');
    t.elemen.namaPelangganBaru.value = 'Soleha';
    t.ctx.simpanNamaPelanggan();
    await flush2();
    assert.doesNotMatch(t.calls.fetch[1].opts.body, /tautkan_id/);
});
