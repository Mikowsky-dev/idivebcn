# iDive BCN

WordPress + WooCommerce + WPML + Elementor para [idivebcn.com](https://idivebcn.com).

## Stack
- WordPress (PHP 8.3-FPM, build custom con Redis ext + WP-CLI)
- MariaDB 10.11
- Redis 7 (object cache dedicado)
- Nginx Alpine (reverse proxy + fastcgi cache)
- Traefik (TLS via Cloudflare DNS challenge)

## Entornos
- **prod**: `idivebcn.com` — rama `main`, VPS Netcup `152.53.127.100`, dir `/opt/wp-idivebcn-prod/`
- **dev**: `dev.idivebcn.com` — rama `dev`, VPS Contabo viejo `194.163.173.218`, dir `/opt/wp-idivebcn-dev/` (pendiente de migración)

## Qué hay versionado
- `docker-compose.yml`, `Dockerfile`
- `nginx/default.conf`, `php/php.ini`, `php/www-custom.conf`
- `html/wp-content/themes/hello-theme-child-master/` (child theme con código custom)
- `html/wp-content/mu-plugins/` (mu-plugins custom)

## Qué NO hay versionado (en `.gitignore`)
- WP core (`html/wp-admin/`, `html/wp-includes/`, `html/*.php`)
- `html/wp-config.php` (contiene credenciales)
- `.env` (credenciales DB/Redis/etc.)
- `db/` (datos MariaDB)
- Plugins, uploads, languages, caches, debug logs
- Backups locales

## Despliegue
```bash
cd /opt/wp-idivebcn-prod
git pull origin main
docker compose build && docker compose up -d
```

## Restaurar desde backup
Los backups diarios de la BD se guardan en OVH SFTP cluster130.
