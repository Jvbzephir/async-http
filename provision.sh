#!/bin/bash

sudo apt-get update
sudo apt-get install git pkg-config autoconf bison libxml2-dev libssl-dev libtool -y

# Install PHP7:

sudo mkdir /etc/php7
cd /etc/php7

sudo mkdir php-src
sudo curl -LSs https://github.com/php/php-src/archive/php-7.0.5.tar.gz | sudo tar -xz -C "php-src" --strip-components 1

pushd php-src
sudo ./buildconf --force
sudo ./configure \
	--prefix=/etc/php7/usr \
	--with-config-file-path=/etc/php7/usr/etc \
	--enable-maintainer-zts \
	--enable-pcntl \
	--enable-sockets \
	--with-iconv \
	--with-openssl \
	--with-zlib=/usr
	
sudo make -j $(($(cat /proc/cpuinfo | grep processor | wc -l) + 1))
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

yes '' | sudo pecl install pthreads
yes '' | sudo pecl install ev-beta

# Install libuv
sudo curl -LSs https://github.com/libuv/libuv/archive/v1.8.0.tar.gz | sudo tar -xz
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
