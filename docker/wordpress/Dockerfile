FROM composer:2 AS composer_stage

FROM wordpress:6.7.1-php8.2-apache

ARG THEME_DIR=/usr/src/wordpress/wp-content/themes/terapia-huvi

# Install Node.js, npm, and other tooling required for building assets
RUN apt-get update \
    && apt-get install -y git unzip curl gnupg ca-certificates rsync \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Provide composer binary
COPY --from=composer_stage /usr/bin/composer /usr/local/bin/composer

# Copy theme sources into the image
COPY . ${THEME_DIR}

WORKDIR ${THEME_DIR}

# Install PHP and JS dependencies, then build assets
RUN composer install --no-dev --optimize-autoloader \
    && npm ci \
    && npm run build \
    && rm -rf node_modules

# Remove local-only tooling from the packaged theme
RUN rm -rf ${THEME_DIR}/docker \
    ${THEME_DIR}/docker-compose.yml \
    ${THEME_DIR}/.env.example \
    ${THEME_DIR}/.dockerignore

WORKDIR /var/www/html

# Copy entrypoint wrapper + theme sync helper
COPY docker/wordpress/copy-theme.sh /usr/local/bin/copy-theme.sh
COPY docker/wordpress/with-theme-entrypoint.sh /usr/local/bin/with-theme-entrypoint.sh

# Ensure uploads directory exists with correct permissions
RUN mkdir -p /var/www/html/wp-content/uploads \
    && chown -R www-data:www-data /usr/src/wordpress/wp-content \
    && chown -R www-data:www-data /var/www/html/wp-content \
    && chmod +x /usr/local/bin/copy-theme.sh \
    && chmod +x /usr/local/bin/with-theme-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/with-theme-entrypoint.sh"]
CMD ["apache2-foreground"]
