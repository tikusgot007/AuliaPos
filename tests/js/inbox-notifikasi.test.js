// Tests for public/assets/js/inbox-notifikasi.js (notifikasi lintas
// halaman: judul tab, favicon, notifikasi Windows + fallback toast untuk
// Inbox).
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

// ---- minimal fake Notification API (mirrors the browser Notification) ---
function fakeNotificationClass(permission) {
    function FakeNotification(title, options) {
        this.title = title;
        this.options = options;
        this.closed = false;
        this.onclick = null;
        FakeNotification.instances.push(this);
    }
    FakeNotification.prototype.close = function() { this.closed = true; };
    FakeNotification.permission = permission;
    FakeNotification.instances = [];
    FakeNotification.requestCount = 0;
    FakeNotification.requestPermission = function() {
        FakeNotification.requestCount++;
        return Promise.resolve(FakeNotification.permission);
    };
    return FakeNotification;
}

function loadNotif(config = {}, notificationRef) {
    const sessionStorage = fakeStorage();
    const localStorage = fakeStorage();
    const stackContainer = new FakeEl();
    const izinBtn = new FakeEl();
    const opened = [];
    const elements = { inboxNotifToastStack: stackContainer, btnIzinNotifInbox: izinBtn };
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
        JSON, String, Array, Object, Number, Math, isNaN, Set, Promise,
        INBOX_NOTIF_CONFIG: { ringkasUrl: '/inbox/api/notifikasi-ringkas', inboxUrl: '/inbox', ...config },
        Image: function() { this.onload = null; this.onerror = null; },
        Notification: notificationRef,
    });
    ctx.window = ctx;
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-notifikasi.js'), 'utf8'), ctx);
    return { ctx, sessionStorage, localStorage, stackContainer, izinBtn, opened, get: (expr) => vm.runInContext(expr, ctx) };
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

test('formatPesanToastNotif: names the customer for that one conversation', () => {
    const t = loadNotif();
    assert.equal(t.ctx.formatPesanToastNotif(item(1, 't1', 'Budi')), 'Pesan baru dari Budi');
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

test('buatToastNotif: appends a sticky toast below the stack', () => {
    const t = loadNotif();
    t.ctx.buatToastNotif(item(1, 't1', 'Budi'));
    assert.equal(t.stackContainer.children.length, 1);

    const [wrap] = t.stackContainer.children[0].children;
    const [body] = wrap.children;
    assert.equal(body.textContent, 'Pesan baru dari Budi');

    // A second toast is added BELOW the first one (newest at the bottom), not merged into it.
    t.ctx.buatToastNotif(item(2, 't2', 'Siti'));
    assert.equal(t.stackContainer.children.length, 2);
    const bottomBody = t.stackContainer.children[1].children[0].children[0];
    assert.equal(bottomBody.textContent, 'Pesan baru dari Siti');
});

test('buatToastNotif: clicking the body opens that conversation AND dismisses that toast', () => {
    const t = loadNotif();
    t.ctx.buatToastNotif(item(1, 't1', 'Budi'));
    t.ctx.buatToastNotif(item(2, 't2', 'Siti'));
    assert.equal(t.stackContainer.children.length, 2);

    const topBody = t.stackContainer.children[0].children[0].children[0];
    topBody.onclick();

    assert.equal(t.opened.length, 1);
    assert.equal(t.opened[0].url, '/inbox?conversation_id=1');
    assert.equal(t.opened[0].name, 'AuliaInbox');

    // Only Budi's toast is gone -- Siti's is untouched, not closed by someone else's click.
    assert.equal(t.stackContainer.children.length, 1);
    assert.equal(t.get('toastAktif.length'), 1);
    const remainingBody = t.stackContainer.children[0].children[0].children[0];
    assert.equal(remainingBody.textContent, 'Pesan baru dari Siti');
});

test('buatToastNotif: the close button removes only that one toast', () => {
    const t = loadNotif();
    t.ctx.buatToastNotif(item(1, 't1'));
    t.ctx.buatToastNotif(item(2, 't2'));
    assert.equal(t.stackContainer.children.length, 2);

    const tutup = t.stackContainer.children[0].children[0].children[1];
    tutup.onclick();

    assert.equal(t.stackContainer.children.length, 1);
    assert.equal(t.get('toastAktif.length'), 1);
});

test('muatNotifikasiInbox (via the forEach it uses): several conversations new in the same poll each get their own toast', () => {
    const t = loadNotif();
    // Mirrors `hasil.itemBaru.forEach(buatToastNotif)` in muatNotifikasiInbox().
    [item(1, 't1', 'Budi'), item(2, 't2', 'Siti'), item(3, 't3', 'Andi')].forEach(t.ctx.buatToastNotif);

    assert.equal(t.stackContainer.children.length, 3);
    const teks = t.stackContainer.children.map((c) => c.children[0].children[0].textContent);
    assert.deepEqual(teks, ['Pesan baru dari Budi', 'Pesan baru dari Siti', 'Pesan baru dari Andi']);
});

test('bersihkanToastSelesai: a toast disappears once the conversation it named is no longer relevant', () => {
    const t = loadNotif();
    t.ctx.buatToastNotif(item(10, 't10'));
    t.ctx.buatToastNotif(item(20, 't20'));
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

// ---- Windows notification channel (AC-1..AC-8) ---------------------------

test('gunakanJalurWindows: only when API exists AND permission is granted (AC-1/AC-2)', () => {
    assert.equal(loadNotif().ctx.gunakanJalurWindows(), false, 'no API -> fallback');
    assert.equal(loadNotif({}, fakeNotificationClass('default')).ctx.gunakanJalurWindows(), false);
    assert.equal(loadNotif({}, fakeNotificationClass('denied')).ctx.gunakanJalurWindows(), false);
    assert.equal(loadNotif({}, fakeNotificationClass('granted')).ctx.gunakanJalurWindows(), true);
});

test('buatOpsiNotifikasiWindows: body names the sender, tag per conversation, silent follows mute (AC-1/AC-4)', () => {
    const t = loadNotif({}, fakeNotificationClass('granted'));
    const opts = t.ctx.buatOpsiNotifikasiWindows(item(7, 't7', 'Budi'), false);
    assert.equal(opts.body, 'Pesan baru dari Budi');
    assert.equal(opts.tag, 'aulia-inbox-7');
    assert.equal(opts.silent, false);
    assert.equal(t.ctx.buatOpsiNotifikasiWindows(item(7, 't7', 'Budi'), true).silent, true);
});

test('tampilkanNotifikasiWindows: creates one OS notification; click opens the conversation and closes it (AC-1/AC-3)', () => {
    const N = fakeNotificationClass('granted');
    const t = loadNotif({}, N);

    assert.equal(t.ctx.tampilkanNotifikasiWindows(item(5, 't5', 'Siti')), true);
    assert.equal(N.instances.length, 1);
    assert.equal(N.instances[0].title, 'Inbox WhatsApp');
    assert.equal(N.instances[0].options.body, 'Pesan baru dari Siti');

    N.instances[0].onclick();
    assert.equal(t.opened.length, 1);
    assert.equal(t.opened[0].url, '/inbox?conversation_id=5');
    assert.equal(N.instances[0].closed, true);
});

test('tampilkanNotifikasiWindows: returns false so the caller falls back to toast when there is no API or no permission (AC-2)', () => {
    assert.equal(loadNotif().ctx.tampilkanNotifikasiWindows(item(1, 't1')), false);
    assert.equal(loadNotif({}, fakeNotificationClass('default')).ctx.tampilkanNotifikasiWindows(item(1, 't1')), false);
});

test('tampilkanNotifikasiWindows: still shown when the page is focused -- no focus suppression (AC-5)', () => {
    const N = fakeNotificationClass('granted');
    const t = loadNotif({}, N);
    t.ctx.document.hasFocus = () => true;

    assert.equal(t.ctx.tampilkanNotifikasiWindows(item(1, 't1')), true);
    assert.equal(N.instances.length, 1);
});

test('bersihkanNotifikasiWindows: closes notifications whose conversation is no longer relevant (AC-6)', () => {
    const N = fakeNotificationClass('granted');
    const t = loadNotif({}, N);
    t.ctx.tampilkanNotifikasiWindows(item(10, 't10'));
    t.ctx.tampilkanNotifikasiWindows(item(20, 't20'));
    assert.equal(N.instances.length, 2);

    t.ctx.bersihkanNotifikasiWindows([20]);
    assert.equal(N.instances[0].closed, true);
    assert.equal(N.instances[1].closed, false);

    t.ctx.bersihkanNotifikasiWindows([]);
    assert.equal(N.instances[1].closed, true);
});

test('mintaIzinNotifikasi: requests permission only while it is still default (AC-7)', async () => {
    const N = fakeNotificationClass('default');
    const t = loadNotif({}, N);
    t.ctx.mintaIzinNotifikasi();
    await Promise.resolve();
    assert.equal(N.requestCount, 1);

    const N2 = fakeNotificationClass('granted');
    loadNotif({}, N2).ctx.mintaIzinNotifikasi();
    await Promise.resolve();
    assert.equal(N2.requestCount, 0, 'already granted -> must not ask again');
});

test('perbaruiTombolIzinNotifikasi: shows the enable button only when API exists and permission is default (AC-7)', () => {
    const t = loadNotif({}, fakeNotificationClass('default'));
    t.ctx.perbaruiTombolIzinNotifikasi();
    assert.equal(t.izinBtn.style.display, 'inline-block');

    const t2 = loadNotif({}, fakeNotificationClass('granted'));
    t2.ctx.perbaruiTombolIzinNotifikasi();
    assert.equal(t2.izinBtn.style.display, 'none');

    const t3 = loadNotif();
    t3.ctx.perbaruiTombolIzinNotifikasi();
    assert.equal(t3.izinBtn.style.display, 'none');
});

(async () => {
    let passed = 0;
    for (const [name, fn] of queue) {
        try { await fn(); passed++; console.log('ok   ' + name); } catch (e) { console.log('FAIL ' + name + '\n' + e.stack); process.exitCode = 1; }
    }
    console.log(passed + ' passed' + (process.exitCode ? ', ' + (queue.length - passed) + ' FAILED' : ''));
})();
