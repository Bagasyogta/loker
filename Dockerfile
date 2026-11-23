# Gunakan base image resmi PHP dengan web server Apache
FROM php:8.2-apache

# Instal dependensi sistem dan ekstensi PHP PDO MySQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_mysql mysqli \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Salin seluruh kode proyek ke direktori web Apache
WORKDIR /var/www/html
COPY . /var/www/html/

# Atur hak kepemilikan berkas untuk web server www-data
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Expose port 80
EXPOSE 80

CMD ["apache2-foreground"]
