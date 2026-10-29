#!/usr/bin/env python3
"""Inspect Metabase permission and field metadata without printing data values."""

import json

from metabase_api import MetabaseAPI

api = MetabaseAPI()
for path in ("/api/permissions/group", "/api/permissions/graph"):
    print(path + "=" + json.dumps(api.get(path), ensure_ascii=False, sort_keys=True))

for key in ("enable-public-sharing", "enable-embedding"):
    try:
        print(key + "=" + json.dumps(api.get("/api/setting/" + key), sort_keys=True))
    except RuntimeError as exc:
        print(key + "=UNAVAILABLE")

metadata = api.get("/api/database/2/metadata")
for table in metadata.get("tables", []):
    if table.get("name") in ("users", "api_tokens", "otp_codes", "store_api_keys", "user_addresses"):
        fields = [(field.get("id"), field.get("name"), field.get("visibility_type")) for field in table.get("fields", [])]
        print("FIELDS_" + table["name"] + "=" + json.dumps(fields, ensure_ascii=False))
