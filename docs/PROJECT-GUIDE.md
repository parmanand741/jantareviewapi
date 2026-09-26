# JantaReview — Complete Project Reference

**Last verified against the code: 2026-09-26.**
Line numbers drift as the code changes. Every entry names the **function or constant** as well as the line, so if a number is off, search the name.

---

## 0. How to use this document

| If you want to… | Go to |
|---|---|
| Understand the whole system in 2 minutes | §1–§2 |
| Know what a setting does | §3 |
| Find which spreadsheet column holds what | §4 |
| Trace one HTTP request | §5 |
| Understand a guard (bot check, rate limit, encryption) | §6 |
| Find where a **feature** is implemented | §7 (backend) and §8 (frontend) |
| Run a scheduled/CLI job | §9 |
| Run or deploy the app | §10 |
| Diagnose a bug from a symptom | §12 |
| See what is already broken or risky | §13 |

**Warnings are marked ⚠️.** They are places where a small edit silently breaks production data or security.

---

## 1. The system at a glance

JantaReview is an anonymous Indian product/service review portal presented as an IT Act §79 intermediary.

```
Browser (user)                     Admin (owner)
   |                                  |
   v                                  v
frontend  (static HTML+JS, Vercel)   frontend/admin  (static, Vercel)
   |  fetch + cookies + CSRF + Turnstile tokens
   v
backend   (CodeIgniter 4 PHP, Render Docker free plan, behind Cloudflare)
   |  Google Sheets API (service account)
   v
Google Sheet  <-- THE ONLY DATABASE (shared between dev and production)
   |
   +-- Mailjet HTTPS API  (OTP + grievance acknowledgement mail)
   +-- Cloudflare Turnstile (bot check)
```

| Piece | Value |
|---|---|
| Backend code | `C:\wamp64\www\backend` (git repo, branch `main`) |
| Frontend code | `C:\wamp64\www\frontend` (no git — Vercel upload is the only copy) |
| Live API | `https://jantareview-api.onrender.com/api` |
| Live site | `https://jantareview.vercel.app` |
| Datastore | one Google Sheet, tabs named in `app/Config/Review.php:33-45` |
| One API endpoint | `GET`/`POST /api` — behaviour chosen by the `action` value |

**Three things that make this project unusual, and explain most of its design:**
1. **No database.** Every read/write is a Google Sheets API call, so there is a hard quota, no transactions, and retries everywhere (§4).
2. **The dev Sheet and the production Sheet are the same file.** Any local test that writes is writing production data (§3.5, §10.1).
3. **Free hosting.** Render free plan sleeps and has one instance; sessions and rate limits live on local disk, so a restart partly resets them (§6.4, §9).

---

## 2. Directory map

### Backend (`C:\wamp64\www\backend`)

```
app/
  Controllers/
    Api.php              the whole HTTP surface: CORS, CSRF, origin, throttling, dispatch table
    BaseController.php   stock CI4, nothing custom
    Home.php             GET / -> default CI4 page (unused)
  Config/
    Review.php           ALL project settings (env -> typed properties)  <-- start here for config
    Routes.php           one route: match(GET,POST,OPTIONS,'api', 'Api::index')
    Security.php         CSRF: regenerate=false, redirect=false (JSON API)
    Filters.php          csrf applied globally as a 'before' filter
    App.php              baseURL, proxyIPs (127.0.0.1, ::1)
    Cookie.php           COOKIE_SECURE / COOKIE_SAMESITE read from env
    Session.php          FileHandler, ci_session, 7200s, matchIP=false
    Logger.php           threshold production?4:9 + ErrorlogHandler (⚠️ local drift, never commit)
    Cors.php             UNUSED (legacy CI4 file)
  Libraries/Legacy/      the real application (no CI4 models/services exist)
    Core.php             Config loader+validation, Request, Response, Admin gate
    Security.php         Turnstile + RateLimit
    Helpers.php          Crypto, Validator, Ids, Time
    Sheets.php           every Google Sheets call
    PublicHandlers.php   all public features (reviews, vote, report, grievance, transparency)
    AdminHandlers.php    all admin features
    ProductLink.php      product-URL validation (structure + live probe)
    Audit.php            Mod_Log writer/reader + integrity chain + public projection
    Otp.php              email verification
    Mailer.php           Mailjet send
    Signals.php          spam/duplicate text detection
    Retention.php        90/180-day purge
    Backup.php           CSV export/restore of all tabs
  Commands/              5 `php spark` commands (§9)
  Views/errors/          CI4 error pages
tests/unit/              8 PHPUnit files (§11)
public/                  front controller + .htaccess (the only web-served folder)
writable/                sessions, logs, rate-limit buckets, OTP files, backups (⚠️ ephemeral on Render)
docker/                  entrypoint.sh + apache-vhost.conf
render.yaml              Render infrastructure-as-code
Dockerfile               php:8.2-apache image
.github/workflows/       nightly-maintenance.yml (§9.6)
key.txt                  ⚠️ credential file, unused by code, still on disk (§13.7)
```

### Frontend (`C:\wamp64\www\frontend`)

```
index.html        the whole site: 865+ lines of markup + inline theme guard + script tags
app.js            ~3350 lines: every feature, all in one classic script
config.js         production API URL + Turnstile sitekey  (safe to upload)
config.local.js   local API override  ⚠️ NEVER upload to Vercel
admin/index.html  admin console markup
admin/admin.js    admin console logic (~1800 lines)
styles.css        hand-written CSS (variables, component classes)
tailwind.css      ⚠️ COMPILED output — regenerate, never hand-edit
frontend-build/   (sibling folder) tailwind.config.cjs + package.json + check-coverage.cjs
frontend-deploy/  (sibling folder) manual Vercel upload snapshot — can go stale
```

There is **no framework, no router, no bundler, no npm runtime** in the frontend. `app.js` is loaded as a plain script and everything is a global function.

---

## 3. Configuration

### 3.1 How config is loaded

`app/Config/Review.php` is a CI4 config class. Its constructor (`:94-163`) reads every env var once. `App\Libraries\Legacy\Config::load()` (`Core.php:14-76`) then copies those properties into a flat static array that the legacy handlers use through `Config::get('key')`.

### 3.2 Hard requirements — the API refuses to boot without these

`Config::validate()` — `Core.php:86-119`:

| Requirement | Line | What happens if missing |
|---|---|---|
| `REVIEW_SPREADSHEET_ID` | `:88-93` | boot throws |
| `GOOGLE_APPLICATION_CREDENTIALS` (file must exist) | `:103-109` | boot throws, names the expected path |
| `REVIEW_ENCRYPTION_KEY` **≥ 32 chars** | `:94-96` | boot throws — and all stored review text becomes undecryptable |
| `REVIEW_ADMIN_KEY` | `:88-93` | boot throws |
| `TURNSTILE_SECRET` | `:88-93` | boot throws |
| `REVIEW_ALLOWED_ORIGINS` non-empty | `:97-99` | boot throws |
| `REVIEW_TURNSTILE_HOSTS` non-empty | `:100-102` | boot throws |
| ⚠️ Credentials must **not** live under `public/` | `:110-118` | boot throws on purpose |

### 3.3 Tuning knobs (env name → default → meaning)

All in `app/Config/Review.php:96-163`. These are the numbers that define how the site behaves.

**Moderation** — `:118-136`
| Env | Default | Meaning |
|---|---|---|
| `REVIEW_UNPUBLISH_THRESHOLD` | `-15` | net score at which the community auto-archives a review |
| `REVIEW_RESCUE_THRESHOLD` | `-10` | score needed to come back |
| `REVIEW_RESCUE_HYSTERESIS_STEP` | `2` | each archive cycle raises the rescue bar by this much |
| `REVIEW_REPORT_CEILING` | `15` | reports that force-archive |
| `REVIEW_QUARANTINE_SECONDS` | `1800` | votes count but cannot change status in the first 30 min |
| `REVIEW_DEDUPE_WINDOW_SECONDS` | `86400` | identical-text check window |

**Rate limits** — `:118-137`
| Env | Default | Meaning |
|---|---|---|
| `REVIEW_VOTE_COOLDOWN_SECONDS` | `60` | per-review review-wide cooldown |
| `REVIEW_VOTE_USER_COOLDOWN_SECONDS` | `300` | per-device gap between any two votes |
| `REVIEW_VOTE_PER_HOUR` | `10` | per-device votes/hour |
| `REVIEW_SUBMIT_PER_HOUR` | `20` | per-device submissions/hour |
| `REVIEW_REPORT_PER_HOUR` | `5` | per-device reports/hour (free tier) |
| `REVIEW_REPORT_COOLDOWN_SECONDS` / `REVIEW_REPORT_TIER_SLOW_SECONDS` | `18000` | spacing after the free tier is used |
| `REVIEW_LINK_CHECK_PER_HOUR` | `25` | per-device link checks/hour |
| `REVIEW_READ_PER_MINUTE` | `120` | per-IP feed/stat reads/minute |
| `REVIEW_OTP_PER_IP_PER_DAY` | `20` | per-IP OTP sends/day |
| `OTP_REQUESTS_PER_DAY` | `3` | **per email address** OTP sends/day |
| `OTP_TTL_SECONDS` / `OTP_MAX_ATTEMPTS` | `600` / `5` | code lifetime |
| `REVIEW_TICKET_MIN_AGE_SECONDS` | `3` | minimum seconds between OTP verify and submit |
| `REVIEW_GLOBAL_CAPS` | submit 300, vote 3000, report 600, urlcheck 600, suggestion 300, grievance 200, otp 400 | whole-site hourly ceilings (`:65-73`, `:173-183`) — `0` disables |
| `REVIEW_GRIEVANCE_PER_HOUR` | — | per-device grievances/hour |

**Retention & officers** — `:141-163`
`REVIEW_RETENTION_DAYS` 90, `REVIEW_AUDIT_RETENTION_DAYS` 180, `GRIEVANCE_OFFICER_NAME|EMAIL|PHONE|ADDRESS`, `GRIEVANCE_ACK_SLA_HOURS` 24, `GRIEVANCE_RESOLVE_SLA_DAYS` 15, `GRIEVANCE_PRIORITY_TAKEDOWN_HOURS` 36, `GRIEVANCE_NOTIFY_ENABLED` (mail off unless true), `REVIEW_ADMIN_ALLOWED_IPS` (empty = console reachable from anywhere), `REVIEW_ADMIN_TTL_SECONDS` 1800, `REVIEW_STORAGE_PATH`, `REVIEW_EXPOSE_ERRORS` (⚠️ leaks exception text to clients), `TURNSTILE_USE_TEST_KEYS`, `TURNSTILE_BYPASS_FOR_LOCAL_DEV`.

### 3.4 Local vs production values that differ

| Setting | `.env` (local) | `render.yaml` (production) |
|---|---|---|
| `COOKIE_SECURE` | `false` | `true` ⚠️ FROZEN |
| `COOKIE_SAMESITE` | `Lax` | `None` ⚠️ FROZEN |
| `TURNSTILE_BYPASS_FOR_LOCAL_DEV` | `true` | must never be set |
| `CI_ENVIRONMENT` | `development` | `production` |

⚠️ `COOKIE_SECURE=true` + `SAMESITE=None` are a pair: CI4 throws if `None` is combined with `Secure=false`. They are required because the site (`jantareview.vercel.app`) and the API (`onrender.com`) are different origins.

### 3.5 ⚠️ The Sheet is shared with production

There is no dev spreadsheet. Anything you test locally that **writes** — submitting a review, voting, reporting, filing a grievance, an OTP request, a rate-limit hit — writes real production rows. Read-only actions are safe.

---

## 4. The datastore (Google Sheets)

### 4.1 The access layer — `app/Libraries/Legacy/Sheets.php`

| Function | Line | Notes |
|---|---|---|
| service bootstrap | `:24-48` | `Google\Client` + `setAuthConfig(credentials_path)`, SPREADSHEETS scope, singleton |
| `findCaBundle()` | `:50-66` | locates a CA bundle for curl; reused by Turnstile (`Security.php:42`) and Mailer (`Mailer.php:58`) |
| `read(tab, range)` | `:77-102` | 6 attempts; Google 400/404 = "empty sheet" → `[]` |
| `append(tab, values, 'RAW')` | `:114-137` | returns the new row number |
| `write` / `setCell` / `batchWrite` | `:142 / :159 / :178-203` | many ranges in one call |
| `clear` | `:208-218` | |
| `deleteRows` | `:231-278` | merges contiguous runs, applies **high-to-low**, refuses row ≤ 1 |
| `addSheet` / `tabIds` / `columnLetter` | `:284 / :327 / :303` | |
| `ensureSheet` | `:383-403` | creates a missing tab + header (writes to production) |
| `withRetries` | `:346-378` | 6 attempts; retryable = 408/429/500/502/503/504; delay `min(8, 2^n)` s |

⚠️ **Every write uses `RAW` input option.** `USER_ENTERED` makes Sheets re-parse dates, numbers and list-looking strings, which silently corrupts ISO timestamps and the epoch lists in `Rate_Limits`. Never change an input option.

⚠️ **A Google `403` is not retried** — it is thrown immediately and `Api.php:76-78` converts it to `503 "The data service is temporarily unavailable."` That usually means the Sheet is not shared with the service-account email, or quota is exhausted.

⚠️ **`deleteRows` is deliberately attempted once, never retried.** A retry after a lost response would delete whatever rows shifted into those positions.

### 4.2 Tab-by-tab column maps

Tab names: `app/Config/Review.php:33-45`.

**Reviews** (`A..U`, 21 columns) — written at `PublicHandlers.php:139-162`
| Col | Field | Written by |
|---|---|---|
| A | id `REV-XXXXXX` / reply `RPL-XXXXXX` | `Ids::make` |
| B | created ISO | |
| C | productUrl (validated HTTPS) | |
| D | imageUrl | |
| E | stars 1–5 | |
| F | **AES-256-GCM ciphertext** of the text | `Crypto::encrypt` |
| G / H / I | helpful / notHelpful / netScore | `vote` |
| J | status `published`\|`archived` | vote, report, admin |
| K | reportCount | `report` |
| L | productName | |
| M | lastVoteAt | `vote` (per-entry cooldown) |
| N | parentId | replies |
| O | contentHash (unkeyed) | never published publicly any more |
| P | submitterToken (keyed IP hash) | ⚠️ never display |
| Q | idempotencyKey | double-submit protection |
| R | platform | |
| S | archiveCycles (rescue hysteresis) | auto-archive |
| T | textHash (keyed) | duplicate detection |
| U | advisory spam flags, CSV | `Signals::flags` |

⚠️ `submitReview:34-46` self-repairs the header row `Q1:U1` if those columns are missing — meaning someone can add columns in the Sheet UI and the code heals them. But `Signals::duplicateOf` and every `read()` range use **fixed column indexes**, so inserting/reordering a column silently breaks dedupe and moderation. Never reorder; only append.

**Deleted_Reviews** (`A..Q`) — `AdminHandlers.php:113-141`; M deletedAt, N restoredAt, O parentId, P rule, Q caseId. ⚠️ `contentHash`, `submitterToken`, `textHash` and `flags` are **not carried across** (`AdminHandlers.php:242-245`), so a restored review loses dedupe protection.

**Reported_Reviews** (`A..G`) — `PublicHandlers.php:613-621`: repId, timestamp, reviewId, productUrl, reason, text, `reporter_key` (per-reporter dedupe; header self-heals at `:582-585`).

**Grievances** (`A..J`) — `PublicHandlers.php:700-724`: caseId, filedAt, ackAt, resolvedAt, reviewId, category, description, **encrypted contact email** (`:721`), status, resolutionNote.

**Anonymous_Grievances** (`A..I`) — same without the email column (`:695-698`).

**Suggestions** (`A..F`) — `:900-906`: suggestionId, submittedAt, category, suggestion, submitterToken, status.

**Mod_Log** (`A..N`) — `Audit.php:12-26`: A actionId, B timestamp, C reviewId, D productName, E productUrl, F stars, **G permanently blank (retired plaintext)**, H reportCount, I ruleOrRestore, J actionType, K actor, L reason, M relatedId, N contentHash. ⚠️ All reads are `A2:N`; adding a 15th column requires widening every range (`Audit.php:38, 51, 171, 198`).

**Rate_Limits** (`A..C`) — `Security.php:204`: `rl_key` (HMAC of scope+identity), scope, hits = CSV of unix epochs, trimmed to 50.

**Votes** (`A..D`) — `PublicHandlers.php:370`: `vote_key`, `review_id`, `vote_type`, `voted_at`. This is the permanent per-voter ledger (§7.5).

**Integrity_Checks** (`A..E`) — `Audit.php:225-226`: checkedAt, kind, rowCount, digest, note.

**Plateforms** (single column of platform names) — ⚠️ the tab-name typo is **load-bearing**; it is what the Sheet actually contains.

---

## 5. Request lifecycle

```
browser fetch  ->  Cloudflare  ->  Render container  ->  Apache (DocumentRoot = public/)
   -> public/.htaccess rewrite -> public/index.php -> app/Config/Routes.php:7
   -> App\Controllers\Api::index()
```

`Api::index()` — `app/Controllers/Api.php:26-90`, in strict order:

| Step | Line | Behaviour |
|---|---|---|
| 1. CORS + security headers | `:32-33` → `applyCors()` `:180-196` | exact-match origin allowlist; sets ACAO, credentials, `Vary: Origin`, `nosniff`, `Cache-Control: no-store`. Runs **first** so `health` is CORS-readable |
| 2. Health short-circuit | `:38-41` | `GET ?action=health` answered **before** config validation, so an uptime probe still works when the config is what is broken |
| 3. Build `Request` | `:43` | parses method, origin, IP, body, action; derives the device identity (`Core.php:139-158`) |
| 4. `OPTIONS` | `:44-46` | bare 204 |
| 5. Origin gate | `:47-52` | POST with no/foreign `Origin` → 403 "Origin is not allowed."; foreign origin on GET → 403 |
| 6. CSRF | `Config/Filters.php:74-84` (global before filter) | POST needs `X-CSRF-TOKEN`; token served by `GET ?action=csrf` (`Api.php:55-60`). `regenerate=false` (`Config/Security.php:74`) |
| 7. Read throttle | `throttleReads()` `:98-110` | 120/min per **IP** for the 5 read actions + 3 GETs |
| 8. Dispatch | `dispatchGet` `:112-121` / `dispatchPost` `:123-158` | `match` on action name |
| 9. Envelope bridge | `:71-88` | handlers are `never`-returning: `Response::json()` throws `ApiResponseException` (`Core.php:122-128, 236-251`) which is caught and turned into JSON. Uncaught `Throwable` → 500 (503 for transport/Google-403) |

⚠️ Adding a feature means three edits: the handler, the `dispatchPost` arm (`Api.php:123-158`), and — if it is a cheap read — the throttle list at `Api.php:98-104`.

### Complete action table

| Action | Method | Handler | Auth guards |
|---|---|---|---|
| `health` | GET | `PublicHandlers::health` `:1059` | none (pre-config) |
| `csrf` | GET | `Api.php:55-60` | none |
| `info` / `''` | GET | `info` `:1064` | origin |
| `compliance` | GET | `getCompliance` `:1035` | origin |
| `platforms` | GET | `getPlatforms` `:310` | origin |
| `getReviews` | POST | `:263` | read throttle |
| `getStats` | POST | `:971` | read throttle |
| `getTransparencyLog` | POST | `:911` | read throttle |
| `getReviewHistory` | POST | `:916` | read throttle |
| `getTransparencyReport` | POST | `:923` | read throttle |
| `checkProductUrl` | POST | `:217` | global cap + 25/hr |
| `submitReview` | POST | `:9` | full stack (§7.2) |
| `requestReviewOtp` | POST | `Otp::request` `:12` | Turnstile + 3 caps |
| `verifyReviewOtp` | POST | `Otp::verify` `:61` | session binding |
| `vote` | POST | `:324` | full stack (§7.5) |
| `report` | POST | `:525` | full stack (§7.6) |
| `fileGrievance` | POST | `:655` | full stack (§7.8) |
| `submitSuggestion` | POST | `:884` | global cap + 5/hr |
| `warmUp` | POST | `:1013` | ⚠️ `Admin::require()` |
| `adminLogin` / `adminSession` / `adminLogout` | POST | `Api.php:160-178` | key / session |
| `adminGetDashboard` | POST | `AdminHandlers:9` | session |
| `adminListGrievances` / `adminListSuggestions` | POST | `:289 / :330` | session |
| `adminDeleteReview` / `adminRestoreReview` / `adminRestoreFromDeleted` / `adminAcknowledgeGrievance` / `adminResolveGrievance` / `adminUpdateSuggestionStatus` | POST | `:77 / :174 / :210 / :380 / :434 / :351` | ⚠️ session **+ step-up key** |

---

## 6. Security mechanisms

### 6.1 Visitor identity (`Request::identity`) — `Core.php:165-172`
`substr(hmac_sha256("id|" + ip, identity_pepper), 0, 20)`. Keyed server-side, so a visitor cannot mint or rotate identities by changing their User-Agent. Stored in Reviews column P. ⚠️ If `REVIEW_IDENTITY_PEPPER` is unset it silently falls back to the encryption key (`Core.php:21`) — changing the encryption key therefore resets every device's history.

### 6.2 Client IP resolution — `Core.php:184-221`
Trust order: `CF-Connecting-IP` → `True-Client-IP` → `X-Forwarded-For` scanned **right-to-left**, but only if `REMOTE_ADDR` is in `App::$proxyIPs` → `REMOTE_ADDR` → `0.0.0.0`.
⚠️ **FROZEN:** `App::$proxyIPs` must keep `127.0.0.1` and `::1` (`Config/App.php:197-200`) or the container's own proxy breaks IP resolution. `X-Real-IP` is deliberately **not** trusted (an origin-bypass header).

### 6.3 Turnstile (bot check) — `Security.php:7-77`
`verify(token, expectedAction, ip)` posts to `challenges.cloudflare.com/turnstile/v0/siteverify` with `remoteip`, 10 s timeout, SSL verification, IPv4, located CA bundle. Three checks after the call: **hostname allowlist** (`:59-67`, env `REVIEW_TURNSTILE_HOSTS` — this is the real defence against a stolen sitekey), **action match** (`:71-73`), and success. Local bypass (`:19-22`) only when `TURNSTILE_BYPASS_FOR_LOCAL_DEV=true` **and** host is `localhost`/`127.0.0.1`/`reviews.local`. Actions used: `submit`, `vote_helpful`, `vote_not_helpful`, `report`, `grievance`, `otp`.
⚠️ The backend never serves the sitekey — it lives in `frontend/config.js:19`. Rotating keys means touching both repos.

### 6.4 Rate limiting — `Security.php:79-329`
Two very different buckets:

| | `checkLocal()` `:122-160` | `checkWindow` / `checkHourly` / `checkSpaced` `:91-114` |
|---|---|---|
| Storage | file `writable/review/rlc_<sha>.json` + flock | `Rate_Limits` Sheet tab |
| Survives a restart | ❌ no (⚠️ and `writable/` is ephemeral on Render) | ✅ yes |
| On error | **fails open** (`$max<1` → allow; unwritable disk → allow) | **fails closed** → exception → 503 |
| Cost | free | 1+ Sheet reads, quota-burning |

So the guard order in every handler is deliberate: **cheap local bucket first → Turnstile → Sheet-backed limits → Sheet work.** `checkSpaced` returns `'ok' | 'gap' | 'cap'` and only records a hit on `ok` (`:106-114`). Keys and windows are summarised in `knownWindows()` `:242-256` (⚠️ `report` uses an 86400 s window because the tier check reads a day). `digest()` `:305-308` HMACs `scope|token` so no raw identity is ever stored. A single global mutex `rl_global.lock` serialises all limit readers (`:321-328`).

### 6.5 CSRF + CORS + origin
CSRF is a global CI4 before-filter; CORS grants only exact-match origins with credentials; and the origin is additionally checked inside the controller (`Api.php:47-52`) so a non-browser client that replays a token still fails.

### 6.6 Encryption at rest — `Helpers.php:9-147`
`Crypto::encrypt` `:18-38`: **AES-256-GCM**, 12-byte random IV, 16-byte tag, AAD `JantaReview:review:v4`, stored as `ENCv4:base64(iv|tag|ct)`. Key derived with HKDF (`:125-133`) from `REVIEW_ENCRYPTION_KEY`. Decrypt dispatches v4/v3/v2 and a literal `[legacy encrypted content]` for `ENCv1` (`:44-62`). Encrypted fields: Reviews F (review text), Grievances H (contact email).
⚠️ **Losing `REVIEW_ENCRYPTION_KEY` permanently destroys all review text and grievance emails.** There is no recovery path, no key rotation mechanism.

Digest helpers, and why each is keyed:
- `Crypto::contentHash` `:143-146` — **unkeyed** `sha256(id|ts|text)`. Stored (Reviews O, Mod_Log N) but ⚠️ never published: anyone could brute-force deleted review text offline.
- `Signals::textHash` `Signals.php:36-41` — keyed; dedupe.
- `Audit::integrityRef` `Audit.php:109-118` — keyed, 12 chars; what the public transparency page shows instead of `contentHash`.

### 6.7 Input validation — `Helpers.php:149-263`
`Validator::sanitize` `:154-166` strips tags, zero-width chars and control chars then truncates. `email()` `:168-172` caps at 254 and lowercases. `url()` `:177-250` — HTTPS only, rejects `localhost`, `.local`, `.internal`, RFC1918, `169.254.x`, `0.x`, **any port ≠ 443**, requires a TLD, and strips ~50 tracking parameters. `stars()` `:252-256`, `status()` `:258-263` (quarantine/unpublished collapse to `archived`).
`Ids::make` `:266-278` — 6 chars from a 31-symbol, ambiguity-free alphabet ≈ 887 M combinations per prefix. Fine for humans, ⚠️ enumerable by an attacker with an admin-only endpoint — which is why admin endpoints require a session, never a guessed ID.

### 6.8 Admin gate — `Core.php:254-360`
`login()` `:260-284`: optional IP allowlist (`guardIp` `:332-344`; **empty = reachable from anywhere**), `hash_equals` compare, `session->regenerate(true)` on success, sliding idle TTL `admin_ttl_seconds` 1800 (`:294-307`). Lockout: 10 failures/hour **per address**, 20/hour **site-wide**, and ⚠️ the shared budget is only spent on a *wrong* key, so a distributed guesser cannot use the correct key as a free lockout for you.
`requireKey()` `:322-329` is the step-up for destructive actions: session **and** the key re-presented in that request.

### 6.9 SSRF defence in the link probe — `ProductLink.php:285-351, 467-524`
https-only, port 443-only, resolved **first** and pinned with `CURLOPT_RESOLVE` to a pre-vetted public IPv4, `FOLLOWLOCATION=false` with every hop re-validated, budgets 6 s total / 2.5 s connect / 4 s read / 2 hops / 192 KB body. Private, reserved and RFC6598 (CGNAT) ranges → `blocked_host`. A DNS-canary `resolverHealthy()` means a flaky resolver cannot reject the whole site.

### 6.10 Spam signals — `Signals.php`
Read-only. `flags()` `:49-95` computes advisory markers (`short_link`, `body_link`, `contact_details`, `repeated_phrase`, `run_on_string`, `shooting`> `shouting`, `emoji_heavy`, `text_equals_product`, `text_equals_url`) — recorded in Reviews U, never enforced. `duplicateOf()` `:102-117` is the **only hard rejection**: byte-identical normalised text from a different device inside 24 h. `linkSiblings()` `:123-131` is advisory at ≥5.
⚠️ `duplicateOf` reads fixed indexes (13 parentId, 15 submitterToken, 19 textHash) — it silently re-points if Reviews columns move.

### 6.11 Audit + integrity chain — `Audit.php`
`log()` `:11-31` appends a Mod_Log row and **swallows failures** into `error_log` (`:28-30`). ⚠️ A moderation action can therefore succeed while its audit row is lost, which later reads as a chain `break`.
`checkpoint(dryRun)` `:186-234` recomputes `prefixDigests()` `:280-292` (per-row sha256 of 14 `\x1f`-joined cells, chained by HMAC with the encryption key) and appends to Integrity_Checks; `kind` is `genesis | checkpoint | break`, and `break` only logs an error (no alerting).
⚠️ `purgedAfter()` `:297-307` accepts a chain restart when a `retention_purged` row exists — so anyone who can append one Mod_Log row can mask a rewrite.

---

## 7. Backend features, one by one

### 7.1 The feed — `getReviews` (`PublicHandlers.php:263-308`)
Reads Reviews `A2:R` (so S/T/U are never fetched), returns newest first, no pagination. Each row: `id, timestamp, productUrl, imageUrl, stars, reviewText (decrypted), helpful, notHelpful, netScore, status, reportCount, flagged, productName, store, parentId, isReply, platform, replyCount`.
- ⚠️ **Nothing is hidden by status** — archived rows are returned with `status:'archived'` and the frontend decides (`PublicHandlers.php:286`). Filtering by "published" is a client-side default (`app.js:41` `state.filter`).
- Archive masking `:274, 279-288`: once `reportCount >= 15`, `productUrl`, `imageUrl` and `reviewText` are blanked out (text is never even decrypted) while id, name, stars, score stay public.
- The public payload deliberately omits `contentHash`, `submitterToken`, `idempotencyKey`, `lastVoteAt`, `archiveCycles`, `textHash`, `flags`.
- Store badge `:290` → `detectStore()` `:1102-1105` → `ProductLink::storeFor()`.

### 7.2 Submitting a review — `submitReview` (`PublicHandlers.php:9-183`)
**Guard order matters** (cheapest first, and the OTP is spent last):
1. `global:submit` local bucket → 503 "The site is busy right now…" `:17-19`
2. Turnstile action `submit` → "Bot protection failed (reason)." `:21-22`
3. `submit` 20/hour per device → "Too many submissions in the last hour." `:24-26`
4. Header self-repair `Q1:U1` `:34-46`
5. **Idempotency** `:32, 48-57` — key required ("Missing submission token."); a matching (submitterToken, key) returns `duplicate:true`, not an error
6. Parent checks `:59-72` — "Parent review not found." / "Replies to replies are not supported."
7. Field validation `:74-98` (top-level only) — messages: "Product name must be at least 2 characters.", "Please provide a valid HTTPS product URL.", "Please select or enter a platform.", "Please select a rating between 1 and 5.", "Review must be at least 10 characters."
8. Duplicate-text rejection `:102-106` → "That review text has already been published here…" — deliberately before the link probe and before the OTP spend
9. Advisory spam flags `:107, 117`
10. **Link token** `:109-119, 190-215` — replies inherit; `enforceLink()` consumes the token, otherwise probes live; **only `invalid` rejects**, `unverified` publishes and marks itself in the audit reason
11. Ciphertext size `:121-124` → "Review is too large to store. Please shorten it."
12. **OTP consume** `:126-130` → `Otp::consume` `:100-122` — "Email verification is required." / "…or has expired." (401) and "Please wait a moment before publishing." (429, min-age floor). The ticket is destroyed on success
13. Append Reviews `A..U` `:131-163` + optional new platform row + `Audit::log` `:165-176` (`published` / `reply_published`, actor `community`)

### 7.3 Product-link validation — `ProductLink.php` (704 lines)
Design policy: **accept and mark unverified**. Marketplace bot-walls make a live probe unreliable, so a structurally-correct Amazon/Flipkart link is `valid` without any network call.
- Verdicts `:34-36`: `valid`, `unverified`, `invalid`. `invalid` is reserved for provably useless links (search pages, homepages, listing pages, dead/blocked hosts).
- Orchestration `:190-225`: structure first; if structure says VALID, the probe can only downgrade on an INVALID answer, otherwise the structure verdict stands with `code:'structure_only'`. This is the fix for "valid Amazon URL flagged as not a product page".
- Structure rules `:231-278`: `bad_url`, `search_page` (SEARCH_KEYS `:65-69`), `homepage_only`, `not_product_page` (LISTING_EXACT `:49-56`, LISTING_PREFIX `:59-63`), `structure_ok`, `unknown_site`.
- Vendor shape patterns `:72-88`: Amazon `/dp|gp/product|gp/aw/d|gp/aod|gp/offer-listing|product-reviews/<10 alnum>` or `?asin=`; Flipkart `/p/itm<hex>` or `?pid=`; Myntra `/product-page/`; 15 vendors. ⚠️ Patterns match **path+query only, never the host** — host trust is separate.
- Two host maps: `VENDOR_HOSTS` `:96-114` (which rules to apply) and `STORE_BADGES` `:117-145` (display name only). Both use whole-label-or-subdomain matching `:625-628`, so `amazon.deals-hub.test` and `notamazon.com` match nothing.
- HTTP probe `:285-351` (budgets and SSRF rules in §6.9). Classification `:353-414`: 404/410 → `http_404`, 5xx → `server_error`, 403/429 → `bot_blocked`, other 4xx → `rejected`, then scans BOT_WALL `:159-168`, SOFT_404_TITLE `:148-156`, GENERIC_PAGE `:174-182`, `empty_page`, else `product_confirmed` / `page_loaded`.
- Metadata `:573-617`: og/twitter title then `<title>`; `clean()` decodes entities **first**, strips tags, replaces `<>"'`, removes control chars, collapses whitespace, `iconv TRANSLIT`, truncates to 180 (siteName 60). Images go through `Validator::url`.
- Link tokens `issue()` / `consume()` `:672-703`: `base64url("expiry|verdict|code") + "." + HMAC-SHA256("link|<url>|<payload>", encryption_key)`; rejects >256 chars, wrong dot count, bad padding, expiry, verdict not in {valid, unverified}, and compares the MAC in constant time. TTL 1800 s.
- Endpoint `checkProductUrl` `PublicHandlers.php:217-261`: **no Turnstile** (it is a typing aid, and gating it breaks the UX), `global:urlcheck` 503, then 25/hour → 429. `linkToken` is issued whenever the verdict is not `invalid`.

### 7.4 Email OTP — `Otp.php` + `Mailer.php`
Request `:12-59`: validate email → Turnstile action `otp` → `global:otp` 503 → `otp_ip|ip` 20/day 429 → set session nonce `:30` → **`otp_24h` 3 per email address** (Sheet-backed) `:32-35` → new 32-byte challenge id + `random_int(100000,999999)` → write record → send → 503 if send fails (and the challenge is deleted).
Storage `:164-182`: `writable/review/otp/<sha256(challengeId)>.json`, written temp+rename with `LOCK_EX`. The file contains **only HMAC digests** — no plaintext code or email.
Verify `:61-98`: format gate → read record → **session binding** → expiry (410) → attempts exhausted (429) → constant-time compare (401 "Incorrect verification code.") → 32-byte one-time verification token in session + `review_otp_verified_at` timestamp → delete challenge.
Session binding `:153-157` = `HMAC(nonce | origin | User-Agent)` — ⚠️ **the nonce is shared by all tabs of one browser session**, so sending a code in tab A and another in tab B invalidates tab A's verify with a 401 "Verification request expired or is invalid."
Anti-speed floor `:114-119`: submit is refused for `ticket_min_age_seconds` (3) after verification, with 429, **without** destroying the verification.
⚠️ The email body hardcodes "expires in 10 minutes" (`:149`) while the TTL is env-driven — change one, check the other.
Mailer `Mailer.php:17-79`: Mailjet v3.1 `/send`, HTTP Basic, 15 s timeout, `http_errors:false`, sender from `OTP_FROM_EMAIL`. Failures return `false` (never throw). ⚠️ On a non-200 it logs the **full response body**, which can echo the recipient into `writable/logs`. OTP mail currently lands in **Spam** — the frontend warns users (`app.js:1035-1042`).

### 7.5 Voting — `vote` (`PublicHandlers.php:324-499`)
Guard order: params `:330-339` → `global:vote` 503 `:337` → **Turnstile for both directions** (`vote_helpful` / `vote_not_helpful`) `:343-344` → voter key `:348` → `checkSpaced('vote', …, 10/hour, 300 s gap)` `:353-366` → ensure Votes header `:370` → **per-review file lock** `:374-375`.
- Voter identity `:502-509` = `HMAC("vote|reviewId|submitterToken", encryption_key)` — no cookie/session, so clearing cookies no longer reopens voting.
- Ledger `:378-386, 471`: a `Votes` row per (voter, review). A duplicate answers **409** `{success:false, error:"You have already voted on this review.", alreadyVoted:true}` — the only endpoint that returns a non-standard envelope so the UI can distinguish "you already voted" from "blocked".
- Per-entry cooldown `:400-406` (column M, 60 s) → "A vote was just recorded…".
- Lock `:512-523` `writable/review/vote_<sha>.lock`, busy → 503 "Vote is busy. Please retry shortly.", released in `finally` `:495-498`.
- Writes `:474-483`: G, H, I (`netScore = helpful - notHelpful`), J (status), M, and S when newly archived.
- ⚠️ Voting is **not** reversible in the UI: there is no un-vote, and the ledger is permanent.

### 7.6 Reporting — `report` (`PublicHandlers.php:525-653`)
Categories `Defamatory | Profanity | Copyright Violation | Spam | Hate Speech` + ≥5 chars `:533-534` → `global:report` → Turnstile `report` `:545-546` → **tiered limits** `:552-560`: first 5 reports in a day are 5/hour; beyond that it drops to 1/hour with 18000 s spacing → find row `:572-576` → per-reporter and per-entry cooldowns `:590-607` → append Reported_Reviews `:611-621` → Reviews K = count+1.
Reaching the 15-report ceiling sets J=`archived` **and** increments S (archive cycles) `:625-632`. Nothing else archives on reports. Messages: "You have already reported this entry." (409), "This entry was reported recently…", "Too many reports in the last hour.", "You have filed several reports recently…".

### 7.7 Moderation maths (where `status` changes)
Exactly three places decide status:
1. **Vote-driven archive** `PublicHandlers.php:433-447` — after the 1800 s quarantine, if published and `netScore <= -15` → `archived`, cycles++ , audit `archived` / actor `community` / "Net Score reached N".
2. **Rescue** `:448-467` — needs `netScore >= -10 + 2*cycles` **and** `reportCount < 15`; audit "Net Score recovered to N (needed R)".
3. **Report ceiling** `:625-632` and admin overrides (`AdminHandlers.php:146, 191, 256`).
`Validator::status()` only normalises for display.

### 7.8 Grievances — `fileGrievance` (`PublicHandlers.php:655-782`) + admin handling
Validation `:660-672`: 6 categories, ≥20 chars, email **or** anonymous → `global:grievance` → Turnstile `grievance` → 3/hour → append (9 columns anonymous `:694-698`, 10 with **encrypted email** named `:700-724`) → audit `grievance_received` → acknowledgement mail → stamp `ackAt` only if a mail was accepted `:756`.
`sendGrievanceAcknowledgement()` `:805-882`: gated on `Mailer::configured()` **and** (`grievance_notify_enabled` or forced); the officer forward is blocked while the officer address is still a placeholder `:841-845`; returns "at least one accepted"; never throws.
Admin: `listGrievances` `AdminHandlers.php:289-327` (decrypts email, merges both tabs), `acknowledgeGrievance` `:380-432` (⚠️ writes `acknowledged` but the **anonymous path writes no audit row**), `resolveGrievance` `:434-477` (writes the note to the case sheet; the audit reason is deliberately `"Case X resolved"` without the note `:451-459`, because Mod_Log is public).

### 7.9 Suggestions — `submitSuggestion` (`PublicHandlers.php:884-909`)
⚠️ **No Turnstile, no OTP** — only `global:suggestion` then 5/hour per device. Writes Suggestions A..F with the submitter token in E and status `New`. Admin `updateSuggestionStatus` `AdminHandlers.php:351-378` (step-up key, writes F only, 6 allowed statuses `:479-488`).

### 7.10 Transparency surfaces
- `getTransparencyLog` `:911-914` → `Audit::publicAll(500)` — **8 whitelisted keys only**: `actionId, timestamp, reviewId, productName, actionType, actor, reason, integrityRef` (`Audit.php:79-94`). Admin-only fields never leave the server except through `adminGetDashboard`: `productUrl, stars, reportCount, ruleBroken, relatedId, contentHash` (`Audit.php:120-144`).
- ⚠️ `publicReason()` `Audit.php:96-102` redacts legacy `"Case X — note"` rows to `"Case X resolved"` and caps at 200 chars. It exists because older rows contain free-text admin notes. If you ever write human text into `reason` again, this leaks it — keep `reason` machine-generated.
- `getReviewHistory` `:916-921` → `Audit::publicForReview()` (same whitelist, filtered by column C).
- `getTransparencyReport` `:923-969` → `Audit::summary()` totals + actor breakdown + grievance SLA maths + `Audit::integrity()` (returns `null` until the nightly checkpoint has run once, so `logIntegrity:null` is expected before the first run).

### 7.11 Utility endpoints
`getStats` `:971-1011` (reads Reviews `A2:O` + Deleted `A2:Q`; replies excluded from `total`; `reported` = archived **and** ≥15 reports; `deleted` = rows with both N and O empty), `getPlatforms` `:310-322` (case-insensitive dedupe, `natcasesort`), `getCompliance` `:1035-1057` (officer block + retention days), `health` `:1059-1062`, `info` `:1064-1096`, `warmUp` `:1013-1033` (⚠️ admin-only, swallows errors).

### 7.12 Admin console API
`getDashboard` `AdminHandlers.php:9-75` — session only, returns Reviews `A2:U` **with decrypted text**, Deleted `A2:R` (skipping replies), and the full raw `Audit::all(500)`.
`deleteReview` `:77-172` — step-up key; **cascades to child replies** `:101-109`; appends an 18-column Deleted row; `Sheets::deleteRows`; one `deleted`/admin audit row per row carrying `ruleBroken`.
`restoreReview` `:174-208` — J=`published`, K=0; ⚠️ **does not reset S (archiveCycles)**, so a restored entry keeps a raised rescue bar.
`restoreFromDeleted` `:210-287` — guards "This entry has already been restored." / "Cannot restore reply — its parent is not in the live feed."; marks Deleted N; ⚠️ loses contentHash, submitterToken, idempotencyKey, textHash and flags, so the same text can be re-submitted and dedupe cannot see it.

---

## 8. Frontend

### 8.1 Bootstrap and configuration
- `config.js:13` production `window.JANTA_REVIEW_API_URL`; `config.js:19` Turnstile sitekey. Read by `app.js:4,6`.
- `config.js:27-39` loads `config.local.js` **only** when the hostname is `localhost`/`127.0.0.1`/`[::1]`, via synchronous XHR + `eval`. A deployed origin can never load it.
- ⚠️ **Never upload `config.local.js` to Vercel** — it would repoint production at a dead `127.0.0.1` API.
- Script order `index.html:854-865`: inline `onTurnstileLoad` shim → Turnstile API (`render=explicit`, async) → `config.js` → `app.js`. Theme-flash guard `index.html:11-16`; fonts `:18-22`; `styles.css` `:23`; compiled `tailwind.css` `:29`.
- Boot `app.js:73-107`: every `setup*`, then skeleton/stats/reviews/platforms, then `startPolling`; `visibilitychange` re-poll at `:102`.

### 8.2 The API helper and app state
- `api(action, payload)` `app.js:1326-1365`: lazily fetches `?action=csrf`, POSTs as `text/plain;charset=utf-8` (a *simple* request, so no CORS preflight) with `X-CSRF-TOKEN` and `credentials:'include'`; parses text then JSON; on an HTTP error it **spreads the parsed body** into `{success:false, status, …}` so callers can see flags like `alreadyVoted`; console noise limited to network/5xx.
- `state` `app.js:36-68` — reviews, platforms, `filter:'published'`, productSearch, Turnstile widget/token triple, `voted`/`reported` (localStorage via `readJSON`), `replyTo`, `modalThreadId`, `submitIdempotencyKey`, `link{url,result,token,checking,failed,error,seq,timer}`, `otp{challengeId,verificationToken,spamNoticeShown}`, `grievance{…}`.
- `setupDelegation` `app.js:128-153` — one document-level listener; `[data-open-write]` plus `data-action` values: `toggle-menu`, `toggle-theme`, `open-modal`(+`data-modal`), `vote`, `report`, `toggle-archive`, `toggle-review-text`, `reply`, `open-replies-modal`, `grievance`, `review-history`.

### 8.3 Frontend features

| Feature | Where | Notes |
|---|---|---|
| Review card | `renderCard` `app.js:1508-1689` | stars `:1514`, status badge `:1518-1522`, monogram/thumb `:1527-1535`, archived banner `:1543-1546`, title `:1551-1560`, reply pill `:1572-1580`, vote row `:1583-1601`, history/report buttons `:1607-1620`, grievance link `:1624-1630`, `card-body`/`card-footer` zones `:1649-1657` |
| Text clamp + "show more" | `fitReviewClamps` `:1708-1726`, `toggleReviewText` `:1728-1748` | scroll-in-place, card height stays constant |
| Filters / sort / search | `:269-281`, `:383-387`, `:396-403` | client-side over the full feed |
| Styled `<select>` replacement | `enhanceSelectToDropdown` `:288-370` | duplicated in admin.js |
| Platform picker | `loadPlatforms` `:507-519` (plain fetch, no CSRF), `setupPlatformPicker` `:521-546`, `renderPlatformOptions` `:548-572` | |
| Write panel | `openWritePanel` `:418-424`, `closeWritePanel` `:489-498`, stars `:240-259`, char counter `:261-267`, reply mode `:426-487` | |
| **Live product-link checker** | constants `:575-577` (700 ms debounce, `linkChecks` Map cache), `setupProductLinkChecker` `:579-603`, `instantLinkError` `:605-615`, `verifyProductLink` `:617-679`, `setLinkBorder` `:681-691`, `renderLinkState` `:693-763`, `ensureLinkChecked` `:765-778`, `resetLinkChecker` `:780-791` | border tones: `border-info/60` checking, `border-live/60` valid, `border-star/60` unverified (amber), `border-danger/70` invalid. Results cached per URL — a bad link stays cached until edited; the status line offers a retry (`data-link-retry`) |
| Submit | `handleSubmit` `:793-873` | requires `state.otp.verificationToken`; link gate `:817-830`; idempotency key `:833-837`; on success resets everything and reloads |
| OTP widget | `requestReviewOtp` `:890-941`, `verifyReviewOtp` `:943-960`, `resetReviewOtp` `:962-978`, sending status `:875-888`, spam notice `:1035-1042` | solves Turnstile **before** the API call; "Resend OTP" replaces the button after success |
| Turnstile mounts | review `:1044-1109` (`turnstileMount`, index.html:527), OTP lazy `:1111-1177` (`otpTurnstileMount`, index.html:502), vote `:1187-1238` (index.html:666), report `:1256-1288` (index.html:632), grievance `:1290-1324` | all use `execution:'execute'` + `turnstile.execute()`; ⚠️ the **sitekey is the container id** pattern that previously caused "The security check did not finish" — keep the mount div empty. Every widget is removed and re-rendered on theme change (`toggleTheme` `:113-123`) |
| Vote flow | `handleVote` `:1909-1912`, `castVote` `:1914-1953`, challenge modal `:1187-1201` | localStorage `voted` map is UI-only; the server ledger is the truth |
| Report | `:1955-2042` | category select + ≥5 chars gate the submit button |
| Replies | `:1750-1907` | thread modal renders from the already-loaded feed |
| Review lifecycle | `:2294-2474` | 5-minute cache + ticker; timeline `renderHistoryTimeline` `:2440-2474` prints `actor` and `ref:` from `integrityRef`; footer copy explains the ref `:2425-2430` |
| Legal docs + EN/HI | `setDocContent` `:2486-2513`, `openDocByName` `:2521-2528`, `DOC_HINDI` `:2538-2706`, docs: suggestion `:2708-2772`, about `:2774-2783`, terms `:2785-2852`, privacy `:2854-2893`, grievance `:2895-2944`, rules `:2946-2991` | ⚠️ **i18n hazard**: English lives inline in JS template strings, Hindi lives in a separate object **keyed by the exact English `<h1>` text**. Renaming an English heading silently kills its Hindi toggle. Two docs intentionally have no Hindi (`languageDisabled` `:2494`) |
| Transparency page | `:2993-3238` | sessionStorage cache `jr_transparency_cache_v1`, 5-minute ticker, summary cards, table header `Action / When / Review ID / Product / Actor / Reason / Ref` (`:3210`) |
| Grievance filing | `ensureGrievanceModalDOM` `:2044-2167` (the modal HTML is **injected by JS**, not in index.html), setup `:2169-2251`, `submitGrievance` `:2253-2292` | anonymous toggle `:2219-2227` |
| Toasts / busy buttons | `:3240-3288` | |
| Overlays + scroll lock | `:982-1001` (`OVERLAY_IDS`) | |
| First-visit dev notice | `:1003-1033` (`tr_dev_notice`) | |
| Skeleton + polling | `renderSkeleton` `:1417-1435`, `loadReviews` `:1437-1461`, `renderReviews` `:1463-1506`, `startPolling`/`pollForUpdates`/`reviewsChanged` `:1366-1396` | polling pauses when `document.hidden` or when the write/report/grievance panel is open |
| Utilities | `:3290-3339` (`escapeHtml`, `escapeAttr`, `truncate`, `readJSON`, icons), time helpers `:172-223` | ⚠️ every interpolation into `innerHTML` must go through `escapeHtml` |

### 8.4 Admin console (`admin/admin.js`, `admin/index.html`)
- Loads `/config.js` then `/admin/admin.js` (`admin/index.html:537-538`); its own `<style>` block `:27-126` — not Tailwind.
- `state` `admin.js:347`; login `setupAuth` `:481-513` → `verifyAndLoad` `:515-541`; backend dot `probeBackendStatus` `:407-428` (uses `?action=health`); key hint `rememberKeyHint` `:440-450` (shows `Last session: XXXX••YY`, never the key); tabs `:567-586`; refresh `:588-596` → `loadAll` `:1022-1074`.
- Step-up dialog: `injectStepUpModal` `:155-198`, `openStepUp` `:210-231`, `submitStepUp` `:254-286`, `stepUpAction` `:288-301`. Escape closes it first of all modals (`setupEscapeHandler` `:1005`).
- Actions `setupActions` `:598-633`: `delete`, `delete-with-case`, `restore`, `restore-deleted`, `ack-grievance`, `resolve-grievance`, `view-history`, `toggle-review-text`, plus suggestion status `updateSuggestionStatus` `:635-662`. Delete modal `:747-822`, restores `:824-849`, grievances `:863-929`.
- Renders: `renderAdminCard` `:1252-1379`, `renderDeletedCard` `:1381-1488`, `renderGrievances` `:1490-1624` (SLA table `:10-19`), `renderSuggestions` `:1626-1690`, `renderLog` `:1692-1743` — the log shows the **raw `contentHash` truncated to 12 chars** because the admin payload is unprojected.
- ✅ **Step-up (fixed 2026-09-26).** `state.adminKey` no longer exists. Every data-changing action opens the **Confirm with the admin key** dialog (`injectStepUpModal` / `openStepUp` / `submitStepUp` / `stepUpAction`) and the typed key is sent **once**, in that one request, then wiped from the input (`closeStepUp`, and again on a rejected attempt). A wrong key keeps the dialog open with the server's message so you can retry; `Unauthorized.` is mapped to "your session expired" because it means the *session* died, not the key. Read-only tabs send no key at all — they only need the session.

### 8.5 Build and deploy
- Tailwind is **compiled ahead of time** — there is no CDN and no runtime JIT. `frontend-build/package.json:7`: `npm run build` → `tailwindcss -c tailwind.config.cjs -i input.css -o ../frontend/tailwind.css --minify` (also `watch`).
- ⚠️ **Any new or changed class name in `index.html`, `app.js`, `admin/index.html` or `admin/admin.js` requires a rebuild and an upload of `tailwind.css`, or the element renders unstyled.** Content globs are exactly those four files (`frontend-build/tailwind.config.cjs`). `frontend-build/check-coverage.cjs` verifies the built CSS covers the classes in use.
- `node_modules` is deliberately kept **outside** `frontend/` so the Vercel upload stays small.
- Deployment is a **manual Vercel upload** of `C:\wamp64\www\frontend-deploy` (a copy that must be refreshed and must never contain `config.local.js`). `frontend-deploy` can silently go stale relative to `frontend` — always copy from `frontend`.
- There is **no git history for the frontend**. The only copies are `frontend/` and `frontend-deploy/` (plus the dated backup archive).

---

## 9. Background jobs and CLI

| Command | File | Writes to the shared Sheet? |
|---|---|---|
| `php spark security:retention-purge [--dry-run]` | `Commands/RetentionPurge.php:15-44` → `Retention.php:24-66` | ⚠️ **deletes rows**: Deleted_Reviews (`A2:M`), Reported_Reviews + Suggestions + Mod_Log (`A2:B`). Reviews, Votes, Grievances, Anonymous_Grievances deliberately untouched. Writes a `retention_purged` audit row, which is what legitimises the integrity-chain restart. Non-blocking `purge.lock` so two purges cannot interleave. Rows with no readable timestamp are **kept** (`:77-94`) |
| `php spark security:audit-checkpoint [--dry-run]` | `Commands/AuditCheckpoint.php:18-50` → `Audit::checkpoint()` | appends to Integrity_Checks; throws on `break` to force a non-zero exit. **Always run `--dry-run` first against live data** |
| `php spark backup:sheets-export [--out] [--keep]` | `Commands/SheetsBackup.php:15-46` → `Backup.php:23-64` | read-only from the Sheet; writes CSV + `_manifest.json` and re-reads each file to verify. Default keep 14 days |
| `php spark backup:sheets-restore --from --prefix` | `Commands/SheetsRestore.php:21-66` | ⚠️ writes **new `<prefix>_<tab>` sheets only**; refuses to touch existing tabs. This is a rehearsal tool, not an overwrite tool |
| `php spark security:otp-cleanup` | `Commands/OtpCleanup.php:15-30` | deletes expired OTP files and prunes `Rate_Limits`. ⚠️ **not scheduled anywhere** — the nightly workflow does not call it |

**Scheduling** — `.github/workflows/nightly-maintenance.yml`: cron `35 19 * * *` (01:05 IST) + manual `workflow_dispatch`; order purge → checkpoint → export; artifact upload; credential file wiped. ⚠️ Gated on the repository variable `MAINTENANCE_ENABLED` — **nothing runs until you enable it**. It runs in GitHub rather than Render because the free Render plan has no cron and `writable/` is ephemeral.

⚠️ **Backups are a formula-injection sink.** `Backup.php:9-17` documents that a cell beginning with `=` becomes a live formula if the CSV is opened in a spreadsheet app. Exports are for restore, not for browsing.

---

## 10. Running, testing, deploying

### 10.1 Local development

```bash
# php and composer are NOT on PATH
export PHP=/c/wamp64/bin/php/php8.2.29/php

# API (from C:/wamp64/www/backend) — either:
$PHP spark serve --port 8080        # or:  $PHP -S 127.0.0.1:8080 -t public

# Static site (from C:/wamp64/www/frontend): any server on :5500
# then open http://127.0.0.1:5500
```

Required local `.env` values (already set): `TURNSTILE_BYPASS_FOR_LOCAL_DEV=true`, `COOKIE_SECURE=false`, `COOKIE_SAMESITE=Lax`, `app.baseURL=http://127.0.0.1:8080/`, `REVIEW_ALLOWED_ORIGINS` containing `http://127.0.0.1:5500`.
Required local frontend: `frontend/config.local.js` = one line setting `window.JANTA_REVIEW_API_URL = 'http://127.0.0.1:8080/api'`.
⚠️ Turnstile stays the **production sitekey** locally — it is bypassed server-side only. A browser extension that blocks Cloudflare breaks the OTP/vote widgets even locally.
⚠️ Any write you do locally lands in the **production spreadsheet** (§3.5), and `checkProductUrl` alone writes rate-limit rows.

### 10.2 Tests

```bash
/c/wamp64/bin/php/php8.2.29/php vendor/bin/phpunit --no-coverage            # whole suite
/c/wamp64/bin/php/php8.2.29/php vendor/bin/phpunit tests/unit/SignalsTest.php
/c/wamp64/bin/php/php8.2.29/php -l app/Libraries/Legacy/PublicHandlers.php   # syntax
node --check app.js                                                          # frontend syntax
```

### 10.3 Deploying
- **Backend:** push to `main` → Render redeploys the Docker image (per `render.yaml`). ⚠️ No `healthCheckPath` — Render probes `HEAD` and `/` is GET-only, so a health check path would 404 the service.
- **Frontend:** manual Vercel upload from `frontend-deploy/` after copying from `frontend/` and rebuilding `tailwind.css` if classes changed.

---

## 11. Test reference (`tests/unit/`)

| File | Protects |
|---|---|
| `ClientIdentityTest.php` | IP resolution edge cases (`X-Real-IP` alone ignored, forged leftmost XFF ignored, right-to-left behind a trusted proxy, `0.0.0.0` fallback, CIDR), `identity()` |
| `ReviewValidationTest.php` | `Validator::sanitize/url/stars/status` |
| `ProductLinkTest.php` | link **structure only** — the network probe is deliberately never called here (a suite that reached Amazon would be flaky). Covers lookalike hosts, ASIN shape rules, badge rules, `clean()` sanitising, token round-trip |
| `AuditVisibilityTest.php` | the 8-field public allowlist, legacy-note redaction, 200-char cap, `integrityRef` determinism and that it is *not* the stored hash |
| `AuditChainTest.php` | prefix digests survive append, detect edit/reorder, ignore trailing blanks |
| `SignalsTest.php` | text hashing, spam flags, cross-device duplicate detection |
| `BackupTest.php` | CSV round-trip, empty store, `columnLetter` |
| `AdminStepUpTest.php` | `Admin::requireKey` both directions: the configured key passes, missing/wrong key refused, and a session alone is not enough |
| `HealthTest.php` | constants + baseURL configured |

⚠️ `tests/database/ExampleDatabaseTest.php` and `tests/session/ExampleSessionTest.php` are CI4 scaffolding and reference a `tests` DB group that does not exist in this project.

---

## 12. "If X breaks, look here"

| Symptom | First places to check |
|---|---|
| Feed shows "Could not load reviews" | `app.js:1326-1365` (`api`), `REVIEW_ALLOWED_ORIGINS` on the server, iOS/third-party-cookie behaviour (§13.4) |
| Every POST is 403 "Origin is not allowed." | `Api.php:47-52`, `REVIEW_ALLOWED_ORIGINS` (`Review.php:116`), `frontend/config.js:13` |
| "CSRF expired" / 403 on the first click | `?action=csrf` at `Api.php:55-60`, `Config/Filters.php:74-84`, cookie settings (`Config/Cookie.php:92-97`) |
| "The security check did not finish." | `app.js:1157` `solveOtpTurnstile`, mount divs in `index.html:502/527/632/666`, Turnstile script `index.html:854-865`, ad-blockers |
| OTP 401 "Incorrect verification code." | `Otp.php:79-81` — usually a stale email: each send makes a new code, the page holds only the newest `challengeId` (`app.js:926`) |
| OTP 401 "Verification request expired or is invalid." | `Otp.php:67-69` — session binding `:153-157`; a second tab, a User-Agent change, or a lost session |
| "You have reached the limit of 3 OTP requests in 24 hours." | `Otp.php:33` → the `Rate_Limits` tab, key `otp_24h` + email digest |
| "Too many requests from your address. Please slow down." | `Api.php:98-110`, 120/min per IP |
| A valid Amazon link marked invalid | `ProductLink.php:190-225` orchestration, then `:72-88` patterns, then `:96-114` host map |
| Store badge missing/wrong | `ProductLink::storeFor` + `STORE_BADGES` `:117-145`, called at `PublicHandlers.php:290` and `AdminHandlers.php:62` |
| Vote says "already voted" wrongly | the `Votes` tab (`vote_key`) — the ledger is permanent (§7.5) |
| A review vanished from the feed | column J status + `reportCount >= 15` masking (`PublicHandlers.php:274-288`); then `Mod_Log` column J actionType |
| Admin console "Backend unreachable" | `?action=health` CORS — `applyCors` must run before the health short-circuit (`Api.php:32-41`) |
| Admin delete/restore fails 401 | §13.1 (frontend never holds the step-up key) |
| Whole API 503 "data service temporarily unavailable" | Google 403/quota → `Api.php:75-78`, `Sheets::withRetries` `:346-378`; the Sheet must stay shared with the service-account email |
| Mail not delivered | `Mailer.php:19-24` (`configured()`), `:61` / `:66-69` logged response, sender `OTP_FROM_EMAIL`, spam folder |
| Transparency shows `logIntegrity: null` | expected until `php spark security:audit-checkpoint` runs once (§7.10) |
| An element has no styling | `tailwind.css` not rebuilt (§8.5) |
| Hindi doc missing / toggle gone | the English `<h1>` no longer matches a `DOC_HINDI` key (§8.3) |

---

## 13. Known issues and warnings

### 13.1 ✅ Admin step-up (fixed 2026-09-26, was: silently broken)
`Admin::requireKey()` (`Core.php:322-329`, called at `AdminHandlers.php:79, 176, 212, 353, 382, 436`) has always demanded the key again on the six data-changing actions. The console used to send `state.adminKey`, which was only ever `null`, so delete / restore / restore-from-deleted / acknowledge / resolve / suggestion-status all failed with 401 while the read tabs worked. Now each of those six goes through `stepUpAction()` in `admin.js`, which prompts for the key per action and never retains it. `tests/unit/AdminStepUpTest.php` pins the server side (correct key passes, missing/wrong key and session-only are refused). ⚠️ **If you add another mutating admin endpoint, route its call through `stepUpAction`** — a plain `api()` call will 401 exactly like the old bug.

### 13.2 ⚠️ Anything written to `Mod_Log.reason` becomes public
`Audit.php:79-102` whitelists fields and redacts `Case X — note`, but a new handler that puts free text into `reason` will publish it. Keep `reason` machine-generated.

### 13.3 ⚠️ `writable/` is ephemeral on Render
Sessions, local rate-limit buckets, pending OTP challenges and per-review vote locks all live there. Every restart/redeploy silently: logs everyone out, resets `checkLocal` buckets, and destroys pending OTP challenges (a user mid-verification gets 401). The Sheet-backed limits are the only durable ones.

### 13.4 Shared datastore + `identity_pepper`
Changing `REVIEW_ENCRYPTION_KEY` (or setting/removing `REVIEW_IDENTITY_PEPPER`) resets every device's rate-limit history and vote identity, and can invalidate the audit chain. Treat them as immutable in production.

### 13.5 Public claims vs code (legal exposure)
- `getCompliance` (`PublicHandlers.php:1035-1057`) states emails are not stored while OTP challenge records and encrypted grievance emails do exist.
- 24 h / 15-day SLA figures are printed as commitments in the grievance doc; the code only measures them (`getTransparencyReport`), and acknowledgement mail is off unless `GRIEVANCE_NOTIFY_ENABLED=true`.
- The Grievance Officer block is still placeholders by your own decision (`Review.php:155-163`, `render.yaml:115-131`) — must be real before launch.
- ⚠️ Any change to legal copy needs your approval of the exact text first.

### 13.6 Audit log failures are silent
`Audit.php:28-30` swallows write errors. A moderation action can succeed with no audit row, and the nightly checkpoint will then report `break` with no explanation. `break` also has no alerting path (`Audit.php:229-231`).

### 13.7 Credential files
`backend/key.txt` is **not read by any code** (only `.gitignore:52`, `.dockerignore:13-14` and `.htaccess:13` mention it to exclude it) and is a different size from the live `writable/secrets/service-account.json`. It is still on disk in a web-served tree. Removing the file does not revoke the credential — rotate it in the provider console if it might be live. `C:\wamp64\www\mailjet_secrete.txt` is already gone.

### 13.8 Drift risks (harmless today, misleading tomorrow)
- `info()`'s endpoint list (`PublicHandlers.php:1064-1096`) is hand-maintained and already omits `checkProductUrl`, `getPlatforms`, `getCompliance`, `health`.
- The OTP email says "10 minutes" while `OTP_TTL_SECONDS` is configurable (`Otp.php:149`).
- `Signals::duplicateOf` uses hard-coded column indexes (`:102-117`).
- `restoreReview` does not reset `archiveCycles` (`AdminHandlers.php:174-208`).
- `restoreFromDeleted` drops hash/dedupe columns (`:242-268`).
- `acknowledgeGrievance` writes no audit row on the anonymous path (`:380-432`).
- `app/Filters/` does not exist — all gating is inside `Api::index()` plus the global CSRF filter, so nothing else runs before the controller.
- `frontend-deploy/` is a stale-able manual copy; `escapeHtml`/`eventColor`/`enhanceSelectToDropdown`/`fitReviewClamps` are duplicated between `app.js` and `admin.js`.
- The root `.htaccess` and `backend/.htaccess` only matter under WAMP; Apache in Docker uses `docker/apache-vhost.conf` (DocumentRoot `public/`, `/app` denied).

### 13.9 Not yet done (owner-side)
Nothing in this session is committed or pushed. `MAINTENANCE_ENABLED` is not set, so no nightly job runs. The first `security:audit-checkpoint` has not run, so `logIntegrity` is empty. `GRIEVANCE_NOTIFY_ENABLED` is false. The GitHub workflow's confirm-gate should be reviewed before `.github/` is committed.

---

## 14. Change-safety checklist

| Before you touch… | Must stay true |
|---|---|
| `render.yaml`, `Config/App.php`, `Config/Cookie.php` | `COOKIE_SECURE=true` + `SAMESITE=None`; no `healthCheckPath`; `proxyIPs` keeps `127.0.0.1`/`::1`; never host on ByetHost/TinkerHost/InfinityFree |
| Any `Sheets.php` call | input option `RAW`; `deleteRows` never retried |
| The Reviews tab columns | append only, never reorder (`Signals.php:102-117` and every `A2:X` range assume positions) |
| `Mod_Log` | 14 columns; all reads are `A2:N`; column G stays empty; keep `reason` machine-generated |
| `Audit.php` | public surface stays the 8-field whitelist; publish keyed refs, never `contentHash` |
| `ProductLink.php` | structural VALID must not be downgraded by a probe; never trust host-name substrings |
| A write endpoint | keep the guard order: local bucket → Turnstile → Sheet limits → Sheet work; cheap reads go in `Api.php:98-104` |
| `Config/Logger.php`, `writable/*/index.html` | ⚠️ deliberate local drift — **never commit** |
| Frontend classes | rebuild `tailwind.css` (§8.5) |
| Frontend upload | `config.local.js` excluded; copy `frontend/` → `frontend-deploy/` |
| Legal/UX copy | show the exact text and get approval first |
| Git | never `git add -A`; `git status` is unreliable (CRLF) — trust `git diff --ignore-cr-at-eol`; confirm before any push |
| After any backend edit | `php -l` each file + `vendor/bin/phpunit` (currently **103 tests / 194 assertions** green) |

---

## 15. Quick index of the most load-bearing files

| File | Size | What it owns |
|---|---|---|
| `app/Controllers/Api.php` | 207 | every guard that runs before a handler; the action table |
| `app/Libraries/Legacy/Core.php` | 360 | config+validation, request parsing, IP/identity, admin gate |
| `app/Libraries/Legacy/Security.php` | 329 | Turnstile + both rate-limit engines |
| `app/Libraries/Legacy/Sheets.php` | 404 | the entire database layer |
| `app/Libraries/Legacy/PublicHandlers.php` | 1106 | every public feature |
| `app/Libraries/Legacy/AdminHandlers.php` | 489 | every admin feature |
| `app/Libraries/Legacy/ProductLink.php` | 704 | link verdicts, probe, SSRF defence, tokens |
| `app/Libraries/Legacy/Audit.php` | 308 | moderation log, integrity chain, public projection |
| `app/Libraries/Legacy/Otp.php` | 202 | email verification |
| `app/Config/Review.php` | 208 | every number and switch |
| `frontend/app.js` | ~3350 | the entire user site |
| `frontend/admin/admin.js` | ~1800 | the moderation console |
