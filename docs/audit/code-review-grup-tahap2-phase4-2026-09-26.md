---
title: Code Review — Grup Tahap 2 Phase 4 (REQ-011/AC-012, label-aman @g.us)
date: 2026-09-26
owner: AuliaPos Inbox module
tags: [audit, code-review, grup, tahap2, phase4, sender-identity]
---

# Code Review — Grup Tahap 2 Phase 4 (`REQ-011`/`AC-012`, label-aman `@g.us`)

> **Fixed point:** perubahan Phase 4 masih uncommitted dan bercampur dengan phase
> refactor lain di working tree. Ruang lingkup dipin ke deliverable Phase 4
> (`REQ-001`/`REQ-011`/`AC-012`): `app/Services/SenderIdentityFormatter.php`,
> `attachSenderNames()` di `app/Controllers/Inbox.php`, dan berkas test terkait.
> Metode: Two-Axis review dengan dua sub-agent paralel (Standards/Security dan
> Spec Compliance), temuan tiap sumbu **diverifikasi ulang** ke kode/probe.

## 1. Ringkasan Eksekutif

- **Sumbu A (Standards, Keamanan & Arsitektur):** arsitektur sehat — formatter
  murni dan stateless, pemisahan tanggung jawab benar, tidak ada mutasi DB di
  jalur baca. Ditemukan satu celah invarian (`SEC-02`: `s.whatsapp.net` dengan
  local part ber-`@` mengembalikan JID mentah) plus beberapa item kebersihan
  (case domain, magic string, kekuatan oracle test). **Phase 4 sendiri tidak
  memperkenalkannya** — celah ini laten pada fungsi yang sama (Phase 3).
- **Sumbu B (Kesesuaian Spec):** delta Phase 4 **patuh spec** — `@g.us`
  (case-insensitive) → `null`, JID grup tidak dirender, nilai DB tidak berubah;
  `@lid`/`*.lid` → `LID`; malformed → `Pengirim`; percakapan pribadi/outgoing
  tidak berubah. Tidak ada scope creep (opsi (b) bersih-bersih data tidak
  dijalankan). Satu inkonsistensi batas `NIT` (`@g.us` local kosong).

## 2. Sumbu A — Standards, Keamanan & Arsitektur

- **[REQUIRED] [SEC-02] `labelFor()` dapat mengembalikan JID mentah lewat local
  part `s.whatsapp.net` yang dibuat khusus.**
  - **Deskripsi:** domain diambil dari `@` **terakhir** (`strrpos`, baris 41),
    tetapi seluruh prefix dikembalikan sebagai nomor (`$local` baris 46 →
    `return $phone` baris 54). Maka
    `labelFor('120363@g.us@s.whatsapp.net')` → `'120363@g.us'`, merender JID grup
    sebagai label dan menembus kontrak "TIDAK PERNAH mengembalikan JID mentah".
  - **Kategori:** Security (input-boundary / information disclosure) + Correctness.
  - **Lokasi:** `app/Services/SenderIdentityFormatter.php` (41-54).
  - **Remedy:** di cabang `s.whatsapp.net` wajibkan phone part cocok `^\d+$`
    (setelah membuang sufiks device `:NN`), selain itu fallback `'Pengirim'`.
    Tambah test negatif `x@g.us@s.whatsapp.net`.
  - **Catatan verifikasi (orchestrator):** **TERKONFIRMASI** lewat probe langsung
    (`120363@g.us@s.whatsapp.net` → `'120363@g.us'`). Reachability dari WA-Gateway
    nyata nihil (Baileys tidak pernah menghasilkan local part ber-`@`); ini
    hardening defense-in-depth, bukan lubang aktif. **Jangan** memperketat guard
    `400` (`REQ-010`/ALT-001 tetap longgar) — perbaikan cukup di formatter.

- **[OPTIONAL] [SEC-01] `messages.sender_jid` mentah tetap ikut terkirim di
  payload thread.**
  - **Deskripsi:** `attachSenderNames()` hanya menimpa `sender_name`; baris
    `findAll()` (semua kolom) diserialisasi apa adanya, jadi nilai `@g.us` legacy
    (dan JID mentah lain) sampai ke browser walau tidak digambar.
  - **Kategori:** Security (information disclosure).
  - **Lokasi:** `app/Controllers/Inbox.php` (309, 316-320);
    `app/Models/MessageModel.php` (100-106).
  - **Remedy:** buat allowlist keluaran eksplisit (buang `sender_jid` dan
    internal media) sebelum `setJSON`; jaga jalur baca tetap read-only.
  - **Catatan verifikasi:** **BENAR secara fakta**, tetapi **pre-existing** (kolom
    `sender_jid` sudah ada sejak Tahap 1/2 dan selalu ikut) dan **bukan**
    pelanggaran `REQ-011` (aturan spec adalah "tidak dirender", bukan "tidak
    dikirim"); JID juga sudah wajar hadir via `chat_id`. Karena itu diturunkan
    dari `REQUIRED` (usulan sub-agent) menjadi **OPTIONAL** — perbaikan
    defense-in-depth di luar delta Phase 4.

- **[OPTIONAL] [TEST-01] Oracle "tidak pernah JID mentah" lemah dan terbatas.**
  - **Deskripsi:** `assertNotSame($jid, $label)` tidak bisa gagal untuk input
    fallback (semua → `'Pengirim'`) dan tidak menangkap kebocoran sub-JID seperti
    `SEC-02`.
  - **Kategori:** Clean Code (kualitas test) / coverage.
  - **Lokasi:** `tests/unit/SenderIdentityFormatterTest.php` (38-55);
    `tests/session/InboxGrupTahap2Phase2Test.php` (282-306).
  - **Remedy:** nyatakan properti secara langsung
    (`assertStringNotContainsString('@', $label)`), tambah kasus crafted/uppercase.

- **[OPTIONAL] [STD-01] Perbandingan domain tidak konsisten huruf besar/kecil.**
  - **Deskripsi:** `g.us` memakai `strcasecmp` (baris 61), tetapi
    `s.whatsapp.net` `===` (49) dan `lid`/`.lid` case-sensitive (57). Akibatnya
    `628...@S.WHATSAPP.NET` → `'Pengirim'` dan `999@LID`/`999@hosted.LID` →
    `'Pengirim'`.
  - **Kategori:** Clean Code / Correctness.
  - **Lokasi:** `app/Services/SenderIdentityFormatter.php` (49, 57, 61).
  - **Remedy:** normalisasi `$domain = strtolower($domain)` sekali, lalu bandingkan
    ketat. **Catatan verifikasi:** terkonfirmasi lewat probe.

- **[NIT] [STD-02] Literal domain masih magic string** sementara label sudah
  konstanta. Lokasi: baris 49, 52, 57, 61.
- **[NIT] [STD-03] `@g.us` dengan local kosong → `'Pengirim'`, bukan `null`.**
  Guard `$pos === 0` (baris 42) berjalan sebelum cek domain grup. Input malformed,
  tanpa kebocoran; inkonsisten dengan aturan domain grup. Lokasi: baris 42, 61-65.
- **[FYI] [STD-04] Arsitektur sehat:** ekstraksi ke service murni benar, `@`-terakhir
  sudah dipakai, jalur baca tidak menulis DB (dibuktikan
  `InboxGrupTahap2Phase2Test.php:332-334`). `sender_name` di-escape di view
  (`escapeHtmlInbox`, `app/Views/inbox/index.php:739-744`), jadi `SEC-02` bukan
  sink XSS.

## 3. Sumbu B — Kesesuaian Spec

- **[NIT] [SPEC-EDGE-01] `@g.us` local kosong menembus aturan tanpa-identitas.**
  - **Deskripsi:** aturan domain grup tak tercapai saat local kosong; `@g.us` →
    `'Pengirim'`, bukan `null`. Tidak membocorkan JID; data legacy nyata selalu
    berlocal numerik (`sender_jid = remoteJid`), jadi dampak nihil.
  - **Referensi Spec:** `REQ-011` / `AC-012` / Section 12 ("domain grup `g.us` →
    tanpa identitas").
  - **Lokasi:** `app/Services/SenderIdentityFormatter.php` (41-44, 61).
  - **Remedy:** dahulukan cek domain `g.us` sebelum guard local kosong, atau
    terima sebagai batas input malformed yang didokumentasikan.

**Tidak ada ketidaksesuaian fungsional lain.** Terverifikasi patuh: (1) `@g.us`
case-insensitive → `null`, tanpa tulis DB; (2) `@s.whatsapp.net` sufiks device
dibersihkan dan `@lid`/`*.lid` → `LID`, malformed → `Pengirim`; (3) `sender_jid
IS NULL` → `sender_name = null` (`Inbox.php:655`); (4) percakapan pribadi tidak
berubah (parameter `jid_type` hanya dikirim di `Inbox.php:309`); (5) cabang
outgoing mendahului cabang grup sehingga pelabelan grup hanya untuk incoming;
(6) tidak ada UPDATE/migrasi/perubahan storage/validasi; (7) test `AC-012` memakai
JID grup nyata `120363012345678901@g.us`, menegaskan `sender_name === null`, tidak
dirender, dan nilai DB tidak berubah.

## 4. Verdict

- **Total temuan:** 7 isu Sumbu A (1 `REQUIRED`, 2 `OPTIONAL`, 2 `NIT`, 1 `FYI`,
  +1 `OPTIONAL` TEST), 1 isu Sumbu B (`NIT`).
- **Temuan Sumbu A terburuk:** `SEC-02` — `labelFor()` dapat mengembalikan JID
  mentah untuk local part `s.whatsapp.net` yang dibuat khusus.
- **Temuan Sumbu B terburuk:** `SPEC-EDGE-01` (NIT) — `@g.us` local kosong →
  `'Pengirim'` alih-alih tanpa identitas.
- **Rekomendasi:** **Proceed to Refactoring Plan.** Delta Phase 4 patuh spec dan
  boleh digabung; celah invarian `SEC-02` diperbaiki lewat
  `plan/plan-refactor-sender-identity-label-hardening-v1.0.md` sebelum menutup
  refactor.
