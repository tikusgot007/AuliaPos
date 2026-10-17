// Tests for buatPesanan() / CSRF handling in public/assets/js/inbox-page.js
// (Inbox -> Kasir, AC-8/AC-9/AC-10 of docs/requirements/2026-10-16-inbox-ke-kasir.md).
// Run: node tests/js/inbox-buat-pesanan.test.js (Node.js built-in test runner)
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

function bloqBuatPesanan() {
    const a = SOURCE.indexOf(START);
    const b = SOURCE.indexOf(END);
    assert.ok(a !== -1, `penanda awal hilang dari inbox-page.js: ${START}`);
    assert.ok(b > a, `penanda akhir hilang dari inbox-page.js: ${END}`);
    return SOURCE.slice(a, b);
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

function fakeTab() {
    return {
        closed: false,
        opener: 'belum-diputus',
        location: { href: '' },
        close() { this.closed = true; },
    };
}

const CSRF_AWAL = { header: 'X-CSRF-TOKEN', token: 'tok-awal' };

function load(opsi = {}) {
    // 'csrf' in opsi: an explicit undefined must reach the page config unchanged
    // (default-parameter syntax would silently replace it).
    const csrf = 'csrf' in opsi ? opsi.csrf : Object.assign({}, CSRF_AWAL);
    const popup = opsi.popup === undefined ? 'ok' : opsi.popup;
    const conversationAktif = 'conversationAktif' in opsi ? opsi.conversationAktif : 42;
    const calls = { fetch: [], toasts: [], render: 0, open: 0 };
    const tab = fakeTab();
    let fetchImpl = () => Promise.reject(new Error('fetch tidak di-mock'));

    const ctx = vm.createContext({
        INBOX_PAGE_CONFIG: { csrf },
        INBOX_URL: { percakapan: '/inbox/percakapan/' },
        conversationAktif,
        renderThreadHeader: () => { calls.render++; },
        showToast: (teks, jenis) => calls.toasts.push([teks, jenis]),
        fetch: (url, opts) => { calls.fetch.push({ url, opts }); return fetchImpl(url, opts); },
        window: {
            open: () => { calls.open++; return popup === 'ok' ? tab : null; },
            location: { href: 'http://halaman-inbox/' },
        },
        Promise, Object, String, Error, console,
    });

    vm.runInContext(bloqBuatPesanan(), ctx);

    return {
        ctx, calls, tab,
        setFetch: (fn) => { fetchImpl = fn; },
        berjalan: () => vm.runInContext('buatPesananBerjalan', ctx),
        token: () => vm.runInContext('CSRF_INBOX.token', ctx),
    };
}

const jawab = (status, body) => () => Promise.resolve({
    status,
    json: () => (body === undefined ? Promise.reject(new SyntaxError('bukan JSON')) : Promise.resolve(body)),
});

const SUKSES = {
    status: 'success', pelanggan_id: 7, pelanggan_baru: false,
    redirect_url: 'http://x/kasir?pelanggan_id=7&pelanggan_nama=Budi&pelanggan_telp=0812&pelanggan_diskon=0',
    csrf: 'tok-baru',
};

test('B-1 (AC-10): POST goes to buat-pesanan with CSRF + XHR headers', async () => {
    const t = load();
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush();

    assert.equal(t.calls.fetch.length, 1);
    const { url, opts } = t.calls.fetch[0];
    assert.equal(url, '/inbox/percakapan/42/buat-pesanan');
    assert.equal(opts.method, 'POST');
    assert.equal(opts.headers['X-CSRF-TOKEN'], 'tok-awal');
    assert.equal(opts.headers['X-Requested-With'], 'XMLHttpRequest');
});

test('B-2 (AC-8): success fills the already-opened tab and keeps this page', async () => {
    const t = load();
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush();

    assert.equal(t.calls.open, 1);
    assert.equal(t.tab.opener, null, 'tab.opener must be cut (noopener)');
    assert.equal(t.tab.location.href, SUKSES.redirect_url);
    assert.equal(t.ctx.window.location.href, 'http://halaman-inbox/', 'Inbox page must not navigate away');
    assert.equal(t.tab.closed, false);
});

test('B-3: popup blocked -> falls back to navigating the current page', async () => {
    const t = load({ popup: 'diblokir' });
    t.setFetch(jawab(200, SUKSES));

    t.ctx.buatPesanan();
    await flush();

    assert.equal(t.ctx.window.location.href, SUKSES.redirect_url);
});

test('B-4: tab closed by the user before the answer -> falls back to current page', async () => {
    const t = load();
    t.setFetch(() => new Promise((resolve) => setImmediate(() => {
        t.tab.closed = true;
        resolve({ status: 200, json: () => Promise.resolve(SUKSES) });
    })));

    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.equal(t.ctx.window.location.href, SUKSES.redirect_url);
});

test('B-5 (AC-9): second click while in flight is ignored; flag resets afterwards', async () => {
    const t = load();
    let lepas;
    t.setFetch(() => new Promise((resolve) => { lepas = () => resolve({ status: 200, json: () => Promise.resolve(SUKSES) }); }));

    t.ctx.buatPesanan();
    t.ctx.buatPesanan();
    t.ctx.buatPesanan();

    assert.equal(t.calls.fetch.length, 1, 'only one request while the first is in flight');
    assert.equal(t.calls.open, 1, 'only one tab');
    assert.equal(t.berjalan(), true);

    lepas();
    await flush();
    await flush();

    assert.equal(t.berjalan(), false, 'button must be usable again');
    assert.ok(t.calls.render >= 2, 'header re-rendered when starting and when finished');
});

test('B-6 (AC-10): CSRF token is replaced from every answer, so the next click uses the new one', async () => {
    const t = load();
    t.setFetch(jawab(200, SUKSES));
    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.equal(t.token(), 'tok-baru');

    t.ctx.buatPesanan();
    await flush();
    assert.equal(t.calls.fetch[1].opts.headers['X-CSRF-TOKEN'], 'tok-baru');
});

test('B-7 (AC-10): an error answer (409) also rotates the token, closes the tab and shows the message', async () => {
    const t = load();
    t.setFetch(jawab(409, { status: 'error', message: 'Sudah ada pelanggan bernama "Budi".', csrf: 'tok-setelah-409' }));

    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.equal(t.token(), 'tok-setelah-409');
    assert.equal(t.tab.closed, true);
    assert.deepEqual(t.calls.toasts, [['Sudah ada pelanggan bernama "Budi".', 'danger']]);
    assert.equal(t.ctx.window.location.href, 'http://halaman-inbox/');
});

test('B-8 (AC-10): CSRF rejection (403 without JSON body) shows the reload hint and closes the tab', async () => {
    const t = load();
    t.setFetch(jawab(403, undefined));

    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.equal(t.tab.closed, true);
    assert.equal(t.calls.toasts.length, 1);
    assert.match(t.calls.toasts[0][0], /Muat ulang halaman Inbox/);
    assert.equal(t.calls.toasts[0][1], 'danger');
    assert.equal(t.berjalan(), false);
});

test('B-9: a real 403 with JSON (ownership) keeps the server message, not the CSRF hint', async () => {
    const t = load();
    t.setFetch(jawab(403, { status: 'error', message: 'Percakapan ini sedang ditangani oleh U30.', csrf: 'tok-x' }));

    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.deepEqual(t.calls.toasts, [['Percakapan ini sedang ditangani oleh U30.', 'danger']]);
});

test('B-10: network failure -> toast, tab closed, button usable again', async () => {
    const t = load();
    t.setFetch(() => Promise.reject(new Error('Failed to fetch')));

    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.equal(t.tab.closed, true);
    assert.deepEqual(t.calls.toasts, [['Gagal menghubungi server: Failed to fetch', 'danger']]);
    assert.equal(t.berjalan(), false);
});

test('B-11: new pelanggan shows an info toast before opening Kasir', async () => {
    const t = load();
    t.setFetch(jawab(200, Object.assign({}, SUKSES, { pelanggan_baru: true })));

    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.deepEqual(t.calls.toasts, [['Pelanggan baru dibuat dari percakapan ini.', 'success']]);
    assert.equal(t.tab.location.href, SUKSES.redirect_url);
});

test('B-12 (AC-12): explicit conversationId wins; no conversation at all -> no request, no tab', async () => {
    const t = load({ conversationAktif: 42 });
    t.setFetch(jawab(200, SUKSES));
    t.ctx.buatPesanan(99);
    await flush();
    assert.equal(t.calls.fetch[0].url, '/inbox/percakapan/99/buat-pesanan');

    const kosong = load({ conversationAktif: null });
    kosong.ctx.buatPesanan();
    assert.equal(kosong.calls.fetch.length, 0);
    assert.equal(kosong.calls.open, 0);
});

test('B-13: a success answer without redirect_url is treated as failure, not a blank navigation', async () => {
    const t = load();
    t.setFetch(jawab(200, { status: 'success', csrf: 'tok-y' }));

    t.ctx.buatPesanan();
    await flush();
    await flush();

    assert.equal(t.tab.closed, true);
    assert.equal(t.tab.location.href, '');
    assert.equal(t.calls.toasts[0][1], 'danger');
});

test('B-14: missing INBOX_PAGE_CONFIG.csrf falls back to a safe default instead of throwing', () => {
    const t = load({ csrf: undefined });
    assert.equal(t.token(), '');
});
