#!/usr/bin/env python3
"""Print only relevant Metabase API request schemas."""

import json

from metabase_api import MetabaseAPI

api = MetabaseAPI()
spec = api.get('/api/docs/openapi.json')
for path in ('/api/permissions/graph', '/api/field/{id}', '/api/table/{id}', '/api/dashboard', '/api/card'):
    entry = spec.get('paths', {}).get(path, {})
    for method, definition in entry.items():
        if method.lower() in ('put', 'post'):
            print(path, method, json.dumps(definition.get('requestBody', {}), sort_keys=True)[:4000])
print('SCHEMAS', ','.join(name for name in spec.get('components', {}).get('schemas', {}) if 'Permission' in name or 'Field' in name))
for name in ('metabase.permissions-rest.schema.graph-update-request', 'metabase.permissions-rest.schema.db-perms', 'metabase.permissions-rest.schema.schemas', 'metabase.permissions-rest.schema.strict-data-perms'):
    print(name, json.dumps(spec.get('components', {}).get('schemas', {}).get(name), sort_keys=True)[:7000])
for path in ('/api/dashboard/{id}', '/api/dashboard/{id}/cards', '/api/collection'):
    entry = spec.get('paths', {}).get(path, {})
    for method, definition in entry.items():
        if method.lower() in ('put', 'post'):
            print(path, method, json.dumps(definition.get('requestBody', {}), sort_keys=True)[:4000])
