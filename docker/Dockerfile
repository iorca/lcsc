# LCSC 硬件资产管理 —— PHP-FPM 运行镜像
#
# 构建上下文 = 项目根目录；Web 服务（Nginx）使用官方镜像 + deploy/nginx.conf。
#
# 代码在镜像内有两份用途：
#   /opt/lcsc      镜像内原始代码（首次启动时复制到持久卷）
#   /var/www/html  持久卷挂载点（实际运行代码；在线升级会改写这里）
#
# 国内网络可通过 build args 切换源（默认官方源）：
#   PHP_IMAGE / COMPOSER_IMAGE  基础镜像（可指向镜像加速地址）
#   APK_MIRROR                  Alpine 包镜像主机（如 mirrors.aliyun.com）
#   COMPOSER_MIRROR             Composer / Packagist 镜像（如 https://mirrors.aliyun.com/composer/）
#
# 注意：这里**不要**写 `# syntax=docker/dockerfile:1` —— 它会让 BuildKit 先从
# docker.io 拉取 14MB 的 Dockerfile 前端镜像，国内经常卡在这一步；
# 本文件只使用内置前端支持的语法（ARG / 多阶段 / COPY --from）。

ARG COMPOSER_IMAGE=composer:2
ARG PHP_IMAGE=php:8.3-fpm-alpine

FROM ${COMPOSER_IMAGE} AS vendor
ARG COMPOSER_MIRROR=
ENV COMPOSER_PROCESS_TIMEOUT=600
WORKDIR /app
COPY composer.json ./
RUN set -eu; \
    echo "[lcsc] composer source: ${COMPOSER_MIRROR:-official}"; \
    if [ -n "$COMPOSER_MIRROR" ]; then export COMPOSER_REPO_PACKAGIST="$COMPOSER_MIRROR"; fi; \
    composer install \
        --no-dev --no-interaction --no-progress --prefer-dist --no-scripts \
        --ignore-platform-reqs

FROM ${PHP_IMAGE}

ARG APK_MIRROR=
ENV TZ=Asia/Shanghai

RUN set -eux; \
    echo "[lcsc] apk source: ${APK_MIRROR:-official}"; \
    if [ -n "$APK_MIRROR" ]; then \
        host="${APK_MIRROR#http://}"; host="${host#https://}"; host="${host%/}"; \
        sed -i "s#dl-cdn.alpinelinux.org#${host}#g" /etc/apk/repositories; \
    fi; \
    cat /etc/apk/repositories; \
    apk add --no-cache \
        ca-certificates \
        libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev \
        su-exec tzdata; \
    update-ca-certificates; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip gd; \
    rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-lcsc.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-lcsc.conf
COPY docker/entrypoint.sh /usr/local/bin/lcsc-entrypoint
RUN chmod +x /usr/local/bin/lcsc-entrypoint

WORKDIR /opt/lcsc
COPY app /opt/lcsc/app
COPY modules /opt/lcsc/modules
COPY ui /opt/lcsc/ui
COPY mobile /opt/lcsc/mobile
COPY third_party /opt/lcsc/third_party
COPY .htaccess composer.json version.json scan_decoder.js /opt/lcsc/
COPY *.php /opt/lcsc/
COPY uploads/.htaccess /opt/lcsc/uploads/.htaccess
COPY updates/.htaccess /opt/lcsc/updates/.htaccess
COPY backups/.gitkeep /opt/lcsc/backups/.gitkeep
COPY --from=vendor /app/vendor /opt/lcsc/vendor

RUN mkdir -p /var/www/html /var/lib/php/sessions \
    && chown -R www-data:www-data /opt/lcsc /var/www/html /var/lib/php/sessions

EXPOSE 9000
STOPSIGNAL SIGQUIT
ENTRYPOINT ["/usr/local/bin/lcsc-entrypoint"]
