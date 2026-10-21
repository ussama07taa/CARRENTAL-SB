$ErrorActionPreference = 'Stop'
Write-Output 'Development tools in Ubuntu (separate from Windows Laragon):'
& wsl -d Ubuntu -- php --version
& wsl -d Ubuntu -- /opt/bensebih-toolchain/node/bin/node --version
& wsl -d Ubuntu -- env PATH=/opt/bensebih-toolchain/node/bin:/usr/bin:/bin pnpm --version
& wsl -d Ubuntu -- php /opt/bensebih-toolchain/composer.phar --version
& wsl -d Ubuntu -- pg_isready -h 127.0.0.1 -p 5432
& wsl -d Ubuntu -- redis-cli ping
