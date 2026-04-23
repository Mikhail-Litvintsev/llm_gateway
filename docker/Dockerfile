FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    unzip \
    && docker-php-ext-install pdo_mysql xml mbstring zip bcmath pcntl sockets \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
