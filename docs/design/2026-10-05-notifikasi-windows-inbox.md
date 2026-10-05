# Design: Windows notifications for Inbox (replace in-page toast)

- **Date**: 2026-10-05
- **Status**: approved (Gate 2)
- **Requirements**: `docs/requirements/2026-10-05-notifikasi-windows-inbox.md`
- **SDLC tier**: A

## 1. Summary

Use the browser Notification API (native Windows toast / Action Center) as the primary
delivery for new `perlu_dibalas` conversations, keeping the existing in-page toast strictly as
a fallback when the API is unavailable or permission is not granted. The feature only works in
a secure context, so production additionally needs an HTTPS (self-signed) listener on
AULIA-SERVER2 while HTTP:80 stays untouched for the gateway.

## 2. Current flow (verified)

    GET /inbox/api/notifikasi-ringkas
      -> Inbox::apiNotifikasiRingkas()          (app/Controllers/Inbox.php:506)
      -> ConversationModel::...                 (ownership + perlu_dibalas filter)

    app/Views/layout/main.php:1226  window.INBOX_NOTIF_CONFIG = {ringkasUrl, inboxUrl}
    app/Views/layout/main.php:1231  <script src="assets/js/inbox-notifikasi.js">
    app/Views/layout/main.php:1261  mulaiNotifikasiInbox(20000)
    app/Views/layout/main.php:1191  <div id="inboxNotifToastStack">

    inbox-notifikasi.js:252 muatNotifikasiInbox()
      -> perbaruiJudulTab() / perbaruiFaviconDot()          (keep)
      -> hitungItemBaru()                                   (keep)
      -> mainkanBeepNotif() + hasil.itemBaru.forEach(buatToastNotif)  (branch: Windows vs toast)

Consumers of the JS: `app/Views/layout/main.php` (global layout) and
`tests/js/inbox-notifikasi.test.js`. No server-side contract changes.

## 3. Options

- **A. Notification API from the page (no service worker), toast fallback** — simplest, no new
  dependency, works while the browser/tab is open. Recommended.
- **B. Service-worker Web Push (VAPID)** — works with the browser closed, but needs push
  infrastructure, a service worker, and subscription storage; far larger scope.

**Recommendation:** A, because the requirement is "know while working at the POS PC" and A
needs no new server component; B can be a later upgrade.

## 4. Planned changes

| File | Change | Reason |
|---|---|---|
| `public/assets/js/inbox-notifikasi.js` | Add `notifikasiWindowsTersedia()`, `gunakanJalurWindows()`, `buatOpsiNotifikasiWindows()`, `tampilkanNotifikasiWindows()`, `tutupNotifikasiWindows()`; branch inside `muatNotifikasiInbox()` | Primary Windows delivery + fallback |
| `app/Views/layout/main.php` | Add a "Aktifkan notifikasi" affordance (user gesture) wired to `mintaIzinNotifikasi()`; keep `#inboxNotifToastStack`; keep title/favicon config | Permission requires a user gesture |
| `tests/js/inbox-notifikasi.test.js` | Tests for AC-1..AC-8 (pure decision fns + FakeNotification) | Traceability |
| `docs/CHANGELOG.md` | Operational UI/notification change note | AGENTS.md §15 |

Infra (separate operational step on AULIA-SERVER2, not in this repo):

- Generate a self-signed certificate with SAN `IP:192.168.1.10` and `DNS:AULIA-SERVER2`.
- Add an Apache HTTPS virtual host on 443 for `/aulia`; **keep the HTTP:80 vhost unchanged**.
- Firewall inbound 443 from the LAN.
- Set production `.env` `app.baseURL = 'https://192.168.1.10/aulia/'` (keep
  `forceGlobalSecureRequests = false`).
- Import the certificate into `LocalMachine\Root` (Trusted Root) on each cashier PC.

## 5. Impact

- **Database / migrations**: none.
- **Routes / API / response formats**: none; `/inbox/api/notifikasi-ringkas` unchanged.
- **Gateway contract**: none **provided HTTP:80 keeps serving and is not redirected** — the
  adapter's `CI4_BASE_URL` stays `http://...`. Do not add a 80→443 redirect for `/aulia`.
- **Existing data**: none.
- **Security / validation**: permission is requested only on a user gesture; notification
  text uses `textContent`/`body` from the server-provided `label` only (no `innerHTML`). The
  self-signed cert is only effective if explicitly trusted; a browser warning is expected
  otherwise.
- **Transactions / concurrency**: n/a.

## 6. Test plan

| Acceptance criterion | Test (file::method) | Type |
|---|---|---|
| AC-1 | `tests/js/inbox-notifikasi.test.js` — `gunakanJalurWindows`/`buatOpsiNotifikasiWindows` + FakeNotification | unit (node vm) |
| AC-2 | same file — fallback decision when unavailable / not granted | unit |
| AC-3 | same file — FakeNotification click handler opens conversation + closes | unit |
| AC-4 | same file — `silent: true` when muted | unit |
| AC-5 | same file — notification still created regardless of focus | unit |
| AC-6 | same file — `tutupNotifikasiWindows` on cleanup | unit |
| AC-7 | same file — `mintaIzinNotifikasi` calls `requestPermission` once | unit |
| AC-8 | same file — existing `formatJudulTab`/favicon tests remain green | unit |
| AC-9 | manual: open `https://192.168.1.10/aulia` after infra + gateway smoke on HTTP:80 | manual/integration |

## 7. Risks and mitigations

- Self-signed cert not trusted -> browser blocks Notification; mitigate: import to
  `LocalMachine\Root` on each cashier PC and verify with a real permission prompt.
- Gateway breaks if 80→443 redirect is added -> keep HTTP:80 vhost as-is; verify heartbeat
  `Terhubung` after the change.
- Mixed content: Inbox assets/media loaded over absolute `http://` would be blocked on the
  HTTPS page -> audit `Views/inbox/index.php` and gateway media URLs; prefer same-origin via POS.
- Silent OS notifications (Focus Assist / notifications disabled per-app still let the
  constructor succeed) -> beep is kept on the Windows path as a guaranteed in-page alert;
  a possible overlap with the Windows sound is accepted.
- Browser closed = no notification -> documented limitation (out of scope).

## 8. Not yet verified

- AULIA-SERVER2 Apache/SSL configuration and certificate-trust procedure.
- Number of cashier PCs that must trust the certificate.
- Whether any Inbox asset is fetched over absolute HTTP (mixed-content risk under HTTPS).

## 9. Approval (Gate 2)

- [x] Approved by: user, date: 2026-10-05
