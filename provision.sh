#!/bin/bash

sudo apt-get update
yes 'Y' | sudo apt-get install git pkg-config autoconf bison libxml2-dev libssl-dev

sudo mkdir /etc/php7
cd /etc/php7

sudo git clone -b master https://git.php.net/repository/php-src.git

cd php-src

sudo ./buildconf
sudo ./configure \
	--prefix=/etc/php7/usr \
	--with-config-file-path=/etc/php7/usr/etc \
	--enable-maintainer-zts \
	--enable-pcntl \
	--with-iconv \
	--with-openssl \
	--with-zlib=/usr
	
sudo make
sudo make install

sudo ln -s /etc/php7/usr/bin/php /usr/local/bin/php
sudo ln -s /etc/php7/usr/bin/pecl /usr/local/bin/pecl
sudo ln -s /etc/php7/usr/bin/pear /usr/local/bin/pear

sudo touch /etc/php7/usr/etc/php.ini
sudo chmod 777 /etc/php7/usr/etc/php.ini

sudo pear config-set php_ini /etc/php7/usr/etc/php.ini
sudo pecl config-set php_ini /etc/php7/usr/etc/php.ini

sudo pecl channel-update pecl.php.net

sudo pecl uninstall ev
yes '' | sudo pecl install ev-beta

sudo pecl uninstall pthreads
yes '' | sudo pecl install pthreads
