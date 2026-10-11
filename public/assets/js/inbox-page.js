/*
 * Inbox page controller script (moved verbatim from app/Views/inbox/index.php,
 * TODO-Q5c). Classic script, not a module: its top-level functions and
 * variables stay global because the view's inline onclick handlers and
 * inbox-thread.js / inbox-template.js call them by name.
 *
 * Server data and URLs come from window.INBOX_PAGE_CONFIG, rendered by the
 * view right before this file is loaded.
 */
const INBOX_PAGE_CONFIG = window.INBOX_PAGE_CONFIG;
const INBOX_URL = INBOX_PAGE_CONFIG.url;

// ================================================================
// STATE
// ================================================================
let conversationAktif = null;
let conversationUntukHapus = null; // target hapus dari row daftar kiri, terpisah dari conversationAktif
let daftarConversation = INBOX_PAGE_CONFIG.conversations;
// Queue View: tab aktif hanya memilih hasil dari queue_status yang
// sudah dihitung backend oleh ConversationModel::withComputedStatus().
// Tidak ada perhitungan status di client-side.
let filterAktif = 'belum_diambil';
const currentUserId = INBOX_PAGE_CONFIG.currentUserId;
const currentUserRole = INBOX_PAGE_CONFIG.currentUserRole;

// Nudge 1 (Fase 5). Timer disimpan global dan SELALU di-clear sebelum
// di-set ulang supaya tidak menumpuk saat kasir cepat pindah thread.
// `nudgeAmbilTampilUntukId` menyimpan id percakapan (String) yang banner-nya
// SEDANG tampil; di-reset saat pindah conversation sehingga banner boleh
// muncul lagi saat kasir kembali -- asalkan masih belum_diambil.
let nudgeAmbilTimer = null;
let nudgeAmbilTampilUntukId = null;

// ================================================================
// UTIL
// ================================================================
// Cari conversation berdasarkan id. Perbandingan via String() SENGAJA
// -- kolom `id` dari CodeIgniter/MySQLi kadang berupa string ("45"),
// sedangkan id di JS (dari onclick, parameter fetch, dst) berupa
// number -- "45" === 45 selalu false, bikin conv "tidak ketemu" tanpa
// error apa pun (root cause pernah bikin modal edit/header/highlight
// aktif semuanya diam-diam kosong). Satu fungsi ini dipakai di semua
// pemanggil supaya perbaikannya tidak perlu diulang di tiap tempat.
function cariConversation(id) {
    return daftarConversation.find(function(c) {
        return String(c.id) === String(id);
    });
}

// Data of the open conversation. A search can leave it out of
// daftarConversation (TASK-017 rule 7); the header and dialogs then
// keep using the last copy seen, so Conversation Detail still works.
let salinanConversationAktif = null;

function conversationAktifSaatIni() {
    const conv = cariConversation(conversationAktif);
    if (conv) {
        salinanConversationAktif = conv;
        return conv;
    }
    if (salinanConversationAktif && String(salinanConversationAktif.id) === String(conversationAktif)) {
        return salinanConversationAktif;
    }
    return undefined;
}

// ================================================================
// DAFTAR CONVERSATION
// ================================================================
// Tombol filter Queue View -- state visual saja, tidak mengubah
// conversationAktif/daftarConversation itu sendiri.
const QUEUE_STATUS_LABEL = {
    belum_diambil: 'Belum Diambil',
    open: 'Open',
    menunggu: 'Menunggu',
    ditunda: 'Ditunda',
    selesai: 'Selesai',
    // Grup Tahap 1 (REQ-009) -- SENGAJA paling akhir. Ini
    // satu-satunya sumber kebenaran untuk highlight tombol aktif
    // (renderFilterButtons()) dan hitung badge angka tab
    // (renderTabCounts()); tanpa entri ini, tombol tab Grup tidak
    // pernah menyala aktif dan badge angkanya tidak pernah ter-update
    // walau filter data tetap jalan normal.
    grup: 'Grup',
};

function renderFilterButtons() {
    Object.keys(QUEUE_STATUS_LABEL).forEach(function(f) {
        const idSuffix = f.charAt(0).toUpperCase() + f.slice(1);
        const btn = document.getElementById('btnFilter' + idSuffix);
        if (btn) btn.classList.toggle('active', filterAktif === f);
    });
}

function renderTabCounts() {
    const counts = {};
    Object.keys(QUEUE_STATUS_LABEL).forEach(function(status) {
        counts[status] = daftarConversation.filter(function(c) {
            return c.queue_status === status;
        }).length;
    });

    document.querySelectorAll('.tab-count').forEach(function(el) {
        const status = el.getAttribute('data-count-for');
        el.textContent = counts[status] ?? 0;
    });
}

// Clears the right panel (thread header + message history + reply form)
// and drops the selected conversation. Used when the active conversation
// is no longer valid for the current view (tab switch or deletion) so the
// previous thread does not stay visible ("sticky") on the right side.
function kosongkanThreadPanel() {
    conversationAktif = null;
    salinanConversationAktif = null;
    document.getElementById('threadHeader').innerHTML = '<span class="text-muted">Pilih percakapan di sebelah kiri untuk mulai.</span>';
    resetThread('<div class="inbox-thread-empty"><i class="fas fa-comments fa-2x me-2"></i> Belum ada percakapan dipilih.</div>');
    document.getElementById('teksBalasan').disabled = true;
    document.getElementById('teksBalasan').placeholder = 'Pilih percakapan dulu...';
    document.getElementById('btnKirimBalasan').disabled = true;
    document.getElementById('btnLampirkanMedia').disabled = true;
    document.getElementById('btnTemplateBalasan').disabled = true;
    batalkanKutipan();
    batalkanMediaBalasan();

    // Nudge 1: panel dikosongkan -> batalkan timer & sembunyikan banner
    // supaya tidak ada banner nyangkut atau timer yang masih berjalan.
    if (nudgeAmbilTimer !== null) {
        clearTimeout(nudgeAmbilTimer);
        nudgeAmbilTimer = null;
    }
    tutupNudgeAmbil();
    nudgeAmbilTampilUntukId = null;
}

function setFilterConversation(filter) {
    // Re-clicking the active tab is a no-op, so it never wipes the
    // thread panel the cashier is currently reading.
    if (filter === filterAktif) return;
    filterAktif = filter;
    // Switching tabs restarts from nothing: empty the right panel
    // before re-rendering the list so the old thread cannot linger.
    kosongkanThreadPanel();
    renderFilterButtons();
    renderDaftarConversation();
}

function renderBadgePerluDibalas() {
    const badge = document.getElementById('perluDibalasBadge');
    const jumlah = daftarConversation.filter(function(c) {
        return c.queue_status === 'belum_diambil' || c.queue_status === 'open';
    }).length;
    if (jumlah > 0) {
        badge.textContent = jumlah + ' Perlu Dibalas';
        badge.style.display = '';
    } else {
        badge.style.display = 'none';
    }
}

const RESPONSE_STATE_LABEL = {
    perlu_dibalas: {
        text: 'Perlu Dibalas',
        kelas: 'bg-danger'
    },
    menunggu_customer: {
        text: 'Menunggu Customer',
        kelas: 'bg-info text-dark'
    },
    follow_up: {
        text: 'Follow-up',
        kelas: 'bg-warning text-dark'
    },
    selesai: {
        text: 'Selesai',
        kelas: 'bg-secondary'
    },
};

// SLA Timer (TASK-016): the color is computed ONLY on the server
// (InboxSlaService, REQ-010). null, missing or unknown values render
// nothing -- covers ditunda/selesai, a null last_message_at, and the
// first paint from index(), which sends no sla_color.
const SLA_WARNA = {
    hijau: {
        kelas: 'bg-success',
        arti: '< 15 menit'
    },
    kuning: {
        kelas: 'bg-warning',
        arti: '15–60 menit'
    },
    merah: {
        kelas: 'bg-danger',
        arti: '> 60 menit'
    },
};

function renderTitikSla(slaColor) {
    if (!Object.prototype.hasOwnProperty.call(SLA_WARNA, slaColor)) return '';

    const info = SLA_WARNA[slaColor];
    return '<span class="inbox-sla-dot ' + info.kelas + '" title="' +
        escapeHtmlInbox('SLA: ' + info.arti + ' sejak pesan terakhir') + '"></span>';
}

// Fase 1e (Spec 4.4, REQ-015, AC-015a-c): baris Match Snippet di bawah
// baris nomor/preview. Server cuma mengirim `match_snippet` kalau
// conversation cocok lewat ISI PESAN (CL-018), jadi daftar Fase 1d
// tampil persis seperti sebelumnya saat snippet null (AC-015b).
function renderSnippetCocok(snippet) {
    if (!snippet || !snippet.text) return '';

    // Sama seperti m.is_internal di renderPesan(): nilai bisa datang
    // sebagai true/1/"1" lewat json_encode.
    const internal = snippet.is_internal === true || snippet.is_internal === 1 || snippet.is_internal === '1';
    const labelInternal = internal ?
        '<span class="inbox-internal-label"><i class="fas fa-sticky-note"></i> Internal</span> ' :
        '';

    // Teksnya pesan pelanggan/staff -- SELALU lewat escapeHtmlInbox(),
    // tidak pernah sebagai HTML mentah (AC-015c).
    return '<div class="list-snippet">' + labelInternal + escapeHtmlInbox(snippet.text) + '</div>';
}

const AVATAR_WARNA = ['#0d6efd', '#1f3b5c', '#8b5cf6', '#d63384', '#fd7e14', '#dc3545', '#198754', '#6f42c1'];

// Decorative only: initials from the display name, or an icon for
// groups and number-only names (a phone number has no initials).
function renderAvatarConversation(nama, isGrup) {
    const teks = String(nama || '').trim();
    let hash = 0;
    for (let i = 0; i < teks.length; i++) hash = (hash * 31 + teks.charCodeAt(i)) >>> 0;
    const warna = AVATAR_WARNA[hash % AVATAR_WARNA.length];

    const kata = teks.split(/\s+/).filter(function(k) {
        return /^\p{L}/u.test(k);
    });
    let isi;
    if (isGrup) {
        isi = '<i class="fas fa-users"></i>';
    } else if (kata.length) {
        isi = escapeHtmlInbox((Array.from(kata[0])[0] + (kata.length > 1 ? Array.from(kata[kata.length - 1])[0] : '')).toUpperCase());
    } else {
        isi = '<i class="fas fa-user"></i>';
    }

    return '<span class="list-avatar" style="background:' + warna + ';" aria-hidden="true">' + isi + '</span>';
}

function renderDaftarConversation() {
    renderFilterButtons();
    renderTabCounts();
    renderBadgePerluDibalas();

    const panel = document.getElementById('inboxListPanel');
    const daftarTampil = daftarConversation.filter(function(c) {
        return c.queue_status === filterAktif;
    });

    if (!daftarTampil.length) {
        let pesanKosong = 'Belum ada percakapan masuk.';
        if (daftarConversation.length) {
            pesanKosong = 'Tidak ada percakapan ' + (QUEUE_STATUS_LABEL[filterAktif] || filterAktif) + '.';
        } else if (kataKunciAktif) {
            // CL-005: a search with no result, not an empty inbox.
            pesanKosong = 'Tidak ditemukan percakapan untuk "' + kataKunciAktif + '".';
        }
        panel.innerHTML = '<div class="p-3 text-muted small text-center">' + escapeHtmlInbox(pesanKosong) + '</div>';
        return;
    }

    panel.innerHTML = daftarTampil.map(function(c) {
        const activeClass = (String(c.id) === String(conversationAktif)) ? ' active' : '';
        // Grup Tahap 2 (REQ-007): judul grup = group_name (subject asli)
        // atau "Grup" -- TIDAK PERNAH nama pengirim terakhir (whatsapp_name).
        const nama = (c.jid_type === 'group') ? (c.group_name || 'Grup') : (c.contact_name || c.whatsapp_name || c.phone || c.chat_id);
        const waktu = c.last_message_at ? formatWaktuInbox(c.last_message_at) : '';
        const panah = c.last_message_direction === 'outgoing' ? '<i class="fas fa-reply fa-xs"></i> ' : '';
        // Grup Tahap 1 (REQ-005, CON-005, CON-006) -- grup tidak
        // menyentuh dimensi kepemilikan/lifecycle, jadi badge closed,
        // response_state, titik SLA, dan badge kepemilikan TIDAK
        // dirender sama sekali (bukan disabled). Penanda "Grup"
        // tampil menggantikan posisi informasi tersebut.
        const isGrup = c.jid_type === 'group';
        const grupBadge = isGrup ? '<span class="badge bg-dark" style="font-size:0.6rem;">Grup</span>' : '';
        const closedBadge = (!isGrup && c.status === 'closed') ? '<span class="badge bg-secondary" style="font-size:0.6rem;">closed</span>' : '';
        const rsInfo = RESPONSE_STATE_LABEL[c.response_state];
        const responseStateBadge = (!isGrup && rsInfo) ? '<span class="badge ' + rsInfo.kelas + '" style="font-size:0.6rem;">' + rsInfo.text + '</span>' : '';
        // String() SENGAJA -- assigned_to dari MySQLi/JSON kadang
        // string ("3"), currentUserId number -- lihat catatan
        // cariConversation() di atas untuk root cause bug yang sama.
        const assignBadge = isGrup ? '' : (c.assigned_to ?
            '<span class="badge ' + (String(c.assigned_to) === String(currentUserId) ? 'bg-info' : 'bg-light text-dark border') + '" style="font-size:0.6rem;">' +
            '<i class="fas fa-user"></i> Dipegang: ' + escapeHtmlInbox(c.assigned_to_name || ('User #' + c.assigned_to)) + '</span>' :
            '<span class="badge bg-light text-muted border" style="font-size:0.6rem;">Belum diambil</span>');

        const nomorAtauLid = c.manual_phone || c.phone || (c.jid_type === 'lid' ? 'LID' : c.jid_type);
        const barisSnippet = renderSnippetCocok(c.match_snippet);

        // CON-002 -- Edit Profil disabled (bukan hilang) untuk grup;
        // Hapus percakapan tetap berfungsi penuh (REQ-007).
        const tombolEditProfil = isGrup ?
            '<button type="button" class="btn btn-sm btn-link p-0 text-muted" style="font-size:0.75rem;" title="Edit profil tidak berlaku untuk grup" disabled><i class="fas fa-pen"></i></button>' :
            '<button type="button" class="btn btn-sm btn-link p-0 text-muted" style="font-size:0.75rem;" title="Edit profil pelanggan" onclick="event.stopPropagation(); editPercakapanDariList(' + c.id + ')"><i class="fas fa-pen"></i></button>';

        return '<a href="#" class="inbox-list-item' + activeClass + '" onclick="return pilihConversation(' + c.id + ')">' +
            renderAvatarConversation(nama, isGrup) +
            '<div class="list-body">' +
            '<div class="d-flex justify-content-between align-items-start gap-1">' +
            '<span class="list-name">' + renderTitikSla(isGrup ? null : c.sla_color) + escapeHtmlInbox(nama) + '</span>' +
            '<span class="d-flex align-items-center gap-1 flex-shrink-0">' +
            '<span class="list-time">' + escapeHtmlInbox(waktu) + '</span>' +
            '<span class="list-aksi">' +
            tombolEditProfil +
            '<button type="button" class="btn btn-sm btn-link p-0 text-danger" style="font-size:0.75rem;" title="Hapus percakapan" onclick="event.stopPropagation(); hapusPercakapanDariList(' + c.id + ')"><i class="fas fa-trash-alt"></i></button>' +
            '</span>' +
            '</span>' +
            '</div>' +
            '<div class="list-preview">' + panah + escapeHtmlInbox(nomorAtauLid) + ' ' + grupBadge + ' ' + closedBadge + ' ' + responseStateBadge + ' ' + assignBadge + '</div>' +
            barisSnippet +
            '</div>' +
            '</a>';
    }).join('');
}

// API mengirim 50 conversation per halaman (spec M3 4.4 CL-010),
// sedangkan tab/angka/badge dihitung dari daftar LENGKAP -- jadi
// ambil page 1, 2, ... sampai halaman berisi < 50, lalu gabungkan.
// Satu halaman gagal = seluruh putaran ditolak (daftar lama dipakai).
// Dedupe per id: conversation bisa bergeser halaman kalau ada pesan
// masuk di tengah putaran.
const CONVERSATIONS_PER_PAGE = 50;

// Active search keyword (TASK-017), already trimmed; '' = no search.
// Every list load, including the 6-second polling, sends it as `q`,
// so the list does not jump back to all conversations.
let kataKunciAktif = '';

// REQ-017b (Fase 1e): satu putaran pemuatan daftar pada satu waktu.
// Ditandai saat putaran mulai dan dilepas di `.finally()` -- jadi
// selalu bersih setelah putaran selesai, berhasil maupun gagal. Yang
// dilewati cuma tick polling 6 detik dan pencarian dengan kata kunci
// yang SAMA; pencarian kata kunci baru tetap dikirim (REQ-017c).
// Pengaman ini per layar, bukan per server -- dua tab tidak
// terlindungi (RISK-009, diterima).
let putaranDaftarBerjalan = false;

// Nomor putaran terakhir yang dimulai. Pengaman di atas hanya boleh
// dilepas oleh putaran yang nomornya masih ini; putaran yang sudah
// dikalahkan kata kunci baru justru harus membiarkannya (CORR-01).
// Tanpa ini pengaman jadi milik bersama, dan sebuah putaran lama yang
// selesai lebih dulu bisa melepasnya sementara putaran baru masih
// berjalan -- tick 6 detik berikutnya lalu memulai putaran ketiga.
let putaranDaftarTerakhir = 0;

function ambilSemuaConversation(kataKunci) {
    const hasil = [];
    const sudahAda = new Set();
    const paramQ = kataKunci ? '&q=' + encodeURIComponent(kataKunci) : '';

    function ambilHalaman(page) {
        return fetch(INBOX_URL.conversations + '?page=' + page + paramQ)
            .then(function(res) {
                return res.json();
            })
            .then(function(json) {
                if (json.status !== 'success') throw new Error(json.message || 'Gagal memuat conversation.');
                json.conversations.forEach(function(c) {
                    if (sudahAda.has(String(c.id))) return;
                    sudahAda.add(String(c.id));
                    hasil.push(c);
                });
                return json.conversations.length < CONVERSATIONS_PER_PAGE ? hasil : ambilHalaman(page + 1);
            });
    }

    return ambilHalaman(1);
}

// saatGagal (optional) is only passed by a search; normal polling
// errors stay silent.
function muatUlangDaftarConversation(saatGagal) {
    const kataKunci = kataKunciAktif;

    // Nomor diambil SEBELUM putaran mulai (RISK-003). Kalau diambil
    // sesudah promise-nya dipasang, putaran yang sudah kalah bisa
    // keburu memakai nomor yang sama dengan putaran terbaru dan
    // perbandingan di `.finally()` jadi tidak berguna.
    const giliran = ++putaranDaftarTerakhir;
    putaranDaftarBerjalan = true;

    // `ambilSemuaConversation()` bisa melempar SINKRON lewat
    // `encodeURIComponent()` -- URIError pada lone surrogate. Kalau itu
    // terjadi, promise-nya belum ada sehingga `.finally()` tidak pernah
    // terpasang dan pengaman tertinggal `true` selamanya; daftar
    // berhenti menyegarkan sampai halaman dimuat ulang (CORR-03).
    let janji;
    try {
        janji = ambilSemuaConversation(kataKunci);
    } catch (err) {
        // Tidak ada `await` antara klaim pengaman dan titik ini, jadi
        // putaran ini pasti masih pemiliknya -- aman dilepas langsung.
        putaranDaftarBerjalan = false;
        if (typeof saatGagal === 'function') {
            saatGagal(err);
        }
        return;
    }

    janji
        .then(function(semua) {
            // The keyword changed while the pages were loading: this
            // result belongs to the old keyword, drop it.
            if (kataKunci !== kataKunciAktif) return;

            daftarConversation = semua;
            renderDaftarConversation();
            renderThreadHeader();
        })
        .catch(function(err) {
            if (kataKunci !== kataKunciAktif) return;
            if (typeof saatGagal === 'function') {
                saatGagal(err);
                return;
            }
            // Diamkan -- polling berikutnya akan coba lagi. Tidak
            // perlu toast tiap gagal 1 siklus, cukup mengganggu.
        })
        .finally(function() {
            // REQ-017b: dilepas di sini supaya putaran berikutnya
            // boleh mulai lagi, termasuk setelah putaran yang gagal --
            // TAPI hanya oleh putaran yang masih terbaru. Putaran yang
            // sudah dikalahkan kata kunci baru membiarkan pengaman
            // tetap terpasang, supaya tick 6 detik tidak memulai
            // putaran ketiga selagi putaran terbaru masih berjalan
            // (CORR-01).
            if (giliran === putaranDaftarTerakhir) putaranDaftarBerjalan = false;
        });
}

// Whitespace-only counts as empty, so no `q` is sent (CL-007). On a
// failed search (e.g. 400) the previous keyword and list stay, and
// the error is shown once (AC-012g).
function jalankanPencarianConversation(kataKunciBaru) {
    const kataKunciSebelumnya = kataKunciAktif;
    const baru = kataKunciBaru.trim();

    // REQ-017b/AC-015d: mengulang kata kunci yang sedang aktif saat
    // satu putaran masih berjalan tidak memulai putaran kedua (tidak
    // ada penumpukan request). Kata kunci BARU tetap dikirim, dan
    // hasil kata kunci lama tetap dibuang oleh pemeriksa di
    // muatUlangDaftarConversation (REQ-017c).
    if (putaranDaftarBerjalan && baru === kataKunciSebelumnya) return;

    kataKunciAktif = baru;

    muatUlangDaftarConversation(function(err) {
        kataKunciAktif = kataKunciSebelumnya;
        showToast('Pencarian gagal: ' + err.message, 'danger');
    });
}

function cariConversationDariInput(e) {
    e.preventDefault();
    jalankanPencarianConversation(document.getElementById('inputCariConversation').value);
    return false;
}

function hapusPencarianConversation() {
    document.getElementById('inputCariConversation').value = '';
    jalankanPencarianConversation('');
}

// ================================================================
// IDENTITAS CUSTOMER: "Nama (No. Telepon)" / "Nama (LID)" -- TIDAK
// PERNAH menampilkan conversation_id ("#45") sebagai identitas
// utama, dan TIDAK PERNAH memperlakukan digit @lid sebagai nomor
// telepon (lihat business rule reconciliation LID<->PN).
// ================================================================
function formatIdentitasCustomer(conv) {
    if (!conv) return '';

    // Grup Tahap 2 (REQ-007): judul grup = nama grup asli (group_name)
    // yang stabil, atau teks generik "Grup" bila belum diketahui.
    // TIDAK PERNAH memakai whatsapp_name/identitas pengirim terakhir, dan
    // TIDAK ada suffix nomor/LID untuk grup. Perilaku pribadi tidak berubah.
    if (conv.jid_type === 'group') return conv.group_name || 'Grup';

    const nama = conv.contact_name || conv.whatsapp_name || null;
    const nomor = conv.manual_phone || conv.phone || null;

    if (nama && nomor) return nama + ' (' + nomor + ')';
    if (nomor) return nomor;
    if (nama) return nama + (conv.jid_type === 'lid' ? ' (LID)' : '');
    if (conv.jid_type === 'lid') return 'LID';
    return conv.chat_id;
}

// ================================================================
// HEADER THREAD (identitas customer + badge/tombol assignment)
// ================================================================
function renderThreadHeader() {
    if (!conversationAktif) return;

    // Jangan render ulang selagi dropdown Follow-up terbuka -- polling
    // 6 detik (muatUlangDaftarConversation) mengganti innerHTML header
    // dan menutup paksa dropdown sebelum sempat diklik.
    if (document.querySelector('#threadHeader .dropdown-menu.show')) return;

    const conv = conversationAktifSaatIni();
    const identitas = formatIdentitasCustomer(conv);
    // Grup Tahap 1 (REQ-006, CON-001, CON-002, CON-005, CON-006) --
    // grup tidak menyentuh dimensi kepemilikan/status. Penanda ini
    // menggantikan seluruh badge kepemilikan/lifecycle yang
    // disembunyikan di bawah, terpisah dari judul percakapan.
    const isGrup = conv && conv.jid_type === 'group';
    const penandaGrup = isGrup ? ' <span class="badge bg-dark">Grup</span>' : '';

    let infoAssign = '';
    let tombolAssign = '';

    // CON-001 -- Ambil/Lepas TIDAK dirender sama sekali untuk grup.
    // CON-006 -- badge kepemilikan juga tidak dirender untuk grup.
    // Lifecycle: aksi kepemilikan (Ambil/Lepas) hanya relevan untuk
    // percakapan AKTIF (status='open') -- setelah Tutup, ownership
    // sudah dilepas server, jadi tidak ada yang bisa dilepas lagi.
    if (!isGrup) {
        if (conv && conv.assigned_to) {
            // String() SENGAJA -- lihat catatan cariConversation() di atas
            // (root cause bug: assigned_to string dari server vs
            // currentUserId number, "3" === 3 selalu false).
            const punyaSaya = String(conv.assigned_to) === String(currentUserId);
            infoAssign = ' <span class="badge ' + (punyaSaya ? 'bg-info' : 'bg-light text-dark border') + '">' +
                '<i class="fas fa-user"></i> Dipegang: ' + escapeHtmlInbox(conv.assigned_to_name || ('User #' + conv.assigned_to)) + '</span>';

            if (punyaSaya || currentUserRole === 'admin') {
                tombolAssign = '<button type="button" class="btn btn-sm btn-outline-secondary me-1" title="Lepas percakapan" onclick="lepasPercakapan()">' +
                    '<i class="fas fa-user-slash"></i> Lepas</button>';
            } else if (conv.bisa_diambil) {
                tombolAssign = '<button type="button" class="btn btn-sm btn-outline-primary me-1" title="Ambil alih percakapan (owner off-shift atau Shift Leader override)" onclick="ambilPercakapan()">' +
                    '<i class="fas fa-user-check"></i> Ambil Alih</button>';
            }
        } else {
            infoAssign = ' <span class="badge bg-light text-muted border">Belum diambil</span>';
            if (conv && conv.bisa_diambil) {
                tombolAssign = '<button type="button" class="btn btn-sm btn-outline-primary me-1" title="Ambil percakapan" onclick="ambilPercakapan()">' +
                    '<i class="fas fa-user-plus"></i> Ambil</button>';
            }
        }
    }

    // Revisi LID-FIRST -> PN-LATER: tombol konfirmasi nomor manual
    // HANYA relevan kalau conversation ini @lid DAN belum punya
    // `phone` ter-verifikasi (kalau sudah ada, reconciliation
    // otomatis/sebelumnya sudah menanganinya). CON-002 -- untuk
    // grup, tombol ini tampil DISABLED (bukan hilang).
    const tombolKonfirmasiNomor = isGrup ?
        ' <button type="button" class="btn btn-sm btn-link p-0 text-muted" style="font-size:0.75rem;" title="Konfirmasi nomor tidak berlaku untuk grup" disabled>' +
        '<i class="fas fa-shield-alt"></i> Konfirmasi Nomor</button>' :
        (conv && conv.jid_type === 'lid' && !conv.phone) ?
        ' <button type="button" class="btn btn-sm btn-link p-0 text-warning" style="font-size:0.75rem;" title="Konfirmasi nomor WhatsApp customer ini" onclick="bukaModalKonfirmasiNomor()">' +
        '<i class="fas fa-shield-alt"></i> Konfirmasi Nomor</button>' :
        '';

    // Tahap 1 lifecycle status (Section 12): tombol "Tutup" memakai
    // flag `bisa_ditutup` dari server (satu sumber kebenaran dengan
    // guard tutupPercakapan()) -- bukan cek status lokal di JS. Tidak
    // ada tombol "Open" manual (reopen cuma lewat pesan masuk baru,
    // lihat InboxGatewayApi::messages()). CON-006 -- badge lifecycle
    // OPEN/CLOSED tidak dirender untuk grup. CON-001 -- tombol Tutup
    // tidak dirender sama sekali untuk grup.
    const badgeStatus = (conv && !isGrup) ?
        ' <span class="badge ' + (conv.status === 'closed' ? 'bg-secondary' : 'bg-success') + '">' + conv.status.toUpperCase() + '</span>' :
        '';
    const tombolTutup = (conv && conv.bisa_ditutup) ?
        '<button type="button" class="btn btn-sm btn-outline-danger me-1" title="Tutup percakapan" onclick="tutupPercakapan()">' +
        '<i class="fas fa-times-circle"></i> Tutup</button>' :
        '';

    // Response state (Langkah 9): "Tandai Dibaca" (perlu_dibalas ->
    // menunggu_customer) dan "Follow-up" (snooze sementara) -- keduanya
    // dipanggil lewat endpoint Langkah 5 & 6. CON-005 -- tombol
    // Tandai Dibaca tidak dirender sama sekali untuk grup.
    const tombolTandaiDibaca = (!isGrup && conv && conv.response_state === 'perlu_dibalas') ?
        '<button type="button" class="btn btn-sm btn-outline-success me-1" title="Tandai sudah dibaca" onclick="tandaiDibacaAktif()">' +
        '<i class="fas fa-check"></i> Tandai Dibaca</button>' :
        '';
    // M3 Fase 2a (TB-01/TASK-004): tombol Handoff hanya untuk
    // percakapan eligible + inisiator yang diizinkan server
    // (assignee saat ini, atau kasir aktif pada belum_diambil) --
    // Q1/P-05 dicerminkan di UI supaya tidak menawarkan aksi 403.
    // TASK-202 (CR-03 = A, LOCKED): cermin gerbang server yang sudah
    // dipersempit -- tanpa pemilik hanya boleh dari tab `belum_diambil`
    // (queue_status), bukan sekadar assigned_to kosong. CON-005 --
    // tombol Handoff tidak dirender sama sekali untuk grup (grup
    // tidak menyentuh dimensi kepemilikan).
    const dapatHandoff = !isGrup && conv && conv.queue_status !== 'selesai' && (
        (conv.assigned_to && String(conv.assigned_to) === String(currentUserId)) ||
        (!conv.assigned_to && conv.queue_status === 'belum_diambil' && currentUserRole === 'kasir')
    );
    const tombolHandoff = dapatHandoff ?
        '<button type="button" class="btn btn-sm btn-outline-primary me-1" title="Serahkan percakapan ke kasir lain" onclick="bukaModalHandoff()">' +
        '<i class="fas fa-share-square"></i> Handoff</button>' :
        '';

    // CON-001 -- Follow-up (pemicu Snooze) TIDAK dirender sama sekali
    // untuk grup.
    const tombolFollowUp = isGrup ? '' :
        '<div class="btn-group me-1">' +
        '<button type="button" class="btn btn-sm btn-outline-warning dropdown-toggle" data-bs-toggle="dropdown" title="Follow-up nanti">' +
        '<i class="fas fa-clock"></i> Follow-up</button>' +
        '<ul class="dropdown-menu dropdown-menu-end">' +
        '<li><a class="dropdown-item" href="#" onclick="return bukaModalSnooze(60, \'1 jam\')">1 jam</a></li>' +
        '<li><a class="dropdown-item" href="#" onclick="return bukaModalSnooze(180, \'3 jam\')">3 jam</a></li>' +
        '<li><a class="dropdown-item" href="#" onclick="return bukaModalSnooze(' + menitSampaiBesokPagi() + ', \'sampai besok pagi\')">Besok pagi</a></li>' +
        (conv && conv.snoozed_until ? '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="#" onclick="return snoozePercakapanAktif(0)">Batal</a></li>' : '') +
        '</ul></div>';

    // TASK-015: always shown, on every status and for every staff --
    // unlike Balas/Follow-up it is not gated on ownership (SEC-001).
    // CON-003 -- Internal Note tetap berfungsi tanpa perubahan untuk grup.
    const tombolCatatanInternal =
        '<button type="button" class="btn btn-sm btn-outline-secondary me-1" title="Tulis catatan internal (tidak terkirim ke pelanggan)" onclick="bukaModalCatatanInternal()">' +
        '<i class="fas fa-sticky-note"></i> Catatan Internal</button>';

    // Edit & Hapus TIDAK lagi tampil di header -- dipindah ke masing-
    // masing row percakapan di daftar kiri (lihat editPercakapanDariList()/
    // hapusPercakapanDariList()).
    document.getElementById('threadHeader').innerHTML =
        '<span><strong>' + escapeHtmlInbox(identitas) + '</strong>' +
        penandaGrup +
        badgeStatus +
        tombolKonfirmasiNomor +
        infoAssign +
        '</span>' +
        '<span>' + tombolCatatanInternal + tombolTandaiDibaca + tombolHandoff + tombolFollowUp + tombolTutup + tombolAssign + '</span>';
}

// Menit dari sekarang sampai jam 08:00 hari berikutnya -- dipakai
// opsi "Besok pagi" di dropdown Follow-up.
function menitSampaiBesokPagi() {
    const now = new Date();
    const besokPagi = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 8, 0, 0);
    return Math.round((besokPagi.getTime() - now.getTime()) / 60000);
}

function tandaiDibacaAktif() {
    if (!conversationAktif) return;

    fetch(INBOX_URL.percakapan + conversationAktif + '/tandai-dibaca', {
            method: 'POST'
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                muatUlangDaftarConversation();
                panggilWhatsappDibaca(conversationAktif);
            } else {
                showToast(json.message || 'Gagal menandai dibaca.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        });
}

/* Kirim permintaan WA read receipt (blue tick) ke server -- Arah 1.
   BEDA dari tombol "Tandai Dibaca" (state internal): ini murni meminta
   Evolution menandai pesan masuk pelanggan sebagai dibaca. Fire-and-forget:
   kegagalan WA read TIDAK boleh mengganggu buka percakapan. Dipanggil saat
   kasir membuka percakapan dan saat menekan "Tandai Dibaca" -- bukan tiap polling. */
function panggilWhatsappDibaca(id) {
    if (!id) return;

    fetch(INBOX_URL.percakapan + id + '/whatsapp-dibaca', {
            method: 'POST'
        })
        .catch(function() { /* fail-soft: blue tick best-effort */ });
}

// Same limit as the Internal Note endpoint (CL-006). Measured in UTF-8
// bytes because catatanInternal() checks strlen(), not characters.
const SNOOZE_ALASAN_MAKS_BYTE = 4096;
let menitSnoozeDipilih = 0;

function bukaModalSnooze(menit, labelDurasi) {
    if (!conversationAktif) return false;

    menitSnoozeDipilih = menit;
    document.getElementById('snoozeLabelDurasi').textContent = labelDurasi;
    document.getElementById('snoozeAlasan').value = '';
    document.getElementById('btnKirimSnooze').disabled = false;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSnooze')).show();
    return false;
}

function kirimSnoozeDariModal(e) {
    e.preventDefault();

    const alasan = document.getElementById('snoozeAlasan').value.trim();
    // Reject BEFORE snoozing: an over-limit reason must not leave a
    // snooze saved without its note (CL-006).
    if (new TextEncoder().encode(alasan).length > SNOOZE_ALASAN_MAKS_BYTE) {
        showToast('Alasan terlalu panjang (maksimal 4096 byte).', 'warning');
        return false;
    }

    document.getElementById('btnKirimSnooze').disabled = true;
    snoozePercakapanAktif(menitSnoozeDipilih, alasan);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSnooze')).hide();
    return false;
}

// Reason is stored as one Internal Note AFTER the snooze succeeds
// (REQ-011, no snooze_reason column). If only the note fails, the
// snooze still counts as done -- warn the staff, no retry, no rollback.
function snoozePercakapanAktif(menit, alasan) {
    if (!conversationAktif) return false;

    const conversationId = conversationAktif;

    fetch(INBOX_URL.percakapan + conversationId + '/snooze', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                menit: menit
            })
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status !== 'success') {
                showToast(json.message || 'Gagal follow-up.', 'danger');
                return;
            }

            muatUlangDaftarConversation();

            if (!(menit > 0 && alasan)) {
                showToast(menit > 0 ? 'Percakapan di-follow-up.' : 'Follow-up dibatalkan.', 'success');
                return;
            }

            return simpanAlasanSnooze(conversationId, alasan);
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        });

    return false;
}

// Single fetch to the Internal Note endpoint (spec 4.3, form field
// `teks`), shared by the snooze reason and the Catatan Internal
// dialog. Rejects on a server error or a network failure.
function kirimCatatanInternal(conversationId, teks) {
    return fetch(INBOX_URL.percakapan + conversationId + '/catatan', {
            method: 'POST',
            body: new URLSearchParams({
                teks: teks
            })
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status !== 'success') {
                throw new Error(json.message || 'Gagal menyimpan catatan.');
            }
            return json;
        });
}

function simpanAlasanSnooze(conversationId, alasan) {
    return kirimCatatanInternal(conversationId, alasan)
        .then(function() {
            showToast('Percakapan di-follow-up. Alasan disimpan sebagai Internal Note.', 'success');
            if (conversationAktif === conversationId) muatUlangPesan(false);
        })
        .catch(function() {
            showToast('Snooze berhasil, tapi alasan gagal disimpan.', 'warning');
        });
}

// ================================================================
// CATATAN INTERNAL (M3 Fase 1c, TASK-015)
// ================================================================
// Open to every logged-in staff on every conversation status,
// including closed -- no assigned_to/ownership gate (SEC-001,
// REQ-008). Never goes through kirimBalasan()/Gateway (CON-002).
let catatanInternalSedangKirim = false;

function bukaModalCatatanInternal() {
    if (!conversationAktif) return;

    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCatatanInternal'));

    // Closed and reopened while a save is still running: only show
    // the dialog. Resetting here would re-enable Simpan and allow a
    // second note to be sent before the first one finishes.
    if (catatanInternalSedangKirim) {
        modal.show();
        return;
    }

    document.getElementById('catatanInternalTeks').value = '';
    catatanInternalSedangKirim = false;
    document.getElementById('btnSimpanCatatanInternal').disabled = false;
    modal.show();
}

function simpanCatatanInternal(e) {
    e.preventDefault();
    if (!conversationAktif || catatanInternalSedangKirim) return false;

    const textarea = document.getElementById('catatanInternalTeks');
    const teks = textarea.value.trim();

    // Both checks run before any request (AC-010c). The limit is in
    // UTF-8 bytes because catatanInternal() checks strlen().
    if (!teks) {
        showToast('Catatan tidak boleh kosong.', 'warning');
        return false;
    }
    if (new TextEncoder().encode(teks).length > SNOOZE_ALASAN_MAKS_BYTE) {
        showToast('Catatan terlalu panjang (maksimal 4096 byte).', 'warning');
        return false;
    }

    const conversationId = conversationAktif;
    const btn = document.getElementById('btnSimpanCatatanInternal');
    catatanInternalSedangKirim = true;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';

    kirimCatatanInternal(conversationId, teks)
        .then(function() {
            // If the dialog was reopened and the text changed while
            // saving, leave it open so the new text is not lost.
            if (textarea.value.trim() === teks) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCatatanInternal')).hide();
                textarea.value = '';
            }
            showToast('Catatan internal disimpan.', 'success');
            if (String(conversationAktif) === String(conversationId)) muatUlangPesan(true);
        })
        .catch(function(err) {
            // Dialog stays open and the typed text is kept (AC-010d).
            showToast('Gagal menyimpan catatan: ' + err.message, 'danger');
        })
        .finally(function() {
            catatanInternalSedangKirim = false;
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Simpan';
        });

    return false;
}

function tutupPercakapan() {
    if (!conversationAktif) return;

    fetch(INBOX_URL.percakapan + conversationAktif + '/tutup', {
            method: 'POST'
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                showToast('Percakapan ditutup.', 'success');
                const idx = daftarConversation.findIndex(function(c) {
                    return String(c.id) === String(conversationAktif);
                });
                if (idx !== -1) daftarConversation[idx] = json.conversation;
                renderDaftarConversation();
                renderThreadHeader();
            } else {
                showToast(json.message || 'Gagal menutup percakapan.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        });
}

function ambilPercakapan() {
    if (!conversationAktif) return;

    fetch(INBOX_URL.percakapan + conversationAktif + '/ambil', {
            method: 'POST'
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                showToast('Percakapan berhasil diambil.', 'success');
                muatUlangDaftarConversation();
            } else {
                showToast(json.message || 'Gagal mengambil percakapan.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        });
}

function lepasPercakapan() {
    if (!conversationAktif) return;

    fetch(INBOX_URL.percakapan + conversationAktif + '/lepas', {
            method: 'POST'
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                showToast('Percakapan dilepas.', 'success');
                muatUlangDaftarConversation();
            } else {
                showToast(json.message || 'Gagal melepas percakapan.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        });
}

// ================================================================
// NUDGE "AMBIL" (Fase 2)
// ================================================================
// Nudge 1 (Fase 5): banner setelah 7 detik membuka percakapan belum_diambil.
// Timer disimpan global & selalu di-clear di awal pilihConversation().
function jadwalkanNudgeAmbil(id) {
    if (nudgeAmbilTimer !== null) {
        clearTimeout(nudgeAmbilTimer);
        nudgeAmbilTimer = null;
    }

    const kunci = String(id);
    // Banner sudah tampil untuk percakapan ini dan belum pindah -> jangan
    // ulang. Variabel ini di-reset saat pindah conversation.
    if (nudgeAmbilTampilUntukId === kunci) return;

    nudgeAmbilTimer = setTimeout(function() {
        nudgeAmbilTimer = null;

        // Thread harus masih percakapan yang sama.
        if (String(conversationAktif) !== kunci) return;
        // Tab harus aktif saat timer jatuh tempo.
        if (document.visibilityState !== 'visible') return;

        const conv = conversationAktifSaatIni();
        // Satu sumber kebenaran status: queue_status dari server
        // (withComputedStatus) -- BUKAN kombinasi kolom manual.
        if (!conv || conv.queue_status !== 'belum_diambil') return;

        nudgeAmbilTampilUntukId = kunci;
        const banner = document.getElementById('nudgeAmbilBanner');
        if (banner) banner.style.display = 'grid';
    }, 7000);
}

function tutupNudgeAmbil() {
    const banner = document.getElementById('nudgeAmbilBanner');
    if (banner) banner.style.display = 'none';
}

// [Lihat saja]: hanya menutup banner. TIDAK mencatat internal note apa pun.
function nudgeAmbilLihatSaja() {
    tutupNudgeAmbil();
}

function nudgeAmbilSekarang() {
    if (!conversationAktif) return;

    panggilAmbilPercakapan(conversationAktif).then(function(json) {
        if (json.status === 'success') {
            showToast('Percakapan berhasil diambil.', 'success');
            tutupNudgeAmbil();
            muatUlangDaftarConversation();
        } else {
            showToast(json.message || 'Gagal mengambil percakapan.', 'danger');
        }
    }).catch(function(err) {
        showToast('Gagal menghubungi server: ' + err.message, 'danger');
    });
}

// Helper Promise: endpoint Ambil existing. Fungsi lama ambilPercakapan()
// tidak mengembalikan promise sehingga tidak bisa ditunggu.
function panggilAmbilPercakapan(id) {
    return fetch(INBOX_URL.percakapan + id + '/ambil', {
        method: 'POST'
    }).then(function(res) {
        return res.json();
    });
}

// --- Nudge 2: konfirmasi sebelum unduh media -------------------
// Dipanggil oleh inbox-thread.js (unduhSatu/unduhTerpilih/
// unduhDariLightbox). Resolve true = lanjutkan unduh, false = batal.
let nudgeUnduhResolver = null;

function nudgeUnduhGate() {
    const conv = conversationAktifSaatIni();

    // Tanpa konteks / grup / sudah ditutup / sudah milik sendiri:
    // tidak perlu tanya apa pun.
    if (!conv) return Promise.resolve(true);
    if (conv.jid_type === 'group') return Promise.resolve(true);
    if (conv.status === 'closed') return Promise.resolve(true);
    if (conv.assigned_to && String(conv.assigned_to) === String(currentUserId)) {
        return Promise.resolve(true);
    }

    return new Promise(function(resolve) {
        bukaNudgeUnduhModal(conv, resolve);
    });
}

function bukaNudgeUnduhModal(conv, resolve) {
    nudgeUnduhResolver = resolve;

    const teks = document.getElementById('nudgeUnduhTeks');
    const btnAmbil = document.getElementById('btnNudgeAmbilUnduh');
    const belumDiambil = conv.queue_status === 'belum_diambil';
    const nama = conv.assigned_to_name || ('User #' + conv.assigned_to);

    if (belumDiambil) {
        teks.textContent = 'Chat ini belum diambil. Ambil dulu agar tidak diproses kasir lain?';
        btnAmbil.style.display = '';
        btnAmbil.textContent = 'Ambil & Unduh';
    } else if (conv.bisa_diambil) {
        teks.textContent = 'Dipegang ' + nama + ' (off-shift). Ambil alih?';
        btnAmbil.style.display = '';
        btnAmbil.textContent = 'Ambil Alih & Unduh';
    } else {
        teks.textContent = 'Dipegang ' + nama + '. Anda hanya bisa unduh.';
        btnAmbil.style.display = 'none';
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('nudgeUnduhModal')).show();
}

function nudgeUnduhPilih(aksi) {
    const resolve = nudgeUnduhResolver;
    nudgeUnduhResolver = null;

    const modalEl = document.getElementById('nudgeUnduhModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    if (aksi === 'batal') {
        modal.hide();
        if (resolve) resolve(false);
        return;
    }

    if (aksi === 'unduh') {
        // Catat note HANYA untuk percakapan belum_diambil (bukan yang
        // sudah dipegang orang lain). Best-effort, tidak menunggu.
        const conv = conversationAktifSaatIni();
        if (conv && conv.queue_status === 'belum_diambil') {
            catatNudgeUnduh(conversationAktif);
        }
        modal.hide();
        if (resolve) resolve(true);
        return;
    }

    // aksi === 'ambil' (juga dipakai Ambil Alih -- endpoint sama).
    panggilAmbilPercakapan(conversationAktif).then(function(json) {
        if (json.status === 'success') {
            showToast('Percakapan berhasil diambil.', 'success');
            muatUlangDaftarConversation();
        } else {
            // 409 "sudah diambil orang lain" -> tetap lanjut unduh (read-only).
            showToast(json.message || 'Gagal mengambil percakapan.', 'warning');
        }
        modal.hide();
        if (resolve) resolve(true);
    }).catch(function(err) {
        // Gagal jaringan: tetap lanjut unduh read-only.
        showToast('Gagal menghubungi server: ' + err.message, 'danger');
        modal.hide();
        if (resolve) resolve(true);
    });
}

function catatNudgeUnduh(id) {
    // Best-effort: kegagalan note tidak boleh mengganggu unduhan.
    fetch(INBOX_URL.percakapan + id + '/nudge-unduh', {
        method: 'POST'
    }).catch(function() {});
}

// ================================================================
// HANDOFF (M3 Fase 2a, TB-01/TASK-004)
// ================================================================
// expected_owner dibaca SAAT DIALOG DIBUKA (bukan saat submit) --
// itulah nilai assigned_to yang "dilihat" kasir; server memakainya
// sebagai syarat conditional write (`assigned_to <=> expected`),
// sehingga bentrok dua staff otomatis ditolak 409 (K-01/Q5).
let handoffExpectedOwner = '';

// Penjaga dobel-klik (TASK-007/D-03): selama satu request masih
// terbang, submit kedua diabaikan. Tombol submit juga di-disable saat
// request berjalan; flag ini menutup celah submit lewat Enter/klik
// sangat cepat sebelum disable sempat terpasang.
let handoffSedangKirim = false;

// Kode HTTP balasan Handoff terakhir -- dipakai untuk membedakan
// jalur kalah conditional write (409) dari penolakan lain, supaya
// panel riwayat hanya dimuat ulang saat keadaan server memang berubah.
let handoffStatusHttp = 0;

function bukaModalHandoff() {
    if (!conversationAktif) return;

    const conv = conversationAktifSaatIni();
    if (!conv) return;

    // null/undefined -> string kosong = "saya lihat belum diambil".
    handoffExpectedOwner = (conv.assigned_to === null || conv.assigned_to === undefined) ?
        '' :
        String(conv.assigned_to);

    document.getElementById('formHandoff').reset();
    document.getElementById('handoffAlert').classList.add('d-none');
    // Sisa keadaan dari percobaan sebelumnya tidak boleh terbawa.
    handoffSedangKirim = false;
    document.getElementById('btnKirimHandoff').disabled = false;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHandoff')).show();
}

// Pesan server dipakai APA ADANYA -- pada 409 pesan itu sudah memuat
// nama pemilik sah (atau fallback "User #{id}"), sehingga kasir tahu
// harus berkoordinasi dengan siapa. Ditulis ke elemen pesan supaya
// tombol "Muat ulang" di kotak yang sama tidak ikut terhapus.
function tampilkanNoticeHandoff(pesan) {
    document.getElementById('handoffAlertMessage').textContent = pesan;
    document.getElementById('handoffAlert').classList.remove('d-none');
}

// Aksi satu-klik untuk kasir yang kalah (TASK-007): segarkan daftar
// Queue + header dari server supaya UI tidak menampilkan keadaan basi,
// lalu tutup dialog agar nilai expected_owner lama tidak dikirim ulang.
// Percobaan ulang apa pun tetap ditolak 409 oleh server (K-09).
function muatUlangSetelahHandoffBasi() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHandoff')).hide();
    muatUlangDaftarConversation();
    showToast('Daftar percakapan dimuat ulang.', 'info');
}

// Label staff untuk event Handoff di timeline. Gunakan semua user,
// bukan hanya kasir aktif, karena riwayat dapat melibatkan admin/user lama.
const namaKasirById = INBOX_PAGE_CONFIG.semuaUserLabel;

function namaStaffHandoff(id) {
    if (id === null || id === undefined || id === '') return 'Belum diambil';
    const nama = namaKasirById[String(id)];
    return nama ? String(nama) : ('Kasir #' + id);
}

function kirimHandoff(e) {
    e.preventDefault();
    if (!conversationAktif) return false;

    // Dobel-klik/Enter berulang aman: selama request pertama masih
    // terbang, submit kedua diabaikan (lihat handoffSedangKirim).
    // Kalau request pertama ternyata kalah 409, klik ulang setelahnya
    // tetap ditolak 409 oleh server (K-09) -- tidak mungkin ada dua
    // pemilik sekaligus.
    if (handoffSedangKirim) return false;

    const target = document.getElementById('handoffTarget').value;
    const summary = document.getElementById('handoffSummary').value.trim();
    const btn = document.getElementById('btnKirimHandoff');

    if (!target) {
        showToast('Target Handoff wajib dipilih.', 'warning');
        return false;
    }

    handoffSedangKirim = true;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyerahkan...';

    const body = 'to_user_id=' + encodeURIComponent(target) +
        '&summary=' + encodeURIComponent(summary) +
        '&expected_owner=' + encodeURIComponent(handoffExpectedOwner);

    fetch(INBOX_URL.percakapan + conversationAktif + '/handoff', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body
        })
        .then(function(res) {
            handoffStatusHttp = res.status;
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHandoff')).hide();
                showToast(json.message || 'Percakapan berhasil diserahkan.', 'success');
                muatUlangDaftarConversation();
                // Handoff sekarang menjadi event timeline.
                muatUlangPesan(true);
            } else {
                // 400 (validasi), 403 (inisiator/target), 409 (kalah
                // conditional write / percakapan selesai) -- pesan
                // server dipakai apa adanya; 409 menyebut nama
                // pemilik sah supaya kasir tahu harus koordinasi
                // dengan siapa.
                tampilkanNoticeHandoff(json.message || 'Gagal menyerahkan percakapan.');
                showToast(json.message || 'Gagal menyerahkan percakapan.', 'danger');

                // 409 = kalah conditional write: owner (dan mungkin
                // riwayat) sudah berubah di server -- segarkan panel
                // supaya tidak menampilkan keadaan basi.
                // Tidak ada panel sticky yang perlu disegarkan.
            }
        })
        .catch(function(err) {
            tampilkanNoticeHandoff('Gagal menghubungi server: ' + err.message);
        })
        .finally(function() {
            handoffSedangKirim = false;
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-share-square"></i> Serahkan';
        });

    return false;
}

// ================================================================
// PILIH CONVERSATION & RIWAYAT PESAN
// ================================================================
function pilihConversation(id) {
    // Nudge 1: hentikan timer lama, sembunyikan banner, dan reset state
    // "sudah tampil" -- banner boleh muncul lagi saat kasir kembali ke
    // percakapan ini selama masih belum_diambil.
    if (nudgeAmbilTimer !== null) {
        clearTimeout(nudgeAmbilTimer);
        nudgeAmbilTimer = null;
    }
    tutupNudgeAmbil();
    nudgeAmbilTampilUntukId = null;

    conversationAktif = id;
    renderDaftarConversation();
    renderThreadHeader();

    // Balas Pesan: kutipan aktif milik percakapan sebelumnya. Kalau
    // dibawa, kasir bisa mengirim balasan ke percakapan A sambil
    // mengutip pesan dari percakapan B tanpa sadar -- server memang
    // akan menolak dengan 400, tapi lebih baik jangan sampai situasi
    // itu muncul sama sekali.
    batalkanKutipan();
    pesanTerkirimTanpaKutipan.clear();
    pesanCached = {};

    document.getElementById('teksBalasan').disabled = false;
    document.getElementById('teksBalasan').placeholder = 'Ketik balasan...';
    document.getElementById('btnKirimBalasan').disabled = false;
    document.getElementById('btnLampirkanMedia').disabled = false;
    document.getElementById('btnTemplateBalasan').disabled = false;
    document.getElementById('teksBalasan').focus();

    muatUlangPesan(true);
    panggilWhatsappDibaca(id);
    jadwalkanNudgeAmbil(id);
    return false;
}

/* ================================================================
   BALAS PESAN (Tahap 3)
   Kutipan yang SEDANG dipilih kasir, atau null. Yang disimpan HANYA id
   pesan sumber + label/cuplikan untuk ditampilkan -- isi kutipan yang
   sesungguhnya dikirim ke Gateway diambil ULANG dari database server
   saat tombol kirim ditekan (ALT-002: browser tidak pernah menentukan
   apa yang sebenarnya dikutip).
   ================================================================ */
let kutipanAktif = null;

/* ================================================================
   TERUSKAN (Tahap 4)
   Aksi Teruskan mengirim pesan SUMBER ke percakapan TUJUAN yang sudah
   ada. Yang disimpan di sini hanya ID pesan sumber + ID percakapan
   tujuan pilihan kasir: isi pesan yang benar-benar dikirim SELALU
   diambil server dari database-nya sendiri (Section 9 "Always do").
   ================================================================ */
let pesanTeruskanId = null;   // messages.id pesan sumber
let tujuanTeruskan = null;    // conversation.id tujuan yang dipilih
let teruskanSedangKirim = false;

/* Jenis pesan sumber yang diteruskan lewat endpoint /inbox/kirim-media;
   sisanya (teks) lewat /inbox/kirim. Dipakai teruskanPesan() memilih rute
   (TASK-007) -- tanpa mengunggah berkas apa pun di jalur media.

   PRN-301: daftar ini berasal dari SATU sumber kebenaran di server
   (`Inbox::TIPE_TERUSKAN_LAMPIRAN`, dikirim lewat `tipeTeruskanLampiran`),
   bukan salinan terpisah -- supaya rute UI dan guard server tidak
   menyimpang. */
const JALUR_MEDIA_TERUSKAN = INBOX_PAGE_CONFIG.tipeTeruskanLampiran;

/* Cuplikan singkat isi satu pesan untuk ditampilkan di area kutipan
   aktif. Meniru aturan yang sama dengan InboxQuoteSnapshotService di
   server: teks bila ada, kalau tidak label jenis media. */
function cuplikanPesan(m) {
    const teks = (m.text || '').trim();

    if (teks !== '') {
        return teks.length > 200 ? teks.slice(0, 200) + '\u2026' : teks;
    }

    const labelMedia = {
        image: '[Foto]',
        document: '[Dokumen]',
        sticker: '[Stiker]',
        audio: '[Audio]',
        video: '[Video]',
        location: '[Lokasi]',
        contact: '[Kontak]'
    };

    return labelMedia[m.message_type] || '[Pesan tanpa teks]';
}

function pilihKutipan(messageId) {
    // Data pesan diambil dari cache render terakhir -- tidak perlu
    // request tambahan ke server hanya untuk mengambil cuplikan yang
    // sudah tampil di layar.
    const m = pesanCached[messageId];
    if (!m) {
        showToast('Pesan tidak ditemukan untuk dikutip.', 'warning');
        return;
    }

    kutipanAktif = {
        id: messageId
    };

    const judul = document.getElementById('kutipanAktifJudul');
    const snippet = document.getElementById('kutipanAktifSnippet');

    // Yang ditampilkan di sini adalah ISI pesan yang sedang dipilih untuk
    // dikutip -- yaitu `m.text` miliknya sendiri, BUKAN `m.quoted_snippet`
    // yang menjelaskan pesan apa yang dikutip oleh pesan ini. Memakai kolom
    // kutipan akan menampilkan kotak kosong pada pesan biasa, persis
    // seperti yang terjadi saat uji manual pertama.
    //
    // Label pengirim memakai `sender_name` yang sudah dipakai bubble
    // (identitas Tahap 2 untuk grup, nama staff untuk pesan kasir), lalu
    // jatuh ke kalimat generik untuk percakapan yang identitasnya belum
    // diketahui -- mis. percakapan LID.
    judul.textContent = m.sender_name ? 'Membalas pesan dari ' + m.sender_name : 'Membalas pesan';
    snippet.textContent = cuplikanPesan(m);

    document.getElementById('kutipanAktif').style.display = 'flex';
    const textarea = document.getElementById('teksBalasan');
    if (textarea) textarea.focus();
}

function batalkanKutipan() {
    kutipanAktif = null;
    document.getElementById('kutipanAktif').style.display = 'none';
}

// ================================================================
// TERUSKAN (Tahap 4, REQ-004/REQ-005/REQ-008)
// ================================================================

/* Buka pemilih percakapan tujuan. Composer dinonaktifkan selama pemilih
   terbuka: isi pesan sumber tidak boleh diedit saat diteruskan (isi yang
   benar-benar dikirim diambil server, bukan dari layar ini). */
function bukaPemilihTeruskan(messageId) {
    const m = pesanCached[messageId];

    if (!m) {
        showToast('Pesan tidak ditemukan untuk diteruskan.', 'warning');
        return;
    }

    pesanTeruskanId = messageId;
    tujuanTeruskan = null;

    document.getElementById('teruskanSumberCuplikan').textContent = cuplikanPesan(m);
    document.getElementById('cariTujuanTeruskan').value = '';
    document.getElementById('btnKirimTeruskan').disabled = true;
    sembunyikanAlertTeruskan();

    document.getElementById('teksBalasan').disabled = true;
    document.getElementById('btnLampirkanMedia').disabled = true;
    document.getElementById('btnTemplateBalasan').disabled = true;
    document.getElementById('btnKirimBalasan').disabled = true;

    // Area kutipan aktif (bila ada) ikut dibekukan selama pemilih terbuka:
    // Teruskan TIDAK PERNAH membawa kutipan (CON-001), jadi tombol batalnya
    // dinonaktifkan supaya isi yang sedang disiapkan tidak berubah-ubah di
    // tengah aksi Teruskan.
    const batalKutipan = document.getElementById('btnBatalKutipan');
    if (batalKutipan) batalKutipan.disabled = true;

    muatDaftarTujuanTeruskan('');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTeruskan')).show();
}

/* Dipanggil setiap dialog ditutup (tombol Batal, X, Esc, atau setelah
   kirim sukses) lewat event `hidden.bs.modal` -- bukan hanya tombol
   Batal -- supaya composer tidak pernah tertinggal dalam keadaan mati. */
function tutupPemilihTeruskan() {
    pesanTeruskanId = null;
    tujuanTeruskan = null;

    if (conversationAktif) {
        document.getElementById('teksBalasan').disabled = false;
        document.getElementById('btnLampirkanMedia').disabled = false;
        document.getElementById('btnTemplateBalasan').disabled = false;
        document.getElementById('btnKirimBalasan').disabled = false;

        // Pulihkan tombol batal area kutipan aktif (kalau ada) -- lihat
        // catatan pembekuannya di bukaPemilihTeruskan().
        const batalKutipan = document.getElementById('btnBatalKutipan');
        if (batalKutipan) batalKutipan.disabled = false;
    }
}

function jalankanPencarianTujuanTeruskan() {
    muatDaftarTujuanTeruskan(document.getElementById('cariTujuanTeruskan').value);
}

/* REQ-005: pilihan HANYA percakapan yang sudah ada, memakai endpoint
   pencarian percakapan yang sudah ada (GET /inbox/api/conversations?q=&page=).
   TIDAK ADA opsi "buat percakapan baru" di alur ini. */
function muatDaftarTujuanTeruskan(kataKunci) {
    const container = document.getElementById('daftarTujuanTeruskan');
    if (!container) return;

    container.innerHTML = '<div class="text-muted small p-2">Memuat percakapan...</div>';

    const paramQ = kataKunci ? '&q=' + encodeURIComponent(kataKunci) : '';

    fetch(INBOX_URL.conversations + '?page=1' + paramQ)
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status !== 'success') throw new Error(json.message || 'Gagal memuat percakapan.');

            if (!json.conversations.length) {
                container.innerHTML = '<div class="text-muted small p-2">Tidak ada percakapan yang cocok.</div>';
                return;
            }

            container.innerHTML = json.conversations.map(function(c) {
                const iniPercakapanIni = String(c.id) === String(conversationAktif);
                const terpilih = tujuanTeruskan !== null && String(c.id) === String(tujuanTeruskan);

                return '<button type="button" class="teruskan-tujuan-item' + (terpilih ? ' terpilih' : '') + '"' +
                    ' onclick="pilihTujuanTeruskan(' + c.id + ', this)">' +
                    '<span class="teruskan-tujuan-nama">' + escapeHtmlInbox(formatIdentitasCustomer(c)) + '</span>' +
                    (iniPercakapanIni ?
                        ' <span class="badge bg-light text-dark border">percakapan ini</span>' :
                        '') +
                    '</button>';
            }).join('');
        })
        .catch(function(err) {
            container.innerHTML = '<div class="text-danger small p-2">' + escapeHtmlInbox(err.message) + '</div>';
        });
}

/* Section 12: percakapan tujuan boleh sama dengan sumber; baris yang
   terpilih selalu ditandai jelas supaya kasir tidak salah percakapan. */
function pilihTujuanTeruskan(id, tombol) {
    tujuanTeruskan = id;
    document.getElementById('btnKirimTeruskan').disabled = false;

    document.querySelectorAll('#daftarTujuanTeruskan .teruskan-tujuan-item').forEach(function(el) {
        el.classList.remove('terpilih');
    });

    if (tombol) tombol.classList.add('terpilih');
}

function tampilkanAlertTeruskan(pesan) {
    document.getElementById('teruskanAlertMessage').textContent = pesan;
    document.getElementById('teruskanAlert').classList.remove('d-none');
}

function sembunyikanAlertTeruskan() {
    const el = document.getElementById('teruskanAlert');
    if (el) el.classList.add('d-none');
}

function kirimTeruskan(e) {
    e.preventDefault();
    teruskanPesan();
    return false;
}

/* Kirim aksi Teruskan (REQ-010: memakai idempotensi `operation_id` yang
   sudah ada; Section 9: `text` TIDAK PERNAH dikirim dari browser). */
function teruskanPesan() {
    if (teruskanSedangKirim) return;

    if (!pesanTeruskanId || !tujuanTeruskan) {
        tampilkanAlertTeruskan('Pilih percakapan tujuan dulu.');
        return;
    }

    if (!gatewayTerhubung) {
        tampilkanAlertTeruskan('Gateway terputus -- pesan belum bisa dikirim sekarang.');
        return;
    }

    teruskanSedangKirim = true;
    const btn = document.getElementById('btnKirimTeruskan');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim...';
    sembunyikanAlertTeruskan();

    const tujuan = tujuanTeruskan;
    const operationId = ambilOperationIdTeruskan();
    const sumber = pesanCached[pesanTeruskanId];

    // REQ-005/REQ-006/TASK-007: rute otomatis menurut jenis pesan sumber.
    // Lampiran (gambar/dokumen/stiker) lewat /inbox/kirim-media TANPA
    // mengunggah berkas apa pun -- FormData di bawah sengaja hanya membawa
    // tiga field (conversation_id, forward_from_message_id, operation_id);
    // byte-nya diambil server dari baris sumber (Section 9 "Always do").
    const lewatJalurMedia = !!(sumber && JALUR_MEDIA_TERUSKAN.indexOf(sumber.message_type) !== -1);

    let permintaan;

    if (lewatJalurMedia) {
        const formData = new FormData();
        formData.append('conversation_id', tujuan);
        formData.append('forward_from_message_id', pesanTeruskanId);
        formData.append('operation_id', operationId);

        permintaan = fetch(INBOX_URL.kirimMedia, {
            method: 'POST',
            body: formData
        });
    } else {
        permintaan = fetch(INBOX_URL.kirim, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'conversation_id=' + encodeURIComponent(tujuan) +
                '&forward_from_message_id=' + encodeURIComponent(pesanTeruskanId) +
                '&operation_id=' + encodeURIComponent(operationId)
        });
    }

    permintaan
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                // Operasi selesai: kunci dibuang supaya Teruskan berikutnya
                // memakai operasi baru.
                buangOperationIdTeruskan();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalTeruskan')).hide();
                showToast('Pesan diteruskan.', 'success');
                // ASSUMPTION-015: pindah ke thread tujuan supaya hasilnya
                // langsung terlihat, lalu segarkan daftar percakapan
                // (tujuan menjadi yang teratas).
                pilihConversation(tujuan);
                muatUlangDaftarConversation();
            } else {
                tampilkanAlertTeruskan(json.message || 'Gagal meneruskan pesan.');
            }
        })
        .catch(function(err) {
            tampilkanAlertTeruskan('Gagal menghubungi server: ' + err.message);
        })
        .finally(function() {
            teruskanSedangKirim = false;
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-share"></i> Teruskan';
        });
}

function muatUlangPesan(scrollPaksa) {
    if (!conversationAktif) return;

    // Respons yang datang setelah kasir pindah percakapan dibuang, supaya
    // tidak menimpa thread percakapan yang sekarang dibuka.
    const idDimuat = conversationAktif;

    fetch(INBOX_URL.conversations + '/' + idDimuat + '/messages')
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success' && idDimuat === conversationAktif) {
                terimaPesanTerbaru(idDimuat, json, scrollPaksa);
            }
        })
        .catch(function() {
            // Diamkan, siklus polling berikutnya coba lagi.
        });
}

// ================================================================
// CHAT BARU (ke nomor yang belum pernah masuk)
// ================================================================
function mulaiChatBaru(e) {
    e.preventDefault();

    if (!gatewayTerhubung) {
        alert('Gateway terputus -- pesan belum bisa dikirim sekarang. Draft Anda tetap tersimpan, coba lagi begitu status kembali "Terhubung".');
        return false;
    }

    const nomor = document.getElementById('nomorChatBaru').value.trim();
    const text = document.getElementById('teksChatBaru').value.trim();
    const btn = document.getElementById('btnChatBaru');

    if (!nomor || !text) {
        showToast('Nomor dan pesan wajib diisi.', 'warning');
        return false;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim...';

    fetch(INBOX_URL.mulaiPercakapan, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'phone=' + encodeURIComponent(nomor) + '&text=' + encodeURIComponent(text)
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                showToast('Chat berhasil dimulai!', 'success');

                // Tutup modal, reset form.
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChatBaru')).hide();
                document.getElementById('formChatBaru').reset();

                // Muat ulang daftar conversation, lalu langsung buka
                // conversation yang baru dibuat/dipakai.
                ambilSemuaConversation(kataKunciAktif)
                    .then(function(semua) {
                        daftarConversation = semua;
                        renderDaftarConversation();
                        pilihConversation(json.conversation_id);
                    })
                    .catch(function() {
                        // Diamkan -- polling berikutnya memuat ulang daftar.
                    });
            } else {
                showToast(json.message || 'Gagal memulai chat.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Kirim & Mulai Chat';
        });

    return false;
}

// ================================================================
// LAMPIRAN MEDIA (antrian, siap dikirim berurutan bareng pesan berikutnya)
// ================================================================
// Tiap entri: { file, operationId, status: 'pending'|'sending'|'failed', error }.
// operationId dibuat per-item (bukan per-form) saat item itu mulai
// dikirim, dan DIPERTAHANKAN di item kalau gagal -- prinsip idempotensi
// yang sama dengan operation_id form (REQ-039), tapi granularitasnya
// per-file karena satu antrian bisa menghasilkan banyak pesan terpisah.
let antrianMediaBalasan = [];
let urutanAntrianBerikutnya = 1;

function renderAntrianMediaBalasan() {
    const wrap = document.getElementById('previewMediaBalasan');
    wrap.innerHTML = '';

    if (antrianMediaBalasan.length === 0) {
        wrap.style.display = 'none';
        document.getElementById('teksBalasan').placeholder = 'Ketik balasan...';
        return;
    }

    wrap.style.display = 'flex';
    document.getElementById('teksBalasan').placeholder = 'Caption (opsional, untuk file terakhir)...';

    antrianMediaBalasan.forEach(function(item) {
        const gagal = item.status === 'failed';
        const span = document.createElement('span');
        span.className = 'badge bg-light text-dark border' + (gagal ? ' border-danger' : '');
        span.title = gagal ? item.error : item.file.name;

        const icon = document.createElement('i');
        icon.className = 'fas ' + (gagal ? 'fa-triangle-exclamation text-danger' : 'fa-paperclip');
        span.appendChild(icon);
        span.appendChild(document.createTextNode(' ' + item.file.name));

        if (gagal) {
            const labelGagal = document.createElement('span');
            labelGagal.className = 'text-danger';
            labelGagal.textContent = ' (gagal)';
            span.appendChild(labelGagal);
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-close btn-sm ms-1';
        btn.style.fontSize = '0.6rem';
        btn.addEventListener('click', function() {
            hapusSatuMediaBalasan(item.id);
        });
        span.appendChild(btn);

        wrap.appendChild(span);
    });
}

// Dipakai bersama oleh input file (klik paperclip), drag-and-drop, dan
// paste -- satu tempat yang menambah file ke antrian, supaya ketiga
// jalur selalu berperilaku identik.
function tambahMediaBalasan(files) {
    if (!files || files.length === 0) return;

    Array.prototype.forEach.call(files, function(file) {
        antrianMediaBalasan.push({
            id: urutanAntrianBerikutnya++,
            file: file,
            operationId: null,
            status: 'pending',
            error: null
        });
    });

    renderAntrianMediaBalasan();
    // Antrian berubah = isi composer berubah -> operasi baru
    // (M1 Wave 2, TASK-019).
    buangOperationIdBalasan();
    sembunyikanStatusKirimBalasan();
}

function pilihMediaBalasan(e) {
    tambahMediaBalasan(e.target.files);
    e.target.value = '';
}

function hapusSatuMediaBalasan(id) {
    antrianMediaBalasan = antrianMediaBalasan.filter(function(item) {
        return item.id !== id;
    });
    renderAntrianMediaBalasan();
    buangOperationIdBalasan();
    sembunyikanStatusKirimBalasan();
}

function batalkanMediaBalasan() {
    antrianMediaBalasan = [];
    document.getElementById('inputMediaBalasan').value = '';
    renderAntrianMediaBalasan();
    // Lampiran dibatalkan = isi composer berubah -> operasi baru
    // (M1 Wave 2, TASK-019).
    buangOperationIdBalasan();
    sembunyikanStatusKirimBalasan();
}

// Template Balasan Cepat (TODO-R1): lihat public/assets/js/inbox-template.js
// (bukaModalTemplate, muatDaftarTemplateBalasan, pilihTemplate) -- dipindah
// ke file terpisah supaya bisa diuji lewat tests/js/inbox-template.test.js,
// mengikuti preseden ekstraksi inbox-thread.js (desain §7).

// --- Drag-and-drop file ke panel chat (mirip WhatsApp Web) ---------
// dragCounter menghitung dragenter/dragleave bersarang (browser
// memicu dragleave setiap kali kursor pindah ke elemen ANAK di
// dalam panel, bukan cuma saat benar-benar keluar panel) -- tanpa
// ini, overlay akan flicker hilang-muncul saat drag melintasi
// pesan/tombol di dalam panel.
let dragCounterInbox = 0;

(function initDropZoneInbox() {
    const panel = document.getElementById('inboxThreadPanel');
    const overlay = document.getElementById('inboxDropOverlay');

    panel.addEventListener('dragenter', function(e) {
        if (!conversationAktif) return;
        e.preventDefault();
        dragCounterInbox++;
        overlay.classList.add('active');
    });

    panel.addEventListener('dragover', function(e) {
        if (!conversationAktif) return;
        e.preventDefault(); // wajib, supaya browser mengizinkan drop
    });

    panel.addEventListener('dragleave', function(e) {
        dragCounterInbox = Math.max(0, dragCounterInbox - 1);
        if (dragCounterInbox === 0) overlay.classList.remove('active');
    });

    panel.addEventListener('drop', function(e) {
        e.preventDefault();
        dragCounterInbox = 0;
        overlay.classList.remove('active');

        if (!conversationAktif) return;

        tambahMediaBalasan(e.dataTransfer.files);
    });
})();

// --- Paste file dari clipboard (Ctrl+V), mirip WhatsApp Web --------
// Hanya diaktifkan kalau clipboard memang berisi file -- paste teks
// biasa ke composer TIDAK di-preventDefault, supaya tetap jalan normal.
document.getElementById('teksBalasan').addEventListener('paste', function(e) {
    if (!conversationAktif) return;
    if (!e.clipboardData || !e.clipboardData.items) return;

    const files = [];
    Array.prototype.forEach.call(e.clipboardData.items, function(item) {
        if (item.kind === 'file') {
            const file = item.getAsFile();
            if (file) files.push(file);
        }
    });

    if (files.length === 0) return;

    e.preventDefault();
    tambahMediaBalasan(files);
});

// ================================================================
// EDIT/HAPUS DARI ROW DAFTAR PERCAKAPAN (panel kiri)
// ================================================================

// Edit: percakapan dibuka dulu (nama, pesan, header ikut render),
// BARU modal edit profil dibuka -- reuse pilihConversation() +
// bukaModalEditProfil() apa adanya, tidak ada logic baru di sini.
function editPercakapanDariList(id) {
    pilihConversation(id);
    bukaModalEditProfil();
}

// Hapus: SENGAJA TIDAK memanggil pilihConversation() -- klik Hapus
// pada row yang sedang tidak aktif tidak boleh ikut membuka
// percakapan itu (beda dari Edit). Target hapus disimpan terpisah
// dari conversationAktif (conversationUntukHapus) supaya tidak
// mengganggu percakapan yang sedang dibuka di panel kanan.
function hapusPercakapanDariList(id) {
    conversationUntukHapus = id;

    const conv = cariConversation(id);
    document.getElementById('namaHapusPercakapan').textContent = formatIdentitasCustomer(conv);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHapusPercakapan')).show();
}

function konfirmasiHapusPercakapan() {
    if (!conversationUntukHapus) return;

    const idDihapus = conversationUntukHapus;
    const btn = document.getElementById('btnKonfirmasiHapusPercakapan');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menghapus...';

    fetch(INBOX_URL.percakapan + idDihapus + '/hapus', {
            method: 'POST'
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHapusPercakapan')).hide();
                showToast('Percakapan berhasil dihapus.', 'success');

                daftarConversation = daftarConversation.filter(function(c) {
                    return String(c.id) !== String(idDihapus);
                });
                renderDaftarConversation();

                // Panel kanan HANYA direset kalau yang dihapus memang
                // conversation yang sedang aktif/terbuka -- kalau kasir
                // menghapus row lain, percakapan yang sedang dibuka
                // tetap tampil apa adanya.
                if (String(idDihapus) === String(conversationAktif)) {
                    // Reuse the shared reset so tab switch and deletion
                    // keep the right panel in exactly the same empty state.
                    kosongkanThreadPanel();
                }

                conversationUntukHapus = null;
            } else {
                showToast(json.message || 'Gagal menghapus percakapan.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash-alt"></i> Ya, Hapus Permanen';
        });
}

// ================================================================
// EDIT PROFIL PELANGGAN (Task Group 1.5 -- nama & nomor manual)
// ================================================================
function bukaModalEditProfil() {
    if (!conversationAktif) return;

    // Prefill dari data yang SUDAH ADA (sumber sama dengan yang dipakai
    // formatIdentitasCustomer()/daftar kiri) -- nama manual (contact_name)
    // menang, fallback ke whatsapp_name; nomor manual (manual_phone)
    // menang, fallback ke phone ter-verifikasi. Jangan biarkan kosong
    // kalau salah satu sumber itu sudah terisi.
    const conv = conversationAktifSaatIni();
    document.getElementById('editProfilNama').value = (conv && (conv.contact_name || conv.whatsapp_name)) || '';
    document.getElementById('editProfilTelepon').value = (conv && (conv.manual_phone || conv.phone)) || '';

    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditProfil')).show();
}

function simpanProfilPelanggan(e) {
    e.preventDefault();
    if (!conversationAktif) return false;

    const nama = document.getElementById('editProfilNama').value.trim();
    const telepon = document.getElementById('editProfilTelepon').value.trim();
    const btn = document.getElementById('btnSimpanProfil');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';

    fetch(INBOX_URL.percakapan + conversationAktif + '/profil', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'customer_name=' + encodeURIComponent(nama) + '&phone=' + encodeURIComponent(telepon)
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditProfil')).hide();
                showToast('Profil pelanggan disimpan.', 'success');

                // Perbarui entri lokal langsung, tidak perlu menunggu polling.
                const idx = daftarConversation.findIndex(function(c) {
                    return String(c.id) === String(conversationAktif);
                });
                if (idx !== -1) {
                    daftarConversation[idx] = json.conversation;
                }
                renderDaftarConversation();
                renderThreadHeader();
            } else {
                showToast(json.message || 'Gagal menyimpan profil.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Simpan';
        });

    return false;
}

// ================================================================
// KONFIRMASI NOMOR WHATSAPP (revisi LID-FIRST -> PN-LATER)
// ================================================================
function bukaModalKonfirmasiNomor() {
    if (!conversationAktif) return;

    document.getElementById('konfirmasiNomorInput').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiNomor')).show();
}

function simpanKonfirmasiNomor(e) {
    e.preventDefault();
    if (!conversationAktif) return false;

    const nomor = document.getElementById('konfirmasiNomorInput').value.trim();
    const btn = document.getElementById('btnKonfirmasiNomor');

    if (!nomor) {
        showToast('Nomor wajib diisi.', 'warning');
        return false;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Menyimpan...';

    fetch(INBOX_URL.percakapan + conversationAktif + '/konfirmasi-nomor', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'phone=' + encodeURIComponent(nomor)
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiNomor')).hide();
                showToast('Nomor WhatsApp dikonfirmasi. Pesan berikutnya dari nomor ini akan otomatis masuk ke sini.', 'success');

                const idx = daftarConversation.findIndex(function(c) {
                    return String(c.id) === String(conversationAktif);
                });
                if (idx !== -1) {
                    daftarConversation[idx] = json.conversation;
                }
                renderDaftarConversation();
                renderThreadHeader();
            } else {
                showToast(json.message || 'Gagal konfirmasi nomor.', 'danger');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-shield-alt"></i> Ya, Konfirmasi';
        });

    return false;
}

// ================================================================
// OPERATION ID KIRIM BALASAN (M1 Wave 2, TASK-019 / REQ-039/REQ-041)
// ================================================================
// Kunci idempotensi MILIK FRONTEND (client-generated). AuliaPos tidak
// pernah membuatnya di server. Kunci hidup di form balas
// (`data-operation-id`) selama satu percobaan kirim:
// - dipakai ulang saat kirim ulang setelah gagal/timeout, supaya
//   Gateway bisa mengenali percobaan yang sama dan tidak menggandakan
//   pesan pelanggan;
// - dibuang setelah kirim BERHASIL atau setelah isi composer berubah
//   (pesan berbeda = operasi berbeda);
// - dibuat baru saat Gateway menolak dengan OPERATION_ID_REUSED.
function buatOperationId() {
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
    if (!form) return buatOperationId();

    let kunci = form.getAttribute('data-operation-id');
    if (!kunci) {
        kunci = buatOperationId();
        form.setAttribute('data-operation-id', kunci);
    }
    return kunci;
}

function buangOperationIdBalasan() {
    const form = document.getElementById('formBalas');
    if (form) form.setAttribute('data-operation-id', '');
}

// Kunci idempotensi aksi Teruskan. Memakai pola yang sama dengan pasangan
// Balas di atas, tetapi hidup pada form-nya SENDIRI: kalau kunci balasan
// yang belum selesai dipakai ulang oleh Teruskan ke percakapan yang sama,
// Gateway/AuliaPos bisa membacanya sebagai replay dan pesan Teruskan tidak
// pernah benar-benar dikirim (REQ-010 tetap terjaga: mekanismenya sama,
// tidak ada kunci yang dibuat server).
function ambilOperationIdTeruskan() {
    const form = document.getElementById('formTeruskan');
    if (!form) return buatOperationId();

    let kunci = form.getAttribute('data-operation-id');
    if (!kunci) {
        kunci = buatOperationId();
        form.setAttribute('data-operation-id', kunci);
    }
    return kunci;
}

function buangOperationIdTeruskan() {
    const form = document.getElementById('formTeruskan');
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

// ================================================================
// EDIT / HAPUS PESAN KELUAR (task 2026-10-07)
// ================================================================
// Kunci idempotensi per-aksi hidup sampai operasi SELESAI; retry manual
// (mis. setelah "hasil belum pasti") memakai kunci yang SAMA supaya adapter
// membacanya sebagai operasi yang sama, bukan aksi baru. Kunci hanya dibuat
// ulang saat adapter menolak dengan OPERATION_ID_REUSED.
let editPesanAktif = { id: null, operationId: null };
let hapusPesanAktif = { id: null, operationId: null };

function tampilkanAlertPesanKeluar(elId, pesan, tipe) {
    const el = document.getElementById(elId);
    if (!el) return;
    el.className = 'alert alert-' + (tipe || 'danger') + ' small';
    el.textContent = pesan;
    el.classList.remove('d-none');
}

function sembunyikanAlertPesanKeluar(elId) {
    const el = document.getElementById(elId);
    if (el) el.classList.add('d-none');
}

function bukaEditPesan(id) {
    const pesan = (typeof pesanDikenal === 'function') ? pesanDikenal(id) : null;
    if (!pesan) {
        showToast('Pesan tidak ada di layar; muat ulang lalu coba lagi.', 'warning');
        return;
    }
    editPesanAktif = { id: id, operationId: null };
    sembunyikanAlertPesanKeluar('editPesanAlert');
    const ta = document.getElementById('teksEditPesan');
    ta.value = pesan.text || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditPesan')).show();
}

function simpanEditPesan(e) {
    e.preventDefault();
    if (!editPesanAktif.id) return false;

    const teks = document.getElementById('teksEditPesan').value.trim();
    if (!teks) {
        tampilkanAlertPesanKeluar('editPesanAlert', 'Teks pesan tidak boleh kosong.', 'warning');
        return false;
    }
    if (teks.length > 4096) {
        tampilkanAlertPesanKeluar('editPesanAlert', 'Teks terlalu panjang (maksimal 4096 karakter).', 'warning');
        return false;
    }

    const btn = document.getElementById('btnSimpanEditPesan');
    btn.disabled = true;
    if (!editPesanAktif.operationId) editPesanAktif.operationId = buatOperationId();

    fetch(INBOX_URL.pesan + editPesanAktif.id + '/edit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'new_text=' + encodeURIComponent(teks) + '&operation_id=' + encodeURIComponent(editPesanAktif.operationId)
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.status === 'success') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditPesan')).hide();
                editPesanAktif = { id: null, operationId: null };
                showToast('Pesan berhasil diedit.', 'success');
                muatUlangPesan(false);
                return;
            }
            if (json.error_code === 'OPERATION_ID_REUSED') {
                // Kunci lama tidak boleh dipakai lagi -> buang, retry pakai kunci baru.
                editPesanAktif.operationId = null;
                tampilkanAlertPesanKeluar('editPesanAlert', json.message || 'Kunci pengiriman tidak valid. Coba simpan lagi.', 'danger');
                return;
            }
            if (json.error_code === 'EDIT_UNRESOLVED' || json.error_code === 'SEND_UNRESOLVED') {
                // Hasil belum pasti: JANGAN auto-retry; kunci dipertahankan.
                tampilkanAlertPesanKeluar('editPesanAlert', 'Hasil edit belum pasti. Cek WhatsApp Web sebelum coba lagi.', 'warning');
                showToast('Hasil edit belum pasti. Cek WhatsApp Web.', 'warning');
                return;
            }
            tampilkanAlertPesanKeluar('editPesanAlert', json.message || 'Gagal mengedit pesan.', 'danger');
            showToast(json.message || 'Gagal mengedit pesan.', 'danger');
        })
        .catch(function(err) {
            // Jaringan gagal: hasil TIDAK diketahui -> jangan retry otomatis.
            tampilkanAlertPesanKeluar('editPesanAlert', 'Gagal menghubungi server: ' + err.message + ' Hasil belum pasti, cek WhatsApp Web.', 'warning');
            showToast('Hasil edit belum pasti. Cek WhatsApp Web.', 'warning');
        })
        .finally(function() { btn.disabled = false; });

    return false;
}

function bukaKonfirmasiHapus(id) {
    hapusPesanAktif = { id: id, operationId: null };
    sembunyikanAlertPesanKeluar('hapusPesanAlert');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiHapus')).show();
}

function konfirmasiHapusPesan() {
    if (!hapusPesanAktif.id) return;

    const btn = document.getElementById('btnKonfirmasiHapus');
    btn.disabled = true;
    if (!hapusPesanAktif.operationId) hapusPesanAktif.operationId = buatOperationId();

    fetch(INBOX_URL.pesan + hapusPesanAktif.id + '/hapus', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'operation_id=' + encodeURIComponent(hapusPesanAktif.operationId)
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.status === 'success') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiHapus')).hide();
                hapusPesanAktif = { id: null, operationId: null };
                showToast('Pesan berhasil dihapus.', 'success');
                muatUlangPesan(false);
                return;
            }
            if (json.error_code === 'OPERATION_ID_REUSED') {
                hapusPesanAktif.operationId = null;
                tampilkanAlertPesanKeluar('hapusPesanAlert', json.message || 'Kunci pengiriman tidak valid. Coba lagi.', 'danger');
                return;
            }
            if (json.error_code === 'DELETE_UNRESOLVED') {
                tampilkanAlertPesanKeluar('hapusPesanAlert', 'Hasil hapus belum pasti. Cek WhatsApp Web sebelum coba lagi.', 'warning');
                showToast('Hasil hapus belum pasti. Cek WhatsApp Web.', 'warning');
                return;
            }
            tampilkanAlertPesanKeluar('hapusPesanAlert', json.message || 'Gagal menghapus pesan.', 'danger');
            showToast(json.message || 'Gagal menghapus pesan.', 'danger');
        })
        .catch(function(err) {
            tampilkanAlertPesanKeluar('hapusPesanAlert', 'Gagal menghubungi server: ' + err.message + ' Hasil belum pasti, cek WhatsApp Web.', 'warning');
            showToast('Hasil hapus belum pasti. Cek WhatsApp Web.', 'warning');
        })
        .finally(function() { btn.disabled = false; });
}

// ================================================================
// KIRIM BALASAN (teks, atau media kalau ada lampiran dipilih)
// ================================================================
function kirimBalasan(e) {
    e.preventDefault();

    if (!gatewayTerhubung) {
        alert('Gateway terputus -- pesan belum bisa dikirim sekarang. Draft Anda tetap tersimpan, coba lagi begitu status kembali "Terhubung".');
        return false;
    }

    if (!conversationAktif) {
        showToast('Pilih percakapan dulu.', 'warning');
        return false;
    }

    if (antrianMediaBalasan.length > 0) {
        return kirimAntrianMediaBalasan();
    }

    const textarea = document.getElementById('teksBalasan');
    const btn = document.getElementById('btnKirimBalasan');
    const text = textarea.value.trim();

    if (!text) {
        showToast('Pesan tidak boleh kosong.', 'warning');
        return false;
    }

    btn.disabled = true;
    textarea.disabled = true;

    // Kunci idempotensi percobaan ini. Dibuat di sini kalau belum ada --
    // dan DIPERTAHANKAN kalau kirim ulang karena gagal/timeout.
    const operationId = ambilOperationIdBalasan();

    // Balas Pesan (Tahap 3): hanya ID LOKAL pesan yang dipilih. Isi
    // kutipan TIDAK ikut dikirim -- server mengambilnya sendiri dari
    // database (ALT-002, spec Section 4.3).
    const quotedMessageId = kutipanAktif ? kutipanAktif.id : null;

    fetch(INBOX_URL.kirim, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'conversation_id=' + encodeURIComponent(conversationAktif) +
                '&text=' + encodeURIComponent(text) +
                '&operation_id=' + encodeURIComponent(operationId) +
                (quotedMessageId === null ? '' : '&quoted_message_id=' + encodeURIComponent(quotedMessageId))
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                textarea.value = '';
                autoResizeTeksBalasan();
                // Kirim berhasil: operasi selesai, kunci dibuang supaya
                // pesan berikutnya memakai operasi baru.
                buangOperationIdBalasan();
                sembunyikanStatusKirimBalasan();
                // Kutipan sudah terkirim sebagai bagian pesan ini, jadi
                // area kutipan aktif ditutup.
                batalkanKutipan();
                // Reaksi (a) REQ-006: Gateway tetap mengirim isi pesan
                // tapi TIDAK menerapkan kutipan. Kasir diberi tahu
                // eksplisit supaya tidak mengira kutipan sudah sampai
                // ke pelanggan.
                if (json.quote_applied === false) {
                    pesanTerkirimTanpaKutipan.add(json.message.id);
                    showToast('Pesan terkirim, TAPI tanpa kutipan -- kutipan tidak sampai ke penerima.', 'warning');
                }
                // Langsung tampilkan pesan baru tanpa nunggu polling
                // (sesuai spec: outgoing langsung terlihat setelah sukses).
                tampilkanBubbleOutgoing(json.message);
                muatUlangDaftarConversation();
            } else {
                tanganiKegagalanKirimBalasan(json, 'Gagal mengirim pesan.');
            }
        })
        .catch(function(err) {
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
        })
        .finally(function() {
            btn.disabled = false;
            textarea.disabled = false;
            textarea.focus();
        });

    return false;
}

// Kirim satu item antrian ke /inbox/kirim-media. caption/quotedMessageId
// hanya diisi kalau item ini adalah item TERAKHIR dalam antrian (lihat
// kirimAntrianMediaBalasan) -- mirip WhatsApp Web: caption nempel di
// pesan terakhir, file lain terkirim tanpa caption.
function kirimSatuMediaBalasan(item, caption, quotedMessageId) {
    if (!item.operationId) {
        item.operationId = buatOperationId();
    }

    const formData = new FormData();
    formData.append('conversation_id', conversationAktif);
    formData.append('caption', caption);
    formData.append('media', item.file);
    formData.append('operation_id', item.operationId);

    if (quotedMessageId !== null) {
        formData.append('quoted_message_id', quotedMessageId);
    }

    return fetch(INBOX_URL.kirimMedia, {
            method: 'POST',
            body: formData
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                if (json.quote_applied === false) {
                    pesanTerkirimTanpaKutipan.add(json.message.id);
                    showToast('Media terkirim, TAPI tanpa kutipan -- kutipan tidak sampai ke penerima.', 'warning');
                }
                tampilkanBubbleOutgoing(json.message);
                return true;
            }

            item.status = 'failed';
            item.error = json.message || 'Gagal mengirim media.';
            tanganiKegagalanKirimBalasan(json, 'Gagal mengirim media.');
            return false;
        })
        .catch(function(err) {
            item.status = 'failed';
            item.error = err.message;
            showToast('Gagal menghubungi server: ' + err.message, 'danger');
            return false;
        });
}

// Kirim seluruh antrian lampiran secara BERURUTAN (1 request per file,
// nunggu hasil sebelum lanjut ke file berikutnya -- supaya urutan
// terkirim sama seperti urutan drop/pilih, mirip WhatsApp Web). Item
// yang gagal ditandai di badge dan TETAP di antrian (tidak menghentikan
// sisanya); item yang sukses langsung dihapus dari antrian.
async function kirimAntrianMediaBalasan() {
    if (!gatewayTerhubung) {
        alert('Gateway terputus -- pesan belum bisa dikirim sekarang. Draft Anda tetap tersimpan, coba lagi begitu status kembali "Terhubung".');
        return false;
    }

    const textarea = document.getElementById('teksBalasan');
    const btn = document.getElementById('btnKirimBalasan');
    const attachBtn = document.getElementById('btnLampirkanMedia');
    const templateBtn = document.getElementById('btnTemplateBalasan');
    const caption = textarea.value.trim();
    // Hanya ID LOKAL yang ikut -- isi kutipan tetap diambil ulang dari
    // database server saat permintaan diproses (ALT-002), persis seperti
    // jalur teks (Tahap 3, TASK-007/AC-003b).
    const quotedMessageId = kutipanAktif ? kutipanAktif.id : null;

    btn.disabled = true;
    textarea.disabled = true;
    attachBtn.disabled = true;
    templateBtn.disabled = true;

    const antrianKirim = antrianMediaBalasan.filter(function(item) {
        return item.status !== 'failed';
    });
    let adaGagal = false;

    for (let i = 0; i < antrianKirim.length; i++) {
        const item = antrianKirim[i];
        const isTerakhir = (i === antrianKirim.length - 1);

        if (!gatewayTerhubung) {
            // Gateway putus di tengah antrian -- sisa item yang belum
            // dicoba ditandai gagal tanpa dikirim, bukan dipaksa ke
            // gateway yang sudah mati.
            item.status = 'failed';
            item.error = 'Gateway terputus sebelum file ini terkirim.';
            adaGagal = true;
            continue;
        }

        item.status = 'sending';
        const sukses = await kirimSatuMediaBalasan(
            item,
            isTerakhir ? caption : '',
            isTerakhir ? quotedMessageId : null
        );

        if (sukses) {
            antrianMediaBalasan = antrianMediaBalasan.filter(function(x) {
                return x.id !== item.id;
            });
        } else {
            adaGagal = true;
        }
    }

    renderAntrianMediaBalasan();

    if (!adaGagal) {
        textarea.value = '';
        autoResizeTeksBalasan();
        buangOperationIdBalasan();
        sembunyikanStatusKirimBalasan();
        batalkanKutipan();
    } else {
        showToast('Sebagian file gagal terkirim -- lihat tanda di lampiran.', 'danger');
    }

    muatUlangDaftarConversation();

    btn.disabled = false;
    textarea.disabled = false;
    attachBtn.disabled = false;
    templateBtn.disabled = false;
    textarea.focus();

    return false;
}

// Enter (tanpa Shift) langsung kirim, Shift+Enter baris baru.
document.getElementById('teksBalasan').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        kirimBalasan(e);
    }
});

// Isi composer berubah = operasi berbeda (M1 Wave 2, TASK-019):
// kunci lama dibuang supaya kirim berikutnya memakai kunci baru.
// Mengubah .value lewat kode TIDAK memicu event ini, jadi pembersihan
// otomatis setelah kirim sukses tidak terganggu.
document.getElementById('teksBalasan').addEventListener('input', function() {
    buangOperationIdBalasan();
    sembunyikanStatusKirimBalasan();
    autoResizeTeksBalasan();
});

// Auto-resize composer (perbaikan UI): tinggi mengikuti isi teks,
// dibatasi max-height lewat CSS (#teksBalasan). Dipanggil saat user
// mengetik (event 'input' di atas) dan setiap kali .value direset
// lewat kode (lihat kirimBalasan()/kirimSemuaMediaBalasan()) --
// mengubah .value lewat kode tidak memicu event 'input' sendiri.
function autoResizeTeksBalasan() {
    const textarea = document.getElementById('teksBalasan');
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
}

// Teruskan (Tahap 4): bersihkan pilihan + pulihkan composer setiap dialog
// ditutup lewat cara apa pun (Batal, X, Esc, backdrop, atau setelah kirim
// sukses) -- bukan hanya tombol Batal.
document.getElementById('modalTeruskan').addEventListener('hidden.bs.modal', function() {
    tutupPemilihTeruskan();
});

// ================================================================
// STATUS GATEWAY
// ================================================================
const STATUS_GATEWAY_LABEL = {
    connected: {
        text: 'Terhubung',
        kelas: 'bg-success',
        icon: 'fa-check-circle'
    },
    connecting: {
        text: 'Menghubungkan...',
        kelas: 'bg-warning',
        icon: 'fa-circle-notch fa-spin'
    },
    reconnecting: {
        text: 'Menghubungkan...',
        kelas: 'bg-warning',
        icon: 'fa-circle-notch fa-spin'
    },
    disconnected: {
        text: 'Terputus',
        kelas: 'bg-secondary',
        icon: 'fa-times-circle'
    },
    logged_out: {
        text: 'Logout',
        kelas: 'bg-secondary',
        icon: 'fa-sign-out-alt'
    },
    // Pencegahan insiden 2026-09-29: status socket masih 'connected'
    // tapi Gateway mendeteksi sesi tidak benar-benar bisa memproses
    // pesan (lihat WA-Gateway connectionManager.js sessionHealth).
    // Warna beda dari 'connecting' (kuning) supaya kasir/admin tidak
    // mengira ini cuma sedang menyambung ulang -- ini butuh tindakan
    // manual (Logout + scan QR ulang), bukan sekadar menunggu.
    degraded: {
        text: 'Bermasalah, perlu scan ulang',
        kelas: 'bg-danger',
        icon: 'fa-exclamation-triangle'
    },
};

function renderStatusGateway(gateway) {
    const badge = document.getElementById('gatewayStatusBadge');
    const info = STATUS_GATEWAY_LABEL[gateway.effective_status] || STATUS_GATEWAY_LABEL.disconnected;

    badge.className = 'badge ' + info.kelas;
    badge.innerHTML = '<i class="fas ' + info.icon + '"></i> ' + info.text +
        (gateway.phone ? ' (' + escapeHtmlInbox(gateway.phone) + ')' : '');

    // Tahap F
    const sebelumnya = gatewayTerhubung;
    gatewayTerhubung = gateway.effective_status === 'connected';

    perbaruiUIGateway();

    // Baru saja RECONNECT (bukan pertama kali load) -- lupakan semua
    // kegagalan SEMENTARA supaya foto langsung dicoba lagi tanpa
    // menunggu jeda 30 detik (REQ-003). Kegagalan PERMANEN (410,
    // mediaGagal) SENGAJA tidak disentuh (CON-002); entri `nonRetryable`
    // ("terlalu besar") juga dikecualikan (SEC-1002).
    if (!sebelumnya && gatewayTerhubung) {
        bersihkanSementaraSetelahReconnect();
    }

    // Lalu muat ulang pesan supaya gambar yang tadinya diblokir otomatis
    // dicoba lagi tanpa kasir harus pindah-balik conversation manual.
    if (!sebelumnya && gatewayTerhubung && conversationAktif) {
        muatUlangPesan(false);
    }
}

function perbaruiUIGateway() {
    const btnKirim = document.getElementById('btnKirimBalasan');
    const btnChatBaru = document.getElementById('btnChatBaru');

    // btnKirimBalasan SUDAH punya logic disabled lain (belum pilih
    // conversation / teks kosong) -- JANGAN timpa logic itu, cuma
    // tambah 1 syarat lagi. Lihat kirimBalasan(e) di bawah untuk guard
    // yang sesungguhnya menahan submit -- disabled di sini murni sinyal
    // visual, bukan satu-satunya proteksi.
    if (btnKirim) {
        btnKirim.title = gatewayTerhubung ? '' : 'Gateway terputus -- belum bisa kirim pesan';
    }
    if (btnChatBaru) {
        btnChatBaru.disabled = !gatewayTerhubung;
        btnChatBaru.title = gatewayTerhubung ? '' : 'Gateway terputus -- belum bisa kirim pesan';
    }
    // teksBalasan SENGAJA TIDAK di-disable/dikosongkan -- kasir tetap
    // boleh ketik draft sambil Gateway terputus, supaya begitu
    // tersambung lagi tinggal klik Kirim tanpa ngetik ulang.
}

function muatUlangStatusGateway() {
    fetch(INBOX_URL.gatewayStatus)
        .then(function(res) {
            return res.json();
        })
        .then(function(json) {
            if (json.status === 'success') {
                renderStatusGateway(json.gateway);
            }
        })
        .catch(function() {
            // Diamkan, siklus polling berikutnya coba lagi.
        });
}

// ================================================================
// SPIKE REALTIME - Milestone 1
// ================================================================
// Tahap ini HANYA membuktikan koneksi WebSocket browser -> Gateway.
// Polling pesan/daftar tetap aktif sampai event message.created selesai
// dan diuji pada milestone berikutnya.
let realtimeSocket = null;
let realtimeReconnectTimer = null;
let realtimeConnecting = false;

// Milestone 4C: coalesce bursty realtime refreshes. Beberapa message.created
// yang datang berdekatan cukup memicu SATU refresh daftar (dan SATU refresh
// thread aktif). Bukan debounce panjang: event PERTAMA langsung menjadwalkan
// refresh (150 ms), jadi refresh mulai <= 250 ms sejak event pertama.
const REALTIME_COALESCE_MS = 150;
let realtimeRefreshTimer = null;
let realtimeRefreshList = false;
let realtimeRefreshThread = false;

function jalankanRealtimeRefresh() {
    realtimeRefreshTimer = null;

    const perluList = realtimeRefreshList;
    const perluThread = realtimeRefreshThread;
    realtimeRefreshList = false;
    realtimeRefreshThread = false;

    let listDitunda = false;
    if (perluList && putaranDaftarBerjalan) {
        // Putaran polling conversation sedang berjalan -- hormati guard
        // existing, jangan menumpuk request. Tandai masih perlu lalu
        // jadwalkan ulang supaya refresh tetap terjadi setelah selesai.
        realtimeRefreshList = true;
        listDitunda = true;
    }

    const lakukanList = perluList && !listDitunda;
    const lakukanThread = perluThread;
    if (lakukanList) muatUlangDaftarConversation();
    // Thread aktif tidak punya guard putaranDaftarBerjalan; refresh langsung.
    if (lakukanThread) muatUlangPesan(false);

    if (lakukanList || lakukanThread) {
        console.info('[Inbox realtime] coalesced refresh list=' + lakukanList + ' thread=' + lakukanThread);
    }

    if (realtimeRefreshList) jadwalkanRealtimeRefresh();
}

function jadwalkanRealtimeRefresh() {
    if (realtimeRefreshTimer !== null) return;
    realtimeRefreshTimer = setTimeout(jalankanRealtimeRefresh, REALTIME_COALESCE_MS);
}

function setRealtimeStatus(state) {
    const badge = document.getElementById('realtimeStatusBadge');
    if (!badge) return;

    const states = {
        connecting: ['bg-warning', '<i class="fas fa-circle-notch fa-spin"></i> Realtime: Menghubungkan...'],
        connected: ['bg-success', '<i class="fas fa-bolt"></i> Realtime: Terhubung'],
        disconnected: ['bg-secondary', '<i class="fas fa-plug"></i> Realtime: Terputus'],
        error: ['bg-danger', '<i class="fas fa-exclamation-circle"></i> Realtime: Error']
    };

    const item = states[state] || states.disconnected;
    badge.className = 'badge ' + item[0];
    badge.innerHTML = item[1];
}

function jadwalkanRealtimeReconnect() {
    if (realtimeReconnectTimer || realtimeSocket) return;

    realtimeReconnectTimer = setTimeout(function() {
        realtimeReconnectTimer = null;
        bukaRealtime();
    }, 5000);
}

function bukaRealtime() {
    if (realtimeConnecting || realtimeSocket) return;

    realtimeConnecting = true;
    setRealtimeStatus('connecting');

    fetch(INBOX_URL.realtimeTicket, {
        credentials: 'same-origin',
        cache: 'no-store'
    })
        .then(function(res) {
            if (!res.ok) throw new Error('ticket HTTP ' + res.status);
            return res.json();
        })
        .then(function(json) {
            if (json.status !== 'success' || !json.ticket || !json.ws_url) {
                throw new Error(json.message || 'Ticket realtime tidak valid');
            }

            const wsUrl = json.ws_url + '?ticket=' + encodeURIComponent(json.ticket);
            const socket = new WebSocket(wsUrl);
            realtimeSocket = socket;

            socket.addEventListener('open', function() {
                realtimeConnecting = false;
                setRealtimeStatus('connected');
                console.info('[Inbox realtime] WebSocket connected');
            });

            socket.addEventListener('message', function(event) {
                try {
                    const payload = JSON.parse(event.data);

                    if (payload.type === 'connection.ready') {
                        console.info('[Inbox realtime] connection.ready', payload);
                        return;
                    }

                    if (payload.type === 'message.created') {
                        console.info('[Inbox realtime] message.created', payload);

                        // WebSocket hanya membawa notifikasi kecil. Data pesan
                        // tetap diambil dari CI4/DB sebagai source of truth.
                        // Beberapa event berdekatan (M4C) di-coalesce menjadi
                        // satu refresh daftar + satu refresh thread aktif.
                        realtimeRefreshList = true;

                        if (
                            conversationAktif &&
                            Number(payload.conversation_id) === Number(conversationAktif)
                        ) {
                            realtimeRefreshThread = true;
                        }

                        jadwalkanRealtimeRefresh();
                    }
                } catch (_) {
                    console.warn('[Inbox realtime] payload bukan JSON');
                }
            });

            socket.addEventListener('error', function() {
                setRealtimeStatus('error');
                console.warn('[Inbox realtime] WebSocket error');
            });

            socket.addEventListener('close', function() {
                if (realtimeSocket === socket) realtimeSocket = null;
                realtimeConnecting = false;
                setRealtimeStatus('disconnected');
                console.warn('[Inbox realtime] WebSocket disconnected; retry 5 detik');
                jadwalkanRealtimeReconnect();
            });
        })
        .catch(function(error) {
            realtimeConnecting = false;
            setRealtimeStatus('error');
            console.warn('[Inbox realtime] gagal membuka koneksi:', error.message);
            jadwalkanRealtimeReconnect();
        });
}

bukaRealtime();

// ================================================================
// POLLING SEDERHANA (bukan WebSocket, sesuai spec)
// ================================================================
renderStatusGateway(INBOX_PAGE_CONFIG.gatewayStatus);
// Render ulang daftar sekali di awal (walau PHP sudah render first-
// paint) -- supaya badge assignment ("Dipegang: .../Belum diambil",
// lihat Tahap 2) & filter langsung konsisten tanpa menunggu polling
// pertama (6 detik). Satu sumber logic render (JS), tidak
// menduplikasi template di PHP.
renderDaftarConversation();

// Deep-link dari toast notifikasi lintas halaman (main.php): buka
// percakapan tertentu langsung saat window Inbox dibuka/difokuskan
// lewat `?conversation_id=`. `daftarConversation` di atas memuat
// SEMUA conversation (lihat Inbox::index()), jadi target selalu ada
// tanpa perlu menunggu polling.
(function bukaConversationDariUrl() {
    const params = new URLSearchParams(window.location.search);
    const id = parseInt(params.get('conversation_id'), 10);
    if (id > 0) pilihConversation(id);
})();

// Milestone 4A: adaptive polling. Timer tetap pada cadence LAMA (daftar
// 6 detik, pesan 4 detik), tetapi tick DILEWATI selama WebSocket
// benar-benar OPEN sampai jendela reconciliation (daftar 30 detik, pesan
// 12 detik) tercapai. Begitu realtime tidak OPEN (closed/error/masih
// connecting), tick berikutnya langsung kembali ke cadence lama. TIDAK
// ada timer dinamis yang saling menjadwalkan -- hanya satu timer per
// resource, jadi tidak mungkin request ganda.
//
// `waktu...Terakhir` menyimpan waktu REQUEST terakhir (bukan tick).
// Inilah yang mencegah pergantian mode memicu dua request identik
// beruntun, sekaligus membatasi reconciliation ke jendela yang benar.
const JEDA_DAFTAR_CEPAT_MS = 6000;
const JEDA_PESAN_CEPAT_MS = 4000;
const JEDA_DAFTAR_REALTIME_MS = 30000;
const JEDA_PESAN_REALTIME_MS = 12000;

// Toleransi kecil: tick bisa mendarat 1-2 ms LEBIH AWAL dari jendela
// (coalescing timer), dan tanpa toleransi itu membuat satu tick terlewat
// sehingga interval jadi 2x (4s->8s saat cepat, 12s->16s saat realtime).
// 100 ms << interval dasar, jadi tidak pernah memicu request lebih awal
// secara berarti.
const TOLERANSI_TICK_MS = 100;

// Jangan anggap `realtimeSocket !== null` sebagai connected: objek bisa
// sudah ada sebelum handshake selesai. Hanya OPEN yang dihitung sehat.
function realtimeTerhubung() {
    return realtimeSocket !== null && realtimeSocket.readyState === WebSocket.OPEN;
}

let waktuDaftarTerakhir = 0;
let waktuPesanTerakhir = 0;

// REQ-017b: tick 6 detik tidak menumpuk putaran kedua. Kalau putaran
// sebelumnya masih jalan, tick ini dilewati -- putaran berikutnya
// tetap datang 6 detik kemudian.
setInterval(function() {
    if (putaranDaftarBerjalan) return;
    const jeda = realtimeTerhubung() ? JEDA_DAFTAR_REALTIME_MS : JEDA_DAFTAR_CEPAT_MS;
    if (Date.now() - waktuDaftarTerakhir < jeda - TOLERANSI_TICK_MS) return;
    waktuDaftarTerakhir = Date.now();
    muatUlangDaftarConversation();
}, JEDA_DAFTAR_CEPAT_MS);
setInterval(function() {
    const jeda = realtimeTerhubung() ? JEDA_PESAN_REALTIME_MS : JEDA_PESAN_CEPAT_MS;
    if (Date.now() - waktuPesanTerakhir < jeda - TOLERANSI_TICK_MS) return;
    waktuPesanTerakhir = Date.now();
    muatUlangPesan(false);
}, JEDA_PESAN_CEPAT_MS);
setInterval(muatUlangStatusGateway, 15000);
