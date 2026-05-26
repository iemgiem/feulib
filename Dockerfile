FROM php:8.2-cli

# mbstring requires oniguruma; install system dep first
RUN apt-get update && apt-get install -y libonig-dev && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring

WORKDIR /app
COPY . .

# Railway injects $PORT at runtime; default to 8080 for local docker runs
CMD php -S 0.0.0.0:${PORT:-8080} router.php
