# M1 Gelombang 1 — Eksekusi Fase 1–3 (2026-09-21)

> **Catatan pembaca:** dokumen ini append-only, melanjutkan `2026-09-21-handoff-m1-wave1-eksekusi-lokal.md`. Semua kode ada di repo **WA-Gateway** (worktree `C:\projects\WA-Gateway-m1`, branch `feature/stage-1-reliability`), mengikuti `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` dan `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.1). Dikerjakan lewat `/sdlc-write-code` dengan berhenti di tiap APPROVAL.
>
> **Legenda bukti:** **Simulasi** = skrip `test/simulate-*.js` dengan mock `sock` dan database sementara. **Nyata** = Gateway sungguhan dengan koneksi WhatsApp. **Semua bukti di dokumen ini adalah Simulasi. Belum ada bukti Nyata.**

## Ringkasan

- TASK-001 sampai TASK-016 selesai. TASK-007 dan TASK-012 (APPROVAL) sudah disetujui user. **TASK-017 (AC-001, `pm2 stop` nyata) dan TASK-018 belum dijalankan.**
- Keputusan awal (opsi A dari handoff): kode ad-hoc sandbox (E-03/E-04/E-05/E-09) dianggap draft dan ditimpa sesuai kontrak plan, bukan dipakai apa adanya.
- 13 commit di atas `091fe19`, **sudah di-push ke `origin` (21 Sep malam, fast-forward `091fe19..065f683`) tetapi belum ada PR, belum ter-merge, belum berjalan di Gateway produksi** (`C:\projects\WA-Gateway`, `master` `e18f716`, tidak disentuh).
- Lingkungan tes: Node v20.20.2 (`C:\nvm4w\nodejs`, sama dengan PM2 produksi), `npm ci` dari `package-lock.json`, database diarahkan ke folder temp.
- 17 skrip `test/simulate-*.js` lulus (0 gagal) ditambah guard `test/check-register-before-send.js`.

## Commit

| Task | Commit | Isi |
|---|---|---|
| 001 | `baf1896` | `EnqueueValidationError`, `enqueue()` mengembalikan `{status}`, `messageType` wajib |
| 002 | `b068b0c` | `enqueueWithRetry` 1 + 3 ulangan (50/200/800 ms) |
| 003 | `cd1e222` | `overflowBuffer` in-memory, maks 500 |
| 004 | `ab3b699` | `_persistIncoming()` (retry → overflow) dan drain di awal siklus worker |
| 005 | `04576f1` | `quick_check` SQLite saat start, `synchronous=FULL`, env batas |
| 006 | `58b146c` | tes tambahan AC-005 dan AC-006 |
| 008 | `c232ecc` | `ownSentRegistry` (TTL 10 menit, maks 1000) |
| 009 | `eac5c28` | ID kiriman sendiri dicatat sebelum `sendMessage` |
| 010 | `ae9f94b` | `append` diterima, disaring `_shouldAcceptAppend()` |
| 011 | `c2dbdc0` | guard statis urutan `register()` sebelum kirim |
| 014 | `e0d6c17` | log error per pesan: ID, JID, tipe konten |
| 013 | `d01569c` | timeout LID 2 detik, cache negatif 60 detik |
| 015 | `065f683` | perbaikan lima celah pemulihan JSON |

## Keputusan dan penyimpangan yang perlu ditinjau

1. **`messageType` wajib** (REQ-006). Default `'text'` dihapus. Keputusan user. Semua pemanggil `enqueue()` sudah selalu mengirimnya. Fixture di dua tes lama ditambah `messageType`.
2. **Level `critical` tidak ada di logger** (`src/logging` hanya `info/warn/error/debug`). Dipakai `logger.error` dengan prefix `[CRITICAL]` dan `severity: 'critical'` (memenuhi GUD-002). Belum mengubah logger bersama. **Asumsi, perlu konfirmasi.**
3. **"Jumlah yang dibuang" (AC-009)** dicatat dua-duanya: `dropped: 1` untuk kejadian itu dan `totalDropped` akumulatif. Spec tidak menjelaskan mana yang dimaksud.
4. **Karantina JSON dilakukan lebih dulu, juga saat `.bak` berhasil memulihkan.** Spec (REQ-017) hanya mewajibkan pemindahan saat keduanya gagal. Ditambah karena menjaga bukti dan mencegah berkas rusak ikut disalin menimpa `.bak`. **Menyimpang dari huruf spec.**
5. **TASK-009: dua titik sisipan, bukan satu.** `sendTextMessage()` dan `sendMediaMessage()` adalah satu-satunya dua pemanggilan `sock.sendMessage()` (semua jalur HTTP bermuara ke sana). Kriteria STOP (lebih dari dua titik) tidak terpenuhi. Opsi `messageId` diverifikasi dari kode Baileys 6.7.24 (`messages-send.js`: `messageId: generateMessageIDV2(...)` lalu `...options`).
6. **Skenario 5 dan 6 di `simulate-enqueue-failure.js` dipindah** ke `_persistIncoming()`. Kontrak lama ("lempar error setelah retry habis") diganti kontrak D-02 ("masuk overflow, tidak melempar"). Assertion setara, bukan dilemahkan.
7. **Variabel env baru (GUD-001):** `ENQUEUE_RETRY_DELAYS_MS` (50,200,800), `ENQUEUE_OVERFLOW_MAX` (500, min 1), `OWN_SENT_TTL_MS` (600000), `OWN_SENT_MAX` (1000, min 1), `LID_LOOKUP_TIMEOUT_MS` (2000, min 1), `LID_LOOKUP_NEGATIVE_TTL_MS` (60000, min 0). Nilai tidak valid memakai bawaan utuh.

## Temuan selama eksekusi

- **E-05 lama:** hasil `null` di-cache **selamanya** bahkan saat timeout atau gagal (hanya log debug). Satu kegagalan sesaat membuat JID itu tidak pernah dicoba lagi sampai restart. Sekarang kegagalan di-cache 60 detik, hasil sukses tetap permanen.
- **E-09 lama, lima celah:** error tanpa ukuran berkas; pemulihan sukses dicatat sebagai error; berkas utama hilang dengan `.bak` valid diabaikan; `_persist()` menyalin berkas utama ke `.bak` tanpa validasi (bisa menimpa satu-satunya cadangan baik); JSON valid berbentuk salah (`{}`, `[]`) dianggap antrean kosong yang sehat.
- **Bug saya sendiri, tertangkap tes:** membuka SQLite dengan koneksi tulis lalu menutupnya membuat SQLite menghapus `-wal` dan `-shm` sendiri, sehingga hanya 1 dari 3 berkas yang dipindah saat database korup (melanggar REQ-013). Pemeriksaan integritas sekarang memakai koneksi read-only.
- **`node.exe` (±87 MB) ter-commit di repo WA-Gateway** dari commit sebelumnya (`e18f716`, "Create node.exe"). Bukan dari eksekusi ini; dicatat karena membebani repo.

## Bukti (Simulasi)

| AC | Bukti | Hasil |
|---|---|---|
| AC-002 | `simulate-append-handling` #5: `append` disuntik di tengah `sendTextMessage` asli | Lulus |
| AC-003, 004, 015, 017 | `simulate-append-handling` #6 sampai #10 | Lulus |
| AC-005, 006 | `simulate-enqueue-integrity`, `simulate-durable-buffer` #13 sampai #13c | Lulus |
| AC-007, 008, 009, 011, 016 | `simulate-durable-buffer` #2 sampai #16 | Lulus |
| AC-010 | `simulate-lid-timeout`: tersimpan tanpa `identity_hint` setelah 2015 ms, peringatan tercatat | Lulus |
| AC-018 | `simulate-lid-timeout`: pesan kedua dilewati dalam 4 ms; batas 59 detik vs 61 detik diuji | Lulus |
| AC-013 | `simulate-error-isolation` (notify dan append, banyak gagal, struktur rusak) | Lulus |
| AC-012 | `simulate-json-recovery` (8 skenario) | Lulus |
| AC-014 | 12 payload dibandingkan dengan `origin/master` (`e18f716`, kode produksi): field dan nilai identik; kontrol negatif terdeteksi | Lulus |
| AC-001 | TASK-017 | **Belum** |

- **Uji mutasi:** 4 mutasi (TASK-014), 6 (TASK-013), 7 (TASK-015), ditambah mutasi pada TASK-009 dan TASK-010. Semua tertangkap oleh assertion yang tepat; file dipulihkan dengan hash sama. Skrip pembantunya bukan bagian repo.
- **AC-014, cara:** baseline diekstrak dengan `git archive` ke folder sementara di luar repo (tanpa `git checkout`), kedua versi diberi 12 pesan yang sama (teks, extended, lid, grup, keluar, gambar, dokumen, sticker, audio, video, alamat unknown, tanpa nama), `postToCI4` di-stub, body POST disimpan. Folder sementara dihapus setelahnya.
- **Guard statis (RISK-001):** 8 fixture rusak (register sesudah await, tanpa register, register di komentar atau method lain, tanpa `messageId`, register ditunda) semuanya gagal; source asli lolos (2 titik kirim).

## Batas bukti (limitation eksplisit)

- **Semua Simulasi.** Balapan `append` sungguhan (Baileys memancarkan `append` kiriman sendiri lewat `process.nextTick` sebelum `sendMessage` kembali) baru terbukti di TASK-017. Sekarang terbukti hanya urutannya: ID sudah tercatat saat `sendMessage` dipanggil.
- Restart Gateway nyata, kehilangan pesan saat offline, dan perilaku dengan pesan WhatsApp asli belum diuji.
- Jalur JSON fallback belum diuji di lingkungan yang benar-benar memakainya (build Android).
- Guard statis membaca pola teks; refactor yang mengubah bentuk method bisa mengelabuinya. Ia melengkapi tes runtime, bukan menggantikannya.
- E-02 dan E-07 tetap di luar scope dan belum diverifikasi dengan WhatsApp nyata.

## Belum dikerjakan

- **TASK-017:** `pm2 stop wa-gateway` ±30 detik, 10 pesan dari HP tes, `pm2 start`, 3 kali. Mematikan Gateway produksi, **hanya boleh >21:00 atau <08:00** (RISK-003), butuh persetujuan terpisah. Prasyarat: kode ini harus ada di Gateway yang diuji. Sekarang baru di worktree `WA-Gateway-m1`; cara deploy (merge ke `master`, lalu restart PM2) belum diputuskan dan juga menyentuh produksi.
- **TASK-018:** persetujuan bahwa AC-001 lulus 3 kali.
- Branch sudah di-push ke `origin` (tanpa force). PR belum dibuat; disarankan setelah `/sdlc-code-review`.
- Di luar scope, dibiarkan: `_resolveLidForPhoneJid` masih meng-cache `null` permanen kalau Gateway belum connected saat pesan diproses (identity hint JID itu hilang sampai restart). Skrip lama `simulate-e05-lid-timeout.js` tumpang tindih dengan `simulate-lid-timeout.js`.
- Gelombang 2 dan 3 (Ticket 05 sampai 16, termasuk idempotency `/send`, GW-09) belum dimulai.

## Referensi

- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (kolom Completed sudah diisi)
- `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.1)
- `docs/decisions/2026-09-21-handoff-m1-wave1-eksekusi-lokal.md`
- `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md`
- `docs/audit/clarification-report-m1-wave1-incoming-reliability-2026-09-21.md`
