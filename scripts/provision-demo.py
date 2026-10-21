"""Create a NEW isolated demonstration DB, never modify the real local DB."""
from pathlib import Path
import base64
import os
import pwd
import secrets
import subprocess

root = Path(__file__).resolve().parents[1]
target = root / 'apps/api/.env.demo'
if target.exists():
    raise SystemExit('Demo environment already exists; retained unchanged.')

def sql(statement, database='postgres'):
    return subprocess.run(['runuser', '-u', 'postgres', '--', 'psql', '-X', '-v', 'ON_ERROR_STOP=1', '-At', '-d', database], input=statement, text=True, capture_output=True, check=True).stdout.strip()

name = 'bensebih_demo'
if sql(f"SELECT 1 FROM pg_database WHERE datname='{name}'") or sql(f"SELECT 1 FROM pg_roles WHERE rolname='{name}'"):
    raise SystemExit('Demo database or role already exists; reconcile manually.')
password = secrets.token_hex(24)
sql(f"CREATE ROLE {name} LOGIN PASSWORD '{password}' NOSUPERUSER NOCREATEDB NOCREATEROLE;")
sql(f'CREATE DATABASE {name} OWNER {name};')
sql('CREATE EXTENSION IF NOT EXISTS btree_gist;', name)
values = (root / 'apps/api/.env.example').read_text()
for old, new in {
    'APP_ENV=local': 'APP_ENV=demo',
    'APP_KEY=': 'APP_KEY=base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
    'DB_DATABASE=bensebih_local': f'DB_DATABASE={name}',
    'DB_USERNAME=bensebih_local': f'DB_USERNAME={name}',
    'DB_PASSWORD=': f'DB_PASSWORD={password}',
    'REDIS_DB=0': 'REDIS_DB=4', 'REDIS_CACHE_DB=1': 'REDIS_CACHE_DB=5',
}.items():
    values = values.replace(old, new)
values += '\nSESSION_COOKIE=bensebih_demo_session\nCACHE_PREFIX=bensebih_demo_cache_\nHORIZON_PREFIX=bensebih_demo_horizon:\n'
fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, 'w') as output:
    output.write(values)
owner = root.stat()
os.chown(target, owner.st_uid, owner.st_gid)
print('Created isolated demo database and private environment file.')
