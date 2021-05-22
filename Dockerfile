FROM php:7.4-apache-buster
MAINTAINER Ryan Maber <ryan@censordodge.com>
WORKDIR /var/www/html
COPY . /var/www/html/
EXPOSE 80
