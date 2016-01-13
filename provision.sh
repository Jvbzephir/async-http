#!/bin/bash

sudo apt-get install -y language-pack-en-base;

yes 'Y' | sudo LC_ALL=en_US.UTF-8 apt-add-repository ppa:ondrej/php-7.0;
yes 'Y' | sudo apt-get update;
yes 'Y' | sudo apt-get install php7.0-cli php7.0-dev php-pear;

sudo pear config-set php_ini /etc/php/7.0/cli/php.ini;
sudo pecl config-set php_ini /etc/php/7.0/cli/php.ini;

sudo pecl channel-update pecl.php.net;

sudo pecl uninstall ev;

yes 'Y' | sudo pecl install ev-beta;
