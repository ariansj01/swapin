#!/usr/bin/env python3
"""Print only schema names and IDs from Metabase's synced source metadata."""

from metabase_api import MetabaseAPI

api = MetabaseAPI()
metadata = api.get("/api/database/2/metadata")
tables = metadata.get("tables", [])
print("SYNCED_TABLE_COUNT=" + str(len(tables)))
for table in sorted(tables, key=lambda item: item.get("name", "")):
    print(f"TABLE={table.get('id')}:{table.get('name')}:FIELDS={len(table.get('fields', []))}")
