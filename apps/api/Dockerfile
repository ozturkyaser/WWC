FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libpq-dev curl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_pgsql pgsql zip pcntl

# Docker-CLI + Compose-Plugin: Verwaltung der Dev-Clone-Stacks ueber den Host-Docker-Socket
RUN ARCH=$(uname -m) \
    && curl -fsSL "https://download.docker.com/linux/static/stable/${ARCH}/docker-27.5.1.tgz" \
        | tar -xz --strip-components=1 -C /usr/local/bin docker/docker \
    && COMPOSE_ARCH=$([ "$ARCH" = "aarch64" ] && echo "aarch64" || echo "x86_64") \
    && mkdir -p /usr/local/lib/docker/cli-plugins \
    && curl -fsSL "https://github.com/docker/compose/releases/download/v2.32.4/docker-compose-linux-${COMPOSE_ARCH}" \
        -o /usr/local/lib/docker/cli-plugins/docker-compose \
    && chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
