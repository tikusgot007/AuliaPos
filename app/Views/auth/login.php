<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?? 'Login' ?></title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* =========================================================
       AULIA LOGIN - DARK THEME
       ========================================================= */

        :root {
            --aulia-green: #16856b;
            --aulia-green-dark: #106b57;
            --aulia-green-light: #1da47f;

            --page-bg: #0d0f0f;
            --page-bg-2: #171a19;

            --card-bg: #151515;
            --card-bg-soft: #1c1c1c;

            --input-bg: #222222;
            --input-border: #383838;
            --input-border-focus: #16856b;

            --text-primary: #ffffff;
            --text-secondary: #b5b5b5;
            --text-muted: #777777;

            --white-soft: #f5f5f5;
        }


        /* =========================================================
       RESET
       ========================================================= */

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }


        /* =========================================================
       BODY
       ========================================================= */

        body {
            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 20px;

            font-family:
                'Segoe UI',
                Tahoma,
                Geneva,
                Verdana,
                sans-serif;

            background:
                radial-gradient(circle at 15% 20%,
                    rgba(22, 133, 107, 0.10),
                    transparent 35%),
                radial-gradient(circle at 85% 80%,
                    rgba(22, 133, 107, 0.07),
                    transparent 35%),
                linear-gradient(135deg,
                    var(--page-bg) 0%,
                    var(--page-bg-2) 100%);

            color: var(--text-primary);
        }


        /* =========================================================
       BACKGROUND PATTERN
       ========================================================= */

        .bg-pattern {
            position: fixed;

            inset: 0;

            width: 100%;
            height: 100%;

            pointer-events: none;

            z-index: 0;

            background-image:
                radial-gradient(circle at 20% 50%,
                    rgba(255, 255, 255, 0.025) 0%,
                    transparent 40%),
                radial-gradient(circle at 80% 50%,
                    rgba(22, 133, 107, 0.035) 0%,
                    transparent 40%);
        }


        /* =========================================================
       LOGIN CARD
       ========================================================= */

        .login-card {
            position: relative;

            z-index: 1;

            width: 100%;
            max-width: 420px;

            padding: 30px 35px;

            background: var(--card-bg);

            border: 1px solid rgba(255, 255, 255, 0.06);

            border-radius: 20px;

            box-shadow:
                0 25px 60px rgba(0, 0, 0, 0.50),
                0 0 0 1px rgba(255, 255, 255, 0.015);

            animation: fadeInUp 0.6s ease;
        }


        /* =========================================================
       CARD ANIMATION
       ========================================================= */

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }


        /* =========================================================
       LOGO / VIDEO CONTAINER
       ========================================================= */

        .login-card .logo {
            width: 100%;

            margin-bottom: 25px;

            background: #000000;

            border-radius: 14px;

            overflow: hidden;

            display: flex;

            align-items: center;

            justify-content: center;

            position: relative;
        }


        /* =========================================================
       INTRO VIDEO
       ========================================================= */

        .login-card .logo video {
            display: block;

            width: 100%;

            max-width: 400px;

            height: 190px;

            object-fit: contain;

            background: #000000;

            border: none;

            outline: none;
        }


        /* =========================================================
       FORM LABEL
       ========================================================= */

        .login-card .form-label {
            display: block;

            margin-bottom: 7px;

            color: var(--white-soft);

            font-size: 14px;

            font-weight: 600;
        }


        /* =========================================================
       INPUT GROUP
       ========================================================= */

        .login-card .input-group {
            width: 100%;
        }


        /* =========================================================
       INPUT ICON
       ========================================================= */

        .login-card .input-group-text {
            min-width: 46px;

            justify-content: center;

            background: var(--input-bg);

            color: var(--aulia-green);

            border: 1px solid var(--input-border);

            border-right: none;

            border-radius: 10px 0 0 10px;
        }


        /* =========================================================
       FORM INPUT
       ========================================================= */

        .login-card .form-control {
            height: 48px;

            padding: 12px 15px;

            background: var(--input-bg);

            color: #ffffff;

            border: 1px solid var(--input-border);

            border-radius: 0 10px 10px 0;

            font-size: 14px;

            transition:
                border-color 0.25s ease,
                box-shadow 0.25s ease,
                background 0.25s ease;
        }


        /* =========================================================
       INPUT PLACEHOLDER
       ========================================================= */

        .login-card .form-control::placeholder {
            color: #777777;

            opacity: 1;
        }


        /* =========================================================
       INPUT FOCUS
       ========================================================= */

        .login-card .form-control:focus {
            background: #252525;

            color: #ffffff;

            border-color: var(--input-border-focus);

            box-shadow:
                0 0 0 0.2rem rgba(22, 133, 107, 0.15);

            outline: none;
        }


        /* =========================================================
       INPUT GROUP FOCUS EFFECT
       ========================================================= */

        .login-card .input-group:focus-within .input-group-text {
            border-color: var(--aulia-green);

            color: var(--aulia-green-light);

            background: #252525;
        }


        /* =========================================================
       LOGIN BUTTON
       ========================================================= */

        .login-card .btn-login {
            width: 100%;

            height: 48px;

            padding: 12px;

            margin-top: 5px;

            border: none;

            border-radius: 10px;

            background:
                linear-gradient(135deg,
                    var(--aulia-green) 0%,
                    var(--aulia-green-dark) 100%);

            color: #ffffff;

            font-size: 15px;

            font-weight: 600;

            cursor: pointer;

            transition:
                transform 0.25s ease,
                box-shadow 0.25s ease,
                filter 0.25s ease;
        }


        /* =========================================================
       BUTTON HOVER
       ========================================================= */

        .login-card .btn-login:hover {
            transform: translateY(-2px);

            filter: brightness(1.05);

            box-shadow:
                0 8px 22px rgba(22, 133, 107, 0.30);
        }


        /* =========================================================
       BUTTON ACTIVE
       ========================================================= */

        .login-card .btn-login:active {
            transform: translateY(0);

            box-shadow:
                0 4px 10px rgba(22, 133, 107, 0.20);
        }


        /* =========================================================
       ALERT
       ========================================================= */

        .login-card .alert {
            border-radius: 10px;

            border: none;

            font-size: 14px;

            margin-bottom: 20px;
        }


        /* =========================================================
       ERROR ALERT
       ========================================================= */

        .login-card .alert-danger {
            background: rgba(220, 53, 69, 0.12);

            color: #ff8d98;

            border: 1px solid rgba(220, 53, 69, 0.20);
        }


        /* =========================================================
       SUCCESS ALERT
       ========================================================= */

        .login-card .alert-success {
            background: rgba(22, 133, 107, 0.12);

            color: #55d5ae;

            border: 1px solid rgba(22, 133, 107, 0.20);
        }


        /* =========================================================
       FOOTER
       ========================================================= */

        .login-card .footer-text {
            text-align: center;

            margin-top: 22px;

            color: var(--text-muted);

            font-size: 12px;

            letter-spacing: 0.2px;
        }


        /* =========================================================
       FOOTER ICON
       ========================================================= */

        .login-card .footer-text i {
            color: var(--aulia-green);

            opacity: 0.8;
        }


        /* =========================================================
       BOOTSTRAP OVERRIDE
       ========================================================= */

        .login-card .mb-3 {
            margin-bottom: 18px !important;
        }


        /* =========================================================
       MOBILE
       ========================================================= */

        @media (max-width: 480px) {

            body {
                padding: 15px;
            }

            .login-card {
                max-width: 100%;

                padding: 24px 20px;

                border-radius: 17px;
            }

            .login-card .logo {
                margin-bottom: 22px;

                border-radius: 12px;
            }

            .login-card .logo video {
                height: 160px;
            }

            .login-card .form-control,
            .login-card .btn-login {
                height: 46px;
            }
        }


        /* =========================================================
       VERY SMALL SCREEN
       ========================================================= */

        @media (max-width: 360px) {

            .login-card {
                padding: 20px 16px;
            }

            .login-card .logo video {
                height: 140px;
            }

            .login-card .form-label {
                font-size: 13px;
            }
        }


        /* =========================================================
       REDUCED MOTION
       ========================================================= */

        @media (prefers-reduced-motion: reduce) {

            .login-card {
                animation: none;
            }

            .login-card .btn-login,
            .login-card .form-control {
                transition: none;
            }
        }
    </style>
</head>

<body>

    <div class="bg-pattern"></div>

    <div class="login-card">
        <div class="logo">
            <video
                autoplay
                loop
                muted
                playsinline
                aria-label="AULIA">
                <source src="<?= base_url('intro_pos.webm') ?>" type="video/webm">
            </video>
        </div>

        <?php if (session()->getFlashdata('error')): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= session()->getFlashdata('error') ?>
            </div>
        <?php endif; ?>

        <?php if (session()->getFlashdata('success')): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= session()->getFlashdata('success') ?>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('/auth/proses-login') ?>" method="post">
            <?= csrf_field() ?>

            <div class="mb-3">
                <label class="form-label fw-semibold">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control" name="username"
                        placeholder="Masukkan username" value="<?= old('username') ?>" required autofocus>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" name="password"
                        placeholder="Masukkan password" required>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt me-2"></i> Login
            </button>
        </form>

        <div class="footer-text">
            <i class="fas fa-shield-alt me-1"></i>Kasir System V2.0
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>