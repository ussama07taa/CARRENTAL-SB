#!/usr/bin/env bash
set -euo pipefail
# Run in Ubuntu as root. Versioned tools do not modify Windows/Laragon.
node_version=v24.22.0
tool_dir=/opt/bensebih-toolchain
mkdir -p "$tool_dir"
if [[ ! -x "$tool_dir/node/bin/node" ]]; then
  stage=$(mktemp -d)
  cd "$stage"
  curl -fsSLO "https://nodejs.org/dist/$node_version/node-$node_version-linux-x64.tar.xz"
  curl -fsSLO "https://nodejs.org/dist/$node_version/SHASUMS256.txt"
  grep " node-$node_version-linux-x64.tar.xz$" SHASUMS256.txt | sha256sum --check
  mkdir -p "$tool_dir/node"
  tar -xJf "node-$node_version-linux-x64.tar.xz" -C "$tool_dir/node" --strip-components=1
fi
export PATH="$tool_dir/node/bin:$PATH"
if [[ ! -f "$tool_dir/composer.phar" ]]; then
  stage=$(mktemp -d)
  curl -fsSL https://getcomposer.org/installer -o "$stage/composer-setup.php"
  expected=$(curl -fsSL https://composer.github.io/installer.sig)
  actual=$(php -r "echo hash_file('sha384', '$stage/composer-setup.php');")
  [[ "$expected" == "$actual" ]] || { echo 'Composer installer checksum mismatch'; exit 1; }
  php "$stage/composer-setup.php" --version=2.9.7 --install-dir="$tool_dir" --filename=composer.phar
fi
npm install --global pnpm@10.34.0
printf '\nToolchain ready: '
node --version
pnpm --version
php "$tool_dir/composer.phar" --version
