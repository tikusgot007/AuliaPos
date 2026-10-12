// Tests for public/assets/js/inbox-emoji.js (AC-EP-1..5 of
// docs/requirements/2026-10-12-reaksi-masuk-dan-emoji-picker-inbox.md).
// Run: node tests/js/inbox-emoji.test.js (Node.js built-in test runner)
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function buatTextarea(value, start, end) {
    return {
        value,
        disabled: false,
        selectionStart: start,
        selectionEnd: end,
        focused: false,
        focus() { this.focused = true; },
        // Same semantics as HTMLTextAreaElement.setRangeText(..., 'end').
        setRangeText(teks, awal, akhir, mode) {
            this.value = this.value.slice(0, awal) + teks + this.value.slice(akhir);
            if (mode === 'end') this.selectionStart = this.selectionEnd = awal + teks.length;
        },
    };
}

function loadEmoji(textarea) {
    const listeners = { document: {}, panel: {} };
    const panel = {
        style: { display: 'none' },
        innerHTML: '',
        addEventListener: (ev, fn) => { listeners.panel[ev] = fn; },
        contains: (el) => el === panel || (el && el.dalamPanel === true),
    };
    const btnEmoji = { contains: (el) => el === btnEmoji };
    const elements = { panelEmoji: panel, teksBalasan: textarea, btnEmojiBalasan: btnEmoji };
    const panggilan = { operasi: 0, status: 0, resize: 0, fetch: 0 };

    const ctx = vm.createContext({
        document: {
            getElementById: (id) => elements[id] || null,
            addEventListener: (ev, fn) => { listeners.document[ev] = fn; },
        },
        fetch: () => { panggilan.fetch++; return Promise.reject(new Error('no network')); },
        buangOperationIdBalasan: () => { panggilan.operasi++; },
        sembunyikanStatusKirimBalasan: () => { panggilan.status++; },
        autoResizeTeksBalasan: () => { panggilan.resize++; },
        Array, Object, String, Boolean,
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/assets/js/inbox-emoji.js'), 'utf8'), ctx);
    return { ctx, panel, btnEmoji, listeners, panggilan };
}

test('AC-EP-1: toggle membuka panel berisi emoji tanpa mengirim apa pun', () => {
    const { ctx, panel, panggilan } = loadEmoji(buatTextarea('', 0, 0));
    ctx.toggleEmojiPanel();
    assert.equal(panel.style.display, 'block');
    assert.match(panel.innerHTML, /data-emoji="🙏"/);
    assert.equal(panggilan.operasi, 0, 'membuka panel tidak mengubah draft');
});

test('AC-EP-2: emoji disisipkan di posisi kursor, bukan mengganti isi', () => {
    const ta = buatTextarea('halo kak', 4, 4);
    const { ctx } = loadEmoji(ta);
    ctx.sisipkanEmoji('🙏');
    assert.equal(ta.value, 'halo🙏 kak');
    assert.equal(ta.selectionStart, 4 + '🙏'.length, 'kursor pindah ke setelah emoji');
    assert.equal(ta.focused, true);
});

test('AC-EP-2: teks yang terseleksi diganti emoji, sisanya utuh', () => {
    const ta = buatTextarea('terima kasih', 7, 12);
    const { ctx } = loadEmoji(ta);
    ctx.sisipkanEmoji('🙏');
    assert.equal(ta.value, 'terima 🙏');
});

test('AC-EP-2: klik emoji di panel menyisipkan emoji itu', () => {
    const ta = buatTextarea('', 0, 0);
    const { listeners } = loadEmoji(ta);
    const tombol = { getAttribute: () => '👍' };
    listeners.panel.click({ target: { closest: () => tombol } });
    assert.equal(ta.value, '👍');
});

test('AC-EP-2: composer nonaktif tidak diubah', () => {
    const ta = buatTextarea('x', 1, 1);
    ta.disabled = true;
    const { ctx } = loadEmoji(ta);
    ctx.sisipkanEmoji('👍');
    assert.equal(ta.value, 'x');
});

test('AC-EP-3: tombol lagi, Esc, dan klik di luar menutup panel', () => {
    const { ctx, panel, btnEmoji, listeners } = loadEmoji(buatTextarea('', 0, 0));
    ctx.toggleEmojiPanel();
    ctx.toggleEmojiPanel();
    assert.equal(panel.style.display, 'none', 'toggle kedua menutup');

    ctx.toggleEmojiPanel();
    listeners.document.keydown({ key: 'Escape' });
    assert.equal(panel.style.display, 'none', 'Esc menutup');

    ctx.toggleEmojiPanel();
    listeners.document.click({ target: btnEmoji });
    assert.equal(panel.style.display, 'block', 'klik tombol emoji tidak ditutup oleh handler luar');
    listeners.document.click({ target: { dalamPanel: true } });
    assert.equal(panel.style.display, 'block', 'klik di dalam panel tidak menutup');
    listeners.document.click({ target: {} });
    assert.equal(panel.style.display, 'none', 'klik di luar menutup');
});

test('AC-EP-4: panel memakai daftar lokal, tanpa permintaan jaringan', () => {
    const { ctx, panggilan } = loadEmoji(buatTextarea('', 0, 0));
    ctx.toggleEmojiPanel();
    assert.equal(panggilan.fetch, 0);
    assert.ok(ctx.DAFTAR_EMOJI_INBOX.length > 0);
});

test('AC-EP-5: sisip emoji membuang operation_id lama dan teks tidak diubah', () => {
    const ta = buatTextarea('ok', 2, 2);
    const { ctx, panggilan } = loadEmoji(ta);
    ctx.sisipkanEmoji('❤️');
    assert.equal(ta.value, 'ok❤️', 'emoji multi-codepoint disimpan apa adanya');
    assert.equal(panggilan.operasi, 1);
    assert.equal(panggilan.status, 1);
    assert.equal(panggilan.resize, 1);
});
