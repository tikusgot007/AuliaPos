<div class="card">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0"><i class="fas fa-flask"></i> Test Inbox WhatsApp (Sementara)</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-secondary">
            <i class="fas fa-info-circle"></i>
            Halaman ini <strong>sementara</strong>, cuma buat verifikasi
            Phase 3 (kirim balasan dari POS). UI Inbox yang sebenarnya
            (daftar chat rapi, riwayat pesan, auto-update pesan masuk)
            akan dibuat di Phase 4.
        </div>

        <h6>Daftar Conversation</h6>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Chat ID (JID)</th>
                        <th>Tipe</th>
                        <th>Nama Kontak</th>
                        <th>Telepon</th>
                        <th>Status</th>
                        <th>Pesan Terakhir</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($conversations)): ?>
                        <?php foreach ($conversations as $c): ?>
                            <tr>
                                <td><?= esc($c['id']) ?></td>
                                <td><code style="font-size: 0.75rem;"><?= esc($c['chat_id']) ?></code></td>
                                <td><?= esc($c['jid_type']) ?></td>
                                <td><?= esc($c['contact_name'] ?? '-') ?></td>
                                <td><?= esc($c['phone'] ?? '-') ?></td>
                                <td>
                                    <span class="badge <?= $c['status'] === 'open' ? 'bg-success' : 'bg-secondary' ?>">
                                        <?= esc($c['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= esc($c['last_message_direction'] ?? '-') ?>
                                    @ <?= esc($c['last_message_at'] ?? '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted">
                                Belum ada conversation. Kirim WA test dulu ke nomor toko (Phase 2).
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h6>Kirim Balasan Test</h6>
        <form id="formKirimTest">
            <div class="mb-3">
                <label class="form-label">Pilih Conversation</label>
                <select class="form-select" id="conversationId" required>
                    <option value="">-- Pilih --</option>
                    <?php foreach ($conversations as $c): ?>
                        <option value="<?= esc($c['id']) ?>">
                            #<?= esc($c['id']) ?> -- <?= esc($c['contact_name'] ?: $c['chat_id']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Pesan</label>
                <textarea class="form-control" id="teksPesan" rows="3" required placeholder="Ketik balasan di sini..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary" id="btnKirimTest">
                <i class="fas fa-paper-plane"></i> Kirim
            </button>
        </form>
    </div>
</div>

<script>
    document.getElementById('formKirimTest').addEventListener('submit', function(e) {
        e.preventDefault();

        const conversationId = document.getElementById('conversationId').value;
        const text = document.getElementById('teksPesan').value.trim();
        const btn = document.getElementById('btnKirimTest');

        if (!conversationId) {
            showToast('Pilih conversation dulu.', 'warning');
            return;
        }
        if (!text) {
            showToast('Pesan tidak boleh kosong.', 'warning');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Mengirim...';

        fetch('<?= base_url('/inbox/kirim') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'conversation_id=' + encodeURIComponent(conversationId) + '&text=' + encodeURIComponent(text)
            })
            .then(function(res) {
                return res.json().then(function(json) {
                    return { status: res.status, json: json };
                });
            })
            .then(function(result) {
                if (result.json.status === 'success') {
                    showToast('Pesan berhasil dikirim!', 'success');
                    document.getElementById('teksPesan').value = '';
                } else {
                    showToast(result.json.message || 'Gagal mengirim pesan.', 'danger');
                }
            })
            .catch(function(err) {
                showToast('Gagal menghubungi server: ' + err.message, 'danger');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Kirim';
            });
    });
</script>
