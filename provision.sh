#!/bin/bash

sudo apt-get update
yes 'Y' | sudo apt-get install git pkg-config autoconf bison libxml2-dev libssl-dev libtool

# Install PHP7:

sudo mkdir /etc/php7
cd /etc/php7

sudo git clone -b master https://git.php.net/repository/php-src.git

pushd php-src
sudo ./buildconf
sudo ./configure \
	--prefix=/etc/php7/usr \
	--with-config-file-path=/etc/php7/usr/etc \
	--enable-pcntl \
	--enable-sockets \
	--with-iconv \
	--with-openssl \
	--with-zlib=/usr
	
sudo make
sudo make install
popd

sudo ln -s /etc/php7/usr/bin/php /usr/local/bin/php
sudo ln -s /etc/php7/usr/bin/pecl /usr/local/bin/pecl
sudo ln -s /etc/php7/usr/bin/pear /usr/local/bin/pear
sudo ln -s /etc/php7/usr/bin/phpize /usr/local/bin/phpize
sudo ln -s /etc/php7/usr/bin/php-config /usr/local/bin/php-config

sudo touch /etc/php7/usr/etc/php.ini
sudo chmod 777 /etc/php7/usr/etc/php.ini

sudo pear config-set php_ini /etc/php7/usr/etc/php.ini
sudo pecl config-set php_ini /etc/php7/usr/etc/php.ini

sudo pecl channel-update pecl.php.net

sudo pecl uninstall ev
yes '' | sudo pecl install ev-beta

# Install libuv
sudo curl -LS https://github.com/libuv/libuv/archive/v1.8.0.tar.gz | sudo tar -xz
pushd libuv-*
sudo ./autogen.sh
sudo ./configure --prefix=$(dirname `pwd`)/libuv
sudo make
sudo make install
popd

sudo git clone https://github.com/bwoebi/php-uv.git
pushd php-uv
sudo phpize
sudo ./configure --with-uv=$(dirname `pwd`)/libuv
sudo make
sudo make install
popd

sudo echo "extension=uv.so" >> /etc/php7/usr/etc/php.ini
