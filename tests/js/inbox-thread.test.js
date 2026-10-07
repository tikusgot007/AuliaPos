// Tests for public/assets/js/inbox-thread.js (AC-6..AC-13 of
// docs/requirements/2026-10-02-perbaikan-thread-inbox.md).
// Run: node tests/js/inbox-thread.test.js (Node.js built-in test runner)
const { test } = require('node:test');
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
        this.style = {};
        this.classes = new Set();
        this.classList = { add: (c) => this.classes.add(c), remove: (c) => this.classes.delete(c) };
        this.scrolledIntoView = 0;
    }
    scrollIntoView() { this.scrolledIntoView++; }
    addEventListener() {}
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
    const button = new FakeEl();
    const counter = { textContent: '0' };
    button.querySelector = (sel) => (sel === '.inbox-gulung-jumlah' ? counter : null);
    const document = {
        getElementById: (id) => (id === 'threadMessages' ? container : id === 'btnGulungBaru' ? button : null),
        createElement: () => ({ set innerHTML(h) { this.content = { firstElementChild: new FakeEl(h) }; } }),
    };
    const ctx = vm.createContext({
        document,
        INBOX_THREAD_CONFIG: { mediaBaseUrl: '/inbox/media/', apiMessagesUrl: '/inbox/api/conversations', maxMediaDownloadMb: 20 },
        fetch: () => new Promise(() => {}),
        setTimeout: () => 0,
        Date, Map, Set, JSON, String, Array, Object, Number, isNaN, encodeURIComponent, Promise, RegExp,
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-thread.js'), 'utf8'), ctx);
    const get = (expr) => vm.runInContext(expr, ctx);
    return { ctx, container, button, counter, get, run: (code) => vm.runInContext(code, ctx) };
}

// Tests run one after another (some are async); results print in order.

const msg = (id, extra = {}) => ({
    id, wa_message_id: 'W' + id, direction: 'incoming', message_type: 'text', text: 'pesan ' + id, sender_name: null,
    send_status: 'received', is_internal: false, is_forwarded: false, is_edited: false, is_edited_text_resolved: false, is_revoked: false, media_filename: null, media_local_filename: null, extra_json: null,
    quoted_wa_message_id: null, quoted_sender_label: null, quoted_snippet: null, quoted_media_available: null,
    quoted_source_message_id: null, quoted_media_type: null, message_timestamp: '2026-10-02 10:00:00', ...extra,
});
// bubbles only (date separators and the "load older" button have no data-id)
const ids = (container) => container.children.filter((c) => c.dataset.id !== undefined).map((c) => c.dataset.id);
// one polling response for conversation 1, like muatUlangPesan() delivers it
const poll = (t, list, force = false, hasMore = false) => t.ctx.terimaPesanTerbaru(1, { status: 'success', messages: list, has_more: hasMore }, force);

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

test('handoff timeline event renders as a non-message timeline item', () => {
    const t = loadThread();
    const h = {
        item_type: 'handoff', handoff_id: 77, from_user_id: 1, to_user_id: 2,
        initiated_by_user_id: 1, summary: '<ringkasan>', next_action: 'follow up',
        note: null, message_timestamp: '2026-10-02 10:05:00'
    };
    const html = t.ctx.renderHandoffTimelineHtml(h);
    assert.match(html, /inbox-handoff-timeline/);
    assert.match(html, /data-id="handoff:77"/);
    assert.ok(html.includes('&lt;ringkasan&gt;'));
    assert.ok(!html.includes('<ringkasan>'));
});

test('handoff timeline omits empty optional note', () => {
    const t = loadThread();
    const html = t.ctx.renderHandoffTimelineHtml({
        item_type: 'handoff', handoff_id: 78, from_user_id: 1, to_user_id: 2,
        initiated_by_user_id: 1, summary: '', message_timestamp: '2026-10-02 10:05:00'
    });
    assert.ok(html.includes('handoff:78'));
    assert.ok(!html.includes('Tindakan lanjutan:'));
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

test('TODO-F7/F8: edit/delete lifecycle labels and validated text state', () => {
    const t = loadThread();

    const edited = t.ctx.renderBubbleHtml(msg(20, { is_edited: true }));
    assert.ok(edited.includes('Pesan diedit'), edited);
    assert.ok(edited.includes('belum tentu terbaru'), edited);
    assert.ok(edited.includes('inbox-teks-basi'), 'unresolved edit stays dimmed: ' + edited);

    const resolved = t.ctx.renderBubbleHtml(msg(21, { is_edited: true, is_edited_text_resolved: true, text: 'teks terbaru' }));
    assert.ok(resolved.includes('Pesan diedit \u2014 teks terbaru'), resolved);
    assert.ok(!resolved.includes('belum tentu terbaru'), resolved);
    assert.ok(!resolved.includes('inbox-teks-basi'), 'resolved edit must render normally: ' + resolved);
    assert.ok(resolved.includes('teks terbaru'), resolved);

    const deleted = t.ctx.renderBubbleHtml(msg(22, { is_revoked: true }));
    assert.ok(deleted.includes('Pesan dihapus'), deleted);
    assert.ok(deleted.includes('inbox-teks-basi'), deleted);

    const normal = t.ctx.renderBubbleHtml(msg(23));
    assert.ok(!normal.includes('Pesan diedit'), normal);
    assert.ok(!normal.includes('Pesan dihapus'), normal);
    assert.ok(!normal.includes('inbox-teks-basi'), normal);
});

test('TODO-F8: stale edit bubble is redrawn when text becomes resolved', () => {
    const t = loadThread();
    const stale = msg(30, { is_edited: true, is_edited_text_resolved: false, text: 'versi lama' });
    const fresh = msg(30, { is_edited: true, is_edited_text_resolved: true, text: 'versi terbaru' });

    const firstHtml = t.ctx.renderBubbleHtml(stale);
    const secondHtml = t.ctx.renderBubbleHtml(fresh);
    assert.notEqual(firstHtml, secondHtml, 'resolved state must change bubble HTML');

    const plan = t.ctx.rencanaPembaruanThread(
        new Map([['30', firstHtml]]),
        [{ key: '30', html: secondHtml }],
        () => true
    );
    assert.equal(plan.ops.length, 1);
    assert.equal(plan.ops[0].action, 'ganti', 'state transition must replace the existing bubble, not append a duplicate');
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

test('timeline orders handoff chronologically without entering message cache', () => {
    const t = loadThread();
    const h = {
        item_type: 'handoff', handoff_id: 8, from_user_id: 1, to_user_id: 2,
        initiated_by_user_id: 1, summary: 'handoff', next_action: 'lanjut',
        note: null, message_timestamp: '2026-10-02 10:01:00'
    };
    const m1 = msg(1, { message_timestamp: '2026-10-02 10:00:00' });
    const m2 = msg(2, { message_timestamp: '2026-10-02 10:02:00' });
    const tmap = new Map([
        [t.ctx.timelineKey(m2), m2], [t.ctx.timelineKey(h), h], [t.ctx.timelineKey(m1), m1]
    ]);
    const ordered = Array.from(t.ctx.urutkanPesan(tmap));
    assert.deepEqual(ordered.map(x => t.ctx.timelineKey(x)), ['1', 'handoff:8', '2']);
    poll(t, [m1, m2]);
    assert.equal(t.get('pesanCached[1].id'), 1);
    assert.equal(t.get('pesanCached[2].id'), 2);
});

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
    const bubble = (id) => t.container.children.find((c) => c.dataset.id === id);
    poll(t, [msg(1), msg(2), msg(3)], true);
    const before = [...t.container.children];
    assert.deepEqual(ids(t.container), ['1', '2', '3']);

    poll(t, [msg(1), msg(2), msg(3)], false);
    assert.equal(t.container.children.length, before.length);
    before.forEach((el, i) => assert.equal(t.container.children[i], el, 'same data: same nodes, same order'));

    poll(t, [msg(1), msg(2), msg(3), msg(4)], false);
    assert.deepEqual(ids(t.container), ['1', '2', '3', '4']);
    before.forEach((el, i) => assert.equal(t.container.children[i], el, 'old nodes not replaced'));

    poll(t, [msg(1), msg(2, { text: 'diubah' }), msg(3), msg(4)], false);
    assert.equal(bubble('1'), before.find((c) => c.dataset.id === '1'));
    assert.notEqual(bubble('2'), before.find((c) => c.dataset.id === '2'), 'only the changed bubble is replaced');
    assert.equal(bubble('3'), before.find((c) => c.dataset.id === '3'));
    assert.deepEqual(ids(t.container), ['1', '2', '3', '4'], 'order is kept after a replace');
});

test('AC-10 on the DOM: removal, late message in the middle, empty state, clean switch', () => {
    const t = loadThread();
    poll(t, [msg(1), msg(3)], true);
    poll(t, [msg(1), msg(2), msg(3)], false);
    assert.deepEqual(ids(t.container), ['1', '2', '3'], 'a late message is inserted at its position');
    poll(t, [msg(3)], false);
    assert.deepEqual(ids(t.container), ['3']);
    poll(t, [], false);
    assert.ok(t.container.querySelector('.inbox-thread-empty'));
    poll(t, [msg(20), msg(21)], true);
    assert.deepEqual(ids(t.container), ['20', '21'], 'the empty-state placeholder is gone');
    t.ctx.resetThread('<div class="inbox-thread-empty">x</div>');
    poll(t, [msg(30)], true);
    assert.deepEqual(ids(t.container), ['30']);
});

test('AC-11: auto-scroll only within 80px of the bottom (or when forced)', () => {
    const t = loadThread();
    const c = t.container;
    c.scrollHeight = 1000; c.clientHeight = 400;
    c.scrollTop = 0; // reading old messages, far from the bottom
    poll(t, [msg(1)], false);
    assert.equal(c.scrollTop, 0, 'a reader scrolled up is not dragged down');
    c.scrollTop = 530; // 70px from the bottom
    poll(t, [msg(1), msg(2)], false);
    assert.equal(c.scrollTop, 1000, 'near the bottom: follow');
    c.scrollTop = 0;
    poll(t, [msg(1), msg(2)], true);
    assert.equal(c.scrollTop, 1000, 'forced scroll');
});

test('AC-12: the bubble shown right after sending is not duplicated by polling', () => {
    const t = loadThread();
    poll(t, [msg(1)], true);
    const sent = msg(2, { direction: 'outgoing', send_status: 'sent', sender_name: 'Rina' });
    t.ctx.tampilkanBubbleOutgoing(sent);
    assert.deepEqual(ids(t.container), ['1', '2']);
    const shown = t.container.children[1];

    poll(t, [msg(1), sent], false);
    assert.deepEqual(ids(t.container), ['1', '2'], 'no duplicate');
    assert.equal(t.container.children[1], shown, 'same data: the shown bubble is kept');

    poll(t, [msg(1), { ...sent, text: 'dari server' }], false);
    assert.deepEqual(ids(t.container), ['1', '2'], 'different data: replaced, still one bubble');
});

test('AC-12: sending in an empty conversation clears the placeholder', () => {
    const t = loadThread();
    poll(t, [], true);
    t.ctx.tampilkanBubbleOutgoing(msg(5, { direction: 'outgoing', send_status: 'sent' }));
    assert.deepEqual(ids(t.container), ['5']);
});

test('AC-13: pesanCached holds exactly the displayed messages (old and new)', () => {
    const t = loadThread();
    poll(t, [msg(1), msg(2)], true);
    assert.deepEqual(Object.keys(t.get('pesanCached')).sort(), ['1', '2']);
    poll(t, [msg(2), msg(3)], false);
    assert.deepEqual(Object.keys(t.get('pesanCached')).sort(), ['2', '3'], 'message 1 left the window');
    t.ctx.tampilkanBubbleOutgoing(msg(4, { direction: 'outgoing', send_status: 'sent' }));
    assert.ok(t.get('pesanCached')['4']);
});


// ---- P6 text format + autolink (AC-20..AC-22, AC-31) ----------------------
test('AC-20: WhatsApp markers', () => {
    const f = loadThread().ctx.formatTeksWa;
    assert.equal(f('*tebal*'), '<strong>tebal</strong>');
    assert.equal(f('_miring_'), '<em>miring</em>');
    assert.equal(f('~coret~'), '<del>coret</del>');
    assert.equal(f('a `kode` b'), 'a <code>kode</code> b');
    assert.equal(f('```blok\nkode```'), '<pre class="inbox-kode-blok">blok\nkode</pre>');
    assert.equal(f('*_dua_*'), '<strong><em>dua</em></strong>', 'markers nest');
    assert.equal(f('*a* dan *b*'), '<strong>a</strong> dan <strong>b</strong>', 'two spans on one line');
    assert.equal(f('halo, *tebal*!'), 'halo, <strong>tebal</strong>!', 'punctuation around is fine');
});

test('AC-20/AC-22: markers must hug the text; unpaired or word-internal markers are left alone', () => {
    const f = loadThread().ctx.formatTeksWa;
    for (const same of ['2*3*4', 'snake_case_name', '* bukan *', '*', '**', '*tebal', 'tebal*', '_ _', 'a ~ b ~ c', 'teks biasa tanpa penanda', '* spasi di dalam*']) {
        assert.equal(f(same), same, same);
    }
    assert.equal(f('*a b*'), '<strong>a b</strong>', 'spaces inside are fine, only the edges matter');
});

test('AC-20: code is not formatted and keeps its content', () => {
    const f = loadThread().ctx.formatTeksWa;
    assert.equal(f('`*bukan tebal*`'), '<code>*bukan tebal*</code>');
    assert.equal(f('```_x_ *y*```'), '<pre class="inbox-kode-blok">_x_ *y*</pre>');
    assert.equal(f('*`kode`*'), '<strong><code>kode</code></strong>');
});

test('AC-21: escape happens BEFORE formatting (XSS vectors stay text)', () => {
    const f = loadThread().ctx.formatTeksWa;
    for (const bad of ['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '*<img src=x onerror=alert(1)>*', '_<b onmouseover=1>x</b>_', '`<script>`', '```<svg onload=alert(1)>```', '~<a href=javascript:alert(1)>x</a>~']) {
        const html = f(bad);
        assert.ok(!/<(script|img|svg|b|a)[\s>]/.test(html.replace(/<(strong|em|del|code|pre)[^>]*>|<\/(strong|em|del|code|pre)>/g, '')), bad + ' -> ' + html);
        assert.ok(html.includes('&lt;'), html);
    }
    // the placeholder delimiter cannot be forged from the input
    assert.equal(f('\u00000\u0000 *x*'), '0 <strong>x</strong>');
});

test('AC-31: only http(s) URLs become links, after escaping', () => {
    const f = loadThread().ctx.formatTeksWa;
    assert.equal(f('lihat https://example.com/a?x=1&y=2 ya'), 'lihat <a href="https://example.com/a?x=1&amp;y=2" target="_blank" rel="noopener noreferrer">https://example.com/a?x=1&amp;y=2</a> ya');
    assert.equal(f('buka http://a.co.'), 'buka <a href="http://a.co" target="_blank" rel="noopener noreferrer">http://a.co</a>.', 'trailing punctuation stays outside');
    assert.equal(f('<https://a.co>'), '&lt;<a href="https://a.co" target="_blank" rel="noopener noreferrer">https://a.co</a>&gt;');
    for (const no of ['javascript:alert(1)', 'data:text/html,x', 'ftp://x.y', 'www.example.com']) assert.ok(!f(no).includes('<a '), no);
    assert.ok(f('https://x.co/"onmouseover="alert(1)').includes('href="https://x.co/"'), 'a quote ends the URL, no attribute injection');
    assert.ok(!f('https://x.co/"onmouseover="alert(1)').includes('" onmouseover'), 'no injected attribute');
    assert.equal(f('https://x.co/a_b_c'), '<a href="https://x.co/a_b_c" target="_blank" rel="noopener noreferrer">https://x.co/a_b_c</a>', 'markers inside a URL are not formatted');
});

test('captions and text use the formatter, quote snippets do not', () => {
    const t = loadThread();
    assert.ok(t.ctx.renderBubbleHtml(msg(1, { text: '*halo*' })).includes('<strong>halo</strong>'));
    assert.ok(t.ctx.renderBubbleHtml(msg(2, { message_type: 'image', media_local_filename: 'a.png', text: '_cap_' })).includes('<em>cap</em>'));
    assert.ok(t.ctx.renderBubbleHtml(msg(3, { message_type: 'document', text: '~cap~' })).includes('<del>cap</del>'));
    const quoted = t.ctx.renderBubbleHtml(msg(4, { quoted_wa_message_id: 'Q', quoted_sender_label: 'A', quoted_snippet: '*snippet*' }));
    assert.ok(quoted.includes('*snippet*') && !quoted.includes('<strong>snippet'), 'snippet stays plain');
});

// ---- P4 dates ----------------------------------------------------------
test('AC-14: label "Hari ini", "Kemarin", or the full date', () => {
    const t = loadThread();
    const now = new Date(2026, 9, 2, 12, 0, 0);
    assert.equal(t.ctx.labelTanggal('2026-10-02 00:05:00', now), 'Hari ini');
    assert.equal(t.ctx.labelTanggal('2026-10-01 23:59:00', now), 'Kemarin');
    assert.equal(t.ctx.labelTanggal('2026-09-25 10:00:00', now), '25 September 2026');
    assert.equal(t.ctx.kunciHari('2026-10-02 10:00:00'), '2026-10-02');
    assert.equal(t.ctx.kunciHari('bukan tanggal'), '');
});

test('AC-14/AC-15/AC-16: a separator before the first message and before each new day, never counted as a message', () => {
    const t = loadThread();
    const now = new Date(2026, 9, 2, 12, 0, 0);
    const day = (d, h, id) => msg(id, { message_timestamp: `2026-10-0${d} 0${h}:00:00` });
    const items = Array.from(t.ctx.susunItemThread([day(1, 8, 1), day(1, 9, 2), day(2, 8, 3)], { now, olderHasMore: false }), (i) => i.key);
    assert.deepEqual(items, ['tgl:2026-10-01', '1', '2', 'tgl:2026-10-02', '3']);
    // same day again: no extra separator; next day: exactly one more
    const more = Array.from(t.ctx.susunItemThread([day(1, 8, 1), day(2, 8, 3), day(2, 9, 4)], { now, olderHasMore: false }), (i) => i.key);
    assert.deepEqual(more, ['tgl:2026-10-01', '1', 'tgl:2026-10-02', '3', '4']);
    // an unparsable timestamp does not start a day
    assert.deepEqual(Array.from(t.ctx.susunItemThread([msg(9, { message_timestamp: 'x' })], { now, olderHasMore: false }), (i) => i.key), ['9']);

    poll(t, [day(1, 8, 1), day(2, 8, 3)], true);
    assert.deepEqual(Object.keys(t.get('pesanCached')).sort(), ['1', '3'], 'separators are not in pesanCached');
    assert.equal(t.container.children.filter((c) => c.className === 'inbox-tanggal').length, 2);
});

// ---- P1b merge / pagination (AC-10, AC-23, AC-27) ------------------------
// strictly increasing timestamps, one second per id (rolls over minutes and hours)
const at = (n) => ({ message_timestamp: new Date(Date.UTC(2026, 9, 1, 0, 0, n)).toISOString().slice(0, 19).replace('T', ' ') });
const range = (a, b) => Array.from({ length: b - a + 1 }, (_, i) => msg(a + i, at(a + i)));
const keys = (map) => Array.from(map.keys()).sort((x, y) => x - y);

test('merge: window slides forward but messages that left it are kept; deleted ones in range are dropped', () => {
    const t = loadThread();
    const known = new Map(range(1, 10).map((m) => [String(m.id), m]));
    // latest window now 4..11 (has_more true): 1..3 are older than the window, kept; 5 was deleted on the server
    const latest = range(4, 11).filter((m) => m.id !== 5);
    const merged = t.ctx.gabungPesanThread(known, latest, true);
    assert.deepEqual(keys(merged), ['1', '2', '3', '4', '6', '7', '8', '9', '10', '11']);
});

test('merge: when the window covers everything (has_more false) a missing message is deleted; empty response empties all', () => {
    const t = loadThread();
    const known = new Map(range(1, 5).map((m) => [String(m.id), m]));
    assert.deepEqual(keys(t.ctx.gabungPesanThread(known, range(2, 5), false)), ['2', '3', '4', '5']);
    assert.equal(t.ctx.gabungPesanThread(known, [], false).size, 0);
});

test('AC-10: switching conversation clears everything first', () => {
    const t = loadThread();
    t.ctx.terimaPesanTerbaru(1, { messages: range(1, 3), has_more: false }, true);
    assert.deepEqual(ids(t.container), ['1', '2', '3']);
    t.ctx.terimaPesanTerbaru(2, { messages: [msg(50, at(50))], has_more: false }, true);
    assert.deepEqual(ids(t.container), ['50']);
    assert.deepEqual(Object.keys(t.get('pesanCached')), ['50']);
});

function fakeServer(t, all, pageSize = 200) {
    // behaves like GET .../messages?before_id=: the pageSize messages just before the cursor
    const calls = [];
    t.ctx.fetch = (url) => {
        calls.push(url);
        const m = url.match(/before_id=(\d+)/);
        const before = m ? all.filter((x) => x.id < Number(m[1])) : all;
        const page = before.slice(-pageSize);
        return Promise.resolve({ json: () => Promise.resolve({ status: 'success', messages: page, has_more: before.length > pageSize }) });
    };
    return calls;
}

test('AC-27: "load older" fetches before the oldest shown, prepends, keeps the reading position, and polling keeps older pages', async () => {
    const t = loadThread();
    const all = range(1, 450);
    const calls = fakeServer(t, all);
    t.container.scrollHeight = 1000; t.container.clientHeight = 400;
    poll(t, all.slice(-200), true, true); // latest window 251..450, more exists
    assert.ok(t.container.children[0].className.includes('inbox-muat-lama') || t.container.children[0].html.includes('Muat pesan lama'), 'the load-older button is the first item');

    t.container.scrollTop = 0;
    const heightAfter = 1000 + 800; // content grew by 800px at the top
    const realRender = t.ctx.renderPesan;
    t.ctx.renderPesan = (...a) => { realRender(...a); t.container.scrollHeight = a[0].length > 200 ? heightAfter : 1000; };
    const ok = await t.ctx.muatPesanLama();
    assert.equal(ok, true);
    assert.ok(calls[0].endsWith('/1/messages?before_id=251'), calls[0]);
    assert.equal(ids(t.container).length, 400, '200 latest + 200 older');
    assert.equal(ids(t.container)[0], '51');
    assert.equal(t.container.scrollTop, 800, 'scrollTop grows by the height added on top (reader stays on the same message)');

    // polling afterwards returns only the latest window: the older page stays
    t.ctx.renderPesan = realRender;
    poll(t, all.slice(-200), false, true);
    assert.equal(ids(t.container).length, 400, 'polling does not remove loaded older messages');

    // the last older page: has_more false -> the button goes away
    const ok2 = await t.ctx.muatPesanLama();
    assert.equal(ok2, true);
    assert.equal(ids(t.container).length, 450);
    assert.ok(!t.container.children.some((c) => c.html.includes('Muat pesan lama')), 'button hidden when nothing older is left');
});

test('AC-26: a window that already holds everything shows no "load older" button', () => {
    const t = loadThread();
    poll(t, range(1, 5), true, false);
    assert.ok(!t.container.children.some((c) => c.html.includes('Muat pesan lama')));
});

test('AC-27: a failed load shows a toast, keeps the thread and allows a retry', async () => {
    const t = loadThread();
    const toasts = [];
    t.ctx.showToast = (m, j) => toasts.push(m + '|' + j);
    poll(t, range(1, 5), true, true);
    t.ctx.fetch = () => Promise.reject(new Error('offline'));
    assert.equal(await t.ctx.muatPesanLama(), false);
    assert.deepEqual(toasts, ['Gagal memuat pesan lama.|warning']);
    assert.equal(ids(t.container).length, 5);
    assert.equal(t.get('threadMemuatLama'), false, 'loading flag is reset');
});

test('a second "load older" while one is running is ignored', async () => {
    const t = loadThread();
    const calls = fakeServer(t, range(1, 450));
    poll(t, range(251, 450), true, true);
    const [a, b] = [t.ctx.muatPesanLama(), t.ctx.muatPesanLama()];
    assert.deepEqual([await a, await b], [true, false]);
    assert.equal(calls.length, 1);
});

// ---- P5 clickable quote (AC-17..AC-19) -----------------------------------
test('AC-19: "Pesan tidak ditemukan" is not a button; others are', () => {
    const t = loadThread();
    const html = (extra) => t.ctx.renderBubbleHtml(msg(1, { quoted_wa_message_id: 'Q1', quoted_snippet: 's', ...extra }));
    assert.ok(!html({ quoted_sender_label: null }).includes('data-kutipan-wa'));
    const ok = html({ quoted_sender_label: 'Pelanggan' });
    assert.ok(ok.includes('data-kutipan-wa="Q1"') && ok.includes('onclick="loncatKeKutipan(this)"') && ok.includes('role="button"'));
    assert.ok(html({ quoted_sender_label: 'A', quoted_wa_message_id: 'x" onclick="evil()' }).includes('data-kutipan-wa="x&quot; onclick=&quot;evil()"'), 'the id is attribute-escaped');
    assert.ok(html({ quoted_sender_label: 'A', quoted_media_available: 1, quoted_media_type: 'document', quoted_source_message_id: 3 }).includes('onclick="event.stopPropagation()"'), 'the document link does not trigger the jump');
});

test('AC-17: clicking a quote scrolls to the source bubble and highlights it', async () => {
    const t = loadThread();
    poll(t, [msg(1, { wa_message_id: 'SRC' }), msg(2), msg(3, { quoted_wa_message_id: 'SRC', quoted_sender_label: 'A', quoted_snippet: 's' })], true);
    const toasts = []; t.ctx.showToast = (m) => toasts.push(m);
    const source = t.container.children.find((c) => c.dataset.id === '1');
    const ok = await t.ctx.loncatKeKutipan({ getAttribute: () => 'SRC' });
    assert.equal(ok, true);
    assert.equal(source.scrolledIntoView, 1);
    assert.ok(source.classes.has('inbox-bubble-sorot'));
    assert.deepEqual(toasts, []);
});

test('AC-18: a source that is not loaded is searched page by page (max 5), then a toast', async () => {
    const t = loadThread();
    const toasts = []; t.ctx.showToast = (m, j) => toasts.push(m + '|' + j);
    const all = range(1, 1500).map((m) => (m.id === 100 ? { ...m, wa_message_id: 'OLD' } : m));
    const calls = fakeServer(t, all, 200);
    poll(t, all.slice(-200), true, true);
    // 100 is 5 pages back from 1301..1500 (1101-1300, 901-1100, 701-900, 501-700, 301-500, 101-300 => page 6+)
    assert.equal(await t.ctx.loncatKeKutipan({ getAttribute: () => 'OLD' }), false);
    assert.equal(calls.length, 5, 'stops after the page limit');
    assert.deepEqual(toasts, ['Pesan asal tidak ada di riwayat yang dimuat|warning']);

    // within reach: loads until found and jumps
    const t2 = loadThread();
    const all2 = range(1, 600).map((m) => (m.id === 300 ? { ...m, wa_message_id: 'NEAR' } : m));
    fakeServer(t2, all2, 200);
    poll(t2, all2.slice(-200), true, true);
    assert.equal(await t2.ctx.loncatKeKutipan({ getAttribute: () => 'NEAR' }), true);
    assert.equal(t2.container.children.find((c) => c.dataset.id === '300').scrolledIntoView, 1);
});

test('AC-18: no older history and no source -> toast, no error', async () => {
    const t = loadThread();
    const toasts = []; t.ctx.showToast = (m) => toasts.push(m);
    poll(t, range(1, 3), true, false);
    assert.equal(await t.ctx.loncatKeKutipan({ getAttribute: () => 'HILANG' }), false);
    assert.equal(toasts.length, 1);
    assert.equal(await t.ctx.loncatKeKutipan({ getAttribute: () => null }), false);
});

// ---- P7 visuals (AC-29, AC-30) -------------------------------------------
test('AC-30: one tick for sent, double tick for delivered, blue double tick for read, "!" for failed', () => {
    const t = loadThread();
    const out = (extra) => t.ctx.renderBubbleHtml(msg(1, { direction: 'outgoing', ...extra }));
    assert.ok(out({ send_status: 'sent' }).includes('fa-check inbox-centang'));
    assert.ok(out({ send_status: 'failed' }).includes('inbox-gagal'));
    assert.ok(!out({ send_status: 'received' }).includes('inbox-centang'));
    assert.ok(!out({ send_status: 'sent', is_internal: true }).includes('inbox-centang'));
    assert.ok(!t.ctx.renderBubbleHtml(msg(2)).includes('inbox-centang'));

    // WhatsApp read receipt: delivered_at -> centang ganda (bukan biru).
    const delivered = out({ send_status: 'sent', delivered_at: '2026-10-07 10:00:00' });
    assert.ok(delivered.includes('fa-check-double inbox-centang'), 'delivered -> double tick');
    assert.ok(!delivered.includes('inbox-centang-read'), 'delivered bukan read');

    // read_at -> centang ganda biru (read menang atas delivered).
    const read = out({ send_status: 'sent', delivered_at: '2026-10-07 10:00:00', read_at: '2026-10-07 10:05:00' });
    assert.ok(read.includes('inbox-centang-read'), 'read -> blue double tick');

    // sent tanpa delivered/read tetap satu centang (tanpa ganda).
    assert.ok(!out({ send_status: 'sent' }).includes('fa-check-double'), 'sent tanpa receipt tetap satu centang');
});

test('AC-29: new messages while reading upward are counted and shown on the button; reaching the bottom clears it', () => {
    const t = loadThread();
    const c = t.container;
    c.scrollHeight = 2000; c.clientHeight = 400;
    poll(t, range(1, 5), true);
    assert.equal(t.button.style.display, 'none');

    c.scrollTop = 200; // far from the bottom: reading
    poll(t, range(1, 7), false);
    assert.equal(c.scrollTop, 200, 'not dragged down');
    assert.equal(t.button.style.display, 'flex');
    assert.equal(t.counter.textContent, '2');
    poll(t, range(1, 8), false);
    assert.equal(t.counter.textContent, '3', 'counts accumulate');
    poll(t, range(1, 8), false);
    assert.equal(t.counter.textContent, '3', 'a poll without news does not change the count');

    t.ctx.gulungKeTerbaru();
    assert.equal(c.scrollTop, 2000);
    assert.equal(t.button.style.display, 'none');

    // at the bottom there is nothing to announce
    poll(t, range(1, 9), false);
    assert.equal(t.button.style.display, 'none');
});

test('AC-29: loading older messages does not count as news', async () => {
    const t = loadThread();
    fakeServer(t, range(1, 450));
    t.container.scrollHeight = 2000; t.container.clientHeight = 400;
    poll(t, range(251, 450), true, true);
    t.container.scrollTop = 0;
    await t.ctx.muatPesanLama();
    assert.equal(t.button.style.display, 'none');
});

// ---- download of images / documents / stickers --------------------------
const imgMsg = (id, extra = {}) => msg(id, { message_type: 'image', text: '', media_mime_type: 'image/jpeg', ...extra });
const docMsg = (id, extra = {}) => msg(id, { message_type: 'document', text: '', media_filename: 'Nota Pesanan.pdf', media_size: 153600, ...extra });

test('DL-1: namaFileDariHeader prefers filename*, then filename, then the fallback', () => {
    const t = loadThread();
    assert.equal(t.ctx.namaFileDariHeader("attachment; filename=\"Nota _.pdf\"; filename*=UTF-8''Nota%20%C3%A9.pdf", 'x'), 'Nota é.pdf');
    assert.equal(t.ctx.namaFileDariHeader('attachment; filename="a.jpg"', 'x'), 'a.jpg');
    assert.equal(t.ctx.namaFileDariHeader(null, 'media-5'), 'media-5');
    assert.equal(t.ctx.namaFileDariHeader("attachment; filename*=UTF-8''%E0%A4%A", 'media-5'), 'media-5', 'bad encoding falls back');
});

test('DL-2: formatUkuranFile and ikonDokumen', () => {
    const t = loadThread();
    assert.equal(t.ctx.formatUkuranFile(null), '');
    assert.equal(t.ctx.formatUkuranFile(500), '500 B');
    assert.equal(t.ctx.formatUkuranFile(153600), '150 KB');
    assert.equal(t.ctx.formatUkuranFile(2621440), '2,5 MB');
    assert.equal(t.ctx.ikonDokumen('a.PDF'), 'fa-file-pdf');
    assert.equal(t.ctx.ikonDokumen('a.xlsx'), 'fa-file-excel');
    assert.equal(t.ctx.ikonDokumen('tanpa-ekstensi'), 'fa-file-alt');
});

test('DL-3: document renders a file card with name, type/size and a download button (no bare link)', () => {
    const t = loadThread();
    const html = t.ctx.renderBubbleHtml(docMsg(21));
    assert.ok(html.includes('inbox-media-document-nama">Nota Pesanan.pdf<'), html);
    assert.ok(html.includes('PDF · 150 KB'), html);
    assert.ok(html.includes('fa-file-pdf'), html);
    assert.ok(html.includes('unduhSatu(21, this)'), html);
    assert.ok(!html.includes('target="_blank"'), 'no bare link that opens a JSON tab on error');
    const kosong = t.ctx.renderBubbleHtml(docMsg(22, { media_filename: '<b>x</b>', media_size: null }));
    assert.ok(!kosong.includes('<b>x</b>'), 'file name is escaped');
    assert.ok(!kosong.includes('inbox-media-document-meta">PDF'), 'no meta line without size');
});

test('DL-4: image opens the lightbox on click and has a hover download button; sticker has no lightbox', () => {
    const t = loadThread();
    const img = t.ctx.renderBubbleHtml(imgMsg(30));
    assert.ok(img.includes('onclick="bukaLightbox(30)"'), img);
    assert.ok(img.includes('unduhSatu(30, this)'), img);
    const sticker = t.ctx.renderBubbleHtml(msg(31, { message_type: 'sticker' }));
    assert.ok(!sticker.includes('bukaLightbox'), sticker);
    assert.ok(sticker.includes('unduhSatu(31, this)'), sticker);
});

test('DL-5: select mode shows checkboxes only on downloadable media, hides Balas/Teruskan, and survives re-render', () => {
    const t = loadThread();
    poll(t, [imgMsg(40), docMsg(41), msg(42)], true);
    t.ctx.alihkanModePilih(true);
    const html = (id) => t.run(`renderBubbleHtml(threadDikenal.get('${id}'))`);
    assert.ok(html(40).includes('type="checkbox"') && !html(40).includes('bubble-aksi') && !html(40).includes('bukaLightbox'));
    assert.ok(html(41).includes('type="checkbox"'));
    assert.ok(html(40).includes('aria-label="Pilih untuk diunduh"') && !html(40).includes('> Pilih'), 'icon-only checkbox with an accessible label');
    assert.ok(/^<div class="inbox-bubble incoming inbox-bubble-pilih"/.test(html(40)), 'direction class stays so CSS can place the checkbox on the empty side');
    assert.ok(!html(42).includes('type="checkbox"'), 'text message cannot be selected');
    t.ctx.alihkanPilihan(40, true);
    assert.ok(html(40).includes('checked'), 'selection is part of the bubble HTML');
    poll(t, [imgMsg(40), docMsg(41), msg(42)], false);
    assert.ok(t.container.children.some((c) => c.dataset.id === '40' && c.html.includes('checked')), 'poll keeps the selection');
    t.ctx.alihkanModePilih(false);
    assert.ok(html(40).includes('bubble-aksi') && !html(40).includes('type="checkbox"'));
    assert.equal(t.get('pilihanUnduh.size'), 0, 'leaving select mode clears the selection');
});

test('DL-5b: in select mode, clicking the image/sticker/document itself toggles selection (not just the checkbox)', () => {
    const t = loadThread();
    poll(t, [imgMsg(70), msg(71, { message_type: 'sticker' }), docMsg(72)], true);
    t.ctx.alihkanModePilih(true);
    const html = (id) => t.run(`renderBubbleHtml(threadDikenal.get('${id}'))`);

    assert.match(html(70), /onclick="klikMediaPilih\(70\)"/, 'image click toggles selection in select mode');
    assert.ok(!html(70).includes('bukaLightbox'), 'lightbox is not opened while selecting');
    assert.match(html(71), /onclick="klikMediaPilih\(71\)"/, 'sticker click toggles selection in select mode');
    assert.match(html(72), /inbox-media-document" onclick="klikMediaPilih\(72\)"/, 'document card click toggles selection');

    t.ctx.klikMediaPilih(70);
    assert.ok(t.get("pilihanUnduh.has('70')"), 'clicking the image selected it');
    assert.ok(html(70).includes('checked'), 'the checkbox reflects the click');

    t.ctx.klikMediaPilih(70);
    assert.ok(!t.get("pilihanUnduh.has('70')"), 'clicking again deselects it');
});

test('DL-5c: outside select mode, clicking the image opens the lightbox and the document download button still works directly', () => {
    const t = loadThread();
    poll(t, [imgMsg(80), docMsg(81)], true);
    const html = (id) => t.run(`renderBubbleHtml(threadDikenal.get('${id}'))`);

    assert.match(html(80), /onclick="bukaLightbox\(80\)"/);
    assert.ok(!html(80).includes('klikMediaPilih'));
    assert.ok(!html(81).includes('onclick="klikMediaPilih'), 'document card has no click handler outside select mode');
    assert.match(html(81), /onclick="event\.stopPropagation\(\); unduhSatu\(81, this\)"/);
});

test('DL-6: switching conversation leaves select mode', () => {
    const t = loadThread();
    poll(t, [imgMsg(50)], true);
    t.ctx.alihkanModePilih(true);
    t.ctx.alihkanPilihan(50, true);
    t.ctx.terimaPesanTerbaru(2, { status: 'success', messages: [msg(60)], has_more: false }, true);
    assert.equal(t.get('modePilih'), false);
    assert.equal(t.get('pilihanUnduh.size'), 0);
});

test('DL-7: unduhBeruntun runs one by one in order with a pause, and counts failures per category', async () => {
    const t = loadThread();
    const urutan = [];
    const hasil = await t.ctx.unduhBeruntun([1, 2, 3, 4], (id) => {
        urutan.push('unduh' + id);
        return Promise.resolve(id === 2 ? { ok: false, kategori: 'kadaluarsa' } : id === 4 ? { ok: false, kategori: 'sementara' } : { ok: true });
    }, () => { urutan.push('jeda'); return Promise.resolve(); });
    assert.deepEqual(urutan, ['unduh1', 'jeda', 'unduh2', 'jeda', 'unduh3', 'jeda', 'unduh4']);
    assert.equal(hasil.berhasil, 2);
    assert.equal(hasil.gagal, 2);
    assert.deepEqual(JSON.parse(JSON.stringify(hasil.perKategori)), { kadaluarsa: 1, sementara: 1 });
    assert.equal(t.ctx.ringkasanUnduhan(hasil), '2 berhasil diunduh, 2 gagal (1 kadaluarsa, 1 Gateway belum terhubung).');
});

test('DL-8: unduhMedia requests ?unduh=1, saves with the server file name, and never rejects', async () => {
    const t = loadThread();
    const diklik = [];
    t.ctx.URL = { createObjectURL: () => 'blob:x', revokeObjectURL: () => {} };
    t.ctx.document.body = { appendChild: () => {} };
    t.ctx.document.createElement = () => ({ style: {}, click() { diklik.push(this.download); }, remove() {} });
    const diminta = [];
    t.ctx.fetch = (url) => {
        diminta.push(url);
        return Promise.resolve({ ok: true, status: 200, headers: { get: () => "attachment; filename=\"a\"; filename*=UTF-8''foto%201.jpg" }, blob: () => Promise.resolve({}) });
    };
    const ok = await t.ctx.unduhMedia(7);
    assert.deepEqual(diminta, ['/inbox/media/7?unduh=1']);
    assert.deepEqual(diklik, ['foto 1.jpg']);
    assert.equal(ok.ok, true);

    const toasts = [];
    t.ctx.showToast = (teks, jenis) => toasts.push([teks, jenis]);
    t.ctx.fetch = () => Promise.resolve({ ok: false, status: 410 });
    const gagal = await t.ctx.unduhMedia(7);
    assert.deepEqual([gagal.ok, gagal.kategori], [false, 'kadaluarsa']);
    assert.equal(toasts.length, 1);
    assert.match(toasts[0][0], /kadaluarsa/);

    t.ctx.fetch = () => Promise.reject(new Error('jaringan'));
    const mati = await t.ctx.unduhMedia(7, { senyap: true });
    assert.equal(mati.kategori, 'sementara');
    assert.equal(toasts.length, 1, 'senyap suppresses the toast');
});
