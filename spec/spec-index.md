---
title: Spec Index — Inbox WhatsApp Grup, Balas Pesan, Teruskan
version: 1.2
date_created: 2026-09-26
owner: AuliaPos Inbox module
tags: [inbox, chat, whatsapp, grup, balas-pesan, teruskan, index]
---

# Introduction

Dokumen ini adalah **titik masuk** untuk seluruh spesifikasi teknis yang menerjemahkan `prd-20260926-0024-whatsapp-grup-balas-teruskan.md` (v1.1, Readiness Score 88/100, status **PROCEED** per `docs/audit/clarification-report-whatsapp-grup-balas-teruskan-2026-09-26.md`). PRD ini dipecah menjadi **empat spec terpisah** karena mencakup dua domain berbeda (identitas grup vs aksi pesan) dan menyentuh **dua repo** dengan siklus rilis berbeda (AuliaPos/CI4 dan `tikusgot007/WA-Gateway`), persis mengikuti pentahapan PRD Section 9.2.

> [!IMPORTANT]
> **Akses repo WA-Gateway.** Sesi ini tidak memiliki working copy `tikusgot007/WA-Gateway` (repo di komputer lain, per catatan pemilik proyek). Seluruh fakta teknis WA-Gateway di spec ini diverifikasi langsung dari kode publik di `https://github.com/tikusgot007/WA-Gateway` (branch `master`: `src/whatsapp/jidUtils.js`, `src/whatsapp/connectionManager.js`, `src/api/ci4Routes.js`) atas izin eksplisit pemilik proyek pada sesi ini — bukan tebakan. Perubahan aktual pada repo tersebut tetap **di luar kemampuan tulis sesi ini** dan memerlukan plan/PR terpisah di repo itu sendiri.

## Peta Spec

| # | File | Tahap PRD | Repo yang tersentuh | Bisa dirilis sendiri? |
| --- | --- | --- | --- | --- |
| 1 | [`spec-design-grup-tahap1-tab-inbox.md`](./spec-design-grup-tahap1-tab-inbox.md) | Tahap 1 — Grup di AuliaPos (GH-011, GH-012) | AuliaPos saja | **Ya** — tidak menunggu apa pun |
| 2 | [`spec-design-grup-tahap2-identitas.md`](./spec-design-grup-tahap2-identitas.md) | Tahap 2 — Grup di WA-Gateway (GH-013, GH-014) | WA-Gateway **dan** AuliaPos | Tidak — Gateway rilis lebih dulu demi kebenaran identitas (`GH-013`) & judul grup (`GH-014`); pesan dari Gateway lama **tetap tersimpan** (hanya salah label), bukan hilang |
| 3 | [`spec-design-balas-pesan.md`](./spec-design-balas-pesan.md) | Tahap 3 — Balas Pesan (GH-015) | WA-Gateway **dan** AuliaPos | Tidak — perlu Tahap 1 selesai (berlaku juga di grup) |
| 4 | [`spec-design-teruskan.md`](./spec-design-teruskan.md) | Tahap 4 — Teruskan (GH-016) | WA-Gateway **dan** AuliaPos | Tidak — perlu Tahap 1 & 3 selesai |

## Urutan Pengerjaan Wajib

Mengikuti PRD Section 9.2 dan `docs/CHAT.md` §18 (dimensi independen — pekerjaan ini hanya boleh menyentuh dimensi **identitas**):

```
Tahap 1 (spec #1) ──► Tahap 2 (spec #2) ──► Tahap 3 (spec #3) ──► Tahap 4 (spec #4)
   AuliaPos saja        2 repo                2 repo                2 repo
```

Setiap spec **wajib** diselesaikan lewat alur SDLC penuh (`/sdlc-clarify-reqs` → `/sdlc-plan-tasks` → `/sdlc-write-code` → `/sdlc-code-review`) sebelum lanjut ke spec berikutnya. Jangan mengerjakan dua spec sekaligus (One Path Rule, `CLAUDE.md`).

## Istilah Kanonik

Seluruh spec dalam set ini memakai istilah dari `CONTEXT.md`: **Grup**, **Balas Pesan**, **Teruskan**. Lihat masing-masing entri untuk sinonim yang harus dihindari (`_Avoid_`).

## Dokumen Sumber

- PRD: `prd-20260926-0024-whatsapp-grup-balas-teruskan.md` v1.1
- Clarification Report: `docs/audit/clarification-report-whatsapp-grup-balas-teruskan-2026-09-26.md`
- Arsitektur & invarian modul Inbox yang sudah berjalan: `docs/CHAT.md`
- Glosarium: `CONTEXT.md`
