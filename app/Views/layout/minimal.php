<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'AULIA KASIR' ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>?v=<?= filemtime(FCPATH . 'assets/css/app.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/layout-minimal.css') ?>?v=<?= filemtime(FCPATH . 'assets/css/layout-minimal.css') ?>">
</head>

<body>
    <main class="container-fluid p-0">
        <?php if (session()->getFlashdata('success')): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast(<?= json_encode(session()->getFlashdata('success')) ?>, 'success');
                });
            </script>
        <?php endif; ?>
        <?php if (session()->getFlashdata('error')): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showToast(<?= json_encode(session()->getFlashdata('error')) ?>, 'danger');
                });
            </script>
        <?php endif; ?>

        <!-- 🔥 INI BAGIAN PENTING: Konten halaman di-include dari variabel $content -->
        <?= $this->include($content ?? '') ?>
    </main>

    <!-- ========================================== -->
    <!-- CONTAINER NOTIFIKASI TOAST (BAWAH KANAN)   -->
    <!-- ========================================== -->
    <div id="toastContainer" class="position-fixed p-3" style="z-index: 9999; top: 0; left: 50%; transform: translateX(-50%);">
        <div id="liveToast" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="toastMessage">
                    <!-- Pesan akan diisi oleh JavaScript -->
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle (termasuk Popper) -- dibutuhkan modal
         (Chat Baru, Hapus Percakapan, Edit Profil) & toast di
         inbox/index.php. -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // showToast()/showNotifikasi() -- salinan persis dari
        // layout/main.php supaya inbox/index.php (yang memanggil
        // showToast() di banyak tempat) tetap berfungsi identik di
        // window standalone ini.
        function showToast(message, type = 'success', opsi = {}) {
            const toastEl = document.getElementById('liveToast');
            const toastBody = document.getElementById('toastMessage');

            toastEl.className = 'toast align-items-center border-0';

            let delay = 3000;
            let autohide = true;

            if (type === 'success') {
                toastEl.classList.add('toast-success');
                delay = 3000;
            } else if (type === 'error' || type === 'danger') {
                toastEl.classList.add('toast-danger');
                autohide = false;
            } else if (type === 'warning') {
                toastEl.classList.add('toast-warning');
                delay = 4000;
            } else {
                toastEl.classList.add('toast-info');
                delay = 3000;
            }

            toastBody.innerHTML = message;

            if (typeof opsi.onClick === 'function') {
                toastBody.style.cursor = 'pointer';
                toastBody.onclick = opsi.onClick;
            } else {
                toastBody.style.cursor = 'default';
                toastBody.onclick = null;
            }

            const toast = new bootstrap.Toast(toastEl, {
                delay: delay,
                autohide: autohide,
                animation: true
            });
            toast.show();
        }

        function showNotifikasi(title, message, type = 'success') {
            const fullMessage = `<strong>${title}</strong><br>${message}`;
            showToast(fullMessage, type);
        }
    </script>
</body>

</html>
