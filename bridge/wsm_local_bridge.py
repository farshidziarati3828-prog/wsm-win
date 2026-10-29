#!/usr/bin/env python3
"""WP Store Manager local bridge for signed attendance sync and local hardware discovery.

The bridge never exports raw biometric templates. A configured Modbus gate can receive a short
coil pulse only after explicit local opt-in and a fresh WordPress "allowed" response. Device
compatibility and timing remain model-, firmware-, network-, and installation-specific.
"""
from __future__ import annotations

import argparse
import base64
import binascii
import getpass
import hashlib
import ipaddress
import json
import os
import re
import platform
import queue
import secrets
import socket
import sys
import threading
from concurrent.futures import ThreadPoolExecutor
import time
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from zoneinfo import ZoneInfo
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

APP_VERSION = "2.3.1"
_DEVICE_ID_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
DEFAULT_PORT = 4370
DEFAULT_LOCAL_API_PORT = 8765
LOCAL_API_PREFIX = "/api/v1"
MAX_LOCAL_BODY_BYTES = 4 * 1024 * 1024
POLL_SECONDS = 45
_DEVICE_ALLOWED_NETWORKS = tuple(ipaddress.ip_network(value) for value in (
    "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "169.254.0.0/16", "127.0.0.0/8",
))

_DEVICE_LOCKS = {}
_DEVICE_LOCKS_GUARD = threading.Lock()
_DEVICE_STATUS = {}
_DEVICE_STATUS_LOCK = threading.Lock()
_CONFIG_LOCK = threading.RLock()
_PAIR_EVENT = threading.Event()
_SSE_SUBSCRIBERS = set()
_SSE_SUBSCRIBERS_LOCK = threading.Lock()
_SSE_SEQUENCE = 0
_HARDWARE_EVENTS = []
_HARDWARE_EVENTS_LOCK = threading.Lock()
_HARDWARE_EVENT_LIMIT = 200
_GATE_OPERATION_LOCK = threading.RLock()
_GATE_LAST_OPEN = {}
NETWORK_SCAN_PORTS = (4370, 515, 9100, 502)
_HARDWARE_CAPABILITIES = {
    "printers": {
        "escpos_receipt": {"supported": True, "paper_width_mm": 80, "transports": ["tcp", "serial", "usb"],
                           "confirmation": "bytes_sent_only"},
        "label_print": {"supported": False, "status_probe_protocols": ["zpl", "tspl"]},
        "browser_print_fallback": {"supported": True, "pdf_via_system_dialog": True},
        "qz_tray": {"supported": False},
    },
    "barcode": {
        "hid_keyboard_wedge": {"supported": True, "requires_focused_input": True},
        "serial_identification": {"supported": True, "barcode_value_persisted": False},
        "webhid_direct": {"supported": False}, "webserial_direct": {"supported": False},
        "webusb_direct": {"supported": False},
    },
    "access_control": {
        "modbus_tcp": True, "modbus_rtu": True, "automatic_open_default": False,
        "osdp": False, "wiegand": False, "generic_dry_contact_control": False,
    },
    "biometrics_and_rfid": {
        "zkteco_pyzku": True, "generic_face_matching": False, "pcsc_nfc": False,
    },
    "mock_mode": {"supported": True, "hardware_side_effects": False},
}


def config_dir() -> Path:
    if os.name == "nt":
        root = os.environ.get("LOCALAPPDATA") or str(Path.home() / "AppData" / "Local")
        path = Path(root) / "WPStoreManagerBridge"
    else:
        path = Path(os.environ.get("XDG_CONFIG_HOME", Path.home() / ".config")) / "wp-store-manager-bridge"
    path.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(path, 0o700)
    except OSError:
        pass
    return path


def config_path() -> Path:
    return config_dir() / "config.json"


def save_config(cfg: dict) -> None:
    with _CONFIG_LOCK:
        path = config_path()
        tmp = path.with_suffix(".tmp")
        tmp.write_text(json.dumps(cfg, ensure_ascii=False, indent=2), encoding="utf-8")
        try:
            os.chmod(tmp, 0o600)
        except OSError:
            pass
        tmp.replace(path)


def load_config() -> dict:
    with _CONFIG_LOCK:
        path = config_path()
        if not path.exists():
            raise RuntimeError("پیکربندی موجود نیست؛ ابتدا دستور setup را اجرا کنید.")
        cfg = json.loads(path.read_text(encoding="utf-8"))
        required = ("api_base", "token", "refresh_token", "signing_key", "device_id")
        if any(not cfg.get(k) for k in required):
            raise RuntimeError("پیکربندی ناقص است؛ دستور setup را دوباره اجرا کنید.")
        return cfg


def normalize_api_base(value: str) -> str:
    value = value.strip()
    if not value:
        raise ValueError("نشانی REST الزامی است.")
    parsed = urllib.parse.urlparse(value)
    if parsed.scheme not in ("https", "http") or not parsed.netloc:
        raise ValueError("نشانی REST باید یک URL کامل مانند https://example.com/wp-json/wsm/v1/ باشد.")
    host = (parsed.hostname or "").lower()
    if parsed.scheme != "https" and host not in ("localhost", "127.0.0.1", "::1"):
        raise ValueError("برای سایت غیرمحلی، HTTPS الزامی است.")
    return value if value.endswith("/") else value + "/"


def request_json(url: str, method: str = "GET", body: dict | None = None, headers: dict | None = None, timeout: int = 15):
    data = None if body is None else json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    request_headers = {"Accept": "application/json", "User-Agent": "WSM-Local-Bridge/" + APP_VERSION}
    if headers:
        request_headers.update(headers)
    if data is not None:
        request_headers["Content-Type"] = "application/json; charset=utf-8"
    req = urllib.request.Request(url, data=data, headers=request_headers, method=method.upper())
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            raw = response.read(2 * 1024 * 1024)
            return response.status, json.loads(raw.decode("utf-8") or "{}")
    except urllib.error.HTTPError as exc:
        try:
            payload = json.loads(exc.read(1024 * 1024).decode("utf-8") or "{}")
        except Exception:
            payload = {"message": "HTTP error"}
        return exc.code, payload
    except urllib.error.URLError as exc:
        raise RuntimeError("ارتباط با وردپرس برقرار نشد: " + str(getattr(exc, "reason", exc))) from exc


class ApiClient:
    def __init__(self, cfg: dict):
        self.cfg = cfg

    def url(self, path: str) -> str:
        return self.cfg["api_base"] + path.lstrip("/")

    def handshake(self, api_base: str, access_key: str, device_name: str):
        body = {
            "access_key": access_key,
            "device_name": device_name,
            "platform": platform.system() or "Desktop",
            "os_version": platform.release()[:40],
            "app_version": APP_VERSION,
        }
        status, data = request_json(api_base + "handshake", "POST", body)
        if 200 <= status < 300 and data.get("login_required"):
            raise RuntimeError(
                "این WordPress از Access Key فقط برای آغاز ورود استفاده می‌کند و نشست قابل‌استفادهٔ پل نمی‌سازد؛ "
                "اپ دسکتاپ را با حساب مدیر باز کنید و «اتصال دستگاه ← جفت‌سازی امن با WordPress» را انجام دهید. "
                "برای جفت‌سازی تازه، Access Key را در تنظیمات Local Bridge وارد نکنید."
            )
        if status < 200 or status >= 300 or not data.get("token"):
            raise RuntimeError(data.get("message", "اتصال اولیه ناموفق بود."))
        self.cfg.update({
            "api_base": api_base,
            "token": data["token"],
            "refresh_token": data.get("refresh_token", ""),
            "signing_key": data.get("signing_key", ""),
            "device_id": int(data.get("device_id", 0)),
            "store_name": data.get("store_name", ""),
            "local_api_key": self.cfg.get("local_api_key") or secrets.token_urlsafe(32),
            "device_name": device_name,
            "zkteco_devices": self.cfg.get("zkteco_devices", []),
            "sent_event_ids": self.cfg.get("sent_event_ids", {}),
            "last_access_sync": 0,
        })
        if not self.cfg["signing_key"] or not self.cfg["device_id"]:
            raise RuntimeError("پاسخ اتصال کلید امضا یا شناسهٔ دستگاه نداشت.")
        self.me()

    def refresh(self) -> bool:
        status, data = request_json(self.url("refresh"), "POST", {"refresh_token": self.cfg.get("refresh_token", "")})
        if status == 200 and data.get("token"):
            with _CONFIG_LOCK:
                latest = load_config()
                latest["token"] = data["token"]
                latest["refresh_token"] = data.get("refresh_token", latest.get("refresh_token", ""))
                save_config(latest)
                self.cfg.update(latest)
            return True
        return False

    def call(self, path: str, method: str = "GET", body: dict | None = None, query: dict | None = None, retry: bool = True):
        path_only = path.split("?", 1)[0].lstrip("/")
        url = self.url(path)
        if query:
            parsed = urllib.parse.urlsplit(url)
            q = urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)
            q.extend((str(k), str(v)) for k, v in query.items() if v is not None and v != "")
            url = urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, parsed.path, urllib.parse.urlencode(q), parsed.fragment))
        raw = None if body is None else json.dumps(body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        stamp = str(int(time.time()))
        nonce = os.urandom(16).hex()
        body_hash = hashlib.sha256(raw or b"").hexdigest()
        route = "/wsm/v1/" + path_only
        canonical = "\n".join((method.upper(), route, stamp, nonce, body_hash)).encode("utf-8")
        sig = hmac_sha256(self.cfg["signing_key"].encode("utf-8"), canonical)
        headers = {
            "Authorization": "Bearer " + self.cfg["token"],
            "X-WSM-Token": self.cfg["token"],
            "X-WSM-Timestamp": stamp,
            "X-WSM-Nonce": nonce,
            "X-WSM-Signature": sig,
        }
        status, data = request_json(url, method, body, headers)
        if status == 401 and retry and data.get("code") in ("wsm_token_expired", "wsm_token_invalid") and self.refresh():
            return self.call(path, method, body, query, retry=False)
        if status < 200 or status >= 300:
            detail = data.get("message", "درخواست REST ناموفق بود")
            if status == 403 and data.get("code") == "wsm_device_permission_denied":
                detail += " — مجوز دستگاه را در پیشخوان وردپرس بررسی کنید."
            raise RuntimeError("HTTP " + str(status) + ": " + str(detail))
        return data

    def me(self):
        return self.call("me")


def hmac_sha256(key: bytes, message: bytes) -> str:
    import hmac
    return hmac.new(key, message, hashlib.sha256).hexdigest()


def _stdin_json() -> dict:
    try:
        value = json.loads(sys.stdin.readline() or "{}")
    except json.JSONDecodeError as exc:
        raise RuntimeError("دادهٔ راه‌اندازی نامعتبر است.") from exc
    if not isinstance(value, dict):
        raise RuntimeError("دادهٔ راه‌اندازی باید شیء JSON باشد.")
    return value


def configure(api_value: str, access_key_value: str, device_name: str) -> dict:
    api_base = normalize_api_base(str(api_value or ""))
    access_key = str(access_key_value or "").strip()
    if not access_key:
        raise RuntimeError("Access Key خالی است.")
    current = {}
    if config_path().exists():
        try:
            current = json.loads(config_path().read_text(encoding="utf-8"))
        except Exception:
            current = {}
    cfg = dict(current)
    client = ApiClient(cfg)
    client.handshake(api_base, access_key, (str(device_name or "").strip() or "پل سخت‌افزاری باشگاه")[:100])
    save_config(cfg)
    print("اتصال ثبت شد. نام فروشگاه: " + (cfg.get("store_name") or "—"))
    try:
        info = client.me()
        perms = info.get("device_permissions", [])
        if "club.bridge" not in perms:
            print("توجه: در پیشخوان وردپرس → مدیر فروشگاه → دستگاه‌ها، مجوز «همگام‌سازی پل سخت‌افزاری باشگاه» را برای این دستگاه فعال کنید.")
    except Exception as exc:
        print("دستگاه ساخته شد؛ پل پس از اعطای مجوز و اتصال دوباره ادامه می‌دهد: " + str(exc))
    print("پیکربندی محرمانه در: " + str(config_path()))
    return cfg


def test_store(_args):
    """Test an already-saved signed session without creating another WordPress device."""
    cfg = load_config()
    info = ApiClient(cfg).me()
    store_name = info.get("store_name") or cfg.get("store_name") or "فروشگاه"
    print("آزمون فروشگاه موفق است؛ اتصال امن و امضای HMAC پاسخ معتبر گرفتند. نام فروشگاه: " + str(store_name))
    if "club.bridge" in info.get("device_permissions", []):
        print("مجوز club.bridge فعال است؛ پل اجازهٔ همگام‌سازی رویدادهای باشگاه را دارد.")
    else:
        print("اتصال برقرار است، اما هنوز مجوز club.bridge برای این دستگاه فعال نیست. آن را در پیشخوان وردپرس ← مدیر فروشگاه ← دستگاه‌ها فعال کنید.")


def setup(args):
    if getattr(args, "stdin_json", False):
        data = _stdin_json()
        configure(data.get("api_base", ""), data.get("access_key", ""), data.get("device_name", ""))
        return
    entered = input("REST API root (مثلاً https://site.example/wp-json/wsm/v1/): ").strip()
    access_key = getpass.getpass("Access Key افزونه: ").strip()
    name = input("نام این پل (پیش‌فرض پل باشگاه): ").strip() or "پل سخت‌افزاری باشگاه"
    configure(entered, access_key, name)


def build_zk_device(host_value, port_value, password_value, name_value, direction_value, transport_value="tcp", timeout_value=8):
    try:
        ip = ipaddress.ip_address(str(host_value).strip())
    except ValueError as exc:
        raise ValueError("IP خصوصی معتبر وارد کنید؛ دامنهٔ عمومی پذیرفته نمی‌شود.") from exc
    if not isinstance(ip, ipaddress.IPv4Address):
        raise ValueError("این پل با PyZK روی TCP/IPv4 کار می‌کند؛ IP محلی IPv4 وارد کنید.")
    if not any(ip in network for network in _DEVICE_ALLOWED_NETWORKS):
        raise ValueError("برای امنیت، IP دستگاه باید در شبکهٔ خصوصی/محلی باشگاه باشد.")
    port_text = "" if port_value is None else str(port_value).strip()
    if not port_text:
        port = DEFAULT_PORT
    elif not port_text.isascii() or not port_text.isdigit():
        raise ValueError("پورت باید عدد صحیح انگلیسی باشد.")
    else:
        port = int(port_text)
    if not 1 <= port <= 65535:
        raise ValueError("پورت خارج از محدوده است.")
    password = str(password_value if password_value is not None else "0").strip() or "0"
    if not password.isdigit() or int(password) > 2147483647:
        raise ValueError("رمز ارتباط این نسخه باید عدد صحیح باشد.")
    name = (str(name_value or "").strip() or str(host_value).strip())[:100]
    direction = str(direction_value or "in").strip().lower()
    if direction not in ("in", "out"):
        raise ValueError("جهت باید in یا out باشد.")
    transport = str(transport_value or "tcp").strip().lower()
    if transport not in ("tcp", "udp"):
        raise ValueError("انتقال ZKTeco باید TCP یا UDP باشد.")
    try:
        timeout = max(0.25, min(10.0, float(timeout_value)))
    except (TypeError, ValueError):
        timeout = 8.0
    return {"host": str(ip), "port": port, "password": int(password), "name": name,
            "direction": direction, "transport": transport, "timeout": timeout}


def _normalize_zk_direction_config(body: dict) -> dict:
    source = str(body.get("direction_source", "fixed") or "fixed").strip().lower()
    if source not in ("fixed", "punch", "status"):
        raise ValueError("منبع جهت تردد باید fixed، punch یا status باشد.")
    raw_map = body.get("direction_map", {})
    if raw_map is None:
        raw_map = {}
    if isinstance(raw_map, str):
        pairs = []
        for entry in raw_map.split(","):
            entry = entry.strip()
            if not entry:
                continue
            if "=" not in entry:
                raise ValueError("نگاشت جهت را به‌صورت 0=in,1=out وارد کنید.")
            pairs.append(entry.split("=", 1))
    elif isinstance(raw_map, dict):
        pairs = list(raw_map.items())
    else:
        raise ValueError("نگاشت کدهای جهت معتبر نیست.")
    if len(pairs) > 16:
        raise ValueError("حداکثر ۱۶ کد جهت قابل نگاشت است.")
    direction_map = {}
    for code, direction in pairs:
        key = str(code if code is not None else "").strip().translate(_DEVICE_ID_DIGITS)
        value = str(direction if direction is not None else "").strip().lower()
        if not re.fullmatch(r"[A-Za-z0-9_-]{1,24}", key) or value not in ("in", "out"):
            raise ValueError("کد نگاشت جهت باید لاتین/عدد و مقصد آن in یا out باشد.")
        if key in direction_map and direction_map[key] != value:
            raise ValueError("برای یک کد جهت، دو مقصد متفاوت ثبت شده است.")
        direction_map[key] = value
    if source != "fixed" and not direction_map:
        raise ValueError("برای تفسیر punch/status باید دست‌کم یک نگاشت صریح مانند 0=in,1=out وارد کنید.")
    return {"direction_source": source, "direction_map": direction_map}


def _attendance_direction(device: dict, event) -> str:
    source = str(device.get("direction_source", "fixed") or "fixed").strip().lower()
    direction_map = device.get("direction_map", {})
    if source in ("punch", "status") and isinstance(direction_map, dict):
        raw_code = getattr(event, source, None)
        code = str(raw_code if raw_code is not None else "").strip().translate(_DEVICE_ID_DIGITS)
        mapped = direction_map.get(code)
        if mapped in ("in", "out"):
            return mapped
    return "out" if str(device.get("direction", "in")).lower() == "out" else "in"


def save_zk_device(host_value, port_value, password_value, name_value, direction_value, transport_value="tcp"):
    device = add_or_replace_zk_device(load_config(), {
        "host": host_value, "port": port_value, "password": password_value,
        "name": name_value, "direction": direction_value, "transport": transport_value,
    })
    print("دستگاه ثبت شد. اثرانگشت/کاربران موجود دست‌کاری نمی‌شوند.")


def add_zk(args):
    if getattr(args, "stdin_json", False):
        data = _stdin_json()
        save_zk_device(data.get("host", ""), data.get("port", DEFAULT_PORT), data.get("password", "0"), data.get("name", ""), data.get("direction", "in"), data.get("transport", "tcp"))
        return
    host = (args.host or input("IP دستگاه ZKTeco در LAN: ")).strip()
    port = args.port or input("پورت ZKTeco [4370]: ") or DEFAULT_PORT
    password = getpass.getpass("رمز ارتباط دستگاه (خالی = 0): ").strip() or "0"
    name = (args.name or input("نام دستگاه: ")).strip() or host
    direction = (args.direction or input("جهت دستگاه [in/out] (پیش‌فرض in): ")).strip().lower() or "in"
    save_zk_device(host, port, password, name, direction)

def zk_connect(device):
    try:
        from zk import ZK
    except ImportError as exc:
        raise RuntimeError(
            "کتابخانهٔ اختیاری pyzk نصب نیست. در CMD برای نصب در همین محیط این دستور را اجرا کنید:\n\""
            + sys.executable + "\" -m pip install pyzk==0.9"
        ) from exc
    transport = str(device.get("transport", "tcp")).lower()
    # pyzk.connect() pings first unless ommit_ping is enabled. ICMP may be blocked while
    # the ZKTeco protocol port is reachable; the protocol handshake itself proves reachability.
    zk = ZK(device["host"], port=int(device.get("port", DEFAULT_PORT)), timeout=float(device.get("timeout", 8)),
            password=int(device.get("password", 0)), force_udp=(transport == "udp"), ommit_ping=True)
    return zk.connect()


def device_identifier(device: dict) -> str:
    key = str(device.get("host", "")) + ":" + str(int(device.get("port", DEFAULT_PORT)))
    return hashlib.sha256(key.encode("utf-8")).hexdigest()[:16]


def _device_lock(device: dict):
    key = str(device.get("host", "")) + ":" + str(int(device.get("port", DEFAULT_PORT)))
    with _DEVICE_LOCKS_GUARD:
        return _DEVICE_LOCKS.setdefault(key, threading.RLock())


def _set_device_status(device: dict, online: bool, error: str = "") -> None:
    entry = {
        "online": bool(online),
        "state": "online" if online else "offline",
        "last_checked": int(time.time()),
        "last_error": str(error or "")[:400],
        "verified": bool(online),
    }
    with _DEVICE_STATUS_LOCK:
        _DEVICE_STATUS[device_identifier(device)] = entry


def _device_status(device: dict) -> dict:
    with _DEVICE_STATUS_LOCK:
        entry = dict(_DEVICE_STATUS.get(device_identifier(device), {}))
    if not entry:
        return {"state": "unknown", "online": None, "last_checked": None, "last_error": ""}
    if time.time() - int(entry.get("last_checked", 0)) > max(180, POLL_SECONDS * 4):
        entry["state"] = "unknown"
        entry["online"] = None
    return entry


@contextmanager
def device_session(device: dict, disable: bool = False):
    """Serialize one device's transactions and always release the TCP session."""
    with _device_lock(device):
        conn = None
        connected = False
        try:
            conn = zk_connect(device)
            connected = True
            _set_device_status(device, True)
            if disable:
                conn.disable_device()
            yield conn
        except Exception as exc:
            _set_device_status(device, connected, str(exc))
            raise
        finally:
            if conn is not None:
                if disable:
                    try:
                        conn.enable_device()
                    except Exception as exc:
                        _set_device_status(device, connected, "بازیابی فعال‌بودن دستگاه ناموفق بود: " + str(exc))
                try:
                    conn.disconnect()
                except Exception:
                    pass


def ensure_local_api_key(cfg: dict) -> str:
    with _CONFIG_LOCK:
        latest = load_config()
        key = str(latest.get("local_api_key", ""))
        if len(key) < 32:
            key = secrets.token_urlsafe(32)
            latest["local_api_key"] = key
            save_config(latest)
        cfg["local_api_key"] = key
        return key


def normalize_origin(value: str) -> str:
    try:
        parsed = urllib.parse.urlsplit(str(value).strip())
        if parsed.scheme not in ("https", "http") or not parsed.hostname or parsed.username or parsed.password:
            return ""
        if parsed.path not in ("", "/") or parsed.query or parsed.fragment:
            return ""
        host = parsed.hostname.lower()
        if ":" in host:
            host = "[" + host + "]"
        port = parsed.port
        if port and not ((parsed.scheme == "https" and port == 443) or (parsed.scheme == "http" and port == 80)):
            host += ":" + str(port)
        return parsed.scheme.lower() + "://" + host
    except (ValueError, TypeError):
        return ""


def is_tauri_local_origin(value: str) -> bool:
    text = str(value or "").strip()
    if text.lower() == "tauri://localhost":
        return True
    return normalize_origin(text) in ("http://tauri.localhost", "https://tauri.localhost")


def cors_origin(value: str) -> str:
    text = str(value or "").strip()
    if text.lower() == "tauri://localhost":
        return "tauri://localhost"
    return normalize_origin(text)


def site_origin(cfg: dict) -> str:
    try:
        parsed = urllib.parse.urlsplit(str(cfg.get("api_base", "")))
        return normalize_origin(parsed.scheme + "://" + parsed.netloc)
    except Exception:
        return ""


def local_api_info(_args):
    port = int(getattr(_args, "port", DEFAULT_LOCAL_API_PORT) or DEFAULT_LOCAL_API_PORT)
    if not 1024 <= port <= 65535:
        raise ValueError("پورت API محلی باید بین ۱۰۲۴ تا ۶۵۵۳۵ باشد.")
    cfg = load_config()
    key = ensure_local_api_key(cfg)
    print("Local API: http://127.0.0.1:" + str(port) + LOCAL_API_PREFIX)
    print("Trusted Origin: " + (site_origin(cfg) or "نامشخص؛ اتصال فروشگاه را دوباره تنظیم کنید."))
    print("Header: X-WSM-Local-Key")
    print("Key: " + key)
    print("این کلید محرمانه است؛ فقط در رابط فروشگاه/رایانهٔ مجاز ذخیره شود.")


def _as_ascii_uint(value, label: str, minimum: int, maximum: int) -> int:
    text = str(value if value is not None else "").strip()
    if not text or not text.isascii() or not text.isdigit():
        raise ValueError(label + " باید عدد صحیح انگلیسی باشد.")
    number = int(text)
    if number < minimum or number > maximum:
        raise ValueError(label + " خارج از محدودهٔ مجاز است.")
    return number


def _find_user_by_uid(users, uid: int):
    return next((user for user in users if int(getattr(user, "uid", -1)) == int(uid)), None)


def _normalize_device_user_id(value) -> str:
    return str(value if value is not None else "").strip().translate(_DEVICE_ID_DIGITS)


def _find_user_by_identifier(users, user_id: str):
    target = _normalize_device_user_id(user_id)
    return next((user for user in users
                 if _normalize_device_user_id(getattr(user, "user_id", "")) == target), None)


def _public_user(user) -> dict:
    return {
        "uid": int(getattr(user, "uid", 0)),
        "user_id": str(getattr(user, "user_id", "")),
        "employee_id": str(getattr(user, "user_id", "")),
        "name": str(getattr(user, "name", "")),
        "group_id": str(getattr(user, "group_id", "")),
        "card": int(getattr(user, "card", 0) or 0),
        "privilege": int(getattr(user, "privilege", 0) or 0),
        "is_admin": int(getattr(user, "privilege", 0) or 0) == 14,
        "password_set": bool(str(getattr(user, "password", ""))),
    }


def _normalize_user_input(body: dict, existing=None) -> dict:
    name = str(body.get("name", getattr(existing, "name", "")) or "").strip()
    if not name or len(name) > 24 or any(ord(ch) < 32 for ch in name):
        raise ValueError("نام کاربر دستگاه باید ۱ تا ۲۴ نویسه و بدون کنترل‌کاراکتر باشد.")
    raw_id = body.get("user_id", body.get("employee_id", getattr(existing, "user_id", "")))
    user_id = _normalize_device_user_id(raw_id)
    if not user_id or not user_id.isascii() or not user_id.isdigit() or int(user_id) < 1 or len(user_id) > 10 or int(user_id) > 4294967295:
        raise ValueError("شمارهٔ پرسنلی/شناسهٔ دستگاه باید عدد انگلیسی بین ۱ تا ۴۲۹۴۹۶۷۲۹۵ باشد.")
    if "password" in body and body.get("password") is not None:
        password = str(body.get("password", ""))
    else:
        password = str(getattr(existing, "password", "") or "")
    if len(password) > 8 or (password and (not password.isascii() or not password.isdigit())):
        raise ValueError("رمز کاربر دستگاه باید خالی یا حداکثر ۸ رقم انگلیسی باشد.")
    raw_group = body.get("group_id", getattr(existing, "group_id", ""))
    group_id = str(raw_group or "").strip()
    if group_id and (not group_id.isascii() or not group_id.isdigit() or int(group_id) > 255):
        raise ValueError("شناسهٔ گروه باید عددی بین ۰ تا ۲۵۵ یا خالی باشد.")
    raw_card = body.get("card", getattr(existing, "card", 0))
    card = _as_ascii_uint(raw_card or 0, "شمارهٔ کارت", 0, 4294967295)
    return {
        "uid": int(getattr(existing, "uid", 0)) if existing is not None else None,
        "name": name,
        "user_id": user_id,
        "password": password,
        "group_id": group_id,
        "card": card,
        "privilege": int(getattr(existing, "privilege", 0) or 0) if existing is not None else 0,
    }


def upsert_device_user(device: dict, body: dict, uid: int | None = None) -> dict:
    if not isinstance(body, dict):
        raise ValueError("بدنهٔ کاربر دستگاه نامعتبر است.")
    with device_session(device, disable=True) as conn:
        users = conn.get_users() or []
        existing = _find_user_by_uid(users, uid) if uid is not None else None
        if uid is not None and existing is None:
            raise KeyError("کاربر دستگاه پیدا نشد.")
        incoming_id = str(body.get("user_id", body.get("employee_id", getattr(existing, "user_id", ""))) or "").strip()
        by_identifier = _find_user_by_identifier(users, incoming_id) if incoming_id else None
        if existing is None and by_identifier is not None:
            existing = by_identifier
        elif existing is not None and by_identifier is not None and int(by_identifier.uid) != int(existing.uid):
            raise ValueError("این شمارهٔ پرسنلی از قبل به کاربر دیگری در دستگاه اختصاص دارد.")
        data = _normalize_user_input(body, existing)
        if existing is None:
            data["uid"] = None  # PyZK allocates the next free internal UID after get_users().
        conn.set_user(uid=data["uid"], name=data["name"], privilege=data["privilege"],
                      password=data["password"], group_id=data["group_id"],
                      user_id=data["user_id"], card=data["card"])
        saved = _find_user_by_identifier(conn.get_users() or [], data["user_id"])
        if saved is None:
            raise RuntimeError("فرمان ثبت ارسال شد اما بازخوانی کاربر از دستگاه موفق نبود.")
        return _public_user(saved)


def delete_device_user(device: dict, uid: int, confirm: bool) -> dict:
    if confirm is not True:
        raise ValueError("برای حذف کاربر، فیلد confirm=true الزامی است.")
    with device_session(device, disable=True) as conn:
        users = conn.get_users() or []
        user = _find_user_by_uid(users, uid)
        if user is None:
            raise KeyError("کاربر دستگاه پیدا نشد.")
        if int(getattr(user, "privilege", 0) or 0) == 14:
            raise PermissionError("حذف مدیر دستگاه از راه دور برای جلوگیری از قفل‌شدن ترمینال مجاز نیست.")
        conn.delete_user(uid=int(uid))
        return {"deleted": True, "user": _public_user(user)}


def list_user_fingerprints(device: dict, uid: int) -> dict:
    with device_session(device) as conn:
        user = _find_user_by_uid(conn.get_users() or [], uid)
        if user is None:
            raise KeyError("کاربر دستگاه پیدا نشد.")
        if not hasattr(conn, "get_templates"):
            return {"uid": int(uid), "supported": False, "slots": [], "template_bytes_returned": False}
        # Read templates only transiently in the local process; never serialize or persist template bytes.
        templates = conn.get_templates() or []
        slots = [{"finger_id": int(item.fid), "valid": bool(item.valid)}
                 for item in templates if int(item.uid) == int(uid)]
        del templates
        return {"uid": int(uid), "supported": True, "slots": slots, "template_bytes_returned": False}


def enroll_device_fingerprint(device: dict, uid: int, finger_id: int) -> dict:
    if not 0 <= int(finger_id) <= 9:
        raise ValueError("شمارهٔ انگشت باید بین ۰ تا ۹ باشد.")
    with device_session(device) as conn:
        user = _find_user_by_uid(conn.get_users() or [], uid)
        if user is None:
            raise KeyError("ابتدا کاربر را در دستگاه ثبت کنید.")
        if not hasattr(conn, "enroll_user"):
            raise RuntimeError("نسخه/مدل دستگاه enrollment از راه دور را پشتیبانی نمی‌کند.")
        # Compatible devices prompt the person at the terminal; no biometric template is sent to WordPress.
        enrolled = bool(conn.enroll_user(uid=int(uid), temp_id=int(finger_id), user_id=str(user.user_id)))
        if not enrolled:
            raise RuntimeError("ثبت اثرانگشت کامل نشد؛ سازگاری مدل، حضور کاربر و پیام دستگاه را بررسی کنید.")
        return {"uid": int(uid), "user_id": str(user.user_id), "finger_id": int(finger_id), "enrolled": True,
                "template_bytes_returned": False, "message": "اثر انگشت روی خود دستگاه ثبت شد."}


def delete_device_fingerprint(device: dict, uid: int, finger_id: int, confirm: bool) -> dict:
    if confirm is not True:
        raise ValueError("برای حذف اثرانگشت، فیلد confirm=true الزامی است.")
    if not 0 <= int(finger_id) <= 9:
        raise ValueError("شمارهٔ انگشت باید بین ۰ تا ۹ باشد.")
    with device_session(device, disable=True) as conn:
        user = _find_user_by_uid(conn.get_users() or [], uid)
        if user is None:
            raise KeyError("کاربر دستگاه پیدا نشد.")
        if not hasattr(conn, "delete_user_template"):
            raise RuntimeError("حذف اثرانگشت در این کتابخانه/مدل در دسترس نیست.")
        deleted = conn.delete_user_template(uid=int(uid), temp_id=int(finger_id), user_id=str(user.user_id))
        if not deleted:
            raise KeyError("قالب اثرانگشت این جایگاه پیدا نشد یا دستگاه حذف را نپذیرفت.")
        return {"uid": int(uid), "finger_id": int(finger_id), "deleted": True}


def add_or_replace_zk_device(cfg: dict, body: dict) -> dict:
    device = build_zk_device(body.get("host", ""), body.get("port", DEFAULT_PORT),
                             body.get("password", "0"), body.get("name", ""), body.get("direction", "in"),
                             body.get("transport", "tcp"), body.get("timeout", 8))
    for key in ("model", "serial_number", "firmware", "platform", "mac"):
        if body.get(key):
            device[key] = str(body[key])[:120]
    device.update(_normalize_zk_direction_config(body))
    counts = body.get("counts")
    if isinstance(counts, dict):
        device["counts"] = {key: max(0, min(10_000_000, int(counts.get(key, 0) or 0)))
                            for key in ("users", "fingerprints", "attendance_records") if str(counts.get(key, "")).isdigit()}
    with _CONFIG_LOCK:
        latest = load_config()
        devices = latest.setdefault("zkteco_devices", [])
        latest["zkteco_devices"] = [d for d in devices if not (
            str(d.get("host", "")) == device["host"] and int(d.get("port", DEFAULT_PORT)) == device["port"])]
        latest["zkteco_devices"].append(device)
        latest.setdefault("sent_event_ids", {})
        save_config(latest)
        cfg["zkteco_devices"] = latest["zkteco_devices"]
        cfg["sent_event_ids"] = latest["sent_event_ids"]
    return device


def get_configured_device(cfg: dict, identifier: str) -> dict:
    for item in cfg.get("zkteco_devices", []):
        try:
            normalized = build_zk_device(item.get("host", ""), item.get("port", DEFAULT_PORT),
                                         item.get("password", "0"), item.get("name", ""), item.get("direction", "in"),
                                         item.get("transport", "tcp"), item.get("timeout", 8))
            for key in ("model", "serial_number", "firmware", "platform", "mac"):
                if item.get(key):
                    normalized[key] = str(item[key])[:120]
            normalized.update(_normalize_zk_direction_config(item))
            if isinstance(item.get("counts"), dict):
                normalized["counts"] = dict(item["counts"])
        except (ValueError, TypeError):
            continue
        if device_identifier(normalized) == identifier:
            return normalized
    raise KeyError("دستگاه در پیکربندی پل پیدا نشد.")


def _configured_zk_by_address(cfg: dict, host: str, port: int = DEFAULT_PORT) -> dict | None:
    for item in cfg.get("zkteco_devices", []):
        try:
            normalized = build_zk_device(item.get("host", ""), item.get("port", DEFAULT_PORT),
                                         item.get("password", "0"), item.get("name", ""), item.get("direction", "in"),
                                         item.get("transport", "tcp"), item.get("timeout", 8))
            normalized.update(_normalize_zk_direction_config(item))
            for key in ("model", "serial_number", "firmware", "platform", "mac"):
                if item.get(key):
                    normalized[key] = str(item[key])[:120]
            if isinstance(item.get("counts"), dict):
                normalized["counts"] = dict(item["counts"])
        except (ValueError, TypeError):
            continue
        if normalized["host"] == str(host) and int(normalized["port"]) == int(port):
            return normalized
    return None


def public_device(device: dict) -> dict:
    return {
        "id": device_identifier(device), "host": device["host"], "port": int(device.get("port", DEFAULT_PORT)),
        "type": "zkteco", "type_label": "ZKTeco / بیومتریک", "transport": device.get("transport", "tcp"),
        "name": device.get("name", device["host"]), "model": device.get("model", "ZKTeco / PyZK"),
        "serial_number": device.get("serial_number", ""), "firmware": device.get("firmware", ""),
        "counts": dict(device.get("counts", {})) if isinstance(device.get("counts"), dict) else {},
        "platform": device.get("platform", ""), "mac": device.get("mac", ""),
        "direction": device.get("direction", "in"),
        "direction_source": device.get("direction_source", "fixed"),
        "direction_map": dict(device.get("direction_map", {})) if isinstance(device.get("direction_map"), dict) else {},
        "password_configured": bool(device.get("password", 0)),
        "status": _device_status(device),
    }


def discover_zkteco_devices(port: int = DEFAULT_PORT, cfg: dict | None = None) -> list[dict]:
    """Find TCP-open ZKTeco candidates on the local IPv4 /24 networks (no writes to devices)."""
    if not 1 <= int(port) <= 65535:
        raise ValueError("پورت کشف دستگاه خارج از محدوده است.")
    addresses = set()
    try:
        for item in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET, socket.SOCK_STREAM):
            address = ipaddress.IPv4Address(item[4][0])
            if address not in ipaddress.ip_network("127.0.0.0/8") and any(address in net for net in _DEVICE_ALLOWED_NETWORKS[:-1]):
                addresses.add(address)
    except OSError:
        pass
    cfg = cfg or {}
    try:
        parsed = urllib.parse.urlsplit(str(cfg.get("api_base", "")))
        remote_port = parsed.port or (443 if parsed.scheme == "https" else 80)
        for item in socket.getaddrinfo(parsed.hostname, remote_port, socket.AF_INET, socket.SOCK_DGRAM):
            probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            try:
                probe.connect(item[4])
                source = ipaddress.IPv4Address(probe.getsockname()[0])
                if source not in ipaddress.ip_network("127.0.0.0/8") and any(source in net for net in _DEVICE_ALLOWED_NETWORKS[:-1]):
                    addresses.add(source)
            finally:
                probe.close()
    except (OSError, ValueError, TypeError):
        pass
    networks = sorted({ipaddress.ip_network(str(address) + "/24", strict=False) for address in addresses}, key=lambda n: (int(n.network_address), n.prefixlen))[:4]
    targets = [str(host) for network in networks for host in network.hosts() if host not in addresses]
    if not targets:
        return []

    def probe(host: str):
        try:
            with socket.create_connection((host, int(port)), timeout=0.35):
                return {"host": host, "port": int(port), "name": host, "state": "tcp_open"}
        except OSError:
            return None

    found = []
    with ThreadPoolExecutor(max_workers=min(64, len(targets))) as executor:
        for result in executor.map(probe, targets):
            if result:
                found.append(result)
    return sorted(found, key=lambda item: int(ipaddress.IPv4Address(item["host"])))


def probe_device(device: dict) -> dict:
    try:
        with device_session(device) as conn:
            device_time = conn.get_time()
            info = {"device_time": device_time.isoformat(timespec="seconds") if hasattr(device_time, "isoformat") else str(device_time)}
            for key, method_name in (("model", "get_device_name"), ("serial_number", "get_serialnumber"),
                                     ("platform", "get_platform"), ("mac", "get_mac"), ("firmware", "get_firmware_version")):
                try:
                    method = getattr(conn, method_name, None)
                    info[key] = str(method())[:120] if callable(method) else None
                except Exception:
                    info[key] = None
            try:
                conn.read_sizes()
                info["counts"] = {
                    "users": int(getattr(conn, "users", 0)),
                    "fingerprints": int(getattr(conn, "fingers", 0)),
                    "attendance_records": int(getattr(conn, "records", 0)),
                }
            except Exception:
                info["counts"] = None
        info.update({"online": True, "state": "online", "verified": True, "last_checked": int(time.time()), "error": ""})
        device.update({key: info[key] for key in ("model", "serial_number", "firmware", "platform", "mac", "counts") if info.get(key) is not None})
        try:
            identifier = device_identifier(device)
            with _CONFIG_LOCK:
                latest = load_config()
                changed = False
                for saved in latest.get("zkteco_devices", []):
                    if device_identifier(saved) == identifier:
                        for key in ("model", "serial_number", "firmware", "platform", "mac", "counts"):
                            if key in device:
                                saved[key] = device[key]
                        changed = True
                        break
                if changed:
                    save_config(latest)
        except (OSError, RuntimeError, ValueError, TypeError):
            pass
        return info
    except Exception as exc:
        info = {"online": False, "state": "offline", "verified": False, "last_checked": int(time.time()), "error": str(exc)[:400]}
        return info


def _escpos_raster_job(body: dict) -> tuple[bytes, int]:
    if not isinstance(body, dict):
        raise ValueError("بدنهٔ رسید معتبر نیست.")
    try:
        width = int(body.get("width", 0))
        height = int(body.get("height", 0))
    except (TypeError, ValueError) as exc:
        raise ValueError("اندازهٔ رسید معتبر نیست.") from exc
    if width != 576 or not 1 <= height <= 24000:
        raise ValueError("رسید باید با عرض ۸۰ میلی‌متر و ارتفاع معتبر باشد.")
    row_bytes = (width + 7) // 8
    expected = row_bytes * height
    encoded = body.get("raster")
    if not isinstance(encoded, str) or not encoded or len(encoded) > 2400000:
        raise ValueError("دادهٔ تصویری رسید خالی یا بیش از حد بزرگ است.")
    try:
        raster = base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise ValueError("دادهٔ تصویری رسید معتبر نیست.") from exc
    if len(raster) != expected or expected > 1728000:
        raise ValueError("اندازهٔ دادهٔ رسید با عرض و ارتفاع اعلام‌شده هم‌خوانی ندارد.")
    x_low, x_high = row_bytes & 0xFF, (row_bytes >> 8) & 0xFF
    y_low, y_high = height & 0xFF, (height >> 8) & 0xFF
    job = bytearray(b"\x1b@\x1ba\x00\x1dv0\x00")
    job.extend(bytes((x_low, x_high, y_low, y_high)))
    job.extend(raster)
    job.extend(b"\n\n\x1dV\x00")
    return bytes(job), height


class PrintDeliveryError(RuntimeError):
    def __init__(self, message: str, delivery_uncertain: bool):
        super().__init__(message)
        self.delivery_uncertain = bool(delivery_uncertain)


def _usb_find(*args, **kwargs):
    try:
        import libusb_package
    except ImportError:
        import usb.core
        return usb.core.find(*args, **kwargs)
    return libusb_package.find(*args, **kwargs)


def _send_escpos_usb(device: dict, payload: bytes) -> int:
    try:
        import usb.core
        import usb.util
    except ImportError as exc:
        raise RuntimeError("برای چاپ USB، وابستگی pyusb پل نصب نیست.") from exc
    try:
        vendor_id = int(str(device.get("usb_vendor_id", "")), 16)
        product_id = int(str(device.get("usb_product_id", "")), 16)
    except (TypeError, ValueError) as exc:
        raise ValueError("شناسهٔ USB پرینتر کامل نیست.") from exc
    matches = list(_usb_find(find_all=True, idVendor=vendor_id, idProduct=product_id) or [])
    serial_number = str(device.get("usb_serial", "") or "")
    if serial_number:
        filtered = []
        for candidate in matches:
            try:
                if str(usb.util.get_string(candidate, candidate.iSerialNumber) or "") == serial_number:
                    filtered.append(candidate)
            except Exception:
                continue
        matches = filtered
    if not matches:
        raise RuntimeError("پرینتر USB متصل نیست یا شناسهٔ آن پیدا نشد.")
    usb_device = matches[0]
    try:
        try:
            try:
                configuration = usb_device.get_active_configuration()
            except Exception:
                usb_device.set_configuration()
                configuration = usb_device.get_active_configuration()
        except Exception as exc:
            raise RuntimeError("پیکربندی USB آماده نشد؛ هیچ داده‌ای ارسال نشده است: " + str(exc)[:140]) from exc
        selected = None
        for interface in configuration:
            for endpoint in interface:
                is_out = usb.util.endpoint_direction(endpoint.bEndpointAddress) == usb.util.ENDPOINT_OUT
                is_bulk = (int(endpoint.bmAttributes) & 0x03) == usb.util.ENDPOINT_TYPE_BULK
                if is_out and is_bulk:
                    selected = (int(interface.bInterfaceNumber), endpoint)
                    break
            if selected:
                break
        if not selected:
            raise RuntimeError("در پرینتر USB مسیر خروجی Bulk پیدا نشد.")
        interface_number, endpoint = selected
        try:
            if os.name != "nt" and hasattr(usb_device, "is_kernel_driver_active") and usb_device.is_kernel_driver_active(interface_number):
                usb_device.detach_kernel_driver(interface_number)
        except Exception:
            pass
        try:
            usb.util.claim_interface(usb_device, interface_number)
        except Exception as exc:
            raise RuntimeError("رابط USB باز نشد؛ هیچ داده‌ای ارسال نشده است: " + str(exc)[:140]) from exc
        try:
            sent = int(endpoint.write(payload, timeout=10000))
        except Exception as exc:
            raise PrintDeliveryError("نوشتن USB آغاز شد اما کامل‌شدن job نامشخص است؛ برای جلوگیری از رسید تکراری وضعیت چاپگر را بررسی کنید.", True) from exc
        if sent != len(payload):
            raise PrintDeliveryError("ارسال USB ناقص بود؛ نتیجهٔ چاپ نامشخص است.", True)
        return sent
    finally:
        try:
            if "interface_number" in locals():
                usb.util.release_interface(usb_device, interface_number)
        except Exception:
            pass
        try:
            usb.util.dispose_resources(usb_device)
        except Exception:
            pass


def _send_escpos_receipt(device: dict, body: dict) -> dict:
    dtype = str(device.get("type", ""))
    protocol = str(device.get("protocol", "") or "").lower()
    allowed = (dtype == "thermal_printer" and protocol in ("escpos", "auto")) or (dtype == "network_printer" and protocol == "escpos")
    if not allowed:
        raise ValueError("این دستگاه با پروتکل ESC/POS رسید ۸۰ میلی‌متری را پشتیبانی نمی‌کند.")
    job, height = _escpos_raster_job(body)
    transport = str(device.get("transport", "tcp")).lower()
    if transport == "tcp":
        host, port = str(device.get("host", "")), int(device.get("port", 0))
        if not host or not 1 <= port <= 65535:
            raise ValueError("نشانی TCP پرینتر کامل نیست.")
        try:
            connection = socket.create_connection((host, port), timeout=5)
        except Exception as exc:
            raise PrintDeliveryError("اتصال TCP برقرار نشد؛ داده‌ای به چاپگر ارسال نشده است: " + str(exc)[:140], False) from exc
        try:
            try:
                connection.settimeout(10)
            except Exception as exc:
                raise PrintDeliveryError("اتصال برقرار شد اما ارسال job شروع نشد: " + str(exc)[:140], False) from exc
            try:
                connection.sendall(job)
            except Exception as exc:
                raise PrintDeliveryError("ارسال TCP آغاز شد اما نتیجهٔ نهایی نامشخص است؛ برای جلوگیری از رسید تکراری وضعیت چاپگر را بررسی کنید.", True) from exc
        finally:
            try:
                connection.close()
            except Exception:
                pass
        sent = len(job)
    elif transport == "serial":
        try:
            import serial
        except ImportError as exc:
            raise RuntimeError("برای چاپ سریال pyserial نصب نیست؛ داده‌ای ارسال نشده است.") from exc
        port = str(device.get("serial_port", ""))
        if not port:
            raise ValueError("پورت سریال پرینتر انتخاب نشده است.")
        try:
            connection = serial.Serial(port=port, baudrate=int(device.get("baudrate", 9600)), timeout=3, write_timeout=10)
        except Exception as exc:
            raise PrintDeliveryError("پورت سریال باز نشد؛ داده‌ای ارسال نشده است: " + str(exc)[:140], False) from exc
        try:
            try:
                sent = int(connection.write(job))
                connection.flush()
            except Exception as exc:
                raise PrintDeliveryError("نوشتن سریال آغاز شد اما نتیجهٔ نهایی نامشخص است؛ برای جلوگیری از رسید تکراری وضعیت چاپگر را بررسی کنید.", True) from exc
        finally:
            try:
                connection.close()
            except Exception:
                pass
        if sent != len(job):
            raise PrintDeliveryError("ارسال سریال ناقص بود؛ نتیجهٔ چاپ نامشخص است.", True)
    elif transport == "usb":
        sent = _send_escpos_usb(device, job)
    else:
        raise ValueError("اتصال پرینتر باید TCP، سریال یا USB باشد.")
    return {"sent": True, "device_id": str(device.get("id") or _hardware_identifier(device)),
            "device_name": str(device.get("name") or "پرینتر حرارتی"), "transport": transport,
            "paper_width_mm": 80, "width_px": 576, "height_px": height, "bytes_sent": sent}


def simulate_hardware_test(body: dict) -> dict:
    """Return a deterministic mock result without touching hardware, WordPress, or device config."""
    body = body if isinstance(body, dict) else {}
    scenario = str(body.get("scenario", "") or "").strip().lower()
    cases = {
        "printer_escpos": (
            "شبیه‌سازی ESC/POS موفق بود؛ هیچ داده‌ای به چاپگر ارسال نشد.",
            {"protocol": "ESC/POS", "transport": "mock", "would_send_bytes": 256,
             "sent_to_hardware": False, "printed": False, "fallback": "browser_print_dialog"},
        ),
        "printer_label_probe": (
            "پرس‌وجوی وضعیت ZPL/TSPL فقط شبیه‌سازی شد؛ چاپ لیبل پشتیبانی/اجرا نشد.",
            {"protocols": ["ZPL", "TSPL"], "status_probe_only": True,
             "print_job_sent": False, "label_print_supported": False},
        ),
        "barcode_hid": (
            "اسکن HID نمونه دریافت شد؛ مقدار نمونه ذخیره نشد.",
            {"transport": "HID keyboard wedge", "sample_value": "MOCK-5901234123457",
             "value_persisted": False, "focused_input_required": True},
        ),
        "rfid_hid": (
            "ورودی RFID/NFC HID نمونه دریافت شد؛ شناسهٔ کارت ذخیره یا به مجوز واقعی تبدیل نشد.",
            {"transport": "RFID/NFC HID keyboard wedge", "sample_value": "MOCK-RFID-0001",
             "value_persisted": False, "access_granted": False},
        ),
        "barcode_serial": (
            "ورودی سریال نمونه دریافت شد؛ داده‌ای از دستگاه واقعی خوانده نشد.",
            {"transport": "COM/Serial", "bytes_received": 18, "payload_persisted": False},
        ),
        "gate_modbus": (
            "Read Coils شبیه‌سازی شد؛ هیچ فرمان نوشتن یا پالس گیتی صادر نشد.",
            {"protocol": "Modbus", "operation": "Read Coils", "coil_value": False,
             "write_sent": False, "gate_opened": False},
        ),
        "gate_allowed": (
            "پاسخ مجاز شبیه‌سازی شد؛ در Mock هیچ فرمان بازکردن گیت صادر نمی‌شود.",
            {"decision": "allowed", "write_sent": False, "gate_opened": False,
             "mock_suppresses_physical_write": True},
        ),
        "gate_denied": (
            "سناریوی رد مجوز شبیه‌سازی شد؛ فرمان گیت صادر نشد.",
            {"decision": "denied", "write_sent": False, "gate_opened": False},
        ),
        "bridge_disconnect": (
            "قطع پل شبیه‌سازی شد؛ مسیر جایگزین پنجرهٔ چاپ مرورگر انتخاب می‌شود.",
            {"bridge_state": "simulated_offline", "fallback": "browser_print_dialog",
             "pdf_available_via_system_dialog": True, "hardware_write": False},
        ),
    }
    if scenario not in cases:
        raise ValueError("سناریوی شبیه‌سازی معتبر نیست.")
    message, details = cases[scenario]
    return {"mode": "mock", "simulated": True, "side_effects": False, "scenario": scenario,
            "outcome": "passed", "message": message, "details": details,
            "timestamp": datetime.now(timezone.utc).isoformat(timespec="seconds")}


class BridgeAPIError(Exception):
    def __init__(self, status: int, message: str, code: str = "bridge_error"):
        super().__init__(message)
        self.status = int(status)
        self.code = str(code)


# pyzk errors are expected device/protocol failures, not bridge implementation faults.
# Keep this dependency optional so the local bridge can start without pyzk installed.
try:
    from zk.exception import ZKErrorConnection as _ZKErrorConnection
    from zk.exception import ZKErrorResponse as _ZKErrorResponse
    from zk.exception import ZKNetworkError as _ZKNetworkError
    _ZK_ERRORS = (_ZKNetworkError, _ZKErrorResponse, _ZKErrorConnection)
except Exception:  # pyzk is optional
    _ZK_ERRORS = ()


def test_zk(args):
    """Perform a real LAN connection and read-only attendance-log test."""
    if getattr(args, "stdin_json", False):
        data = _stdin_json()
        device = build_zk_device(data.get("host", ""), data.get("port", DEFAULT_PORT), data.get("password", "0"), data.get("name", ""), data.get("direction", "in"), data.get("transport", "tcp"))
    else:
        host = (args.host or input("IP دستگاه ZKTeco در LAN: ")).strip()
        port = args.port or input("پورت ZKTeco [4370]: ") or DEFAULT_PORT
        password = getpass.getpass("رمز ارتباط دستگاه (خالی = 0): ").strip() or "0"
        name = (args.name or input("نام دستگاه: ")).strip() or host
        direction = (args.direction or "in").strip().lower()
        device = build_zk_device(host, port, password, name, direction, getattr(args, "transport", "tcp"))
    conn = None
    try:
        conn = zk_connect(device)
        records = conn.get_attendance() or []
        print("آزمون واقعی دستگاه موفق است: اتصال شبکه و خواندن گزارش تردد برقرار شد؛ رویداد موجود: " + str(len(records)))
        print("این آزمون فقط گزارش تردد را می‌خواند؛ اثرانگشت/کاربران دستگاه را تغییر نمی‌دهد و رلهٔ گیت را کنترل نمی‌کند.")
    finally:
        if conn is not None:
            try:
                conn.disconnect()
            except Exception:
                pass


def publish_local_event(event_name: str, payload: dict) -> None:
    global _SSE_SEQUENCE
    with _SSE_SUBSCRIBERS_LOCK:
        _SSE_SEQUENCE += 1
        item = {"id": _SSE_SEQUENCE, "event": str(event_name), "data": payload}
        subscribers = list(_SSE_SUBSCRIBERS)
    for subscriber in subscribers:
        try:
            subscriber.put_nowait(item)
        except queue.Full:
            try:
                subscriber.get_nowait()
                subscriber.put_nowait(item)
            except (queue.Empty, queue.Full):
                pass


def _persist_sent_ids(cfg: dict, host: str, event_ids: list[str]) -> None:
    with _CONFIG_LOCK:
        latest = load_config()
        sent_map = latest.get("sent_event_ids")
        if not isinstance(sent_map, dict):
            sent_map = {}
            latest["sent_event_ids"] = sent_map
        current = sent_map.get(host, [])
        values = [str(value) for value in current] if isinstance(current, (list, tuple)) else []
        seen = set(values)
        for value in event_ids:
            value = str(value)
            if value not in seen:
                values.append(value)
                seen.add(value)
        sent_map[host] = values[-5000:]
        save_config(latest)
        cfg["sent_event_ids"] = latest["sent_event_ids"]


def _sync_attendance_records(client: ApiClient, cfg: dict, device: dict, records, allow_gate: bool = False) -> dict:
    records = list(records or [])
    sent_map = cfg.get("sent_event_ids")
    if not isinstance(sent_map, dict):
        sent_map = {}
        cfg["sent_event_ids"] = sent_map
    sent_values = sent_map.get(device["host"], [])
    sent = set(str(value) for value in sent_values) if isinstance(sent_values, (list, tuple)) else set()
    queued = set()
    pending = []
    invalid = 0
    for event in records:
        uid = _normalize_device_user_id(getattr(event, "user_id", "") or "")
        stamp = getattr(event, "timestamp", None)
        if (not uid or len(uid) > 32 or not re.fullmatch(r"[A-Za-z0-9_-]{1,32}", uid)
                or stamp is None):
            invalid += 1
            continue
        try:
            stamp_text = stamp.isoformat(timespec="seconds") if hasattr(stamp, "isoformat") else str(stamp)
        except (TypeError, ValueError, OverflowError):
            invalid += 1
            continue
        raw = "|".join((device["host"], uid, stamp_text,
                        str(getattr(event, "status", "")), str(getattr(event, "punch", ""))))
        event_id = hashlib.sha256(raw.encode("utf-8")).hexdigest()
        if event_id in sent or event_id in queued:
            continue
        pending.append({"event_id": event_id, "device_user_id": uid, "timestamp": stamp_text,
                        "direction": _attendance_direction(device, event)})
        queued.add(event_id)
    delivered = 0
    failed = 0
    if invalid:
        _log_hardware_event(device, "رد رکوردهای تردد نامعتبر", "rejected", str(invalid) + " رکورد برای حفظ هم‌ترازی ارسال نشد.")
    for start in range(0, len(pending), 100):
        batch = pending[start:start + 100]
        remote = client.call("bridge/attendance", "POST", {"events": batch})
        raw_items = remote.get("items", []) if isinstance(remote, dict) else []
        remote_items = raw_items if isinstance(raw_items, list) else []
        batch_ids = [item["event_id"] for item in batch]
        id_bearing = any(isinstance(item, dict) and item.get("event_id") for item in remote_items)
        correlated = {}
        ambiguous = set()
        if id_bearing:
            for item in remote_items:
                if not isinstance(item, dict):
                    continue
                response_id = str(item.get("event_id", "") or "").lower()
                if not response_id:
                    continue
                if response_id in correlated:
                    ambiguous.add(response_id)
                else:
                    correlated[response_id] = item
            results = [None if event_id in ambiguous else correlated.get(event_id) for event_id in batch_ids]
        elif len(remote_items) == len(batch) and (
                not isinstance(remote, dict) or remote.get("received") in (None, len(batch))):
            # Compatibility with older server builds: positional matching is allowed only for a complete response.
            results = [item if isinstance(item, dict) else None for item in remote_items]
        else:
            results = [None] * len(batch)

        acknowledged = []
        for item, result in zip(batch, results):
            accepted = (isinstance(result, dict) and result.get("accepted") is not False
                        and not result.get("error_code"))
            if not accepted:
                failed += 1
                detail = str((result or {}).get("message", "پاسخ API برای این event_id قابل‌تطبیق یا تأیید نبود."))[:240] if isinstance(result, dict) else "پاسخ API برای این event_id قابل‌تطبیق یا تأیید نبود."
                _log_hardware_event(device, "رد/عدم‌تأیید رویداد تردد", "rejected", detail)
                continue
            acknowledged.append(item["event_id"])
            gate_actions = _open_gate_for_authorized_event(cfg, item, result) if allow_gate else []
            _log_hardware_event(device, "رویداد تردد " + str(item.get("direction", "in")),
                                str(result.get("decision", "denied")),
                                str(result.get("member_name") or result.get("member_code") or result.get("reason") or "رویداد بررسی شد")[:240])
            publish_local_event("attendance", {
                "event_id": item["event_id"],
                "device_user_id": item["device_user_id"],
                "member_id": int(result.get("member_id", 0) or 0),
                "member_name": str(result.get("member_name", "") or "ناشناس"),
                "member_code": str(result.get("member_code", "") or ""),
                "timestamp": str(result.get("occurred_at", item["timestamp"])),
                "direction": item["direction"],
                "event_type": str(result.get("event_type", "entry")),
                "decision": str(result.get("decision", "denied")),
                "reason": str(result.get("reason", "")),
                "image_url": str(result.get("image_url", "") or ""),
                "device_id": device_identifier(device),
                "device_name": str(device.get("name", device.get("host", ""))),
                "gate_actions": gate_actions,
            })
        if acknowledged:
            _persist_sent_ids(cfg, device["host"], acknowledged)
            sent.update(acknowledged)
        delivered += len(acknowledged)
    return {"records_read": len(records), "sent": delivered, "failed": failed,
            "already_synced": max(0, len(records) - invalid - len(queued)), "invalid": invalid}

def sync_device(client: ApiClient, cfg: dict, device: dict, allow_gate: bool = False) -> dict:
    with device_session(device) as conn:
        records = conn.get_attendance() or []
        result = _sync_attendance_records(client, cfg, device, records, allow_gate=allow_gate)
    if result["sent"] or result.get("failed") or result.get("invalid"):
        print(time.strftime("%Y-%m-%d %H:%M:%S") + " | " + device.get("name", device["host"]) +
              " | ارسال: " + str(result["sent"]) + " | رد/نامشخص: " + str(result.get("failed", 0)) +
              " | رکورد نامعتبر: " + str(result.get("invalid", 0)))
    return result


def _wordpress_timezone(name: str | None):
    name = str(name or "").strip()
    if not name:
        return None
    if name.upper() in ("UTC", "ETC/UTC", "GMT"):
        return timezone.utc
    match = re.fullmatch(r"([+-])(\d{2}):(\d{2})", name)
    if match:
        hours, minutes = int(match.group(2)), int(match.group(3))
        if hours > 23 or minutes > 59:
            return None
        seconds = (hours * 60 + minutes) * 60 * (1 if match.group(1) == "+" else -1)
        return timezone(timedelta(seconds=seconds))
    try:
        return ZoneInfo(name)
    except (KeyError, ValueError, TypeError):
        return None


def _store_site_timezone(cfg: dict, name: str) -> None:
    name = str(name or "").strip()[:100]
    if not name or _wordpress_timezone(name) is None:
        raise RuntimeError("منطقهٔ زمانی WordPress نامعتبر یا در سیستم پل در دسترس نیست؛ بستهٔ tzdata را نصب کنید.")
    with _CONFIG_LOCK:
        latest = load_config()
        latest["site_timezone"] = name
        save_config(latest)
        cfg["site_timezone"] = name


def sync_device_time(client: ApiClient, cfg: dict, device: dict) -> dict:
    """Set the terminal's wall clock to the configured WordPress site's wall clock."""
    data = client.call("bridge/time")
    raw_time = str(data.get("server_time", "")).strip()
    timezone_name = str(data.get("timezone", "") or "").strip()
    site_timezone = _wordpress_timezone(timezone_name)
    if not site_timezone:
        raise RuntimeError("منطقهٔ زمانی WordPress معتبر نیست؛ نصب tzdata یا تنظیم timezone را بررسی کنید.")
    try:
        target = datetime.fromisoformat(raw_time.replace("Z", "+00:00"))
    except ValueError as exc:
        raise RuntimeError("زمان سرور WordPress معتبر نیست.") from exc
    _store_site_timezone(cfg, timezone_name)
    if target.tzinfo is not None:
        target = target.astimezone(site_timezone).replace(tzinfo=None)
    target = target.replace(microsecond=0)
    with device_session(device) as conn:
        before = conn.get_time()
        if conn.set_time(target) is False:
            raise RuntimeError("دستگاه تنظیم زمان را نپذیرفت.")
        after = conn.get_time()
    return {
        "synced": True,
        "before": before.isoformat(timespec="seconds") if hasattr(before, "isoformat") else str(before),
        "server_time": target.isoformat(timespec="seconds"),
        "device_time": after.isoformat(timespec="seconds") if hasattr(after, "isoformat") else str(after),
        "timezone": timezone_name,
    }

def sync_device_users(client: ApiClient, device: dict) -> dict:
    """Upsert member names/IDs from WordPress; never transfer PINs or fingerprints."""
    page = 1
    synced = 0
    skipped = 0
    failed = []
    seen = set()
    while page <= 100:
        data = client.call("bridge/access-list", query={"per_page": 200, "page": page})
        items = data.get("items", [])
        for row in items:
            user_id = str(row.get("device_user_id", "") or "").strip()
            name = str(row.get("name", "") or "").strip()
            if (not user_id or not user_id.isascii() or not user_id.isdigit() or int(user_id) < 1
                    or int(user_id) > 4294967295 or user_id in seen):
                skipped += 1
                continue
            seen.add(user_id)
            try:
                upsert_device_user(device, {"user_id": user_id, "name": (name or user_id)[:24]})
                synced += 1
            except Exception as exc:
                if len(failed) < 20:
                    failed.append({"user_id": user_id, "message": str(exc)[:160]})
        if not data.get("has_more"):
            break
        page += 1
    return {"synced": synced, "skipped": skipped, "failed": failed, "delete_policy": "device users are not automatically deleted"}


def clear_device_attendance(client: ApiClient, cfg: dict, device: dict) -> dict:
    """Sync to WordPress first; clear only inside a disabled, serialized device session."""
    with device_session(device, disable=True) as conn:
        records = conn.get_attendance() or []
        sync_result = _sync_attendance_records(client, cfg, device, records)
        if sync_result.get("invalid", 0) or sync_result.get("failed", 0):
            raise RuntimeError("پاک‌کردن متوقف شد: همهٔ رویدادها به‌طور معتبر و هم‌بسته به WordPress تأیید نشدند؛ ابتدا خطاهای همگام‌سازی را رفع کنید.")
        cleared = conn.clear_attendance()
        if cleared is False:
            raise RuntimeError("دستگاه پاک‌کردن گزارش تردد را نپذیرفت.")
        remaining = None
        try:
            remaining = len(conn.get_attendance() or [])
        except Exception:
            pass
    return {"cleared": True, "records_before_clear": len(records), "sync": sync_result,
            "records_remaining": remaining}


def refresh_access_snapshot(client: ApiClient):
    page = 1
    eligible = 0
    total = 0
    while True:
        data = client.call("bridge/access-list", query={"per_page": 200, "page": page})
        items = data.get("items", [])
        total += len(items)
        eligible += sum(1 for row in items if row.get("eligible"))
        if not data.get("has_more"):
            break
        page += 1
        if page > 1000:
            break
    print(time.strftime("%Y-%m-%d %H:%M:%S") + " | فهرست استحقاق نرم‌افزار: " + str(eligible) + "/" + str(total) + " مجاز")
    print("این همگام‌سازی فقط داده/گزارش را به‌روز می‌کند و به‌تنهایی رله یا مجوزهای دسترسی دستگاه را تغییر نمی‌دهد.")


_HARDWARE_TYPE_LABELS = {
    "zkteco": "ZKTeco / اثرانگشت، چهره یا کارت",
    "face_reader": "چهره‌خوان / API داخلی",
    "rfid_reader": "کارت‌خوان RFID/NFC",
    "barcode_hid": "بارکدخوان USB HID",
    "barcode_serial": "بارکدخوان سریال / Bluetooth SPP",
    "thermal_printer": "پرینتر حرارتی ESC/POS",
    "label_printer": "لیبل‌زن ZPL/TSPL",
    "network_printer": "پرینتر شبکه‌ای",
    "modbus_gate": "گیت Modbus TCP",
    "serial_gate": "گیت RS-485/RS-232",
    "dry_contact": "رله / کنتاکت خشک",
    "adms_push": "ZKTeco ADMS / Push",
    "network_candidate": "دستگاه شبکه‌ای شناسایی‌نشده",
    "serial_candidate": "دستگاه USB/Serial شناسایی‌نشده",
    "usb_candidate": "دستگاه USB شناسایی‌نشده",
}
_HARDWARE_DEFAULT_PORTS = {
    "zkteco": 4370, "face_reader": 80, "thermal_printer": 9100,
    "label_printer": 9100, "network_printer": 9100, "modbus_gate": 502,
    "serial_gate": 0, "dry_contact": 0, "adms_push": 80,
    "network_candidate": 0, "serial_candidate": 0, "usb_candidate": 0,
    "barcode_hid": 0, "barcode_serial": 0, "rfid_reader": 0,
}
_HARDWARE_ALLOWED_TYPES = set(_HARDWARE_TYPE_LABELS)


def _hardware_identifier(device: dict) -> str:
    identity = "|".join((
        str(device.get("type", "")), str(device.get("transport", "")),
        str(device.get("host", "")), str(device.get("port", "")),
        str(device.get("serial_port", "")), str(device.get("usb_vendor_id", "")),
        str(device.get("usb_product_id", "")), str(device.get("usb_serial", "")),
        str(device.get("name", "") if device.get("type") in ("barcode_hid", "rfid_reader") else ""),
    ))
    return "hw-" + hashlib.sha256(identity.encode("utf-8")).hexdigest()[:16]


def _truthy(value) -> bool:
    return value is True or value == 1 or str(value or "").strip().lower() in ("1", "true", "yes", "on")


def normalize_hardware_device(body: dict) -> dict:
    body = body if isinstance(body, dict) else {}
    dtype = str(body.get("type") or body.get("device_type") or body.get("kind") or "").strip().lower()
    aliases = {"zkteco_adms": "adms_push", "adms": "adms_push", "rfid": "rfid_reader",
               "barcode": "barcode_hid", "printer": "network_printer", "gate": "modbus_gate"}
    dtype = aliases.get(dtype, dtype)
    if dtype not in _HARDWARE_ALLOWED_TYPES:
        raise ValueError("نوع سخت‌افزار پشتیبانی‌شده را انتخاب کنید.")
    transport = str(body.get("transport") or "").strip().lower()
    if not transport:
        transport = {"barcode_hid": "hid", "rfid_reader": "hid", "barcode_serial": "serial",
                     "serial_candidate": "serial", "usb_candidate": "usb", "serial_gate": "serial",
                     "dry_contact": "serial", "adms_push": "push", "face_reader": "http",
                     "modbus_gate": "tcp", "network_printer": "tcp", "thermal_printer": "tcp",
                     "label_printer": "tcp", "network_candidate": "tcp"}.get(dtype, "tcp")
    if transport not in ("tcp", "udp", "http", "https", "serial", "usb", "hid", "push"):
        raise ValueError("روش اتصال دستگاه معتبر نیست.")
    allowed_transports = {
        "face_reader": ("http", "https"), "modbus_gate": ("tcp",),
        "serial_gate": ("serial",), "barcode_hid": ("hid",),
        "barcode_serial": ("serial",), "rfid_reader": ("hid", "serial"),
        "thermal_printer": ("tcp", "serial", "usb"),
        "label_printer": ("tcp", "serial", "usb"),
        "network_printer": ("tcp",), "adms_push": ("push",),
        "dry_contact": ("serial",), "network_candidate": ("tcp",),
        "serial_candidate": ("serial",), "usb_candidate": ("usb",),
    }
    if transport not in allowed_transports.get(dtype, ("tcp",)):
        raise ValueError("روش اتصال با نوع دستگاه انتخاب‌شده سازگار نیست.")
    host = str(body.get("host", body.get("ip", "")) or "").strip()
    network_required = dtype in ("face_reader", "modbus_gate", "adms_push", "network_candidate") or (
        dtype in ("network_printer", "thermal_printer", "label_printer") and transport in ("tcp", "udp", "http", "https"))
    if host:
        try:
            address = ipaddress.ip_address(host)
        except ValueError as exc:
            raise ValueError("برای حفظ محدودهٔ امنیتی، IP خصوصی IPv4 وارد کنید.") from exc
        if not isinstance(address, ipaddress.IPv4Address) or not any(address in net for net in _DEVICE_ALLOWED_NETWORKS):
            raise ValueError("IP دستگاه باید IPv4 خصوصی یا محلی باشد.")
        host = str(address)
    elif network_required:
        raise ValueError("برای این نوع اتصال، IP خصوصی دستگاه الزامی است.")
    port_value = body.get("port", _HARDWARE_DEFAULT_PORTS.get(dtype, 0))
    try:
        port = int(str(port_value or _HARDWARE_DEFAULT_PORTS.get(dtype, 0)).translate(_DEVICE_ID_DIGITS))
    except (TypeError, ValueError) as exc:
        raise ValueError("پورت باید عدد صحیح باشد.") from exc
    if port < 0 or port > 65535 or (network_required and not 1 <= port <= 65535):
        raise ValueError("پورت دستگاه خارج از محدوده است.")
    serial_port = str(body.get("serial_port", body.get("com_port", "")) or "").strip()[:255]
    if dtype in ("barcode_serial", "serial_candidate", "serial_gate", "dry_contact") and transport == "serial" and not serial_port:
        if dtype != "serial_candidate":
            raise ValueError("پورت COM/Serial را انتخاب کنید.")
    if dtype in ("thermal_printer", "label_printer", "rfid_reader") and transport == "serial" and not serial_port:
        raise ValueError("پورت COM/Serial را انتخاب کنید.")
    defaults = {"thermal_printer": "escpos", "label_printer": "zpl", "network_printer": "auto",
                "modbus_gate": "modbus_tcp", "serial_gate": "modbus_rtu", "face_reader": "http_api",
                "barcode_hid": "keyboard_wedge", "barcode_serial": "serial", "rfid_reader": "hid",
                "adms_push": "adms", "dry_contact": "relay", "network_candidate": "tcp",
                "serial_candidate": "serial", "usb_candidate": "usb"}
    protocol = str(body.get("protocol") or defaults.get(dtype, "tcp")).strip().lower()[:40]
    if protocol == "auto" and dtype not in ("thermal_printer", "label_printer", "network_printer"):
        protocol = defaults.get(dtype, "tcp")
    allowed_protocols = {
        "face_reader": ("http_api",), "modbus_gate": ("modbus_tcp",),
        "serial_gate": ("modbus_rtu",), "barcode_hid": ("keyboard_wedge",),
        "barcode_serial": ("serial",), "rfid_reader": ("hid", "serial"),
        "thermal_printer": ("escpos", "auto"), "label_printer": ("zpl", "tspl", "auto"),
        "network_printer": ("auto", "escpos", "zpl", "tspl", "lpd"),
        "adms_push": ("adms",), "dry_contact": ("relay",),
        "network_candidate": ("tcp",), "serial_candidate": ("serial",), "usb_candidate": ("usb", "hid"),
    }
    if protocol not in allowed_protocols.get(dtype, (protocol,)):
        raise ValueError("پروتکل با نوع دستگاه انتخاب‌شده سازگار نیست.")
    name = str(body.get("name") or body.get("model") or body.get("product") or host or serial_port or _HARDWARE_TYPE_LABELS[dtype]).strip()[:100]
    model = str(body.get("model") or body.get("product") or "").strip()[:120]
    username = str(body.get("username", "") or "").strip()[:100]
    password = str(body.get("password", "") or "")[:256]
    try:
        baudrate = int(body.get("baudrate", 9600) or 9600)
    except (TypeError, ValueError) as exc:
        raise ValueError("سرعت سریال معتبر نیست.") from exc
    if baudrate not in (1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200, 230400):
        raise ValueError("سرعت سریال پشتیبانی نمی‌شود.")
    try:
        unit_id = int(body.get("unit_id", body.get("slave_id", 1)) or 1)
        coil = int(body.get("coil", body.get("coil_address", 0)) or 0)
        pulse_ms = int(body.get("pulse_ms", 500) or 500)
    except (TypeError, ValueError) as exc:
        raise ValueError("تنظیمات Modbus باید عدد صحیح باشند.") from exc
    if not 1 <= unit_id <= 247 or not 0 <= coil <= 65535 or not 100 <= pulse_ms <= 3000:
        raise ValueError("شناسهٔ Modbus، آدرس Coil یا مدت پالس خارج از محدوده است.")
    directions = body.get("open_directions", ["in"])
    if isinstance(directions, str):
        directions = [part.strip().lower() for part in directions.split(",") if part.strip()]
    if not isinstance(directions, (list, tuple)):
        directions = ["in"]
    directions = sorted({str(item).lower() for item in directions if str(item).lower() in ("in", "out")}) or ["in"]
    usb_vid = str(body.get("usb_vendor_id", body.get("vid", "")) or "").lower().strip()
    usb_pid = str(body.get("usb_product_id", body.get("pid", "")) or "").lower().strip()
    if (usb_vid and not re.fullmatch(r"[0-9a-f]{1,4}", usb_vid)) or (usb_pid and not re.fullmatch(r"[0-9a-f]{1,4}", usb_pid)):
        raise ValueError("USB Vendor ID و Product ID باید شناسهٔ hexadecimal چهاررقمی باشند.")
    usb_serial = str(body.get("usb_serial", "") or "")[:120]
    if dtype == "usb_candidate" and (not usb_vid or not usb_pid):
        raise ValueError("برای USB candidate هر دو Vendor ID و Product ID لازم است.")
    api_path = str(body.get("api_path", "/") or "/").strip()[:200]
    if not api_path.startswith("/") or api_path.startswith("//") or ".." in api_path or any(ord(char) < 32 for char in api_path):
        raise ValueError("مسیر API باید یک مسیر محلی معتبر و بدون traversal باشد.")
    manufacturer = str(body.get("manufacturer", "") or "")[:100]
    product = str(body.get("product", model) or "")[:120]
    last_scan_only = _truthy(body.get("discovered", False))
    device = {
        "type": dtype, "transport": transport, "protocol": protocol, "host": host, "port": port,
        "serial_port": serial_port, "baudrate": baudrate, "name": name, "model": model,
        "username": username, "password": password, "unit_id": unit_id, "coil": coil,
        "pulse_ms": pulse_ms, "auto_open_on_allowed": _truthy(body.get("auto_open_on_allowed", False)),
        "open_directions": directions, "usb_vendor_id": usb_vid, "usb_product_id": usb_pid,
        "usb_serial": usb_serial, "manufacturer": manufacturer, "product": product,
        "api_path": api_path, "discovered": last_scan_only,
    }
    if device["auto_open_on_allowed"] and dtype not in ("modbus_gate", "serial_gate"):
        raise ValueError("بازشدن خودکار فقط برای گیت Modbus TCP/RTU پشتیبانی می‌شود؛ رلهٔ عمومی را دستی پیکربندی کنید.")
    device["id"] = _hardware_identifier(device)
    return device


def _set_hardware_status(device: dict, online: bool | None, error: str = "", details: dict | None = None) -> dict:
    entry = {"online": online, "state": "online" if online is True else "offline" if online is False else "unknown",
             "last_checked": int(time.time()), "last_error": str(error or "")[:400], "verified": bool((details or {}).get("verified", False))}
    if details:
        for key in ("model", "firmware", "serial_number", "counts", "protocol", "latency_ms", "response"):
            if key in details:
                entry[key] = details[key]
    with _DEVICE_STATUS_LOCK:
        _DEVICE_STATUS[str(device.get("id") or _hardware_identifier(device))] = entry
    return entry


def _hardware_status(device: dict) -> dict:
    with _DEVICE_STATUS_LOCK:
        entry = dict(_DEVICE_STATUS.get(str(device.get("id") or _hardware_identifier(device)), {}))
    if not entry:
        return {"state": "unknown", "online": None, "last_checked": None, "last_error": "", "verified": False}
    if time.time() - int(entry.get("last_checked", 0)) > max(180, POLL_SECONDS * 4):
        entry["state"] = "unknown"
        entry["online"] = None
    return entry


def public_hardware_device(device: dict) -> dict:
    return {
        "id": str(device.get("id") or _hardware_identifier(device)), "type": device.get("type", "network_candidate"),
        "type_label": _HARDWARE_TYPE_LABELS.get(device.get("type", ""), "سخت‌افزار"),
        "transport": device.get("transport", "tcp"), "protocol": device.get("protocol", ""),
        "name": device.get("name", ""), "model": device.get("model") or device.get("product") or "",
        "manufacturer": device.get("manufacturer", ""), "host": device.get("host", ""),
        "port": int(device.get("port", 0) or 0), "serial_port": device.get("serial_port", ""),
        "baudrate": int(device.get("baudrate", 9600) or 9600), "usb_vendor_id": device.get("usb_vendor_id", ""),
        "usb_product_id": device.get("usb_product_id", ""), "api_path": device.get("api_path", "/"),
        "unit_id": int(device.get("unit_id", 1) or 1),
        "coil": int(device.get("coil", 0) or 0), "pulse_ms": int(device.get("pulse_ms", 500) or 500),
        "auto_open_on_allowed": bool(device.get("auto_open_on_allowed", False)),
        "open_directions": device.get("open_directions", ["in"]), "status": _hardware_status(device),
    }


def _log_hardware_event(device: dict | str, event: str, status: str, detail: str = "") -> dict:
    if isinstance(device, dict):
        name = str(device.get("name") or device.get("host") or device.get("serial_port") or device.get("id") or "سخت‌افزار")
        identifier = str(device.get("id") or _hardware_identifier(device))
    else:
        name, identifier = str(device or "سخت‌افزار"), ""
    record = {"occurred_at": datetime.now().astimezone().isoformat(timespec="seconds"),
              "device_id": identifier, "device_name": name[:120], "event": str(event or "رویداد")[:100],
              "status": str(status or "unknown")[:32], "detail": str(detail or "")[:300]}
    with _HARDWARE_EVENTS_LOCK:
        _HARDWARE_EVENTS.append(record)
        del _HARDWARE_EVENTS[:-_HARDWARE_EVENT_LIMIT]
    publish_local_event("hardware", record)
    return record


def _hardware_event_snapshot(limit: int = 50) -> list[dict]:
    with _HARDWARE_EVENTS_LOCK:
        return [dict(item) for item in reversed(_HARDWARE_EVENTS[-max(1, min(200, int(limit))):])]


def add_or_replace_hardware_device(cfg: dict, body: dict) -> dict:
    device = normalize_hardware_device(body)
    with _CONFIG_LOCK:
        latest = load_config()
        items = latest.setdefault("hardware_devices", [])
        latest["hardware_devices"] = [item for item in items if str(item.get("id") or _hardware_identifier(item)) != device["id"]]
        latest["hardware_devices"].append(device)
        save_config(latest)
        cfg["hardware_devices"] = latest["hardware_devices"]
    _log_hardware_event(device, "پیکربندی دستگاه", "saved", "دستگاه در تنظیمات محلی پل ثبت شد.")
    return device


def get_configured_hardware_device(cfg: dict, identifier: str) -> dict:
    for item in cfg.get("hardware_devices", []):
        try:
            normalized = normalize_hardware_device(item)
        except (ValueError, TypeError):
            continue
        if normalized["id"] == identifier:
            return normalized
    raise KeyError("سخت‌افزار در پیکربندی پل پیدا نشد.")


def _find_configured_hardware_device(cfg: dict, predicate) -> dict | None:
    for item in reversed(cfg.get("hardware_devices", [])):
        try:
            normalized = normalize_hardware_device(item)
        except (ValueError, TypeError):
            continue
        if predicate(normalized):
            return normalized
    return None


def _set_or_replace_hardware_device(cfg: dict, identifier: str, body: dict) -> dict:
    with _CONFIG_LOCK:
        latest = load_config()
        current = get_configured_hardware_device(latest, identifier)
        merged = {**current, **body}
        if not str(body.get("password", "") or "").strip():
            merged["password"] = current.get("password", "")
        updated = normalize_hardware_device(merged)
        items = [item for item in latest.get("hardware_devices", [])
                 if str(item.get("id") or _hardware_identifier(item)) not in (identifier, updated["id"])]
        items.append(updated)
        latest["hardware_devices"] = items
        save_config(latest)
        cfg["hardware_devices"] = items
    _log_hardware_event(updated, "به‌روزرسانی پیکربندی", "saved", "تنظیمات سخت‌افزار ذخیره شد.")
    return updated


def _remove_hardware_device(cfg: dict, identifier: str) -> None:
    with _CONFIG_LOCK:
        latest = load_config()
        latest["hardware_devices"] = [item for item in latest.get("hardware_devices", []) if str(item.get("id") or _hardware_identifier(item)) != identifier]
        save_config(latest)
        cfg["hardware_devices"] = latest["hardware_devices"]


def _available_hardware_dependencies() -> dict:
    import importlib.util
    import importlib.metadata
    modules = {"pyzk": "zk", "pymodbus": "pymodbus", "pyserial": "serial", "pyusb": "usb", "tzdata": "tzdata"}
    result = {}
    for package, module in modules.items():
        try:
            available = importlib.util.find_spec(module) is not None
        except (ImportError, ValueError):
            available = False
        version = ""
        if package == "tzdata" and not available:
            try:
                ZoneInfo("America/Los_Angeles")
                available = True
                version = "system timezone database"
            except (KeyError, ValueError, TypeError):
                pass
        if available and not version:
            try:
                version = importlib.metadata.version(package)
            except importlib.metadata.PackageNotFoundError:
                version = "installed"
        result[package] = {"installed": available, "version": version}
    return result


def _enumerate_serial_ports() -> tuple[list[dict], list[str]]:
    try:
        from serial.tools import list_ports
    except ImportError:
        return [], ["pyserial"]
    items = []
    try:
        for port in list_ports.comports():
            item = {"type": "serial_candidate", "transport": "serial", "serial_port": str(port.device),
                    "name": str(port.description or port.device)[:100], "model": str(port.product or port.description or "Serial/COM")[:120],
                    "manufacturer": str(port.manufacturer or "")[:100], "usb_vendor_id": ("%04x" % port.vid) if port.vid is not None else "",
                    "usb_product_id": ("%04x" % port.pid) if port.pid is not None else "", "usb_serial": str(port.serial_number or "")[:120],
                    "baudrate": 9600, "discovered": True}
            normalized = normalize_hardware_device(item)
            items.append(normalized)
    except Exception as exc:
        return items, ["serial-port-enumeration: " + str(exc)[:160]]
    return items, []


def _enumerate_usb_devices() -> tuple[list[dict], list[str]]:
    try:
        import usb.core
        import usb.util
    except ImportError:
        return [], ["pyusb"]
    items = []
    try:
        for device in _usb_find(find_all=True) or []:
            vid = int(getattr(device, "idVendor", 0) or 0)
            pid = int(getattr(device, "idProduct", 0) or 0)
            if not vid and not pid:
                continue
            try:
                manufacturer = usb.util.get_string(device, device.iManufacturer) if device.iManufacturer else ""
            except Exception:
                manufacturer = ""
            try:
                product = usb.util.get_string(device, device.iProduct) if device.iProduct else ""
            except Exception:
                product = ""
            try:
                serial = usb.util.get_string(device, device.iSerialNumber) if device.iSerialNumber else ""
            except Exception:
                serial = ""
            is_hid = False
            try:
                config = device.get_active_configuration()
                is_hid = any(int(interface.bInterfaceClass) == 3 for interface in config)
            except Exception:
                pass
            items.append(normalize_hardware_device({
                "type": "usb_candidate", "transport": "usb", "name": (product or manufacturer or "USB device")[:100],
                "model": (product or ("USB %04X:%04X" % (vid, pid)))[:120], "manufacturer": manufacturer or "",
                "usb_vendor_id": "%04x" % vid, "usb_product_id": "%04x" % pid, "usb_serial": serial or "",
                "protocol": "hid" if is_hid else "usb", "discovered": True,
            }))
    except Exception as exc:
        return items, ["usb-enumeration: " + str(exc)[:160]]
    return items, []


def _local_scan_networks(cfg: dict | None = None) -> tuple[list, set]:
    addresses = set()
    try:
        for item in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET, socket.SOCK_STREAM):
            address = ipaddress.IPv4Address(item[4][0])
            if address not in ipaddress.ip_network("127.0.0.0/8") and any(address in net for net in _DEVICE_ALLOWED_NETWORKS[:-1]):
                addresses.add(address)
    except OSError:
        pass
    cfg = cfg or {}
    try:
        parsed = urllib.parse.urlsplit(str(cfg.get("api_base", "")))
        if parsed.hostname:
            remote_port = parsed.port or (443 if parsed.scheme == "https" else 80)
            for item in socket.getaddrinfo(parsed.hostname, remote_port, socket.AF_INET, socket.SOCK_DGRAM):
                probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
                try:
                    probe.connect(item[4])
                    source = ipaddress.IPv4Address(probe.getsockname()[0])
                    if source not in ipaddress.ip_network("127.0.0.0/8") and any(source in net for net in _DEVICE_ALLOWED_NETWORKS[:-1]):
                        addresses.add(source)
                finally:
                    probe.close()
    except (OSError, ValueError, TypeError):
        pass
    networks = sorted({ipaddress.ip_network(str(address) + "/24", strict=False) for address in addresses},
                      key=lambda item: int(item.network_address))[:4]
    return networks, addresses


def _scan_tcp_ports(cfg: dict) -> tuple[list[tuple[str, int]], list[str]]:
    networks, own_addresses = _local_scan_networks(cfg)
    targets = [str(host) for network in networks for host in network.hosts() if host not in own_addresses]
    if not targets:
        return [], ["هیچ رابط شبکهٔ IPv4 خصوصی برای اسکن پیدا نشد."]
    tasks = [(host, port) for host in targets for port in NETWORK_SCAN_PORTS]
    def probe(task):
        host, port = task
        try:
            with socket.create_connection((host, port), timeout=0.28):
                return host, port
        except OSError:
            return None
    found = []
    with ThreadPoolExecutor(max_workers=min(96, len(tasks))) as executor:
        for result in executor.map(probe, tasks):
            if result:
                found.append(result)
    return sorted(set(found), key=lambda item: (int(ipaddress.IPv4Address(item[0])), item[1])), []


def _valid_escpos_status(response: bytes) -> bool:
    return len(response) == 1 and response[0] < 0x80


def _query_escpos_status(host: str, port: int, timeout: float = 0.35) -> bytes:
    """Send the ESC/POS real-time status query only to an explicitly configured ESC/POS printer."""
    with socket.create_connection((host, int(port)), timeout=timeout) as connection:
        connection.settimeout(timeout)
        connection.sendall(b"\x10\x04\x01")
        try:
            return connection.recv(8)
        except socket.timeout:
            return b""


def _query_label_status(host: str, port: int, protocol: str, timeout: float = 0.35) -> bytes:
    """Send only a documented, non-printing status request to an explicitly selected label protocol."""
    protocol = str(protocol or "").lower()
    if protocol == "zpl":
        command = b"~HS\r\n"
    elif protocol == "tspl":
        command = b"\x1b!?"
    else:
        raise ValueError("پروتکل پرس‌وجوی وضعیت ZPL/TSPL مشخص نشده است.")
    chunks = []
    total = 0
    with socket.create_connection((host, int(port)), timeout=timeout) as connection:
        connection.settimeout(timeout)
        connection.sendall(command)
        while total < 2048:
            try:
                chunk = connection.recv(min(512, 2048 - total))
            except socket.timeout:
                break
            if not chunk:
                break
            chunks.append(chunk)
            total += len(chunk)
            if protocol == "tspl":
                break
    return b"".join(chunks)


def _valid_label_status(protocol: str, response: bytes) -> bool:
    protocol = str(protocol or "").lower()
    if protocol == "tspl":
        # ESC ! ? is documented as a one-byte status response; do not treat arbitrary data as proof.
        return len(response) == 1
    if protocol != "zpl":
        return False
    try:
        text = response.decode("ascii")
    except (AttributeError, UnicodeDecodeError):
        return False
    frames = re.findall(r"\x02([0-9, ]+)\x03\r?\n", text)
    if len(frames) != 3:
        return False
    comma_counts = [frame.count(",") for frame in frames]
    return comma_counts[0] >= 10 and comma_counts[1] >= 9 and comma_counts[2] >= 1


def _query_label_serial_status(device: dict, protocol: str, timeout: float = 0.35) -> bytes:
    """Query a configured serial label printer without submitting a print job."""
    try:
        import serial
    except ImportError as exc:
        raise RuntimeError("pyserial نصب نیست؛ requirements پل را نصب کنید.") from exc
    protocol = str(protocol or "").lower()
    if protocol == "zpl":
        command = b"~HS\r\n"
    elif protocol == "tspl":
        command = b"\x1b!?"
    else:
        raise ValueError("پروتکل پرس‌وجوی وضعیت ZPL/TSPL مشخص نشده است.")
    with serial.Serial(port=str(device.get("serial_port", "")), baudrate=int(device.get("baudrate", 9600)),
                       timeout=timeout, write_timeout=timeout) as connection:
        connection.reset_input_buffer()
        connection.write(command)
        connection.flush()
        if protocol == "tspl":
            return connection.read(1)
        captured = bytearray()
        while len(captured) < 2048:
            chunk = connection.read(min(512, 2048 - len(captured)))
            if not chunk:
                break
            captured.extend(chunk)
        return bytes(captured)


def _query_escpos_serial_status(device: dict, timeout: float = 0.35) -> bytes:
    """Query ESC/POS status over a configured serial port without printing."""
    try:
        import serial
    except ImportError as exc:
        raise RuntimeError("pyserial نصب نیست؛ requirements پل را نصب کنید.") from exc
    with serial.Serial(port=str(device.get("serial_port", "")), baudrate=int(device.get("baudrate", 9600)),
                       timeout=timeout, write_timeout=timeout) as connection:
        connection.reset_input_buffer()
        connection.write(b"\x10\x04\x01")
        connection.flush()
        return connection.read(8)


def _query_escpos_usb_status(device: dict, timeout_ms: int = 500) -> bytes:
    """Query a claimed USB printer interface; no print, cut, drawer, or reset command."""
    try:
        import usb.core
        import usb.util
    except ImportError as exc:
        raise RuntimeError("pyusb نصب نیست؛ requirements پل را نصب کنید.") from exc
    vid = int(str(device.get("usb_vendor_id", "")), 16)
    pid = int(str(device.get("usb_product_id", "")), 16)
    target = _usb_find(idVendor=vid, idProduct=pid)
    if target is None:
        raise RuntimeError("پرینتر USB با VID/PID ثبت‌شده متصل نیست.")
    try:
        configuration = target.get_active_configuration()
    except Exception as exc:
        raise RuntimeError("پیکربندی فعال USB پیدا نشد؛ رابط پرینتر تغییر داده نشد.") from exc
    selected = None
    for interface in configuration:
        endpoints = list(interface)
        out_ep = next((ep for ep in endpoints if usb.util.endpoint_direction(ep.bEndpointAddress) == usb.util.ENDPOINT_OUT), None)
        in_ep = next((ep for ep in endpoints if usb.util.endpoint_direction(ep.bEndpointAddress) == usb.util.ENDPOINT_IN), None)
        if out_ep is not None and in_ep is not None:
            selected = (int(interface.bInterfaceNumber), out_ep, in_ep)
            break
    if selected is None:
        raise RuntimeError("این رابط USB خروجی و بازخورد وضعیت ESC/POS قابل‌خواندن ندارد.")
    interface_number, out_ep, in_ep = selected
    claimed = False
    try:
        usb.util.claim_interface(target, interface_number)
        claimed = True
        target.write(out_ep.bEndpointAddress, b"\x10\x04\x01", timeout=timeout_ms)
        return bytes(target.read(in_ep.bEndpointAddress, 8, timeout=timeout_ms))
    except usb.core.USBError as exc:
        if getattr(exc, "errno", None) in (110, 60):
            return b""
        raise RuntimeError("پرس‌وجوی وضعیت ESC/POS از USB ناموفق بود: " + str(exc)[:160]) from exc
    finally:
        if claimed:
            try:
                usb.util.release_interface(target, interface_number)
            except Exception:
                pass


def _modbus_client(device: dict):
    try:
        from pymodbus.client import ModbusTcpClient
    except ImportError:
        try:
            from pymodbus.client.sync import ModbusTcpClient
        except ImportError as exc:
            raise RuntimeError("پکیج pymodbus نصب نیست؛ requirements پل را نصب کنید.") from exc
    try:
        from pymodbus.client import ModbusSerialClient
    except ImportError:
        try:
            from pymodbus.client.sync import ModbusSerialClient
        except ImportError:
            ModbusSerialClient = None
    timeout = 0.35
    if device.get("type") == "modbus_gate" or device.get("transport") == "tcp":
        try:
            return ModbusTcpClient(host=str(device["host"]), port=int(device.get("port", 502)), timeout=timeout, retries=0)
        except TypeError:
            return ModbusTcpClient(str(device["host"]), port=int(device.get("port", 502)), timeout=timeout)
    if ModbusSerialClient is None:
        raise RuntimeError("نسخهٔ pymodbus نصب‌شده Modbus RTU سریال را پشتیبانی نمی‌کند.")
    if not device.get("serial_port"):
        raise ValueError("پورت سریال گیت انتخاب نشده است.")
    try:
        return ModbusSerialClient(port=str(device["serial_port"]), baudrate=int(device.get("baudrate", 9600)),
                                  bytesize=8, parity="N", stopbits=1, timeout=timeout, retries=0)
    except TypeError:
        return ModbusSerialClient(method="rtu", port=str(device["serial_port"]), baudrate=int(device.get("baudrate", 9600)), timeout=timeout)


def _modbus_call(client, operation: str, device: dict, value=None):
    method = getattr(client, operation)
    address = int(device.get("coil", 0))
    common = {"address": address}
    if operation == "read_coils":
        common["count"] = 1
    else:
        common["value"] = bool(value)
    last_error = None
    for unit_name in ("device_id", "slave", "unit"):
        try:
            return method(**common, **{unit_name: int(device.get("unit_id", 1))})
        except TypeError as exc:
            last_error = exc
    if last_error:
        raise last_error
    raise RuntimeError("نسخهٔ pymodbus با این دستگاه سازگار نیست.")


def _modbus_read_coils(device: dict) -> dict:
    client = _modbus_client(device)
    try:
        if not client.connect():
            raise RuntimeError("اتصال Modbus برقرار نشد.")
        started = time.perf_counter()
        response = _modbus_call(client, "read_coils", device)
        if response is None or (callable(getattr(response, "isError", None)) and response.isError()):
            raise RuntimeError("پاسخ Read Coils معتبر نیست.")
        coils = list(getattr(response, "bits", []) or [])
        return {"verified": True, "coil": bool(coils[0]) if coils else None,
                "latency_ms": round((time.perf_counter() - started) * 1000, 1)}
    finally:
        try:
            client.close()
        except Exception:
            pass


def _modbus_write_coil(device: dict, state: bool) -> None:
    client = _modbus_client(device)
    try:
        if not client.connect():
            raise RuntimeError("اتصال Modbus برای کنترل گیت برقرار نشد.")
        response = _modbus_call(client, "write_coil", device, state)
        if response is None or (callable(getattr(response, "isError", None)) and response.isError()):
            raise RuntimeError("گیت دستور Modbus را نپذیرفت.")
    finally:
        try:
            client.close()
        except Exception:
            pass


def _probe_udp_zkteco(host: str) -> tuple[dict | None, dict]:
    device = build_zk_device(host, DEFAULT_PORT, "0", "ZKTeco " + host, "in", "udp", 0.45)
    result = probe_device(device)
    if result.get("online"):
        device.update({key: result.get(key) for key in ("model", "serial_number", "firmware", "platform", "mac", "counts") if result.get(key) is not None})
        return device, result
    return None, result


def identify_serial_barcode(cfg: dict, body: dict) -> dict:
    port = str(body.get("serial_port", body.get("port", "")) or "").strip()[:255]
    try:
        baudrate = int(body.get("baudrate", 9600) or 9600)
        seconds = max(2, min(15, int(body.get("seconds", 8) or 8)))
    except (TypeError, ValueError) as exc:
        raise ValueError("سرعت یا مهلت آزمون سریال معتبر نیست.") from exc
    if not port:
        raise ValueError("پورت COM/Serial را انتخاب کنید.")
    try:
        import serial
    except ImportError as exc:
        raise RuntimeError("پکیج pyserial نصب نیست؛ requirements پل را نصب کنید.") from exc
    captured = bytearray()
    started = time.monotonic()
    last_byte = None
    try:
        with serial.Serial(port=port, baudrate=baudrate, timeout=0.2, write_timeout=0.2) as connection:
            deadline = time.monotonic() + seconds
            while time.monotonic() < deadline and len(captured) < 256:
                chunk = connection.read(64)
                if chunk:
                    captured.extend(chunk[:256-len(captured)])
                    last_byte = time.monotonic()
                    if b"\n" in captured or b"\r" in captured:
                        break
                elif captured and last_byte and time.monotonic() - last_byte >= 0.6:
                    break
    except Exception as exc:
        raise RuntimeError("بازکردن پورت سریال ناموفق بود: " + str(exc)[:220]) from exc
    if not captured:
        raise RuntimeError("داده‌ای دریافت نشد؛ بارکدخوان را وصل کنید و هنگام شمارش معکوس یک بارکد اسکن کنید.")
    device = add_or_replace_hardware_device(cfg, {
        "type": "barcode_serial", "transport": "serial", "protocol": "serial/spp", "serial_port": port,
        "baudrate": baudrate, "name": body.get("name") or "بارکدخوان سریال " + port,
        "model": "Barcode/RS-232/SPP (اسکن دریافت شد)", "discovered": True,
    })
    _set_hardware_status(device, True, "", {"verified": True, "response": str(len(captured)) + " bytes"})
    _log_hardware_event(device, "شناسایی بارکدخوان سریال", "online", "اسکن آزمایشی دریافت شد؛ محتوای بارکد ذخیره نشد.")
    return {"device": public_hardware_device(device), "identified": True, "bytes_received": len(captured),
            "elapsed_ms": round((time.monotonic() - started) * 1000, 1)}


def register_hid_reader(cfg: dict, body: dict) -> dict:
    value = str(body.get("scan_value", "") or "").strip()
    if not value or len(value) > 128 or any(ord(char) < 32 for char in value):
        raise ValueError("یک بارکد/کارت معتبر را با HID Keyboard اسکن کنید.")
    dtype = str(body.get("type", "barcode_hid") or "barcode_hid").lower()
    if dtype not in ("barcode_hid", "rfid_reader"):
        dtype = "barcode_hid"
    device = add_or_replace_hardware_device(cfg, {
        "type": dtype, "transport": "hid", "protocol": "keyboard_wedge",
        "name": body.get("name") or ("کارت‌خوان RFID/NFC HID" if dtype == "rfid_reader" else "بارکدخوان USB HID"),
        "model": "USB HID Keyboard (بدون درایور)", "discovered": True,
    })
    _set_hardware_status(device, True, "", {"verified": True, "response": "scan captured"})
    _log_hardware_event(device, "شناسایی HID Keyboard", "online", "ورودی اسکن دریافت شد؛ مقدار بارکد/کارت ذخیره نشد.")
    return {"device": public_hardware_device(device), "identified": True, "scan_length": len(value)}


def probe_hardware_device(device: dict) -> dict:
    started = time.perf_counter()
    dtype = str(device.get("type", ""))
    protocol = str(device.get("protocol", "") or "").lower()
    details = {"verified": False}
    try:
        if dtype in ("modbus_gate", "serial_gate"):
            details.update(_modbus_read_coils(device))
        elif dtype in ("thermal_printer", "label_printer", "network_printer") and device.get("transport") == "serial":
            if dtype == "thermal_printer" and protocol in ("escpos", "auto"):
                response = _query_escpos_serial_status(device)
                verified = _valid_escpos_status(response)
                details.update({"verified": verified, "protocol": "escpos" if verified else protocol,
                                "response": response.hex() if response else "",
                                "model": device.get("model") or "Serial ESC/POS endpoint"})
            elif dtype in ("label_printer", "network_printer") and protocol in ("zpl", "tspl"):
                response = _query_label_serial_status(device, protocol)
                details.update({"verified": _valid_label_status(protocol, response), "protocol": protocol,
                                "response": response[:128].hex() if response else "",
                                "model": device.get("model") or ("Serial " + protocol.upper() + " status endpoint")})
            else:
                try:
                    import serial
                except ImportError as exc:
                    raise RuntimeError("pyserial نصب نیست؛ requirements پل را نصب کنید.") from exc
                with serial.Serial(port=str(device.get("serial_port", "")), baudrate=int(device.get("baudrate", 9600)), timeout=0.25):
                    pass
                details.update({"verified": False, "protocol": protocol,
                                "response": "COM باز شد؛ پروتکل/چاپ آزمایش نشد.",
                                "model": device.get("model") or "Serial printer endpoint"})
        elif dtype in ("thermal_printer", "label_printer", "network_printer") and device.get("transport") == "usb":
            if dtype == "thermal_printer" and protocol in ("escpos", "auto"):
                response = _query_escpos_usb_status(device)
                verified = _valid_escpos_status(response)
                details.update({"verified": verified, "protocol": "escpos" if verified else protocol,
                                "response": response.hex() if response else "",
                                "model": device.get("model") or "USB ESC/POS endpoint"})
            else:
                try:
                    import usb.core
                except ImportError as exc:
                    raise RuntimeError("pyusb نصب نیست؛ requirements پل را نصب کنید.") from exc
                vid, pid = int(str(device.get("usb_vendor_id", "")), 16), int(str(device.get("usb_product_id", "")), 16)
                if _usb_find(idVendor=vid, idProduct=pid) is None:
                    raise RuntimeError("دستگاه USB با VID/PID ثبت‌شده متصل نیست.")
                details.update({"verified": False, "protocol": protocol,
                                "response": "VID/PID حاضر است؛ پروتکل چاپ از USB تأیید نشد.",
                                "model": device.get("model") or "USB printer endpoint"})
        elif dtype in ("serial_candidate", "barcode_serial", "dry_contact", "rfid_reader") and device.get("transport") == "serial":
            try:
                import serial
            except ImportError as exc:
                raise RuntimeError("pyserial نصب نیست؛ requirements پل را نصب کنید.") from exc
            with serial.Serial(port=str(device.get("serial_port", "")), baudrate=int(device.get("baudrate", 9600)), timeout=0.25):
                pass
            details.update({"verified": False, "protocol": protocol,
                            "response": "پورت COM باز شد؛ خواندن داده/پروتکل در آزمون جداگانه لازم است.",
                            "model": device.get("model") or "Serial device"})
        elif dtype == "usb_candidate":
            try:
                import usb.core
            except ImportError as exc:
                raise RuntimeError("pyusb نصب نیست؛ requirements پل را نصب کنید.") from exc
            vid, pid = int(str(device.get("usb_vendor_id", "0")), 16), int(str(device.get("usb_product_id", "0")), 16)
            found = _usb_find(idVendor=vid, idProduct=pid)
            if found is None:
                raise RuntimeError("دستگاه USB دیگر متصل نیست.")
            details.update({"verified": False, "response": "VID/PID حاضر است؛ نقش و پروتکل دستگاه مشخص نشد.",
                            "model": device.get("model") or "USB device"})
        elif dtype == "barcode_hid" or (dtype == "rfid_reader" and device.get("transport") == "hid"):
            details.update({"verified": False, "model": device.get("model") or "HID Keyboard",
                            "response": "HID کیبوردی از راه دور قابل probe نیست؛ یک اسکن نمونه برای تأیید لازم است."})
            elapsed = round((time.perf_counter() - started) * 1000, 1)
            details["latency_ms"] = elapsed
            message = "HID فقط با دریافت اسکن نمونه قابل تأیید است."
            status = _set_hardware_status(device, None, message, details)
            _log_hardware_event(device, "تست HID نیازمند ورودی", "needs_input", message)
            return {"online": None, "state": "unknown", "last_checked": status["last_checked"],
                    "error": message, **details}
        elif dtype == "adms_push":
            raise RuntimeError("ADMS به دریافت‌کنندهٔ عمومی HTTPS در WordPress نیاز دارد؛ این نسخهٔ پل محلی endpoint عمومی ندارد.")
        elif dtype == "face_reader":
            scheme = "https" if device.get("transport") == "https" or int(device.get("port", 0)) == 443 else "http"
            if device.get("username") and scheme != "https":
                raise RuntimeError("اعتبارنامهٔ Basic روی HTTP ارسال نمی‌شود؛ برای آزمون احراز هویت HTTPS انتخاب کنید.")
            url = scheme + "://" + str(device["host"]) + ":" + str(int(device.get("port", 80))) + str(device.get("api_path", "/"))
            headers = {"Accept": "application/json, text/plain, */*"}
            if device.get("username"):
                import base64
                token = base64.b64encode((str(device["username"]) + ":" + str(device.get("password", ""))).encode("utf-8")).decode("ascii")
                headers["Authorization"] = "Basic " + token
            request = urllib.request.Request(url, headers=headers, method="GET")
            try:
                with urllib.request.urlopen(request, timeout=0.6) as response:
                    details.update({"verified": False, "response": "HTTP " + str(response.status) + "; reachability only",
                                    "model": device.get("model") or "HTTP API endpoint"})
            except urllib.error.HTTPError as exc:
                if exc.code in (401, 403, 404):
                    details.update({"verified": False, "response": "HTTP " + str(exc.code) + "; API/model not verified",
                                    "model": device.get("model") or "HTTP API endpoint"})
                else:
                    raise RuntimeError("HTTP " + str(exc.code) + " از دستگاه چهره‌خوان") from exc
        elif dtype in ("thermal_printer", "label_printer", "network_printer", "network_candidate"):
            host, port = str(device["host"]), int(device.get("port", 9100))
            if dtype != "network_candidate" and protocol == "escpos":
                response = _query_escpos_status(host, port)
                verified = _valid_escpos_status(response)
                details.update({"verified": verified, "protocol": "escpos",
                                "response": response.hex() if response else "",
                                "model": device.get("model") or "ESC/POS status endpoint"})
            elif dtype != "network_candidate" and dtype in ("label_printer", "network_printer") and protocol in ("zpl", "tspl"):
                response = _query_label_status(host, port, protocol)
                details.update({"verified": _valid_label_status(protocol, response), "protocol": protocol,
                                "response": response[:128].hex() if response else "",
                                "model": device.get("model") or (protocol.upper() + " status endpoint")})
            else:
                with socket.create_connection((host, port), timeout=0.45):
                    pass
                details.update({"verified": False, "protocol": protocol,
                                "response": "پورت TCP باز است؛ نوع مدل/پروتکل چاپ تأیید نشد.",
                                "model": device.get("model") or "TCP port open"})
        elif device.get("host"):
            with socket.create_connection((str(device["host"]), int(device.get("port", 0))), timeout=0.45):
                details.update({"verified": False, "model": device.get("model") or "TCP endpoint"})
        else:
            raise RuntimeError("برای این نوع دستگاه، اتصال قابل‌آزمون ثبت نشده است.")
        elapsed = round((time.perf_counter() - started) * 1000, 1)
        details["latency_ms"] = elapsed
        status = _set_hardware_status(device, True, "", details)
        result_state = "online" if status.get("verified") else "reachable"
        _log_hardware_event(device, "تست اتصال", result_state, str(details.get("model", ""))[:180])
        return {"online": True, "state": result_state, "last_checked": status["last_checked"], "error": "", **details}
    except Exception as exc:
        error = str(exc)[:400]
        status = _set_hardware_status(device, False, error, details)
        _log_hardware_event(device, "تست اتصال", "offline", error)
        return {"online": False, "state": "offline", "last_checked": status["last_checked"], "error": error, **details}

def _fresh_attendance_event(stamp_value: str, max_age: float = 12.0, site_timezone: str = "") -> bool:
    try:
        stamp = datetime.fromisoformat(str(stamp_value).replace("Z", "+00:00"))
        if stamp.tzinfo is None:
            configured_timezone = _wordpress_timezone(site_timezone)
            stamp = stamp.replace(tzinfo=configured_timezone) if configured_timezone else stamp.astimezone()
        return abs(time.time() - stamp.timestamp()) <= max_age
    except (TypeError, ValueError, OverflowError, OSError):
        return False

def _open_gate_for_authorized_event(cfg: dict, event: dict, result: dict) -> list[dict]:
    # A physical pulse requires a complete, non-duplicate acknowledgement correlated to the exact
    # terminal event and user. Missing/legacy response fields must fail closed rather than infer consent.
    if not isinstance(result, dict) or result.get("accepted") is not True or result.get("duplicate") is not False:
        return []
    response_event_id = str(result.get("event_id", "") or "").lower()
    request_event_id = str(event.get("event_id", "") or "").lower()
    if not re.fullmatch(r"[a-f0-9]{64}", request_event_id) or response_event_id != request_event_id:
        return []
    request_uid = str(event.get("device_user_id", "") or "").strip()
    response_uid = str(result.get("device_user_id", "") or "").strip()
    if not request_uid or response_uid != request_uid:
        return []
    if str(result.get("decision", "")).strip().lower() != "allowed":
        return []
    member_id = str(result.get("member_id", "") or "").strip()
    try:
        has_member_id = int(member_id) > 0
    except (TypeError, ValueError):
        has_member_id = False
    if not has_member_id and not str(result.get("member_code", "") or "").strip():
        return []
    site_timezone = str(cfg.get("site_timezone", "") or "")
    if _wordpress_timezone(site_timezone) is None:
        return []
    if not _fresh_attendance_event(str(event.get("timestamp", "") or ""), site_timezone=site_timezone):
        return []
    server_stamp = str(result.get("occurred_at", "") or "").strip()
    if server_stamp and not _fresh_attendance_event(server_stamp, site_timezone=site_timezone):
        return []
    opened = []
    for raw in cfg.get("hardware_devices", []):
        try:
            gate = normalize_hardware_device(raw)
        except (ValueError, TypeError):
            continue
        if gate.get("type") not in ("modbus_gate", "serial_gate") or not gate.get("auto_open_on_allowed"):
            continue
        if str(event.get("direction", "in")).lower() not in gate.get("open_directions", ["in"]):
            continue
        try:
            with _GATE_OPERATION_LOCK:
                last = _GATE_LAST_OPEN.get(gate["id"], 0)
                if time.monotonic() - last < 0.5:
                    raise RuntimeError("گیت برای جلوگیری از پالس تکراری در فاصلهٔ ایمن است.")
                client = _modbus_client(gate)
                if not client.connect():
                    try:
                        client.close()
                    except Exception:
                        pass
                    raise RuntimeError("اتصال Modbus برای کنترل گیت برقرار نشد.")
                begin = time.perf_counter()
                try:
                    response = _modbus_call(client, "write_coil", gate, True)
                    if response is None or (callable(getattr(response, "isError", None)) and response.isError()):
                        raise RuntimeError("گیت دستور بازشدن را نپذیرفت.")
                    latency = round((time.perf_counter() - begin) * 1000, 1)
                    _GATE_LAST_OPEN[gate["id"]] = time.monotonic()
                    time.sleep(int(gate.get("pulse_ms", 500)) / 1000.0)
                finally:
                    try:
                        _modbus_call(client, "write_coil", gate, False)
                    finally:
                        client.close()
                _GATE_LAST_OPEN[gate["id"]] = time.monotonic()
            detail = "ورود مجاز از WordPress؛ زمان فرمان coil " + str(latency) + "ms، پالس " + str(gate.get("pulse_ms", 500)) + "ms"
            _set_hardware_status(gate, True, "", {"verified": True, "latency_ms": latency})
            _log_hardware_event(gate, "بازکردن گیت پس از تأیید", "opened", detail)
            opened.append({"device_id": gate["id"], "latency_ms": latency})
        except Exception as exc:
            _set_hardware_status(gate, False, str(exc), {"verified": False})
            _log_hardware_event(gate, "بازکردن گیت پس از تأیید", "failed", str(exc)[:240])
    return opened


def discover_hardware_devices(cfg: dict) -> dict:
    started = time.perf_counter()
    open_ports, scan_warnings = _scan_tcp_ports(cfg)
    discovered = []
    verified_zk = set()
    configured_zk_hosts = {str(item.get("host", "")) for item in cfg.get("zkteco_devices", []) if item.get("host")}
    tcp_zk_hosts = sorted({host for host, port in open_ports if port == DEFAULT_PORT},
                          key=lambda host: int(ipaddress.IPv4Address(host)))

    def check_zk(host: str):
        configured = _configured_zk_by_address(cfg, host, DEFAULT_PORT)
        device = configured or build_zk_device(host, DEFAULT_PORT, "0", "ZKTeco " + host, "in", "tcp", 0.55)
        info = probe_device(device)
        return device, info, configured is not None

    with ThreadPoolExecutor(max_workers=min(16, max(1, len(tcp_zk_hosts)))) as executor:
        zk_results = list(executor.map(check_zk, tcp_zk_hosts)) if tcp_zk_hosts else []
    for device, info, was_configured in zk_results:
        if info.get("online"):
            device.update({key: info.get(key) for key in ("model", "serial_number", "firmware", "platform", "mac", "counts") if info.get(key) is not None})
            device["timeout"] = float(device.get("timeout", 8.0) or 8.0)
            saved = device if was_configured else add_or_replace_zk_device(cfg, device)
            verified_zk.add(device["host"])
            discovered.append(public_device(saved))
            _log_hardware_event(saved, "شناسایی ZKTeco TCP", "online", str(info.get("model") or info.get("firmware") or "PyZK"))

    dependencies = _available_hardware_dependencies()
    for raw in cfg.get("zkteco_devices", []):
        if str(raw.get("transport", "tcp")).lower() != "udp":
            continue
        configured = _configured_zk_by_address(cfg, str(raw.get("host", "")), int(raw.get("port", DEFAULT_PORT)))
        if not configured or configured["host"] in verified_zk:
            continue
        info = probe_device(configured)
        if info.get("online"):
            configured.update({key: info.get(key) for key in ("model", "serial_number", "firmware", "platform", "mac", "counts") if info.get(key) is not None})
            verified_zk.add(configured["host"])
            discovered.append(public_device(configured))
            _log_hardware_event(configured, "آزمون ZKTeco UDP ذخیره‌شده", "online", str(info.get("model") or info.get("firmware") or "PyZK"))

    if dependencies.get("pyzk", {}).get("installed"):
        networks, own = _local_scan_networks(cfg)
        udp_targets = [str(host) for network in networks for host in network.hosts()
                       if host not in own and str(host) not in verified_zk and str(host) not in configured_zk_hosts]
        if udp_targets:
            with ThreadPoolExecutor(max_workers=min(64, len(udp_targets))) as executor:
                for host, result in zip(udp_targets, executor.map(_probe_udp_zkteco, udp_targets)):
                    device, info = result
                    if device and info.get("online"):
                        device["timeout"] = 8.0
                        saved = add_or_replace_zk_device(cfg, device)
                        verified_zk.add(host)
                        discovered.append(public_device(saved))
                        _log_hardware_event(saved, "شناسایی ZKTeco UDP", "online", str(info.get("model") or info.get("firmware") or "PyZK"))

    for host, port in open_ports:
        if port == DEFAULT_PORT:
            if host in verified_zk or host in configured_zk_hosts:
                continue
            existing = _find_configured_hardware_device(
                cfg, lambda item: item.get("host") == host and int(item.get("port", 0) or 0) == port
                and item.get("type") == "network_candidate")
            if existing:
                _set_hardware_status(existing, True, "TCP باز است، اما مدل/پروتکل تأیید نشد.", {"verified": False})
                discovered.append(public_hardware_device(existing))
                continue
            candidate = normalize_hardware_device({"type": "network_candidate", "host": host, "port": port,
                                                    "name": "دستگاه شبکه‌ای " + host, "model": "پورت 4370 باز؛ PyZK تأیید نشد.", "discovered": True})
            saved = add_or_replace_hardware_device(cfg, candidate)
            _set_hardware_status(saved, True, "TCP باز است، اما مدل/پروتکل تأیید نشد.", {"verified": False})
            discovered.append(public_hardware_device(saved))
            continue

        if port in (515, 9100):
            existing = _find_configured_hardware_device(
                cfg, lambda item: item.get("host") == host and int(item.get("port", 0) or 0) == port
                and item.get("type") in ("thermal_printer", "label_printer", "network_printer", "network_candidate"))
            if existing:
                probe = probe_hardware_device(existing)
                discovered.append(public_hardware_device(existing))
                _log_hardware_event(existing, "بازبینی چاپگر پیکربندی‌شده", "online" if probe.get("online") else "offline",
                                    str(probe.get("response") or probe.get("model") or probe.get("error", ""))[:180])
                continue
            dtype = "network_printer"
            protocol = "lpd" if port == 515 else "auto"
            model = "پورت LPD باز؛ صف چاپ نیازمند تنظیم جداگانه" if port == 515 else "پورت 9100 باز؛ پروتکل چاپ تأیید نشده"
            candidate = normalize_hardware_device({"type": dtype, "host": host, "port": port, "transport": "tcp",
                                                    "protocol": protocol, "name": "پرینتر نامشخص " + host,
                                                    "model": model, "discovered": True})
            saved = add_or_replace_hardware_device(cfg, candidate)
            _set_hardware_status(saved, True, "پورت شبکه باز است؛ پروتکل/مدل چاپ تأیید نشده.",
                                 {"verified": False, "protocol": protocol, "model": model})
            discovered.append(public_hardware_device(saved))
            _log_hardware_event(saved, "شناسایی پورت چاپگر", "reachable", model)
            continue

        if port == 502:
            existing = _find_configured_hardware_device(
                cfg, lambda item: item.get("host") == host and int(item.get("port", 0) or 0) == port
                and item.get("type") == "modbus_gate")
            device = existing or normalize_hardware_device({"type": "modbus_gate", "host": host, "port": port,
                                                              "transport": "tcp", "protocol": "modbus_tcp",
                                                              "name": "کنترلر Modbus " + host,
                                                              "model": "Modbus/TCP candidate", "discovered": True})
            saved = device if existing else add_or_replace_hardware_device(cfg, device)
            try:
                probe = _modbus_read_coils(saved)
                _set_hardware_status(saved, True, "", {**probe, "model": saved.get("model") or "Modbus/TCP controller"})
                saved["model"] = saved.get("model") or "Modbus/TCP controller"
            except Exception as exc:
                probe = {"verified": False}
                _set_hardware_status(saved, True, str(exc)[:300], {"verified": False, "model": "پورت 502 باز؛ Read Coils تأیید نشد"})
            discovered.append(public_hardware_device(saved))
            _log_hardware_event(saved, "شناسایی Modbus TCP", "online" if probe.get("verified") else "candidate",
                                str(probe.get("latency_ms", "")) + "ms")

    serial_items, serial_errors = _enumerate_serial_ports()
    for candidate in serial_items:
        existing = _find_configured_hardware_device(
            cfg, lambda item: item.get("serial_port") == candidate.get("serial_port")
            and item.get("type") != "serial_candidate")
        if existing:
            if existing.get("type") in ("thermal_printer", "label_printer", "network_printer"):
                probe_hardware_device(existing)
            else:
                _set_hardware_status(existing, True, "پورت COM/USB-Serial حاضر است؛ پروتکل دستگاه هنوز تأیید نشده.", {"verified": False})
            discovered.append(public_hardware_device(existing))
            continue
        saved = add_or_replace_hardware_device(cfg, candidate)
        _set_hardware_status(saved, True, "پورت COM/USB-Serial حاضر است؛ نوع دستگاه را با آزمون شناسایی کنید.", {"verified": False})
        discovered.append(public_hardware_device(saved))

    usb_items, usb_errors = _enumerate_usb_devices()
    for candidate in usb_items:
        vid, pid, serial_number = candidate.get("usb_vendor_id"), candidate.get("usb_product_id"), candidate.get("usb_serial", "")
        existing = _find_configured_hardware_device(
            cfg, lambda item: item.get("type") != "usb_candidate"
            and item.get("usb_vendor_id") == vid and item.get("usb_product_id") == pid
            and (not serial_number or not item.get("usb_serial") or item.get("usb_serial") == serial_number))
        if existing:
            if existing.get("type") == "thermal_printer" and existing.get("protocol") in ("escpos", "auto"):
                probe_hardware_device(existing)
            else:
                _set_hardware_status(existing, True, "USB VID/PID حاضر است؛ پروتکل/کاربری دستگاه نیازمند آزمون است.", {"verified": False})
            discovered.append(public_hardware_device(existing))
            continue
        saved = add_or_replace_hardware_device(cfg, candidate)
        _set_hardware_status(saved, True, "USB شناسایی شد؛ پروتکل/کاربری دستگاه نیازمند آزمون است.", {"verified": False})
        discovered.append(public_hardware_device(saved))

    unique = {item["id"]: item for item in discovered}
    elapsed = round((time.perf_counter() - started) * 1000, 1)
    warnings = scan_warnings + serial_errors + usb_errors
    if not dependencies.get("pyzk", {}).get("installed"):
        warnings.append("pyzk نصب نیست؛ ZKTeco فقط با افزودن و آزمون دستی قابل بررسی است.")
    if not dependencies.get("pymodbus", {}).get("installed"):
        warnings.append("pymodbus نصب نیست؛ پورت ۵۰۲ فقط به‌عنوان نامزد ثبت می‌شود و Read Coils/گیت فعال نیست.")
    _log_hardware_event("اسکن شبکه/USB", "completed", "complete", str(len(unique)) + " دستگاه · " + str(elapsed) + "ms")
    return {"items": list(unique.values()), "devices": _local_api_items(cfg), "ports": list(NETWORK_SCAN_PORTS),
            "serial_ports": [public_hardware_device(item) for item in serial_items],
            "usb_devices": [public_hardware_device(item) for item in usb_items],
            "dependencies": dependencies, "warnings": warnings, "scan_ms": elapsed,
            "network_scope": "private IPv4 /24 only",
            "probe": "TCP port reachability, ZK device-info probe, Modbus Read Coils; no print/relay write"}

def _local_api_items(cfg: dict) -> list:
    items = [public_device(device) for device in cfg.get("zkteco_devices", [])]
    for item in cfg.get("hardware_devices", []):
        try:
            items.append(public_hardware_device(normalize_hardware_device(item)))
        except (ValueError, TypeError):
            continue
    return items


def _set_or_replace_device(cfg: dict, identifier: str, body: dict) -> dict:
    with _CONFIG_LOCK:
        latest = load_config()
        current = get_configured_device(latest, identifier)
        merged = {**current, **body}
        if not str(body.get("password", "") or "").strip():
            merged["password"] = current.get("password", 0)
        device = add_or_replace_zk_device(latest, merged)
        cfg["zkteco_devices"] = latest.get("zkteco_devices", [])
        cfg["sent_event_ids"] = latest.get("sent_event_ids", {})
        return device


def make_local_api_server(port: int = DEFAULT_LOCAL_API_PORT):
    if not 1024 <= int(port) <= 65535:
        raise ValueError("پورت API محلی باید بین ۱۰۲۴ تا ۶۵۵۳۵ باشد.")

    class LocalBridgeHandler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        server_version = "WSM-Local-Bridge"
        sys_version = ""

        def log_message(self, fmt, *args):
            # Log only the method and path; never echo query values, bodies, or credentials.
            try:
                parts = str(getattr(self, "requestline", "")).split()
                if len(parts) >= 2:
                    method = parts[0][:16]
                    path = urllib.parse.urlsplit(parts[1]).path[:200]
                    sys.stderr.write("[WSM Local API] " + method + " " + path + "\n")
                else:
                    sys.stderr.write("[WSM Local API] request error\n")
            except Exception:
                pass

        def _send_json(self, status: int, payload: dict):
            raw = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
            self.send_response(int(status))
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Cache-Control", "no-store, max-age=0")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Length", str(len(raw)))
            self.send_header("Connection", "close")
            if getattr(self, "_cors_origin", ""):
                self.send_header("Access-Control-Allow-Origin", self._cors_origin)
                self.send_header("Vary", "Origin")
            self.end_headers()
            try:
                self.wfile.write(raw)
            except (BrokenPipeError, ConnectionResetError):
                pass
            self.close_connection = True

        def _send_error(self, status: int, message: str, code: str = "bridge_error"):
            self._send_json(status, {"error": {"code": code, "message": str(message)}})

        def _trusted_config(self):
            try:
                raw_host = self.headers.get("Host", "")
                parsed_host = urllib.parse.urlsplit("//" + raw_host)
                host = (parsed_host.hostname or "").lower()
                request_port = parsed_host.port
                server_port = int(self.server.server_address[1])
                if host not in ("127.0.0.1", "localhost") or (request_port is not None and request_port != server_port):
                    self._send_error(403, "فقط Host محلی پذیرفته می‌شود.", "local_host_only")
                    return None
                client_ip = ipaddress.ip_address(self.client_address[0])
                if not client_ip.is_loopback:
                    self._send_error(403, "این API فقط از همان رایانه قابل استفاده است.", "loopback_only")
                    return None
                cfg = load_config()
                ensure_local_api_key(cfg)
                origin = self.headers.get("Origin", "")
                self._cors_origin = ""
                if origin:
                    normalized = normalize_origin(origin)
                    if not cors_origin(origin) or (normalized != site_origin(cfg) and not is_tauri_local_origin(origin)):
                        self._send_error(403, "Origin این صفحه با فروشگاه پیکربندی‌شده مطابقت ندارد.", "origin_denied")
                        return None
                    self._cors_origin = cors_origin(origin)
                return cfg
            except Exception:
                self._send_error(503, "پیکربندی پل آماده نیست؛ ابتدا اتصال فروشگاه را تنظیم کنید.", "bridge_not_configured")
                return None

        def _authorized_config(self):
            cfg = self._trusted_config()
            if cfg is None:
                return None
            supplied = self.headers.get("X-WSM-Local-Key", "")
            authorization = self.headers.get("Authorization", "")
            if not supplied and authorization.lower().startswith("bearer "):
                supplied = authorization[7:].strip()
            expected = str(cfg.get("local_api_key", ""))
            supplied_text = str(supplied)
            if not supplied_text.isascii() or not expected.isascii() or not secrets.compare_digest(supplied_text, expected):
                self._send_error(401, "کلید محلی معتبر نیست.", "local_key_invalid")
                return None
            return cfg

        def _read_json(self) -> dict:
            raw_length = self.headers.get("Content-Length", "0")
            try:
                length = int(raw_length)
            except (TypeError, ValueError):
                raise BridgeAPIError(400, "Content-Length نامعتبر است.", "body_length_invalid")
            if length < 0 or length > MAX_LOCAL_BODY_BYTES:
                raise BridgeAPIError(413, "بدنهٔ درخواست بیش از حد بزرگ است.", "body_too_large")
            raw = self.rfile.read(length) if length else b"{}"
            try:
                body = json.loads(raw.decode("utf-8") or "{}")
            except (UnicodeDecodeError, json.JSONDecodeError) as exc:
                raise BridgeAPIError(400, "بدنه باید JSON معتبر UTF-8 باشد.", "json_invalid") from exc
            if not isinstance(body, dict):
                raise BridgeAPIError(400, "بدنهٔ JSON باید یک شیء باشد.", "json_object_required")
            return body

        def _check_loopback_peer(self):
            try:
                raw_host = self.headers.get("Host", "")
                parsed_host = urllib.parse.urlsplit("//" + raw_host)
                host = (parsed_host.hostname or "").lower()
                request_port = parsed_host.port
                server_port = int(self.server.server_address[1])
                if host not in ("127.0.0.1", "localhost") or (request_port is not None and request_port != server_port):
                    self._send_error(403, "فقط Host محلی پذیرفته می‌شود.", "local_host_only")
                    return False
                if not ipaddress.ip_address(self.client_address[0]).is_loopback:
                    self._send_error(403, "این API فقط از همان رایانه قابل استفاده است.", "loopback_only")
                    return False
                return True
            except Exception:
                self._send_error(403, "درخواست محلی معتبر نیست.", "local_peer_invalid")
                return False

        def _handle_pair(self):
            if not self._check_loopback_peer():
                return
            try:
                request_origin = self.headers.get("Origin", "")
                cors = cors_origin(request_origin)
                if not cors:
                    raise BridgeAPIError(403, "Origin معتبر برای جفت‌سازی الزامی است.", "origin_required")
                self._cors_origin = cors
                body = self._read_json()
                api_base = normalize_api_base(str(body.get("api_base", "")))
                pair_origin = normalize_origin(str(body.get("origin", "") or request_origin))
                if site_origin({"api_base": api_base}) != pair_origin:
                    raise BridgeAPIError(403, "مبدأ جفت‌سازی با نشانی REST API یکسان نیست.", "origin_mismatch")
                if normalize_origin(request_origin) != pair_origin and not is_tauri_local_origin(request_origin):
                    raise BridgeAPIError(403, "Origin صفحه با مبدأ مجاز جفت‌سازی مطابقت ندارد.", "origin_denied")
                pairing_token = str(body.get("pairing_token", "") or "").strip()
                if not pairing_token or len(pairing_token) < 40:
                    raise BridgeAPIError(400, "کد جفت‌سازی WordPress معتبر نیست.", "pair_token_invalid")
                try:
                    current = load_config()
                except (RuntimeError, ValueError, OSError):
                    # A fresh, administrator-authorized grant can repair an old or incomplete local config.
                    current = None
                current_api = ""
                if current is not None:
                    try:
                        current_api = normalize_api_base(str(current.get("api_base", "")))
                    except ValueError:
                        current_api = ""
                    supplied_key = str(self.headers.get("X-WSM-Local-Key", ""))
                    expected_key = str(current.get("local_api_key", ""))
                    if (current.get("pairing_mode") == "admin_pair" and current_api == api_base
                            and supplied_key.isascii() and expected_key.isascii() and len(expected_key) >= 32
                            and secrets.compare_digest(supplied_key, expected_key)):
                        key = ensure_local_api_key(current)
                        _PAIR_EVENT.set()
                        self._send_json(200, {"paired": True, "existing": True, "local_api_key": key,
                                              "device_id": int(current.get("device_id", 0)), "api_base": api_base})
                        return
                info = {
                    "device_name": (str(body.get("device_name", "WP Store Manager Local Bridge") or "").strip() or "WP Store Manager Local Bridge")[:100],
                    "platform": platform.system() or "Desktop",
                    "os_version": platform.release()[:40],
                    "app_version": APP_VERSION,
                }
                status, data = request_json(api_base + "bridge/pair", "POST", {
                    "pairing_token": pairing_token,
                    "origin": pair_origin,
                    **info,
                }, timeout=20)
                if status < 200 or status >= 300 or not data.get("token"):
                    message = str(data.get("message", "جفت‌سازی WordPress ناموفق بود."))
                    error_status = status if 400 <= status < 500 else 502
                    raise BridgeAPIError(error_status, message, str(data.get("code", "pairing_failed")))
                same_site = bool(current is not None and current_api == api_base)
                cfg = {
                    "api_base": api_base,
                    "token": str(data.get("token", "")),
                    "refresh_token": str(data.get("refresh_token", "")),
                    "signing_key": str(data.get("signing_key", "")),
                    "device_id": int(data.get("device_id", 0)),
                    "store_name": str(data.get("store_name", "")),
                    "device_name": info["device_name"],
                    "app_version": APP_VERSION,
                    "pairing_mode": "admin_pair",
                    "paired_at": int(time.time()),
                    "local_api_key": secrets.token_urlsafe(32),
                    "zkteco_devices": list(current.get("zkteco_devices", [])) if current else [],
                    "hardware_devices": list(current.get("hardware_devices", [])) if current else [],
                    "sent_event_ids": dict(current.get("sent_event_ids", {})) if same_site and current else {},
                    "last_access_sync": 0,
                }
                if not cfg["token"] or not cfg["signing_key"] or not cfg["device_id"]:
                    raise BridgeAPIError(502, "WordPress پاسخ اتصال کامل برنگرداند.", "pair_response_invalid")
                save_config(cfg)
                if current is not None and current.get("token") and current.get("signing_key"):
                    try:
                        ApiClient(current).call("logout", "POST", {}, retry=False)
                    except Exception:
                        pass
                _PAIR_EVENT.set()
                self._send_json(200, {"paired": True, "existing": False, "local_api_key": cfg["local_api_key"],
                                      "device_id": cfg["device_id"], "api_base": api_base})
            except BridgeAPIError as exc:
                self._send_error(exc.status, str(exc), exc.code)
            except ValueError as exc:
                self._send_error(400, str(exc), "invalid_request")
            except RuntimeError as exc:
                self._send_error(502, str(exc), "pairing_failed")
            except Exception:
                self._send_error(500, "جفت‌سازی پل انجام نشد؛ گزارش محلی را بررسی کنید.", "pairing_failed")

        def _send_preflight(self):
            self.send_response(204)
            self.send_header("Access-Control-Allow-Origin", self._cors_origin)
            self.send_header("Vary", "Origin")
            self.send_header("Access-Control-Allow-Methods", "GET, POST, PUT, DELETE, OPTIONS")
            self.send_header("Access-Control-Allow-Headers", "Content-Type, X-WSM-Local-Key, Authorization")
            self.send_header("Access-Control-Allow-Private-Network", "true")
            self.send_header("Access-Control-Max-Age", "300")
            self.send_header("Content-Length", "0")
            self.send_header("Connection", "close")
            self.end_headers()
            self.close_connection = True

        def _handle_options(self):
            path = urllib.parse.urlsplit(self.path).path
            if path == LOCAL_API_PREFIX + "/pair":
                if not self._check_loopback_peer():
                    return
                origin = cors_origin(self.headers.get("Origin", ""))
                if not origin:
                    self._send_error(403, "Preflight جفت‌سازی باید Origin معتبر داشته باشد.", "origin_required")
                    return
                self._cors_origin = origin
                self._send_preflight()
                return
            cfg = self._trusted_config()
            if cfg is None:
                return
            if not getattr(self, "_cors_origin", ""):
                self._send_error(403, "Preflight مرورگر باید از Origin مجاز فروشگاه باشد.", "origin_required")
                return
            self._send_preflight()

        def _stream_events(self):
            subscriber = queue.Queue(maxsize=100)
            with _SSE_SUBSCRIBERS_LOCK:
                _SSE_SUBSCRIBERS.add(subscriber)
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream; charset=utf-8")
            self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("X-Accel-Buffering", "no")
            self.send_header("Connection", "keep-alive")
            if getattr(self, "_cors_origin", ""):
                self.send_header("Access-Control-Allow-Origin", self._cors_origin)
                self.send_header("Vary", "Origin")
            self.end_headers()
            try:
                self.wfile.write(b": WSM bridge stream ready\n\n")
                self.wfile.flush()
                while not getattr(self.server, "_wsm_sse_stop", threading.Event()).is_set():
                    try:
                        item = subscriber.get(timeout=15)
                        payload = json.dumps(item["data"], ensure_ascii=False, separators=(",", ":"))
                        chunk = "id: " + str(item["id"]) + "\nevent: " + str(item["event"]) + "\ndata: " + payload + "\n\n"
                        self.wfile.write(chunk.encode("utf-8"))
                    except queue.Empty:
                        self.wfile.write(b": heartbeat\n\n")
                    self.wfile.flush()
            except (BrokenPipeError, ConnectionResetError, OSError):
                pass
            finally:
                with _SSE_SUBSCRIBERS_LOCK:
                    _SSE_SUBSCRIBERS.discard(subscriber)
                self.close_connection = True

        def _route(self, method: str, path: str, body: dict, cfg: dict):
            parts = [urllib.parse.unquote(part) for part in path.strip("/").split("/") if part]
            if any(part in (".", "..") for part in parts) or parts[:2] != ["api", "v1"]:
                raise BridgeAPIError(404, "مسیر API پیدا نشد.", "route_not_found")
            tail = parts[2:]
            if tail == ["discover"] and method in ("GET", "POST"):
                return 200, discover_hardware_devices(cfg)
            if tail == ["capabilities"] and method == "GET":
                return 200, {"dependencies": _available_hardware_dependencies(), "ports": list(NETWORK_SCAN_PORTS),
                             "hardware": _HARDWARE_CAPABILITIES,
                             "adms_push": {"available": False, "reason": "نیازمند endpoint عمومی HTTPS در WordPress"}}
            if tail == ["mock", "test"]:
                if method != "POST":
                    raise BridgeAPIError(405, "روش درخواست برای این مسیر پشتیبانی نمی‌شود.", "method_not_allowed")
                return 200, simulate_hardware_test(body)
            if tail == ["serial", "ports"] and method == "GET":
                ports, errors = _enumerate_serial_ports()
                return 200, {"items": [public_hardware_device(item) for item in ports], "warnings": errors}
            if tail == ["serial", "identify"] and method == "POST":
                return 200, identify_serial_barcode(cfg, body)
            if tail == ["barcode", "hid"] and method == "POST":
                return 201, register_hid_reader(cfg, body)
            if tail == ["health"] and method == "GET":
                devices = _local_api_items(cfg)
                active_gate = any(item.get("type") in ("modbus_gate", "serial_gate") and item.get("auto_open_on_allowed") for item in devices)
                return 200, {
                    "ok": True, "version": APP_VERSION, "service": "wsm-local-hardware-bridge",
                    "site_origin": site_origin(cfg), "poll_seconds": 1 if active_gate else POLL_SECONDS,
                    "devices": devices, "events": _hardware_event_snapshot(50),
                    "dependencies": _available_hardware_dependencies(), "hardware_capabilities": _HARDWARE_CAPABILITIES,
                    "active_gate": active_gate,
                    "security": {"bind": "127.0.0.1", "requires_local_key": True,
                                 "fingerprint_templates_exposed": False, "gate_auto_open_default": False},
                }
            if tail == ["devices"]:
                if method == "GET":
                    items = _local_api_items(cfg)
                    return 200, {"items": items, "total": len(items)}
                if method == "POST":
                    dtype = str(body.get("type") or body.get("device_type") or "zkteco").lower()
                    if dtype == "zkteco":
                        device = add_or_replace_zk_device(cfg, body)
                        _log_hardware_event(device, "افزودن ZKTeco", "saved", "پیکربندی ذخیره شد.")
                        return 201, {"device": public_device(device)}
                    device = add_or_replace_hardware_device(cfg, body)
                    if device.get("type") in ("modbus_gate", "serial_gate") and device.get("auto_open_on_allowed"):
                        _PAIR_EVENT.set()
                    return 201, {"device": public_hardware_device(device)}
                raise BridgeAPIError(405, "روش درخواست برای این مسیر پشتیبانی نمی‌شود.", "method_not_allowed")
            if len(tail) < 2 or tail[0] != "devices":
                raise BridgeAPIError(404, "مسیر API پیدا نشد.", "route_not_found")
            identifier = tail[1]
            resource = tail[2:]
            if identifier.startswith("hw-"):
                device = get_configured_hardware_device(cfg, identifier)
                if resource == ["print"] and method == "POST":
                    try:
                        if (str(device.get("type", "")) in ("label_printer", "network_printer")
                                and str(device.get("protocol", "")).lower() in ("zpl", "tspl")):
                            # Label status probing exists, but label job generation/transmission does not.
                            # Return an explicit unsupported response before opening any device transport.
                            raise BridgeAPIError(
                                501,
                                "چاپ لیبل ZPL/TSPL در این نسخه پیاده‌سازی نشده است؛ برای این دستگاه فقط "
                                "پرس‌وجوی وضعیت (ZPL: ~HS ، TSPL: ESC ! ?) اجرا می‌شود و هیچ job چاپی ارسال نمی‌شود.",
                                "label_print_not_supported",
                            )
                        result = _send_escpos_receipt(device, body)
                    except Exception as exc:
                        _log_hardware_event(device, "چاپ رسید POS", "failed", str(exc)[:250])
                        raise
                    _log_hardware_event(device, "چاپ رسید POS", "sent", str(result.get("height_px", "")) + "px · ESC/POS · ارسال شد")
                    return 200, result
                if not resource:
                    if method == "GET":
                        return 200, {"device": public_hardware_device(device)}
                    if method == "PUT":
                        dtype = str(body.get("type", "") or "").lower()
                        if dtype == "zkteco":
                            updated = add_or_replace_zk_device(cfg, body)
                            _remove_hardware_device(cfg, identifier)
                            _log_hardware_event(updated, "تبدیل نامزد به ZKTeco", "saved", "PyZK و همگام‌سازی تردد فعال شد.")
                            return 200, {"device": public_device(updated)}
                        updated = _set_or_replace_hardware_device(cfg, identifier, body)
                        if updated.get("type") in ("modbus_gate", "serial_gate"):
                            _PAIR_EVENT.set()
                        return 200, {"device": public_hardware_device(updated)}
                    if method == "DELETE":
                        if body.get("confirm") is not True:
                            raise ValueError("برای حذف دستگاه، confirm=true الزامی است.")
                        _remove_hardware_device(cfg, identifier)
                        _log_hardware_event(device, "حذف از پل", "removed", "تنظیم محلی حذف شد.")
                        return 200, {"removed": True, "device_id": identifier}
                if resource == ["status"] and method in ("GET", "POST"):
                    return 200, {"device": public_hardware_device(device), "probe": probe_hardware_device(device)}
                if resource == ["gate", "test"] and method == "POST":
                    if device.get("type") not in ("modbus_gate", "serial_gate"):
                        raise ValueError("این دستگاه گیت Modbus نیست.")
                    probe = _modbus_read_coils(device)
                    _set_hardware_status(device, True, "", probe)
                    _log_hardware_event(device, "Read Coils آزمون", "online", str(probe.get("latency_ms", "")) + "ms")
                    return 200, {"device": public_hardware_device(device), "probe": probe}
                raise BridgeAPIError(404, "عملیات یا مسیر دستگاه پیدا نشد.", "resource_not_found")
            device = get_configured_device(cfg, identifier)
            if not resource:
                if method == "GET":
                    return 200, {"device": public_device(device)}
                if method == "PUT":
                    updated = _set_or_replace_device(cfg, identifier, body)
                    return 200, {"device": public_device(updated)}
                if method == "DELETE":
                    if body.get("confirm") is not True:
                        raise ValueError("برای حذف تنظیم دستگاه، confirm=true الزامی است.")
                    with _CONFIG_LOCK:
                        latest = load_config()
                        latest["zkteco_devices"] = [item for item in latest.get("zkteco_devices", [])
                                                      if device_identifier(item) != identifier]
                        save_config(latest)
                        cfg["zkteco_devices"] = latest["zkteco_devices"]
                    return 200, {"removed": True, "device_id": identifier}
            if resource == ["status"] and method == "GET":
                probe = probe_device(device)
                device.update({key: probe[key] for key in ("model", "serial_number", "firmware", "platform", "mac", "counts") if probe.get(key) is not None})
                return 200, {"device": public_device(device), "probe": probe}
            if resource == ["users", "sync"] and method == "POST":
                return 200, sync_device_users(ApiClient(cfg), device)
            if resource == ["users"]:
                if method == "GET":
                    with device_session(device) as conn:
                        users = [_public_user(user) for user in (conn.get_users() or [])]
                    return 200, {"items": users, "total": len(users)}
                if method == "POST":
                    return 201, {"user": upsert_device_user(device, body)}
            if len(resource) >= 2 and resource[0] == "users":
                uid = _as_ascii_uint(resource[1], "UID دستگاه", 1, 65535)
                if len(resource) == 2:
                    if method == "PUT":
                        return 200, {"user": upsert_device_user(device, body, uid)}
                    if method == "DELETE":
                        return 200, delete_device_user(device, uid, body.get("confirm") is True)
                if resource[2:] == ["fingerprints"] and method == "GET":
                    return 200, list_user_fingerprints(device, uid)
                if resource[2:] == ["fingerprints", "enroll"] and method == "POST":
                    finger_id = _as_ascii_uint(body.get("finger_id", 0), "شمارهٔ انگشت", 0, 9)
                    return 200, enroll_device_fingerprint(device, uid, finger_id)
                if len(resource) == 4 and resource[2] == "fingerprints" and method == "DELETE":
                    finger_id = _as_ascii_uint(resource[3], "شمارهٔ انگشت", 0, 9)
                    return 200, delete_device_fingerprint(device, uid, finger_id, body.get("confirm") is True)
            if resource == ["time", "sync"] and method == "POST":
                return 200, sync_device_time(ApiClient(cfg), cfg, device)
            if resource == ["attendance", "sync"] and method == "POST":
                result = sync_device(ApiClient(cfg), cfg, device)
                return 200, {"synced": True, **result}
            if resource == ["attendance"] and method == "DELETE":
                if body.get("confirm") is not True:
                    raise ValueError("برای پاک‌کردن گزارش‌ها، confirm=true الزامی است.")
                if body.get("sync_before_clear") is not True:
                    raise ValueError("پاک‌کردن فقط پس از همگام‌سازی موفق مجاز است؛ sync_before_clear=true الزامی است.")
                result = clear_device_attendance(ApiClient(cfg), cfg, device)
                return 200, result
            raise BridgeAPIError(404, "عملیات یا مسیر دستگاه پیدا نشد.", "resource_not_found")

        def _dispatch(self, method: str):
            cfg = self._authorized_config()
            if cfg is None:
                return
            try:
                path = urllib.parse.urlsplit(self.path).path
                if method == "GET" and path == LOCAL_API_PREFIX + "/events/stream":
                    self._stream_events()
                    return
                body = self._read_json() if method in ("POST", "PUT", "DELETE") else {}
                status, payload = self._route(method, path, body, cfg)
                self._send_json(status, payload)
            except BridgeAPIError as exc:
                self._send_error(exc.status, str(exc), exc.code)
            except PermissionError as exc:
                self._send_error(403, str(exc), "operation_denied")
            except KeyError as exc:
                self._send_error(404, str(exc).strip("'\""), "not_found")
            except ValueError as exc:
                self._send_error(400, str(exc), "invalid_request")
            except PrintDeliveryError as exc:
                self._send_json(502, {"error": {"code": "print_delivery_uncertain" if exc.delivery_uncertain else "print_not_sent",
                                                  "message": str(exc)[:400], "delivery_uncertain": exc.delivery_uncertain}})
            except RuntimeError as exc:
                message = str(exc)
                status = 424 if message.startswith("HTTP 403:") else 502
                if str(locals().get("path", "")).endswith("/print"):
                    self._send_json(status, {"error": {"code": "print_not_sent", "message": message[:400], "delivery_uncertain": False}})
                else:
                    self._send_error(status, message, "device_or_site_operation_failed")
            except _ZK_ERRORS as exc:
                # A terminal being offline, refusing the protocol/password, or timing out is an
                # operational device failure, not an internal bridge crash. Preserve the cause.
                self._send_error(502, ("دستگاه پاسخ معتبر نداد: " + str(exc))[:400], "device_unreachable")
            except Exception:
                self._send_error(500, "خطای داخلی پل رخ داد؛ گزارش محلی را بررسی کنید.", "internal_error")

        def do_OPTIONS(self):
            self._handle_options()

        def do_GET(self):
            self._dispatch("GET")

        def do_POST(self):
            if urllib.parse.urlsplit(self.path).path == LOCAL_API_PREFIX + "/pair":
                self._handle_pair()
                return
            self._dispatch("POST")

        def do_PUT(self):
            self._dispatch("PUT")

        def do_DELETE(self):
            self._dispatch("DELETE")

    server = ThreadingHTTPServer(("127.0.0.1", int(port)), LocalBridgeHandler)
    server.daemon_threads = True
    server._wsm_sse_stop = threading.Event()
    return server


def local_api_server_thread(port: int = DEFAULT_LOCAL_API_PORT):
    server = make_local_api_server(port)
    thread = threading.Thread(target=server.serve_forever, name="WSM-Local-API", daemon=True)
    thread.start()
    return server


def run(args):
    api_port = int(getattr(args, "api_port", DEFAULT_LOCAL_API_PORT) or DEFAULT_LOCAL_API_PORT)
    server = local_api_server_thread(api_port)
    print("پل WP Store Manager فعال است. برای توقف Ctrl+C را بزنید.")
    print("Local API روی http://127.0.0.1:" + str(api_port) + LOCAL_API_PREFIX + " فقط برای رایانهٔ محلی آماده است.")
    print("این پل تا جفت‌سازی مدیرمحور در WordPress بدون فایل پیکربندی آمادهٔ اتصال می‌ماند.")
    next_access = 0
    next_users = 0
    next_device_time_sync = {}
    waiting_message_shown = False
    try:
        while True:
            try:
                try:
                    cfg = load_config()
                except RuntimeError:
                    if not waiting_message_shown:
                        print("در انتظار جفت‌سازی از پنل اتصال دستگاه در اپ WordPress Store Manager…")
                        waiting_message_shown = True
                    _PAIR_EVENT.wait(timeout=POLL_SECONDS)
                    _PAIR_EVENT.clear()
                    continue
                waiting_message_shown = False
                ensure_local_api_key(cfg)
                client = ApiClient(cfg)
                info = client.me()
                if "club.bridge" not in info.get("device_permissions", []):
                    print("مجوز club.bridge در WordPress فعال نیست؛ پل پس از تأیید مدیر خودکار ادامه می‌دهد.")
                    _PAIR_EVENT.wait(timeout=POLL_SECONDS)
                    _PAIR_EVENT.clear()
                    continue
                devices = cfg.get("zkteco_devices", [])
                now = time.time()
                for device in devices:
                    time_sync_key = device_identifier(device)
                    if now < next_device_time_sync.get(time_sync_key, 0):
                        continue
                    try:
                        sync_device_time(client, cfg, device)
                        next_device_time_sync[time_sync_key] = time.time() + 21600
                    except Exception as exc:
                        next_device_time_sync[time_sync_key] = time.time() + 300
                        print("همگام‌سازی ساعت دستگاه ناموفق: " + str(exc), file=sys.stderr)
                for device in devices:
                    try:
                        sync_device(client, cfg, device, allow_gate=True)
                    except Exception as exc:
                        print("خطای دستگاه " + device.get("name", device.get("host", "?")) + ": " + str(exc), file=sys.stderr)
                if now >= next_access:
                    try:
                        refresh_access_snapshot(client)
                    except Exception as exc:
                        print("همگام‌سازی فهرست عضویت ناموفق: " + str(exc), file=sys.stderr)
                    next_access = time.time() + 300
                if devices and now >= next_users:
                    for device in devices:
                        try:
                            result = sync_device_users(client, device)
                            if result.get("synced") or result.get("failed"):
                                print("همگام‌سازی کاربران " + device.get("name", device.get("host", "?")) +
                                      ": " + str(result.get("synced", 0)) + " به‌روزرسانی")
                        except Exception as exc:
                            print("همگام‌سازی کاربران دستگاه ناموفق: " + str(exc), file=sys.stderr)
                    next_users = time.time() + 1800
                gate_armed = any(item.get("type") in ("modbus_gate", "serial_gate") and item.get("auto_open_on_allowed")
                                 for item in cfg.get("hardware_devices", []))
                _PAIR_EVENT.wait(timeout=1 if gate_armed else POLL_SECONDS)
                _PAIR_EVENT.clear()
            except KeyboardInterrupt:
                print("\nپل متوقف شد.")
                break
            except Exception as exc:
                print("خطای عمومی پل: " + str(exc), file=sys.stderr)
                _PAIR_EVENT.wait(timeout=POLL_SECONDS)
                _PAIR_EVENT.clear()
    finally:
        server._wsm_sse_stop.set()
        try:
            server.shutdown()
        finally:
            server.server_close()


def main():
    parser = argparse.ArgumentParser(description="پل محلی امن WP Store Manager")
    sub = parser.add_subparsers(dest="command", required=True)
    p_setup = sub.add_parser("setup", help="اتصال امن به API وردپرس")
    p_setup.add_argument("--stdin-json", action="store_true", help=argparse.SUPPRESS)
    p_setup.set_defaults(func=setup)
    p_test_store = sub.add_parser("test-store", help="آزمون اتصال ذخیره‌شده به WordPress")
    p_test_store.set_defaults(func=test_store)
    p_local_info = sub.add_parser("local-api-info", help="نمایش endpoint و کلید API محلی برای فرانت‌اند مجاز")
    p_local_info.add_argument("--port", type=int, default=DEFAULT_LOCAL_API_PORT)
    p_local_info.set_defaults(func=local_api_info)
    p_test_zk = sub.add_parser("test-zk", help="آزمون واقعی اتصال LAN و خواندن گزارش تردد ZKTeco")
    p_test_zk.add_argument("--host")
    p_test_zk.add_argument("--port")
    p_test_zk.add_argument("--name")
    p_test_zk.add_argument("--direction", choices=("in", "out"))
    p_test_zk.add_argument("--transport", choices=("tcp", "udp"), default="tcp")
    p_test_zk.add_argument("--stdin-json", action="store_true", help=argparse.SUPPRESS)
    p_test_zk.set_defaults(func=test_zk)
    p_device = sub.add_parser("add-zk", help="افزودن دستگاه حضور و غیاب ZKTeco در LAN")
    p_device.add_argument("--host")
    p_device.add_argument("--port")
    p_device.add_argument("--name")
    p_device.add_argument("--direction", choices=("in", "out"))
    p_device.add_argument("--transport", choices=("tcp", "udp"), default="tcp")
    p_device.add_argument("--stdin-json", action="store_true", help=argparse.SUPPRESS)
    p_device.set_defaults(func=add_zk)
    p_run = sub.add_parser("run", help="شروع همگام‌سازی تردد و API محلی")
    p_run.add_argument("--api-port", type=int, default=DEFAULT_LOCAL_API_PORT)
    p_run.set_defaults(func=run)
    args = parser.parse_args()
    try:
        args.func(args)
    except Exception as exc:
        print("خطا: " + str(exc), file=sys.stderr)
        raise SystemExit(1)


if __name__ == "__main__":
    main()
