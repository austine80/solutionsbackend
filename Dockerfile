FROM php:8.2-apache

# Install system dependencies needed for PHP extensions
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    && docker-php-ext-install curl

# Copy your project files
COPY . /var/www/html/
