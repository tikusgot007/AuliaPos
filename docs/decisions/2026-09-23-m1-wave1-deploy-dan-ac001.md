# M1 Gelombang 1 — Deploy Gateway Live & Pengukuran AC-001 (2026-09-23)

> **Catatan pembaca:** dokumen ini melanjutkan
> `2026-09-21-m1-wave1-eksekusi-fase1-3.md`. Dikerjakan lewat
> `/sdlc-write-code` mengikuti
> `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (v1.2):
> **TASK-019 (DEPLOY)** lalu **TASK-017 (VERIFY/APPROVAL, AC-001)**.
> Panduan operasional:
> `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md`.
>
> **Legenda bukti:** **Nyata** = Gateway live `C:\projects\WA-Gateway` dengan koneksi
> WhatsApp sungguhan. Sampai 2026-09-21 seluruh bukti AC-002…AC-018 adalah *Simulasi*;
> bagian 1 di bawah adalah bukti **Nyata** pertama di Gelombang 1.

## 1. TASK-019 — Deploy fast-forward ke folder live

Deploy dijalankan **sekali** sesuai TASK-019, hanya berupa `merge --ff-only` +
`pm2 restart` (pengecualian terkontrol CON-005 v1.2). Tidak ada `git checkout`,
tidak ada `npm install`, tidak ada pengeditan kode di folder live, dan `auth/`
tidak disentuh. Perintah verbatim: runbook §3.

| Item | Nilai |
| --- | --- |
| Repo / branch | `C:\projects\WA-Gateway` (folder live) `master` |
| Waktu deploy | 2026-09-23 16:40 WIB (UTC+7) |
| Titik rollback | `e18f716` ("Create node.exe") |
| HEAD sebelum | `e18f716` |
| Perintah deploy | `merge --ff-only feature/stage-1-reliability` |
| Hasil merge | `Updating e18f716..065f683` — **Fast-forward**, exit 0 |
| HEAD sesudah | `065f683` |
| Commit diperoleh | 21 (`git rev-list --count e18f716..HEAD`) |
| Diff merge | 21 berkas, `+2825 / -48` |
| `git status --short` | kosong sebelum **dan** sesudah merge |
| Status PM2 sebelum | `online`, pid 11504, uptime 47m, restarts 0 |
| Status PM2 sesudah | `online`, pid 9740, restarts 1, uptime 21s |
| `script path` PM2 | `C:\projects\WA-Gateway\src\app\index.js` |
| `exec cwd` PM2 | `C:\projects\WA-Gateway` |
| Folder `auth/` | tidak tersentuh (tidak muncul di diff merge) |

Perintah persis yang dijalankan:
`git -C C:\projects\WA-Gateway merge --ff-only feature/stage-1-reliability`
(langkah lengkap: runbook §3).

Commit `065f683` berjudul *fix(buffer): harden JSON fallback backup and
recovery (M1 W1 TASK-015)*. Diff merge menyentuh `src/**`, `test/**`, dan
`docs/decisions/**`. `script path` dan `exec cwd` PM2 tetap menunjuk folder
live, tidak berubah.

Bukti proses baru menjalankan kode `065f683` — dari `logs/gateway.log`
(pid 9740):

- `16:40:19` — `SQLite incoming buffer siap`, `pendingSaatStartup: 0`,
  path `C:\projects\WA-Gateway\data\gateway.sqlite`.
- `16:40:19` — `Gateway starting` (`host 127.0.0.1`, `port 3000`,
  `authFolder C:\projects\WA-Gateway\auth`).
- `16:40:22` — `[DELIVERY] worker pengiriman pesan masuk dimulai`
  (`intervalMs 5000`, `ci4BaseUrl http://localhost/aulia`).
- `16:40:22` — `[HEARTBEAT] worker heartbeat status dimulai` (`intervalMs 15000`).
- `16:40:25` — `Status koneksi berubah menjadi: connected` (nomor `6281913500707`).

**Tidak ada error saat start.** Rollback bila diperlukan (plan §9):
`git -C C:\projects\WA-Gateway reset --hard e18f716` lalu `pm2 restart wa-gateway`.

### 1.1 Titik verifikasi untuk TASK-017

Path DB mengikuti `SQLITE_PATH=./data/gateway.sqlite` di `src/config/index.js`
dengan `cwd` = folder live (`C:\projects\WA-Gateway`).

| Yang diukur | Lokasi nyata |
| --- | --- |
| Tabel `incoming_queue` | `data\gateway.sqlite` di folder live |
| Log aplikasi | `logs\gateway.log` (JSON per baris) |
| Log proses PM2 | `C:\Users\AAN\.pm2\logs\wa-gateway-*.log` |
| HTTP API | `http://127.0.0.1:3000` |
| Endpoint AuliaPos | `http://localhost/aulia` |

Kontrak HTTP ke AuliaPos tetap `POST /api/inbox/gateway/messages` (CON-001).

## 2. TASK-017 — Pengukuran AC-001 (3 percobaan)

Dijalankan 2026-09-23 mulai 16:45 WIB atas persetujuan eksplisit user
(persetujuan dibuka per percobaan: percobaan 2 dan 3 dimulai setelah percobaan
sebelumnya dilaporkan). Protokol: `pm2 stop` → 10 pesan dari HP tes ke
`6281913500707` dikirim beruntun → `pm2 start` → tunggu `connected`.

| Percobaan | Berhenti → `connected` | Diterima | Hilang | Duplikat |
| --- | --- | --- | --- | --- |
| 1 | 16:45:47 → 16:48:12 | 10/10 | 0 | 0 |
| 2 | 16:53:10 → 16:55:37 | 10/10 | 0 | 0 |
| 3 | 16:57:12 → 16:58:34 | 10/10 | 0 | 0 |

Penanda: percobaan 1 `AC001-P1-01`…`-10`; percobaan 2 `AC001-P2-01`
lalu `AC001-P1-02`…`-10` (salah ganti awalan); percobaan 3 hanya `01`…`10`.

**Hasil: 3/3 percobaan, 30/30 pesan, 0 hilang, 0 duplikat.**

### 2.1 Bukti per percobaan

| Percobaan | `incoming_queue` | AuliaPos `messages` | Batch offline | Error |
| --- | --- | --- | --- | --- |
| 1 | `id 116–125` (10) | `id 168–177` (10) | `handled 11` | 1 level-50 |
| 2 | `id 126–135` (10) | `id 178–187` (10) | `handled 10` | 1 level-50 |
| 3 | `id 136–145` (10) | `id 188–197` (10) | `handled 11` | 1 level-50 |

- Angka per percobaan di atas adalah `count(*)` dan `count(distinct
  wa_message_id)` — keduanya sama, yaitu 10.
- Semua baris `incoming_queue`: `jid_type=lid`, `direction=incoming`,
  `status=completed`; `dup_groups=0` di `incoming_queue` maupun AuliaPos.
- Pengiriman ke AuliaPos: 30 log `[DELIVERY] pesan masuk berhasil diteruskan
  ke CI4`, semuanya `duplicate: false`.
- Satu-satunya error level-50 tiap percobaan adalah peringatan Baileys
  `init queries` → `Timed out` ±60 detik setelah `connected`; pola sama sudah
  ada pada boot 08:53 sebelum deploy, dan tidak terkait pemrosesan pesan.
- Tidak ada log skip/`unknown`/overflow/error M1 pada ketiga percobaan.

Total kumulatif setelah pengukuran: `incoming_queue` 127 baris (`max_id 145`),
AuliaPos `messages` 35 baris (33 di antaranya percakapan `4220`), 0 duplikat.

## 3. Interpretasi

- **AC-001 lulus:** 3 percobaan, masing-masing 10/10 pesan muncul di
  `incoming_queue` **dan** di AuliaPos, dengan 0 hilang dan 0 duplikat. Ini
  kriteria kelulusan akhir Gelombang 1 (plan TASK-017, runbook §6).
- Setiap pesan yang dikirim saat Gateway berhenti tersimpan **tepat sekali**:
  30 baris dengan 30 `wa_message_id` unik, tanpa grup duplikat di kedua sisi.
- Kode yang diukur adalah `065f683` (hasil TASK-019), jadi hasil ini sah
  sebagai ukuran kode M1 — bukan kode lama `e18f716`.
- Batas bukti: verifikasi "0 duplikat" memakai `wa_message_id`, bukan teks,
  karena percobaan 2 memang memuat ulang teks penanda dari percobaan 1
  (pesan baru dengan `wa_message_id` baru — bukan duplikat REQ-005).

## 4. Temuan operasional (di luar definisi AC)

- **Log PM2 bisa menyesatkan saat membaca bukti.** `pm2 logs --nostream`
  mencampur isi berkas `wa-gateway-out.log` / `wa-gateway-error.log` yang
  ternyata **stale** (mtime terakhir `2026-09-21 20:46`), sehingga 95 baris
  `Session error: … Bad MAC` yang muncul berasal dari 21 Sep dan **bukan** dari
  restart hari ini (jumlahnya tetap 95 selama 12 detik pengamatan). Log proses
  hari ini ditulis ke `logs\gateway.log` (logger aplikasi).
- **`cmd /c` tidak bisa dipakai apa adanya dari shell bash/MSYS di mesin
  ini.** `/c` diterjemahkan MSYS menjadi `C:\`, sehingga `cmd /c "pm2 …"`
  membuka `cmd` interaktif dan tidak menjalankan PM2. Yang terbukti jalan:
  `cmd //c "pm2 …"` atau `MSYS_NO_PATHCONV=1 cmd /c "pm2 …"`. PM2 terpasang
  7.0.4, dipakai bersama Node v20.20.2.
- **Berkas besar `node.exe` (±87 MB) masih ter-commit di repo WA-Gateway** (`e18f716`,
  "Create node.exe") dan kini ikut ke `master`. Bukan berasal dari Gelombang 1.
- **Sebagian pesan offline tiba menyusul (bukan hilang).** Pola berulang di
  ketiga percobaan: percobaan 1 → 5 pesan pada `connected` + 5 pada +2 m 02 s;
  percobaan 2 → 9 + 1 pada +47 s; percobaan 3 → 6 + 4 pada +2 m 00 s. Semua
  akhirnya tersimpan tepat sekali, jadi tidak ada pesan yang hilang; namun
  pengukuran berikutnya harus menunggu ±2,5 menit setelah `connected` sebelum
  menyimpulkan "hilang".
- **Peringatan `init queries Timed out` milik Baileys muncul ±60 detik setelah
  setiap `connected`** (16:49:12, 16:56:37, 16:59:34) sementara pesan tetap
  tersimpan normal. Sudah ada sebelum deploy (08:53) — bukan regresi M1.

## 5. Referensi

- `plan/plan-process-m1-wave1-incoming-reliability-v1.0.md` (v1.2) —
  TASK-017, TASK-018, TASK-019, §9.
- `spec/spec-process-m1-wave1-incoming-reliability.md` (v1.1) — definisi
  AC-001 (tidak diubah).
- `docs/runbooks/runbook-m1-wave1-task017-ac001-2026-09-23.md` — prosedur
  3 percobaan.
- `docs/decisions/2026-09-21-m1-wave1-eksekusi-fase1-3.md` — eksekusi
  Fase 1–3 (bukti simulasi).
- `docs/decisions/2026-09-21-m1-ticket01-baseline.md` — baseline terukur
  sebelum perubahan.
