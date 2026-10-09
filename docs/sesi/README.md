# Indeks Checkpoint Sesi

Daftar checkpoint per sesi kerja. Sesi baru: salin `TEMPLATE.md`, isi, lalu
tambahkan barisnya di sini (urutan terbaru di atas).

| Tanggal | Topik | Status |
|---|---|---|
| 2026-10-08 | [Installer gateway: ambil source via Git, ganti ZIP (TODO-Q3g)](2026-10-08-installer-git-based-source.md) | selesai (kode + 9/9 cek lokal lulus; commit `1336aee` aulia-app, `82d1b9b` WA-Gateway; E2E PC/VM bersih belum) |
| 2026-10-08 | [Audit trail + validasi tanggal Kas Keluar (TODO-BL10) + rencana cutover gateway (TODO-Q3f)](2026-10-08-audit-validasi-kas-keluar-todo-bl10.md) | sebagian (BL10 kode+migrasi lokal selesai, commit `6e9e39a`/`747c0fb` ter-push; migrasi staging/produksi belum) |
| 2026-10-07 | [WhatsApp read receipt dua arah — implementasi + deploy produksi](2026-10-07-whatsapp-read-receipt-deploy.md) | sebagian (fitur ter-deploy & terverifikasi lokal/API; uji HP produksi belum; auto-start gateway belum andal) |
| 2026-10-06 | [Analisa log gateway aulia3 (bug balas-gambar + hasil F10)](2026-10-06-analisa-log-gateway-aulia3.md) | selesai (analisa read-only; bug quoted-image sudah diperbaiki `b48c2e5`; F10 bersih) |
| 2026-10-05 | [HTTPS self-signed AULIA-SERVER2 (secure context notifikasi Windows)](2026-10-05-https-self-signed-aulia-server2.md) | sebagian (cert + https + `80df50f` ter-deploy; trust PC kasir lain & uji E2E notifikasi belum) |
| 2026-10-05 | [Notifikasi Windows Inbox (ganti utama dari toast)](2026-10-05-notifikasi-windows-inbox.md) | sebagian (kode + test 25/25; uji browser & infra HTTPS produksi belum; belum di-commit) |
| 2026-10-05 | [Deploy produksi TODO-F8 (teks pesan diedit)](2026-10-05-deploy-produksi-f8.md) | selesai (aulia3 gateway + AULIA-SERVER2 POS; F7 & F8 terverifikasi; soak realtime berjalan) |
| 2026-10-05 | [Penutupan gate TODO-F8 (teks pesan diedit)](2026-10-05-penutupan-gate-f8.md) | selesai (gate clear; PR #5 & #49 merged; WA-Gateway `evolution` = `e653de5`) |
| 2026-10-04 | [Arsip piutang (TODO-BL06)](2026-10-04-arsip-piutang-todo-bl06.md) | selesai (kode + test; belum di-commit) |
| 2026-10-04 | [Login user non-aktif ditolak (TODO-BL05)](2026-10-04-login-user-nonaktif-todo-bl05.md) | selesai (kode + test; commit `3f0294e`) |
| 2026-10-04 | [Pembayaran ditolak pada transaksi batal (TODO-BL04)](2026-10-04-pembayaran-transaksi-batal-todo-bl04.md) | selesai (kode + test; commit `bb5e5da`) |
| 2026-10-04 | [Validasi item transaksi POS (TODO-BL03)](2026-10-04-validasi-item-transaksi-todo-bl03.md) | selesai (kode + test; commit `8de96e1`) |
| 2026-10-04 | [Closing kas historis vs arsip (TODO-BL02)](2026-10-04-closing-kas-arsip-todo-bl02.md) | selesai (kode + test; commit `efe83ed`) |
| 2026-10-04 | [Atomisitas penyimpanan transaksi POS (TODO-BL01)](2026-10-04-atomisitas-transaksi-pos-todo-bl01.md) | selesai (kode + test; commit `a4b3646`) |
| 2026-10-04 | [Fix race pembuatan percakapan baru (TODO-I4)](2026-10-04-fix-race-pembuatan-percakapan-todo-i4.md) | selesai (kode + test; commit `521468e`) |
| 2026-10-03 | [Verifikasi merge arsip (TODO-T3) + fix batas ukuran foto profil (TODO-F7)](2026-10-03-verifikasi-merge-arsip-dan-fix-ukuran-foto-profil.md) | selesai (merge + push; commit `2dfb985`) |
| 2026-10-03 | [Handoff: Template Balasan Cepat (Inbox) — requirement disetujui, desain draft](2026-10-03-handoff-template-balasan-cepat.md) | blocked (hold — Gate 2 & implementasi menunggu tim lain) |
| 2026-10-03 | [Unduh media Inbox, notifikasi lintas halaman, ekspor Excel-saja, ops gateway (O1–O3/F2), insiden Avast](2026-10-03-inbox-media-notifikasi-ops-gateway.md) | selesai (semua ter-merge/terdeploy; commit `848fdff`) |
| 2026-10-02 | [Perbaikan thread pesan Inbox: 200 terbaru + pagination, render per pesan, tampilan ala vue-advanced-chat](2026-10-02-perbaikan-thread-inbox.md) | implementasi selesai (tes node/PHP/browser di sandbox; uji manual di server pengembang menunggu) |
| 2026-10-02 | [Paket instalasi gateway untuk PC baru (bootstrap online, TODO-I1)](2026-10-02-paket-instalasi-gateway-pc-baru.md) | sebagian (implementasi + 6 cek lokal lulus; E2E PC baru/VM belum; belum di-commit) |
| 2026-10-02 | [Verifikasi uji backlog media + stress test gateway (TODO-F1), temuan bug quoted/forward (TODO-F4/F5)](2026-10-02-verifikasi-backlog-stress-test-gateway.md) | selesai (verifikasi + dokumentasi; commit `26a7847`) |
| 2026-10-02 | [Bug Inbox: nama conversation berubah jadi nama staff saat balas dari WA Web/HP](2026-10-02-bug-nama-conversation-wa-web.md) | selesai (fix + test; commit `04f037c`; produksi tersinkron) |
| 2026-10-02 | [Server-side DataTables tab Periode Laporan (S4)](2026-10-02-server-side-periode-laporan.md) | selesai (kode + verifikasi; belum di-commit) |
| 2026-10-02 | [Server-side DataTables laporan/daftar (S1–S3b)](2026-10-02-server-side-datatables.md) | sebagian (S1–S3b selesai & terverifikasi; S4 `laporan` = tab Periode saja, lihat checkpoint S4) |
| 2026-10-02 | [Standardisasi pemilih tanggal/rentang/periode](2026-10-02-standardisasi-pemilih-tanggal.md) | selesai (kode; verifikasi browser di `…-verifikasi.md`) |
| 2026-10-01 | [Produksi aulia3: tipe pesan Inbox, dead-letter, berkas besar, view-once, media ondemand](2026-10-01-produksi-aulia3-inbox-tipe-pesan.md) | sebagian (kode selesai; uji backlog semalam menunggu) |
| 2026-10-01 | [Adapter Evolution API (`evolution-gateway`): uji end-to-end + perbaikan bug + dukungan grup](2026-10-01-evolution-gateway-adapter.md) | selesai (uji via UI Inbox POS) |
| 2026-09-30 | [Riset pengganti Baileys + spike adapter Fonnte (uji nyata end-to-end)](2026-09-30-riset-pengganti-baileys-dan-spike-fonnte.md) | sebagian (riset + spike Fonnte terbukti; keputusan solusi final ditahan) |
| 2026-09-30 | [Analisis Baileys (@lid), requirement offline, arah pengganti](2026-09-30-analisis-baileys-dan-requirement-offline.md) | sebagian (analisis selesai; keputusan solusi ditahan) |
| 2026-09-30 | [Investigasi akar penyebab insiden WA Gateway degraded](2026-09-30-investigasi-akar-penyebab-degraded.md) | sebagian (investigasi selesai; keputusan nyalakan Gateway ditahan) |
| 2026-09-30 | [Sinkronisasi repo & setup checkpoint lintas-sesi](2026-09-30-sinkronisasi-repo-dan-checkpoint.md) | selesai |
| 2026-09-30 | [Insiden WA Gateway: sesi "connected" tapi diam-diam gagal](2026-09-30-wa-gateway-sesi-degraded.md) | selesai (kode); §6 dikoreksi lewat §13; tindak lanjut operasional tersisa |
| 2026-09-30 | [Perbaikan modul Produk](2026-09-30-modul-produk.md) | selesai |
