#!/usr/bin/env python3
"""Restrict default users and hide production PII in Metabase metadata."""

from metabase_api import MetabaseAPI

api = MetabaseAPI()
graph = api.get('/api/permissions/graph')
all_users = graph['groups']['1']
for db_id in ('2',):
    permissions = all_users.get(db_id, {})
    permissions['create-queries'] = 'no'
    all_users[db_id] = permissions
api.put('/api/permissions/graph', {'groups': graph['groups'], 'revision': graph['revision']})

private_tables = {
    'api_tokens', 'otp_codes', 'store_api_keys', 'user_addresses',
    'password_resets', 'personal_access_tokens', 'sessions',
}
private_names = {
    'password_hash', 'password', 'national_id', 'id_card_image',
    'birth_cert_image', 'selfie_image', 'bank_account', 'google_id',
    'phone', 'email', 'store_login', 'store_phone', 'api_key', 'token',
    'secret', 'access_token', 'refresh_token', 'otp', 'code',
}
changed_fields = 0
changed_tables = 0
metadata = api.get('/api/database/2/metadata')
for table in metadata.get('tables', []):
    if table['name'] in private_tables:
        api.put(f"/api/table/{table['id']}", {'visibility_type': 'hidden'})
        changed_tables += 1
    for field in table.get('fields', []):
        name = field['name'].lower()
        if name in private_names or any(name.endswith('_' + suffix) for suffix in ('token', 'secret', 'password')):
            api.put(f"/api/field/{field['id']}", {'visibility_type': 'sensitive', 'data_sensitivity': 'PII'})
            changed_fields += 1

for key in ('enable-public-sharing', 'enable-embedding'):
    api.put('/api/setting/' + key, {'value': False})

checked = api.get('/api/permissions/graph')['groups']['1']['2']
assert checked.get('create-queries', 'no') == 'no'
print(f"SECURITY_HARDENING=PASS fields={changed_fields} tables={changed_tables}")
