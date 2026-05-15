# Arquitectura iDive BCN Prod

## Dominio
- `idivebcn.com` + `www.idivebcn.com` (redirect www → apex)

## Containers (4)
| Container | Imagen | Función |
|---|---|---|
| `db_id_prod` | mariadb:10.11 | DB `idivedb` |
| `redis_id_prod` | redis:7-alpine | Object cache WP (dedicado, DB 0) |
| `wp_id_prod` | build local (php8.3-fpm + redis + wp-cli) | PHP-FPM |
| `nginx_id_prod` | nginx:alpine | Reverse proxy + fastcgi cache |

## Redes
- `wp_id_prod_net` (interna, driver bridge)
- `traefik_public` (external): solo nginx_id_prod

## Credenciales
Todas en `/opt/wp-idivebcn-prod/.env` (chmod 600):
- DB: `idiveuser` / `idivedb`
- Salts WP (8 únicos, conservados del viejo wp-config)

## Configuración WP
- Table prefix: `wpidive_`
- `WP_REDIS_HOST`: `redis_id_prod` (cambio respecto al viejo `redis` compartido)
- `WP_REDIS_PREFIX`: `idprod_` (mantenido)
- `WP_REDIS_DATABASE`: `0` (antes era `5`)
- `DISABLE_WP_CRON`: true

## Stack del sitio (33 plugins notables)
- E-commerce: WooCommerce + Bookings + Dynamic Pricing + Product Addons + PDF Vouchers + Email Customizer + Side Cart + Permalink Manager + TM Extra Product Options
- Pagos: Redsys Oficial
- WPML: core + WC + media + string (aunque /en/ devuelve 404, sitio ES-only)
- Builder: Elementor + Pro
- Sliders: Nextend Smart Slider 3 Pro
- Forms: Gravity Forms + multilingual + signature + e2pdf
- Email design: Kadence WC Email Designer + WC Email Customizer
- Cache: Redis Cache
- Mail: WP Mail SMTP Pro
- Otros: Google Site Kit, WPS Cleaner, All-in-One WP Migration (+ unlimited)

## Themes
- `hello-elementor` + `hello-theme-child-master` (activo)

## mu-plugins
- `debug-redirect.php`

## Traefik
- Resolver `cloudflare` (DNS challenge)
- Middlewares: `idprod-www-redirect`, `idprod-headers` (X-Forwarded-Proto=https)
- Cert emitido en `/opt/traefik/acme.json`

## Migración (2026-05-15)
- Origen: `/opt/wp-idivebcn-prod/` en VPS Contabo (194.163.173.218)
- Destino: `/opt/wp-idivebcn-prod/` en VPS Netcup (152.53.127.100)
- Tamaño: 3.1G origen → 826M wp-content + 278M db
- Backup: dump SQL (5.1M gz) + tar.gz wp-content (333M)
- Excluidos: cache/, upgrade*/, ai1wm-backups/, debug.log, temp-write-test-*
- DNS Cloudflare apex+www movidos (proxy naranja)
- DB restaurada: 117 tablas, siteurl/home correctos
- Verificación pre-DNS: HTTP 200 home, /tienda/, /wp-admin/, Redis 388 keys

## Pendiente
- Crear repo GitHub (junto con SuperDive)
