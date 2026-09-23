# Pendiente: terminar configuración de Docker (Horizon + pgvector)

Contexto: en esta máquina no tengo permisos para instalar Docker. Los archivos
ya están editados y subidos al repo. Falta ejecutar todo esto desde casa,
donde sí puedo instalar Docker.

## 0. Antes de nada: subir lo que ya está editado

```bash
git add docker-compose.yml docker/php/Dockerfile docker/postgres/init/01-init.sql
git commit -m "Migrar a PHP 8.4, Postgres con pgvector y Horizon" -m "- Dockerfile: base php:8.3-fpm-alpine -> php:8.4-fpm-alpine
- docker-compose.yml: imagen de postgres -> pgvector/pgvector:pg16
- docker-compose.yml: sustituido el servicio queue-worker (queue:work manual) por horizon (php artisan horizon)
- docker/postgres/init/01-init.sql: añadida CREATE EXTENSION IF NOT EXISTS vector;"
git push
```

## 1. Instalar Docker (Ubuntu 24.04)

```bash
sudo apt remove docker docker-engine docker.io containerd runc
sudo apt update
sudo apt install ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc

echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# para no necesitar sudo en cada comando docker:
sudo usermod -aG docker $USER
# cerrar sesión y volver a entrar, o: newgrp docker

# comprobar:
docker --version
docker compose version
```

## 2. Clonar/actualizar el proyecto

```bash
git clone <URL-del-repo>
# o si ya lo tenías clonado en esta máquina de casa:
cd "AI Mail Automation Platform"
git pull
```

## 3. Variables de entorno

```bash
cp .env.docker.example .env
# rellenar/ajustar DB_DATABASE, DB_USERNAME, DB_PASSWORD, GRAFANA_USER, etc.
```

## 4. Levantar app + postgres + redis, e instalar Horizon

```bash
docker compose up -d app postgres redis
docker compose exec app composer require laravel/horizon
docker compose exec app php artisan horizon:install
docker compose exec app php artisan migrate
```

## 5. Recrear el volumen de Postgres (necesario por el cambio a pgvector)

⚠️ Esto borra los datos actuales de esa base de datos si ya existían.

```bash
docker compose down
docker volume rm nombre-proyecto_postgres-data
docker compose up -d --build
```

(el nombre exacto del volumen puede variar; para verlo antes de borrar: `docker volume ls`)

## 6. Comprobar que todo arranca bien

```bash
docker compose ps
docker compose logs -f horizon
```

Dentro de `psql`, comprobar que la extensión vector quedó instalada:
```bash
docker compose exec postgres psql -U app -d app -c "\dx"
```
Debería aparecer `vector` en el listado.

## 7. Pendiente opcional (no bloqueante)

- Proteger la ruta `/horizon` con una Gate en `app/Providers/HorizonServiceProvider.php`
  (por defecto solo es accesible en entorno `local`).
- Ajustar `config/horizon.php` (número de procesos, colas, balanceo) según carga esperada.