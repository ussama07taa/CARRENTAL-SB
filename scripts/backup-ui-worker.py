#!/usr/bin/env python3
"""Private local-demo worker; no user supplied command or destination."""
import os,json,fcntl,subprocess,datetime,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
folder=ROOT/'.local/recovery-drills'
os.umask(0o077)
lock=open(folder/'worker.lock','a')
try: fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError: sys.exit(0)
state_path=folder/'operation.json'
state=json.loads(state_path.read_text())
def save(stage):
 state['stage']=stage;state['updated_at']=datetime.datetime.now(datetime.timezone.utc).isoformat()
 temp=folder/'operation.tmp';temp.write_text(json.dumps(state));temp.replace(state_path)
try:
 save('copying')
 with open(folder/'worker.private.log','a') as log:
  process=subprocess.Popen(['/usr/bin/python3',str(ROOT/'scripts/demo-recovery-check.py')],stdout=subprocess.PIPE,stderr=log,text=True)
  for line in process.stdout:
   log.write(line);log.flush()
   if line.strip()=='BACKUP_STAGE verifying':save('verifying')
  save('completed' if process.wait()==0 else 'failed')
except Exception:
 save('failed')
