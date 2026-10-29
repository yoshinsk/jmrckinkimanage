# Path: tests/auth/Dockerfile.php
# 機能: PHP-FPM 8.4.27とPDO MySQLで共通認証の隔離検証環境を作る。
FROM php:8.4.27-fpm-bookworm@sha256:df5a5113e1179ddf4560cd15727eb3812f6457829756d2a96c6c11bb4c250089
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libxml2-dev \
    && docker-php-ext-install pdo_mysql zip dom \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /srv/sessions \
    && chown www-data:www-data /srv/sessions && chmod 700 /srv/sessions
COPY src/ /srv/auth/src/
COPY auth.config.php /srv/private/auth.config.php
COPY site/ /srv/web/
COPY tests/ /srv/tests/
COPY scripts/ /srv/auth/scripts/
COPY templates/ /srv/auth/templates/
COPY tls.crt /srv/tests/tls.crt
RUN printf 'clear_env = no\n' > /usr/local/etc/php-fpm.d/zz-lab.conf \
    && printf 'display_errors=Off\nlog_errors=On\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/zz-lab.ini
ENV JMRC_AUTH_BOOTSTRAP=/srv/auth/src/bootstrap.php JMRC_AUTH_CONFIG=/srv/private/auth.config.php
