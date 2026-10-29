"""Local-only Metabase API helper. Never logs credentials or response bodies."""

import json
import os
from pathlib import Path
from urllib.error import HTTPError
from urllib.request import Request, urlopen

BASE = "http://127.0.0.1:3000"
SECRETS = Path("/opt/swaapin-metabase/secrets")
ADMIN_EMAIL = "darazedr@gmail.com"


class MetabaseAPI:
    def __init__(self):
        self.session_file = SECRETS / "metabase_admin_session"
        self.session = self.session_file.read_text().strip() if self.session_file.exists() else ""
        if not self._session_works():
            self._login()

    def _call(self, method, path, payload=None, authenticated=True):
        headers = {"Content-Type": "application/json"}
        if authenticated and self.session:
            headers["X-Metabase-Session"] = self.session
        data = None if payload is None else json.dumps(payload).encode("utf-8")
        request = Request(BASE + path, data=data, headers=headers, method=method)
        try:
            with urlopen(request, timeout=60) as response:
                body = response.read()
                return json.loads(body) if body else None
        except HTTPError as exc:
            raise RuntimeError(f"Metabase API {method} {path} returned HTTP {exc.code}") from None

    def _session_works(self):
        if not self.session:
            return False
        try:
            user = self._call("GET", "/api/user/current")
            return bool(user.get("is_superuser") and user.get("email") == ADMIN_EMAIL)
        except RuntimeError:
            return False

    def _login(self):
        password = (SECRETS / "metabase_admin_password").read_text().strip()
        result = self._call(
            "POST", "/api/session",
            {"username": ADMIN_EMAIL, "password": password},
            authenticated=False,
        )
        self.session = result["id"]
        fd = os.open(self.session_file, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            stream.write(self.session + "\n")
        os.chmod(self.session_file, 0o600)

    def get(self, path):
        return self._call("GET", path)

    def post(self, path, payload=None):
        return self._call("POST", path, payload)

    def put(self, path, payload):
        return self._call("PUT", path, payload)

    def delete(self, path):
        return self._call("DELETE", path)
