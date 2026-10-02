// Media failure state machine ported from app/Views/inbox/index.php v2.4 (lines 990-1000 and 2602-2742), near verbatim,
// so the spike does not understate K2. Only change: the probe URL comes from window.SPIKE_MEDIA_URL.
const mediaGagal = new Set();
const mediaSementara = new Map();
const MEDIA_COBAAN_MAKS = 3;
const MEDIA_JEDA_COBAAN_MS = 30000;
const BATAS_MEDIA_UNDUH_MB = 20;
let gatewayTerhubung = true;

function kategoriStatusMedia(status) {
    if (status === 410) return 'kadaluarsa';
    // CLN-701 (opsi B): 413 = lampiran melebihi batas unduh/tampilan --
    // kategori eksplisit supaya kasir tahu ADA lampiran besar yang tidak
    // bisa ditampilkan, bukan "tidak tersedia" generik.
    if (status === 413) return 'terlalu_besar';
    if (status === 502 || status === 503 || status === 504) return 'sementara';
    return 'lain';
}

function htmlMediaTidakTersedia(kategori, jenis) {
    const ikon = jenis === 'sticker' ? 'fa-icons' : 'fa-image';
    const label = jenis === 'sticker' ? 'Sticker' : 'Gambar';

    if (kategori === 'kadaluarsa') {
        // Teks lama dipertahankan apa adanya (REQ-002/CON-002).
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

// Masih boleh dicoba lagi? Dibatasi JUMLAH dan JEDA (REQ-004) -- tanpa
// jeda, siklus polling 4 detik akan menembak request terus-menerus.
function bolehCobaLagiMedia(entri) {
    if (entri.nonRetryable === true) {
        return false;
    }

    return entri.cobaan < MEDIA_COBAAN_MAKS &&
        (Date.now() - entri.terakhirMs) >= MEDIA_JEDA_COBAAN_MS;
}

// 'kadaluarsa' pindah ke latch permanen; kategori lain masuk buku
// percobaan terbatas. 'terlalu_besar' (413) DETERMINISTIK: habiskan
// jatah percobaan supaya tidak ditembak ulang tiap siklus polling,
// tapi TETAP di buku sementara agar label eksplisit "terlalu besar"
// muncul lewat entriSementara.kategori (CLN-801).
// CLN-902: makna "non-retryable" dinyatakan EKSPLISIT lewat field
// nonRetryable -- bukan dipinjam dari sentinel cobaan = MEDIA_COBAAN_MAKS,
// supaya perubahan MEDIA_COBAAN_MAKS kelak tidak diam-diam mengubah
// semantik retry. Sentinel tetap sebagai pengaman kedua.
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

// SEC-1002: saat reconnect, lupakan kegagalan SEMENTARA supaya foto
// langsung dicoba lagi tanpa menunggu jeda 30 detik (REQ-003). Entri
// `nonRetryable` (413 deterministik, "terlalu besar") TIDAK boleh ikut
// dibuang -- itu permanen, jadi akan langsung gagal lagi tiap polling.
function bersihkanSementaraSetelahReconnect() {
    mediaSementara.forEach(function(entri, kunci) {
        if (entri.nonRetryable !== true) {
            mediaSementara.delete(kunci);
        }
    });
}

// Dipanggil dari <img onerror>. Ganti gambar yang gagal dengan placeholder
// SEKARANG (supaya tidak muncul ikon gambar rusak), lalu periksa status
// HTTP-nya sekali lewat fetch() untuk mengetahui penyebab sebenarnya
// (REQ-002), baru perbarui teksnya.
function tanganiMediaGagal(imgEl, kunci) {
    const jenis = (imgEl && imgEl.getAttribute('data-media-jenis')) || 'image';
    const entri = mediaSementara.get(kunci);

    // Kategori belum diketahui pada detik pertama kegagalan, jadi pakai
    // kategori terakhir yang sudah terbukti kalau ada, atau pesan generik
    // ('lain') -- JANGAN mengklaim "Gateway belum bisa dihubungi" sebelum
    // diperiksa. Teks yang benar muncul begitu probe di bawah selesai.
    gantiMediaDenganPlaceholder(imgEl, kunci, jenis, entri ? entri.kategori : 'lain');

    // Hanya SATU request tambahan, dan hanya di jalur gagal (RISK-002) --
    // jalur sukses beserta caching ETag-nya tidak tersentuh (CON-004).
    fetch(window.SPIKE_MEDIA_URL(kunci), {
            method: 'GET'
        })
        .then(function(res) {
            catatKegagalanMedia(kunci, kategoriStatusMedia(res.status));
            // Isinya tidak dibutuhkan -- hentikan unduhannya.
            if (res.body && typeof res.body.cancel === 'function') res.body.cancel();
        })
        .catch(function() {
            // Jaringan mati / Gateway tidak menjawab sama sekali.
            catatKegagalanMedia(kunci, 'sementara');
        })
        .then(function() {
            perbaruiPlaceholderMedia(kunci, jenis);
        });
}

function gantiMediaDenganPlaceholder(imgEl, kunci, jenis, kategori) {
    if (!imgEl || !imgEl.parentNode) return;

    const pembungkus = document.createElement('div');
    pembungkus.innerHTML = htmlMediaTidakTersedia(kategori, jenis);

    const pengganti = pembungkus.firstChild;
    pengganti.setAttribute('data-media-pesan', kunci);
    imgEl.parentNode.replaceChild(pengganti, imgEl);
}

function perbaruiPlaceholderMedia(kunci, jenis) {
    const el = document.querySelector('[data-media-pesan="' + kunci + '"]');
    if (!el) return;

    let kategori = 'lain';
    if (mediaGagal.has(kunci)) {
        kategori = 'kadaluarsa';
    } else {
        const entri = mediaSementara.get(kunci);
        if (entri) kategori = entri.kategori;
    }

    el.outerHTML = htmlMediaTidakTersedia(kategori, jenis);
}
