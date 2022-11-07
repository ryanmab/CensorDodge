FROM php:8.1-apache-buster
MAINTAINER Ryan Maber <ryan@censordodge.com>
WORKDIR /var/www/html
COPY . /var/www/html/
EXPOSE 80
