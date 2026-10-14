# Builds the child theme CSS and uploads the theme to the Hostinger site over SSH.
# Usage: .\scripts\deploy-theme.ps1
# Requires the "hostinger-ews" host entry in ~/.ssh/config.

$ErrorActionPreference = 'Stop'

$SshHost  = 'hostinger-ews'
$SiteRoot = '~/domains/deeppink-woodcock-284086.hostingersite.com/public_html'
$Theme    = 'bricks-child'
$Local    = Join-Path $PSScriptRoot "..\wp-content\themes"

Push-Location (Join-Path $Local $Theme)
try {
	if (-not (Test-Path node_modules)) { npm install --no-audit --no-fund }
	npm run build
} finally {
	Pop-Location
}

$archive = Join-Path $env:TEMP "$Theme.tar.gz"
tar -czf $archive -C $Local --exclude "$Theme/node_modules" --exclude "*.map" $Theme

scp -q $archive "${SshHost}:~/$Theme.tar.gz"
ssh $SshHost "tar -xzf ~/$Theme.tar.gz -C $SiteRoot/wp-content/themes && rm ~/$Theme.tar.gz && cd $SiteRoot && wp cache flush --quiet && (wp litespeed-purge all --quiet 2>/dev/null || true)"

Remove-Item $archive
Write-Host "Deployed $Theme"
