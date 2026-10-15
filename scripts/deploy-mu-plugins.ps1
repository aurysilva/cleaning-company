# Builds the CC Admin UI CSS and uploads the must-use plugin to the Hostinger site over SSH.
# Usage: .\scripts\deploy-mu-plugins.ps1            (build + lint + deploy)
#        .\scripts\deploy-mu-plugins.ps1 -BuildOnly (just compile the SCSS)
# Requires the "hostinger-ews" host entry in ~/.ssh/config.
#
# Only cc-admin-ui.php and cc-admin-ui/ are touched on the server. Other mu-plugins (Hostinger's, temporary
# integration helpers) are listed for reference and left alone. PHP is linted in a staging folder outside
# public_html before anything goes live, and the folder is swapped in one move.

param([switch]$BuildOnly)

$ErrorActionPreference = 'Stop'

$SshHost = 'hostinger-ews'
$DomainRel = 'domains/deeppink-woodcock-284086.hostingersite.com'
$Domain  = "~/$DomainRel"
$MuDir   = "$Domain/public_html/wp-content/mu-plugins"
$Stage   = "$Domain/cc-staging-ui/deploy"
$Name    = 'cc-admin-ui'
$Tarball = "$Domain/cc-staging-ui/$Name.tar.gz"
$Local   = Join-Path $PSScriptRoot '..\wp-content\mu-plugins'
$Scss    = Join-Path $Local "$Name\assets\scss"
$Css     = Join-Path $Local "$Name\assets\css"

# Compile with the Sass already installed for the theme (read-only use); fall back to npx.
$ThemeSass = Join-Path $PSScriptRoot '..\wp-content\themes\bricks-child\node_modules\sass\sass.js'
foreach ($entry in 'admin-shell', 'admin-ui', 'login') {
	$sassArgs = @("$Scss\$entry.scss", "$Css\$entry.css", '--style=compressed', '--no-source-map')
	if (Test-Path $ThemeSass) { node $ThemeSass @sassArgs } else { npx --yes sass@1 @sassArgs }
	if ($LASTEXITCODE -ne 0) { throw "Sass failed for $entry" }
}
Write-Host 'Built CSS'
if ($BuildOnly) { return }

$archive = Join-Path $env:TEMP "$Name.tar.gz"
tar -czf $archive -C $Local --exclude "$Name/assets/scss" --exclude '*.map' "$Name.php" $Name

# Everything stays inside the site's own folder; the account hosts other live sites.
ssh $SshHost "mkdir -p $Domain/cc-staging-ui"
scp -q $archive "${SshHost}:$DomainRel/cc-staging-ui/$Name.tar.gz"
if ($LASTEXITCODE -ne 0) { throw 'Upload failed' }
$remote = @"
set -e
echo 'mu-plugins on the server before deploy:'
ls -la $MuDir
rm -rf $Stage && mkdir -p $Stage
tar -xzf $Tarball -C $Stage && rm $Tarball
find $Stage -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
cd $MuDir
rm -rf .$Name-old
if [ -d $Name ]; then mv $Name .$Name-old; fi
mv $Stage/$Name $Name
mv $Stage/$Name.php $Name.php
rm -rf .$Name-old $Stage
rmdir $Domain/cc-staging-ui 2>/dev/null || true
echo 'Deployed files:'
ls -la $MuDir/$Name.php $MuDir/$Name
exit 0
"@
# PowerShell appends CRLF when piping to a native command; the trailing LF keeps that stray CR on its own
# line, after the exit, where bash never reaches it.
(($remote -replace "`r", '') + "`n") | ssh $SshHost 'bash -s'
if ($LASTEXITCODE -ne 0) { throw 'Remote deploy failed (nothing was swapped if PHP lint failed)' }

Remove-Item $archive
Write-Host "Deployed $Name"
