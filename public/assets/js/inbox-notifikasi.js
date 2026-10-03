/*
 * Notifikasi lintas halaman untuk Inbox WhatsApp (judul tab, favicon,
 * suara, toast): dipicu oleh percakapan 'perlu_dibalas' yang RELEVAN bagi
 * user yang login (filter kepemilikan dilakukan di server --
 * Inbox::apiNotifikasiRingkas() -- bukan di sini).
 *
 * Dimuat dari `app/Views/layout/main.php` (semua halaman, bukan cuma
 * /inbox) lewat:
 *   window.INBOX_NOTIF_CONFIG = { ringkasUrl: '...', inboxUrl: '...' };
 *
 * Script biasa (bukan module): fungsi murni di bagian atas file dites
 * langsung lewat vm.runInContext (lihat tests/js/inbox-notifikasi.test.js),
 * fungsi DOM/jaringan di bagian bawah menyambungkannya ke halaman nyata.
 */
const notifConfig = (typeof globalThis.INBOX_NOTIF_CONFIG === 'object' && globalThis.INBOX_NOTIF_CONFIG) || {};

const NOTIF_SEEN_KEY = 'auliaInboxNotifSeen';
const NOTIF_MUTE_KEY = 'auliaInboxNotifMuted';

// ================================================================
// FUNGSI MURNI (testable tanpa DOM nyata)
// ================================================================

/* Baca peta {id: last_message_at} yang sudah pernah ditampilkan, dari
   string JSON (sessionStorage). JSON rusak/kosong -> peta kosong, bukan
   error -- kegagalan baca tidak boleh menghentikan notifikasi. */
function parseNotifSeen(mentah) {
    try {
        const hasil = JSON.parse(mentah || '{}');
        return (hasil && typeof hasil === 'object') ? hasil : {};
    } catch (e) {
        return {};
    }
}

/* Pola sama dengan `statusJadwalTerakhir` di main.php: sessionStorage
   (bukan variabel JS biasa) karena tiap navigasi di aplikasi ini adalah
   full page reload. Tanpa ini, pindah halaman akan memicu toast/beep
   berulang untuk percakapan yang SAMA walau tidak ada pesan baru.
   `belumPernahDicek` (key sessionStorage belum pernah diisi SAMA SEKALI
   di tab ini) membedakan "pesan baru" dari "percakapan yang sudah
   perlu_dibalas SEBELUM kasir membuka halaman ini" -- yang kedua tidak
   boleh membunyikan apa pun pada polling pertama suatu sesi tab. */
function hitungItemBaru(items, sudahDilihat, belumPernahDicek) {
    const petaBaru = {};
    const itemBaru = [];

    items.forEach(function(item) {
        const kunci = String(item.id);
        petaBaru[kunci] = item.last_message_at;

        if (!belumPernahDicek && sudahDilihat[kunci] !== item.last_message_at) {
            itemBaru.push(item);
        }
    });

    return { petaBaru: petaBaru, itemBaru: itemBaru };
}

function formatJudulTab(judulAsli, jumlah) {
    return jumlah > 0 ? '(' + jumlah + ') ' + judulAsli : judulAsli;
}

// Satu orang -> langsung sebut namanya; banyak orang -> sebut semua
// nama (dibatasi MAKS_NAMA_TOAST supaya toast tidak jadi sangat panjang
// kalau banyak percakapan masuk sekaligus).
const MAKS_NAMA_TOAST = 5;

function formatPesanToastNotif(itemBaru) {
    if (itemBaru.length === 1) return 'Pesan baru dari ' + itemBaru[0].label;

    const nama = itemBaru.map(function(i) { return i.label; });

    if (nama.length <= MAKS_NAMA_TOAST) return 'Pesan baru dari ' + nama.join(', ');

    return 'Pesan baru dari ' + nama.slice(0, MAKS_NAMA_TOAST).join(', ') +
        ', +' + (nama.length - MAKS_NAMA_TOAST) + ' lainnya';
}

// Satu orang -> deep-link ke percakapan itu; banyak orang -> buka Inbox
// apa adanya (tidak ada satu percakapan "paling benar" untuk dituju).
function tujuanKlikToastNotif(itemBaru) {
    return itemBaru.length === 1 ? itemBaru[0].id : null;
}

/* Toast mana yang sudah tidak relevan lagi (SEMUA percakapan yang
   disebutnya sudah ditangani/tidak lagi 'perlu_dibalas' bagi user ini),
   dipanggil tiap polling supaya toast sticky tidak menumpuk selamanya.
   `entries`: [{key, ids}] toast yang sedang tampil. `idMasihRelevan`:
   id percakapan dari polling TERBARU (apa adanya, boleh number/string). */
function entriUntukDibuang(entries, idMasihRelevan) {
    const relevanSet = new Set(idMasihRelevan.map(String));

    return entries
        .filter(function(e) { return !e.ids.some(function(id) { return relevanSet.has(String(id)); }); })
        .map(function(e) { return e.key; });
}

// ================================================================
// DOM / JARINGAN
// ================================================================

function bacaNotifSeen() {
    try {
        return parseNotifSeen(sessionStorage.getItem(NOTIF_SEEN_KEY));
    } catch (e) {
        return {};
    }
}

function simpanNotifSeen(peta) {
    try {
        sessionStorage.setItem(NOTIF_SEEN_KEY, JSON.stringify(peta));
    } catch (e) { /* private mode / storage penuh -- bukan fatal, lanjut tanpa persist */ }
}

function notifDibungkam() {
    try {
        return localStorage.getItem(NOTIF_MUTE_KEY) === '1';
    } catch (e) {
        return false;
    }
}

function alihkanMuteNotif() {
    const bungkam = !notifDibungkam();
    try { localStorage.setItem(NOTIF_MUTE_KEY, bungkam ? '1' : '0'); } catch (e) { /* abaikan */ }
    perbaruiIkonMuteNotif();
}

function perbaruiIkonMuteNotif() {
    const ikon = document.getElementById('inboxNotifMuteIcon');
    if (ikon) ikon.className = notifDibungkam() ? 'fas fa-bell-slash' : 'fas fa-bell';
}

// Beep pendek via Web Audio -- tanpa file audio baru. Browser bisa
// menolak membuat AudioContext sebelum ada interaksi user di tab ini
// (kebijakan autoplay); dibungkus try/catch supaya kegagalan beep tidak
// pernah mengganggu toast/judul tab.
function mainkanBeepNotif() {
    if (notifDibungkam()) return;
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = 880;
        gain.gain.value = 0.15;
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start();
        osc.stop(ctx.currentTime + 0.15);
        setTimeout(function() { ctx.close(); }, 300);
    } catch (e) { /* autoplay diblokir / tidak didukung -- abaikan, toast tetap tampil */ }
}

const JUDUL_ASLI_TAB = (typeof document !== 'undefined' && document.title) || '';
let faviconAsliHref = null;

function perbaruiJudulTab(jumlah) {
    document.title = formatJudulTab(JUDUL_ASLI_TAB, jumlah);
}

// Titik merah digambar ulang di atas favicon asli lewat canvas -- tidak
// perlu aset gambar baru, otomatis ikut favicon yang sedang dipakai.
function perbaruiFaviconDot(tampilkanDot) {
    const link = document.querySelector('link[rel="icon"]') || document.querySelector('link[rel="shortcut icon"]');
    if (!link) return;
    if (faviconAsliHref === null) faviconAsliHref = link.href;
    if (!tampilkanDot) {
        link.href = faviconAsliHref;
        return;
    }

    const img = new Image();
    img.onload = function() {
        try {
            const ukuran = 32;
            const canvas = document.createElement('canvas');
            canvas.width = ukuran;
            canvas.height = ukuran;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, ukuran, ukuran);
            ctx.beginPath();
            ctx.arc(ukuran - 7, 7, 6, 0, 2 * Math.PI);
            ctx.fillStyle = '#dc3545';
            ctx.fill();
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = 1.5;
            ctx.stroke();
            link.href = canvas.toDataURL('image/png');
        } catch (e) { /* favicon lintas-origin / canvas diblokir -- biarkan tanpa dot */ }
    };
    img.onerror = function() {};
    img.src = faviconAsliHref;
}

function bukaInboxKePercakapan(id) {
    window.open(notifConfig.inboxUrl + '?conversation_id=' + id, 'AuliaInbox', 'width=1200,height=800');
}

function bukaInboxDariToast(idTujuan) {
    if (idTujuan === null) {
        window.open(notifConfig.inboxUrl, 'AuliaInbox', 'width=1200,height=800');
    } else {
        bukaInboxKePercakapan(idTujuan);
    }
}

// Toast notifikasi Inbox TIDAK memakai showToast()/#liveToast (satu slot,
// auto-hide 3 detik, dipakai bersama fitur lain seperti reminder jadwal)
// -- di sini sengaja sticky DAN bisa menumpuk, jadi komponennya sendiri.
// { key, ids, el } per toast yang sedang tampil.
let toastAktif = [];
let toastKeyBerikutnya = 1;

// Dibangun lewat createElement/textContent, BUKAN innerHTML dengan teks
// dinamis -- `label` berasal dari whatsapp_name/contact_name, yang bisa
// diisi bebas oleh pelanggan/kasir, jadi tidak boleh ditafsirkan sebagai
// HTML (lihat Inbox::apiNotifikasiRingkas()).
function buatToastNotif(itemBaru) {
    const container = document.getElementById('inboxNotifToastStack');
    if (!container) return;

    const key = toastKeyBerikutnya++;
    const ids = itemBaru.map(function(i) { return i.id; });
    const idTujuan = tujuanKlikToastNotif(itemBaru);

    const el = document.createElement('div');
    el.className = 'toast align-items-center border-0 text-bg-primary show';
    el.setAttribute('role', 'alert');

    const wrap = document.createElement('div');
    wrap.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.style.cursor = 'pointer';
    body.textContent = formatPesanToastNotif(itemBaru);
    body.onclick = function() { bukaInboxDariToast(idTujuan); };

    const tutup = document.createElement('button');
    tutup.type = 'button';
    tutup.className = 'btn-close btn-close-white me-2 m-auto';
    tutup.setAttribute('aria-label', 'Tutup');
    tutup.onclick = function() { buangToastNotif(key); };

    wrap.appendChild(body);
    wrap.appendChild(tutup);
    el.appendChild(wrap);
    container.prepend(el); // toast baru tampil di atas toast lama

    toastAktif.push({ key: key, ids: ids, el: el });
}

function buangToastNotif(key) {
    const idx = toastAktif.findIndex(function(e) { return e.key === key; });
    if (idx === -1) return;

    const entri = toastAktif[idx];
    if (entri.el.parentNode) entri.el.parentNode.removeChild(entri.el);
    toastAktif.splice(idx, 1);
}

function bersihkanToastSelesai(idMasihRelevan) {
    entriUntukDibuang(toastAktif, idMasihRelevan).forEach(buangToastNotif);
}

function muatNotifikasiInbox() {
    fetch(notifConfig.ringkasUrl)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status !== 'success') return;
            const items = d.items || [];

            perbaruiJudulTab(items.length);
            perbaruiFaviconDot(items.length > 0);
            bersihkanToastSelesai(items.map(function(i) { return i.id; }));

            const belumPernahDicek = sessionStorage.getItem(NOTIF_SEEN_KEY) === null;
            const hasil = hitungItemBaru(items, bacaNotifSeen(), belumPernahDicek);
            simpanNotifSeen(hasil.petaBaru);

            if (!hasil.itemBaru.length) return;

            mainkanBeepNotif();
            buatToastNotif(hasil.itemBaru);
        })
        .catch(function() { /* siklus polling berikutnya coba lagi */ });
}

function mulaiNotifikasiInbox(intervalMs) {
    perbaruiIkonMuteNotif();
    muatNotifikasiInbox();
    setInterval(muatNotifikasiInbox, intervalMs);
}
