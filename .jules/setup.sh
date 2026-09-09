#!/bin/bash
export DEBIAN_FRONTEND=noninteractive

# Ensure local .env exists
if [ ! -f .env ]; then
  cp .env.example .env
fi

# Point to local native services instead of docker
sed -i 's/DB_HOST=postgres/DB_HOST=127.0.0.1/g' .env
sed -i 's/REDIS_HOST=redis/REDIS_HOST=127.0.0.1/g' .env
sed -i 's/MQTT_HOST=mqtt/MQTT_HOST=127.0.0.1/g' .env
sed -i 's/REVERB_HOST=reverb/REVERB_HOST=127.0.0.1/g' .env

sudo apt-get update
sudo apt-get install -y php-pgsql php-redis postgresql postgresql-contrib redis-server mosquitto

sudo service postgresql start
sudo service redis-server start
sudo service mosquitto start

sudo -u postgres psql -c "CREATE USER postgres WITH PASSWORD 'secret';" || true
sudo -u postgres psql -c "ALTER USER postgres WITH PASSWORD 'secret';" || true
sudo -u postgres psql -c "CREATE DATABASE camera_hub OWNER postgres;" || true
sudo -u postgres psql -c "ALTER USER postgres WITH SUPERUSER;" || true

composer install
php artisan key:generate
php artisan migrate --force
npm install --ignore-scripts
npm run build
