$ErrorActionPreference = 'Stop'
& wsl -d Ubuntu -u root --exec systemctl start postgresql redis-server
if ($LASTEXITCODE -ne 0) { throw 'Unable to start local database services.' }
& wsl -d Ubuntu --cd /home/taaouati/projects/bensebih-car-rental --exec bash scripts/check.sh
exit $LASTEXITCODE