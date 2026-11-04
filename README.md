# VM Playback

A small PHP voicemail player for a browser **and** NetSapiens Web Responder calls. Both players use pitch-preserving FFmpeg `atempo` conversion. Browser speed changes briefly reload audio and resume near the same source position; phone speed changes restart the current message. No Composer, JavaScript build step, database, or external TTS vendor code is needed. Phone prompts use the PBX's configured Voice Services/TTS integration.

## Requirements

- PHP **8.2+**, with cURL, JSON, sessions, and `proc_open` enabled. Tests also need DOM/XML.
- FFmpeg available to the PHP process (`ffmpeg` or an absolute `FFMPEG_BIN`).
- NetSapiens v44.1+ with enhanced Web Responder verbs and Voice Services/TTS enabled for the owning system user/domain.
- An API bearer token permitted to read, save, forward, and soft-delete the configured mailboxes.
- HTTPS hosting reachable by both your browser **and the NetSapiens core/media servers**.

## Setup

1. Copy `.env.example` to `.env` at the project root (not inside `public/`).
2. Set `APP_URL` to the public application URL, including a subdirectory if applicable and no trailing slash, e.g. `https://voice.example.com/vm`.
3. Set `NS_API_TOKEN` **on the server only**. Do not paste it into a URL, browser form, Git, or a support message. Static bearer tokens must be replaced when they expire; automatic OAuth refresh is not implemented.
4. Generate three **different** random secrets and put them in `APP_KEY`, `BROWSER_ACCESS_KEY`, and `WEBRESPONDER_KEY`:

   ```sh
   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
   ```

   Run this separately for each key. `APP_KEY` signs media URLs. The browser access key protects the shared web login; the responder key protects initial phone requests. Do not reuse the API token for these keys.
5. Set `ALLOWED_MAILBOXES`, e.g. `6001@1180.DynaCloud,6002@1180.DynaCloud`. The shared browser key grants access to **all listed mailboxes**. This is not individual-user/PIN authentication. Restrict the API token to the minimum required scope.
6. Set `MEDIA_ALLOWED_HOSTS` to the exact lowercase HTTPS host(s) returned in `file-access-url`. Redirects and non-HTTPS media URLs are rejected. A signed access URL is fetched without forwarding the API bearer token.
7. Ensure `var/` (or `STORAGE_PATH`) is writable by PHP and is private to that OS user. Protect `.env` with filesystem permissions (`chmod 600 .env`). Keep private storage outside the document root and disable PHP error display in production.
8. Set **only `public/` as the document root**. Do not expose the project root, `.env`, `src/`, `tests/`, or `var/` over HTTP. No rewrite rules are required.
9. Confirm soft-delete semantics on a **disposable test mailbox** before enabling trash (see below).

### Trash-only deletion: important

The API's published endpoint is named **Delete Voicemail**, without an explicit soft-delete guarantee in its description. This app implements the supplied server behavior: `DELETE .../voicemails/new/{filename}` or `.../save/{filename}` moves a message to `trash`.

As a deployment safeguard, trash actions are disabled until `NS_DELETE_MOVES_TO_TRASH=true` is set. Verify on your server using a disposable message: move it to trash, list `trash`, and restore it using Save. Then enable the switch.

**The application never sends DELETE for the `trash` folder**, even if someone crafts a request or presses 2 while listening to trash. There is no empty-trash or permanent-delete operation. Saving a trashed message restores it to **Saved**, not New. The correct API folder spelling is `trash`, not `thrash`. Independent PBX retention/purge policies are outside this application's control.

### Local development

```sh
cp .env.example .env
# Fill in the environment values described above.
# For local HTTP only: APP_ENV=development and APP_URL=http://localhost:8080
php -S 0.0.0.0:8080 -t public
```

The built-in server is for development only. Use PHP-FPM with Nginx/Apache for production. A public HTTPS deployment is needed for a real phone test. `APP_ENV=development` permits an HTTP application URL, but upstream API/audio requests still require HTTPS. Never configure a real phone to fetch media from `localhost`.

### Windows / IIS / Apache setup

Set an absolute FFmpeg executable path in `.env`, for example:

```dotenv
FFMPEG_BIN=C:/ffmpeg/bin/ffmpeg.exe
```

Use the actual installation location. Do not include command-line arguments in this value. PHP uses Windows' `NUL` device for child-process I/O (and `/dev/null` on Unix), so no `/dev` directory needs to be created on Windows. `proc_open` must be enabled in the web server's PHP configuration. The web-server service account must have permission to execute FFmpeg and write to `var/`.

Verify the executable in PowerShell:

```powershell
& 'C:\ffmpeg\bin\ffmpeg.exe' -version
```

A successful shell check does not prove the web-server service account has the same access. Prefer an absolute path rather than relying on your interactive user's `PATH`. Restart/recycle the PHP/web-server process after changing its environment or `php.ini`, then refresh the mailbox and select the message again.

For production, set `display_errors=Off` and `log_errors=On` in the **web server's** `php.ini` so PHP warnings do not corrupt JSON/audio responses. Inspect the server's private PHP error log when troubleshooting.

### Example Nginx configuration

Adapt paths, TLS configuration, and PHP-FPM socket to your server:

```nginx
# In the http context:
limit_req_zone $binary_remote_addr zone=vm_login:10m rate=5r/m;

server {
    listen 443 ssl;
    server_name voice.example.com;
    root /srv/vmplayback/public;
    index index.php;
    # Configure your TLS certificate/key here.

    location = /index.php {
        limit_req zone=vm_login burst=10 nodelay;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location / { try_files $uri $uri/ =404; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_read_timeout 120s;
    }
    location ~ /\. { deny all; }
}
```

Use a short upstream API timeout and configure core HTTP timeouts to allow audio preparation. For larger installations, pre-generate audio or use background workers rather than long synchronous PHP requests. WAV downloads are limited to 32 MiB; audio conversion is limited to 30 seconds of wall time and 30 minutes of source audio. Overlong messages are not supported in full.

## Browser use

Open:

```text
https://voice.example.com/index.php?AccountUser=6001&AccountDomain=1180.DynaCloud
```

Sign in with `BROWSER_ACCESS_KEY`. The domain/extension fields can be prefilled through GET parameters, then click **Connect mailbox**. Merely knowing the domain/user is not authorization.

- Browse **New**, **Saved**, or **Trash**. Messages are ordered newest first.
- Select a message, then press the native audio Play control. Browser autoplay is deliberately not required.
- Use **− / +** for 0.50×–2.00× playback, in 0.25× steps. The server prepares pitch-preserving audio at the chosen speed, then the browser resumes near the same source position (with a brief loading pause). Speed carries over to the next message. The native audio playback rate stays at 1× to avoid stretching the speech twice. Use the application’s speed buttons, not the browser’s native speed menu.
- Save, move to trash (with confirmation), or forward to a numeric extension in the same domain. Forwarding may move the original, according to the API; the mailbox is refreshed afterward.
- Save a trashed message to restore it. Trash cannot be deleted again or forwarded until restored.
- Keyboard shortcuts mirror the phone keys when focus is not in an input, button, or dialog.
- Web login lasts eight hours. Log out when finished. Login has a per-IP limit of ten failed attempts per fifteen minutes; add reverse-proxy rate limiting too, especially if PHP sees only a proxy's IP.

All metadata/actions use server-side API calls. The browser never receives the API token or upstream signed media URL. Browser audio uses 48 kHz PCM WAV instead of the telephone-only 8 kHz format. Phone audio remains compatible 8 kHz mono. Speed processing resamples to 48 kHz before stretching and only downsamples for phone delivery. At 1× no tempo filter is run. Upsampling cannot restore detail missing from the original recording; extreme 0.5×/2× speeds can still sound less natural than 0.75×–1.25×.

## Phone / Web Responder setup

Route calls **through a system user** before the To-Web dial translation, as NetSapiens recommends. Confirm which user's identity your core sends as `AccountUser` and `AccountDomain`; those two fields select the mailbox, not ANI/DNIS, `ToUser`, or `ToDomain`.

Configure To-Web with this initial URL (replace the placeholder locally with your responder key):

```text
https://voice.example.com/webresponder.php?key=YOUR_WEBRESPONDER_KEY
```

The core should append/post `AccountUser` and `AccountDomain`. Both GET and form-encoded POST are accepted. You may include an explicit initial mailbox where needed:

```text
https://voice.example.com/webresponder.php?key=YOUR_WEBRESPONDER_KEY&AccountUser=6001&AccountDomain=1180.DynaCloud&folder=new
```

Only allow trusted/internal callers to reach this route. **This is not a voicemail PIN check.** A trusted To-Web URL plus an allowlisted mailbox grants access. Do not expose a fixed mailbox responder to unauthenticated public callers. Restrict responder ingress to trusted core servers at your firewall/proxy where possible. Redact responder/media query strings from access logs because the initial key, callback session IDs, and media signatures are capabilities.

The core needs to:

1. Request the initial XML using the responder key and mailbox parameters.
2. Execute enhanced `<Response>`, `<Say>`, and `<Gather input="dtmf">` with a nested `<Play>`.
3. Fetch the absolute, signed `media.php` WAV URL without browser cookies.
4. Follow the absolute Gather `action` URL and send `Digits`. Callbacks carry opaque, server-side session IDs; **no cookies or PHP session IDs are needed**.
5. Return control with empty `Digits` on a Gather timeout. Validate this behavior on your core version; after three empty callbacks the application ends the call.

### Diagnosing a responder error before playback

If the initial XML contains the fallback Gather but no `<Play>`, the HTTP request reached PHP and passed responder-key and mailbox validation. The failure happened while reading the mailbox or preparing the first message's audio. This is not evidence that the core cannot fetch the public media URL yet; no media URL was returned. XML `&amp;` in a callback attribute is correct escaping.

The error response now identifies mailbox loading vs audio preparation, offers **5 to skip an unplayable message**, and includes a non-secret diagnostic reference in an XML comment. The private PHP error log contains the reference, operation and redacted reason. The same redacted record is saved as `last_error` inside the call's private state file.

On Windows, immediately after reproducing a failed call, run in PowerShell:

```powershell
cd D:\www\vmplayback
Get-ChildItem .\var\calls\*.json |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1 |
    ForEach-Object {
        (Get-Content -LiteralPath $_.FullName -Raw | ConvertFrom-Json).last_error |
            ConvertTo-Json
    }
```

If you set `STORAGE_PATH`, use its `calls` directory instead. The command prints **only `last_error`**. Do not share the entire state file: it contains signed audio URLs and callback capabilities. A missing `last_error` usually means that the selected call never reached this catch, succeeded, or predates the diagnostic update; check the PHP error log and the record's timestamp/reference.

Typical diagnostic results:

- `operation: list` + `HTTP 401` / `403`: check API token expiry and the token's mailbox/domain permissions (the responder key and API token are different credentials).
- `operation: list` + a JSON/list error: the PBX returned an unexpected response shape; inspect that response privately without posting credentials.
- `operation: audio` + download HTTP status / cURL error: check signed upstream media access, the exact allowed media host, and server connectivity.
- cURL error `6` means DNS resolution failed, `7` connection failed, `28` timeout, and `60` certificate verification failed. Configure a trusted CA bundle for the **web server's PHP** rather than disabling TLS verification.
- `operation: audio` + FFmpeg/cache/empty WAV error: verify the absolute `FFMPEG_BIN`, service-account execute/write permissions, and whether this specific voicemail actually contains audio. Press 5 to test the next message.

Retrying playback does not repeat a save/forward operation that succeeded before the following audio failed. A failed folder read leaves the existing folder and messages paired correctly.

Rotate any responder key posted in a trace and invalidate any exposed callback session by removing its corresponding private `var/calls/*.json` file (this ends that session). Do not share replacements or complete callback/media URLs. Production use should have HTTPS and trusted-core ingress restrictions.

### Keypad

| Key | Action |
| --- | --- |
| **1** | Save/restore current message, then advance |
| **2** | Move current message to trash, then advance; blocked in Trash |
| **4** | Ask for a destination extension, then ask for confirmation |
| **5** | Next message without changing its folder |
| **7** | Slower by 0.25× (minimum 0.50×), replay from the beginning |
| **8** | Replay current message |
| **9** | Faster by 0.25× (maximum 2.00×), replay from the beginning |
| **\*** | Folder menu: 1 New, 2 Saved, 3 Trash |
| **0** | End call (outside extension-entry mode) |

For forwarding: enter up to 12 extension digits and wait three seconds for the interdigit timeout. Press star to cancel extension entry. Then press **1 to confirm** or **2 to cancel**. No undocumented `finishOnKey` attribute is used.

The phone first announces the message number/caller, then plays audio inside Gather so DTMF can interrupt it. When audio finishes, the action menu plays. At the end of a folder, the caller can choose another folder or hang up. Listening/skipping does **not** automatically mark a message saved; only key 1 does.

### Phone speed limitations

NetSapiens documents no speed/seek attribute on `<Play>`. This implementation creates a pitch-preserving WAV at the selected speed and returns its HTTP URL to `<Play>`. **A phone speed change restarts the current message; it does not resume at the exact sample position.** This is HTTP WAV delivery through Web Responder, not a bidirectional WebSocket `<Stream>` service. Initial audio preparation/download can add a delay. Repeated playback uses the private cache.

Call state is stored for two hours. The server freezes the mailbox identity at entry and ignores replacement account fields on callbacks. A file lock serializes callbacks; the last twelve XML responses are cached by sequence number so normal core retries do not repeat mutations. A crash between a successful upstream mutation and durable local state storage, or an upstream timeout after a mutation, cannot be made exactly-once without API idempotency support; check the mailbox rather than blindly retrying ambiguous operations. Deploy a single application instance or use shared private storage with working file locks/sticky routing.

## API mapping

Base URL defaults to `https://nms1.nyc.birns.net/ns-api/v2`.

| Operation | Upstream request |
| --- | --- |
| List | `GET /domains/{domain}/users/{user}/voicemails/{folder}` |
| Save/restore | `PATCH /domains/{domain}/users/{user}/voicemails/{folder}/{filename}/save` |
| Forward | `PATCH /domains/{domain}/users/{user}/voicemails/{folder}/{filename}/forward` with `{"voicemail-forward-new-destination":"6002"}` |
| Trash | `DELETE /domains/{domain}/users/{user}/voicemails/{new-or-save}/{filename}`; gated by soft-delete confirmation |

Forward uses the message's **actual current folder**, not a hardcoded `save`. Both current hyphenated JSON fields (as in the supplied response) and older aliases such as `remotepath`, `FromName`, and `NmsAni` are accepted. A single voicemail object or a JSON array is accepted. Unexpected/error-shaped JSON fails closed. No undocumented pagination parameters are sent; if your installation truncates folder listings, confirm its pagination contract before using large mailboxes.

## Private storage and cleanup

Run hourly as the same OS user as PHP:

```cron
0 * * * * cd /srv/vmplayback && /usr/bin/php bin/cleanup.php
```

The cleanup script removes audio unused for four hours, expired call sessions, stale conversion intermediates, and old login-throttle records. Zero-byte audio lock files remain intentionally so concurrent workers always lock the same inode. Monitor disk usage and apply a suitable volume quota. Treat this cache as sensitive voicemail content; use encrypted storage if required by your policies. Signed media links last one hour and work without browser cookies, so treat them as bearer capabilities. An issued link is not immediately revoked by logout or moving the message to trash. Rotate `APP_KEY` to invalidate all outstanding media links.

## Intermittent silence / playback troubleshooting

After updating, hard-refresh the browser (Ctrl+F5). Assets and rendered audio have new version keys, so old narrowband cache files are not reused; no manual deletion of `var/` is needed.

- The browser waits for WAV metadata and a positive duration before reporting audio ready. It reports decode/download failures, loading timeouts, buffering, and stalled downloads instead of silently claiming success.
- **Replay** requests a new signed media link and retries the current message, including when an older link expired. There are no uncontrolled automatic retries. If the browser blocks resuming after a speed change, press the native Play control.
- Empty/header-only and truncated rendered WAVs are rejected. Invalid cached files are removed and re-fetched. This does not detect a legitimate recording containing only silence; compare another message and the original recording in the PBX portal.
- In Developer Tools → Network, check `api.php` and `media.php`. A media `200` or `206` is expected; `403` indicates an invalid/expired link, `404` a missing cache entry, and `422` an invalid WAV. The API returns a readable conversion/download error. Do not share signed media URLs or tokens in screenshots/logs.
- PHP warnings, output buffering and compression can corrupt binary/range responses. `media.php` disables PHP compression and clears output buffers before serving WAV bytes, locks the cache during delivery, and refreshes its last-used time. Keep `display_errors=Off` and check your private PHP log.
- Larger browser WAVs use more disk and bandwidth than narrowband phone files. Keep the existing cleanup job and monitor storage. Genuine upstream silence or poor source quality cannot be repaired by changing playback speed.

## Tests

```sh
find src public tests bin -name '*.php' -exec php -l {} \;
php tests/run.php
php tests/run.php --trash-enabled
php tests/process.php
php tests/audio.php  # Requires native PHP and FFmpeg
node --check public/assets/app.js
node --check public/assets/audio.js
node tests/browser.mjs
```

The offline tests cover request paths and JSON, response normalization, allowlisting/path validation, trash safeguards, signed audio/ranges, XML escaping, DTMF speed/navigation, forward confirmation/cancellation, folder switching, restoration, and timeout/hangup handling. The transport and audio renderer are replaced by test doubles; they do not contact or modify a live PBX. `tests/process.php` separately checks native child-process startup, null-device I/O, and arguments with spaces. `tests/audio.php` renders a synthetic tone with real FFmpeg at all seven speeds, checks duration/pitch/non-silence for browser and phone profiles, and exercises invalid-cache replacement. `tests/browser.mjs` tests metadata readiness, seeking across speeds, native rate 1×, errors, and timeouts using a fake media element (not a real browser decoder). CI runs these suites on Windows and Linux with PHP 8.2 and 8.4. These automated checks cannot establish perceptual voice quality; listening on the actual browser and phone remains necessary.

**Before production:** test an actual call on your core, DTMF interruption, empty Gather timeout callbacks, WAV codec delivery, forwarding to a test extension, and verified trash/restore behavior. Check that media fetches and callbacks work without browser cookies. Network access to the supplied PBX and a usable token are not part of the offline tests.

## References

- [NetSapiens Web Responders v44.4](https://docs.ns-api.com/v44.4/docs/web-responders-v2)
- [Read voicemail](https://docs.ns-api.com/v44.4/reference/readvoicemails)
- [Save voicemail](https://docs.ns-api.com/v44.4/reference/savevoicemail)
- [Forward voicemail](https://docs.ns-api.com/v44.4/reference/forwardvoicemail)
- [Delete voicemail](https://docs.ns-api.com/v44.4/reference/deletevoicemail)
