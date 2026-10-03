// Template Balasan Cepat (TODO-R1) -- composer Inbox, modal pemilihan
// template.
//
// Memilih template HANYA mengisi composer (gambar lewat
// antrianMediaBalasan yang sudah ada, teks lewat teksBalasan) -- BELUM
// terkirim. Kirim tetap lewat kirimBalasan() yang sudah ada di
// app/Views/inbox/index.php, tidak ada jalur kirim baru (AC-9, AC-10,
// AC-11).
//
// Dependensi global dari index.php (sama-sama <script> biasa di
// halaman yang sama, bukan module): conversationAktif, tambahMediaBalasan,
// buangOperationIdBalasan, sembunyikanStatusKirimBalasan, showToast.
// Dependensi dari inbox-thread.js: escapeHtmlInbox.
// window.INBOX_TEMPLATE_CONFIG.apiUrl diisi oleh index.php.
//
// Run test: node tests/js/inbox-template.test.js (no framework, no deps)

var daftarTemplateBalasanCache = [];

function bukaModalTemplate() {
    if (!conversationAktif) return;
    muatDaftarTemplateBalasan();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTemplateBalasan')).show();
}

function muatDaftarTemplateBalasan() {
    const container = document.getElementById('daftarTemplateBalasan');
    if (!container) return;

    container.innerHTML = '<div class="text-muted small p-2">Memuat template...</div>';

    return fetch(window.INBOX_TEMPLATE_CONFIG.apiUrl)
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status !== 'success') throw new Error(json.message || 'Gagal memuat template.');

            daftarTemplateBalasanCache = json.templates;

            if (!json.templates.length) {
                container.innerHTML = '<div class="text-muted small p-2">Belum ada template balasan.</div>';
                return;
            }

            container.innerHTML = json.templates.map(function(t) {
                return '<button type="button" class="teruskan-tujuan-item" onclick="pilihTemplate(' + t.id + ')">' +
                    '<span class="teruskan-tujuan-nama">' + escapeHtmlInbox(t.nama) + '</span>' +
                    '</button>';
            }).join('');
        })
        .catch(function(err) {
            container.innerHTML = '<div class="text-danger small p-2">' + escapeHtmlInbox(err.message) + '</div>';
        });
}

// Ekstensi per MIME gambar template -- harus selaras dengan MIME yang
// divalidasi/diterima BalasanTemplateImageService (jpg/png/webp).
const EKSTENSI_PER_MIME_TEMPLATE = {
    'image/jpeg': 'jpg',
    'image/png': 'png',
    'image/webp': 'webp'
};

function pilihTemplate(id) {
    // Bandingkan sebagai string: endpoint /inbox/api/balasan-template
    // mengembalikan id sebagai STRING (mis. "1", perilaku driver MySQL/
    // JSON), sementara onclick yang dirender menulis `pilihTemplate(1)`
    // (angka). Tanpa konversi, `t.id === id` selalu false ("1" === 1) dan
    // klik template diam-diam tidak melakukan apa-apa. Pola String(x) ===
    // String(y) sama seperti perbandingan id percakapan lain di index.php.
    const template = daftarTemplateBalasanCache.find(function(t) {
        return String(t.id) === String(id);
    });
    if (!template) return;

    if (template.teks) {
        const textarea = document.getElementById('teksBalasan');
        textarea.value = template.teks;
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTemplateBalasan')).hide();

    if (template.gambar_url) {
        // Gambar template bukan File dari input lokal -- ambil isinya
        // lalu bungkus jadi File agar bisa masuk antrianMediaBalasan dan
        // dikirim lewat jalur /inbox/kirim-media yang sudah ada, tanpa
        // menduplikasi logic antrian (AC-10, AC-11). Modal SUDAH ditutup
        // di atas -- gambar dimuat di latar belakang, kasir tidak perlu
        // menunggu sebelum lanjut mengetik.
        return fetch(template.gambar_url)
            .then(function(res) {
                return res.blob();
            })
            .then(function(blob) {
                const ext = EKSTENSI_PER_MIME_TEMPLATE[blob.type] || 'jpg';
                const file = new File([blob], template.nama + '.' + ext, { type: blob.type });
                tambahMediaBalasan([file]);
            })
            .catch(function(err) {
                showToast('Gagal memuat gambar template: ' + err.message, 'danger');
            });
    }

    // Teks-saja: isi composer berubah -> operasi baru (M1 Wave 2, TASK-019),
    // sama seperti tambahMediaBalasan() untuk lampiran.
    buangOperationIdBalasan();
    sembunyikanStatusKirimBalasan();
}
