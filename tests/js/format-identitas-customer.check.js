'use strict';
/**
 * Pemeriksaan minimal (assert-based, TANPA framework/dependency) untuk
 * formatIdentitasCustomer() di app/Views/inbox/index.php -- logic
 * fallback nama/nomor/LID yang non-trivial (perubahan UI Inbox:
 * pindah Edit/Hapus ke daftar percakapan + identitas header).
 *
 * Fungsi diduplikasi PERSIS di sini (bukan di-require dari view PHP,
 * tidak ada infra Node di project ini -- lihat CLAUDE.md, aplikasi ini
 * murni CI4/PHP) -- kalau formatIdentitasCustomer() di index.php
 * diubah, salin ulang fungsinya ke sini dan jalankan lagi:
 *   node tests/js/format-identitas-customer.check.js
 */
const assert = require('assert');

function formatIdentitasCustomer(conv) {
    if (!conv) return '';

    const nama = conv.contact_name || conv.whatsapp_name || null;
    const nomor = conv.manual_phone || conv.phone || null;

    if (nama && nomor) return nama + ' (' + nomor + ')';
    if (nomor) return nomor;
    if (nama) return nama + (conv.jid_type === 'lid' ? ' (LID)' : '');
    if (conv.jid_type === 'lid') return 'LID';
    return conv.chat_id;
}

// Nama + nomor -> "Nama (Nomor)"
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: 'James', phone: '628123456789', jid_type: 'pn', chat_id: 'x' }),
    'James (628123456789)'
);

// whatsapp_name dipakai kalau contact_name (manual) kosong
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: null, whatsapp_name: 'James (WA)', phone: '628123456789', jid_type: 'pn', chat_id: 'x' }),
    'James (WA) (628123456789)'
);

// manual_phone menang atas phone untuk tampilan
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: 'James', phone: '628999', manual_phone: '628111', jid_type: 'pn', chat_id: 'x' }),
    'James (628111)'
);

// @lid TANPA nomor + ADA nama -> "Nama (LID)", BUKAN angka @lid
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: 'James', phone: null, manual_phone: null, jid_type: 'lid', chat_id: '123456789@lid' }),
    'James (LID)'
);

// @lid TANPA nomor DAN TANPA nama -> "LID" saja, BUKAN angka @lid
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: null, whatsapp_name: null, phone: null, manual_phone: null, jid_type: 'lid', chat_id: '123456789@lid' }),
    'LID'
);

// @lid yang SUDAH dikonfirmasi (phone terisi) -> tampil seperti PN biasa
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: 'James', phone: '628123456789', manual_phone: null, jid_type: 'lid', chat_id: '123456789@lid' }),
    'James (628123456789)'
);

// Tidak ada nama sama sekali, ada nomor -> nomor saja (bukan "null (628...)")
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: null, whatsapp_name: null, phone: '628123456789', jid_type: 'pn', chat_id: 'x' }),
    '628123456789'
);

// Tidak ada apa-apa (pn tanpa nama/nomor, kasus langka) -> fallback chat_id, BUKAN conversation_id/"#45"
assert.strictEqual(
    formatIdentitasCustomer({ contact_name: null, whatsapp_name: null, phone: null, manual_phone: null, jid_type: 'pn', chat_id: '628999@s.whatsapp.net' }),
    '628999@s.whatsapp.net'
);

// conv null (mis. belum termuat) -> string kosong, BUKAN "#null"/"#undefined"/"Percakapan"
assert.strictEqual(formatIdentitasCustomer(null), '');

console.log('OK: semua kasus formatIdentitasCustomer() (nama+nomor, LID dengan/tanpa nama, LID terkonfirmasi, tanpa nama, tanpa apa-apa, null) lulus.');
