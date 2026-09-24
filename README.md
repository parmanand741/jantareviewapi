# CodeIgniter 4 Application Starter

## What is CodeIgniter?

CodeIgniter is a PHP full-stack web framework that is light, fast, flexible and secure.
More information can be found at the [official site](https://codeigniter.com).

This repository holds a composer-installable app starter.
It has been built from the
[development repository](https://github.com/codeigniter4/CodeIgniter4).

More information about the plans for version 4 can be found in [CodeIgniter 4](https://forum.codeigniter.com/forumdisplay.php?fid=28) on the forums.

You can read the [user guide](https://codeigniter.com/user_guide/)
corresponding to the latest version of the framework.

## Installation & updates

`composer create-project codeigniter4/appstarter` then `composer update` whenever
there is a new release of the framework.

When updating, check the release notes to see if there are any changes you might need to apply
to your `app` folder. The affected files can be copied or merged from
`vendor/codeigniter4/framework/app`.

## Setup

Copy `env` to `.env` and tailor for your app, specifically the baseURL
and any database settings.

## Review API deployment

The review backend is exposed by CI4 at `/api` and `/api/index.php`. It
preserves the action-based contract used by the separate frontend in
`updated/public`, while Google Sheets remains the only application datastore.
The frontend assets are already available outside CI4 under
`E:\wamp64\www\updated\public`: `index.html`, `styles.css`, `app.js`,
`config.js`, and the `admin` directory. `config.js` defines the API URL before
the frontend scripts load. Change that file for production; it contains no
secrets.

The floating “Suggest a feature” form writes only category, suggestion text,
timestamp, an anonymous rate-limit token, and a generated ID to the separate
`Suggestions` Google Sheet tab. It does not request an email address.

Google Sheets writes use `spreadsheets.values.batchUpdate` when several cells
must change together, such as vote counts, moderation status, and grievance
status. The Sheets service also retries transient transport and API failures
including HTTP 429 with bounded exponential backoff and jitter (up to six
attempts). This reduces quota pressure and avoids immediately repeating a
rate-limited request; it does not remove Google Sheets quotas, so clients
should still avoid unnecessary polling.

Turnstile uses two different credentials: the browser receives only the
public site key in `updated/config.js`, while CI4 receives the private secret
in `.env`. The local frontend uses Cloudflare's documented test site key.
Before production, replace `JANTA_REVIEW_TURNSTILE_SITEKEY` with the public
site key paired with `TURNSTILE_SECRET`, and add the deployed hostname in
Cloudflare Turnstile Hostname Management. Never put `TURNSTILE_SECRET` in
frontend files.

Copy `.env.example` to `.env` and set the spreadsheet ID, protected
service-account path, encryption key, admin key, Turnstile secret, allowed
frontend origins, and grievance officer settings. Keep the service-account JSON
outside both public web roots and set `COOKIE_SECURE=true` in production.

Review email verification is ephemeral. Configure `OTP_FROM_EMAIL` and the
`OTP_SMTP_*` settings for a dedicated SMTP mailbox. The API stores only a
short-lived hash-based challenge under `writable/review/otp`; it never writes
the email address or OTP to Google Sheets, review rows, or audit logs.
Challenges expire, have a limited number of attempts, and are bound to the
browser session, origin, and user-agent.

OTP delivery is limited to three requests per 24 hours for the same normalized
email and anonymous client token. The quota is kept in protected writable
files as timestamps indexed by a keyed HMAC; no plaintext email, OTP, or
decryptable email record is stored. This is intentionally a one-way verifier,
not encryption: if the server must compare a value without recovering it,
HMAC is safer and more appropriate than encryption.

Run the cleanup command periodically to remove unused OTP challenges and
quota records whose full 24-hour window has expired:

```powershell
php spark security:otp-cleanup
```

On Windows, create a Task Scheduler task that runs every 15 minutes:

```text
Program:  C:\path\to\php.exe
Arguments: spark security:otp-cleanup
Start in: E:\wamp64\www\reviews
```

The command removes expired files from `writable/review/otp` and deletes or
prunes only `rl_otp_24h_*.log` records. It never scans or deletes unrelated
files. A record is removed only after its last timestamp is older than the
24-hour enforcement window.

The admin UI authenticates once with `adminLogin`; CI4 stores the authenticated
state in an HttpOnly session cookie. Protected admin actions no longer accept a
raw admin key. Rotate any service-account, admin, encryption, or Turnstile
secrets that were present in the legacy configuration before production use.

Point Apache/IIS to the CI4 `public` directory for the API, not the project
root, and configure CORS to the exact origin(s) serving `updated/public`.

## Important Change with index.php

`index.php` is no longer in the root of the project! It has been moved inside the *public* folder,
for better security and separation of components.

This means that you should configure your web server to "point" to your project's *public* folder, and
not to the project root. A better practice would be to configure a virtual host to point there. A poor practice would be to point your web server to the project root and expect to enter *public/...*, as the rest of your logic and the
framework are exposed.

**Please** read the user guide for a better explanation of how CI4 works!

## Repository Management

We use GitHub issues, in our main repository, to track **BUGS** and to track approved **DEVELOPMENT** work packages.
We use our [forum](http://forum.codeigniter.com) to provide SUPPORT and to discuss
FEATURE REQUESTS.

This repository is a "distribution" one, built by our release preparation script.
Problems with it can be raised on our forum, or as issues in the main repository.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library
