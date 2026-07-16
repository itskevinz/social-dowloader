FROM php:8.3-apache

# ext-curl is required by index.php (fG/fP + the streaming proxy)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install curl \
    && a2enmod headers \
    && rm -rf /var/lib/apt/lists/*

# Slightly saner defaults for a downloader tool (large-ish responses, longer proxy streams)
RUN { \
        echo "memory_limit=256M"; \
        echo "max_execution_time=180"; \
        echo "upload_max_filesize=0"; \
        echo "output_buffering=Off"; \
    } > /usr/local/etc/php/conf.d/kevinz.ini

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Render injects $PORT at runtime; Apache's default vhost/ports listen on 80,
# so rewrite both at container start.
ENV PORT=80
EXPOSE 80
CMD sh -c "sed -i \"s/80/\${PORT}/g\" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf && apache2-foreground"
