'use strict';
/**
 * Pemeriksaan minimal (assert-based, TANPA framework/dependency) untuk
 * siklus hidup operation_id composer balasan di
 * app/Views/inbox/index.php (M1 Wave 2, TASK-019 / REQ-039, REQ-041,
 * AC-046).
 *
 * Logic non-trivial yang diuji: pembuatan kunci (crypto.randomUUID
 * dengan fallback hex Math.random), pemakaian ulang saat kirim ulang
 * setelah gagal/timeout, pembuangan kunci setelah sukses atau isi
 * berubah, pembuatan kunci baru saat OPERATION_ID_REUSED, dan status
 * "hasil belum pasti" untuk SEND_IN_PROGRESS/SEND_UNRESOLVED, serta
 * penanganan OPERATION_STORE_ERROR dengan pesan restart operator (F-1).
 *
 * Fungsi diduplikasi PERSIS di sini (bukan di-require dari view PHP,
 * tidak ada infra Node di project ini -- lihat CLAUDE.md, aplikasi ini
 * murni CI4/PHP) -- kalau fungsi-fungsi ini di index.php diubah, salin
 * ulang ke sini dan jalankan lagi:
 *   node tests/js/operation-id-composer.check.js
 */
const assert = require('assert');

// --- Stub DOM minimal -------------------------------------------------
let toasts = [];

function showToast(message, type) {
    toasts.push({ message: message, type: type });
}

const elemen = {
    formBalas: {
        attrs: {},
        getAttribute(kunci) {
            return Object.prototype.hasOwnProperty.call(this.attrs, kunci) ? this.attrs[kunci] : null;
        },
        setAttribute(kunci, nilai) {
            this.attrs[kunci] = nilai;
        },
    },
    statusKirimBalasan: {
        style: {},
        className: '',
        textContent: '',
    },
};

global.document = {
    getElementById(id) {
        return Object.prototype.hasOwnProperty.call(elemen, id) ? elemen[id] : null;
    },
};

global.window = {};

// --- Fungsi yang disalin VERBATIM dari app/Views/inbox/index.php -------
function buatOperationIdBalasan() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }

    // Fallback peramban lama: hex 32 karakter (di dalam batas 64
    // karakter REQ-020).
    let hex = '';
    for (let i = 0; i < 32; i++) {
        hex += Math.floor(Math.random() * 16).toString(16);
    }
    return hex;
}

function ambilOperationIdBalasan() {
    const form = document.getElementById('formBalas');
    if (!form) return buatOperationIdBalasan();

    let kunci = form.getAttribute('data-operation-id');
    if (!kunci) {
        kunci = buatOperationIdBalasan();
        form.setAttribute('data-operation-id', kunci);
    }
    return kunci;
}

function buangOperationIdBalasan() {
    const form = document.getElementById('formBalas');
    if (form) form.setAttribute('data-operation-id', '');
}

function tampilkanStatusKirimBalasan(pesan, tipe) {
    const el = document.getElementById('statusKirimBalasan');
    if (!el) return;

    const kelas = tipe === 'warning' ? 'text-warning' : 'text-danger';
    el.className = 'small mt-1 ' + kelas;
    el.textContent = pesan;
    el.style.display = 'block';
}

function sembunyikanStatusKirimBalasan() {
    const el = document.getElementById('statusKirimBalasan');
    if (el) el.style.display = 'none';
}

// Cabang respons Gateway yang ambigu / kunci dipakai ulang / penyimpanan gagal.
// Mengembalikan true kalau kegagalan sudah ditangani khusus di sini.
function tanganiKegagalanKirimBalasan(json, pesanDefault) {
    if (json.error_code === 'SEND_IN_PROGRESS' || json.error_code === 'SEND_UNRESOLVED') {
        // Hasil belum pasti: kunci DIPERTAHANKAN (kirim ulang tidak
        // menggandakan pesan), tapi kasir diminta memeriksa dulu.
        tampilkanStatusKirimBalasan('Hasil belum pasti, jangan kirim ulang dulu. Periksa WhatsApp atau tab lain sebelum mengirim ulang.', 'warning');
        showToast('Hasil belum pasti, jangan kirim ulang dulu. Periksa WhatsApp atau tab lain sebelum mengirim ulang.', 'warning');
        return true;
    }

    if (json.error_code === 'OPERATION_ID_REUSED') {
        // Kunci lama tidak boleh dipakai lagi -- buang supaya kirim
        // berikutnya memakai kunci baru (AC-046).
        buangOperationIdBalasan();
        sembunyikanStatusKirimBalasan();
        showToast(json.message || 'Kunci pengiriman tidak valid. Gunakan kunci baru sebelum mengirim ulang.', 'danger');
        return true;
    }

    if (json.error_code === 'OPERATION_STORE_ERROR') {
        // Penyimpanan operasi gagal (disk penuh, basis data rusak, dll).
        // Kunci DIPERTAHANKAN (fail closed) supaya operator bisa lihat
        // riwayat percobaan. Kasir harus menghubungi operator untuk
        // restart Gateway, bukan mengirim ulang.
        tampilkanStatusKirimBalasan('Penyimpanan operasi gagal. Hubungi operator untuk restart Gateway. Jangan coba kirim ulang sendiri.', 'danger');
        showToast('Penyimpanan operasi gagal. Hubungi operator untuk restart Gateway.', 'danger');
        return true;
    }

    // Kegagalan biasa (NOT_CONNECTED/DEAD_LETTERED/HTTP lain):
    // kunci DIPERTAHANKAN supaya percobaan ulang tetap idempoten.
    showToast(json.message || pesanDefault, 'danger');
    return true;
}

function resetComposer() {
    toasts = [];
    elemen.formBalas.attrs = {};
    elemen.statusKirimBalasan.style = {};
    elemen.statusKirimBalasan.className = '';
    elemen.statusKirimBalasan.textContent = '';
}

// --- 1. Pembuatan kunci ------------------------------------------------
global.window = { crypto: { randomUUID: () => '11111111-2222-3333-4444-555555555555' } };
assert.strictEqual(
    buatOperationIdBalasan(),
    '11111111-2222-3333-4444-555555555555',
    'crypto.randomUUID() harus dipakai kalau tersedia'
);

// Tanpa crypto -> fallback hex 32 karakter (<= 64, batas REQ-020).
global.window = {};
const fallback = buatOperationIdBalasan();
assert.ok(/^[0-9a-f]{32}$/.test(fallback), 'fallback harus hex 32 karakter, dapat: ' + fallback);

// --- 2. Siklus hidup kunci ---------------------------------------------
resetComposer();

// Kirim pertama: kunci dibuat dan disimpan di composer.
const kunciPertama = ambilOperationIdBalasan();
assert.ok(kunciPertama.length > 0, 'kunci pertama harus dibuat');
assert.strictEqual(
    elemen.formBalas.attrs['data-operation-id'],
    kunciPertama,
    'kunci harus tersimpan di data-operation-id'
);

// Kirim ulang setelah gagal/timeout: kunci yang SAMA dipakai lagi.
assert.strictEqual(
    ambilOperationIdBalasan(),
    kunciPertama,
    'kirim ulang setelah gagal harus memakai kunci yang sama (REQ-039)'
);

// Isi pesan berubah / kirim sukses: kunci dibuang.
buangOperationIdBalasan();
assert.strictEqual(elemen.formBalas.attrs['data-operation-id'], '', 'kunci harus dibuang setelah sukses');

// Percobaan berikutnya: kunci BARU, bukan kunci lama.
const kunciKedua = ambilOperationIdBalasan();
assert.notStrictEqual(kunciKedua, kunciPertama, 'percobaan berikutnya harus memakai kunci baru');
assert.strictEqual(elemen.formBalas.attrs['data-operation-id'], kunciKedua);


// --- 3. Cabang SEND_IN_PROGRESS / SEND_UNRESOLVED ----------------------
['SEND_IN_PROGRESS', 'SEND_UNRESOLVED'].forEach(function(errorCode) {
    resetComposer();
    const kunci = ambilOperationIdBalasan();

    tanganiKegagalanKirimBalasan({ error_code: errorCode, message: 'Gateway sibuk.' }, 'Gagal mengirim pesan.');

    assert.strictEqual(
        elemen.formBalas.attrs['data-operation-id'],
        kunci,
        errorCode + ': kunci harus DIPERTAHANKAN supaya kirim ulang idempoten'
    );
    assert.strictEqual(elemen.statusKirimBalasan.style.display, 'block', errorCode + ': status harus tampil');
    assert.ok(
        elemen.statusKirimBalasan.textContent.indexOf('Hasil belum pasti, jangan kirim ulang dulu.') === 0,
        errorCode + ': status harus memuat peringatan hasil belum pasti'
    );
    // REQ-003: pesan harus memberikan arahan eksplisit tambahan (F-2 mitigation)
    assert.ok(
        elemen.statusKirimBalasan.textContent.indexOf('Periksa') !== -1 || elemen.statusKirimBalasan.textContent.indexOf('sebelum mengirim') !== -1,
        errorCode + ': status harus mengandung arahan untuk memeriksa sebelum kirim ulang (REQ-003 mitigation)'
    );
    assert.strictEqual(elemen.statusKirimBalasan.className, 'small mt-1 text-warning');
    assert.strictEqual(toasts.length, 1, errorCode + ': harus ada satu toast');
    assert.strictEqual(toasts[0].type, 'warning', errorCode + ': toast harus warning, bukan saran kirim ulang');
});

// --- 4. Cabang OPERATION_ID_REUSED -------------------------------------
resetComposer();
const kunciLama = ambilOperationIdBalasan();

tanganiKegagalanKirimBalasan({ error_code: 'OPERATION_ID_REUSED', message: 'Kunci sudah dipakai.' }, 'Gagal mengirim pesan.');

assert.strictEqual(
    elemen.formBalas.attrs['data-operation-id'],
    '',
    'OPERATION_ID_REUSED: kunci lama harus dibuang agar percobaan berikutnya memakai kunci baru'
);
const kunciSetelahReused = ambilOperationIdBalasan();
assert.notStrictEqual(kunciSetelahReused, kunciLama, 'OPERATION_ID_REUSED: kunci berikutnya harus baru');
assert.strictEqual(elemen.statusKirimBalasan.style.display, 'none', 'OPERATION_ID_REUSED: status "belum pasti" harus disembunyikan');
assert.strictEqual(toasts.length, 1);
assert.strictEqual(toasts[0].type, 'danger');

// --- 5. Kegagalan biasa (NOT_CONNECTED/DEAD_LETTERED) -------------------
resetComposer();
const kunciBiasa = ambilOperationIdBalasan();

tanganiKegagalanKirimBalasan({ error_code: 'NOT_CONNECTED', message: '', state: 'failed' }, 'Gagal mengirim pesan.');

assert.strictEqual(
    elemen.formBalas.attrs['data-operation-id'],
    kunciBiasa,
    'kegagalan biasa: kunci harus DIPERTAHANKAN untuk percobaan ulang'
);
assert.strictEqual(elemen.statusKirimBalasan.style.display, undefined, 'kegagalan biasa tidak menampilkan status "belum pasti"');
assert.strictEqual(toasts.length, 1);
assert.strictEqual(toasts[0].type, 'danger');
assert.strictEqual(toasts[0].message, 'Gagal mengirim pesan.');

// --- 6. Cabang OPERATION_STORE_ERROR (F-1: kegagalan penyimpanan operasi) ---
resetComposer();
const kunsiStore = ambilOperationIdBalasan();

tanganiKegagalanKirimBalasan({ error_code: 'OPERATION_STORE_ERROR', message: 'Gagal menyimpan operasi: disk penuh atau basis data rusak.' }, 'Gagal mengirim pesan.');

assert.strictEqual(
    elemen.formBalas.attrs['data-operation-id'],
    kunsiStore,
    'OPERATION_STORE_ERROR: kunci harus DIPERTAHANKAN (fail closed, tidak discard)'
);
assert.strictEqual(
    elemen.statusKirimBalasan.style.display,
    'block',
    'OPERATION_STORE_ERROR: status harus ditampilkan dengan pesan khusus'
);
assert.ok(
    elemen.statusKirimBalasan.textContent.indexOf('operator') !== -1 || elemen.statusKirimBalasan.textContent.indexOf('restart') !== -1,
    'OPERATION_STORE_ERROR: pesan status harus menyebut operator atau restart, tidak hanya pesan default'
);
assert.strictEqual(
    elemen.statusKirimBalasan.className,
    'small mt-1 text-danger',
    'OPERATION_STORE_ERROR: harus menggunakan kelas text-danger'
);
assert.strictEqual(toasts.length, 1, 'OPERATION_STORE_ERROR: harus ada satu toast');
assert.strictEqual(toasts[0].type, 'danger', 'OPERATION_STORE_ERROR: toast harus danger');
assert.ok(
    toasts[0].message.indexOf('operator') !== -1 || toasts[0].message.indexOf('restart') !== -1,
    'OPERATION_STORE_ERROR: toast pesan harus menyebut operator atau restart'
);

console.log('OK: semua kasus siklus hidup operation_id composer (crypto + fallback, reuse, discard, SEND_IN_PROGRESS/SEND_UNRESOLVED, OPERATION_ID_REUSED, OPERATION_STORE_ERROR, kegagalan biasa) lulus.');
