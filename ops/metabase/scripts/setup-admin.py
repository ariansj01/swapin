#!/usr/bin/env python3
"""Complete Metabase's local-only first-run setup without printing secrets."""

import json
import os
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE = "http://127.0.0.1:3000"
EMAIL = "darazedr@gmail.com"
SECRET_DIR = Path("/opt/swaapin-metabase/secrets")


def api(method: str, path: str, body=None, session=None):
    headers = {"Content-Type": "application/json"}
    if session:
        headers["X-Metabase-Session"] = session
    data = None if body is None else json.dumps(body).encode("utf-8")
    request = Request(BASE + path, data=data, headers=headers, method=method)
    try:
        with urlopen(request, timeout=30) as response:
            return json.load(response)
    except HTTPError as exc:
        raise RuntimeError(f"Metabase API {path} returned HTTP {exc.code}") from None


password = (SECRET_DIR / "metabase_admin_password").read_text().strip()
assert len(password) >= 32
props = api("GET", "/api/session/properties")
token = props.get("setup-token")

if token:
    result = api(
        "POST",
        "/api/setup",
        {
            "token": token,
            "user": {
                "first_name": "Swaapin",
                "last_name": "Admin",
                "email": EMAIL,
                "password": password,
            },
            "prefs": {"site_name": "Swaapin Analytics", "allow_tracking": False},
        },
    )
else:
    result = api("POST", "/api/session", {"username": EMAIL, "password": password})

session = result.get("id")
if not session:
    raise RuntimeError("Metabase did not return an administrator session")

current_user = api("GET", "/api/user/current", session=session)
if current_user.get("email") != EMAIL or not current_user.get("is_superuser"):
    raise RuntimeError("Administrator identity verification failed")

session_path = SECRET_DIR / "metabase_admin_session"
fd = os.open(session_path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
with os.fdopen(fd, "w", encoding="utf-8") as stream:
    stream.write(session + "\n")
os.chmod(session_path, 0o600)

print("ADMIN_SETUP=PASS")
print("ADMIN_EMAIL=" + EMAIL)
print("SETUP_TOKEN_CLEARED=" + str(api("GET", "/api/session/properties").get("setup-token") is None).upper())
