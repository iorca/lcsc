#!/bin/sh
set -eu

# 复用 deploy/nginx.conf（Nginx 安全规则唯一来源），只改写三处部署参数：
#   server_name  ->  _
#   root         ->  /var/www/html
#   fastcgi_pass ->  app:9000
#
# 改安全规则请改 deploy/nginx.conf；本脚本不复制任何规则，避免第三份副本漂移。
SRC=/etc/nginx/lcsc/nginx.conf
DST=/etc/nginx/conf.d/default.conf

sed \
    -e 's#^[[:space:]]*server_name[[:space:]][^;]*;#    server_name _;#' \
    -e 's#^[[:space:]]*root[[:space:]][^;]*;#    root /var/www/html;#' \
    -e 's#^[[:space:]]*fastcgi_pass[[:space:]][[:space:]]*unix:/run/php/php-fpm.sock;#    fastcgi_pass app:9000;#g' \
    "$SRC" > "$DST"

nginx -t
exec nginx -g 'daemon off;'
