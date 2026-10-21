$ErrorActionPreference = 'Stop'
& wsl -d Ubuntu -u root --exec systemctl start postgresql redis-server
if ($LASTEXITCODE -ne 0) { throw 'Unable to start database services.' }
& wsl -d Ubuntu --cd /home/taaouati/projects/bensebih-car-rental --exec env BENSEBIH_DEMO=1 bash scripts/dev.sh
exit $LASTEXITCODE
