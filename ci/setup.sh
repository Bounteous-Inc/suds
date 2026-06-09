#!/usr/bin/env bash
#
# CI environment bootstrap for Bitbucket Pipelines (php:8.3-cli image).
#
# Installs system packages, PHP extensions required by Drupal, and Composer,
# then runs `composer install`.
#
# Usage: . ci/setup.sh

set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1

# System packages needed as build prerequisites for PHP extensions.
apt-get update -q
apt-get install -y -q --no-install-recommends \
  git \
  sqlite3 \
  libsqlite3-dev \
  libpng-dev \
  libjpeg-dev \
  libfreetype6-dev \
  libzip-dev \
  zip \
  unzip

# PHP extensions required by Drupal core (beyond those already in php:8.3-cli).
docker-php-ext-configure gd --with-freetype --with-jpeg
docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite gd zip

# Install Composer (not bundled with php:8.3-cli).
curl -sS https://getcomposer.org/installer \
  | php -- --install-dir=/usr/local/bin --filename=composer

# Install PHP project dependencies.
composer install --no-interaction --prefer-dist --no-progress
