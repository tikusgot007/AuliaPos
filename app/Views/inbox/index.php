<style>
    /* ========================================================== */
    /* LAYOUT INBOX - 2 KOLOM (daftar chat | riwayat pesan)        */
    /* ========================================================== */
    .inbox-wrapper {
        display: flex;
        height: calc(100vh - 220px);
        min-height: 480px;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
    }

    .inbox-list-col {
        width: 320px;
        min-width: 260px;
        border-right: 1px solid #dee2e6;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .inbox-list-filter .btn.active {
        background: #0d6efd;
        color: #fff;
        border-color: #0d6efd;
    }

    .inbox-list-panel {
        flex: 1;
        overflow-y: auto;
        background: #f8f9fa;
        min-height: 0;
    }

    .inbox-list-item {
        display: block;
        padding: 12px 14px;
        border-bottom: 1px solid #e9ecef;
        border-left: 3px solid transparent;
        cursor: pointer;
        text-decoration: none;
        color: inherit;
    }

    .inbox-list-item:hover {
        background: #eef2f7;
    }

    .inbox-list-item.active {
        background: #d7e6fb;
        border-left-color: #0d6efd;
    }

    .inbox-list-item .list-name {
        font-weight: 600;
        font-size: 0.9rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .inbox-list-item .list-preview {
        font-size: 0.78rem;
        color: #6c757d;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .inbox-list-item .list-time {
        font-size: 0.7rem;
        color: #9aa0a6;
    }

    .inbox-thread-panel {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .inbox-thread-header {
        padding: 10px 16px;
        border-bottom: 1px solid #dee2e6;
        background: #fff;
    }

    .inbox-thread-messages {
        flex: 1;
        overflow-y: auto;
        padding: 16px;
        background: #e5ddd5;
        /* nuansa hijau muda WA */
    }

    .inbox-thread-empty {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #6c757d;
    }

    .inbox-bubble {
        max-width: 70%;
        padding: 8px 12px;
        border-radius: 10px;
        margin-bottom: 8px;
        font-size: 0.88rem;
        word-wrap: break-word;
        white-space: pre-wrap;
    }

    .inbox-bubble.incoming {
        background: #fff;
        margin-right: auto;
        border-top-left-radius: 2px;
    }

    .inbox-bubble.outgoing {
        background: #d9fdd3;
        margin-left: auto;
        border-top-right-radius: 2px;
    }

    .inbox-bubble .bubble-meta {
        font-size: 0.68rem;
        color: #8a8a8a;
        margin-top: 2px;
        text-align: right;
    }

    .inbox-bubble .bubble-sender {
        font-size: 0.7rem;
        font-weight: 600;
        color: #1da47f;
        margin-bottom: 2px;
    }

    .inbox-media-image {
        max-width: 100%;
        max-height: 300px;
        border-radius: 6px;
        display: block;
        cursor: pointer;
    }

    .inbox-media-document {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 10px;
        background: rgba(0, 0, 0, 0.04);
        border-radius: 6px;
        text-decoration: none;
        color: inherit;
        font-size: 0.85rem;
        word-break: break-all;
    }

    .inbox-media-document:hover {
        background: rgba(0, 0, 0, 0.08);
    }

    .inbox-media-unavailable {
        padding: 10px;
        background: rgba(0, 0, 0, 0.04);
        border-radius: 6px;
        color: #999;
        font-size: 0.8rem;
        font-style: italic;
    }

    .inbox-media-caption {
        margin-top: 4px;
        font-size: 0.85rem;
    }

    .inbox-thread-form {
        padding: 10px 16px;
        border-top: 1px solid #dee2e6;
        background: #fff;
    }

    #gatewayStatusBadge.bg-success { background-color: #198754 !important; }
    #gatewayStatusBadge.bg-warning { background-color: #ffc107 !important; color: #212529 !important; }
    #gatewayStatusBadge.bg-secondary { background-color: #6c757d !important; }
</style>

<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fab fa-whatsapp"></i> Inbox WhatsApp</h5>
        <span class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalChatBaru">
                <i class="fas fa-plus"></i> Chat Baru
            </button>
            <span id="gatewayStatusBadge" class="badge bg-secondary">
                <i class="fas fa-circle-notch fa-spin"></i> Memeriksa...
            </span>
        </span>
    </div>
    <div class="card-body p-2">
        <div class="inbox-wrapper">
            <!-- ============================================ -->
            <!-- PANEL KIRI: DAFTAR CONVERSATION               -->
            <!-- ============================================ -->
            <div class="inbox-list-col">
            <div class="inbox-list-filter d-flex gap-1 p-2 border-bottom" style="background:#fff;">
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterSemua" onclick="setFilterConversation('semua')">Semua</button>
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterOpen" onclick="setFilterConversation('open')">Open</button>
                <button type="button" class="btn btn-sm btn-outline-secondary flex-fill" id="btnFilterClosed" onclick="setFilterConversation('closed')">Closed</button>
            </div>
            <div class="inbox-list-panel" id="inboxListPanel">
                <?php if (empty($conversations)): ?>
                    <div class="p-3 text-muted small text-center">
                        Belum ada percakapan masuk.
                    </div>
                <?php endif; ?>
                <?php foreach ($conversations as $c): ?>
                    <a href="#" class="inbox-list-item" data-conversation-id="<?= esc($c['id']) ?>" onclick="return pilihConversation(<?= (int) $c['id'] ?>)">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="list-name"><?= esc($c['contact_name'] ?: $c['whatsapp_name'] ?: $c['phone'] ?: $c['chat_id']) ?></span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="list-time"><?= $c['last_message_at'] ? date('d/m H:i', strtotime($c['last_message_at'])) : '' ?></span>
                                <button type="button" class="btn btn-sm btn-link p-0 text-muted" style="font-size:0.75rem;" title="Edit profil pelanggan" onclick="event.stopPropagation(); editPercakapanDariList(<?= (int) $c['id'] ?>)"><i class="fas fa-pen"></i></button>
                                <button type="button" class="btn btn-sm btn-link p-0 text-danger" style="font-size:0.75rem;" title="Hapus percakapan" onclick="event.stopPropagation(); hapusPercakapanDariList(<?= (int) $c['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                            </span>
                        </div>
                        <div class="list-preview">
                            <?= $c['last_message_direction'] === 'outgoing' ? '<i class="fas fa-reply fa-xs"></i> ' : '' ?>
                            <?= esc($c['manual_phone'] ?: $c['phone'] ?: ($c['jid_type'] === 'lid' ? 'LID' : $c['jid_type'])) ?>
                            <?php if ($c['status'] === 'closed'): ?>
                                <span class="badge bg-secondary" style="font-size: 0.6rem;">closed</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            </div>

            <!-- ============================================ -->
            <!-- PANEL KANAN: RIWAYAT PESAN + FORM KIRIM        -->
            <!-- ============================================ -->
            <div class="inbox-thread-panel">
                <div class="inbox-thread-header d-flex justify-content-between align-items-center" id="threadHeader">
                    <span class="text-muted">Pilih percakapan di sebelah kiri untuk mulai.</span>
                </div>

                <div class="inbox-thread-messages" id="threadMessages">
                    <div class="inbox-thread-empty">
                        <i class="fas fa-comments fa-2x me-2"></i> Belum ada percakapan dipilih.
                    </div>
                </div>

                <div class="inbox-thread-form">
                    <div id="previewMediaBalasan" class="mb-2" style="display:none;">
                        <span class="badge bg-light text-dark border">
                            <i class="fas fa-paperclip"></i> <span id="previewMediaNama"></span>
                            <button type="button" class="btn-close btn-sm ms-1" style="font-size:0.6rem;" onclick="batalkanMediaBalasan()"></button>
                        </span>
                    </div>
                    <form id="formBalas" onsubmit="return kirimBalasan(event)">
                        <div class="input-group">
                            <button class="btn btn-outline-secondary" type="button" id="btnLampirkanMedia" disabled onclick="document.getElementById('inputMediaBalasan').click()">
                                <i class="fas fa-paperclip"></i>
                            </button>
                            <input type="file" id="inputMediaBalasan" style="display:none;" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx" onchange="pilihMediaBalasan(event)">
                            <textarea class="form-control" id="teksBalasan" rows="1" placeholder="Pilih percakapan dulu..." disabled></textarea>
                            <button class="btn btn-success" type="submit" id="btnKirimBalasan" disabled>
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL CHAT BARU (kirim ke nomor yang belum pernah masuk) -->
<!-- ============================================ -->
<div class="modal fade" id="modalChatBaru" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Chat Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formChatBaru" onsubmit="return mulaiChatBaru(event)">
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle"></i>
                        Dipakai untuk mulai chat ke nomor yang <strong>belum pernah</strong> mengirim
                        pesan ke toko. Kalau nomor ini sudah pernah chat sebelumnya, pesan akan
                        otomatis masuk ke percakapan yang sudah ada.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nomor WhatsApp Customer</label>
                        <input type="text" class="form-control" id="nomorChatBaru" placeholder="Contoh: 08123456789" required>
                        <small class="text-muted">Format 08xx, 62xx, atau +62xx.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pesan Pertama</label>
                        <textarea class="form-control" id="teksChatBaru" rows="3" required placeholder="Ketik pesan pembuka..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnChatBaru">
                        <i class="fas fa-paper-plane"></i> Kirim & Mulai Chat
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL KONFIRMASI HAPUS PERCAKAPAN              -->
<!-- ============================================ -->
<div class="modal fade" id="modalHapusPercakapan" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="fas fa-trash-alt"></i> Hapus Percakapan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger small mb-0">
                    <i class="fas fa-exclamation-triangle"></i>
                    Percakapan dengan <strong id="namaHapusPercakapan"></strong> beserta
                    <strong>SEMUA riwayat pesannya</strong> akan dihapus permanen dan
                    <strong>tidak bisa dikembalikan</strong>. Yakin lanjutkan?
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="btnKonfirmasiHapusPercakapan" onclick="konfirmasiHapusPercakapan()">
                    <i class="fas fa-trash-alt"></i> Ya, Hapus Permanen
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL EDIT PROFIL PELANGGAN (Task Group 1.5)   -->
<!-- ============================================ -->
<div class="modal fade" id="modalEditProfil" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit Profil Pelanggan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEditProfil" onsubmit="return simpanProfilPelanggan(event)">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama Pelanggan</label>
                        <input type="text" class="form-control" id="editProfilNama" placeholder="Kosongkan untuk hapus nama manual">
                        <small class="text-muted">Nama ini TIDAK akan ditimpa otomatis oleh nama WhatsApp customer.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">No. Telepon</label>
                        <input type="text" class="form-control" id="editProfilTelepon" placeholder="Contoh: 08123456789">
                        <small class="text-muted">Format 08xx, 62xx, atau +62xx. Murni catatan -- bukan nomor WhatsApp yang terverifikasi.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary" id="btnSimpanProfil">
                        <i class="fas fa-save"></i> Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL KONFIRMASI NOMOR WHATSAPP (Task Group 1.5 -->
<!-- revisi LID-FIRST -> PN-LATER)                  -->
<!-- ============================================ -->
<div class="modal fade" id="modalKonfirmasiNomor" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Konfirmasi Nomor WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formKonfirmasiNomor" onsubmit="return simpanKonfirmasiNomor(event)">
                <div class="modal-body">
                    <div class="alert alert-warning small">
                        <i class="fas fa-exclamation-triangle"></i>
                        Percakapan ini belum punya nomor WhatsApp yang terverifikasi
                        (identitas teknisnya <code>@lid</code>, WhatsApp tidak
                        membocorkan nomornya ke Gateway). Isi nomor ini HANYA kalau
                        Anda BENAR-BENAR YAKIN ini nomor WhatsApp customer yang sama
                        -- pesan berikutnya dari nomor ini akan otomatis masuk ke
                        percakapan ini.
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nomor WhatsApp yang Dikonfirmasi</label>
                        <input type="text" class="form-control" id="konfirmasiNomorInput" placeholder="Contoh: 08123456789" required>
                        <small class="text-muted">Format 08xx, 62xx, atau +62xx.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning" id="btnKonfirmasiNomor">
                        <i class="fas fa-shield-alt"></i> Ya, Konfirmasi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // ================================================================
    // STATE
    // ================================================================
    let conversationAktif = null;
    let conversationUntukHapus = null; // target hapus dari row daftar kiri, terpisah dari conversationAktif
    let daftarConversation = <?= json_encode($conversations) ?>;
    // Tahap 1 lifecycle status (docs/aturan-bisnis-CHAT.md Section 12):
    // filter tampilan daftar percakapan SAJA (client-side) -- tidak ada
    // endpoint/query baru, data lengkap tetap dimuat seperti sebelumnya.
    let filterAktif = 'semua'; // 'semua' | 'open' | 'closed'
    const currentUserId = <?= (int) $currentUserId ?>;
    const currentUserRole = <?= json_encode($currentUserRole) ?>;

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
        return d.toLocaleString('id-ID', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    // Cari conversation berdasarkan id. Perbandingan via String() SENGAJA
    // -- kolom `id` dari CodeIgniter/MySQLi kadang berupa string ("45"),
    // sedangkan id di JS (dari onclick, parameter fetch, dst) berupa
    // number -- "45" === 45 selalu false, bikin conv "tidak ketemu" tanpa
    // error apa pun (root cause pernah bikin modal edit/header/highlight
    // aktif semuanya diam-diam kosong). Satu fungsi ini dipakai di semua
    // pemanggil supaya perbaikannya tidak perlu diulang di tiap tempat.
    function cariConversation(id) {
        return daftarConversation.find(function(c) { return String(c.id) === String(id); });
    }

    // ================================================================
    // DAFTAR CONVERSATION
    // ================================================================
    // Tombol filter Semua/Open/Closed -- state visual saja, tidak
    // mengubah conversationAktif/daftarConversation itu sendiri.
    function renderFilterButtons() {
        ['semua', 'open', 'closed'].forEach(function(f) {
            const btn = document.getElementById('btnFilter' + f.charAt(0).toUpperCase() + f.slice(1));
            if (btn) btn.classList.toggle('active', filterAktif === f);
        });
    }

    function setFilterConversation(filter) {
        filterAktif = filter;
        renderFilterButtons();
        renderDaftarConversation();
    }

    function renderDaftarConversation() {
        renderFilterButtons();

        const panel = document.getElementById('inboxListPanel');
        const daftarTampil = filterAktif === 'semua'
            ? daftarConversation
            : daftarConversation.filter(function(c) { return c.status === filterAktif; });

        if (!daftarTampil.length) {
            panel.innerHTML = '<div class="p-3 text-muted small text-center">' +
                (daftarConversation.length ? 'Tidak ada percakapan ' + filterAktif + '.' : 'Belum ada percakapan masuk.') +
                '</div>';
            return;
        }

        panel.innerHTML = daftarTampil.map(function(c) {
            const activeClass = (String(c.id) === String(conversationAktif)) ? ' active' : '';
            const nama = c.contact_name || c.whatsapp_name || c.phone || c.chat_id;
            const waktu = c.last_message_at ? formatWaktuInbox(c.last_message_at) : '';
            const panah = c.last_message_direction === 'outgoing' ? '<i class="fas fa-reply fa-xs"></i> ' : '';
            const closedBadge = c.status === 'closed' ? '<span class="badge bg-secondary" style="font-size:0.6rem;">closed</span>' : '';
            // String() SENGAJA -- assigned_to dari MySQLi/JSON kadang
            // string ("3"), currentUserId number -- lihat catatan
            // cariConversation() di atas untuk root cause bug yang sama.
            const assignBadge = c.assigned_to
                ? '<span class="badge ' + (String(c.assigned_to) === String(currentUserId) ? 'bg-info' : 'bg-light text-dark border') + '" style="font-size:0.6rem;">' +
                  '<i class="fas fa-user"></i> Dipegang: ' + escapeHtmlInbox(c.assigned_to_name || ('User #' + c.assigned_to)) + '</span>'
                : '<span class="badge bg-light text-muted border" style="font-size:0.6rem;">Belum diambil</span>';

            const nomorAtauLid = c.manual_phone || c.phone || (c.jid_type === 'lid' ? 'LID' : c.jid_type);

            return '<a href="#" class="inbox-list-item' + activeClass + '" onclick="return pilihConversation(' + c.id + ')">' +
                '<div class="d-flex justify-content-between align-items-start">' +
                '<span class="list-name">' + escapeHtmlInbox(nama) + '</span>' +
                '<span class="d-flex align-items-center gap-1">' +
                '<span class="list-time">' + escapeHtmlInbox(waktu) + '</span>' +
                '<button type="button" class="btn btn-sm btn-link p-0 text-muted" style="font-size:0.75rem;" title="Edit profil pelanggan" onclick="event.stopPropagation(); editPercakapanDariList(' + c.id + ')"><i class="fas fa-pen"></i></button>' +
                '<button type="button" class="btn btn-sm btn-link p-0 text-danger" style="font-size:0.75rem;" title="Hapus percakapan" onclick="event.stopPropagation(); hapusPercakapanDariList(' + c.id + ')"><i class="fas fa-trash-alt"></i></button>' +
                '</span>' +
                '</div>' +
                '<div class="list-preview">' + panah + escapeHtmlInbox(nomorAtauLid) + ' ' + closedBadge + ' ' + assignBadge + '</div>' +
                '</a>';
        }).join('');
    }

    function muatUlangDaftarConversation() {
        fetch('<?= base_url('/inbox/api/conversations') ?>')
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    daftarConversation = json.conversations;
                    renderDaftarConversation();
                    renderThreadHeader();
                }
            })
            .catch(function() {
                // Diamkan -- polling berikutnya akan coba lagi. Tidak
                // perlu toast tiap gagal 1 siklus, cukup mengganggu.
            });
    }

    // ================================================================
    // IDENTITAS CUSTOMER: "Nama (No. Telepon)" / "Nama (LID)" -- TIDAK
    // PERNAH menampilkan conversation_id ("#45") sebagai identitas
    // utama, dan TIDAK PERNAH memperlakukan digit @lid sebagai nomor
    // telepon (lihat business rule reconciliation LID<->PN).
    // ================================================================
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

    // ================================================================
    // HEADER THREAD (identitas customer + badge/tombol assignment)
    // ================================================================
    function renderThreadHeader() {
        if (!conversationAktif) return;

        const conv = cariConversation(conversationAktif);
        const identitas = formatIdentitasCustomer(conv);

        let infoAssign = '';
        let tombolAssign = '';

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
            }
        } else {
            infoAssign = ' <span class="badge bg-light text-muted border">Belum diambil</span>';
            tombolAssign = '<button type="button" class="btn btn-sm btn-outline-primary me-1" title="Ambil percakapan" onclick="ambilPercakapan()">' +
                '<i class="fas fa-user-plus"></i> Ambil</button>';
        }

        // Revisi LID-FIRST -> PN-LATER: tombol konfirmasi nomor manual
        // HANYA relevan kalau conversation ini @lid DAN belum punya
        // `phone` ter-verifikasi (kalau sudah ada, reconciliation
        // otomatis/sebelumnya sudah menanganinya).
        const tombolKonfirmasiNomor = (conv && conv.jid_type === 'lid' && !conv.phone)
            ? ' <button type="button" class="btn btn-sm btn-link p-0 text-warning" style="font-size:0.75rem;" title="Konfirmasi nomor WhatsApp customer ini" onclick="bukaModalKonfirmasiNomor()">' +
              '<i class="fas fa-shield-alt"></i> Konfirmasi Nomor</button>'
            : '';

        // Tahap 1 lifecycle status (Section 12): tombol "Tutup" HANYA
        // muncul kalau conversation sedang OPEN -- tidak ada tombol
        // "Open" manual (reopen cuma lewat pesan masuk baru, lihat
        // InboxGatewayApi::messages()).
        const badgeStatus = conv
            ? ' <span class="badge ' + (conv.status === 'closed' ? 'bg-secondary' : 'bg-success') + '">' + conv.status.toUpperCase() + '</span>'
            : '';
        const tombolTutup = (conv && conv.status === 'open')
            ? '<button type="button" class="btn btn-sm btn-outline-danger me-1" title="Tutup percakapan" onclick="tutupPercakapan()">' +
              '<i class="fas fa-times-circle"></i> Tutup</button>'
            : '';

        // Edit & Hapus TIDAK lagi tampil di header -- dipindah ke masing-
        // masing row percakapan di daftar kiri (lihat editPercakapanDariList()/
        // hapusPercakapanDariList()).
        document.getElementById('threadHeader').innerHTML =
            '<span><strong>' + escapeHtmlInbox(identitas) + '</strong>' +
            badgeStatus +
            tombolKonfirmasiNomor +
            infoAssign +
            '</span>' +
            '<span>' + tombolTutup + tombolAssign + '</span>';
    }

    function tutupPercakapan() {
        if (!conversationAktif) return;

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/tutup', { method: 'POST' })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    showToast('Percakapan ditutup.', 'success');
                    const idx = daftarConversation.findIndex(function(c) { return String(c.id) === String(conversationAktif); });
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

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/ambil', { method: 'POST' })
            .then(function(res) { return res.json(); })
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

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/lepas', { method: 'POST' })
            .then(function(res) { return res.json(); })
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
    // PILIH CONVERSATION & RIWAYAT PESAN
    // ================================================================
    function pilihConversation(id) {
        conversationAktif = id;
        renderDaftarConversation();
        renderThreadHeader();

        document.getElementById('teksBalasan').disabled = false;
        document.getElementById('teksBalasan').placeholder = 'Ketik balasan...';
        document.getElementById('btnKirimBalasan').disabled = false;
        document.getElementById('btnLampirkanMedia').disabled = false;
        document.getElementById('teksBalasan').focus();

        muatUlangPesan(true);
        return false;
    }

    function renderIsiPesan(m) {
        const urlMedia = '<?= base_url('/inbox/media/') ?>' + m.id;

        if (m.message_type === 'image') {
            // onerror: media bisa saja sudah kadaluarsa di server WhatsApp
            // (lihat catatan desain -- kita cuma simpan referensi, bukan
            // file permanen) -- tampilkan placeholder yang jelas, bukan
            // ikon "broken image" generik browser.
            return '<img src="' + urlMedia + '" alt="Gambar" class="inbox-media-image" ' +
                'onerror="this.outerHTML=\'<div class=&quot;inbox-media-unavailable&quot;><i class=&quot;fas fa-image&quot;></i> Gambar tidak tersedia (kemungkinan sudah kadaluarsa)</div>\'">' +
                (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
        }

        if (m.message_type === 'document') {
            const namaFile = m.media_filename || 'Dokumen';
            return '<a href="' + urlMedia + '" target="_blank" class="inbox-media-document">' +
                '<i class="fas fa-file-alt"></i> ' + escapeHtmlInbox(namaFile) +
                '</a>' +
                (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
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
                (m.text ? '<div class="inbox-media-caption">' + escapeHtmlInbox(m.text) + '</div>' : '');
        }

        return escapeHtmlInbox(m.text);
    }

    function renderPesan(messages) {
        const container = document.getElementById('threadMessages');

        if (!messages.length) {
            container.innerHTML = '<div class="inbox-thread-empty"><i class="fas fa-comment-dots fa-2x me-2"></i> Belum ada pesan di percakapan ini.</div>';
            return;
        }

        container.innerHTML = messages.map(function(m) {
            const arah = m.direction === 'outgoing' ? 'outgoing' : 'incoming';
            const senderLabel = (m.direction === 'outgoing' && m.sender_name)
                ? '<div class="bubble-sender">' + escapeHtmlInbox(m.sender_name) + '</div>'
                : '';

            return '<div class="inbox-bubble ' + arah + '">' +
                senderLabel +
                renderIsiPesan(m) +
                '<div class="bubble-meta">' + formatWaktuInbox(m.message_timestamp) + '</div>' +
                '</div>';
        }).join('');

        container.scrollTop = container.scrollHeight;
    }

    function muatUlangPesan(scrollPaksa) {
        if (!conversationAktif) return;

        fetch('<?= base_url('/inbox/api/conversations') ?>/' + conversationAktif + '/messages')
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    renderPesan(json.messages);
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

        const nomor = document.getElementById('nomorChatBaru').value.trim();
        const text = document.getElementById('teksChatBaru').value.trim();
        const btn = document.getElementById('btnChatBaru');

        if (!nomor || !text) {
            showToast('Nomor dan pesan wajib diisi.', 'warning');
            return false;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim...';

        fetch('<?= base_url('/inbox/mulai-percakapan') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'phone=' + encodeURIComponent(nomor) + '&text=' + encodeURIComponent(text)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    showToast('Chat berhasil dimulai!', 'success');

                    // Tutup modal, reset form.
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChatBaru')).hide();
                    document.getElementById('formChatBaru').reset();

                    // Muat ulang daftar conversation, lalu langsung buka
                    // conversation yang baru dibuat/dipakai.
                    fetch('<?= base_url('/inbox/api/conversations') ?>')
                        .then(function(r) { return r.json(); })
                        .then(function(listJson) {
                            if (listJson.status === 'success') {
                                daftarConversation = listJson.conversations;
                                renderDaftarConversation();
                                pilihConversation(json.conversation_id);
                            }
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
    // LAMPIRAN MEDIA (dipilih, siap dikirim bareng pesan berikutnya)
    // ================================================================
    let fileMediaBalasan = null;

    function pilihMediaBalasan(e) {
        const file = e.target.files && e.target.files[0];
        if (!file) return;

        fileMediaBalasan = file;
        document.getElementById('previewMediaNama').textContent = file.name;
        document.getElementById('previewMediaBalasan').style.display = 'block';
        document.getElementById('teksBalasan').placeholder = 'Caption (opsional)...';
    }

    function batalkanMediaBalasan() {
        fileMediaBalasan = null;
        document.getElementById('inputMediaBalasan').value = '';
        document.getElementById('previewMediaBalasan').style.display = 'none';
        document.getElementById('teksBalasan').placeholder = 'Ketik balasan...';
    }

    function tampilkanBubbleOutgoing(m) {
        const container = document.getElementById('threadMessages');
        const emptyState = container.querySelector('.inbox-thread-empty');
        if (emptyState) container.innerHTML = '';

        container.insertAdjacentHTML('beforeend',
            '<div class="inbox-bubble outgoing">' +
            '<div class="bubble-sender">' + escapeHtmlInbox(m.sender_name) + '</div>' +
            renderIsiPesan(m) +
            '<div class="bubble-meta">' + formatWaktuInbox(m.message_timestamp) + '</div>' +
            '</div>');
        container.scrollTop = container.scrollHeight;
    }

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

        fetch('<?= base_url('/inbox/percakapan/') ?>' + idDihapus + '/hapus', { method: 'POST' })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalHapusPercakapan')).hide();
                    showToast('Percakapan berhasil dihapus.', 'success');

                    daftarConversation = daftarConversation.filter(function(c) { return String(c.id) !== String(idDihapus); });
                    renderDaftarConversation();

                    // Panel kanan HANYA direset kalau yang dihapus memang
                    // conversation yang sedang aktif/terbuka -- kalau kasir
                    // menghapus row lain, percakapan yang sedang dibuka
                    // tetap tampil apa adanya.
                    if (String(idDihapus) === String(conversationAktif)) {
                        conversationAktif = null;
                        document.getElementById('threadHeader').innerHTML = '<span class="text-muted">Pilih percakapan di sebelah kiri untuk mulai.</span>';
                        document.getElementById('threadMessages').innerHTML = '<div class="inbox-thread-empty"><i class="fas fa-comments fa-2x me-2"></i> Belum ada percakapan dipilih.</div>';
                        document.getElementById('teksBalasan').disabled = true;
                        document.getElementById('teksBalasan').placeholder = 'Pilih percakapan dulu...';
                        document.getElementById('btnKirimBalasan').disabled = true;
                        document.getElementById('btnLampirkanMedia').disabled = true;
                        batalkanMediaBalasan();
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
        const conv = cariConversation(conversationAktif);
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

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/profil', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'customer_name=' + encodeURIComponent(nama) + '&phone=' + encodeURIComponent(telepon)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditProfil')).hide();
                    showToast('Profil pelanggan disimpan.', 'success');

                    // Perbarui entri lokal langsung, tidak perlu menunggu polling.
                    const idx = daftarConversation.findIndex(function(c) { return String(c.id) === String(conversationAktif); });
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

        fetch('<?= base_url('/inbox/percakapan/') ?>' + conversationAktif + '/konfirmasi-nomor', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'phone=' + encodeURIComponent(nomor)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKonfirmasiNomor')).hide();
                    showToast('Nomor WhatsApp dikonfirmasi. Pesan berikutnya dari nomor ini akan otomatis masuk ke sini.', 'success');

                    const idx = daftarConversation.findIndex(function(c) { return String(c.id) === String(conversationAktif); });
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
    // KIRIM BALASAN (teks, atau media kalau ada lampiran dipilih)
    // ================================================================
    function kirimBalasan(e) {
        e.preventDefault();

        if (!conversationAktif) {
            showToast('Pilih percakapan dulu.', 'warning');
            return false;
        }

        if (fileMediaBalasan) {
            return kirimMediaBalasan();
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

        fetch('<?= base_url('/inbox/kirim') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'conversation_id=' + encodeURIComponent(conversationAktif) + '&text=' + encodeURIComponent(text)
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    textarea.value = '';
                    // Langsung tampilkan pesan baru tanpa nunggu polling
                    // (sesuai spec: outgoing langsung terlihat setelah sukses).
                    tampilkanBubbleOutgoing(json.message);
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal mengirim pesan.', 'danger');
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

    function kirimMediaBalasan() {
        const textarea = document.getElementById('teksBalasan');
        const btn = document.getElementById('btnKirimBalasan');
        const caption = textarea.value.trim();
        const file = fileMediaBalasan;

        btn.disabled = true;
        textarea.disabled = true;
        document.getElementById('btnLampirkanMedia').disabled = true;

        const formData = new FormData();
        formData.append('conversation_id', conversationAktif);
        formData.append('caption', caption);
        formData.append('media', file);

        fetch('<?= base_url('/inbox/kirim-media') ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(json) {
                if (json.status === 'success') {
                    textarea.value = '';
                    batalkanMediaBalasan();
                    tampilkanBubbleOutgoing(json.message);
                    muatUlangDaftarConversation();
                } else {
                    showToast(json.message || 'Gagal mengirim media.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                textarea.disabled = false;
                document.getElementById('btnLampirkanMedia').disabled = false;
                textarea.focus();
            });

        return false;
    }

    // Enter (tanpa Shift) langsung kirim, Shift+Enter baris baru.
    document.getElementById('teksBalasan').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            kirimBalasan(e);
        }
    });

    // ================================================================
    // STATUS GATEWAY
    // ================================================================
    const STATUS_GATEWAY_LABEL = {
        connected: { text: 'Terhubung', kelas: 'bg-success', icon: 'fa-check-circle' },
        connecting: { text: 'Menghubungkan...', kelas: 'bg-warning', icon: 'fa-circle-notch fa-spin' },
        reconnecting: { text: 'Menghubungkan...', kelas: 'bg-warning', icon: 'fa-circle-notch fa-spin' },
        disconnected: { text: 'Terputus', kelas: 'bg-secondary', icon: 'fa-times-circle' },
        logged_out: { text: 'Logout', kelas: 'bg-secondary', icon: 'fa-sign-out-alt' },
    };

    function renderStatusGateway(gateway) {
        const badge = document.getElementById('gatewayStatusBadge');
        const info = STATUS_GATEWAY_LABEL[gateway.effective_status] || STATUS_GATEWAY_LABEL.disconnected;

        badge.className = 'badge ' + info.kelas;
        badge.innerHTML = '<i class="fas ' + info.icon + '"></i> ' + info.text +
            (gateway.phone ? ' (' + escapeHtmlInbox(gateway.phone) + ')' : '');
    }

    function muatUlangStatusGateway() {
        fetch('<?= base_url('/inbox/api/gateway-status') ?>')
            .then(function(res) { return res.json(); })
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
    // POLLING SEDERHANA (bukan WebSocket, sesuai spec)
    // ================================================================
    renderStatusGateway(<?= json_encode($gatewayStatus) ?>);
    // Render ulang daftar sekali di awal (walau PHP sudah render first-
    // paint) -- supaya badge assignment ("Dipegang: .../Belum diambil",
    // lihat Tahap 2) & filter langsung konsisten tanpa menunggu polling
    // pertama (6 detik). Satu sumber logic render (JS), tidak
    // menduplikasi template di PHP.
    renderDaftarConversation();

    setInterval(muatUlangDaftarConversation, 6000);
    setInterval(function() { muatUlangPesan(false); }, 4000);
    setInterval(muatUlangStatusGateway, 15000);
</script>
