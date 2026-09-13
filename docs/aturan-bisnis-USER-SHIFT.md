# Aturan Bisnis: User / Employee / Priority / Shift Leader (Cross-Version)

> **Status: DISEPAKATI, BELUM DIIMPLEMENTASIKAN** (dokumen dibuat
> 2026-09-13, diperbarui 2026-09-13 — definisi "tercatat masuk pada
> jadwal shift" dikunci). Ini adalah dokumentasi **master,
> lintas-versi** untuk konsep Priority karyawan dan Shift Leader
> dinamis. Aturan di sini **bukan** aturan khusus modul Chat/Inbox —
> berlaku untuk AuliaPos v2.x, v3.x, Chat/Inbox, Assignment, dan modul
> operasional lain yang membutuhkan authority bertingkat.
>
> Modul lain (mis. Chat — lihat `CHAT-01-aturan-bisnis-inbox.md` §13)
> **tidak boleh mendefinisikan ulang** hierarchy ini secara lokal.
> Cukup merujuk balik ke dokumen ini.

---

## Daftar Isi

1. [Konsep Dasar](#1-konsep-dasar)
2. [Definisi Istilah](#2-definisi-istilah)
3. [Priority Karyawan](#3-priority-karyawan)
4. [Shift Member — Dasar Penentuan](#4-shift-member--dasar-penentuan)
5. [Shift Leader — Effective Authority](#5-shift-leader--effective-authority)
6. [Struktur Hierarchy](#6-struktur-hierarchy)
7. [Cakupan Lintas Modul & Lintas Versi](#7-cakupan-lintas-modul--lintas-versi)
8. [Di Luar Scope Dokumen Ini](#8-di-luar-scope-dokumen-ini)
9. [Pertanyaan Terbuka / Perlu Keputusan](#9-pertanyaan-terbuka--perlu-keputusan)

---

## 1. Konsep Dasar

Dokumen ini mengatur tiga hal yang harus dipisah dengan tegas:

- **Priority** — atribut permanen milik karyawan (Section 3).
- **Shift Member** — status karyawan yang **tercatat masuk pada
  jadwal shift** tertentu hari itu, murni berdasarkan data jadwal
  kerja (Section 4).
- **Shift Leader** — authority *sementara/dinamis*, muncul dari
  kombinasi Priority + siapa saja yang berstatus Shift Member pada
  shift tersebut (Section 5). **Bukan** role permanen.

## 2. Definisi Istilah

Istilah berikut dipakai konsisten di seluruh dokumen ini — hindari
sinonim yang ambigu (mis. "karyawan aktif", "sedang online", "sedang
login") saat merujuk ke konsep-konsep ini:

| Istilah | Definisi |
|---|---|
| **Role** | Role dasar/permanen akun, mis. Admin atau User/Karyawan. Tidak berubah oleh shift atau Priority. |
| **Priority** | Nilai prioritas permanen yang melekat pada karyawan. Nilai lebih besar = prioritas lebih tinggi. Nilai **harus unik** antar-karyawan. |
| **Shift** | Periode kerja sebagaimana ditentukan oleh sistem jadwal (mis. Pagi/Siang/PM sesuai modul Jadwal — lihat catatan istilah di Section 4). |
| **Shift Member** (karyawan yang **tercatat masuk pada jadwal shift**) | Karyawan yang berdasarkan jadwal kerja tercatat masuk pada shift tersebut pada hari itu. **Bukan** soal online/login/check-in/check-out. |
| **Shift Leader** | Effective authority yang otomatis diberikan kepada Shift Member dengan Priority tertinggi pada shift tersebut. **Bukan** role permanen. Kalau tidak ada Shift Member pada suatu shift → tidak ada Shift Leader untuk shift itu. |
| **Admin** | Berada di luar hierarchy Priority; authority-nya selalu di atas Shift Leader maupun Priority berapa pun. |

**Yang secara eksplisit BUKAN dasar penentuan Shift Member/Shift
Leader:** login ke AuliaPos, status online, check-in, check-out, atau
mekanisme kehadiran aktual lainnya. Satu-satunya sumber kebenaran
adalah **data jadwal kerja** (lihat Section 4).

## 3. Priority Karyawan

1. Setiap karyawan memiliki **Priority** permanen (angka).
2. Priority melekat pada karyawan, **bukan** pada shift atau sesi
   kerja tertentu.
3. Semakin besar angka Priority, semakin tinggi tingkat
   prioritas/otoritas karyawan tersebut.
   Contoh: Priority 10 > Priority 7 > Priority 5.
4. Priority **harus unik** antar karyawan — sistem tidak
   memperbolehkan dua karyawan memiliki Priority yang sama persis.
   (Konsekuensi: tidak pernah ada *tie* saat menentukan Shift Leader —
   lihat Section 5.)

## 4. Shift Member — Dasar Penentuan

> **Catatan istilah (penting):** modul Jadwal yang sudah ada
> (`AULIA-02-modul-pendukung.md` §1, arsip `[AULIA §20]`) memakai kata
> "shift" untuk nilai jadwal harian per karyawan
> (`ENUM('P','S','PM','L')` — Pagi/Siang/PM/Libur) pada tabel
> `jadwal`. Dokumen ini **mengacu ke sumber yang sama persis** — bukan
> konsep baru yang terpisah. "Shift" dan "tercatat masuk pada jadwal
> shift" di dokumen ini **selalu** berarti baris `jadwal` milik
> karyawan tersebut pada hari itu, dengan nilai shift kerja (`P`/`S`/
> `PM` — bukan `L`/Libur). Tidak ada sumber data real-time terpisah
> (bukan "sesi kerja yang sedang berlangsung", bukan status login).

5. **"Tercatat masuk pada jadwal shift"** ditentukan **sepenuhnya**
   berdasarkan jadwal kerja: seorang karyawan berstatus **Shift
   Member** untuk shift `X` pada hari `H` jika dan hanya jika baris
   jadwalnya di hari `H` mencatat dia masuk shift `X`.
6. **Tidak diperlukan** untuk menjadi Shift Member:
   - login ke AuliaPos;
   - status online;
   - check-in;
   - check-out;
   - mekanisme kehadiran aktual lainnya.
7. **Login ke AuliaPos tidak memengaruhi status Shift Member/Leader**
   pada kedua arah:
   - Karyawan yang tercatat masuk pada jadwal shift tetap dianggap
     Shift Member walaupun dia **belum login** ke AuliaPos.
   - Login ke AuliaPos **tidak membuat** seseorang otomatis menjadi
     Shift Member kalau jadwalnya tidak mencatat dia pada shift itu.

**Contoh** (Shift Pagi hari ini, berdasarkan jadwal):

| Karyawan | Jadwal hari ini | Shift Member Shift Pagi? |
|---|---|---|
| Aan | `P` (Pagi) | Ya — ikut kandidat Leader |
| Budi | `P` (Pagi) | Ya — ikut kandidat Leader |
| Citra | `S` (Siang) | Tidak — bukan Shift Pagi |
| Deni | `L` (Libur) | Tidak — tidak masuk shift apa pun |

## 5. Shift Leader — Effective Authority

8. Shift Leader **bukan** role permanen yang melekat pada karyawan.
9. Shift Leader adalah **effective authority** yang muncul berdasarkan
   shift yang sedang berjalan (periode shift hari itu — Pagi/Siang/PM
   sesuai jadwal), **bukan** berdasarkan siapa yang online/login saat
   itu.
10. Untuk setiap shift:
    - Lihat seluruh karyawan yang berstatus **Shift Member** pada
      shift tersebut (Section 4) — yaitu tercatat masuk pada jadwal
      shift itu hari ini.
    - Di antara mereka, Shift Member dengan Priority **tertinggi**
      otomatis menjadi Shift Leader.
    - Shift Member lain pada shift yang sama tetap berstatus User
      biasa.
11. **Jika tidak ada satu pun karyawan yang tercatat masuk pada suatu
    shift (tidak ada Shift Member):**
    - → **tidak ada Shift Leader** untuk shift tersebut.
    - **Dilarang**: mengambil Leader dari shift lain, menjadikan
      Admin sebagai Leader, atau membuat fallback Leader otomatis
      dalam bentuk apa pun. Admin tetap Admin — statusnya sebagai
      Admin sudah cukup (lihat Section 6), tidak perlu "merangkap"
      jadi Shift Leader.
12. **Tidak ada penunjukan Shift Leader secara manual** — status ini
    selalu dihitung otomatis dari jadwal + Priority, tidak pernah
    di-set langsung oleh admin atau siapa pun.
13. Pergantian periode shift (mis. Pagi berakhir, Siang dimulai)
    **otomatis** memicu penghitungan ulang siapa yang menjadi Shift
    Leader berdasarkan jadwal shift yang baru — tidak perlu:
    - perubahan role permanen,
    - logout/login,
    - penunjukan manual apa pun.
14. Karyawan yang **tidak** berstatus Shift Member pada suatu shift
    (mis. sedang Libur, atau jadwalnya di shift lain):
    - tidak ikut menentukan siapa Shift Leader shift itu, dan
    - tidak memperoleh authority khusus shift berdasarkan Priority
      miliknya (Priority-nya "tidur" selama ia bukan Shift Member di
      shift manapun).
15. Dalam satu shift **hanya ada satu** Shift Leader. Karena Priority
    unik (Section 3 butir 4), tidak pernah terjadi *tie*.

## 6. Struktur Hierarchy

16. **Admin berada di luar hierarchy Priority.** Admin selalu berada
    di atas seluruh karyawan, berapa pun Priority karyawan tersebut —
    Priority tidak pernah membuat seorang karyawan "mengalahkan"
    Admin.
17. Struktur authority secara konseptual:

    ```
    ADMIN
       │
       ▼
    SHIFT LEADER   (dinamis — Shift Member dengan Priority tertinggi
       │             pada shift yang berjalan; tidak ada kalau tidak
       │             ada Shift Member)
       ▼
    USER BIASA
    ```

18. Shift Leader memiliki kewenangan tambahan dibanding User biasa,
    namun **tidak semua** kewenangan Admin otomatis diberikan kepada
    Shift Leader. Hak akses Leader harus didefinisikan **secara
    eksplisit per fitur** — tidak ada warisan otomatis dari Admin ke
    Shift Leader.

## 7. Cakupan Lintas Modul & Lintas Versi

19. Konsep Priority/Shift Member/Shift Leader ini adalah
    **CROSS-VERSION BUSINESS RULE** dan menjadi master rule untuk:
    - AuliaPos v2.x
    - AuliaPos v3.x
    - Chat/Inbox (lihat `CHAT-01-aturan-bisnis-inbox.md` §13 — bagian
      itu merujuk balik ke dokumen ini alih-alih mendefinisikan ulang)
    - Assignment
    - Fitur operasional lain yang membutuhkan authority bertingkat

    Dokumen ini adalah **satu-satunya sumber kebenaran** untuk konsep
    Priority/Shift Member/Shift Leader; dokumen modul lain tidak boleh
    mendeklarasikan aturan versi sendiri untuk konsep ini.

## 8. Di Luar Scope Dokumen Ini

20. **Daftar kewenangan spesifik Shift Leader** per fitur (mis. apa
    saja yang boleh dilakukan Shift Leader di Chat, di kasir, dll)
    **belum ditentukan di sini** — akan dibahas dan didokumentasikan
    sebagai tahap terpisah, per modul, setelah konsep dasar ini
    disetujui.
21. Implementasi teknis (query/skema untuk menghitung Shift Member
    dan Shift Leader dari tabel `jadwal` yang ada, caching, dsb)
    **belum dibahas** — dokumen ini murni aturan bisnis/konsep.
    Tidak boleh dianggap sebagai instruksi implementasi.

## 9. Pertanyaan Terbuka / Perlu Keputusan

Bagian ini **sengaja tidak diisi dengan aturan buatan sendiri** —
ditulis di sini supaya diputuskan oleh pemilik produk sebelum lanjut
ke tahap implementasi/kewenangan.

1. Belum ditentukan bagaimana Priority di-assign/diubah (siapa yang
   berwenang mengubah Priority karyawan, apakah lewat halaman
   user-management yang sudah ada, dsb).

> Jangan menjawab pertanyaan di atas dengan asumsi — tunggu keputusan
> eksplisit dari pemilik produk sebelum menambahkan jawaban ke bagian
> ini.
