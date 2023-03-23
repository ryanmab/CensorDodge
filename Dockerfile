FROM php:8.1-apache-buster
LABEL org.opencontainers.image.authors="ryan@censordodge.com"
WORKDIR /var/www/html
COPY . /var/www/html/
EXPOSE 80
