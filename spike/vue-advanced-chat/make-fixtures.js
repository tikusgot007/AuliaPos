// Generates fixtures.json (small, every case), fixtures-500.json (performance) and media/*.png.
// Run: node make-fixtures.js
const fs = require('fs');
const zlib = require('zlib');

function png(w, h, [r, g, b]) {
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (buf) => { let c = 0xffffffff; for (const x of buf) c = crcTable[(c ^ x) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: w }, () => [r, g, b]).flat())]);
  const raw = Buffer.concat(Array.from({ length: h }, () => row));
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const writeMedia = (id, w, h, rgb) => fs.writeFileSync(`media/${id}.png`, png(w, h, rgb));

const CUST = '6281234567890@s.whatsapp.net';
const base = {
  wa_message_id: null, direction: 'incoming', message_type: 'text', text: null, sender_jid: CUST, sender_name: null,
  send_status: 'received', is_internal: false, is_forwarded: false, media_filename: null, media_local_filename: null,
  extra_json: null, quoted_wa_message_id: null, quoted_sender_label: null, quoted_snippet: null,
  quoted_media_available: null, quoted_source_message_id: null, quoted_media_type: null,
};
const out = (o) => ({ direction: 'outgoing', sender_jid: null, sender_name: 'Rina (Kasir)', send_status: 'sent', ...o });
const quote = (srcWa, label, snippet, avail, srcId, type) => ({ quoted_wa_message_id: srcWa, quoted_sender_label: label, quoted_snippet: snippet, quoted_media_available: avail, quoted_source_message_id: srcId, quoted_media_type: type });

// 'D' = days ago (7, 1, 0); order is ascending.
const cases = [
  [7, { text: 'Halo kak, ready kain katun?' }],
  [7, out({ text: 'Halo kak, ready. Mau berapa meter?' })],
  [7, { message_type: 'image', media_local_filename: '3.png' }],
  [7, { message_type: 'image', media_local_filename: '4.png', text: 'Yang warna ini ya kak' }],
  [7, { message_type: 'sticker', media_local_filename: '5.png' }],
  [7, { message_type: 'document', media_filename: 'Invoice-0912.pdf', text: 'Tolong dicek invoicenya' }],
  [7, { message_type: 'audio' }],
  [7, { message_type: 'video', text: 'Ini contoh bahannya' }],
  [7, { message_type: 'location', extra_json: JSON.stringify({ kind: 'location', latitude: -7.2575, longitude: 112.7521, name: 'Toko Aulia', address: 'Jl. Pasar Besar 12, Surabaya', live: false }) }],
  [7, { message_type: 'location', extra_json: JSON.stringify({ kind: 'location', latitude: -7.26, longitude: 112.75, live: true }) }],
  [7, { message_type: 'contact', extra_json: JSON.stringify({ kind: 'contact', contacts: [{ display_name: 'Budi Santoso', vcard: 'BEGIN:VCARD\nTEL;type=CELL:+62 812-3456-7890\nEND:VCARD' }, { display_name: 'Tanpa Nomor', vcard: 'BEGIN:VCARD\nEND:VCARD' }] }) }],
  [1, { message_type: 'unsupported', text: 'Customer mengirim pesan lihat-sekali — cek WhatsApp Web.' }],
  [1, { message_type: 'unsupported', text: 'Customer mengirim polling — cek WhatsApp Web.' }],
  [1, { message_type: 'unsupported', text: 'Customer mengirim video singkat — cek WhatsApp Web.' }],
  [1, { message_type: 'unsupported', text: 'Customer mengirim file besar diatas 64mb — cek WhatsApp Web.' }],
  [1, { message_type: 'unsupported', text: 'Customer membalas tombol — cek WhatsApp Web.' }],
  [1, { message_type: 'text', direction: 'outgoing', sender_jid: null, sender_name: 'Rina (Kasir)', send_status: 'sent', is_internal: true, text: 'Catatan internal: pelanggan ini minta diskon grosir.' }],
  [1, out({ text: 'Pesan yang diteruskan dari grup supplier', is_forwarded: true })],
  [1, { text: 'Balasan ke teks biasa', ...quote('WA-1', 'Anda', 'Halo kak, ready kain katun?', null, null, null) }],
  [1, { text: 'Kutipan media tidak tersedia (cabang a)', ...quote('WA-3', 'Anda', '[Foto]', 0, 3, 'image') }],
  [1, out({ text: 'Kutipan gambar (cabang b, image)', ...quote('WA-3', 'Pelanggan', '[Foto]', 1, 3, 'image') })],
  [1, out({ text: 'Kutipan sticker (cabang b, sticker)', ...quote('WA-5', 'Pelanggan', '[Stiker]', 1, 5, 'sticker') })],
  [1, out({ text: 'Kutipan dokumen (cabang c)', ...quote('WA-6', 'Pelanggan', 'Invoice-0912.pdf', 1, 6, 'document') })],
  [1, out({ text: 'Kutipan audio (cabang d)', ...quote('WA-7', 'Pelanggan', '[Audio]', 1, 7, 'audio') })],
  [0, out({ text: 'Kutipan video (cabang d)', ...quote('WA-8', 'Pelanggan', 'Ini contoh bahannya', 1, 8, 'video') })],
  [0, out({ text: 'Kutipan media, id sumber kosong (cabang e)', ...quote('WA-X', 'Pelanggan', '[Foto] lama', 1, null, 'image') })],
  [0, out({ text: 'Kutipan tidak ditemukan', ...quote('WA-NOPE', null, null, null, null, null) })],
  [0, out({ text: 'Kutipan gambar yang file-nya hilang (404)', ...quote('WA-99', 'Pelanggan', '[Foto]', 1, 99, 'image') })],
  [0, { message_type: 'image', media_local_filename: 'hilang.png', text: 'Gambar yang sudah kadaluarsa' }],
  [0, out({ text: 'Pesan ini gagal terkirim', send_status: 'failed' })],
  [0, { text: '<script>alert(1)</script>' }],
  [0, { text: '<img src=x onerror=alert(1)>' }],
  [0, { text: 'Caption XSS', message_type: 'image', media_local_filename: '4.png', sender_name: '<b onmouseover=alert(1)>Budi</b>' }],
  [0, { message_type: 'document', media_filename: '"><img src=x onerror=alert(1)>.pdf', text: '<svg onload=alert(1)>' }],
  [0, { text: 'Kutipan XSS', ...quote('WA-1', '<i>Anda</i>', '<img src=x onerror=alert(1)>', null, null, null) }],
  [0, { message_type: 'location', extra_json: JSON.stringify({ kind: 'location', latitude: 1, longitude: 2, name: '<img src=x onerror=alert(1)>', address: '<script>alert(1)</script>' }) }],
  [0, { message_type: 'contact', extra_json: JSON.stringify({ kind: 'contact', contacts: [{ display_name: '<img src=x onerror=alert(1)>', vcard: 'TEL:+6281200000000' }] }) }],
  [0, { text: 'Format: *tebal* _miring_ ~coret~ `kode sebaris` dan ```blok\nkode```\nBukan format: 2*3*4, snake_case_name, * bukan *' }],
  [0, out({ text: 'Siap kak, terima kasih. Nomor ini https://example.com/cek?x=1 ya' })],
];

const media = { 3: [200, 120, 80], 4: [80, 140, 210], 5: [220, 180, 60], 6: [100, 100, 100] };
const iso = (daysAgo, idx) => { const d = new Date(Date.UTC(2026, 9, 2 - daysAgo, 3, 0, 0)); d.setUTCMinutes(idx * 3); return d.toISOString().slice(0, 19).replace('T', ' '); }; // timestamps have no zone (like the API); the browser parses them as local time

function build(list) {
  return list.map(([d, o], i) => ({ id: i + 1, ...base, wa_message_id: `WA-${i + 1}`, message_timestamp: iso(d, i), ...o }));
}

const small = build(cases);
// quote targets: the wa_message_id of the quoted source must exist for jump-to-source (K9)
const conversation = { id: 1, chat_id: CUST, jid_type: 'user', contact_name: 'Pelanggan Uji', status: 'open' };
fs.writeFileSync('fixtures.json', JSON.stringify({ status: 'success', conversation, messages: small }, null, 1));
Object.entries(media).forEach(([id, rgb]) => writeMedia(id, 320, 200, rgb));
writeMedia(5, 128, 128, media[5]); writeMedia(7, 16, 16, [0, 0, 0]);

// 500 messages: cycle through the case templates with unique ids/timestamps over 10 days.
const big = [];
for (let i = 0; i < 500; i++) {
  const [, o] = cases[i % cases.length];
  const day = Math.floor(i / 50);
  const m = { id: 1000 + i, ...base, wa_message_id: `WB-${i}`, message_timestamp: iso(9 - day, i % 50), ...o };
  if (m.message_type === 'image' || m.message_type === 'sticker') { m.media_local_filename = m.media_local_filename === 'hilang.png' ? 'hilang.png' : `${m.id}.png`; if (m.media_local_filename !== 'hilang.png') writeMedia(m.id, 160, 100, [(i * 7) % 255, 120, 160]); }
  if (m.quoted_source_message_id && m.quoted_source_message_id !== 99) m.quoted_source_message_id = [3, 4, 5, 6][i % 4];
  big.push(m);
}
fs.writeFileSync('fixtures-500.json', JSON.stringify({ status: 'success', conversation, messages: big }));
console.log('small', small.length, 'big', big.length);
