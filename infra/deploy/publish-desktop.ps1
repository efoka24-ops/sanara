<#
.SYNOPSIS
  Publie l'installateur du poste Sanara dans /desktop-releases/ du serveur (FTP).

.DESCRIPTION
  Envoie l'installateur et son .blockmap, puis latest.yml EN DERNIER : c'est lui qui annonce la
  version, à la page de téléchargement du site comme aux postes déjà installés (T128).
  Construire d'abord : corepack pnpm --filter @sanara/desktop dist
  Identifiants lus dans .env (jamais affichés).
#>
param([string]$EnvFile = (Join-Path $PSScriptRoot '..\..\.env'))

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$cfg = @{}
Get-Content -LiteralPath $EnvFile -Encoding UTF8 | ForEach-Object {
  if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)$') { $v = $Matches[2].Trim(); if ($v -match "^'(.*)'$") { $v = $Matches[1] }; $cfg[$Matches[1]] = $v }
}
foreach ($k in 'FTP_HOST', 'FTP_PORT', 'FTP_USER', 'FTP_PASSWORD', 'APP_URL') {
  if ([string]::IsNullOrWhiteSpace($cfg[$k])) { throw "Variable manquante dans .env : $k" }
}
$release = Join-Path $root 'apps\desktop\release'
$latest = Join-Path $release 'latest.yml'
if (-not (Test-Path $latest)) { throw 'latest.yml absent : construire d''abord l''installateur.' }
$file = ((Get-Content $latest | Where-Object { $_ -match '^path:\s*(.+)$' } | Select-Object -First 1) -replace '^path:\s*', '').Trim()
$exe = Join-Path $release $file
if (-not (Test-Path $exe)) { throw "Installateur introuvable : $file" }

$ftp = "ftp://$($cfg['FTP_HOST']):$($cfg['FTP_PORT'])/desktop-releases"
$cred = "$($cfg['FTP_USER']):$($cfg['FTP_PASSWORD'])"
foreach ($f in @($exe, "$exe.blockmap", $latest)) {
  Write-Host (">> envoi de {0} ({1:N1} Mo)" -f (Split-Path $f -Leaf), ((Get-Item $f).Length / 1MB))
  curl.exe -sS --fail --ftp-create-dirs --retry 3 --user $cred -T $f "$ftp/"
  if ($LASTEXITCODE) { throw "Echec de l'envoi de $f" }
}
$base = $cfg['APP_URL'].TrimEnd('/') -replace '^https://', 'http://'
Write-Host ("   {0}/desktop-releases/latest.yml -> HTTP {1}" -f $base, (curl.exe -s -o NUL -w '%{http_code}' "$base/desktop-releases/latest.yml"))
