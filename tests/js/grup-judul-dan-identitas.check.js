'use strict';
/**
 * Pemeriksaan PERILAKU (assert-based, TANPA framework/dependency) untuk jalur
 * judul grup di app/Views/inbox/index.php:
 *   - `renderDaftarConversation` -- ekspresi nama baris daftar: grup memakai
 *     `group_name` atau `"Grup"`, TIDAK PERNAH `whatsapp_name`.
 *   - `formatIdentitasCustomer` -- header thread: grup memakai `group_name`
 *     atau `"Grup"`; percakapan pribadi tidak berubah.
 *
 * TASK-501/CC-01: menggantikan assertion substring teks sumber JS (rapuh --
 * perubahan format memecahkannya tanpa menunjukkan regresi perilaku) dengan
 * pemeriksaan perilaku. Fungsi diduplikasi PERSIS di sini (pola sama seperti
 * `format-identitas-customer.check.js`; project ini murni CI4/PHP, tanpa infra
 * Node). Kalau fungsi di `index.php` diubah, salin ulang fungsinya ke sini dan
 * jalankan lagi:
 *   node tests/js/grup-judul-dan-identitas.check.js
 */
const assert = require('assert');

function judulBarisDaftar(c) {
    return (c.jid_type === 'group') ? (c.group_name || 'Grup') : (c.contact_name || c.whatsapp_name || c.phone || c.chat_id);
}

function formatIdentitasCustomer(conv) {
    if (!conv) return '';

    if (conv.jid_type === 'group') return conv.group_name || 'Grup';

    const nama = conv.contact_name || conv.whatsapp_name || null;
    const nomor = conv.manual_phone || conv.phone || null;

    if (nama && nomor) return nama + ' (' + nomor + ')';
    if (nomor) return nomor;
    if (nama) return nama + (conv.jid_type === 'lid' ? ' (LID)' : '');
    if (conv.jid_type === 'lid') return 'LID';
    return conv.chat_id;
}

// Grup: group_name dipakai apa adanya; whatsapp_name (pengirim terakhir)
// TIDAK PERNAH dipakai sebagai judul grup.
assert.strictEqual(
    judulBarisDaftar({ jid_type: 'group', group_name: 'Grup Jualan', whatsapp_name: 'Budi Terakhir' }),
    'Grup Jualan'
);
assert.strictEqual(
    formatIdentitasCustomer({ jid_type: 'group', group_name: 'Grup Jualan', whatsapp_name: 'Budi Terakhir' }),
    'Grup Jualan'
);

// Grup tanpa group_name -> "Grup" generik, bukan nama pengirim terakhir.
assert.strictEqual(
    judulBarisDaftar({ jid_type: 'group', group_name: null, whatsapp_name: 'Budi Terakhir' }),
    'Grup'
);
assert.strictEqual(
    formatIdentitasCustomer({ jid_type: 'group', group_name: '', whatsapp_name: 'Budi Terakhir' }),
    'Grup'
);

// Pribadi: perilaku lama tidak berubah.
assert.strictEqual(
    judulBarisDaftar({ jid_type: 'pn', contact_name: null, whatsapp_name: 'Budi', phone: '6281', chat_id: 'x' }),
    'Budi'
);
assert.strictEqual(
    formatIdentitasCustomer({ jid_type: 'pn', contact_name: 'Budi', phone: '628123' }),
    'Budi (628123)'
);
assert.strictEqual(
    formatIdentitasCustomer({ jid_type: 'lid', contact_name: null, whatsapp_name: null, phone: null, manual_phone: null, chat_id: '9@lid' }),
    'LID'
);
assert.strictEqual(formatIdentitasCustomer(null), '');

console.log('OK: judul grup (daftar & header) memakai group_name/"Grup"; perilaku pribadi tidak berubah.');
