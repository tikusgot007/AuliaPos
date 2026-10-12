// Composer emoji picker (docs/requirements/2026-10-12-reaksi-masuk-dan-emoji-picker-inbox.md,
// AC-EP-1..5). Static curated list, no network and no dependency, so it works
// on servers without internet. Inserting only fills the composer; sending still
// goes through kirimBalasan().
//
// Globals from inbox-page.js (plain <script>, same page): buangOperationIdBalasan,
// sembunyikanStatusKirimBalasan, autoResizeTeksBalasan.
//
// Run test: node tests/js/inbox-emoji.test.js
// ponytail: curated subset without search; upgrade path: vendored full picker.

var DAFTAR_EMOJI_INBOX = [
    { judul: 'Sering dipakai', emoji: ['🙏', '👍', '😊', '🙂', '😁', '😂', '🤣', '😅', '😍', '🥰', '😘', '🤗', '👌', '👏', '🙌', '💪', '❤️', '🔥', '✅', '🎉'] },
    { judul: 'Wajah', emoji: ['😀', '😃', '😄', '😆', '😉', '😋', '😎', '🤩', '🥳', '😇', '🤔', '🤭', '🤫', '😌', '😴', '🥺', '😢', '😭', '😤', '😱', '😳', '🙄', '😬', '🤐'] },
    { judul: 'Tangan', emoji: ['👋', '🤝', '✌️', '🤞', '👆', '👇', '👉', '👈', '☝️', '✋', '🤲', '👎'] },
    { judul: 'Toko & lainnya', emoji: ['📦', '🛒', '🛍️', '💰', '💵', '💳', '🧾', '📝', '📌', '📍', '⏰', '📅', '📞', '📷', '🎁', '⭐', '✨', '💯', '⚠️', '❌', '❓', '❗', '➡️', '🌹'] }
];

var emojiPanelSudahDirender = false;

function renderPanelEmoji() {
    const panel = document.getElementById('panelEmoji');
    if (!panel || emojiPanelSudahDirender) return;
    panel.innerHTML = DAFTAR_EMOJI_INBOX.map(function(grup) {
        return '<div class="inbox-emoji-judul">' + grup.judul + '</div>' +
            '<div class="inbox-emoji-grid">' +
            grup.emoji.map(function(e) {
                return '<button type="button" class="inbox-emoji-item" data-emoji="' + e + '" aria-label="' + e + '">' + e + '</button>';
            }).join('') +
            '</div>';
    }).join('');
    emojiPanelSudahDirender = true;
}

function panelEmojiTerbuka() {
    const panel = document.getElementById('panelEmoji');
    return Boolean(panel) && panel.style.display === 'block';
}

function toggleEmojiPanel() {
    const panel = document.getElementById('panelEmoji');
    if (!panel) return;
    if (panelEmojiTerbuka()) {
        tutupEmojiPanel();
        return;
    }
    renderPanelEmoji();
    panel.style.display = 'block';
}

function tutupEmojiPanel() {
    const panel = document.getElementById('panelEmoji');
    if (panel) panel.style.display = 'none';
}

function sisipkanEmoji(emoji) {
    const textarea = document.getElementById('teksBalasan');
    if (!textarea || textarea.disabled || !emoji) return;
    const awal = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
    const akhir = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : awal;
    textarea.setRangeText(emoji, awal, akhir, 'end');
    textarea.focus();
    // Programmatic value changes do not fire 'input': mirror its handler so the
    // changed draft gets a new operation_id (AC-EP-5).
    buangOperationIdBalasan();
    sembunyikanStatusKirimBalasan();
    autoResizeTeksBalasan();
}

function pasangPanelEmoji() {
    const panel = document.getElementById('panelEmoji');
    if (!panel) return;
    panel.addEventListener('mousedown', function(e) {
        // Keep the textarea caret/focus while clicking an emoji.
        e.preventDefault();
    });
    panel.addEventListener('click', function(e) {
        const tombol = e.target && e.target.closest ? e.target.closest('.inbox-emoji-item') : null;
        if (tombol) sisipkanEmoji(tombol.getAttribute('data-emoji'));
    });
    document.addEventListener('click', function(e) {
        if (!panelEmojiTerbuka()) return;
        const tombol = document.getElementById('btnEmojiBalasan');
        if (panel.contains(e.target) || (tombol && tombol.contains(e.target))) return;
        tutupEmojiPanel();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && panelEmojiTerbuka()) tutupEmojiPanel();
    });
}

pasangPanelEmoji();
