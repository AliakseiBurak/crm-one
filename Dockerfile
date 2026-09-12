FROM node:24-bookworm-slim AS node

FROM php:8.5-fpm-bookworm

ARG APP_UID=1000
ARG APP_GID=1000

ENV TZ=Europe/Minsk

# Fail fast with clear logs; isolate apt from extension compilation for cacheability.
SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        unzip \
        git \
        curl \
        ca-certificates \
        tzdata \
    && ln -sf /usr/share/zoneinfo/Europe/Minsk /etc/localtime \
    && echo ${TZ} > /etc/timezone

RUN docker-php-ext-install \
        pdo_mysql \
        intl \
        zip \
        bcmath \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN echo 'date.timezone = ${TZ}' > /usr/local/etc/php/conf.d/timezone.ini
RUN echo 'xdebug.mode=debug,coverage' > /usr/local/etc/php/conf.d/xdebug.ini \
    && echo 'xdebug.start_with_request=trigger' >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo 'xdebug.client_host=host.docker.internal' >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo 'xdebug.client_port=9003' >> /usr/local/etc/php/conf.d/xdebug.ini

# Fail the build loudly if required modules are not actually loadable.
RUN php -m | grep -Eq '^pdo_mysql$' \
    && php -m | grep -Eq '^intl$' \
    && php -m | grep -Eq '^zip$'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js 24 (LTS) для сборки фронтенд-активов (Webpack Encore 7 требует
# Node ^22.18 || ^24.11 || >=26; в debian bookworm apt-пакет nodejs — 18.x).
# Бинарно совместим: оба образа на базе debian bookworm (та же glibc).
# npm/npx в node-образе — hardlink-файлы, COPY из flat их в обычные файлы
# и ломает относительный require ../lib/cli.js, поэтому бинарники npm/npx
# пересоздаются симлинками на установленный пакет.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
COPY --from=node /usr/lib/x86_64-linux-gnu/libstdc++.so.6 /usr/lib/x86_64-linux-gnu/libstdc++.so.6
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && node --version && npm --version

# Не показывать npm-уведомление о новой версии при каждой сборке (npm 11 из
# node-образа достаточен: сборка детерминирована package-lock.json).
ENV NO_UPDATE_NOTIFIER=1

RUN groupadd --gid ${APP_GID} app \
    && useradd --uid ${APP_UID} --gid ${APP_GID} --create-home --shell /bin/bash app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
COPY docker/gen-certs.sh /usr/local/bin/gen-certs

RUN chmod +x /usr/local/bin/entrypoint /usr/local/bin/gen-certs

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
