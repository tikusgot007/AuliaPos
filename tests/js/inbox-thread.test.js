// Tests for public/assets/js/inbox-thread.js (AC-6..AC-13 of
// docs/requirements/2026-10-02-perbaikan-thread-inbox.md).
// Run: node tests/js/inbox-thread.test.js   (no framework, no dependencies)
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// ---- minimal fake DOM: just what the thread code touches ----------------
class FakeEl {
    constructor(html = '') {
        this.html = html;
        this.children = [];
        this.parentNode = null;
        this.className = (html.match(/^<div class="([^"]*)"/) || [])[1] || '';
        this.dataset = { id: (html.match(/data-id="([^"]*)"/) || [])[1] };
        this.scrollTop = 0;
        this.scrollHeight = 0;
        this.clientHeight = 0;
    }
    get firstChild() { return this.children[0] || null; }
    get nextSibling() {
        if (!this.parentNode) return null;
        const siblings = this.parentNode.children;
        return siblings[siblings.indexOf(this) + 1] || null;
    }
    _detach() {
        if (this.parentNode) this.parentNode.children.splice(this.parentNode.children.indexOf(this), 1);
        this.parentNode = null;
    }
    remove() { this._detach(); }
    appendChild(el) { return this.insertBefore(el, null); }
    insertBefore(el, ref) {
        el._detach();
        const at = ref ? this.children.indexOf(ref) : this.children.length;
        this.children.splice(at, 0, el);
        el.parentNode = this;
        return el;
    }
    replaceWith(el) {
        const parent = this.parentNode;
        const at = parent.children.indexOf(this);
        el._detach();
        this._detach();
        parent.children.splice(at, 0, el);
        el.parentNode = parent;
    }
    querySelector(sel) { return sel.startsWith('.') ? this.children.find((c) => c.className.split(' ').includes(sel.slice(1))) || null : null; }
    set innerHTML(html) {
        this.children.forEach((c) => { c.parentNode = null; });
        this.children = [];
        if (html) this.insertBefore(new FakeEl(html), null);
        this._inner = html;
    }
    get innerHTML() { return this._inner || ''; }
}

function loadThread() {
    const container = new FakeEl();
    const document = {
        getElementById: (id) => (id === 'threadMessages' ? container : null),
        createElement: () => ({ set innerHTML(h) { this.content = { firstElementChild: new FakeEl(h) }; } }),
    };
    const ctx = vm.createContext({
        document,
        INBOX_THREAD_CONFIG: { mediaBaseUrl: '/inbox/media/', maxMediaDownloadMb: 20 },
        fetch: () => new Promise(() => {}),
        Date, Map, Set, JSON, String, Array, Object, Number, isNaN, encodeURIComponent,
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-thread.js'), 'utf8'), ctx);
    const get = (expr) => vm.runInContext(expr, ctx);
    return { ctx, container, get, run: (code) => vm.runInContext(code, ctx) };
}

let passed = 0;
function test(name, fn) {
    try { fn(); passed++; console.log('ok   ' + name); } catch (e) { console.log('FAIL ' + name + '\n' + e.stack); process.exitCode = 1; }
}

const msg = (id, extra = {}) => ({
    id, wa_message_id: 'W' + id, direction: 'incoming', message_type: 'text', text: 'pesan ' + id, sender_name: null,
    send_status: 'received', is_internal: false, is_forwarded: false, media_filename: null, media_local_filename: null, extra_json: null,
    quoted_wa_message_id: null, quoted_sender_label: null, quoted_snippet: null, quoted_media_available: null,
    quoted_source_message_id: null, quoted_media_type: null, message_timestamp: '2026-10-02 10:00:00', ...extra,
});
const ids = (container) => container.children.map((c) => c.dataset.id);

// ---- pure rendering ----------------------------------------------------
test('escapeHtmlInbox escapes &, < and >', () => {
    const t = loadThread();
    assert.equal(t.ctx.escapeHtmlInbox('<a href="x">&</a>'), '&lt;a href="x"&gt;&amp;&lt;/a&gt;');
    assert.equal(t.ctx.escapeHtmlInbox(null), '');
});

test('AC-21 style: dangerous text never becomes markup', () => {
    const t = loadThread();
    for (const text of ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>']) {
        const html = t.ctx.renderBubbleHtml(msg(1, { text }));
        assert.ok(!html.includes('<script'), html);
        assert.ok(!html.includes('<img src=x'), html);
        assert.ok(html.includes('&lt;'), html);
    }
    const html = t.ctx.renderBubbleHtml(msg(2, { sender_name: '<b onmouseover=1>x</b>', quoted_wa_message_id: 'Q', quoted_sender_label: '<i>a</i>', quoted_snippet: '<img src=x>' }));
    assert.ok(!html.includes('<b onmouseover'), html);
    assert.ok(!html.includes('<i>a</i>'), html);
});

test('bubble carries data-id and the direction class (incoming, outgoing, internal note)', () => {
    const t = loadThread();
    assert.match(t.ctx.renderBubbleHtml(msg(5)), /^<div class="inbox-bubble incoming" data-id="5">/);
    assert.match(t.ctx.renderBubbleHtml(msg(6, { direction: 'outgoing', send_status: 'sent' })), /inbox-bubble outgoing" data-id="6"/);
    const internal = t.ctx.renderBubbleHtml(msg(7, { direction: 'outgoing', is_internal: 1 }));
    assert.match(internal, /inbox-bubble internal-note/);
    assert.ok(internal.includes('Internal'));
    assert.ok(!internal.includes('bubble-aksi'), 'no actions on internal notes');
});

test('actions: Teruskan disabled for audio/video/location/contact, absent for unsent outgoing', () => {
    const t = loadThread();
    for (const type of ['audio', 'video', 'location', 'contact']) {
        const html = t.ctx.renderBubbleHtml(msg(8, { message_type: type }));
        assert.ok(/<button[^>]*disabled/.test(html), type);
        assert.ok(html.includes('pilihKutipan(8)'), type);
    }
    assert.ok(!t.ctx.renderBubbleHtml(msg(9, { direction: 'outgoing', send_status: 'failed' })).includes('bubble-aksi'));
    assert.ok(t.ctx.renderBubbleHtml(msg(10)).includes('bukaPemilihTeruskan(10)'));
});

test('forwarded label and the five quote branches', () => {
    const t = loadThread();
    assert.ok(t.ctx.renderBubbleHtml(msg(1, { is_forwarded: true })).includes('Diteruskan'));
    const q = (extra) => t.ctx.renderBubbleHtml(msg(2, { quoted_wa_message_id: 'Q', quoted_sender_label: 'Pelanggan', quoted_snippet: 'snap', ...extra }));
    assert.ok(q({ quoted_media_available: 0, quoted_media_type: 'image', quoted_source_message_id: 3 }).includes('[Media tidak tersedia]'));
    assert.ok(/<img src="\/inbox\/media\/3"/.test(q({ quoted_media_available: 1, quoted_media_type: 'image', quoted_source_message_id: 3 })));
    assert.ok(q({ quoted_media_available: 1, quoted_media_type: 'document', quoted_source_message_id: 3 }).includes('inbox-kutipan-dokumen'));
    assert.ok(q({ quoted_media_available: 1, quoted_media_type: 'audio', quoted_source_message_id: 3 }).includes('[Audio]'));
    assert.ok(q({ quoted_media_available: 1, quoted_media_type: 'image', quoted_source_message_id: null }).includes('snap'));
    assert.ok(q({ quoted_sender_label: null }).includes('Pesan tidak ditemukan'));
});

test('media state changes the markup (failed, gateway down, temporary failure)', () => {
    const t = loadThread();
    const img = msg(11, { message_type: 'image', media_local_filename: null });
    assert.ok(t.ctx.renderBubbleHtml(img).includes('<img src="/inbox/media/11"'));
    t.run("mediaGagal.add('11')");
    assert.ok(t.ctx.renderBubbleHtml(img).includes('kemungkinan sudah kadaluarsa'));
    t.run("mediaGagal.delete('11'); gatewayTerhubung = false");
    assert.ok(t.ctx.renderBubbleHtml(img).includes('Gateway terputus'));
    assert.ok(t.ctx.renderBubbleHtml(msg(12, { message_type: 'image', media_local_filename: 'f.jpg' })).includes('<img src="/inbox/media/12"'), 'local copy renders even when the gateway is down');
});

// ---- plan (pure) -------------------------------------------------------
const items = (t, list) => list.map((m) => ({ key: String(m.id), html: t.ctx.renderBubbleHtml(m) }));
// arrays created inside the vm context have another realm's prototype; copy them before deepEqual
const actions = (plan) => Array.from(plan.ops, (o) => o.action);
const removed = (plan) => Array.from(plan.remove);

test('AC-7: identical data -> every bubble is kept', () => {
    const t = loadThread();
    const list = [msg(1), msg(2), msg(3)];
    const old = new Map(items(t, list).map((i) => [i.key, i.html]));
    const plan = t.ctx.rencanaPembaruanThread(old, items(t, list), () => true);
    assert.deepEqual(actions(plan), ['tetap', 'tetap', 'tetap']);
    assert.deepEqual(removed(plan), []);
});

test('AC-8: a new message only adds one bubble', () => {
    const t = loadThread();
    const old = new Map(items(t, [msg(1), msg(2)]).map((i) => [i.key, i.html]));
    const plan = t.ctx.rencanaPembaruanThread(old, items(t, [msg(1), msg(2), msg(3)]), () => true);
    assert.deepEqual(actions(plan), ['tetap', 'tetap', 'tambah']);
});

test('AC-9: a changed message (send_status, quote filled, media state) redraws only that bubble', () => {
    const t = loadThread();
    const base = [msg(1), msg(2, { direction: 'outgoing', send_status: 'sent' }), msg(3, { message_type: 'image', media_local_filename: 'a.jpg' })];
    const old = new Map(items(t, base).map((i) => [i.key, i.html]));
    const failed = [msg(1), msg(2, { direction: 'outgoing', send_status: 'failed' }), base[2]];
    assert.deepEqual(actions(t.ctx.rencanaPembaruanThread(old, items(t, failed), () => true)), ['tetap', 'ganti', 'tetap']);
    // client-side state, not part of the server data
    t.run("mediaGagal.add('3')");
    assert.deepEqual(actions(t.ctx.rencanaPembaruanThread(old, items(t, base), () => true)), ['tetap', 'tetap', 'ganti']);
    t.run("mediaGagal.delete('3'); pesanTerkirimTanpaKutipan.add(2)");
    assert.deepEqual(actions(t.ctx.rencanaPembaruanThread(old, items(t, base), () => true)), ['tetap', 'ganti', 'tetap']);
});

test('AC-10: a message missing from the response is removed; switching conversation replaces everything', () => {
    const t = loadThread();
    const old = new Map(items(t, [msg(1), msg(2), msg(3)]).map((i) => [i.key, i.html]));
    const plan = t.ctx.rencanaPembaruanThread(old, items(t, [msg(2), msg(3)]), () => true);
    assert.deepEqual(removed(plan), ['1']);
    assert.deepEqual(actions(plan), ['tetap', 'tetap']);
    const other = t.ctx.rencanaPembaruanThread(old, items(t, [msg(10), msg(11)]), () => true);
    assert.deepEqual(removed(other).sort(), ['1', '2', '3']);
    assert.deepEqual(actions(other), ['tambah', 'tambah']);
});

test('an element that is no longer in the DOM is drawn again', () => {
    const t = loadThread();
    const old = new Map(items(t, [msg(1), msg(2)]).map((i) => [i.key, i.html]));
    const plan = t.ctx.rencanaPembaruanThread(old, items(t, [msg(1), msg(2)]), (k) => k !== '2');
    assert.deepEqual(actions(plan), ['tetap', 'tambah']);
});

// ---- DOM application (fake DOM) -----------------------------------------
test('AC-7/AC-8/AC-9 on the DOM: untouched bubbles stay the same node', () => {
    const t = loadThread();
    t.ctx.renderPesan([msg(1), msg(2), msg(3)], true);
    const before = [...t.container.children];
    assert.deepEqual(ids(t.container), ['1', '2', '3']);

    t.ctx.renderPesan([msg(1), msg(2), msg(3)], false);
    assert.deepEqual(t.container.children, before, 'same data: same nodes, same order');
    before.forEach((el, i) => assert.equal(t.container.children[i], el));

    t.ctx.renderPesan([msg(1), msg(2), msg(3), msg(4)], false);
    assert.equal(t.container.children.length, 4);
    before.forEach((el, i) => assert.equal(t.container.children[i], el, 'old bubbles not replaced'));

    t.ctx.renderPesan([msg(1), msg(2, { text: 'diubah' }), msg(3), msg(4)], false);
    assert.equal(t.container.children[0], before[0]);
    assert.notEqual(t.container.children[1], before[1], 'only the changed bubble is replaced');
    assert.equal(t.container.children[2], before[2]);
    assert.deepEqual(ids(t.container), ['1', '2', '3', '4'], 'order is kept after a replace');
});

test('AC-10 on the DOM: removal, late message in the middle, empty state, clean switch', () => {
    const t = loadThread();
    t.ctx.renderPesan([msg(1), msg(3)], true);
    t.ctx.renderPesan([msg(1), msg(2), msg(3)], false);
    assert.deepEqual(ids(t.container), ['1', '2', '3'], 'a late message is inserted at its position');
    t.ctx.renderPesan([msg(3)], false);
    assert.deepEqual(ids(t.container), ['3']);
    t.ctx.renderPesan([], false);
    assert.ok(t.container.querySelector('.inbox-thread-empty'));
    t.ctx.renderPesan([msg(20), msg(21)], true);
    assert.deepEqual(ids(t.container), ['20', '21'], 'the empty-state placeholder is gone');
    t.ctx.resetThread('<div class="inbox-thread-empty">x</div>');
    t.ctx.renderPesan([msg(30)], true);
    assert.deepEqual(ids(t.container), ['30']);
});

test('AC-11: auto-scroll only within 80px of the bottom (or when forced)', () => {
    const t = loadThread();
    const c = t.container;
    c.scrollHeight = 1000; c.clientHeight = 400;
    c.scrollTop = 0; // reading old messages, far from the bottom
    t.ctx.renderPesan([msg(1)], false);
    assert.equal(c.scrollTop, 0, 'a reader scrolled up is not dragged down');
    c.scrollTop = 530; // 70px from the bottom
    t.ctx.renderPesan([msg(1), msg(2)], false);
    assert.equal(c.scrollTop, 1000, 'near the bottom: follow');
    c.scrollTop = 0;
    t.ctx.renderPesan([msg(1), msg(2)], true);
    assert.equal(c.scrollTop, 1000, 'forced scroll');
});

test('AC-12: the bubble shown right after sending is not duplicated by polling', () => {
    const t = loadThread();
    t.ctx.renderPesan([msg(1)], true);
    const sent = msg(2, { direction: 'outgoing', send_status: 'sent', sender_name: 'Rina' });
    t.ctx.tampilkanBubbleOutgoing(sent);
    assert.deepEqual(ids(t.container), ['1', '2']);
    const shown = t.container.children[1];

    t.ctx.renderPesan([msg(1), sent], false);
    assert.deepEqual(ids(t.container), ['1', '2'], 'no duplicate');
    assert.equal(t.container.children[1], shown, 'same data: the shown bubble is kept');

    t.ctx.renderPesan([msg(1), { ...sent, text: 'dari server' }], false);
    assert.deepEqual(ids(t.container), ['1', '2'], 'different data: replaced, still one bubble');
});

test('AC-12: sending in an empty conversation clears the placeholder', () => {
    const t = loadThread();
    t.ctx.renderPesan([], true);
    t.ctx.tampilkanBubbleOutgoing(msg(5, { direction: 'outgoing', send_status: 'sent' }));
    assert.deepEqual(ids(t.container), ['5']);
});

test('AC-13: pesanCached holds exactly the displayed messages (old and new)', () => {
    const t = loadThread();
    t.ctx.renderPesan([msg(1), msg(2)], true);
    assert.deepEqual(Object.keys(t.get('pesanCached')).sort(), ['1', '2']);
    t.ctx.renderPesan([msg(2), msg(3)], false);
    assert.deepEqual(Object.keys(t.get('pesanCached')).sort(), ['2', '3'], 'message 1 left the window');
    t.ctx.tampilkanBubbleOutgoing(msg(4, { direction: 'outgoing', send_status: 'sent' }));
    assert.ok(t.get('pesanCached')['4']);
});

console.log(passed + ' passed' + (process.exitCode ? ', some FAILED' : ''));
