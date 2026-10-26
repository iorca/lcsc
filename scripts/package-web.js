#!/usr/bin/env node
/**
 * 按 release-manifest.json 的文件清单，把 PHP 源码 + composer vendor 复制进 web-dist/，
 * 供 GitHub Actions 打成可部署的 web 源码 zip。
 *
 * 用法：node scripts/package-web.js
 */
const fs = require('fs');
const path = require('path');

const root = process.cwd();
const manifestPath = path.join(root, 'release-manifest.json');
const dist = path.join(root, 'web-dist');

if (!fs.existsSync(manifestPath)) {
  console.error('找不到 release-manifest.json');
  process.exit(1);
}

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const files = manifest.files || [];

fs.rmSync(dist, { recursive: true, force: true });
fs.mkdirSync(dist, { recursive: true });

function copyItem(src, dest) {
  if (fs.statSync(src).isDirectory()) {
    fs.cpSync(src, dest, { recursive: true });
  } else {
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.copyFileSync(src, dest);
  }
}

let copied = 0;
for (const f of files) {
  const src = path.join(root, f);
  const dest = path.join(dist, f);
  if (fs.existsSync(src)) {
    copyItem(src, dest);
    copied++;
  } else {
    console.warn('清单文件缺失，跳过:', f);
  }
}

// composer 安装产物（phpspreadsheet 等）
const vendorSrc = path.join(root, 'vendor');
if (fs.existsSync(vendorSrc)) {
  fs.cpSync(vendorSrc, path.join(dist, 'vendor'), { recursive: true });
  console.log('已包含 vendor/ (composer 依赖)');
} else {
  console.warn('未找到 vendor/，请先 composer install');
}

// 补齐运行时配置样例
for (const extra of ['.env.example']) {
  const s = path.join(root, extra);
  if (fs.existsSync(s)) fs.copyFileSync(s, path.join(dist, extra));
}

console.log(`web-dist 构建完成：复制 ${copied}/${files.length} 个清单文件 -> ${dist}`);
