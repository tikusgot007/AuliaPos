// Panel saran M.A.R.A di Inbox (TODO-MARA1) -- draft balasan,
// daftar-periksa (checklist), dan peringatan untuk percakapan yang
// sedang dibuka kasir.
//
// Arah data: M.A.R.A POST ke /api/inbox/mara/suggestions -> AuliaPos
// simpan (tabel mara_suggestions) -> file ini FETCH saran aktif per
// percakapan saat kasir membuka/menyegarkan percakapan (Opsi A "cukup
// simpan"). TIDAK ada push realtime.
//
// Dependensi global dari index.php / inbox-page.js (semua <script>
// biasa di halaman yang sama, bukan module): conversationAktif,
// showToast, dan escapeHtmlInbox (dari inbox-thread.js).
// window.INBOX_PAGE_CONFIG.url.suggestionBaseUrl diisi index.php.
//
// Teks saran dari M.A.R.A TIDAK DIPERCAYA -> SELALU lewat escapeHtmlInbox
// sebelum disisipkan ke innerHTML (AC-15, anti-XSS).
//
// Run test: node tests/js/inbox-suggestions.test.js (no framework, no deps)

// Saran yang sedang ditampilkan (array baris terakhir dari server).
var daftarSaranMara = [];
// Penanda request terakhir: hasil fetch lama yang datang belakangan
// (percakapan sudah berganti) tidak boleh menimpa panel.
var saranRequestSeq = 0;
// Throttle refresh dari loop polling pesan (muatUlangPesan jalan tiap
// ~4 detik). Saran berubah sangat jarang (maks 3 per percakapan), jadi
// memanggil endpoint tiap tick hanya membebani server tanpa manfaat.
// Refresh PAKSA (kasir pindah percakapan) selalu lewat.
var saranTerakhirId = null;
var saranTerakhirAmbilMs = 0;
var SARAN_THROTTLE_MS = 60000;

/**
 * Ambil & render saran aktif untuk satu percakapan.
 *
 * @param conversationId
 * @param {boolean} force true = selalu fetch (dipakai saat kasir pindah
 *   percakapan); false = throttle (dipakai dari loop polling).
 */
function muatSaranMara(conversationId, force) {
    const panel = document.getElementById('panelSaranMara');
    if (!panel) return;

    if (!conversationId) {
        daftarSaranMara = [];
        saranTerakhirId = null;
        saranTerakhirAmbilMs = 0;
        panel.style.display = 'none';
        panel.innerHTML = '';
        return;
    }

    // Throttle: lewati kalau percakapan sama dan baru saja diambil.
    const sekarang = Date.now();
    if (!force && conversationId === saranTerakhirId
        && (sekarang - saranTerakhirAmbilMs) < SARAN_THROTTLE_MS) {
        return;
    }

    saranTerakhirId = conversationId;
    saranTerakhirAmbilMs = sekarang;

    const base = (window.INBOX_PAGE_CONFIG && window.INBOX_PAGE_CONFIG.url
        && window.INBOX_PAGE_CONFIG.url.suggestionBaseUrl) || '';
    const seq = ++saranRequestSeq;

    fetch(base + conversationId + '/suggestions')
        .then(function(res) { return res.json(); })
        .then(function(json) {
            // Abaikan hasil basi: percakapan sudah berganti sejak request ini.
            if (seq !== saranRequestSeq) return;
            if (!json || json.status !== 'success') {
                daftarSaranMara = [];
                renderSaranMara();
                return;
            }
            daftarSaranMara = Array.isArray(json.suggestions) ? json.suggestions : [];
            renderSaranMara();
        })
        .catch(function() {
            if (seq !== saranRequestSeq) return;
            // Gagal fetch bukan kondisi fatal -- sembunyikan panel, jangan
            // ganggu alur kasir (fail-safe, sama semangat endpoint baca).
            daftarSaranMara = [];
            renderSaranMara();
            // Boleh dicoba lagi lebih cepat dari throttle kalau memang gagal.
            saranTerakhirAmbilMs = 0;
        });
}

/**
 * Kosongkan & sembunyikan panel (mis. saat tidak ada percakapan aktif).
 */
function kosongkanSaranMara() {
    daftarSaranMara = [];
    saranRequestSeq++;
    saranTerakhirId = null;
    saranTerakhirAmbilMs = 0;
    renderSaranMara();
}

/**
 * Render panel dari daftarSaranMara. Di luar area scroll thread sehingga
 * tidak ikut dihapus saat polling merender ulang riwayat pesan.
 */
function renderSaranMara() {
    const panel = document.getElementById('panelSaranMara');
    if (!panel) return;

    if (!daftarSaranMara.length) {
        panel.style.display = 'none';
        panel.innerHTML = '';
        return;
    }

    const bagian = daftarSaranMara.map(function(s) {
        return renderSatuSaran(s);
    }).join('');

    panel.innerHTML = '<div class="inbox-saran-judul">Saran M.A.R.A</div>' + bagian;
    panel.style.display = '';
}

/**
 * Render satu saran. Warna tepi/kartu mengikuti priority (AC-14):
 * normal -> biru, tinggi -> kuning, darurat -> merah.
 */
function renderSatuSaran(s) {
    const priority = ['normal', 'tinggi', 'darurat'].indexOf(s.priority) !== -1
        ? s.priority
        : 'normal';
    const tipe = s.type || '';
    const kelasPrioritas = 'inbox-saran--' + priority;

    let isi = '';

    if (tipe === 'balasan') {
        // Draft siap-pakai: teks + tombol "Pakai" yang mengisi kotak
        // balasan (TIDAK otomatis terkirim, AC-12).
        isi = '<div class="inbox-saran-teks">' + escapeHtmlInbox(s.text) + '</div>' +
            '<button type="button" class="btn btn-sm btn-primary mt-1" ' +
            'onclick="pakaiSaranBalasan(' + parseInt(s.id, 10) + ')">' +
            '<i class="fas fa-reply"></i> Pakai draft ini</button>';
    } else if (tipe === 'daftar-periksa') {
        // Checklist: tiap item bisa dicentang terpisah (AC-13).
        const items = Array.isArray(s.items) ? s.items : [];
        const baris = items.map(function(item, idx) {
            const id = 'saranCek' + parseInt(s.id, 10) + '_' + idx;
            return '<label class="inbox-saran-item" for="' + id + '">' +
                '<input type="checkbox" id="' + id + '" class="form-check-input">' +
                '<span>' + escapeHtmlInbox(item) + '</span>' +
                '</label>';
        }).join('');
        isi = '<div class="inbox-saran-teks">' + escapeHtmlInbox(s.text) + '</div>' +
            (baris ? '<div class="inbox-saran-checklist">' + baris + '</div>' : '');
    } else if (tipe === 'peringatan') {
        isi = '<div class="inbox-saran-teks">' + escapeHtmlInbox(s.text) + '</div>';
    } else {
        // Tipe tak dikenal (server seharusnya sudah menolak) -- tampilkan
        // teksnya saja, jangan tebak-tebak.
        isi = '<div class="inbox-saran-teks">' + escapeHtmlInbox(s.text) + '</div>';
    }

    return '<div class="inbox-saran ' + kelasPrioritas + '">' +
        '<div class="inbox-saran-tipe">' + escapeHtmlInbox(labelTipeSaran(tipe)) + '</div>' +
        isi +
        '</div>';
}

function labelTipeSaran(tipe) {
    if (tipe === 'balasan') return 'Draft balasan';
    if (tipe === 'daftar-periksa') return 'Daftar periksa';
    if (tipe === 'peringatan') return 'Peringatan';
    return tipe;
}

/**
 * Isi kotak balasan (#teksBalasan) dengan teks saran -- TIDAK mengirim
 * apa pun; kasir yang memutuskan (AC-12). Pola sama dengan Template
 * Balasan Cepat (inbox-template.js): fokus + beri tahu kasir.
 */
function pakaiSaranBalasan(saranId) {
    const saran = daftarSaranMara.filter(function(s) {
        return parseInt(s.id, 10) === parseInt(saranId, 10);
    })[0];

    const textarea = document.getElementById('teksBalasan');
    if (!saran || !textarea) return;

    textarea.value = saran.text || '';

    // Panggil hook resize/auto-grow yang sudah ada kalau tersedia, supaya
    // tinggi kotak menyesuaikan isi (tidak wajib; diabaikan bila tak ada).
    if (typeof autoGrowTeksBalasan === 'function') {
        try { autoGrowTeksBalasan(); } catch (e) { /* opsional */ }
    }

    textarea.focus();
    if (typeof showToast === 'function') {
        showToast('Draft saran M.A.R.A dimasukkan ke kotak balasan. Periksa dulu sebelum kirim.', 'info');
    }
}
