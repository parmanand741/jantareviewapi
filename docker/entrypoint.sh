#!/bin/bash
set -euo pipefail

APP_DIR="${APP_DIR:-/app}"
WRITABLE="${APP_DIR}/writable"
SECRETS_DIR="${WRITABLE}/secrets"

# ---------------------------------------------------------------------------
# 1) Google service-account JSON, delivered as a base64-encoded env var so the
#    secret never lives in the image or the git repo. A bad paste must NOT crash
#    the whole container (set -e), so decode non-fatally and only keep a
#    non-empty result.
# ---------------------------------------------------------------------------
mkdir -p "${SECRETS_DIR}"
SA_FILE="${SECRETS_DIR}/service-account.json"
if [ -n "${GOOGLE_SERVICE_ACCOUNT_JSON_BASE64:-}" ]; then
    # Strip whitespace/newlines Render may have added around the value, then decode.
    if printf '%s' "${GOOGLE_SERVICE_ACCOUNT_JSON_BASE64}" | tr -d ' \t\r\n' | base64 -d > "${SA_FILE}.tmp" 2>/dev/null && [ -s "${SA_FILE}.tmp" ]; then
        mv "${SA_FILE}.tmp" "${SA_FILE}"
        chmod 600 "${SA_FILE}"
        echo "[entrypoint] wrote service-account.json from GOOGLE_SERVICE_ACCOUNT_JSON_BASE64"
    else
        rm -f "${SA_FILE}.tmp"
        echo "[entrypoint] WARNING: GOOGLE_SERVICE_ACCOUNT_JSON_BASE64 is not valid base64; Google Sheets calls will fail." >&2
    fi
else
    echo "[entrypoint] WARNING: GOOGLE_SERVICE_ACCOUNT_JSON_BASE64 not set; Google Sheets calls will fail." >&2
fi

# ---------------------------------------------------------------------------
# 2) Resolve the public base URL. Render injects RENDER_EXTERNAL_URL
#    (e.g. https://jantareview-api.onrender.com). APP_BASE_URL overrides it.
# ---------------------------------------------------------------------------
BASE_URL="${APP_BASE_URL:-}"
if [ -z "${BASE_URL}" ] && [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    BASE_URL="${RENDER_EXTERNAL_URL%/}/"
fi
if [ -z "${BASE_URL}" ]; then
    BASE_URL="http://localhost:8080/"
fi

# ---------------------------------------------------------------------------
# 3) Write the minimal .env. CodeIgniter's env() reads every other setting
#    (REVIEW_*, OTP_*, TURNSTILE_*, COOKIE_*, GRIEVANCE_*) straight from the
#    real environment variables Render injects, so only the dotted keys that
#    cannot be OS env vars are written here.
# ---------------------------------------------------------------------------
cat > "${APP_DIR}/.env" <<EOF
CI_ENVIRONMENT = production
app.baseURL = '${BASE_URL}'
GOOGLE_APPLICATION_CREDENTIALS = 'writable/secrets/service-account.json'
EOF
chmod 640 "${APP_DIR}/.env"
echo "[entrypoint] app.baseURL = ${BASE_URL}"

# ---------------------------------------------------------------------------
# 4) Ensure the writable tree exists and is owned by the Apache user.
# ---------------------------------------------------------------------------
for d in cache logs session uploads debugbar review review/otp secrets; do
    mkdir -p "${WRITABLE}/${d}"
done
chown -R www-data:www-data "${WRITABLE}" "${APP_DIR}/.env"
chmod -R 775 "${WRITABLE}"

# ---------------------------------------------------------------------------
# 5) Render routes traffic to the port in $PORT (Docker services must bind to
#    0.0.0.0:$PORT, not 80). Rebind Apache to it before starting.
# ---------------------------------------------------------------------------
PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
echo "[entrypoint] Apache listening on port ${PORT}"

exec apache2-foreground
