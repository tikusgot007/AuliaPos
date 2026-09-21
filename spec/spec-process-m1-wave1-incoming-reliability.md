---
title: M1 Gelombang 1 — Keandalan Pesan Masuk WA-Gateway (pesan offline, enqueue, buffer)
version: 1.1
date_created: 2026-09-21
last_updated: 2026-09-21
owner: WA-Gateway reliability (M1)
tags: [gateway, whatsapp, baileys, m1, reliability, incoming, buffer]
---

# Introduction

Spesifikasi ini mendefinisikan **gelombang 1 dari M1 (Reliability)**: memastikan pesan masuk yang sudah diterima WA-Gateway tidak hilang di jalur menuju `incoming_queue`, dan kegagalan di jalur itu tidak lagi senyap. Sasarannya menutup risiko P0 #1 dan #2 di `docs/TODO-CHAT.md` (pesan masuk hilang, dan antrean JSON korup).

Dasar spec ini adalah bukti terukur dari Ticket 01 (`docs/decisions/2026-09-21-m1-ticket01-baseline.md`), audit Ticket 02 (`docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md`, temuan E-01 sampai E-09), dan requirement GW-01 sampai GW-25 (`docs/GATEWAY-REQUIREMENTS.md`). Tidak ada PRD.

## 1. Purpose & Scope

**Tujuan:** pesan yang dikirim pelanggan tidak boleh hilang hanya karena Gateway sedang mati, karena pelanggaran data yang ditolak database senyap, atau karena gangguan sementara pada penyimpanan buffer.

**Audiens:** `/sdlc-clarify-reqs`, `/sdlc-plan-tasks`, dan developer yang mengubah kode WA-Gateway.

**Dalam scope (semuanya kode di repo WA-Gateway):**

- E-01: menerima pesan offline bertipe `append` tanpa mencatat ulang pesan yang Gateway kirim sendiri.
- E-03: `enqueue()` tidak lagi membuang baris diam-diam, dan hasil sisipan diperiksa.
- E-04 dan Ticket 03 (durable buffer): kegagalan simpan dicoba ulang, ditampung sementara, dan dibuat terlihat. Pemeriksaan integritas SQLite saat start.
- E-05: query LID diberi batas waktu supaya tidak menahan penyimpanan.
- E-06: kegagalan sebelum enqueue tercatat lengkap dan tidak mengganggu pesan lain.
- E-09 dan Ticket 04: pemulihan JSON fallback tanpa mulai dari kosong.

## 1.1 Out of Scope

- E-02 (pesan berbungkus seperti ephemeral dan view-once) dan E-07 (upsert tanpa konten): perlu verifikasi dulu.
- Penyebab dekripsi gagal dan urutan timestamp (GW-11 dan GW-25).
- Gelombang 2 (idempotency `/send`, Ticket 09–11) dan gelombang 3 (dead-letter, attempt counter, metrik, Ticket 06–08 dan 13–14).
- Ticket 05, 12, 15, 16.
- Perubahan kode AuliaPos. Idempotensi `wa_message_id` di AuliaPos dipertahankan apa adanya.
- Folder `auth/`, versi Baileys, dan kontrak HTTP ke AuliaPos.

## 1.2 Open Questions & Assumptions

Keputusan yang sudah diambil pemilik proyek:

- **D-01 (E-01):** Gateway menerima semua pesan `append`, **kecuali** ID pesan yang baru ia kirim sendiri lewat endpoint kirim (daftar ID di memori). Dipilih di atas dua alternatif: "terima semua dan andalkan AuliaPos" dan "hanya pesan dari pelanggan".
- **D-02 (E-04):** kalau simpan ke buffer gagal, coba ulang 3 kali dengan jeda singkat, lalu tampung di memori (maksimal 500) disertai error keras. Dipilih di atas alternatif "berkas cadangan di disk" dan "coba ulang saja".
- **D-03 (E-01, klarifikasi 21 Sep):** Gateway menentukan ID pesan **sebelum** mengirim, mencatatnya ke daftar ID kiriman sendiri, lalu meneruskannya ke Baileys lewat opsi `messageId`.
  - Alasan: Baileys memancarkan `append` untuk kiriman sendiri lewat `process.nextTick` tepat sebelum `sendMessage()` mengembalikan hasil, sehingga mencatat ID sesudah kirim bisa kalah balapan.
  - Dipilih di atas "catat setelah kirim dan tunda pengecekan 2 detik" dan "andalkan AuliaPos".
- **D-04 (E-01, klarifikasi 21 Sep):** untuk pesan `append`, hanya alamat berjenis `pn`, `lid`, dan `group` yang diterima. Alamat lain (mis. channel yang terklasifikasi `unknown`) dilewati dan dicatat. Perilaku pesan `notify` tidak diubah.

Hasil klarifikasi: `docs/audit/clarification-report-m1-wave1-incoming-reliability-2026-09-21.md` (Readiness Score 85/100).

> [!WARNING] ASSUMPTION: Spec ditulis dalam bahasa Indonesia, mengikuti spec M3 dan seluruh decision log M1. `AGENTS.md` menetapkan bahasa Inggris untuk dokumen SDLC. Ubah bila diminta.

> [!WARNING] ASSUMPTION: Daftar ID kiriman sendiri disimpan di memori selama 10 menit, maksimal 1000 ID, dikosongkan saat proses berhenti.

> [!WARNING] ASSUMPTION: Batas waktu query LID adalah 2 detik. Jeda coba ulang enqueue 50, 200, dan 800 ms. Penampung sementara berisi maksimal 500 event, dan bila penuh event terbaru dibuang dengan log `critical`.

> [!WARNING] ASSUMPTION: Pesan tanpa field wajib atau tanpa ID tidak disimpan dan tidak diberi ID buatan. Ini dicatat sebagai error keras.

> [!WARNING] ASSUMPTION: Tidak ada pesan "minimal" darurat untuk kegagalan ekstraksi konten (E-06). Kontrak AuliaPos menolak event yang tidak lengkap, sehingga pesan seperti itu akan menjadi pesan beracun yang dicoba ulang tanpa batas, dan dead-letter baru ada di gelombang 3.

> [!WARNING] ASSUMPTION: Pemeriksaan integritas SQLite saat start memakai `PRAGMA quick_check`. Bila gagal, berkas dipindah dengan nama `.corrupt-<waktu>` dan Gateway memulai database baru dengan error keras. `PRAGMA synchronous` diatur eksplisit ke `FULL`.

> [!WARNING] ASSUMPTION: Pemulihan JSON memakai satu cadangan (`.bak`) dari penulisan sebelumnya. Bila berkas utama dan cadangan sama-sama rusak, berkas utama dipindah ke `.corrupt-<waktu>` dan antrean mulai dari kosong dengan error keras.

> [!WARNING] ASSUMPTION: Protokol AC-001: hentikan Gateway dengan `pm2 stop` sekitar 30 detik, kirim 10 pesan dari HP tes saat Gateway berhenti, lalu `pm2 start`. Ulangi 3 kali. Ini menggantikan kill di tengah burst (Ticket 01) yang hanya menyisakan jendela sekitar 3 detik.

> [!WARNING] ASSUMPTION: Pengurasan penampung sementara memakai satu percobaan per event tanpa jeda di setiap siklus worker. Coba ulang berjeda hanya berlaku pada jalur penerimaan pesan.

> [!WARNING] ASSUMPTION: Kegagalan query LID di-cache negatif 60 detik per JID supaya batch pesan tidak menunggu batas waktu berulang kali.

> [!WARNING] ASSUMPTION: Pesan yang tertinggal di database SQLite korup saat start dianggap hilang dari antrean aktif, tetapi berkasnya dipindah (tidak dihapus) untuk diperiksa manual.

> [!WARNING] ASSUMPTION: Istilah "pesan masuk" dan nama `incoming_queue` dipertahankan walau tabel itu juga berisi balasan dari HP (`outgoing`). Belum ada `CONTEXT.md`, jadi pembakuan istilah ditunda.

Terselesaikan lewat klarifikasi: dua sumber `append` lain di Baileys (`messages-recv.js` baris 601 dan 957) adalah pesan turunan notifikasi (grup atau protokol) dan pesan channel. Stub tanpa isi sudah tertahan oleh `!msg.message`, dan alamat `unknown` ditutup oleh D-04.

## 2. Definitions

- **Pesan offline (`append`):** pesan yang WhatsApp kirim ulang saat Gateway tersambung kembali. Baileys memberi tipe `append` (`node.attrs.offline` bernilai benar). Di percakapan sehari-hari disebut "pesan titipan".
- **Pesan `notify`:** pesan yang tiba real-time saat Gateway sedang tersambung.
- **Kiriman sendiri:** pesan yang Gateway kirim lewat endpoint kirim (`/send`, `/send-media`, dan sejenisnya). Baileys juga memancarkannya sebagai `append` (`emitOwnEvents` aktif).
- **Daftar ID kiriman sendiri:** himpunan `wa_message_id` dari kiriman sendiri terbaru, disimpan di memori dengan batas waktu dan ukuran. ID dicatat **sebelum** pesan dikirim.
- **Jenis alamat (`jid_type`):** klasifikasi JID oleh Gateway: `pn` (nomor telepon), `lid`, `group`, atau `unknown`.
- **Buffer / antrean:** tabel `incoming_queue` (atau berkas JSON fallback) yang menampung pesan masuk sebelum diteruskan ke AuliaPos.
- **Enqueue:** memasukkan satu pesan masuk ke buffer.
- **Penampung sementara (overflow):** daftar di memori untuk event yang gagal disimpan ke buffer setelah dicoba ulang.
- **Petunjuk identitas (identity hint):** LID yang terkait sebuah nomor telepon, hasil query `onWhatsApp()`, dikirim sebagai metadata tambahan.
- **Idempoten:** menyimpan pesan yang sudah ada tidak membuat baris baru dan bukan kesalahan.

## 3. Requirements, Constraints & Guidelines

### E-01 — Pesan offline

- **REQ-001**: Gateway MUST memproses event `messages.upsert` bertipe `notify` dan `append`. Tipe lain diabaikan dengan log level debug.
- **REQ-002**: Untuk event `append`, pesan yang `wa_message_id`-nya ada di daftar ID kiriman sendiri MUST NOT dimasukkan ke buffer.
- **REQ-003**: Setiap pengiriman oleh Gateway (teks maupun media) MUST menentukan ID pesan sebelum mengirim, mencatatnya ke daftar ID kiriman sendiri, lalu meneruskannya ke Baileys lewat opsi `messageId`. Pencatatan tidak boleh menunggu hasil `sendMessage()`. Entri kedaluwarsa setelah 10 menit, dan daftar dibatasi 1000 ID (yang terlama dikeluarkan lebih dulu). ID tetap tercatat walau kirim gagal.
- **REQ-004**: Pesan `append` yang bukan kiriman sendiri (termasuk balasan yang diketik dari HP saat Gateway mati) MUST dimasukkan ke buffer dengan arah `incoming` atau `outgoing` sesuai `fromMe`.
- **REQ-005**: Pesan yang sama yang tiba dua kali (mis. lewat `notify` lalu `append`, atau dikirim ulang WhatsApp setelah restart) MUST menghasilkan satu baris dan tidak menghasilkan error.
- **REQ-018**: Untuk event `append`, Gateway MUST hanya memasukkan pesan beralamat `pn`, `lid`, atau `group` ke buffer. Alamat berjenis lain MUST dilewati dan dicatat pada level info dengan JID dan ID pesan. Perilaku event `notify` MUST tidak berubah.

### E-03 — Integritas enqueue

- **REQ-006**: `enqueue()` MUST memvalidasi field wajib (`wa_message_id`, `chat_id`, `jid_type`, `message_type`, `message_timestamp`) sebelum menyisipkan. Kalau ada yang kosong, `enqueue()` MUST melempar error bertipe khusus, dan pemanggil MUST mencatat error keras berisi alasan dan kunci pesan.
- **REQ-007**: `enqueue()` MUST memeriksa hasil sisipan. Jika tidak ada baris baru, ia MUST memeriksa apakah `wa_message_id` sudah ada. Jika sudah ada, itu duplikat sah. Jika belum ada, itu error tak terduga yang MUST dicatat keras.
- **REQ-008**: `enqueue()` MUST mengembalikan status `inserted` atau `duplicate`.

### E-04 dan Ticket 03 — Durable buffer

- **REQ-009**: Bila `enqueue()` melempar error selain validasi, pemanggil MUST mencoba ulang 3 kali dengan jeda 50, 200, dan 800 ms.
- **REQ-010**: Bila semua percobaan gagal, event MUST ditambahkan ke penampung sementara (maksimal 500 event) dan error keras MUST dicatat. Bila penampung penuh, event terbaru dibuang dan log `critical` MUST mencatat jumlah yang dibuang.
- **REQ-011**: Worker pengiriman MUST mencoba memasukkan isi penampung sementara ke buffer di awal setiap siklus, sebelum mengambil event yang jatuh tempo. Setiap event dicoba satu kali tanpa jeda. Event yang berhasil dikeluarkan dari penampung, yang gagal tetap di sana.
- **REQ-012**: Ukuran penampung sementara MUST dicatat di log setiap kali berubah.
- **REQ-013**: Saat start, Gateway MUST menjalankan `PRAGMA quick_check` pada database SQLite dan mengatur `PRAGMA synchronous` ke `FULL`. Bila pemeriksaan gagal, berkas MUST dipindah (tidak dihapus) ke nama `.corrupt-<waktu>` (bersama berkas `-wal` dan `-shm`) supaya dapat diperiksa manual, Gateway MUST memulai database baru, dan error keras MUST dicatat.

### E-05 — Query LID

- **REQ-014**: Query `onWhatsApp()` untuk petunjuk identitas MUST memiliki batas waktu 2 detik. Jika lewat atau gagal, pesan MUST tetap disimpan tanpa petunjuk identitas dan peringatan MUST dicatat.
- **REQ-019**: Kegagalan query LID untuk sebuah JID MUST di-cache negatif selama 60 detik, dan selama itu query untuk JID yang sama MUST dilewati tanpa menunggu batas waktu.

### E-06 — Kegagalan sebelum enqueue

- **REQ-015**: Exception pada pemrosesan satu pesan MUST dicatat pada level error dengan ID pesan, JID, dan tipe konten, tanpa memengaruhi pesan lain dalam batch yang sama.

### E-09 dan Ticket 04 — JSON fallback

- **REQ-016**: Sebelum menimpa berkas JSON buffer, penulisan MUST menyimpan salinan penulisan sebelumnya sebagai cadangan.
- **REQ-017**: Saat memuat, jika berkas utama tidak terbaca, Gateway MUST mencoba cadangan dan mencatat peringatan. Jika keduanya gagal, berkas utama MUST dipindah ke `.corrupt-<waktu>`, antrean mulai dari kosong, dan error keras MUST dicatat berisi ukuran berkas.

### Batasan dan panduan

- **CON-001**: Kontrak HTTP ke AuliaPos (`POST /api/inbox/gateway/messages`) MUST tidak berubah.
- **CON-002**: Perubahan skema `incoming_queue` hanya boleh menambah kolom dan MUST kompatibel dengan database SQLite yang sudah ada.
- **CON-003**: Folder `auth/` dan sesi WhatsApp MUST tidak disentuh oleh implementasi maupun pengujian.
- **CON-004**: Tidak ada dependensi npm baru.
- **GUD-001**: Semua batas (`TTL`, ukuran, jeda, batas waktu) SHOULD dapat diatur lewat variabel lingkungan dengan nilai bawaan di spec ini.
- **GUD-002**: Setiap kegagalan yang menyebabkan pesan tidak tersimpan SHOULD terlihat di log level `error` atau lebih tinggi.

## 4. Interfaces & Data Contracts

Semua antarmuka bersifat internal Gateway. Tidak ada perubahan pada API HTTP.

### 4.1 `incomingBuffer.enqueue(event)`

| Aspek | Kontrak |
|---|---|
| Masukan | Event ternormalisasi: `messageId`, `chatId`, `jidType`, `messageType`, `timestamp` wajib. `direction`, `text`, `media`, `identityHint`, `sender` opsional |
| Keluaran | `{ status: 'inserted' }` atau `{ status: 'duplicate' }` |
| Error | Validasi gagal: error bertipe khusus (tidak ada baris). Kegagalan penyimpanan: error asli diteruskan ke pemanggil |
| Efek samping | Tidak ada baris ditulis untuk event tidak valid |

### 4.2 Daftar ID kiriman sendiri

| Operasi | Kontrak |
|---|---|
| `register(messageId)` | Dipanggil **sebelum** pesan dikirim. Mencatat ID dengan waktu sekarang. Mengeluarkan yang terlama bila melebihi batas |
| `wasSentByUs(messageId)` | `true` bila ID ada dan belum lewat 10 menit |
| Pembuatan ID | Gateway membuat ID sebelum kirim dan meneruskannya ke Baileys lewat opsi `messageId` pada `sendMessage()` |

### 4.3 Penampung sementara

| Operasi | Kontrak |
|---|---|
| `push(event)` | Menambah event. Bila penuh, mengembalikan `dropped` dan mencatat `critical` |
| `drain(tryEnqueue)` | Mencoba tiap event lewat `tryEnqueue`, menghapus yang berhasil, mengembalikan sisa |
| `size()` | Jumlah event tertampung |

### 4.4 Konfigurasi baru (variabel lingkungan)

| Nama | Bawaan | Arti |
|---|---|---|
| `OWN_SENT_TTL_MS` | `600000` | Masa berlaku ID kiriman sendiri |
| `OWN_SENT_MAX` | `1000` | Jumlah maksimum ID |
| `LID_LOOKUP_TIMEOUT_MS` | `2000` | Batas waktu query LID |
| `LID_LOOKUP_NEGATIVE_TTL_MS` | `60000` | Masa cache negatif kegagalan query LID per JID |
| `ENQUEUE_RETRY_DELAYS_MS` | `50,200,800` | Jeda coba ulang enqueue |
| `ENQUEUE_OVERFLOW_MAX` | `500` | Kapasitas penampung sementara |

## 5. Acceptance Criteria

- **AC-001**: Given Gateway dihentikan dengan `pm2 stop` sekitar 30 detik dan 10 pesan pelanggan dikirim selama itu, When Gateway dijalankan lagi (`pm2 start`) dan WhatsApp mengirim ulang pesan tertunda, Then 10 pesan muncul di `incoming_queue` dan AuliaPos (0 hilang, 0 duplikat). Diulang 3 kali.
- **AC-002**: Given Gateway mengirim pesan lewat endpoint kirim dan Baileys memancarkan `append` untuk pesan itu **sebelum** `sendMessage()` mengembalikan hasil (disimulasikan), When event diproses, Then tidak ada baris baru untuk ID pesan itu di `incoming_queue`.
- **AC-003**: Given balasan diketik dari HP saat Gateway mati (bukan kiriman sendiri), When pesan tiba sebagai `append` dengan `fromMe`, Then tercatat sebagai `outgoing` tanpa identitas staff.
- **AC-004**: Given pesan yang sama tiba lewat `notify` dan `append`, When keduanya diproses, Then hanya satu baris dan tidak ada log error.
- **AC-005**: Given event tanpa `messageId`, When `enqueue()` dipanggil, Then error keras tercatat berisi alasan, tidak ada baris tersimpan, dan pesan lain dalam batch tetap diproses.
- **AC-006**: Given sisipan tidak menghasilkan baris padahal ID belum ada (disimulasikan), When `enqueue()` selesai, Then error tak terduga tercatat.
- **AC-007**: Given `enqueue()` gagal dua kali lalu berhasil (disimulasikan), When pesan diproses, Then pesan tersimpan setelah coba ulang dan penampung sementara kosong.
- **AC-008**: Given `enqueue()` gagal terus, When pesan diproses, Then event masuk penampung sementara dan error keras tercatat. When database pulih dan siklus worker berjalan, Then event tersimpan dan penampung kosong.
- **AC-009**: Given penampung sementara berisi 500 event, When ada kegagalan lagi, Then event terbaru dibuang dan log `critical` mencatat jumlah yang dibuang.
- **AC-010**: Given `onWhatsApp()` tidak pernah selesai, When pesan beralamat nomor telepon tiba, Then pesan tersimpan tanpa petunjuk identitas dalam waktu sekitar 2 detik dan peringatan tercatat.
- **AC-011**: Given database SQLite korup saat start, When Gateway dijalankan, Then berkas dipindah ke `.corrupt-<waktu>`, database baru dibuat, error keras tercatat, dan Gateway berjalan.
- **AC-012**: Given berkas JSON utama korup dan cadangan valid, When dimuat, Then antrean pulih dari cadangan dan peringatan tercatat. Given keduanya korup, Then berkas utama dipindah ke `.corrupt-<waktu>` dan error keras tercatat.
- **AC-013**: Given exception pada satu pesan, When batch diproses, Then error tercatat dengan ID pesan, JID, dan tipe konten, dan pesan lain dalam batch tetap tersimpan.
- **AC-014**: Given payload ke AuliaPos sebelum dan sesudah perubahan untuk pesan yang sama, When dibandingkan, Then field identik (kontrak tidak berubah).
- **AC-015**: Given event `messages.upsert` bertipe selain `notify` dan `append`, When diproses, Then tidak ada baris tersimpan dan hanya ada log level debug (REQ-001).
- **AC-016**: Given penampung sementara berubah ukuran (masuk atau keluar), When perubahan terjadi, Then ukuran terbaru tercatat di log (REQ-012).
- **AC-017**: Given pesan `append` beralamat `unknown` (mis. channel) dan pesan `append` beralamat `pn`, `lid`, dan `group`, When diproses, Then hanya tiga yang terakhir tersimpan, dan yang `unknown` dilewati dengan log info (REQ-018). Given pesan `notify` beralamat `unknown`, Then perilakunya sama seperti sebelum perubahan.
- **AC-018**: Given query LID untuk sebuah JID gagal, When pesan kedua dari JID yang sama tiba dalam 60 detik, Then query dilewati tanpa menunggu batas waktu dan pesan tetap tersimpan (REQ-019).

## 6. Test Automation Strategy & Testing Seams

- **Testing Seams**: (1) `_onMessagesUpsert` dengan event Baileys palsu (`notify`, `append`, dan ID kiriman sendiri), dan (2) `incomingBuffer` langsung dengan file SQLite atau JSON sementara. Tidak ada seam ketiga.
- **Test Levels**: skrip integrasi ringan berbasis `assert` (pola `test/simulate-*.js`). Pengukuran akhir memakai uji nyata (`pm2 stop` sekitar 30 detik dengan 10 pesan dari HP tes, diulang 3 kali).
- **Test Data Management**: database dan berkas sementara di folder sementara sistem, dihapus setelah tiap skenario (pola `simulate-reliability-baseline.js` yang sudah ada).
- **CI/CD Integration**: tidak ada pipeline. Skrip dijalankan manual dengan `node`.
- **Coverage Requirements**: setiap REQ punya minimal satu AC, dan setiap AC punya minimal satu skrip uji atau prosedur ukur tertulis. Tidak ada ambang persentase.
- **Pemetaan REQ ke AC**:
  - E-01: REQ-001 ke AC-015, REQ-002 dan REQ-003 ke AC-002, REQ-004 ke AC-003, REQ-005 ke AC-004, REQ-018 ke AC-017.
  - E-03: REQ-006 dan REQ-008 ke AC-005 dan AC-004, REQ-007 ke AC-006.
  - E-04 dan Ticket 03: REQ-009 ke AC-007, REQ-010 ke AC-008 dan AC-009, REQ-011 ke AC-008, REQ-012 ke AC-016, REQ-013 ke AC-011.
  - E-05 dan E-06: REQ-014 ke AC-010, REQ-019 ke AC-018, REQ-015 ke AC-013.
  - Ticket 04: REQ-016 dan REQ-017 ke AC-012.
  - Batasan: CON-001 ke AC-014. GUD-001 diverifikasi lewat pemeriksaan konfigurasi saat review kode.

## 7. Project Structure & Commands

### Project Structure

- Kode ada di repo WA-Gateway, worktree `C:\projects\WA-Gateway-m1`, branch `feature/stage-1-reliability`.
- `src/whatsapp/connectionManager.js`: jalur event masuk, daftar ID kiriman sendiri, query LID.
- `src/store/incomingBuffer.js`: `enqueue()`, penampung sementara, pemeriksaan integritas, pemulihan JSON.
- `src/delivery/incomingDelivery.js`: pengurasan penampung sementara di awal siklus.
- `src/config/index.js`: variabel lingkungan baru.
- `test/`: skrip uji baru dengan pola `simulate-*.js`.

### Commands

- **Build:** tidak ada.
- **Test:** `node test/<nama-skrip>.js` (belum ada test runner).
- **Lint/Format:** tidak ada yang dikonfigurasi.
- **Dev:** `npm run dev` (`node --watch src/app/index.js`). Jangan dijalankan pada Gateway yang sedang aktif.

## 8. Code Style & Conventions

Kode Gateway memakai CommonJS, `'use strict'`, dua spasi, tanda kutip tunggal, komentar dalam bahasa Indonesia, dan pencatatan lewat `logger`. Contoh gaya:

```javascript
'use strict';

const logger = require('../logging');

async function enqueueWithRetry(buffer, event, delaysMs) {
  let lastError;
  for (let attempt = 0; attempt <= delaysMs.length; attempt += 1) {
    try {
      return buffer.enqueue(event);
    } catch (err) {
      lastError = err;
      if (attempt < delaysMs.length) {
        await new Promise((resolve) => setTimeout(resolve, delaysMs[attempt]));
      }
    }
  }
  logger.error('[DELIVERY] GAGAL menyimpan pesan setelah dicoba ulang', {
    messageId: event.messageId,
    error: lastError.message,
  });
  throw lastError;
}
```

## 9. Implementation Boundaries

- **Always do:** menambah skrip uji untuk setiap perubahan, menjaga payload ke AuliaPos tetap identik, membuat semua batas dapat diatur lewat variabel lingkungan, dan bekerja hanya di worktree `C:\projects\WA-Gateway-m1`.
- **Ask first:** menambah kolom atau tabel di `incoming_queue`, menambah dependensi npm, mengubah kontrak HTTP, dan menjalankan uji yang mematikan Gateway aktif.
- **Never do:** mengubah folder `auth/`, menjalankan `git checkout` pada `C:\projects\WA-Gateway` (Gateway aktif), menjalankan uji destruktif pada database aktif, menghapus atau melewati uji yang gagal, dan mengubah kode AuliaPos dalam gelombang ini.

## 10. Rationale, Context & Architecture Decisions (ADRs)

- **E-01:** filter `type !== 'notify'` terbukti menghilangkan pesan (hilang 3/14 dan 3/15). Filter yang sama secara tidak sengaja menahan pencatatan ganda kiriman sendiri, karena Baileys memancarkan kiriman sendiri sebagai `append`. Karena itu filter tidak boleh dilepas begitu saja (D-01).
  - Pencatatan ID harus terjadi sebelum kirim, karena Baileys memancarkan `append` kiriman sendiri lewat `process.nextTick` sebelum `sendMessage()` kembali (D-03).
  - Alamat non-pelanggan pada `append` dilewati (D-04).
- **E-03:** `INSERT OR IGNORE` mengabaikan pelanggaran `NOT NULL`, `UNIQUE`, dan `CHECK` dengan `changes=0` tanpa exception (diuji). Tanpa memeriksa hasil, penolakan senyap tidak terdeteksi.
- **E-04:** Baileys sudah mengirim tanda terima sebelum pesan disimpan, jadi pesan yang gagal disimpan tidak akan datang lagi. Penampung di memori menjaga dari gangguan sementara, tetapi bukan dari crash (D-02). Ini batasan yang diterima.
- **E-05 dan E-06:** memperpendek jendela antara tanda terima dan penyimpanan. Tidak ada pesan darurat untuk kegagalan ekstraksi karena akan menjadi pesan beracun.
- **ADR:** tidak dibuat. D-01 sampai D-04 mudah dibalik, sehingga tidak memenuhi ketiga kriteria (sulit dibalik, mengejutkan tanpa konteks, trade-off nyata) di `.claude/standards/ADR-FORMAT.md`.

## 11. Dependencies & External Integrations

### External Systems

- **EXT-001**: Baileys 6.7.24. Perilaku `append` (`messages-recv.js:699` untuk pesan offline, `messages-send.js:705` untuk kiriman sendiri) dan `onWhatsApp()`.
- **EXT-002**: AuliaPos (`InboxGatewayApi::messages()`). Idempotensi `wa_message_id` dipertahankan dan menjadi jaring pengaman kedua.

### Infrastructure Dependencies

- **INF-001**: Node.js 20 dan `better-sqlite3` (Node 24 tidak didukung). Fallback JSON dipakai bila `better-sqlite3` tidak tersedia.
- **INF-002**: PM2 (`wa-gateway`) sebagai pengelola proses di Aan-PC.

### Data Dependencies

- **DAT-001**: skema `incoming_queue` yang ada (kolom `wa_message_id`, `chat_id`, `jid_type`, `message_type`, `message_timestamp` bersifat `NOT NULL`).

## 12. Examples & Edge Cases

```text
Kasus 1 (E-01, offline):
  Gateway mati -> pelanggan kirim "P1" -> Gateway hidup -> Baileys memancarkan append(P1)
  P1 tidak ada di daftar kiriman sendiri -> disimpan -> terkirim ke AuliaPos.

Kasus 2 (E-01, kiriman sendiri):
  Kasir mengirim balasan lewat POS -> Gateway membuat ID R1 -> register(R1) -> sendMessage(messageId=R1)
  Baileys memancarkan append(R1) (bisa sebelum sendMessage kembali) -> R1 ada di daftar -> dilewati
  (sudah dicatat AuliaPos).

Kasus 3 (edge, balasan dari HP saat Gateway mati):
  Staf mengetik "R2" di HP saat Gateway mati -> hidup lagi -> append(R2, fromMe)
  R2 bukan kiriman sendiri -> disimpan sebagai outgoing tanpa identitas staff.

Kasus 4 (edge, proses restart):
  Daftar ID kiriman sendiri hilang saat restart. Bila WhatsApp mengirim ulang pesan yang sudah
  dicatat, idempotensi wa_message_id di Gateway dan AuliaPos mencegah baris ganda.

Kasus 5 (E-03):
  event.messageId kosong -> enqueue melempar error validasi -> error keras tercatat, tidak ada baris.

Kasus 6 (D-04, alamat non-pelanggan):
  Gateway mengikuti sebuah channel -> Baileys memancarkan append dengan JID @newsletter
  -> jid_type unknown -> dilewati dengan log info, tidak masuk antrean.
```

## 13. Validation Criteria

- Semua AC-001 sampai AC-018 lulus, dengan skrip uji otomatis untuk AC-002 sampai AC-018 dan pengukuran nyata untuk AC-001.
- Pengukuran nyata memakai protokol AC-001 (`pm2 stop` sekitar 30 detik, 10 pesan dari HP tes, `pm2 start`, diulang 3 kali) dan mencatat 0 pesan hilang dan 0 duplikat.
- Sepuluh kiriman lewat `/send` tidak menambah baris baru di `incoming_queue` untuk ID kiriman sendiri.
- Tidak ada `Gateway aktif` yang berubah kodenya selama pengembangan.

## 14. Related Specifications / Further Reading

- `docs/decisions/2026-09-21-m1-ticket01-baseline.md`: hasil pengukuran dan pembuktian E-01.
- `docs/decisions/2026-09-21-m1-ticket02-audit-enqueue.md`: temuan E-01 sampai E-09.
- `docs/GATEWAY-REQUIREMENTS.md`: GW-01 sampai GW-25 (terutama GW-08).
- `docs/CHAT.md`: aturan bisnis Inbox (bagian 2, 4, 6, 7, 9, 14).
- `docs/audit/clarification-report-m1-wave1-incoming-reliability-2026-09-21.md`: laporan klarifikasi (Readiness Score 85/100) dan keputusan D-03 dan D-04.
- `spec/spec-design-m3-operational-inbox-fase1.md`: contoh bentuk spec dan pembagian fase.
