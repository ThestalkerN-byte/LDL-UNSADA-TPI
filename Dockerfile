# ──────────────────────────────────────────────────────────────
# Dockerfile — LDL-UNSADA-TPI
# PHP 8.2 + Apache + Extensiones MySQL + Composer
# ──────────────────────────────────────────────────────────────
FROM php:8.2-apache

# 1. Instalar dependencias del sistema y extensiones PHP necesarias
RUN apt-get update && apt-get install -y \
        libzip-dev \
        zip \
        unzip \
        curl \
        libssl-dev \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 2. Habilitar módulo Apache rewrite (necesario para .htaccess)
RUN a2enmod rewrite

# 3. Configurar Apache: ServerName y DirectoryIndex
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# 4. Configuración del VirtualHost (permite .htaccess con AllowOverride All)
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html\n\
    <Directory /var/www/html>\n\
        Options Indexes FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# 5. Instalar Composer (gestor de dependencias PHP)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 6. Copiar los archivos del proyecto al contenedor
WORKDIR /var/www/html
COPY . .

# 7. Instalar dependencias PHP (PHPMailer, Doctrine, JWT, etc.)
#    --no-dev excluye herramientas de desarrollo en producción
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 8. Permisos correctos para Apache
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# 9. Exponer puerto 80
EXPOSE 80
