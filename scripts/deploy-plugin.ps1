# Uploads the CC Bookings plugin to the Hostinger site over SSH.
# Usage: .\scripts\deploy-plugin.ps1
# Requires the "hostinger-ews" host entry in ~/.ssh/config. Activate once with: wp plugin activate cc-bookings

$ErrorActionPreference = 'Stop'

$SshHost  = 'hostinger-ews'
$SiteRoot = '~/domains/deeppink-woodcock-284086.hostingersite.com/public_html'
$Plugin   = 'cc-bookings'
$Local    = Join-Path $PSScriptRoot "..\wp-content\plugins"

$archive = Join-Path $env:TEMP "$Plugin.tar.gz"
tar -czf $archive -C $Local --exclude "*.map" $Plugin

scp -q $archive "${SshHost}:~/$Plugin.tar.gz"
# Extract to a staging folder and swap, so a half-uploaded plugin is never live.
ssh $SshHost "set -e; cd $SiteRoot/wp-content/plugins && rm -rf .$Plugin-new && mkdir .$Plugin-new && tar -xzf ~/$Plugin.tar.gz -C .$Plugin-new && rm ~/$Plugin.tar.gz && rm -rf .$Plugin-old && (if [ -d $Plugin ]; then mv $Plugin .$Plugin-old; fi) && mv .$Plugin-new/$Plugin $Plugin && rm -rf .$Plugin-new .$Plugin-old && cd $SiteRoot && wp cache flush --quiet && (wp litespeed-purge all --quiet 2>/dev/null || true)"

Remove-Item $archive
Write-Host "Deployed $Plugin"
