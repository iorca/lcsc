#!/bin/sh
set -eu

CODE=/var/www/html
PRISTINE=/opt/lcsc
BOOTSTRAP="$CODE/.lcsc-bootstrap"

# 1) 首次启动 / 上次复制中断：把镜像内代码复制到持久卷。
#    用引导标记判断复制是否完成；上次复制中断（无标记）会在本次启动自动重试。
if [ ! -f "$BOOTSTRAP" ] && [ ! -f "$CODE/.installed" ]; then
    echo "[lcsc] preparing code volume ..."
    mkdir -p "$CODE"
    cp -a "$PRISTINE"/. "$CODE"/
    touch "$BOOTSTRAP"
    echo "[lcsc] code volume prepared"
fi

# 2) 可写目录与上传 / 升级目录的保护文件
mkdir -p "$CODE/uploads/import" "$CODE/uploads/thumbs" "$CODE/backups" "$CODE/updates"
[ -f "$CODE/uploads/.htaccess" ] || cp "$PRISTINE/uploads/.htaccess" "$CODE/uploads/.htaccess"
[ -f "$CODE/updates/.htaccess" ] || cp "$PRISTINE/updates/.htaccess" "$CODE/updates/.htaccess"

# 3) 代码卷归 PHP 进程所有：在线升级需要改写代码目录
if [ "$(stat -c '%u' "$CODE")" != "$(id -u www-data)" ]; then
    chown -R www-data:www-data "$CODE"
fi
# 目录可能由旧的 init 容器以 root 创建，单独校正可写目录（含导入 / 缩略图子目录）
chown www-data:www-data \
    "$CODE" "$CODE/uploads" "$CODE/uploads/import" "$CODE/uploads/thumbs" \
    "$CODE/backups" "$CODE/updates" 2>/dev/null || true
mkdir -p /var/lib/php/sessions
chown -R www-data:www-data /var/lib/php/sessions

# 4) 免向导自动安装（显式 LCSC_AUTOINSTALL=1 且尚未安装）
if [ "${LCSC_AUTOINSTALL:-}" = "1" ] && [ ! -f "$CODE/.installed" ]; then
    echo "[lcsc] waiting for database ${LCSC_DB_HOST:-db}:${LCSC_DB_PORT:-3306} ..."
    i=0
    until su-exec www-data php -r '
        try {
            new PDO(
                "mysql:host=" . (getenv("LCSC_DB_HOST") ?: "db") . ";port=" . (getenv("LCSC_DB_PORT") ?: "3306") . ";charset=utf8mb4",
                getenv("LCSC_DB_USER") ?: "root",
                getenv("LCSC_DB_PASS") ?: ""
            );
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    '; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "[lcsc] database wait timeout (120s); check the db service and .env" >&2
            exit 1
        fi
        sleep 2
    done

    echo "[lcsc] running non-interactive install ..."
    su-exec www-data php "$CODE/install.php" || {
        echo "[lcsc] auto install failed; check LCSC_ADMIN_PASS strength and database privileges" >&2
        exit 1
    }
fi

echo "[lcsc] starting php-fpm ..."
exec php-fpm -F
