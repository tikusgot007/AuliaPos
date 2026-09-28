'use strict';
/**
 * Pemeriksaan PERILAKU (assert-based, TANPA framework/dependency) untuk logika
 * kegagalan media Inbox di app/Views/inbox/index.php
 * (plan-bugfix-inbox-media-unavailable-v1.0, TASK-015..018).
 *
 * Logic non-trivial yang diuji:
 *   - kategoriStatusMedia()   : 410 permanen; 413 'terlalu_besar'; 502/503/504
 *     sementara; sisanya 'lain'.
 *   - htmlMediaTidakTersedia(): teks JUJUR per kategori. Kasus utama: hanya
 *     kategori 'kadaluarsa' yang boleh menyebut "kadaluarsa" (REQ-002) --
 *     ini regresi persis yang dilaporkan kasir ("semua error disebut
 *     kadaluarsa").
 *   - bolehCobaLagiMedia()    : field eksplisit nonRetryable DICEK LEBIH DULU
 *     (CLN-902), lalu batas 3 percobaan DAN jeda 30 detik (REQ-004).
 *   - catatKegagalanMedia()   : 410 pindah ke latch permanen, media sementara
 *     dibuang; 413 'terlalu_besar' menandai nonRetryable: true + menghabiskan
 *     jatah percobaan (non-retryable); kategori lain masuk buku percobaan
 *     tanpa menaikkan cobaan.
 *
 * Fungsi diduplikasi PERSIS di sini (pola sama seperti
 * tests/js/operation-id-composer.check.js -- project ini murni CI4/PHP, tanpa
 * infra Node). Kalau fungsi-fungsi ini di index.php diubah, salin ulang ke
 * sini dan jalankan lagi:
 *   node tests/js/media-inbox-retry.check.js
 *
 * CATATAN CAKUPAN: ini menguji ATURAN-nya, bukan integrasi DOM/render.
 * Urutan keputusan di renderIsiPesan() tetap wajib lewat checklist browser
 * manual TASK-019 (tidak ada test runner DOM di project ini, RISK-001).
 */
const assert = require('assert');

// --- Salinan VERBATIM dari app/Views/inbox/index.php --------------------

const MEDIA_COBAAN_MAKS = 3;
const MEDIA_JEDA_COBAAN_MS = 30000;

/* CLN-701: batas unduh/tampilan media masuk dari server (default 100MB). */
const BATAS_MEDIA_UNDUH_MB = 100;

let mediaGagal;
let mediaSementara;

function resetState() {
    mediaGagal = new Set();
    mediaSementara = new Map();
}

function kategoriStatusMedia(status) {
    if (status === 410) return 'kadaluarsa';
    // CLN-701 (opsi B): 413 = lampiran melebihi batas unduh/tampilan.
    if (status === 413) return 'terlalu_besar';
    if (status === 502 || status === 503 || status === 504) return 'sementara';
    return 'lain';
}

function htmlMediaTidakTersedia(kategori, jenis) {
    const ikon = jenis === 'sticker' ? 'fa-icons' : 'fa-image';
    const label = jenis === 'sticker' ? 'Sticker' : 'Gambar';

    if (kategori === 'kadaluarsa') {
        return '<div class="inbox-media-unavailable"><i class="fas ' + ikon + '"></i> ' +
            label + ' tidak tersedia (kemungkinan sudah kadaluarsa)</div>';
    }

    if (kategori === 'terlalu_besar') {
        return '<div class="inbox-media-unavailable"><i class="fas fa-file-circle-exclamation"></i> ' +
            'Lampiran terlalu besar untuk ditampilkan (batas ' + BATAS_MEDIA_UNDUH_MB + 'MB)</div>';
    }

    if (kategori === 'sementara') {
        return '<div class="inbox-media-unavailable"><i class="fas fa-wifi"></i> ' +
            'Gateway belum bisa dihubungi — akan dicoba lagi</div>';
    }

    return '<div class="inbox-media-unavailable"><i class="fas ' + ikon + '"></i> ' +
        label + ' tidak tersedia</div>';
}

function bolehCobaLagiMedia(entri) {
    if (entri.nonRetryable === true) {
        return false;
    }

    return entri.cobaan < MEDIA_COBAAN_MAKS &&
        (Date.now() - entri.terakhirMs) >= MEDIA_JEDA_COBAAN_MS;
}

function catatKegagalanMedia(kunci, kategori) {
    if (kategori === 'kadaluarsa') {
        mediaGagal.add(kunci);
        mediaSementara.delete(kunci);
        return;
    }

    const lama = mediaSementara.get(kunci);

    mediaSementara.set(kunci, {
        kategori: kategori,
        nonRetryable: kategori === 'terlalu_besar',
        cobaan: kategori === 'terlalu_besar' ? MEDIA_COBAAN_MAKS : (lama ? lama.cobaan : 0),
        terakhirMs: Date.now()
    });
}

// --- Pemeriksaan -------------------------------------------------------

let pass = 0;
let fail = 0;

function check(nama, fn) {
    resetState();
    try {
        fn();
        console.log('[PASS] ' + nama);
        pass++;
    } catch (err) {
        console.log('[FAIL] ' + nama + ' => ' + err.message);
        fail++;
    }
}

check('kategoriStatusMedia: 410 -> kadaluarsa (permanen)', () => {
    assert.strictEqual(kategoriStatusMedia(410), 'kadaluarsa');
});

check('kategoriStatusMedia: 502/503/504 -> sementara', () => {
    assert.strictEqual(kategoriStatusMedia(502), 'sementara');
    assert.strictEqual(kategoriStatusMedia(503), 'sementara');
    assert.strictEqual(kategoriStatusMedia(504), 'sementara');
});

check('kategoriStatusMedia: status lain -> lain (bukan permanen)', () => {
    assert.strictEqual(kategoriStatusMedia(200), 'lain');
    assert.strictEqual(kategoriStatusMedia(500), 'lain');
    assert.strictEqual(kategoriStatusMedia(404), 'lain');
});

check('kategoriStatusMedia: 413 -> terlalu_besar (kategori eksplisit)', () => {
    assert.strictEqual(kategoriStatusMedia(413), 'terlalu_besar');
});

check('teks: 413 menyebut "terlalu besar" + batas, BUKAN "tidak tersedia"', () => {
    const html = htmlMediaTidakTersedia('terlalu_besar', 'image');

    assert.ok(html.includes('terlalu besar'), 'harus menyebut "terlalu besar"');
    assert.ok(html.includes(String(BATAS_MEDIA_UNDUH_MB) + 'MB'), 'harus menyebut batas dalam MB');
    assert.ok(!html.includes('tidak tersedia (kemungkinan sudah kadaluarsa)'), 'bukan pesan kadaluarsa');
});

check('teks: hanya kategori kadaluarsa yang menyebut "kadaluarsa" (REQ-002)', () => {
    assert.ok(
        htmlMediaTidakTersedia('kadaluarsa', 'image').includes('kadaluarsa'),
        'kadaluarsa harus tetap memakai teks lama'
    );

    const sementara = htmlMediaTidakTersedia('sementara', 'image');
    assert.ok(
        sementara.includes('Gateway belum bisa dihubungi'),
        'kegagalan sementara harus menyebut Gateway, bukan kadaluarsa'
    );
    assert.ok(
        !sementara.includes('kadaluarsa'),
        'kegagalan sementara TIDAK BOLEH menyebut kadaluarsa -- inilah bug yang dilaporkan'
    );

    const lain = htmlMediaTidakTersedia('lain', 'image');
    assert.ok(lain.includes('tidak tersedia'), 'kategori lain tetap generik');
    assert.ok(!lain.includes('kadaluarsa'), 'kategori lain TIDAK BOLEH menyebut kadaluarsa');
});

check('teks: sticker memakai ikon/label sticker, bukan gambar', () => {
    const html = htmlMediaTidakTersedia('lain', 'sticker');
    assert.ok(html.includes('fa-icons'), 'sticker harus memakai fa-icons');
    assert.ok(html.includes('Sticker'), 'label harus "Sticker"');
    assert.ok(!html.includes('Gambar'), 'label tidak boleh "Gambar" untuk sticker');
});

check('teks: kelas CSS inbox-media-unavailable dipertahankan semua kategori', () => {
    ['kadaluarsa', 'terlalu_besar', 'sementara', 'lain'].forEach((kategori) => {
        const html = htmlMediaTidakTersedia(kategori, 'image');
        assert.ok(
            html.includes('class="inbox-media-unavailable"'),
            'kelas inbox-media-unavailable wajib ada untuk kategori ' + kategori
        );
    });
});

check('catat: 410 memindahkan ke latch permanen dan membuang catatan sementara', () => {
    catatKegagalanMedia('7', 'sementara');
    catatKegagalanMedia('7', 'kadaluarsa');

    assert.ok(mediaGagal.has('7'), 'harus masuk latch permanen');
    assert.strictEqual(mediaSementara.has('7'), false, 'catatan sementara harus dibuang');
});

check('catat: kategori sementara mencatat kategori + terakhirMs, cobaan mulai 0', () => {
    catatKegagalanMedia('9', 'sementara');
    const entri = mediaSementara.get('9');

    assert.strictEqual(entri.kategori, 'sementara');
    assert.strictEqual(entri.cobaan, 0, 'probe pertama belum menghabiskan jatah percobaan');
    assert.ok(Date.now() - entri.terakhirMs < 1000, 'terakhirMs harus diisi sekarang');
});

check('catat: 413 terlalu_besar -> jatah habis (non-retryable) + label eksplisit dipertahankan', () => {
    catatKegagalanMedia('15', 'terlalu_besar');
    const entri = mediaSementara.get('15');

    assert.strictEqual(entri.kategori, 'terlalu_besar', 'label "terlalu besar" tetap muncul lewat entriSementara.kategori');
    assert.strictEqual(entri.cobaan, MEDIA_COBAAN_MAKS, 'CLN-801: jatah percobaan langsung habis');
    assert.strictEqual(bolehCobaLagiMedia(entri), false, 'CLN-801: 413 deterministik -> berhenti mencoba');
    assert.strictEqual(entri.nonRetryable, true, 'CLN-902: terlalu_besar ditandai nonRetryable eksplisit');
    assert.strictEqual(mediaGagal.has('15'), false, 'bukan latch kadaluarsa -- tetap di buku sementara');
});

check('catat: kategori sementara TIDAK ditandai nonRetryable (CLN-902)', () => {
    catatKegagalanMedia('16', 'sementara');

    assert.notStrictEqual(mediaSementara.get('16').nonRetryable, true, 'sementara tetap retryable');
});

check('bolehCobaLagiMedia: nonRetryable dicek LEBIH DULU walau jatah/jeda seolah boleh (CLN-902)', () => {
    const entri = { kategori: 'terlalu_besar', nonRetryable: true, cobaan: 0, terakhirMs: 0 };

    assert.strictEqual(bolehCobaLagiMedia(entri), false, 'nonRetryable harus menang lebih dulu');
});

check('jeda: catatan baru belum boleh dicoba lagi', () => {
    catatKegagalanMedia('11', 'sementara');
    assert.strictEqual(bolehCobaLagiMedia(mediaSementara.get('11')), false);
});

check('jeda: setelah 30 detik, percobaan ulang boleh jalan', () => {
    catatKegagalanMedia('12', 'sementara');
    const entri = mediaSementara.get('12');
    entri.terakhirMs = Date.now() - (MEDIA_JEDA_COBAAN_MS + 1000);

    assert.strictEqual(bolehCobaLagiMedia(entri), true);
});

check('batas: percobaan ke-3 habis -> TIDAK dicoba lagi walau jeda sudah lewat', () => {
    catatKegagalanMedia('13', 'sementara');
    const entri = mediaSementara.get('13');

    // Tiga kali percobaan ulang dijalankan (cara yang sama dipakai
    // renderIsiPesan(): cobaan += 1, terakhirMs = Date.now()).
    for (let i = 0; i < 3; i++) {
        entri.cobaan += 1;
        entri.terakhirMs = Date.now();
    }

    assert.strictEqual(entri.cobaan, 3, 'harus tepat 3 percobaan');
    assert.strictEqual(bolehCobaLagiMedia(entri), false, 'jatah habis -> berhenti mencoba');
});

check('batas: percobaan ke-1 dan ke-2 masih boleh, ke-3 tidak', () => {
    catatKegagalanMedia('14', 'sementara');
    const entri = mediaSementara.get('14');

    entri.cobaan = 0;
    entri.terakhirMs = 0;
    assert.strictEqual(bolehCobaLagiMedia(entri), true, 'percobaan 1 boleh');

    entri.cobaan = 1;
    entri.terakhirMs = 0;
    assert.strictEqual(bolehCobaLagiMedia(entri), true, 'percobaan 2 boleh');

    entri.cobaan = 2;
    entri.terakhirMs = 0;
    assert.strictEqual(bolehCobaLagiMedia(entri), true, 'percobaan 3 (terakhir) boleh');

    entri.cobaan = 3;
    entri.terakhirMs = 0;
    assert.strictEqual(bolehCobaLagiMedia(entri), false, 'setelah 3 percobaan berhenti');
});

check('kunci: 410 memakai kunci String, cocok dengan String(m.id) di render', () => {
    catatKegagalanMedia(String(900042), 'kadaluarsa');
    assert.ok(mediaGagal.has('900042'), 'kunci permanen harus String');
    assert.ok(mediaGagal.has(String(900042)), 'lookup String(m.id) harus menemukannya');
});

console.log('\n== ' + pass + ' PASS, ' + fail + ' FAIL ==');
process.exit(fail > 0 ? 1 : 0);
