# Use official PHP image with Apache
FROM php:8.2-apache

# Install cURL extension for API requests
RUN docker-php-ext-install curl

# Copy project files to web root
COPY . /var/www/html/

# Expose port 10000 (Render uses its own internal port mapping)
EXPOSE 10000
