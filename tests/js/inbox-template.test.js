// Tests for public/assets/js/inbox-template.js (AC-9, AC-10, AC-11, AC-12
// of docs/requirements/2026-10-03-template-balasan-cepat.md).
// Run: node tests/js/inbox-template.test.js   (no framework, no dependencies)
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function loadTemplate(overrides = {}) {
    const elements = {
        daftarTemplateBalasan: { innerHTML: '' },
        teksBalasan: { value: '' },
        modalTemplateBalasan: {},
    };

    const modalCalls = [];
    const antrianCalls = [];
    const toasts = [];
    const opsiDibuang = { count: 0 };

    const document = {
        getElementById: (id) => elements[id] || null,
    };

    const bootstrap = {
        Modal: {
            getOrCreateInstance: (el) => ({
                show: () => modalCalls.push(['show', el]),
                hide: () => modalCalls.push(['hide', el]),
            }),
        },
    };

    function escapeHtmlInbox(str) {
        return String(str).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    const ctx = vm.createContext(Object.assign({
        document,
        bootstrap,
        window: { INBOX_TEMPLATE_CONFIG: { apiUrl: '/inbox/api/balasan-template' } },
        conversationAktif: 1,
        fetch: () => Promise.reject(new Error('fetch tidak di-mock')),
        tambahMediaBalasan: (files) => antrianCalls.push(files),
        buangOperationIdBalasan: () => { opsiDibuang.count++; },
        sembunyikanStatusKirimBalasan: () => {},
        showToast: (teks, jenis) => toasts.push([teks, jenis]),
        escapeHtmlInbox,
        File,
        Blob,
        Promise, Array, Object, String, JSON,
    }, overrides));

    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-template.js'), 'utf8'), ctx);

    return { ctx, elements, modalCalls, antrianCalls, toasts, opsiDibuang };
}

const queue = [];
function test(name, fn) { queue.push([name, fn]); }

test('T-1: muatDaftarTemplateBalasan renders a clickable item per template', async () => {
    const t = loadTemplate({
        fetch: () => Promise.resolve({
            json: () => Promise.resolve({
                status: 'success',
                templates: [{ id: 1, nama: 'QRIS', teks: 'Scan ini', gambar_url: null }],
            }),
        }),
    });

    await t.ctx.muatDaftarTemplateBalasan();

    assert.match(t.elements.daftarTemplateBalasan.innerHTML, /QRIS/);
    assert.match(t.elements.daftarTemplateBalasan.innerHTML, /pilihTemplate\(1\)/);
});

test('T-2 (AC-12): muatDaftarTemplateBalasan shows an explicit empty-state message, not a bare empty list', async () => {
    const t = loadTemplate({
        fetch: () => Promise.resolve({ json: () => Promise.resolve({ status: 'success', templates: [] }) }),
    });

    await t.ctx.muatDaftarTemplateBalasan();

    assert.match(t.elements.daftarTemplateBalasan.innerHTML, /Belum ada template balasan/);
});

test('T-3 (AC-9, AC-11): selecting a text-only template fills the textarea and discards the idempotency key, without touching the media queue', async () => {
    const t = loadTemplate();
    t.ctx.daftarTemplateBalasanCache = [{ id: 5, nama: 'Jam Buka', teks: 'Kami buka 08:00-20:00', gambar_url: null }];

    t.ctx.pilihTemplate(5);

    assert.equal(t.elements.teksBalasan.value, 'Kami buka 08:00-20:00');
    assert.equal(t.opsiDibuang.count, 1);
    assert.deepEqual(t.antrianCalls, []);
    assert.deepEqual(t.modalCalls, [['hide', t.elements.modalTemplateBalasan]]);
});

test('T-4 (AC-9, AC-10): selecting a template with an image fetches it and pushes a File into the existing attachment queue', async () => {
    const blob = new Blob(['x'], { type: 'image/png' });
    const t = loadTemplate({
        fetch: (url) => {
            assert.equal(url, 'http://example.com/balasan-template/foto/abc.png');
            return Promise.resolve({ blob: () => Promise.resolve(blob) });
        },
    });
    t.ctx.daftarTemplateBalasanCache = [{
        id: 9, nama: 'QRIS', teks: null, gambar_url: 'http://example.com/balasan-template/foto/abc.png',
    }];

    await t.ctx.pilihTemplate(9);

    assert.equal(t.antrianCalls.length, 1);
    const file = t.antrianCalls[0][0];
    assert.ok(file instanceof File);
    assert.equal(file.name, 'QRIS.png');
    assert.equal(file.type, 'image/png');
    // Isi teks tetap diisi bersamaan kalau template punya gambar DAN teks
    // (AC-9: "gambar (jika ada) DAN teks template masuk ke composer").
    assert.deepEqual(t.modalCalls, [['hide', t.elements.modalTemplateBalasan]]);
});

test('T-5: selecting an unknown template id is a no-op', () => {
    const t = loadTemplate();
    t.ctx.daftarTemplateBalasanCache = [{ id: 1, nama: 'A', teks: 'x', gambar_url: null }];

    t.ctx.pilihTemplate(999);

    assert.equal(t.elements.teksBalasan.value, '');
    assert.deepEqual(t.modalCalls, []);
});

test('T-6: bukaModalTemplate does nothing without an active conversation', () => {
    const t = loadTemplate({ conversationAktif: null });

    t.ctx.bukaModalTemplate();

    assert.deepEqual(t.modalCalls, []);
});

(async () => {
    let passed = 0;
    for (const [name, fn] of queue) {
        try { await fn(); passed++; console.log('ok   ' + name); } catch (e) { console.log('FAIL ' + name + '\n' + e.stack); process.exitCode = 1; }
    }
    console.log(passed + ' passed' + (process.exitCode ? ', ' + (queue.length - passed) + ' FAILED' : ''));
})();
