FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libcurl4-openssl-dev \
        libpng-dev \
    && docker-php-ext-install pdo_pgsql curl gd \
    && rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
    && a2enmod mpm_prefork headers rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && apache2ctl -t \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html/

RUN sed -i 's/\r$//' /var/www/html/docker-start.sh \
    && mkdir -p /var/lib/easysched/sessions \
    && chown -R www-data:www-data /var/lib/easysched \
    && chmod -R 770 /var/lib/easysched \
    && printf '<Directory /var/www/html/data>\n    DirectorySlash Off\n</Directory>\n' > /etc/apache2/conf-available/easysched-data.conf \
    && a2enconf easysched-data \
    && apache2ctl -t \
    && chmod +x /var/www/html/docker-start.sh

ENV EASYSCHED_SESSION_PATH=/var/lib/easysched/sessions

EXPOSE 80

CMD ["/var/www/html/docker-start.sh"]
