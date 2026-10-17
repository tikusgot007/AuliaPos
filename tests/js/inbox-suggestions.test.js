// Tests for public/assets/js/inbox-suggestions.js (M.A.R.A suggestions panel,
// AC-12..AC-15 of docs/requirements/2026-10-13-mara-saran-ke-kasir.md).
// Run: node tests/js/inbox-suggestions.test.js (Node.js built-in test runner)
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadSuggestions(overrides = {}) {
    const elements = {
        panelSaranMara: { innerHTML: '', style: { display: '' } },
        teksBalasan: { value: '', focus: () => {} },
    };

    const toasts = [];
    let fetchImpl = () => Promise.reject(new Error('fetch tidak di-mock'));

    // escapeHtmlInbox asli (inbox-thread.js) -- cukup &, <, > untuk kebutuhan
    // render teks saran; disalin apa adanya dari sumber agar test mengukur
    // perilaku yang sama.
    function escapeHtmlInbox(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    const document = {
        getElementById: (id) => elements[id] || null,
    };

    const ctx = vm.createContext(Object.assign({
        document,
        window: { INBOX_PAGE_CONFIG: { url: { suggestionBaseUrl: '/inbox/api/conversations/' } } },
        conversationAktif: 1,
        fetch: (url, opts) => fetchImpl(url, opts),
        showToast: (teks, jenis) => toasts.push([teks, jenis]),
        escapeHtmlInbox,
        console,
        Promise, Array, Object, String, JSON, parseInt,
    }, overrides));

    ctx.__setFetch = (fn) => { fetchImpl = fn; };

    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-suggestions.js'), 'utf8'),
        ctx
    );

    return { ctx, elements, toasts };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('S-1 (AC-12): balasan suggestion renders text and a clickable "Pakai" button', async () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{ id: 5, type: 'balasan', priority: 'normal', text: 'Halo, ada yang bisa dibantu?', items: null }];

    t.ctx.renderSaranMara();

    assert.match(t.elements.panelSaranMara.innerHTML, /Halo, ada yang bisa dibantu\?/);
    assert.match(t.elements.panelSaranMara.innerHTML, /pakaiSaranBalasan\(5\)/);
    assert.notEqual(t.elements.panelSaranMara.style.display, 'none');
});

test('S-2 (AC-12): pakaiSaranBalasan fills #teksBalasan without sending', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{ id: 7, type: 'balasan', priority: 'normal', text: 'Draft terisi', items: null }];

    t.ctx.pakaiSaranBalasan(7);

    assert.equal(t.elements.teksBalasan.value, 'Draft terisi');
});

test('S-3 (AC-12): pakaiSaranBalasan matches string vs number id (API nyata mengirim string)', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{ id: '9', type: 'balasan', priority: 'normal', text: 'String id', items: null }];

    t.ctx.pakaiSaranBalasan(9);

    assert.equal(t.elements.teksBalasan.value, 'String id');
});

test('S-4 (AC-13): daftar-periksa renders a checkbox per item', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{
        id: 11, type: 'daftar-periksa', priority: 'tinggi', text: 'Cek dulu',
        items: ['Cek ukuran', 'Cek jumlah'],
    }];

    t.ctx.renderSaranMara();

    const html = t.elements.panelSaranMara.innerHTML;
    assert.match(html, /type="checkbox"/);
    assert.match(html, /Cek ukuran/);
    assert.match(html, /Cek jumlah/);
    assert.equal((html.match(/type="checkbox"/g) || []).length, 2);
});

test('S-5 (AC-14): priority drives the colour class', () => {
    const t = loadSuggestions();

    for (const [priority, kelas] of [['normal', 'inbox-saran--normal'], ['tinggi', 'inbox-saran--tinggi'], ['darurat', 'inbox-saran--darurat']]) {
        t.ctx.daftarSaranMara = [{ id: 1, type: 'peringatan', priority, text: 'x', items: null }];
        t.ctx.renderSaranMara();
        assert.match(t.elements.panelSaranMara.innerHTML, new RegExp(kelas));
    }
});

test('S-6 (AC-14): unknown priority falls back to normal', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{ id: 1, type: 'peringatan', priority: 'entah', text: 'x', items: null }];

    t.ctx.renderSaranMara();

    assert.match(t.elements.panelSaranMara.innerHTML, /inbox-saran--normal/);
});

test('S-7 (AC-15): suggestion text is escaped (no XSS)', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{
        id: 1, type: 'peringatan', priority: 'normal',
        text: '<img src=x onerror=alert(1)>',
        items: null,
    }];

    t.ctx.renderSaranMara();

    const html = t.elements.panelSaranMara.innerHTML;
    assert.doesNotMatch(html, /<img src=x onerror/);
    assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
});

test('S-8 (AC-15): checklist items are escaped too', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{
        id: 1, type: 'daftar-periksa', priority: 'normal', text: '',
        items: ['<script>alert(1)</script>'],
    }];

    t.ctx.renderSaranMara();

    assert.doesNotMatch(t.elements.panelSaranMara.innerHTML, /<script>alert/);
    assert.match(t.elements.panelSaranMara.innerHTML, /&lt;script&gt;/);
});

test('S-9: empty list hides the panel', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [];

    t.ctx.renderSaranMara();

    assert.equal(t.elements.panelSaranMara.style.display, 'none');
    assert.equal(t.elements.panelSaranMara.innerHTML, '');
});

test('S-10 (AC-8): muatSaranMara fetches the per-conversation endpoint and renders', async () => {
    const t = loadSuggestions();
    let calledUrl = null;
    t.ctx.__setFetch((url) => {
        calledUrl = url;
        return Promise.resolve({
            json: () => Promise.resolve({
                status: 'success',
                suggestions: [{ id: 3, type: 'balasan', priority: 'normal', text: 'Dari server', items: null }],
            }),
        });
    });

    t.ctx.muatSaranMara(42);
    await flush();

    assert.equal(calledUrl, '/inbox/api/conversations/42/suggestions');
    assert.match(t.elements.panelSaranMara.innerHTML, /Dari server/);
});

test('S-11: stale response from a previous conversation does not overwrite the panel', async () => {
    const t = loadSuggestions();
    let resolveFetch;
    t.ctx.__setFetch(() => new Promise((resolve) => { resolveFetch = resolve; }));

    t.ctx.muatSaranMara(1);
    // Percakapan berganti: kosongkan -> increment seq.
    t.ctx.kosongkanSaranMara();

    resolveFetch({
        json: () => Promise.resolve({
            status: 'success',
            suggestions: [{ id: 9, type: 'balasan', priority: 'normal', text: 'Basi', items: null }],
        }),
    });
    await flush();

    assert.doesNotMatch(t.elements.panelSaranMara.innerHTML, /Basi/);
});

test('S-12: failed fetch hides the panel instead of throwing', async () => {
    const t = loadSuggestions();
    t.ctx.__setFetch(() => Promise.reject(new Error('network')));

    t.ctx.muatSaranMara(5);
    await flush();

    assert.equal(t.elements.panelSaranMara.style.display, 'none');
});

test('S-13: non-forced repeat for the SAME conversation is throttled (no extra fetch)', async () => {
    const t = loadSuggestions();
    let calls = 0;
    t.ctx.__setFetch(() => {
        calls++;
        return Promise.resolve({ json: () => Promise.resolve({ status: 'success', suggestions: [] }) });
    });

    t.ctx.muatSaranMara(7, true);   // open: forced
    await flush();
    t.ctx.muatSaranMara(7);         // poll: same conversation, throttled
    await flush();

    assert.equal(calls, 1, 'Poll refresh for the same conversation must not refetch within the throttle window.');
});

test('S-14: forced fetch for a DIFFERENT conversation always fires', async () => {
    const t = loadSuggestions();
    const urls = [];
    t.ctx.__setFetch((url) => {
        urls.push(url);
        return Promise.resolve({ json: () => Promise.resolve({ status: 'success', suggestions: [] }) });
    });

    t.ctx.muatSaranMara(7, true);
    await flush();
    t.ctx.muatSaranMara(8, true);
    await flush();

    assert.deepEqual(urls, [
        '/inbox/api/conversations/7/suggestions',
        '/inbox/api/conversations/8/suggestions',
    ]);
});

// ---------------------------------------------------------------------------
// Inbox -> Kasir: action button on M.A.R.A suggestions (AC-12 / AC-13).
// ---------------------------------------------------------------------------

test('S-15 (AC-12): balasan and peringatan with action buka_kasir render the "Buka di Kasir" button', () => {
    for (const type of ['balasan', 'peringatan']) {
        const t = loadSuggestions();
        t.ctx.daftarSaranMara = [{ id: 11, type, priority: 'normal', text: 'Buatkan pesanan', items: null, action: 'buka_kasir' }];

        t.ctx.renderSaranMara();

        assert.match(t.elements.panelSaranMara.innerHTML, /jalankanAksiSaran\(11\)/, type);
        assert.match(t.elements.panelSaranMara.innerHTML, /Buka di Kasir/, type);
    }
});

test('S-16 (AC-13): no button for a missing, null, unknown or URL-like action', () => {
    for (const action of [undefined, null, '', 'hapus_semua', 'https://evil.example/x', 'BUKA_KASIR', ['buka_kasir']]) {
        const t = loadSuggestions();
        t.ctx.daftarSaranMara = [{ id: 12, type: 'peringatan', priority: 'normal', text: 'x', items: null, action }];

        t.ctx.renderSaranMara();

        assert.doesNotMatch(t.elements.panelSaranMara.innerHTML, /jalankanAksiSaran|Buka di Kasir/, JSON.stringify(action));
    }
});

test('S-17 (AC-13): daftar-periksa never gets the button, even if the server data carries an action', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{ id: 13, type: 'daftar-periksa', priority: 'normal', text: 't', items: ['a'], action: 'buka_kasir' }];

    t.ctx.renderSaranMara();

    assert.doesNotMatch(t.elements.panelSaranMara.innerHTML, /jalankanAksiSaran|Buka di Kasir/);
});

test('S-18 (AC-12): clicking the action calls buatPesanan() for the OPEN conversation (no id from suggestion data)', () => {
    const dipanggil = [];
    const t = loadSuggestions({ buatPesanan: (...args) => dipanggil.push(args) });
    t.ctx.daftarSaranMara = [{ id: 14, type: 'balasan', priority: 'normal', text: 'x', items: null, action: 'buka_kasir', conversation_id: 9999 }];

    t.ctx.jalankanAksiSaran(14);

    assert.equal(dipanggil.length, 1);
    assert.deepEqual(dipanggil[0], [], 'must call buatPesanan() with no arguments so the page uses its own conversationAktif');
});

test('S-19 (AC-13): jalankanAksiSaran ignores an unknown suggestion id, an unknown action, and a missing buatPesanan', () => {
    const dipanggil = [];
    const t = loadSuggestions({ buatPesanan: () => dipanggil.push(1) });
    t.ctx.daftarSaranMara = [
        { id: 15, type: 'balasan', priority: 'normal', text: 'x', items: null, action: 'hapus_semua' },
        { id: 16, type: 'balasan', priority: 'normal', text: 'x', items: null },
    ];

    t.ctx.jalankanAksiSaran(15);
    t.ctx.jalankanAksiSaran(16);
    t.ctx.jalankanAksiSaran(404);
    assert.equal(dipanggil.length, 0);

    const tanpaFungsi = loadSuggestions();
    tanpaFungsi.ctx.daftarSaranMara = [{ id: 17, type: 'balasan', priority: 'normal', text: 'x', items: null, action: 'buka_kasir' }];
    assert.doesNotThrow(() => tanpaFungsi.ctx.jalankanAksiSaran(17));
});

test('S-20: the action button does not weaken the existing "Pakai draft ini" button or escaping', () => {
    const t = loadSuggestions();
    t.ctx.daftarSaranMara = [{ id: 18, type: 'balasan', priority: 'normal', text: '<script>alert(1)</script>', items: null, action: 'buka_kasir' }];

    t.ctx.renderSaranMara();

    assert.match(t.elements.panelSaranMara.innerHTML, /pakaiSaranBalasan\(18\)/);
    assert.match(t.elements.panelSaranMara.innerHTML, /jalankanAksiSaran\(18\)/);
    assert.doesNotMatch(t.elements.panelSaranMara.innerHTML, /<script>alert/);
});