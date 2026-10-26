# 组装桌面版产物：
#   1) payload/ = runtime + www + conf + launcher.exe
#   2) LCSC-portable.zip  （含 portable.flag，解压即用）
#   3) LCSC-Setup-1.2.0.exe（NSIS 安装版，若 makensis 可用）
# 用法：在 desktop/ 目录运行  powershell -ExecutionPolicy Bypass -File package.ps1
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot

# 准备 www：若 desktop/www 不存在，则从仓库根 web-dist 复制（由 scripts/package-web.js 生成）
$www = Join-Path $root "www"
if (-not (Test-Path $www)) {
  $webDist = Join-Path $root ".." "web-dist"
  if (Test-Path $webDist) {
    New-Item -ItemType Directory -Force -Path $www | Out-Null
    Copy-Item (Join-Path $webDist "*") $www -Recurse -Force
    Write-Host "已从 web-dist 复制 www"
  } else {
    Write-Error "找不到 www 目录，且仓库根 web-dist 不存在，无法打包"
  }
}

$payload = Join-Path $root "payload"
Remove-Item -Recurse -Force $payload -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $payload | Out-Null

Copy-Item (Join-Path $root "runtime") $payload -Recurse
Copy-Item (Join-Path $root "www")     $payload -Recurse
Copy-Item (Join-Path $root "conf")    $payload -Recurse
Copy-Item (Join-Path $root "launcher.exe") $payload

# 便携 zip（写 portable.flag）
New-Item -ItemType File -Path (Join-Path $payload "portable.flag") | Out-Null
$zip = Join-Path $root "LCSC-portable.zip"
Remove-Item -Force $zip -ErrorAction SilentlyContinue
Compress-Archive -Path "$payload\*" -DestinationPath $zip
Write-Host "便携包已生成: $zip"

# 安装版（NSIS）
$makensis = Get-Command makensis -ErrorAction SilentlyContinue
if (-not $makensis) { $makensis = "C:\ProgramData\chocolatey\bin\makensis.exe" }
if (Test-Path $makensis) {
  & $makensis (Join-Path $root "build.nsi")
  Write-Host "安装包已生成: LCSC-Setup-1.2.0.exe"
} else {
  Write-Host "未检测到 makensis，跳过安装版（可用 choco install nsis 安装）"
}
