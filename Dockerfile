FROM php:8.1-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql

# Enable Apache modules
RUN a2enmod rewrite
RUN a2enmod headers
RUN a2enmod negotiation
RUN a2enmod ssl

# Copy application files
COPY . /var/www/html/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/public \
    && chmod -R 750 /var/www/html/src