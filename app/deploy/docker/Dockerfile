# Build context: /DATA (dbtoolbox-ui + dbtoolbox-php-1.0.0 siblings)
FROM node:22-bookworm-slim AS frontend-build

WORKDIR /src
COPY dbtoolbox-ui/ dbtoolbox-ui/
COPY dbtoolbox-php-1.0.0/frontend/package*.json dbtoolbox-php-1.0.0/frontend/

WORKDIR /src/dbtoolbox-php-1.0.0/frontend
RUN npm ci

COPY dbtoolbox-php-1.0.0/frontend/ ./
COPY dbtoolbox-php-1.0.0/VERSION ../VERSION

WORKDIR /src/dbtoolbox-ui
RUN npm install --no-save \
  react@^18.3.1 react-dom@^18.3.1 lucide-react@^0.454.0 \
  @tanstack/react-query@^5.59.0 @monaco-editor/react@^4.6.0 \
  ag-grid-community@^32.3.2 ag-grid-react@^32.3.2 clsx@^2.1.1 \
  dagre@^0.8.5 reactflow@^11.11.4 sql-formatter@^15.4.6 \
  tailwind-merge@^2.5.4 zustand@^5.0.1

WORKDIR /src/dbtoolbox-php-1.0.0/frontend
RUN npx vite build

FROM php:8.3-apache-bookworm

RUN apt-get update \
  && apt-get install -y --no-install-recommends libpq-dev \
  && docker-php-ext-install pdo_mysql pdo_pgsql \
  && a2enmod rewrite headers \
  && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
  && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY dbtoolbox-php-1.0.0/deploy/docker/apache.conf /etc/apache2/conf-available/dbtoolbox-php.conf
RUN a2enconf dbtoolbox-php

WORKDIR /var/www/html

COPY dbtoolbox-php-1.0.0/composer.json dbtoolbox-php-1.0.0/VERSION ./
COPY dbtoolbox-php-1.0.0/config/config.example.php config/config.example.php
COPY dbtoolbox-php-1.0.0/migrations/ migrations/
COPY dbtoolbox-php-1.0.0/scripts/ scripts/
COPY dbtoolbox-php-1.0.0/src/ src/
COPY dbtoolbox-php-1.0.0/public/index.php dbtoolbox-php-1.0.0/public/.htaccess public/
COPY --from=frontend-build /src/dbtoolbox-php-1.0.0/public/index.html public/index.html
COPY --from=frontend-build /src/dbtoolbox-php-1.0.0/public/assets public/assets

COPY dbtoolbox-php-1.0.0/deploy/docker/docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh \
  && mkdir -p storage/backups \
  && chown -R www-data:www-data storage

VOLUME ["/var/www/html/storage"]

EXPOSE 80

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
