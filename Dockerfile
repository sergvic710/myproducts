FROM php:8.4-apache
# Arguments defined in docker-compose.yml
ARG user=sample
ARG uid=1000

#Change Root direcory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN docker-php-ext-install mysqli
RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN a2enmod rewrite

RUN apt-get update -y && apt-get install -y  \
    sendmail  \
    libpng-dev \
    zlib1g-dev \
    libzip-dev \
    git \
    curl \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libmagickwand-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev


#RUN docker-php-ext-install zip
#RUN docker-php-ext-install gd

RUN docker-php-ext-configure gd --enable-gd --with-freetype=/usr --with-webp=/usr/include --with-jpeg=/usr/include
RUN docker-php-ext-install -j$(nproc) pdo_mysql \
    bcmath \
    gd \
    exif \
    zip \
    intl

RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

RUN pear install -a SOAP-0.13.0 \
    && docker-php-ext-install soap;

# Install xdebug
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
# Create system user to run Composer and Artisan Commands
RUN useradd -G www-data,root -u $uid -d /home/$user $user
RUN mkdir -p /home/$user/.composer && \
    chown -R $user:$user /home/$user

USER $user
