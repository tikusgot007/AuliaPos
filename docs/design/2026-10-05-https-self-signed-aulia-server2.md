# Design: HTTPS self-signed for POS on AULIA-SERVER2 (secure context)

- **Date**: 2026-10-05
- **Status**: implemented (server-side done 2026-10-05; cashier-PC trust + E2E pending)
- **Requirements**: `docs/requirements/2026-10-05-notifikasi-windows-inbox.md` (AC-9)
- **Related**: `docs/design/2026-10-05-notifikasi-windows-inbox.md`, `docs/TODO.md` TODO-N1
- **SDLC tier**: A (infrastructure, production-affecting)

## 1. Summary

Replace the expired default XAMPP certificate on AULIA-SERVER2 with a proper self-signed
certificate whose SAN includes `IP:192.168.1.10` (and `DNS:AULIA-SERVER2`), give it a long
validity, distribute the certificate as a trusted root to the cashier PCs, then switch the
production `app.baseURL` to `https://192.168.1.10/aulia/`. Port 80 stays serving (no
redirect) so the WhatsApp gateway keeps reaching POS over HTTP. This is the prerequisite
that makes the browser `Notification` API (Windows notifications) work for cashiers.

## 2. Current state (verified via read-only recon)

- AULIA-SERVER2 XAMPP shares `\\aulia-server2\xampp` -> `D:\xampp`; POS at
  `D:\xampp\htdocs\aulia` (= `W:\htdocs\aulia`); production HEAD `a127d63` (`v2.4`).
- `apache/conf/httpd.conf`: `Listen 80`, `LoadModule ssl_module`, `Include
  conf/extra/httpd-ssl.conf`, DocumentRoot `D:/xampp/htdocs`, `ServerName localhost:80`.
- `apache/conf/extra/httpd-ssl.conf`: `Listen 443`, vhost `_default_:443`, DocumentRoot
  `D:/xampp/htdocs`, `SSLCertificateFile "conf/ssl.crt/server.crt"`,
  `SSLCertificateKeyFile "conf/ssl.key/server.key"`.
- **Port 443 is already open on 192.168.1.10** (TCP test OK).
- Existing `conf/ssl.crt/server.crt`: `subject=CN=localhost`, self-signed, **expired
  2019-11-08**, **no extensions/SAN** -> browsers reject it, which is why HTTPS is unused.
- `conf` directory ACL: `Everyone FullControl` -> the `xampp` share is writable by us.
- Production `.env`: `CI_ENVIRONMENT = development`, `app.baseURL = 'http://192.168.1.10/aulia/'`,
  `inbox.gatewayBaseUrl = 'http://AULIA3:3000'` (server-side, unaffected).
- No absolute `http://` URLs in `app/Views/inbox/**` or `public/assets/js/**` -> low
  mixed-content risk when the page moves to HTTPS.
- **Remote management is limited**: admin shares (`c$`, `d$`, `admin$`) are denied, WinRM
  is not usable from this PC, and remote service queries fail. We can read/write files via
  the `xampp` share but **cannot restart Apache on server2 remotely**.
- Cashier PC access/identity is not known (who/how many must trust the new cert).

## 3. Options

- **A. Single self-signed leaf certificate with SAN + trust import on each cashier PC**
  (recommended): fewest moving parts; trusted once per PC; renewal in ~10 years needs
  re-import.
- **B. Private local CA (root + server cert)**: re-issuing certs later does not require
  re-trusting each PC, but adds CA management now. More than the current need (YAGNI).
- **C. Brave `--unsafely-treat-insecure-origin-as-secure` flag**: rejected earlier — must
  be set per PC/shortcut and lowers origin protections.

**Recommendation:** A, because it is the smallest change that yields a real secure context
and matches a single-server, few-PC shop.

## 4. Planned changes

On AULIA-SERVER2 (via `\\aulia-server2\xampp`, after explicit approval):

| Target | Change | Reason |
|---|---|---|
| `apache\conf\ssl.crt\server.crt` | Replace with new self-signed cert (SAN `IP:192.168.1.10`, `DNS:AULIA-SERVER2`, `DNS:localhost`; RSA 2048; ~10y) | Current cert expired + no SAN |
| `apache\conf\ssl.key\server.key` | Replace with matching private key | Pair with the new cert |
| (backup) `ssl.crt\server.crt.bak-<ts>`, `ssl.key\server.key.bak-<ts>` | Keep originals for rollback | Rollback safety |
| Apache service (`httpd`) | **Restart** — must be done on server2 by a human (no remote access) | Load the new cert |
| `htdocs\aulia\.env` | `app.baseURL = 'https://192.168.1.10/aulia/'` (after cert trusted + Apache up) | Serve the app over HTTPS |

On each cashier PC (human step; script provided):

- Run `\\aulia-server2\xampp\import-sertifikat-aulia.bat` (self-elevating) to import
  `server.crt` into `LocalMachine\Root`. It uses the .NET `X509Store` API, **not** the
  `Import-Certificate` cmdlet (the PKI module is missing on older Windows/PowerShell), and
  skips if the certificate is already installed.

Repository documentation (this repo):

- `docs/sesi/2026-10-05-https-self-signed-aulia-server2.md` checkpoint + `docs/TODO.md` TODO-N1 update.

HTTP:80 DocumentRoot/vhost stays **unchanged**; no 80->443 redirect is added.

## 5. Impact

- **Database / migrations**: none.
- **Routes / API**: none. Generated URLs become `https://`.
- **Gateway contract**: none **as long as HTTP:80 keeps serving without redirect** — the
  adapter's `CI4_BASE_URL` stays `http://...`. Do not add a 80->443 redirect for `/aulia`.
- **Existing data**: none.
- **Security**: removes reliance on an expired default cert; `CI_ENVIRONMENT = development`
  was observed on production (pre-existing, out of scope; flagged separately). Private key
  lives on a share with `Everyone FullControl` as before.
- **Sessions/cookies**: HTTPS origin differs from HTTP; cashiers re-login once.
- **Mixed content**: low risk (no absolute `http://` in inbox/JS); verify Inbox media and
  profile pictures render over HTTPS.

## 6. Verification plan

1. Before restart: back up old `server.crt`/`server.key`.
2. After replacing certs + Apache restart: `curl -k -I https://192.168.1.10/aulia/` returns
   200 and the presented cert has the expected SAN (`openssl s_client -showcerts`).
3. After trust import on a cashier PC: `https://192.168.1.10/aulia/` loads **without** a
   certificate warning; login works.
4. `Notification.permission` can be requested and Windows notifications appear (AC-1..AC-8).
5. Gateway still `Terhubung` (heartbeat `http://.../aulia`), Inbox messages still arrive.
6. HTTP:80 still serves (no redirect): `curl -I http://192.168.1.10/aulia/` returns 200.

## 7. Risks and mitigations

- Apache restart drops both HTTP and HTTPS briefly (gateway blip) -> do it outside cashier
  peak hours; verify heartbeat recovers.
- Self-signed cert not trusted -> manual import per cashier PC; provide exact command.
- `.env` flipped to https before Apache serves a trusted cert -> breaks the UI. Order is
  strict: replace certs -> restart -> verify -> only then flip `.env`.
- SAN/identity wrong -> verify with `openssl s_client` before announcing.
- Renewal in ~10y -> re-import on each PC (acceptable; documented).
- Anyone browsing by a different host (e.g. `http://AULIA-SERVER2/aulia`) is unaffected
  (HTTP still serves).

## 8. Not yet verified

- How Apache is run/restarted on server2 (XAMPP Control Panel vs Windows service) and who
  can do it.
- Number/identity of cashier PCs that must trust the cert, and whether they are domain-joined
  (GPO option) or standalone.
- Whether any other service/consumer relies on the current (expired) 443 cert.

## 9. Addendum 2026-10-05: realtime WebSocket under HTTPS

Switching to HTTPS broke the Inbox realtime badge: `Inbox::apiRealtimeTicket()` derived the
browser WebSocket URL from `inbox.gatewayBaseUrl`, yielding `ws://AULIA3:3000/realtime`,
which the browser blocks on an HTTPS page (mixed content).

Fix (same-origin proxy):

- server2 Apache: enable `mod_proxy_http` + `mod_proxy_wstunnel`; add
  `ProxyPass "/realtime-ws" "ws://AULIA3:3000/realtime"` in the `_default_:443` vhost.
- Code: new pure `App\Libraries\InboxRealtimeWs::url()`; the controller returns
  `wss://<request-host>/realtime-ws` on secure requests and keeps the direct gateway
  URL on plain HTTP (dev unaffected).
- The gateway's upgrade handler only checks path `/realtime` + ticket, not Origin/Host
  (`src/realtime/server.js:119-128`), so proxying is safe.
- Verified: `GET https://192.168.1.10/realtime-ws` reaches the gateway
  (`{"ok":false,"error":"Not found"}`), and an upgrade without a valid ticket returns
  `401 Unauthorized`. Unit test `tests/unit/InboxRealtimeWsTest.php`.

## 10. Approval (production gate)

- [ ] Approved by: <name>, date: <YYYY-MM-DD>
