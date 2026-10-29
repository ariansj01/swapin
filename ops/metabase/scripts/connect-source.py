#!/usr/bin/env python3
"""Connect only the production swapin database through its read-only account."""

from pathlib import Path

from metabase_api import MetabaseAPI, SECRETS

api = MetabaseAPI()
props = api.get("/api/session/properties")
if "mysql" not in props.get("engines", {}):
    raise RuntimeError("Metabase MySQL/MariaDB driver is unavailable")

name = "Swaapin Production (read-only)"
databases = api.get("/api/database")
items = databases.get("data", databases) if isinstance(databases, dict) else databases
matches = [item for item in items if item.get("name") == name]
if len(matches) > 1:
    raise RuntimeError("Duplicate Swaapin database connections exist")

if matches:
    db_id = matches[0]["id"]
else:
    password = (SECRETS / "mariadb_ro_password").read_text().strip()
    if len(password) != 64:
        raise RuntimeError("Invalid MariaDB analytics password file")
    result = api.post(
        "/api/database",
        {
            "name": name,
            "engine": "mysql",
            "details": {
                "host": "127.0.0.1",
                "port": 3306,
                "db": "swapin",
                "user": "metabase_ro",
                "password": password,
                "ssl": False,
            },
            "is_on_demand": False,
        },
    )
    db_id = result["id"]

database = api.get(f"/api/database/{db_id}")
if database.get("name") != name or database.get("engine") != "mysql":
    raise RuntimeError("Metabase source database verification failed")

print("SOURCE_DATABASE_ID=" + str(db_id))
print("SOURCE_DATABASE_NAME=" + name)
print("SOURCE_ACCESS=READ_ONLY")
