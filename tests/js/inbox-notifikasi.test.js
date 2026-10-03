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

function loadNotif(config = {}) {
    const sessionStorage = fakeStorage();
    const localStorage = fakeStorage();
    const toasts = [];
    const opened = [];
    const document = {
        title: 'AULIA',
        getElementById: () => null,
        querySelector: () => null,
        createElement: () => ({ getContext: () => ({}) }),
    };
    const ctx = vm.createContext({
        document,
        window: {},
        sessionStorage,
        localStorage,
        fetch: () => new Promise(() => {}),
        setInterval: () => 0,
        setTimeout: () => 0,
        console,
        JSON, String, Array, Object, Number, Math, isNaN,
        INBOX_NOTIF_CONFIG: { ringkasUrl: '/inbox/api/notifikasi-ringkas', inboxUrl: '/inbox', ...config },
        showToast: (msg, type, opsi) => { toasts.push({ msg, type, opsi }); },
        Image: function() { this.onload = null; this.onerror = null; },
    });
    ctx.window = ctx;
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-notifikasi.js'), 'utf8'), ctx);
    return { ctx, sessionStorage, localStorage, toasts, opened, get: (expr) => vm.runInContext(expr, ctx) };
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

test('formatPesanToastNotif: singular names the customer, plural gives a count', () => {
    const t = loadNotif();
    assert.equal(t.ctx.formatPesanToastNotif([item(1, 't1', 'Budi')]), 'Pesan baru dari Budi');
    assert.equal(t.ctx.formatPesanToastNotif([item(1, 't1'), item(2, 't2')]), '2 percakapan menunggu balasan Anda');
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

(async () => {
    let passed = 0;
    for (const [name, fn] of queue) {
        try { await fn(); passed++; console.log('ok   ' + name); } catch (e) { console.log('FAIL ' + name + '\n' + e.stack); process.exitCode = 1; }
    }
    console.log(passed + ' passed' + (process.exitCode ? ', ' + (queue.length - passed) + ' FAILED' : ''));
})();
