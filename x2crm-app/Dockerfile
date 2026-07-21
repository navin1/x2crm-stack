# X2CRM runs on a classic AMP stack. PHP 7.4 is the newest version the
# legacy X2CRM codebase is broadly compatible with (test 8.x carefully
# if you fork/patch the code).
FROM php:7.4-apache

RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev libzip-dev unzip curl git \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) gd mysqli pdo pdo_mysql zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# This image ships with no php.ini at all (only the extension .ini files
# under conf.d/), so upload_max_filesize/post_max_size fall back to PHP's
# stock 2M/8M defaults — plenty for normal form posts, but Admin > Import
# Records fails outright ("There was an error saving the models file",
# AdminController::actionImportModels) the moment a Contacts CSV export
# exceeds 2MB, since PHP silently rejects the upload before X2CRM's code
# ever runs. Bumped for realistic CSV import/export sizes; max_execution_time
# raised too since importing thousands of rows one at a time can run long.
RUN { \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 300'; \
    } > /usr/local/etc/php/conf.d/x2crm-uploads.ini

WORKDIR /var/www/html

# X2CRM's GitHub repo puts the real application inside an `x2engine/`
# subfolder (alongside `build/`, README, CHANGELOG, etc at the repo
# root) — so we copy from there specifically, not the repo root, or
# Apache would end up serving the wrong directory.
COPY src/x2engine/ /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

EXPOSE 80
