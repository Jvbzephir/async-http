#!/bin/bash

sudo apt-get install python-software-properties
sudo LC_ALL=C.UTF-8 add-apt-repository ppa:ondrej/php

sudo apt-get update
sudo apt-get purge php5-common -y
sudo apt-get install git php7.0-cli php7.0-dev php-pear -y
sudo apt-get --purge autoremove -y

sudo pear config-set php_ini /etc/php/7.0/cli/php.ini
sudo pecl config-set php_ini /etc/php/7.0/cli/php.ini

sudo pecl channel-update pecl.php.net

# yes '' | sudo pecl install ev
# yes '' | sudo pecl install eio
