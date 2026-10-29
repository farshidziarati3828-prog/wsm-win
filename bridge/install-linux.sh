#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$ROOT/wsm_local_bridge.py"
REQ="$ROOT/requirements.txt"

if ! command -v python3 >/dev/null 2>&1; then
  echo 'Python 3 پیدا نشد؛ Python 3.10 یا جدیدتر و بستهٔ venv را نصب کنید.' >&2
  exit 1
fi
python3 - <<'PY'
import sys
if sys.version_info < (3, 10):
    raise SystemExit('Python 3.10 یا جدیدتر لازم است.')
PY

python3 -m venv "$ROOT/.venv"
PY="$ROOT/.venv/bin/python"
"$PY" -m pip install --upgrade pip
"$PY" -m pip install -r "$REQ"

if command -v systemctl >/dev/null 2>&1 && systemctl --user show-environment >/dev/null 2>&1; then
  UNIT_DIR="$HOME/.config/systemd/user"
  mkdir -p "$UNIT_DIR"
  cat > "$UNIT_DIR/wsm-local-bridge.service" <<EOF
[Unit]
Description=WP Store Manager local attendance bridge
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart="$PY" "$SCRIPT" run
WorkingDirectory="$ROOT"
Restart=on-failure
RestartSec=10

[Install]
WantedBy=default.target
EOF
  systemctl --user daemon-reload
  systemctl --user enable --now wsm-local-bridge.service
  echo 'پل به‌صورت سرویس کاربر نصب و اجرا شد؛ پس از ورود مجدد نیز خودکار شروع می‌شود.'
else
  STATE_DIR="${XDG_STATE_HOME:-$HOME/.local/state}/wp-store-manager-bridge"
  mkdir -p "$STATE_DIR"
  PID_FILE="$STATE_DIR/bridge.pid"
  LOG_FILE="$STATE_DIR/bridge.log"
  if [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
    echo 'پل از قبل در حال اجرا است.'
  else
    nohup "$PY" "$SCRIPT" run >>"$LOG_FILE" 2>&1 </dev/null &
    echo $! > "$PID_FILE"
    echo 'پل در پس‌زمینه شروع شد؛ گزارش اجرا در:' "$LOG_FILE"
    echo 'برای شروع خودکار پس از ورود، systemd user فعال کنید یا همین نصب‌گر را دوباره اجرا کنید.'
  fi
fi

echo
echo 'گام بعد: اپ WP Store Manager را در همین رایانه باز کنید، «اتصال دستگاه» را انتخاب و جفت‌سازی امن را تأیید کنید.'
echo 'نیازی به واردکردن Access Key در نصب‌گر نیست؛ کد جفت‌سازی کوتاه‌عمر از نشست مدیر WordPress صادر می‌شود.'
echo 'پس از جفت‌سازی، IP خصوصی ZKTeco و پورت TCP (پیش‌فرض 4370) را در همان پنل اضافه و آزمون کنید.'
echo 'فایل پیکربندی پل در پروفایل کاربر با مجوز محدود ذخیره می‌شود؛ دستگاه را در اینترنت عمومی در معرض قرار ندهید.'
