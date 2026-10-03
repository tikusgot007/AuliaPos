// Tests for public/assets/js/inbox-notifikasi.js (notifikasi lintas
// halaman: judul tab, favicon, suara, toast untuk Inbox).
// Run: node tests/js/inbox-notifikasi.test.js   (no framework, no dependencies)
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// ---- minimal fake storage (Map-backed, mirrors the Web Storage API) -----
function fakeStorage() {
    const map = new Map();
    return {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => { map.set(k, String(v)); },
        removeItem: (k) => { map.delete(k); },
        clear: () => { map.clear(); },
    };
}

// ---- minimal fake DOM element: just what inbox-notifikasi.js touches -----
class FakeEl {
    constructor() {
        this.children = [];
        this.parentNode = null;
        this.className = '';
        this.style = {};
        this._text = '';
    }
    set textContent(v) { this._text = v; }
    get textContent() { return this._text; }
    setAttribute() {}
    getContext() { return {}; } // only exercised by the (untested here) favicon canvas path
    appendChild(el) { el.parentNode = this; this.children.push(el); return el; }
    prepend(el) { el.parentNode = this; this.children.unshift(el); return el; }
    removeChild(el) {
        const i = this.children.indexOf(el);
        if (i !== -1) this.children.splice(i, 1);
        el.parentNode = null;
    }
}

function loadNotif(config = {}) {
    const sessionStorage = fakeStorage();
    const localStorage = fakeStorage();
    const stackContainer = new FakeEl();
    const opened = [];
    const elements = { inboxNotifToastStack: stackContainer };
    const document = {
        title: 'AULIA',
        getElementById: (id) => elements[id] || null,
        querySelector: () => null,
        createElement: () => new FakeEl(),
    };
    const ctx = vm.createContext({
        document,
        window: {},
        open: (url, name, opts) => { opened.push({ url, name, opts }); },
        sessionStorage,
        localStorage,
        fetch: () => new Promise(() => {}),
        setInterval: () => 0,
        setTimeout: () => 0,
        console,
        JSON, String, Array, Object, Number, Math, isNaN, Set,
        INBOX_NOTIF_CONFIG: { ringkasUrl: '/inbox/api/notifikasi-ringkas', inboxUrl: '/inbox', ...config },
        Image: function() { this.onload = null; this.onerror = null; },
    });
    ctx.window = ctx;
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-notifikasi.js'), 'utf8'), ctx);
    return { ctx, sessionStorage, localStorage, stackContainer, opened, get: (expr) => vm.runInContext(expr, ctx) };
}

const queue = [];
function test(name, fn) { queue.push([name, fn]); }

const item = (id, ts, label) => ({ id, last_message_at: ts, label: label || ('Pelanggan ' + id) });

// ---- pure functions -----------------------------------------------------

test('parseNotifSeen: valid JSON object passes through; null/empty/garbage become {}', () => {
    const t = loadNotif();
    assert.deepEqual(JSON.parse(JSON.stringify(t.ctx.parseNotifSeen('{"1":"2026-10-03 10:00:00"}'))), { '1': '2026-10-03 10:00:00' });
    assert.deepEqual(JSON.parse(JSON.stringify(t.ctx.parseNotifSeen(null))), {});
    assert.deepEqual(JSON.parse(JSON.stringify(t.ctx.parseNotifSeen(''))), {});
    assert.deepEqual(JSON.parse(JSON.stringify(t.ctx.parseNotifSeen('not json'))), {});
    assert.deepEqual(JSON.parse(JSON.stringify(t.ctx.parseNotifSeen('"a string, not an object"'))), {});
});

test('hitungItemBaru: first-ever check (belumPernahDicek) seeds the map but reports nothing new', () => {
    const t = loadNotif();
    const hasil = t.ctx.hitungItemBaru([item(1, 't1'), item(2, 't2')], {}, true);
    assert.deepEqual(JSON.parse(JSON.stringify(hasil.itemBaru)), []);
    assert.deepEqual(JSON.parse(JSON.stringify(hasil.petaBaru)), { '1': 't1', '2': 't2' });
});

test('hitungItemBaru: a conversation unseen before (and not a first check) is new', () => {
    const t = loadNotif();
    const hasil = t.ctx.hitungItemBaru([item(1, 't1')], {}, false);
    assert.equal(hasil.itemBaru.length, 1);
    assert.equal(hasil.itemBaru[0].id, 1);
});

test('hitungItemBaru: same last_message_at as last seen is not reported again (no repeat toast on page navigation)', () => {
    const t = loadNotif();
    const hasil = t.ctx.hitungItemBaru([item(1, 't1'), item(2, 't2')], { '1': 't1', '2': 't2' }, false);
    assert.deepEqual(JSON.parse(JSON.stringify(hasil.itemBaru)), []);
});

test('hitungItemBaru: a changed last_message_at on an already-seen conversation is reported as new', () => {
    const t = loadNotif();
    const hasil = t.ctx.hitungItemBaru([item(1, 't2')], { '1': 't1' }, false);
    assert.equal(hasil.itemBaru.length, 1);
    assert.equal(hasil.petaBaru['1'], 't2');
});

test('hitungItemBaru: a conversation no longer in the list (replied/reassigned away) is simply dropped, not an error', () => {
    const t = loadNotif();
    const hasil = t.ctx.hitungItemBaru([item(2, 't2')], { '1': 't1', '2': 't2' }, false);
    assert.deepEqual(JSON.parse(JSON.stringify(hasil.itemBaru)), []);
    assert.deepEqual(JSON.parse(JSON.stringify(hasil.petaBaru)), { '2': 't2' });
});

test('formatJudulTab: prefixes a count, or restores the original title when zero', () => {
    const t = loadNotif();
    assert.equal(t.ctx.formatJudulTab('AULIA', 0), 'AULIA');
    assert.equal(t.ctx.formatJudulTab('AULIA', 3), '(3) AULIA');
});

test('formatPesanToastNotif: one person is named directly; several are all named, comma-separated', () => {
    const t = loadNotif();
    assert.equal(t.ctx.formatPesanToastNotif([item(1, 't1', 'Budi')]), 'Pesan baru dari Budi');
    assert.equal(
        t.ctx.formatPesanToastNotif([item(1, 't1', 'Budi'), item(2, 't2', 'Siti')]),
        'Pesan baru dari Budi, Siti'
    );
});

test('formatPesanToastNotif: beyond the cap, the list is truncated with a "+N lainnya" tail', () => {
    const t = loadNotif();
    const enam = [1, 2, 3, 4, 5, 6].map((n) => item(n, 't' + n, 'P' + n));
    assert.equal(
        t.ctx.formatPesanToastNotif(enam),
        'Pesan baru dari P1, P2, P3, P4, P5, +1 lainnya'
    );
});

test('tujuanKlikToastNotif: one person deep-links to that conversation; several have no single target', () => {
    const t = loadNotif();
    assert.equal(t.ctx.tujuanKlikToastNotif([item(5, 't5')]), 5);
    assert.equal(t.ctx.tujuanKlikToastNotif([item(1, 't1'), item(2, 't2')]), null);
});

test('entriUntukDibuang: a toast is only dropped once NONE of its conversations are still relevant', () => {
    const t = loadNotif();
    const entries = [
        { key: 1, ids: [10] },
        { key: 2, ids: [20, 21] },
    ];
    // 10 is gone (handled), 20 is still pending, 21 is gone -- entry 2 survives because 20 is still relevant.
    assert.deepEqual(t.ctx.entriUntukDibuang(entries, [20]), [1]);
    // Neither 20 nor 21 is relevant any more -- entry 2 is now dropped too.
    assert.deepEqual(t.ctx.entriUntukDibuang(entries, []), [1, 2]);
    // Everything still relevant -- nothing dropped.
    assert.deepEqual(t.ctx.entriUntukDibuang(entries, [10, 20, 21]), []);
});

// ---- mute toggle (persisted via localStorage) ---------------------------

test('notifDibungkam/alihkanMuteNotif: starts unmuted, toggling persists to localStorage', () => {
    const t = loadNotif();
    assert.equal(t.ctx.notifDibungkam(), false);
    t.ctx.alihkanMuteNotif();
    assert.equal(t.ctx.notifDibungkam(), true);
    assert.equal(t.localStorage.getItem('auliaInboxNotifMuted'), '1');
    t.ctx.alihkanMuteNotif();
    assert.equal(t.ctx.notifDibungkam(), false);
});

test('mainkanBeepNotif: does nothing (no throw) when muted, and swallows a missing AudioContext', () => {
    const t = loadNotif();
    t.ctx.alihkanMuteNotif();
    assert.doesNotThrow(() => t.ctx.mainkanBeepNotif());
    t.ctx.alihkanMuteNotif();
    assert.doesNotThrow(() => t.ctx.mainkanBeepNotif(), 'no AudioContext in the test environment -- must not throw');
});

// ---- end-to-end sessionStorage round trip via bacaNotifSeen/simpanNotifSeen ----

test('bacaNotifSeen/simpanNotifSeen round-trip through sessionStorage', () => {
    const t = loadNotif();
    assert.deepEqual(JSON.parse(JSON.stringify(t.ctx.bacaNotifSeen())), {});
    t.ctx.simpanNotifSeen({ '5': 'x' });
    assert.deepEqual(JSON.parse(JSON.stringify(t.ctx.bacaNotifSeen())), { '5': 'x' });
});

// ---- sticky, stacking toast (DOM) ----------------------------------------

test('buatToastNotif: appends a sticky toast on top of the stack; clicking it opens the right target', () => {
    const t = loadNotif();
    t.ctx.buatToastNotif([item(1, 't1', 'Budi')]);
    assert.equal(t.stackContainer.children.length, 1);

    const [wrap] = t.stackContainer.children[0].children;
    const [body] = wrap.children;
    assert.equal(body.textContent, 'Pesan baru dari Budi');

    body.onclick();
    assert.equal(t.opened.length, 1);
    assert.equal(t.opened[0].url, '/inbox?conversation_id=1');
    assert.equal(t.opened[0].name, 'AuliaInbox');

    // A second toast is added ABOVE the first one (prepend), not merged into it.
    t.ctx.buatToastNotif([item(2, 't2', 'Siti'), item(3, 't3', 'Andi')]);
    assert.equal(t.stackContainer.children.length, 2);
    const topBody = t.stackContainer.children[0].children[0].children[0];
    assert.equal(topBody.textContent, 'Pesan baru dari Siti, Andi');

    // Several people named -> clicking opens the Inbox with no specific conversation.
    topBody.onclick();
    assert.equal(t.opened[1].url, '/inbox');
});

test('buatToastNotif: the close button removes only that one toast', () => {
    const t = loadNotif();
    t.ctx.buatToastNotif([item(1, 't1')]);
    t.ctx.buatToastNotif([item(2, 't2')]);
    assert.equal(t.stackContainer.children.length, 2);

    const tutup = t.stackContainer.children[0].children[0].children[1];
    tutup.onclick();

    assert.equal(t.stackContainer.children.length, 1);
    assert.equal(t.get('toastAktif.length'), 1);
});

test('bersihkanToastSelesai: a toast disappears once every conversation it named is no longer relevant', () => {
    const t = loadNotif();
    t.ctx.buatToastNotif([item(10, 't10')]);
    t.ctx.buatToastNotif([item(20, 't20'), item(21, 't21')]);
    assert.equal(t.stackContainer.children.length, 2);

    // 10 was handled (gone); 20 is still pending -> only the first toast goes.
    t.ctx.bersihkanToastSelesai([20]);
    assert.equal(t.stackContainer.children.length, 1);
    assert.equal(t.get('toastAktif.length'), 1);

    // Now 20 is handled too -> the second toast goes as well.
    t.ctx.bersihkanToastSelesai([]);
    assert.equal(t.stackContainer.children.length, 0);
    assert.equal(t.get('toastAktif.length'), 0);
});

(async () => {
    let passed = 0;
    for (const [name, fn] of queue) {
        try { await fn(); passed++; console.log('ok   ' + name); } catch (e) { console.log('FAIL ' + name + '\n' + e.stack); process.exitCode = 1; }
    }
    console.log(passed + ' passed' + (process.exitCode ? ', ' + (queue.length - passed) + ' FAILED' : ''));
})();
