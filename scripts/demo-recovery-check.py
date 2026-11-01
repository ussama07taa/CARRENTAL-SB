#!/usr/bin/env python3
"""Local demo only: consistent backup and restore drill in a private, socket-only PG cluster.
Never restores over the source database. Run as the WSL project owner.
The private backup contains .env secrets: keep it local, never commit or publish it.
"""
from pathlib import Path
import os,subprocess,hashlib,json,tarfile,shutil,datetime,sys,tempfile
ROOT=Path(__file__).resolve().parents[1]
API=ROOT/'.local/pickup-sandbox/api'
CFG={}
for line in (API/'.env').read_text().splitlines():
 if '=' in line and not line.startswith('#'):
  k,v=line.split('=',1);CFG[k]=v.strip().strip('"').strip("'")
assert CFG['DB_DATABASE']=='bensebih_pickup_sandbox_20261019','Demo only'
os.umask(0o077)
import fcntl
(ROOT/'.local/recovery-drills').mkdir(parents=True,exist_ok=True)
backup_lock=open(ROOT/'.local/recovery-drills/backup.lock','a')
try: fcntl.flock(backup_lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError: raise RuntimeError('A backup is already running')
print('BACKUP_STAGE copying',flush=True)
STAMP=datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
OUT=ROOT/'.local/recovery-drills'/STAMP;OUT.mkdir(parents=True,mode=0o700)
PG=Path('/usr/lib/postgresql/16/bin')
ENV=dict(os.environ,PGPASSWORD=CFG['DB_PASSWORD'],PGCONNECT_TIMEOUT='10')
SOURCE=[str(PG/'psql'),'-X','-h',CFG['DB_HOST'],'-p',CFG.get('DB_PORT','5432'),'-U',CFG['DB_USERNAME'],'-d',CFG['DB_DATABASE'],'-At','-v','ON_ERROR_STOP=1']
def run(args,env=ENV,**kwargs):
 p=subprocess.run(args,env=env,capture_output=True,**kwargs)
 if p.returncode:
  (OUT/'command-error.private').write_bytes(p.stderr.encode() if isinstance(p.stderr,str) else p.stderr)
  raise RuntimeError('Command failed: '+Path(args[0]).name+' (details in private error log)')
 return p.stdout

def sql(command,base=SOURCE,env=ENV):return run(base+['-c',command],env=env,text=True).strip()
def quote(s):return '"'+s.replace('"','""')+'"'
def files(root):
 result={}
 for p in sorted(root.rglob('*')):
  if p.is_symlink():raise RuntimeError('Symlink requires explicit backup policy: '+str(p.relative_to(root)))
  if p.is_file():result[str(p.relative_to(root))]={'size':p.stat().st_size,'sha256':hashlib.sha256(p.read_bytes()).hexdigest()}
 return result
TABLES=sql("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename").splitlines()
def table_manifest(base=SOURCE,env=ENV):
 result={}
 for name in TABLES:
  data=run(base+['-c','COPY (SELECT row_to_json(t)::text FROM public.'+quote(name)+' t ORDER BY row_to_json(t)::text) TO STDOUT'],env=env)
  result[name]={'rows':int(sql('SELECT count(*) FROM public.'+quote(name),base,env)),'sha256':hashlib.sha256(data).hexdigest()}
 return result
lock=subprocess.Popen(SOURCE,env=ENV,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,bufsize=1)
try:
 lock.stdin.write('BEGIN; SET lock_timeout=\'10s\'; LOCK TABLE '+','.join('public.'+quote(t) for t in TABLES)+' IN SHARE MODE; SELECT \'BACKUP_LOCKED\';\n');lock.stdin.flush()
 while True:
  line=lock.stdout.readline()
  if not line:raise RuntimeError('Could not obtain consistent backup lock')
  if 'BACKUP_LOCKED' in line:break
 before=files(API/'storage/app')
 tables=table_manifest()
 run([str(PG/'pg_dump'),'-h',CFG['DB_HOST'],'-p',CFG.get('DB_PORT','5432'),'-U',CFG['DB_USERNAME'],'-Fc','--no-owner','--no-acl','-f',str(OUT/'database.dump'),CFG['DB_DATABASE']])
 with tarfile.open(OUT/'documents.tar.gz','w:gz') as archive:archive.add(API/'storage/app',arcname='storage/app')
 shutil.copy2(API/'.env',OUT/'environment.private')
 # Keep the matching application sources; dependencies can be reinstalled from lockfiles.
 def source_filter(info):
  if set(Path(info.name).parts)&{'vendor','node_modules','.next','.git','storage','.env'}:return None
  return info
 with tarfile.open(OUT/'application.tar.gz','w:gz') as archive:
  archive.add(API,arcname='api',filter=source_filter)
  archive.add(API.parent/'dashboard',arcname='dashboard',filter=source_filter)
 if before!=files(API/'storage/app'):raise RuntimeError('Files changed during snapshot: retry backup')
 (OUT/'manifest.json').write_text(json.dumps({'source_database':CFG['DB_DATABASE'],'created_utc':STAMP,'tables':tables,'documents':before},indent=2))
finally:
 if lock.poll() is None:
  lock.stdin.write('ROLLBACK;\n');lock.stdin.flush();lock.stdin.close();lock.wait(timeout=15)
(OUT/'archive-checksums.json').write_text(json.dumps({p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in OUT.iterdir() if p.is_file()},indent=2))
print('BACKUP_STAGE verifying',flush=True)
# No TCP listener; only this owner can access the private Unix socket.
cluster=OUT/'postgres';sock=Path(tempfile.mkdtemp(prefix='bs-restore-'))
run([str(PG/'initdb'),'-D',str(cluster),'--auth=trust','--no-locale','--encoding=UTF8'],env=os.environ)
started=False
try:
 run([str(PG/'pg_ctl'),'-D',str(cluster),'-l',str(OUT/'postgres.log'),'-o',"-k "+str(sock)+" -p 55439 -c listen_addresses=''",'-w','start'],env=os.environ);started=True
 target=[str(PG/'psql'),'-X','-h',str(sock),'-p','55439','-d','postgres','-At','-v','ON_ERROR_STOP=1']
 sql('CREATE DATABASE '+quote(CFG['DB_DATABASE']),target,os.environ)
 target[target.index('-d')+1]=CFG['DB_DATABASE']
 run([str(PG/'pg_restore'),'-h',str(sock),'-p','55439','-d',CFG['DB_DATABASE'],'--no-owner','--no-acl','--exit-on-error',str(OUT/'database.dump')],env=os.environ)
 restored=OUT/'restored';restored.mkdir()
 with tarfile.open(OUT/'documents.tar.gz') as archive:archive.extractall(restored,filter='data')
 with tarfile.open(OUT/'application.tar.gz') as archive:archive.extractall(restored/'application',filter='data')
 actual=table_manifest(target,os.environ);assert actual==tables,'Restored rows differ'
 actual_files=files(restored/'storage/app');assert actual_files==before,'Restored document bytes differ'
 # Explicitly prove constraints and indexes are restored, not only table rows.
 for query in ["SELECT conrelid::regclass,conname,contype,convalidated,condeferrable,condeferred,conkey,confrelid::regclass,confkey FROM pg_constraint WHERE connamespace='public'::regnamespace ORDER BY conrelid::regclass::text,conname","SELECT t.relname,i.relname,x.indisunique,x.indisvalid,x.indisprimary,x.indisexclusion,x.indkey,a.amname,(x.indpred IS NOT NULL),(x.indexprs IS NOT NULL) FROM pg_index x JOIN pg_class t ON t.oid=x.indrelid JOIN pg_class i ON i.oid=x.indexrelid JOIN pg_am a ON a.oid=i.relam WHERE t.relnamespace='public'::regnamespace ORDER BY t.relname,i.relname"]:
  expected=sql(query); recovered=sql(query,target,os.environ)
  if expected!=recovered:
   import difflib
   (OUT/'schema-diff.txt').write_text('\n'.join(difflib.unified_diff(expected.splitlines(),recovered.splitlines())))
   raise RuntimeError('Schema integrity differs: '+str(OUT/'schema-diff.txt'))
 result={'verified':True,'tables':len(tables),'rows':sum(x['rows'] for x in tables.values()),'files':len(before),'document_bytes':sum(x['size'] for x in before.values()),'restored_database':CFG['DB_DATABASE'],'isolated_socket':str(sock),'source_untouched':True,'backup_directory':str(OUT)}
 (OUT/'verification.json').write_text(json.dumps(result,indent=2));print(json.dumps(result,indent=2),flush=True)
finally:
 if started:run([str(PG/'pg_ctl'),'-D',str(cluster),'-m','fast','-w','stop'],env=os.environ)
