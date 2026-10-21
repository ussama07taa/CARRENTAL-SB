"""Create isolated local databases. Run as root in Ubuntu once."""
from pathlib import Path
import secrets
import subprocess

root = Path(__file__).resolve().parents[1]
api = root / 'apps/api'

def sql(statement, database='postgres'):
    return subprocess.run(['runuser', '-u', 'postgres', '--', 'psql', '-X', '-v', 'ON_ERROR_STOP=1', '-At', '-d', database], input=statement, text=True, capture_output=True, check=True).stdout.strip()

subprocess.run(['systemctl', 'start', 'postgresql', 'redis-server'], check=True)
for name, filename in [('bensebih_local', '.env'), ('bensebih_test', '.env.testing')]:
    target = api / filename
    if target.exists():
        print(f'{filename} exists; retained without modification.')
        continue
    if sql(f"SELECT 1 FROM pg_database WHERE datname='{name}'") or sql(f"SELECT 1 FROM pg_roles WHERE rolname='{name}'"):
        raise SystemExit(f'{name} already exists without configuration; reconcile before proceeding.')
    password = secrets.token_hex(24)
    sql(f"CREATE ROLE {name} LOGIN PASSWORD '{password}' NOSUPERUSER NOCREATEDB NOCREATEROLE;")
    sql(f'CREATE DATABASE {name} OWNER {name};')
    sql('CREATE EXTENSION IF NOT EXISTS btree_gist;', name)
    values = (api / '.env.example').read_text().replace('DB_DATABASE=bensebih_local', f'DB_DATABASE={name}').replace('DB_USERNAME=bensebih_local', f'DB_USERNAME={name}').replace('DB_PASSWORD=', f'DB_PASSWORD={password}')
    if filename == '.env.testing':
        values = values.replace('APP_ENV=local', 'APP_ENV=testing').replace('REDIS_DB=0', 'REDIS_DB=2').replace('REDIS_CACHE_DB=1', 'REDIS_CACHE_DB=3')
    target.write_text(values)
    target.chmod(0o600)
    print(f'Created {name}; credential saved only in ignored {filename}.')
