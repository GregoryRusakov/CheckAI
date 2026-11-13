FROM php:8.3-apache-bookworm

WORKDIR /opt/checkai
COPY public/ public/
COPY src/ src/
COPY database/ database/
COPY bin/ bin/
COPY tests/ tests/
RUN a2enmod rewrite \
    && sed -i 's|/var/www/html|/opt/checkai/public|g' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' '<Directory /opt/checkai/public>' '    Options -Indexes' '    AllowOverride None' '    AddCharset UTF-8 .js .css' '    Require all granted' '    RewriteEngine On' '    RewriteCond %{REQUEST_FILENAME} !-f' '    RewriteCond %{REQUEST_FILENAME} !-d' '    RewriteRule ^ index.php [QSA,L]' '</Directory>' > /etc/apache2/conf-enabled/checkai.conf \
    && mkdir -p var \
    && chown www-data:www-data var \
    && php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);'
