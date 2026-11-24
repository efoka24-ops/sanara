<#
.SYNOPSIS
  Déploie Sanara sur Camoo (FTP) : front statique à la racine, API dans /api, code PHP dans /api-core.

.DESCRIPTION
  1. Construit le front (Vite) sauf -SkipBuild.
  2. Prépare un dossier de livraison (.deploy-stage) avec les dépendances PHP de production.
  3. Zippe, envoie l'archive + un extracteur à usage unique (jeton aléatoire) par FTP.
  4. Appelle l'extracteur, qui décompresse puis se supprime lui-même.
  Les identifiants sont lus dans le fichier .env à la racine du dépôt (ignoré par git).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File infra\deploy\deploy.ps1
#>
param(
  [switch]$SkipBuild,
  [string]$EnvFile = (Join-Path $PSScriptRoot '..\..\.env')
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

# Windows PowerShell 5.1 traite la sortie stderr d'un programme externe comme une erreur :
# on exécute les commandes externes en mode "Continue" et on vérifie $LASTEXITCODE.
function Invoke-External([scriptblock]$Block, [string]$Failure) {
  $old = $ErrorActionPreference
  $ErrorActionPreference = 'Continue'
  try { & $Block | Out-Host } finally { $ErrorActionPreference = $old }
  if ($LASTEXITCODE -ne 0) { throw $Failure }
}

function Read-DotEnv([string]$path) {
  $h = @{}
  Get-Content -LiteralPath $path -Encoding UTF8 | ForEach-Object {
    if ($_ -match '^\s*([A-Z0-9_]+)\s*=\s*(.*)$') { $k = $Matches[1]; $v = $Matches[2].Trim(); if ($v -match "^'(.*)'$") { $v = $Matches[1] }; $h[$k] = $v }
  }
  return $h
}

$cfg = Read-DotEnv $EnvFile
foreach ($k in 'FTP_HOST', 'FTP_PORT', 'FTP_USER', 'FTP_PASSWORD', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'JWT_SECRET', 'APP_URL', 'STORAGE_KEY', 'CRON_KEY', 'SUPERADMIN_EMAIL', 'SUPERADMIN_PASSWORD', 'MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD') {
  if (-not $cfg.ContainsKey($k) -or [string]::IsNullOrWhiteSpace($cfg[$k])) { throw "Variable manquante dans .env : $k" }
}
$base = $cfg['APP_URL'].TrimEnd('/')
$ftp = "ftp://$($cfg['FTP_HOST']):$($cfg['FTP_PORT'])"
$cred = "$($cfg['FTP_USER']):$($cfg['FTP_PASSWORD'])"

# 1. Build du front
if (-not $SkipBuild) {
  Write-Host '>> Build du front'
  Push-Location $root
  try { Invoke-External { corepack pnpm --filter '@sanara/web' build } 'Echec du build web' } finally { Pop-Location }
}

# 2. Dossier de livraison
$stage = Join-Path $root '.deploy-stage'
if (Test-Path $stage) { Remove-Item -Recurse -Force $stage }
New-Item -ItemType Directory -Path $stage, "$stage\api", "$stage\api-core", "$stage\storage-private" | Out-Null

Copy-Item -Recurse -Force "$root\apps\web\dist\*" $stage
Copy-Item -Force "$PSScriptRoot\root.htaccess" "$stage\.htaccess"
Copy-Item -Force "$PSScriptRoot\api.htaccess" "$stage\api\.htaccess"
Copy-Item -Force "$PSScriptRoot\api.user.ini" "$stage\api\.user.ini"
Copy-Item -Force "$root\apps\api\public\index.php" "$stage\api\index.php"
Copy-Item -Force "$PSScriptRoot\deny.htaccess" "$stage\api-core\.htaccess"
Copy-Item -Force "$PSScriptRoot\deny.htaccess" "$stage\storage-private\.htaccess"
foreach ($d in 'app', 'config', 'database') { if (Test-Path "$root\apps\api\$d") { Copy-Item -Recurse -Force "$root\apps\api\$d" "$stage\api-core\$d" } }
Copy-Item -Force "$root\apps\api\composer.json", "$root\apps\api\composer.lock" "$stage\api-core\"

Write-Host '>> Dependances PHP de production'
Push-Location "$stage\api-core"
try { Invoke-External { composer install --no-dev --optimize-autoloader --no-interaction --no-progress } 'Echec composer install' } finally { Pop-Location }

$envServer = @"
APP_ENV=staging
APP_DEBUG=false
APP_URL=$base
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE='$($cfg['DB_DATABASE'] -replace "'", "\'")'
DB_USERNAME='$($cfg['DB_USERNAME'] -replace "'", "\'")'
DB_PASSWORD='$($cfg['DB_PASSWORD'] -replace "'", "\'")'
JWT_SECRET='$($cfg['JWT_SECRET'])'
STORAGE_KEY='$($cfg['STORAGE_KEY'])'
CRON_KEY='$($cfg['CRON_KEY'])'
SUPERADMIN_EMAIL='$($cfg['SUPERADMIN_EMAIL'])'
SUPERADMIN_PASSWORD='$($cfg['SUPERADMIN_PASSWORD'])'
MAIL_HOST='$($cfg['MAIL_HOST'])'
MAIL_PORT=$(if ($cfg['MAIL_PORT']) { $cfg['MAIL_PORT'] } else { 587 })
MAIL_ENCRYPTION=tls
MAIL_USERNAME='$($cfg['MAIL_USERNAME'])'
MAIL_PASSWORD='$($cfg['MAIL_PASSWORD'] -replace "'", "\x27")'
MAIL_FROM_NAME=Sanara
"@
[IO.File]::WriteAllText("$stage\api-core\.env", ($envServer -replace "`r`n", "`n"), (New-Object Text.UTF8Encoding($false)))

# 3. Archive (bsdtar produit des chemins avec /)
$zip = Join-Path $root '.deploy-release.zip'
if (Test-Path $zip) { Remove-Item -Force $zip }
Push-Location $stage
try { Invoke-External { tar -a -c -f $zip * .htaccess } 'Echec de creation du zip' } finally { Pop-Location }
Write-Host ("   archive : {0:N1} Mo" -f ((Get-Item $zip).Length / 1MB))

# 4. Extracteur a usage unique
$token = -join ((1..24) | ForEach-Object { '{0:x}' -f (Get-Random -Maximum 16) })
$name = "_deploy_$token.php"
$extractor = @"
<?php
header('Content-Type: application/json');
if (!hash_equals('$token', (string)(`$_GET['t'] ?? ''))) { http_response_code(403); exit('{"error":"forbidden"}'); }
`$root = __DIR__; `$zipPath = `$root . '/release.zip'; `$out = ['ok' => false];
function rrmdir(string `$d): void { if (!is_dir(`$d)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(`$d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as `$f) { `$f->isDir() ? @rmdir(`$f->getPathname()) : @unlink(`$f->getPathname()); } @rmdir(`$d); }
try {
  `$z = new ZipArchive();
  if (`$z->open(`$zipPath) !== true) throw new Exception('zip illisible');
  rrmdir(`$root . '/api-core/vendor'); rrmdir(`$root . '/api-core/app'); rrmdir(`$root . '/assets');
  `$ok = `$z->extractTo(`$root); `$out['files'] = `$z->numFiles; `$z->close();
  `$out['ok'] = `$ok;
  try {
    require `$root . '/api-core/vendor/autoload.php';
    Dotenv\Dotenv::createImmutable(`$root . '/api-core')->load();
    `$cfg = Sanara\Common\Config::fromEnv(`$_ENV);
    Sanara\Common\Database::configure(`$cfg);
    `$p = new Sanara\Common\Persistence\Provisioner(Sanara\Common\Database::db(), new Sanara\Common\Clock(), `$root . '/api-core/database/migrations');
    `$out['provision'] = `$p->run((string)`$_ENV['SUPERADMIN_EMAIL'], (string)`$_ENV['SUPERADMIN_PASSWORD']);
  } catch (Throwable `$e) { `$out['provision_error'] = get_class(`$e) . ': ' . `$e->getMessage(); }
} catch (Throwable `$e) { `$out['error'] = `$e->getMessage(); }
@unlink(`$zipPath); `$out['self_deleted'] = @unlink(__FILE__);
echo json_encode(`$out);
"@
$tmpPhp = Join-Path $env:TEMP $name
[IO.File]::WriteAllText($tmpPhp, ($extractor -replace "`r`n", "`n"), (New-Object Text.UTF8Encoding($false)))

Write-Host '>> Envoi FTP'
curl.exe -sS --fail --user $cred -T $zip "$ftp/release.zip"
if ($LASTEXITCODE) { throw 'Echec envoi release.zip' }
curl.exe -sS --fail --user $cred -T $tmpPhp "$ftp/$name"
if ($LASTEXITCODE) { throw 'Echec envoi extracteur' }
Remove-Item -Force $tmpPhp

Write-Host '>> Extraction sur le serveur'
$res = curl.exe -sS -m 300 "$base/$name`?t=$token"
Write-Host "   $res"
if ($res -notmatch '"ok":true') {
  # Nettoyage de securite si l'extracteur n'a pas pu se supprimer
  curl.exe -sS --user $cred -Q "DELE $name" "$ftp/" | Out-Null
  throw "Extraction echouee : $res"
}

Remove-Item -Force $zip
Remove-Item -Recurse -Force $stage
Write-Host '>> Verification'
Write-Host ("   {0}/ -> HTTP {1}" -f $base, (curl.exe -sS -o NUL -w '%{http_code}' "$base/"))
Write-Host ("   {0}/api/health -> {1}" -f $base, (curl.exe -sS "$base/api/health"))
