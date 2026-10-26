# 下载 Windows 运行时二进制到 runtime/ （nginx / php / mariadb）
# 用法：在 desktop/ 目录运行  powershell -ExecutionPolicy Bypass -File fetch.ps1
$ErrorActionPreference = "Stop"

$NGINX_VER   = "1.26.2"
$PHP_VER     = "8.3.12"
$MARIADB_VER = "11.4.4"

# 国内镜像回退（按需取消注释；默认用官方源）
# $NGINX_MIRROR   = "https://mirrors.aliyun.com/nginx/"
# $PHP_MIRROR     = "https://mirrors.aliyun.com/php/"   # 该镜像未必含 windows 构建，失败时改回官方
# $MARIADB_MIRROR = "https://mirrors.aliyun.com/mariadb/"

$runtime = Join-Path $PSScriptRoot "runtime"
New-Item -ItemType Directory -Force -Path $runtime | Out-Null

function Fetch($name, $url, $topDir, $dest) {
    $tmp = Join-Path $env:TEMP "$name.zip"
    Write-Host "下载 $name : $url"
    Invoke-WebRequest -Uri $url -OutFile $tmp -UseBasicParsing
    $extract = Join-Path $env:TEMP "$name-ext"
    Remove-Item -Recurse -Force $extract -ErrorAction SilentlyContinue
    Expand-Archive -Path $tmp -DestinationPath $extract -Force
    $src = Join-Path $extract $topDir
    if (-not (Test-Path $src)) { $src = $extract }
    New-Item -ItemType Directory -Force -Path $dest | Out-Null
    Get-ChildItem $src | ForEach-Object { Move-Item $_.FullName -Destination $dest -Force }
    Remove-Item -Recurse -Force $extract
    Remove-Item -Force $tmp
    Write-Host "$name -> $dest"
}

# nginx（Windows 稳定版，含 nginx.exe）
Fetch "nginx" "https://nginx.org/download/nginx-$NGINX_VER.zip" "nginx-$NGINX_VER" (Join-Path $runtime "nginx")

# php（8.3 NTS x64，含 php-cgi.exe）
# 注意：windows.php.net 的 releases/ 只保留最新几个小版本，旧版本已移到
# releases/archives/；8.3.12 不在 releases/ 顶层（404），故走 archives/ 路径。
# 该 URL 会 302 重定向到 CDN，Invoke-WebRequest 默认跟随，可正常下载。
Fetch "php" "https://windows.php.net/downloads/releases/archives/php-$PHP_VER-nts-Win32-vs16-x64.zip" "php" (Join-Path $runtime "php")

# mariadb（Windows x64 zip，含 bin/mariadbd.exe）
Fetch "mariadb" "https://archive.mariadb.org/mariadb-$MARIADB_VER/winx64-packages/mariadb-$MARIADB_VER-winx64.zip" "mariadb-$MARIADB_VER-winx64" (Join-Path $runtime "mariadb")

# CA 证书包 —— **必须有**：Windows 版 PHP 不自带 CA bundle，
# 缺这个文件时所有 https 出站（立创商品查询 / AI 接口 / 版本检测）都会
# 静默失败（curl errno 77: Problem with the SSL CA cert）。
$caPath = Join-Path $runtime "cacert.pem"
Write-Host "下载 CA 证书包: https://curl.se/ca/cacert.pem"
Invoke-WebRequest -Uri "https://curl.se/ca/cacert.pem" -OutFile $caPath -UseBasicParsing
$caSize = (Get-Item $caPath).Length
if ($caSize -lt 50000) {
    throw "cacert.pem 下载异常（仅 $caSize 字节），https 出站会全部失败"
}
Write-Host "CA 证书包就位: $caSize 字节"

Write-Host "完成。运行时已就位："
Get-ChildItem $runtime
