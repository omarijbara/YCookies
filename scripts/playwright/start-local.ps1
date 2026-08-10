$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$dbPath = Join-Path $root 'database\playwright.sqlite'

if (-not (Test-Path $dbPath)) {
    New-Item -ItemType File -Path $dbPath -Force | Out-Null
}

$env:APP_ENV = 'local'
$env:APP_URL = 'http://127.0.0.1:8000'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $dbPath
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'array'
$env:PULSE_ENABLED = 'false'
$env:TELESCOPE_ENABLED = 'false'
$env:NIGHTWATCH_ENABLED = 'false'
$env:PROXY_SHARED_SECRET = 'playwright-proxy-secret'

Set-Location $root

php artisan config:clear | Out-Null
php artisan migrate:fresh --force
php artisan db:seed --class=Database\\Seeders\\E2ETestSeeder --force
php artisan serve --host=127.0.0.1 --port=8000
