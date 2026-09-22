# AuliaPos — Priority, Shift & Effective Shift Leader Authority

Single source of truth untuk konsep Priority karyawan, Shift Member, dan Effective Shift Leader — authority dinamis yang dipakai `AULIA.md` (dan, kalau/ketika diperlukan, `CHAT.md`) untuk menentukan siapa boleh melakukan aksi tertentu di luar Admin/kasir biasa.

**Cross-module/cross-version:** dokumen ini satu-satunya sumber kebenaran untuk konsep Priority/Shift Member/Shift Leader. Dokumen modul lain (`AULIA.md`, `CHAT.md`) tidak mendefinisikan ulang konsep ini secara lokal — cukup merujuk balik ke sini dan mendaftarkan kapabilitas spesifik yang diberikan (Section 5).

---

## 1. Tiga Konsep yang Harus Dipisah Tegas

- **Priority** — atribut permanen milik karyawan (Section 2).
- **Shift Member** — status karyawan yang tercatat masuk pada jadwal shift tertentu hari itu, murni dari data jadwal kerja (Section 3).
- **Effective Shift Leader** — authority *sementara/dinamis*, dihitung ON-DEMAND setiap request dari kombinasi Priority + siapa saja yang berstatus Shift Member saat ini (Section 4). **Bukan** role permanen.

| Istilah | Definisi |
|---|---|
| **Role** | Role dasar/permanen akun: `admin` atau `kasir` (`users.role`). Tidak pernah berubah oleh shift atau Priority — tidak pernah bernilai `'shift_leader'`. |
| **Priority** | Nilai prioritas permanen milik karyawan. Lebih besar = lebih tinggi. **Unik** antar-karyawan. |
| **Shift** | Periode kerja dari modul Jadwal (`P`/`S`/`PM`/`L`, lihat `AULIA.md` §8.1 — Employee Schedule → Konsep & mode). |
| **Shift Member** | Karyawan yang berdasarkan jadwal kerja tercatat masuk pada shift tertentu hari itu. **Bukan** soal online/login/check-in/check-out. |
| **Effective Shift Leader** | Shift Member dengan Priority tertinggi yang **sedang berada dalam jendela jam kerja shiftnya saat ini**, di antara SELURUH Shift Member hari itu (bukan per kode shift — lihat Section 4). Kalau tidak ada yang memenuhi syarat → tidak ada Shift Leader saat itu. |
| **Admin** | Di luar hierarchy Priority — otoritasnya selalu di atas Shift Leader maupun Priority berapa pun, dan **tidak pernah** jadi kandidat Shift Leader (lihat Section 4). |

**Yang secara eksplisit BUKAN dasar penentuan Shift Member/Shift Leader:** login ke AuliaPos, status online, check-in, check-out, atau mekanisme kehadiran aktual lainnya. Satu-satunya sumber kebenaran adalah **data jadwal kerja**.

---

## 2. Priority Karyawan

1. Setiap karyawan boleh punya **Priority** permanen: kolom `users.priority` (`SMALLINT UNSIGNED NULL`).
2. Priority melekat pada karyawan, **bukan** pada shift/sesi kerja tertentu.
3. Semakin besar angka, semakin tinggi prioritas (Priority 10 > Priority 7 > Priority 5).
4. Priority **harus unik** antar karyawan — `UNIQUE KEY uq_users_priority`. `NULL` = belum di-ranking (bukan Priority 0), dan MySQL memperlakukan tiap `NULL` sebagai distinct sehingga banyak karyawan unranked bisa hidup berdampingan. Konsekuensi keunikan: tidak pernah ada *tie* saat menentukan Shift Leader.
5. **Diisi lewat Manajemen User** (`/user-management`, admin-only, lihat `AULIA.md` → User Account & Profile) — field opsional saat create/edit. **Field yang dikosongkan pada submit (create maupun edit) selalu menulis `NULL`** — tidak ada mode "kosong = biarkan nilai lama". Validasi keunikan: pre-check PHP (pesan error jelas) + `UNIQUE KEY` DB sebagai otoritas akhir untuk race condition.
6. Priority **tidak pernah** ditulis ke `users.role` — role tetap persis `admin`/`kasir`.
7. Priority sendiri **tidak memberi otoritas apa pun** — ia hanya input ke algoritma seleksi Shift Leader (Section 4). Karyawan Priority tertinggi yang sedang off-shift atau nonaktif tidak punya otoritas tambahan apa pun saat itu.

---

## 3. Shift Member — Dasar Penentuan

"Tercatat masuk pada jadwal shift" ditentukan **sepenuhnya** dari jadwal kerja (tabel `jadwal`, `AULIA.md` §8.2 — Employee Schedule → Database): karyawan berstatus Shift Member untuk hari `H` jika baris jadwalnya di hari itu mencatat dia masuk shift kerja (`P`/`S`/`PM` — bukan `L`).

**Tidak diperlukan** untuk menjadi Shift Member: login ke AuliaPos, status online, check-in/check-out, atau mekanisme kehadiran lain. **Login ke AuliaPos tidak memengaruhi status ini** pada kedua arah — karyawan tercatat di jadwal tetap Shift Member walau belum login; login tidak membuat seseorang otomatis Shift Member kalau jadwalnya tidak mencatatnya di shift itu.

---

## 4. Effective Shift Leader — Algoritma (CURRENT, terimplementasi)

**Satu Shift Leader GLOBAL** untuk seluruh operasional pada satu waktu — **BUKAN** satu Leader per kode shift. Kandidat dari shift `P`/`S`/`PM` yang overlap jamnya masuk **satu pool gabungan**; yang menang adalah Priority tertinggi di antara SEMUA kandidat yang sedang bekerja, apa pun kode shift-nya.

> Rancangan awal (Section 8) sempat mengusulkan satu Leader **per kode shift** (berpotensi banyak Leader simultan kalau P/S overlap) — rancangan itu **tidak pernah diimplementasikan** dan digantikan oleh algoritma satu-Leader-global di bawah ini sebelum kode apa pun untuknya ditulis.

### 4.1 Syarat kandidat (`App\Services\EffectiveShiftLeaderService`)
Semua wajib terpenuhi:
- `users.role = 'kasir'` — **eksplisit**, bukan sekadar `role <> 'admin'`. Admin **tidak pernah** jadi kandidat sama sekali, walau (secara data anomali) punya Priority & jadwal.
- `users.is_active = 1`.
- `users.priority IS NOT NULL`.
- Punya row `jadwal` pada tanggal itu dengan `shift != 'L'`.
- Sedang berada dalam jendela jam kerja shift tersebut saat ini (`App\Services\EvaluasiJendelaKerjaShift`, jam dibaca dari `JadwalModel::DEFINISI_SHIFT` — satu-satunya sumber jam shift, tidak pernah diduplikasi).

### 4.2 Pemilihan
Di antara kandidat yang lolos syarat di atas, **Priority tertinggi menang**. Kalau kandidat Priority tertinggi ternyata sedang di luar jendela jam kerjanya (mis. shiftnya belum mulai/sudah selesai), giliran kandidat Priority berikutnya yang dicek — bukan otomatis "tidak ada Leader".

### 4.3 Tidak ada fallback, tidak ada state tersimpan
Dihitung **on-demand setiap request**, tidak pernah disimpan/di-cache. **Tidak ada** fallback ke Leader sebelumnya, Leader shift lain, atau Admin — kalau tidak ada kandidat yang memenuhi syarat & sedang bekerja, hasilnya `null` (tidak ada Shift Leader saat itu). Pergantian periode shift otomatis memicu penghitungan ulang — tidak perlu perubahan role, logout/login, atau penunjukan manual apa pun. **Tidak ada penunjukan manual** — status ini selalu dihitung, tidak pernah di-set langsung oleh siapa pun.

### 4.4 Info read-only untuk semua role
`GET /roster/shift-leader-saat-ini` (semua role login, tanpa gate admin/kasir) menampilkan siapa Shift Leader saat ini — badge di header (polling 60 detik). **Murni informasi**, dihitung lewat service yang sama, tidak menambah mekanisme otorisasi baru. Dibungkus try/catch fail-closed di titik konsumsi (badge header, endpoint info, DAN `Authority::isCurrentShiftLeader()` itu sendiri) — kalau skema `users.priority`/`jadwal` belum ada di database yang dipakai (mis. migration belum jalan di server), degradasi aman ke "tidak ada Shift Leader" / `false`, **tidak pernah** menjatuhkan operasi lain (termasuk operasi Admin yang sama sekali tidak butuh jawaban ini).

---

## 5. Struktur Hierarchy & Kapabilitas

```
ADMIN
   │
   ▼
EFFECTIVE SHIFT LEADER   (dinamis — Shift Member Priority tertinggi
   │                       yang sedang bekerja; tidak ada kalau tidak
   │                       ada kandidat memenuhi syarat)
   ▼
KASIR BIASA
```

Admin selalu di atas seluruh karyawan berapa pun Priority-nya — Priority tidak pernah membuat karyawan "mengalahkan" Admin.

**Tidak ada warisan otomatis dari Admin ke Shift Leader, dan tidak ada `hasAuthority()`/capability registry/permission-matrix generik.** Setiap kapabilitas Shift Leader didaftarkan **eksplisit per fitur** di bawah ini — menambah kapabilitas baru berarti menambah baris baru di sini (dan di kode), bukan otomatis berlaku dari adanya konsep "Shift Leader" saja.

### Kapabilitas yang SUDAH diberikan ke Effective Shift Leader

| Kapabilitas | Sejak | Detail lengkap |
|---|---|---|
| `PROSES → SELESAI` (workflow umum) | Tahap 3 | `AULIA.md` §2.3 — Transaction Status → Syarat & siapa boleh menyelesaikan |
| `→ BATAL` (proses maupun selesai) | Tahap 5 & 5.1 | `AULIA.md` §2.4 — Transaction Status → Pembatalan |
| Backdate payment + override `kasir_id` | Tahap 5 | `AULIA.md` → Backdate Payment |
| `GET /api/kasir-list` | Tahap 5 | `AULIA.md` §4.2 — Backdate Payment → Siapa boleh & validasi |

### Kapabilitas yang secara eksplisit TIDAK diberikan
- `PROSES → MANGKRAK` / aktifkan kembali — **admin-only murni** (`AULIA.md` §2.5 — Transaction Status → Status MANGKRAK).
- Mutation Jadwal Karyawan (`/jadwal/*`) — admin-only, tidak diperluas.
- Manajemen User (termasuk mengubah Priority siapa pun) — admin-only.
- Archive Transaksi — admin-only.

Enforcement selalu di backend (`App\Services\Authority::isCurrentShiftLeader()`, dikonsumsi controller/model terkait) — UI (tombol, field) hanya lapis pertama.

---

## 6. Cakupan Lintas Modul & Lintas Versi

Dokumen ini berlaku untuk AuliaPos inti (`AULIA.md`) dan, kalau/ketika dibutuhkan, modul Chat/Inbox (`CHAT.md`) serta modul operasional lain yang butuh authority bertingkat — dokumen modul lain tidak boleh mendeklarasikan aturan Priority/Shift Leader versi sendiri, cukup merujuk balik ke sini.

---

## 7. Known Implementation Gaps

- **Chat/Inbox belum memakai konsep ini sama sekali.** `CHAT.md` mendaftarkan "kewenangan spesifik Shift Leader untuk Chat" sebagai item belum dibahas/backlog (lihat `CHAT.md` §17) — tidak ada kode Chat yang membaca `Authority::isCurrentShiftLeader()` per dokumen ini ditulis.
- **Tidak ada mekanisme capability generik** (`hasAuthority()`/permission matrix) — dianggap over-engineering sampai ada kebutuhan nyata lebih dari 4 kapabilitas eksplisit di Section 5. Menambah kapabilitas baru berarti perubahan kode eksplisit + baris baru di tabel Section 5, bukan konfigurasi generik.
- Tidak ada UI admin-facing "daftar kasir aktif yang belum punya Priority" untuk membantu rollout — murni kenyamanan operasional, ditinjau manual lewat Manajemen User.

---

## 8. Historical Design (superseded, tidak diimplementasikan)

Rancangan awal dokumen ini (sebelum implementasi nyata) mengusulkan Shift Leader **per kode shift** (P/S/PM masing-masing bisa punya Leader sendiri secara simultan), dengan larangan eksplisit terhadap fallback lintas-shift. Rancangan itu digantikan sepenuhnya oleh algoritma satu-Leader-global di Section 4 sebelum kode apa pun ditulis untuknya — **tidak pernah berjalan di produksi**. Teks rancangan aslinya (verbatim, termasuk larangan fallback yang secara prinsip masih konsisten dengan Section 4.3) ada di `archive/aturan-bisnis-USER-SHIFT.md`, dipertahankan untuk konteks sejarah keputusan desain, BUKAN sebagai aturan yang berlaku.
