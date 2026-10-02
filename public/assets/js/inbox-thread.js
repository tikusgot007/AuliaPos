/*
 * Inbox thread (panel pesan): rendering per pesan, kutipan, aksi, dan state media.
 * Dipindahkan dari app/Views/inbox/index.php (docs/requirements/2026-10-02-perbaikan-thread-inbox.md, P2/P3).
 *
 * Script biasa (bukan module): fungsi dan state di bawah sengaja global karena
 * dipakai handler inline pada HTML yang dirender (onclick/onerror/onload) dan
 * oleh index.php. Nilai dari PHP datang lewat satu objek konfigurasi:
 *   window.INBOX_THREAD_CONFIG = { mediaBaseUrl: '.../inbox/media/', maxMediaDownloadMb: 20 };
 * Dimuat SEBELUM script inline index.php.
 */
const threadConfig = (typeof globalThis.INBOX_THREAD_CONFIG === 'object' && globalThis.INBOX_THREAD_CONFIG) || {};

// ================================================================
// STATE
// ================================================================
// id pesan yang medianya sudah dipastikan PERMANEN gagal (410 dari
// Gateway) -- tidak pernah dicoba lagi selama halaman ini terbuka.
// Kunci SELALU String(id) supaya konsisten dengan nilai yang dibaca
// ulang dari atribut DOM setelah kegagalan (lihat tanganiMediaGagal()).
// Reset tiap reload halaman (cukup untuk 1 sesi kerja, tidak perlu
// persisten di frontend).
const mediaGagal = new Set();

// Kegagalan SEMENTARA per id pesan: { kategori, cobaan, terakhirMs }.
// Dipisah dari mediaGagal supaya foto yang cuma kesulitan sesaat tetap
// dicoba lagi (plan-bugfix-inbox-media-unavailable-v1.0, REQ-003/REQ-004),
// dengan jumlah percobaan DAN jeda waktu dibatasi supaya media yang
// benar-benar rusak tidak memicu request tiap siklus polling 4 detik.
const mediaSementara = new Map();
const MEDIA_COBAAN_MAKS = 3;
const MEDIA_JEDA_COBAAN_MS = 30000;
let gatewayTerhubung = true; // Tahap F -- optimistic default sebelum poll pertama datang

/* Pesan yang terkirim tapi kutipannya tidak sampai ke penerima
   (reaksi (a) REQ-006, `quote_applied:false`). Sengaja disimpan di
   memori saja, TIDAK kolom di database: penanda ini menggambarkan
   hasil percobaan kirim yang sedang berjalan, dan menghilang saat
   halaman dimuat ulang (ASSUMPTION-008). */
const pesanTerkirimTanpaKutipan = new Set();

/* Data pesan hasil render terakhir, dipakai pilihKutipan() agar kotak
   kutipan bisa ditampilkan tanpa request tambahan. Direset setiap
   renderPesan() supaya tidak menahan baris lama. */
let pesanCached = {};

// ================================================================
// UTIL
// ================================================================
function escapeHtmlInbox(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function formatWaktuInbox(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString('id-ID', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Untuk nilai di dalam atribut HTML ber-kutip: escapeHtmlInbox() tidak
// meng-escape kutip, jadi nilai dari server tidak boleh langsung masuk atribut.
function escapeAttrInbox(str) {
    return escapeHtmlInbox(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/* Format teks WhatsApp (P6/AC-20..AC-22, AC-31).
   URUTAN WAJIB: escape HTML dulu, baru penanda. Kode (``` dan `) dan tautan
   disisihkan ke penampung lebih dulu supaya isinya tidak ikut diformat.
   Penanda *tebal*, _miring_, ~coret~ hanya berlaku bila menempel pada teks
   (tidak ada spasi di tepi dalamnya) dan tidak menempel pada huruf/angka di
   luarnya, jadi `2*3*4`, `snake_case_name` dan `* bukan *` tidak berubah.
   Penanda tanpa pasangan dibiarkan. Hanya http(s) yang menjadi tautan. */
function penandaWa(teks, c, tag) {
    const pola = new RegExp(
        '(?<![\\p{L}\\p{N}_' + c + '])[' + c + '](?![\\s' + c + '])([^' + c + '\\n]*[^\\s' + c + '])[' + c + '](?![\\p{L}\\p{N}_' + c + '])',
        'gu'
    );
    return teks.replace(pola, '<' + tag + '>$1</' + tag + '>');
}

function formatTeksWa(teks) {
    const penampung = [];
    const simpan = function(html) { return '\u0000' + (penampung.push(html) - 1) + '\u0000'; };
    // \u0000 dipakai sebagai pembatas penampung: buang dari masukan supaya tidak bisa dipalsukan.
    let s = escapeHtmlInbox(String(teks ?? '').replace(/\u0000/g, ''));

    s = s.replace(/```([\s\S]+?)```/g, function(_, isi) {
        return simpan('<pre class="inbox-kode-blok">' + isi.replace(/^\n|\n$/g, '') + '</pre>');
    });
    s = s.replace(/`([^`\n]+)`/g, function(_, isi) { return simpan('<code>' + isi + '</code>'); });
    s = s.replace(/https?:\/\/(?:(?!&lt;|&gt;)[^\s"'])+/gi, function(url) {
        const ekor = (url.match(/[.,;:!?)\]}]+$/) || [''])[0];
        const bersih = ekor ? url.slice(0, -ekor.length) : url;
        return simpan('<a href="' + bersih + '" target="_blank" rel="noopener noreferrer">' + bersih + '</a>') + ekor;
    });

    s = penandaWa(s, '*', 'strong');
    s = penandaWa(s, '_', 'em');
    s = penandaWa(s, '~', 'del');

    return s.replace(/\u0000(\d+)\u0000/g, function(_, n) { return penampung[Number(n)]; });
}

function adaKutipan(m) {
    return m && m.quoted_wa_message_id !== null && m.quoted_wa_message_id !== undefined && m.quoted_wa_message_id !== '';
}

/**
 * Kotak kutipan pada satu bubble. SATU komponen dipakai untuk balasan
 * kasir (REQ-005) dan pesan masuk pelanggan (REQ-013), supaya keduanya
 * dijamin tampil sama.
 *
 * Penentuan "kutipan tidak ditemukan" HANYA lewat `quoted_sender_label`
 * yang null -- itulah penanda tunggalnya (F-B). UI dilarang menyimpulkan
 * status itu dengan membandingkan isi cuplikan terhadap teks generik
 * mana pun, karena cuplikan itu isinya pesan asli, bukan penanda.
 */
function renderKotakKutipan(m) {
    if (!adaKutipan(m)) return '';

    const tidakDitemukan = (m.quoted_sender_label === null || m.quoted_sender_label === undefined || m.quoted_sender_label === '');

    // Fallback tampilan kutipan media (REQ-008/REQ-008c, AC-005 v1.7),
    // lima cabang -- representasi dipilih oleh `quoted_media_type`, BUKAN
    // ditebak dari `quoted_snippet` (caption bisa menggantikan label jenis):
    //  (a) `quoted_media_available = 0` -> "[Media tidak tersedia]"
    //      langsung, tanpa live-fetch (berlaku semua tipe).
    //  (b) tipe image/sticker + snapshot 1 + id sumber terisi -> <img>
    //      live-fetch GET /inbox/media/:id; 404/410/error jatuh ke
    //      placeholder yang sama TANPA menulis ulang DB (snapshot beku,
    //      REQ-007) dan kegagalannya diingat di `mediaGagal` supaya tidak
    //      di-fetch ulang tiap siklus polling (PERF-001).
    //  (c) tipe document + snapshot 1 + id sumber terisi -> tautan, bukan
    //      <img>, tanpa fetch saat render.
    //  (d) tipe audio/video -> label jenis tanpa fetch/player/unduhan.
    //  (e) id sumber NULL (legacy / sumber tidak ditemukan) ATAU
    //      `quoted_media_type` NULL (legacy / sumber teks) -> tanpa
    //      live-fetch; pakai snapshot tersimpan apa adanya.
    const angkaMedia = (m.quoted_media_available === null || m.quoted_media_available === undefined)
        ? null
        : String(m.quoted_media_available);
    const mediaTidakTersedia = (angkaMedia === '0');

    const sumberId = m.quoted_source_message_id;
    const adaSumberId = (sumberId !== null && sumberId !== undefined && sumberId !== '');
    const tipeMedia = (m.quoted_media_type === null || m.quoted_media_type === undefined)
        ? null
        : String(m.quoted_media_type);
    const adaSumberMedia = (angkaMedia === '1') && adaSumberId && (tipeMedia !== null);

    // Kunci memori kegagalan DIPISAH dari `mediaGagal.has(m.id)` milik
    // renderIsiPesan(): satu pesan bisa sekaligus punya media sendiri DAN
    // membawa kutipan, jadi kunci mentah `m.id` akan salah menandai media
    // pesan itu sendiri sebagai gagal.
    const kunciKutipanGagal = 'kutipan:' + m.id;

    let isiKutipan;
    if (mediaTidakTersedia) {
        isiKutipan = '<div class="inbox-kutipan-snippet inbox-kutipan-tak-ada">[Media tidak tersedia]</div>';
    } else if (adaSumberMedia && (tipeMedia === 'image' || tipeMedia === 'sticker')) {
        if (mediaGagal.has(kunciKutipanGagal)) {
            isiKutipan = '<div class="inbox-kutipan-snippet inbox-kutipan-tak-ada">[Media tidak tersedia]</div>';
        } else {
            const urlMediaKutipan = threadConfig.mediaBaseUrl + sumberId;
            const kelasMedia = tipeMedia === 'sticker' ? 'inbox-kutipan-media inbox-kutipan-sticker' : 'inbox-kutipan-media';
            isiKutipan = '<img src="' + urlMediaKutipan + '" alt="Media kutipan" class="' + kelasMedia + '" ' +
                'onerror="mediaGagal.add(\'' + kunciKutipanGagal + '\'); this.outerHTML=\'<div class=&quot;inbox-kutipan-snippet inbox-kutipan-tak-ada&quot;>[Media tidak tersedia]</div>\'">';
        }
    } else if (adaSumberMedia && tipeMedia === 'document') {
        const urlMediaKutipan = threadConfig.mediaBaseUrl + sumberId;
        const labelDokumen = m.quoted_snippet || '[Dokumen]';
        isiKutipan = '<a href="' + urlMediaKutipan + '" target="_blank" class="inbox-kutipan-dokumen" onclick="event.stopPropagation()">' +
            '<i class="fas fa-file-alt"></i> ' + escapeHtmlInbox(labelDokumen) + '</a>';
    } else if (adaSumberMedia && (tipeMedia === 'audio' || tipeMedia === 'video')) {
        const labelTipe = tipeMedia === 'audio' ? '[Audio]' : '[Video]';
        isiKutipan = '<div class="inbox-kutipan-snippet">' + labelTipe + '</div>';
    } else {
        isiKutipan = '<div class="inbox-kutipan-snippet">' + escapeHtmlInbox(m.quoted_snippet || 'Pesan tidak ditemukan') + '</div>';
    }

    const judul = tidakDitemukan
        ? '<div class="inbox-kutipan-pengirim">Pesan tidak ditemukan</div>'
        : '<div class="inbox-kutipan-pengirim">' + escapeHtmlInbox(m.quoted_sender_label) + '</div>';

    // P5: kotak kutipan bisa diklik untuk meloncat ke pesan asal, kecuali
    // "Pesan tidak ditemukan" (tidak ada tujuan; AC-19).
    const kelasKlik = tidakDitemukan ? '' :
        ' inbox-kutipan-klik" role="button" tabindex="0" title="Lihat pesan asal"' +
        ' data-kutipan-wa="' + escapeAttrInbox(m.quoted_wa_message_id) + '"' +
        ' onclick="loncatKeKutipan(this)" onkeydown="if (event.key === \'Enter\') loncatKeKutipan(this)';

    return '<div class="inbox-kutipan' + kelasKlik + '">' + '<i class="fas fa-reply align-self-center text-success"></i>' +
        '<div class="inbox-kutipan-isi">' + judul + isiKutipan + '</div>' +
        '</div>';
}

/* Aksi per-pesan (REQ-004): tombol "Balas". Tidak dirender untuk
   Internal Note (bukan percakapan dengan pelanggan) dan untuk pesan
   outgoing yang belum terkirim (tidak ada yang bisa dibalas).
   Mengembalikan TOMBOL saja -- wadah `.bubble-aksi` dirakit
   renderAksiPesan() supaya Balas dan Teruskan berada di satu blok aksi. */
function renderAksiBalas(m) {
    if (!aksiPesanTersedia(m)) return '';

    return '<button type="button" class="btn btn-outline-success btn-sm" onclick="pilihKutipan(' + m.id + ')">' +
        '<i class="fas fa-reply"></i> Balas</button>';
}

/* Aksi per-pesan (REQ-004/REQ-006): tombol "Teruskan". Sama seperti
   Balas, tidak dirender untuk Internal Note dan outgoing belum terkirim.
   Untuk audio/video tombol tetap DIRENDER tetapi disabled + alasan
   (AC-002/GH-016) -- bukan disembunyikan; server tetap menolak percobaan
   langsung ke endpoint (GUD-001). */
function renderAksiTeruskan(m) {
    if (!aksiPesanTersedia(m)) return '';

    if (m.message_type === 'audio' || m.message_type === 'video'
        || m.message_type === 'location' || m.message_type === 'contact') {
        const jenis = (m.message_type === 'audio' || m.message_type === 'video')
            ? 'audio/video' : 'lokasi/kontak';
        return '<button type="button" class="btn btn-outline-secondary btn-sm" disabled' +
            ' title="Teruskan — ' + jenis + ' tidak dapat diteruskan">' +
            '<i class="fas fa-share"></i> Teruskan — ' + jenis + ' tidak dapat diteruskan</button>';
    }

    return '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="bukaPemilihTeruskan(' + m.id + ')">' +
        '<i class="fas fa-share"></i> Teruskan</button>';
}

/* Satu blok aksi per bubble: wadah tidak dirender sama sekali bila tidak
   ada tombol yang tersedia, supaya bubble yang tidak punya aksi tampil
   persis seperti sebelumnya. */
function renderAksiPesan(m) {
    const tombol = renderAksiBalas(m) + renderAksiTeruskan(m);

    if (tombol === '') return '';

    return '<div class="bubble-aksi">' + tombol + '</div>';
}

// ================================================================
// AKSI TERUSKAN / LABEL (dipakai bubble)
// ================================================================
/* Kelayakan aksi per-pesan (CLN-401/CLN-604) -- dipakai bersama oleh Balas
   dan Teruskan supaya tidak ada dua salinan predikat yang bisa menyimpang.
   Namanya sengaja netral (`aksiPesanTersedia`), bukan nama salah satu aksi
   saja, karena predikat yang sama menggerbangi "Balas" DAN "Teruskan".
   Sumber yang tidak layak: catatan internal (isi untuk toko) dan outgoing
   yang belum terkirim (tidak pernah sampai ke siapa pun). Aturan yang sama
   ditegakkan server (GUD-001). */
function aksiPesanTersedia(m) {
    const internal = m.is_internal === true || m.is_internal === 1 || m.is_internal === '1';
    const belumTerkirim = m.direction === 'outgoing' && m.send_status !== 'sent';

    return !internal && !belumTerkirim;
}

/* REQ-008/AC-008: label "Diteruskan" dibangun dari kolom `is_forwarded`
   SAJA -- tidak pernah dari `forward_marker_applied` milik Gateway, supaya
   label tetap konsisten apa pun metode penanda yang dipakai Gateway.
   Nilainya bisa datang sebagai true/1/"1" lewat json_encode (pola yang
   sama dipakai is_internal). */
function renderLabelDiteruskan(m) {
    const forwarded = m.is_forwarded === true || m.is_forwarded === 1 || m.is_forwarded === '1';

    if (!forwarded) return '';

    return '<div class="inbox-forward-label"><i class="fas fa-share"></i> Diteruskan</div>';
}

// ================================================================
// KEGAGALAN MEDIA (plan-bugfix-inbox-media-unavailable-v1.0)
// ================================================================
// Sebelum ini SATU latch (mediaGagal) menandai media gagal SELAMANYA dan
// SELALU menyebut "kemungkinan sudah kadaluarsa" -- padahal <img onerror>
// tidak bisa membedakan "Gateway tidak bisa dihubungi" dari "media
// kadaluarsa". Fungsi-fungsi di bawah memisahkan keduanya:
//   - 'kadaluarsa' (410)                -> latch permanen, tanpa percobaan ulang
//   - 'sementara'  (502/503/504/jaringan) -> dicoba lagi, jumlah + jeda dibatasi
//   - 'lain'                            -> pesan generik, tetap dicoba (dibatasi)
// Jalur sukses TIDAK tersentuh: pemeriksaan status hanya jalan SETELAH error.

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
            'Lampiran terlalu besar untuk ditampilkan (batas ' + threadConfig.maxMediaDownloadMb + 'MB)</div>';
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
    fetch(threadConfig.mediaBaseUrl + kunci, {
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

// Tahap 4: `extra_json` datang sebagai string JSON dari API; parse aman
// (data lama/tipe lain tidak punya kolom ini).
function parseExtraJson(m) {
    if (!m || !m.extra_json) return null;
    try { return JSON.parse(m.extra_json); } catch (e) { return null; }
}

// Ambil nomor telepon dari vCard kontak WhatsApp (baris TEL;...:+62...).
function nomorDariVcard(vcard) {
    if (!vcard) return null;
    const hasil = String(vcard).match(/TEL[^:]*:\s*([+0-9()\-.\s]+)/i);
    if (!hasil) return null;
    const digit = hasil[1].replace(/[^0-9]/g, '');
    return digit.length >= 8 ? digit : null;
}

function renderIsiPesan(m) {
    const urlMedia = threadConfig.mediaBaseUrl + m.id;

    if (m.message_type === 'image') {
        const kunci = String(m.id);
        const caption = m.text ? '<div class="inbox-media-caption">' + formatTeksWa(m.text) + '</div>' : '';

        // Tahap E: permanen (410) -- JANGAN buat tag <img> lagi untuk
        // pesan ini sama sekali, mencegah polling 4 detik terus meminta
        // ulang media yang sudah dipastikan kadaluarsa.
        if (mediaGagal.has(kunci)) {
            return htmlMediaTidakTersedia('kadaluarsa', 'image') + caption;
        }
        // Kegagalan sementara: tampilkan placeholder yang jujur,
        // KECUALI jatah percobaan ulangnya sudah waktunya (REQ-003/REQ-004).
        const entriSementara = mediaSementara.get(kunci);
        if (entriSementara && !bolehCobaLagiMedia(entriSementara)) {
            return htmlMediaTidakTersedia(entriSementara.kategori, 'image') + caption;
        }
        // Tahap F -- jangan buat <img> sama sekali kalau sudah TAHU
        // bakal gagal (belum ada di disk lokal DAN Gateway terputus)
        // -- beda dari mediaGagal (baru tahu SETELAH gagal request).
        // Pesan placeholder SENGAJA beda dari yang di atas (410
        // kadaluarsa, Tahap E) supaya kasir tidak bingung 2 penyebab
        // berbeda dikira sama.
        if (!gatewayTerhubung && !m.media_local_filename) {
            return '<div class="inbox-media-unavailable"><i class="fas fa-wifi"></i> Gateway terputus -- gambar belum bisa dimuat, coba lagi nanti</div>' + caption;
        }
        // Percobaan ulang: hitung + catat waktunya SEKARANG, supaya
        // kegagalan berikutnya tidak memicu request tiap 4 detik.
        if (entriSementara) {
            entriSementara.cobaan += 1;
            entriSementara.terakhirMs = Date.now();
        }
        // onerror: penyebab sebenarnya BELUM diketahui -- <img> tidak
        // membawa status HTTP apa pun. tanganiMediaGagal() yang
        // memeriksanya sekali dan memilih pesan yang jujur.
        return '<img src="' + urlMedia + '" alt="Gambar" class="inbox-media-image" data-media-jenis="image" ' +
            'onload="mediaSementara.delete(\'' + kunci + '\')" ' +
            'onerror="tanganiMediaGagal(this, \'' + kunci + '\')">' + caption;
    }

    if (m.message_type === 'sticker') {
        // Sticker TIDAK PERNAH punya caption di WhatsApp -- beda dari
        // image/document, tidak perlu render m.text sama sekali.
        const kunci = String(m.id);

        if (mediaGagal.has(kunci)) {
            return htmlMediaTidakTersedia('kadaluarsa', 'sticker');
        }
        const entriSementara = mediaSementara.get(kunci);
        if (entriSementara && !bolehCobaLagiMedia(entriSementara)) {
            return htmlMediaTidakTersedia(entriSementara.kategori, 'sticker');
        }
        // Tahap F -- lihat catatan sama di blok image di atas.
        if (!gatewayTerhubung && !m.media_local_filename) {
            return '<div class="inbox-media-unavailable"><i class="fas fa-wifi"></i> Gateway terputus -- sticker belum bisa dimuat, coba lagi nanti</div>';
        }
        if (entriSementara) {
            entriSementara.cobaan += 1;
            entriSementara.terakhirMs = Date.now();
        }
        return '<img src="' + urlMedia + '" alt="Sticker" class="inbox-media-sticker" data-media-jenis="sticker" ' +
            'onload="mediaSementara.delete(\'' + kunci + '\')" ' +
            'onerror="tanganiMediaGagal(this, \'' + kunci + '\')">';
    }

    if (m.message_type === 'document') {
        const namaFile = m.media_filename || 'Dokumen';
        return '<a href="' + urlMedia + '" target="_blank" class="inbox-media-document">' +
            '<i class="fas fa-file-alt"></i> ' + escapeHtmlInbox(namaFile) +
            '</a>' +
            (m.text ? '<div class="inbox-media-caption">' + formatTeksWa(m.text) + '</div>' : '');
    }

    // Audio (termasuk voice note -- tidak dibedakan, lihat catatan
    // desain di InboxGatewayApi::messages()) dan video: SENGAJA TIDAK
    // ADA player/download sama sekali -- binary-nya tidak pernah
    // diambil AuliaPos maupun Gateway, cukup placeholder yang jelas
    // + caption (kalau ada). Kasir yang perlu dengar/lihat isinya
    // buka langsung dari WhatsApp Web/HP toko.
    if (m.message_type === 'audio' || m.message_type === 'video') {
        const label = m.message_type === 'audio' ? 'audio' : 'video';
        const icon = m.message_type === 'audio' ? 'fa-microphone' : 'fa-video';
        return '<div class="inbox-media-unavailable" style="font-style:normal;">' +
            '<i class="fas ' + icon + '"></i> Customer mengirim ' + label + ' — cek WhatsApp Web.' +
            '</div>' +
            (m.text ? '<div class="inbox-media-caption">' + formatTeksWa(m.text) + '</div>' : '');
    }

    // Tahap 4 -- lokasi: kartu berisi nama/alamat + tautan peta.
    if (m.message_type === 'location') {
        const extra = parseExtraJson(m);
        if (extra && extra.kind === 'location') {
            const koordinat = extra.latitude + ', ' + extra.longitude;
            const peta = 'https://www.google.com/maps?q=' + encodeURIComponent(koordinat);
            const judul = extra.name ? escapeHtmlInbox(extra.name) : 'Lokasi';
            const alamat = extra.address
                ? '<div style="font-size:12px;opacity:.75;">' + escapeHtmlInbox(extra.address) + '</div>'
                : '';
            const langsung = extra.live
                ? ' <span style="font-size:11px;opacity:.75;">(langsung)</span>'
                : '';
            return '<a href="' + peta + '" target="_blank" rel="noopener" style="display:block;text-decoration:none;color:inherit;">' +
                '<i class="fas fa-map-marker-alt" style="color:#e11d48;"></i> <strong>' + judul + '</strong>' + langsung +
                '<div style="font-size:12px;opacity:.75;">' + escapeHtmlInbox(koordinat) + '</div>' + alamat +
                '</a>';
        }
        return '<div class="inbox-media-unavailable" style="font-style:normal;"><i class="fas fa-map-marker-alt"></i> Lokasi</div>';
    }

    // Tahap 4 -- kontak: daftar nama + nomor (dari vCard).
    if (m.message_type === 'contact') {
        const extra = parseExtraJson(m);
        const daftar = extra && Array.isArray(extra.contacts) ? extra.contacts : [];
        if (!daftar.length) {
            return '<div class="inbox-media-unavailable" style="font-style:normal;"><i class="fas fa-address-card"></i> Kontak</div>';
        }
        const baris = daftar.map(function (c) {
            const nama = c && c.display_name ? escapeHtmlInbox(c.display_name) : 'Kontak';
            const nomor = c ? nomorDariVcard(c.vcard) : null;
            const telp = nomor ? ' <span style="opacity:.75;">+' + escapeHtmlInbox(nomor) + '</span>' : '';
            return '<div style="font-size:13px;"><i class="fas fa-user"></i> ' + nama + telp + '</div>';
        }).join('');
        return '<div style="font-style:normal;">' + baris + '</div>';
    }

    // Penanda teks (`unsupported`): samakan FORMAT dengan placeholder
    // audio/video -- kotak abu-abu + ikon + teks, bukan bubble teks biasa.
    // Ikon dipilih dari kata kunci penanda; default ikon netral.
    if (m.message_type === 'unsupported') {
        const teks = (m.text || '').trim();
        let ikon = 'fa-comment-slash';
        if (/lihat-sekali/i.test(teks)) ikon = 'fa-eye-slash';
        else if (/video singkat/i.test(teks)) ikon = 'fa-video';
        else if (/polling/i.test(teks)) ikon = 'fa-poll';
        else if (/undangan acara/i.test(teks)) ikon = 'fa-calendar-day';
        else if (/katalog produk/i.test(teks)) ikon = 'fa-box-open';
        else if (/file besar/i.test(teks)) ikon = 'fa-file';
        else if (/album/i.test(teks)) ikon = 'fa-images';
        else if (/tombol|daftar/i.test(teks)) ikon = 'fa-reply';

        return '<div class="inbox-media-unavailable" style="font-style:normal;">' +
            '<i class="fas ' + ikon + '"></i> ' + escapeHtmlInbox(teks || '[Pesan belum didukung]') +
            '</div>';
    }

    return formatTeksWa(m.text);
}

// ================================================================
// RENDER THREAD (per pesan, P3)
// ================================================================

/* HTML satu bubble. Penanda `data-id` memberi kunci per pesan. HTML inilah
   yang dibandingkan antar polling: apa pun yang memengaruhi tampilan
   (data server DAN state sisi klien seperti mediaGagal, mediaSementara,
   gatewayTerhubung, pesanTerkirimTanpaKutipan) sudah ikut di dalamnya,
   jadi tidak ada "tanda tangan" terpisah yang bisa lupa satu state. */
function renderBubbleHtml(m) {
    const internal = m.is_internal === true || m.is_internal === 1 || m.is_internal === '1';
    const arah = internal ? 'internal-note' : (m.direction === 'outgoing' ? 'outgoing' : 'incoming');
    const senderLabel = (m.sender_name) ?
        '<div class="bubble-sender">' + escapeHtmlInbox(m.sender_name) + '</div>' :
        '';
    const internalLabel = internal ?
        '<div class="inbox-internal-label"><i class="fas fa-sticky-note"></i> Internal</div>' :
        '';
    // Penanda "Terkirim tanpa kutipan" hanya untuk pesan yang
    // terkirim BARU SESAJA di sesi ini (lihat pesanTerkirimTanpaKutipan).
    const penandaKutipan = pesanTerkirimTanpaKutipan.has(m.id) ?
        '<div class="penanda-tanpa-kutipan"><i class="fas fa-exclamation-triangle"></i> Terkirim tanpa kutipan</div>' :
        '';

    return '<div class="inbox-bubble ' + arah + '" data-id="' + escapeHtmlInbox(m.id) + '">' +
        internalLabel +
        senderLabel +
        renderLabelDiteruskan(m) +
        renderKotakKutipan(m) +
        renderIsiPesan(m) +
        penandaKutipan +
        renderAksiPesan(m) +
        '<div class="bubble-meta">' + renderCentangKirim(m) + formatWaktuInbox(m.message_timestamp) + '</div>' +
        '</div>';
}

/* Penanda kirim pada pesan keluar (AC-30): satu centang untuk `sent`, ikon
   "!" untuk `failed`. Centang ganda/biru tidak ada: gateway tidak mengirim
   status delivered/read. Catatan internal tidak pernah dikirim ke pelanggan. */
function renderCentangKirim(m) {
    const internal = m.is_internal === true || m.is_internal === 1 || m.is_internal === '1';

    if (m.direction !== 'outgoing' || internal) return '';
    if (m.send_status === 'sent') return '<i class="fas fa-check inbox-centang" title="Terkirim"></i> ';
    if (m.send_status === 'failed') return '<i class="fas fa-exclamation-circle inbox-gagal" title="Gagal terkirim"></i> ';

    return '';
}

/* Rencana pembaruan thread (fungsi murni, dites di tests/js/inbox-thread.test.js).
   - `htmlLama`: Map kunci -> HTML yang sedang tampil.
   - `itemsBaru`: [{key, html}] urut tampil.
   - `adaDiDom(key)`: false bila elemen kunci itu sudah tidak ada di thread
     (mis. kontainer ditimpa kode lain); item itu digambar ulang.
   Hasil: `remove` = kunci yang dibuang; `ops` = satu aksi per item baru:
   'tambah' (belum ada), 'ganti' (HTML berubah), 'tetap' (tidak disentuh). */
function rencanaPembaruanThread(htmlLama, itemsBaru, adaDiDom) {
    const kunciBaru = new Set(itemsBaru.map(function(it) { return it.key; }));
    const remove = [];

    htmlLama.forEach(function(_html, key) {
        if (!kunciBaru.has(key)) remove.push(key);
    });

    const ops = itemsBaru.map(function(it) {
        let action = 'tambah';

        if (htmlLama.has(it.key) && (!adaDiDom || adaDiDom(it.key))) {
            action = htmlLama.get(it.key) === it.html ? 'tetap' : 'ganti';
        }

        return { key: it.key, html: it.html, action: action };
    });

    return { remove: remove, ops: ops };
}

// kunci -> { html, el }: bubble yang saat ini dikelola thread.
const threadEntries = new Map();

function elemenDariHtml(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    return tpl.content.firstElementChild;
}

function htmlThreadSaatIni() {
    const peta = new Map();
    threadEntries.forEach(function(entri, key) { peta.set(key, entri.html); });
    return peta;
}

function terapkanRencanaThread(container, rencana) {
    rencana.remove.forEach(function(key) {
        const entri = threadEntries.get(key);
        if (entri && entri.el.parentNode) entri.el.remove();
        threadEntries.delete(key);
    });

    // Apa pun di kontainer yang bukan bubble kelolaan (placeholder
    // "Belum ada pesan", dsb.) dibuang sebelum bubble digambar.
    const dikelola = new Set();
    threadEntries.forEach(function(entri) { dikelola.add(entri.el); });
    Array.from(container.children).forEach(function(anak) {
        if (!dikelola.has(anak)) anak.remove();
    });

    let sebelumnya = null;
    rencana.ops.forEach(function(op) {
        let el;

        if (op.action === 'tetap') {
            el = threadEntries.get(op.key).el;
        } else {
            el = elemenDariHtml(op.html);
            const lama = threadEntries.get(op.key);
            if (op.action === 'ganti' && lama && lama.el.parentNode) lama.el.replaceWith(el);
            threadEntries.set(op.key, { html: op.html, el: el });
        }

        // Urutan DOM mengikuti urutan data; elemen yang sudah di tempatnya
        // tidak disentuh (gambar yang termuat tetap node yang sama).
        const seharusnyaDi = sebelumnya ? sebelumnya.nextSibling : container.firstChild;
        if (el !== seharusnyaDi) container.insertBefore(el, seharusnyaDi);
        sebelumnya = el;
    });
}

// ================================================================
// TANGGAL, PENGGABUNGAN, PAGINATION (P1b, P4, P5, P7)
// ================================================================

const BATAS_HALAMAN_KUTIPAN = 5; // maks. halaman lama yang dimuat untuk mencari pesan asal kutipan (AC-18)

function waktuPesan(ts) {
    return new Date(String(ts).replace(' ', 'T'));
}

function awalHari(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

/* Kunci hari (zona waktu browser, sama dengan formatWaktuInbox). '' bila
   timestamp tidak valid: pesan itu tidak memulai pemisah baru. */
function kunciHari(ts) {
    const d = waktuPesan(ts);
    if (isNaN(d.getTime())) return '';
    const dua = function(n) { return (n < 10 ? '0' : '') + n; };

    return d.getFullYear() + '-' + dua(d.getMonth() + 1) + '-' + dua(d.getDate());
}

function labelTanggal(ts, sekarang) {
    const d = waktuPesan(ts);
    const selisih = Math.round((awalHari(sekarang) - awalHari(d)) / 86400000);

    if (selisih === 0) return 'Hari ini';
    if (selisih === 1) return 'Kemarin';

    return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

// Urutan tampil yang sama dengan server: message_timestamp, lalu id.
function urutPesan(a, b) {
    if (a.message_timestamp !== b.message_timestamp) return a.message_timestamp < b.message_timestamp ? -1 : 1;
    return Number(a.id) - Number(b.id);
}

function urutkanPesan(peta) {
    return Array.from(peta.values()).sort(urutPesan);
}

/* Gabungkan pesan yang sudah dikenal dengan jendela TERBARU dari polling
   (fungsi murni). Pesan di dalam rentang jendela tetapi tidak ada di respons
   dianggap dihapus (AC-10). Pesan yang lebih tua dari jendela dipertahankan:
   mereka dimuat lewat "Muat pesan lama", atau baru saja bergeser keluar
   jendela karena pesan baru masuk (AC-27). */
function gabungPesanThread(dikenal, terbaru, adaLagi) {
    const hasil = new Map();

    if (!terbaru.length) return hasil;

    dikenal.forEach(function(m, key) {
        const didalamJendela = !adaLagi || urutPesan(m, terbaru[0]) >= 0;
        if (!didalamJendela) hasil.set(key, m);
    });
    terbaru.forEach(function(m) { hasil.set(String(m.id), m); });

    return hasil;
}

function htmlTombolMuatLama(memuat) {
    return '<div class="inbox-muat-lama"><button type="button" class="btn btn-outline-secondary btn-sm"' +
        (memuat ? ' disabled' : ' onclick="muatPesanLama()"') + '>' +
        (memuat ? '<i class="fas fa-spinner fa-spin"></i> Memuat...' : '<i class="fas fa-history"></i> Muat pesan lama') +
        '</button></div>';
}

/* Daftar item thread urut tampil (fungsi murni): tombol "Muat pesan lama"
   bila masih ada riwayat, pemisah tanggal (kapsul) sebelum pesan pertama
   tiap hari, dan bubble pesan. Pemisah bukan pesan: tidak masuk pesanCached. */
function susunItemThread(messages, opsi) {
    const items = [];
    let hariTerakhir = '';

    if (opsi.olderHasMore) items.push({ key: 'muat-lama', html: htmlTombolMuatLama(opsi.memuatLama) });

    messages.forEach(function(m) {
        const hari = kunciHari(m.message_timestamp);

        if (hari && hari !== hariTerakhir) {
            items.push({ key: 'tgl:' + hari, html: '<div class="inbox-tanggal"><span>' + escapeHtmlInbox(labelTanggal(m.message_timestamp, opsi.now)) + '</span></div>' });
            hariTerakhir = hari;
        }

        items.push({ key: String(m.id), html: renderBubbleHtml(m) });
    });

    return items;
}

// State per percakapan yang sedang dibuka.
let threadKonvId = null;
let threadDikenal = new Map();      // String(id) -> pesan (jendela terbaru + halaman lama)
let threadOlderHasMore = false;     // masih ada riwayat yang lebih lama di server
let threadOlderDimuat = false;      // sudah pernah memuat halaman lama di percakapan ini
let threadMemuatLama = false;
let threadKunciAkhir = null;        // id pesan terakhir pada render sebelumnya
let threadJumlahBaru = 0;           // pesan baru yang masuk selagi kasir membaca ke atas

/* Kosongkan state thread. `htmlKosong` (opsional) menjadi isi kontainer,
   mis. placeholder "Belum ada percakapan dipilih". */
function resetThread(htmlKosong) {
    threadEntries.clear();
    pesanCached = {};
    threadDikenal = new Map();
    threadOlderHasMore = false;
    threadOlderDimuat = false;
    threadMemuatLama = false;
    threadKunciAkhir = null;
    threadJumlahBaru = 0;
    perbaruiTombolGulung();
    document.getElementById('threadMessages').innerHTML = htmlKosong || '';
}

function perbaruiTombolGulung() {
    const tombol = document.getElementById('btnGulungBaru');
    if (!tombol) return;

    tombol.style.display = threadJumlahBaru > 0 ? 'flex' : 'none';
    const jumlah = tombol.querySelector('.inbox-gulung-jumlah');
    if (jumlah) jumlah.textContent = String(threadJumlahBaru);
}

function gulungKeTerbaru() {
    const container = document.getElementById('threadMessages');
    container.scrollTop = container.scrollHeight;
    threadJumlahBaru = 0;
    perbaruiTombolGulung();
}

function renderPesan(messages, paksaScroll, opsi) {
    const container = document.getElementById('threadMessages');
    opsi = opsi || {};

    // Tentukan SEBELUM DOM berubah -- scrollHeight/scrollTop
    // lama masih relevan di sini. Toleransi 80px: kasir yang sudah
    // scroll ke atas membaca pesan lama TIDAK boleh diseret balik
    // ke bawah oleh polling (setiap 4 detik); tapi kalau memang
    // sudah dekat bawah (baru geser dikit / belum sempat scroll),
    // tetap auto-scroll seperti biasa supaya pesan baru terlihat.
    const dekatBawah = (container.scrollHeight - container.scrollTop - container.clientHeight) <= 80;
    const harusScroll = !!paksaScroll || dekatBawah;

    if (!container.__gulungDipasang && container.addEventListener) {
        container.__gulungDipasang = true;
        container.addEventListener('scroll', function() {
            if ((container.scrollHeight - container.scrollTop - container.clientHeight) <= 80 && threadJumlahBaru > 0) {
                threadJumlahBaru = 0;
                perbaruiTombolGulung();
            }
        });
    }

    if (!messages.length) {
        resetThread('<div class="inbox-thread-empty"><i class="fas fa-comment-dots fa-2x me-2"></i> Belum ada pesan di percakapan ini.</div>');
        if (harusScroll) container.scrollTop = container.scrollHeight;
        return;
    }

    // Cache untuk pilihKutipan() (Balas Pesan, REQ-005) dan Teruskan --
    // berisi SEMUA pesan yang tampil, tidak menahan percakapan sebelumnya.
    pesanCached = {};
    messages.forEach(function(m) { pesanCached[m.id] = m; });

    // Penghitung pesan baru (AC-29): hanya pesan yang datang SETELAH pesan
    // terakhir render sebelumnya, dan hanya bila kasir tidak sedang di dasar.
    const kunciAkhirBaru = String(messages[messages.length - 1].id);
    if (harusScroll) {
        threadJumlahBaru = 0;
    } else if (!opsi.dariMuatLama && threadKunciAkhir !== null) {
        const idx = messages.findIndex(function(m) { return String(m.id) === threadKunciAkhir; });
        if (idx >= 0) threadJumlahBaru += messages.length - 1 - idx;
    }
    threadKunciAkhir = kunciAkhirBaru;
    perbaruiTombolGulung();

    const items = susunItemThread(messages, { now: new Date(), olderHasMore: threadOlderHasMore, memuatLama: threadMemuatLama });
    const rencana = rencanaPembaruanThread(htmlThreadSaatIni(), items, function(key) {
        const entri = threadEntries.get(key);
        return !!entri && entri.el.parentNode === container;
    });
    terapkanRencanaThread(container, rencana);

    if (harusScroll) {
        container.scrollTop = container.scrollHeight;
    }
}

/* Hasil polling "pesan terbaru" untuk satu percakapan. Berganti percakapan
   mengosongkan semuanya (AC-10); selain itu jendela terbaru digabung dengan
   halaman lama yang sudah dimuat. */
function terimaPesanTerbaru(konvId, json, paksaScroll) {
    if (konvId !== threadKonvId) {
        resetThread('');
        threadKonvId = konvId;
    }

    const terbaru = json.messages || [];
    threadDikenal = gabungPesanThread(threadDikenal, terbaru, !!json.has_more);

    // Sebelum ada halaman lama yang dimuat, "ada riwayat lama" persis
    // has_more jendela terbaru; sesudahnya hanya bisa menjadi false.
    if (!threadOlderDimuat) threadOlderHasMore = !!json.has_more;
    else if (!json.has_more) threadOlderHasMore = false;

    renderPesan(urutkanPesan(threadDikenal), paksaScroll);
}

function gambarUlangThread() {
    renderPesan(urutkanPesan(threadDikenal), false, { dariMuatLama: true });
}

function tampilkanToastThread(teks, jenis) {
    if (typeof showToast === 'function') showToast(teks, jenis);
}

/* "Muat pesan lama": ambil halaman sebelum pesan tertua yang tampil
   (GET .../messages?before_id=). Posisi baca dijaga: tinggi yang bertambah
   di atas ditambahkan ke scrollTop (AC-27). Mengembalikan Promise<boolean>. */
function muatPesanLama() {
    const urut = urutkanPesan(threadDikenal);

    if (threadMemuatLama || !threadOlderHasMore || threadKonvId === null || !urut.length) {
        return Promise.resolve(false);
    }

    const konv = threadKonvId;
    threadMemuatLama = true;
    gambarUlangThread();

    return fetch(threadConfig.apiMessagesUrl + '/' + konv + '/messages?before_id=' + encodeURIComponent(urut[0].id))
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (konv !== threadKonvId) return false;
            if (json.status !== 'success') throw new Error(json.message || 'gagal');

            const container = document.getElementById('threadMessages');
            const tinggiSebelum = container.scrollHeight;
            const posisiSebelum = container.scrollTop;

            (json.messages || []).forEach(function(m) { threadDikenal.set(String(m.id), m); });
            threadOlderHasMore = !!json.has_more;
            threadOlderDimuat = true;
            threadMemuatLama = false;

            gambarUlangThread();
            container.scrollTop = posisiSebelum + (container.scrollHeight - tinggiSebelum);

            return true;
        })
        .catch(function() {
            if (konv === threadKonvId) {
                threadMemuatLama = false;
                gambarUlangThread();
            }
            tampilkanToastThread('Gagal memuat pesan lama.', 'warning');
            return false;
        });
}

function sorotBubble(el) {
    el.classList.add('inbox-bubble-sorot');
    setTimeout(function() { el.classList.remove('inbox-bubble-sorot'); }, 1600);
}

function cariDanLoncat(waId, halaman) {
    let sumber = null;
    threadDikenal.forEach(function(m) { if (m.wa_message_id === waId) sumber = m; });

    if (sumber) {
        const entri = threadEntries.get(String(sumber.id));
        if (entri) {
            entri.el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            sorotBubble(entri.el);
            return Promise.resolve(true);
        }
    }

    if (threadOlderHasMore && halaman < BATAS_HALAMAN_KUTIPAN) {
        return muatPesanLama().then(function(berhasil) {
            return berhasil ? cariDanLoncat(waId, halaman + 1) : false;
        });
    }

    tampilkanToastThread('Pesan asal tidak ada di riwayat yang dimuat', 'warning');
    return Promise.resolve(false);
}

/* Klik pada kotak kutipan (P5): gulung ke pesan asal dan sorot sebentar.
   Pesan asal dicari lewat quoted_wa_message_id = wa_message_id; bila belum
   dimuat, halaman lama dimuat berurutan (maks. BATAS_HALAMAN_KUTIPAN). */
function loncatKeKutipan(el) {
    const waId = el && el.getAttribute ? el.getAttribute('data-kutipan-wa') : null;
    if (!waId) return Promise.resolve(false);

    return cariDanLoncat(waId, 0);
}

/* Bubble pesan keluar yang baru dikirim, tampil tanpa menunggu polling.
   Memakai kunci dan HTML yang sama dengan jalur polling, jadi polling
   berikutnya mengganti bubble ini (bila datanya berbeda) alih-alih
   menggandakannya (AC-12). */
function tampilkanBubbleOutgoing(m) {
    const container = document.getElementById('threadMessages');
    const emptyState = container.querySelector('.inbox-thread-empty');
    if (emptyState) container.innerHTML = '';

    pesanCached[m.id] = m;

    const key = String(m.id);
    const baru = Object.assign({ direction: 'outgoing' }, m);
    threadDikenal.set(key, baru);
    threadKunciAkhir = key;

    // Bila hari berganti atau tombol "muat lama"/pemisah belum ada, gambar
    // ulang lewat jalur yang sama dengan polling supaya pemisah ikut benar.
    renderPesan(urutkanPesan(threadDikenal), true);
}
