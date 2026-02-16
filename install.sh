#!/bin/bash
cd /home/meder98/meder98.beget.tech/api

# Установка зависимостей
/usr/bin/php7.4 /usr/bin/composer install --no-dev --optimize-autoloader

# Копирование .env
cp .env.production .env

# Генерация ключа
/usr/bin/php7.4 artisan key:generate --force

# Миграции
/usr/bin/php7.4 artisan migrate --force

# Кеширование
/usr/bin/php7.4 artisan config:cache
/usr/bin/php7.4 artisan route:cache
/usr/bin/php7.4 artisan view:cache

# Права доступа
chmod -R 775 storage bootstrap/cache
chmod -R 777 storage/logs

# Создание символической ссылки
/usr/bin/php7.4 artisan storage:link

echo "Installation completed!"
