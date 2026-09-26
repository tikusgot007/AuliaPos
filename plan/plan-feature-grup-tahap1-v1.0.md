---
goal: Pisahkan percakapan Grup WhatsApp dari antrean kerja kasir (tab Grup, badge, aksi dinonaktifkan)
version: 1.0
date_created: 2026-09-26
last_updated: 2026-09-26
owner: AuliaPos Inbox module
status: 'Planned'
tags: [feature, inbox, grup, tahap1]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

Plan ini adalah rincian eksekusi untuk `spec-design-grup-tahap1-tab-inbox.md` (v1.0): menambah tab **Grup** di `/inbox`, mengecualikan percakapan grup dari badge `perlu_dibalas`, dan menonaktifkan/menyembunyikan aksi yang tidak berlaku untuk grup. Tahap ini murni sisi AuliaPos — tidak ada perubahan skema database, tidak menyentuh repo `WA-Gateway`.

## 1. Requirements & Constraints

- **REQ-001**: `GET /inbox/api/conversations?status=grup` mengembalikan seluruh percakapan `jid_type='group'`.
- **REQ-002**: Percakapan grup tidak pernah cocok dengan status lama (`belum_diambil`/`open`/`menunggu`/`ditunda`/`selesai`).
- **REQ-003**: `withComputedStatus()` menghitung `queue_status='grup'` untuk baris `jid_type='group'`, ditaruh **setelah** blok if/elseif yang sudah ada.
- **REQ-004**: `apiPerluDibalasCount()` mengecualikan `jid_type='group'` dari `count()`, dengan menambah `jid_type` ke `select()`.
- **REQ-005**: Baris daftar grup menampilkan badge teks "Grup".
- **REQ-006**: Header percakapan grup menampilkan penanda yang sama, terpisah dari judul.
- **CON-001**: Tombol Ambil/Lepas/Tutup/Snooze **tidak dirender** (bukan disabled) untuk grup.
- **CON-002**: Konfirmasi Nomor (header) dan Edit Profil (baris daftar) dirender **disabled** untuk grup; Hapus percakapan tetap berfungsi penuh.
- **CON-003**: Kirim pesan teks/media dan Internal Note tetap berfungsi tanpa perubahan pada grup.
- **CON-004**: Endpoint aksi (`ambilPercakapan`, `lepasPercakapan`, `tutupPercakapan`, `snoozePercakapan`, `konfirmasiNomor`, Edit Profil) menolak `403` untuk `jid_type='group'` di server, terlepas dari UI.
- **GUD-001**: Tidak boleh menambah query baru ke database — semua filter beroperasi di atas `findAll()` yang sudah ada.

## 2. Implementation Steps

> **EXECUTION DIRECTIVE FOR AI AGENTS:**
> Jalankan plan ini phase demi phase. Jalankan task VERIFY di akhir phase. Setelah diuji, **STOP DAN TUNGGU** persetujuan eksplisit user sebelum lanjut ke phase berikutnya.

### Implementation Phase 1

- GOAL-001: Percakapan Grup punya tab, penanda, dan badge sendiri; aksi yang tidak relevan dinonaktifkan di UI maupun server; tidak ada regresi pada percakapan pribadi.

| Task     | Description                                                                                                                                                                                 | Ref ID          | AC Ref         | Dep      | Files | Completed | Date |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- | -------------- | -------- | ----- | --------- | ---- |
| TASK-001 | **[HIGH RISK — ASSUMPTION-001]** Verifikasi nilai literal `jid_type` untuk grup adalah `'group'` terhadap `aulia_inboxdb` produksi atau log payload `POST /api/inbox/gateway/messages`. Catat hasilnya (nilai literal terkonfirmasi atau berbeda) sebelum lanjut TASK-002. | ASSUMPTION-001  | -              | -        | 0     |           |      |
| TASK-002 | Di `app/Models/ConversationModel.php`, fungsi `withComputedStatus()`: tambahkan assignment `$conversation['queue_status'] = 'grup'` untuk `jid_type === 'group'`, ditaruh **setelah** blok if/elseif `response_state`/`queue_status` yang sudah ada di dalam loop (lihat contoh spec §12), agar tidak tertimpa.                                        | REQ-003         | AC-002         | TASK-001 | 1     |           |      |
| TASK-003 | Di `app/Controllers/Inbox.php`: tambahkan `'grup'` ke konstanta `QUEUE_STATUSES` (baris ~29), lalu di `apiConversations()` pastikan `status=grup` memfilter `jid_type='group'` (filter-after-fetch, tanpa query baru).                                                    | REQ-001, REQ-002 | AC-001, AC-002 | TASK-002 | 1     |           |      |
| TASK-004 | Di `app/Controllers/Inbox.php`, fungsi `apiPerluDibalasCount()` (baris ~316-333): tambahkan `jid_type` ke daftar kolom `select()` yang sudah ada, lalu kecualikan baris `jid_type='group'` dari `count()`.                                                                 | REQ-004         | AC-003         | TASK-002 | 1     |           |      |
| TASK-005 | Di `app/Controllers/Inbox.php`: tambahkan pengecekan `jid_type==='group'` → balas `403` di awal `ambilPercakapan()`, `lepasPercakapan()`, `tutupPercakapan()`, `snoozePercakapan()`, `konfirmasiNomor()`, dan endpoint Edit Profil, mengikuti pola `cekOwnership()` yang sudah ada.                                          | CON-004         | AC-006         | TASK-002 | 1     |           |      |
| TASK-006 | Di `app/Views/inbox/index.php`: (a) tambah tab **Grup** di daftar tab; (b) tampilkan badge teks "Grup" pada baris daftar (dekat `RESPONSE_STATE_LABEL`, baris ~840) dan di header percakapan, terpisah dari judul; (c) untuk `jid_type==='group'`, jangan render tombol Ambil/Lepas/Tutup/Snooze sama sekali; (d) render tombol Konfirmasi Nomor (header, pola `tombolKonfirmasiNomor` baris ~1166) dan Edit Profil (baris daftar kiri) dalam keadaan `disabled`, sementara tombol Hapus di baris yang sama tetap aktif. | REQ-005, REQ-006, CON-001, CON-002, CON-003 | AC-004, AC-005 | TASK-003, TASK-004, TASK-005 | 1 |    |      |
| TASK-007 | **VERIFY**: Jalankan `vendor/bin/phpunit --no-coverage --filter InboxTest` (harus hijau). Tambahkan/gunakan factory conversation `jid_type='group'` (pola `app/Commands/SeedFase1ePerf.php:180`) untuk menguji filter `status=grup`, badge count, dan 403 pada endpoint aksi. Lanjutkan manual check di browser: tab Grup hanya menampilkan grup, badge `perlu_dibalas` berkurang tepat sejumlah grup di data uji, tidak ada tombol Ambil/Lepas/Tutup/Snooze pada grup, dan percakapan pribadi tidak berubah perilaku (regresi nol, AC-007). | -       | -      | -    | -     |           |      |
| TASK-008 | **APPROVAL**: Tunggu konfirmasi eksplisit user bahwa Tahap 1 selesai dan siap lanjut ke `spec-design-grup-tahap2-identitas.md`.                                                                                                                                            | -       | -      | -    | -     |           |      |

## 3. Alternatives

- **ALT-001**: Menambah kolom `is_group` terpisah di `conversations` alih-alih memakai `jid_type` yang sudah ada — ditolak karena melanggar CON (Tahap 1 tidak boleh menambah skema) dan tidak perlu, karena `jid_type='group'` sudah cukup sebagai sumber kebenaran.
- **ALT-002**: Menambah query baru khusus untuk hitung badge grup — ditolak karena melanggar GUD-001 (tidak boleh menambah query, harus filter-after-fetch di atas `findAll()` yang sudah ada).

## 4. Dependencies

- **DEP-001**: Tidak ada dependency library baru — seluruh perubahan memakai kode dan pola yang sudah ada di `Inbox.php`, `ConversationModel.php`, `index.php`.

## 5. Files

- **FILE-001**: `app/Controllers/Inbox.php` — tambah `'grup'` ke `QUEUE_STATUSES`; ubah `apiConversations()`, `apiPerluDibalasCount()`; tambah cek 403 grup di endpoint aksi.
- **FILE-002**: `app/Models/ConversationModel.php` — ubah `withComputedStatus()` untuk set `queue_status='grup'`.
- **FILE-003**: `app/Views/inbox/index.php` — tambah tab Grup, badge penanda, sembunyikan/disable tombol untuk grup.

## 6. Testing

- **TEST-001**: `vendor/bin/phpunit --no-coverage --filter InboxTest` — unit test `ConversationModel` (`jid_type='group'` → `queue_status='grup'`) dan feature/HTTP test (filter `status=grup`, badge count, penolakan 403).
- **TEST-002**: Manual check di browser (localhost XAMPP): tab Grup, badge sidebar, tombol yang hilang/disabled, regresi nol pada percakapan pribadi.

## 7. Risks & Assumptions

- **ASSUMPTION-001** *(dari spec, [ASSUMPTION] tag)*: Nilai `jid_type` untuk grup adalah string literal `'group'`, dikonfirmasi dari kode publik `tikusgot007/WA-Gateway` (`classifyJid()`), tapi **belum diverifikasi terhadap data produksi** karena database lokal sesi ini kosong dari data grup. **TASK-001 (High Risk)** wajib dijalankan lebih dulu untuk memverifikasi nilai ini sebelum TASK-002 dst. dikerjakan.
- **RISK-001**: Kalau nilai literal ternyata bukan `'group'` (mis. berbeda kapitalisasi atau nilai lain), seluruh filter di TASK-002/003/004/005 harus disesuaikan — dampaknya terbatas karena semua ada di 3 file yang sama, tapi wajib dicek ulang sebelum lanjut.

## 8. Related Specifications / Further Reading

- [`spec-design-grup-tahap1-tab-inbox.md`](../spec/spec-design-grup-tahap1-tab-inbox.md)
- [`spec-index.md`](../spec/spec-index.md)
- `spec-design-grup-tahap2-identitas.md` — lanjutan tahap ini
- `docs/CHAT.md` §11 (Lifecycle), §18 (Developer Invariants), §19 (Response State)

## 9. Rollback / Recovery Plan

Jika implementasi Phase 1 gagal atau menyebabkan error kritis pada percakapan pribadi (regresi):
1. `git revert` commit yang mengubah `app/Controllers/Inbox.php`, `app/Models/ConversationModel.php`, dan `app/Views/inbox/index.php` untuk plan ini.
2. Tidak ada migrasi database yang perlu dibatalkan (Tahap 1 tidak mengubah skema).
3. Tidak ada environment variable yang perlu direstore.
4. Jalankan ulang `vendor/bin/phpunit --no-coverage` untuk memastikan kembali ke status hijau sebelum perubahan.
