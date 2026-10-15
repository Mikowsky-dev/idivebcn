# Calendario de cierres — iDive BCN

Herramienta web para que el centro cierre y reabra días del calendario de reserva sin entrar
al admin de WordPress. **Ya está en producción en SuperDive** desde el 15-oct-2026
(`https://superdivetossa.com/calendario-cierres/`), validada por Miquel. Aquí está **medida y
pendiente de implementar**.

- **Guía completa de replicación:** `40-runbooks/replicar-calendario-de-cierres.md` del vault.
- **Código a copiar:** repo `Mikowsky-dev/superdivetossa`, rama `main`, commit `047306b`.
  Ocho ficheros en `html/wp-content/mu-plugins/mikowsky-cierres*`. iDive es **otro repo**, así
  que es copiar ficheros entre repos, no un merge.
- **Memoria compartida con SuperDive** (decisión de Miquel, 15-oct-2026): las dos webs se
  trabajan juntas a menudo, así que lo que se aprenda en una se escribe también en la otra.
  Contraparte: `/opt/wp-superdive-dev/memory/` y la memoria local del agente de superdive.

---

## Lo medido en iDive el 15-oct-2026 (dev, contenedores levantados a petición)

### Un solo producto con calendario

```
56   Discover Scuba Diving Booking   fmt="0" (d/m/Y)   date_internal_name=["Date"]  -> idx 0
     fechas ya cerradas: 24/12/2025,25/12/2025,26/12/2025,31/12/2025,01/01/2026
```

Es el **único** producto del sitio con elemento de fecha de EPO. Eso simplifica dos cosas: el
mapa de actividades tiene una sola entrada, y el filtro global de fuente única (ver abajo) no
tiene daño colateral posible.

Mapa a poner en `mikowsky-cierres.php`:

```php
const MIKOWSKY_CIERRES_ACTIVITIES = [
    'dsd' => [ 'product' => 56, 'label' => 'Discover Scuba Diving', 'element' => null ],
];
```

### Las traducciones HEREDAN — es el caso bueno

| | id | `tm_meta` | `tm_meta_wpml` |
|---|---|---|---|
| es | 56 | tiene las fechas | — |
| en | 1888 | — | **— (no tiene valor propio)** |

Verificado en el front, que es lo que vale: `/discover-scuba-diving-booking-en/?lang=en`
devuelve **las mismas fechas** que el español. O sea que **escribir solo el producto base
cubre ES y EN**.

Aun así conviene poner el filtro de fuente única que lleva el plugin: hoy no hace falta, pero
deja el sitio inmune si alguien edita el builder de la traducción desde el admin y le crea
valor propio. En SuperDive ese caso **sí** se daba y habría dejado días reservables en catalán
e inglés sin que nadie se enterase.

### ⚠️ Correcciones a `arquitectura.md`

Dos datos de esa memoria estaban caducados y habrían despistado:

1. **«sitio ES-only» es falso.** Hay **tres idiomas activos**: `es`, `en` y `fr`. Negociación
   por query string (`?lang=`, tipo 3). Lo que sí falla es la forma `/en/`, que devuelve 404 —
   de ahí venía la confusión.
2. **«Pendiente: crear repo GitHub» ya está hecho**: `Mikowsky-dev/idivebcn`, rama `dev` aquí
   y `main` en producción.

### ⚠️ Trampa al verificar: la traducción tiene slug propio

```
/discover-scuba-diving-booking/            -> 200, muestra las fechas   (es)
/discover-scuba-diving-booking/?lang=en    -> 404                        ← engaña
/discover-scuba-diving-booking-en/         -> 404                        ← engaña
/discover-scuba-diving-booking-en/?lang=en -> 200, muestra las fechas   (en)
```

Hacen falta **las dos cosas**: el slug traducido **y** el parámetro. Pedir solo una devuelve
404 y parece que el día no está bloqueado cuando sí lo está. En SuperDive pasa lo mismo pero
con 301 en vez de 404, que al menos se puede seguir con `curl -L`; aquí ni eso.

**Al verificar un cierre, usar siempre la URL completa de cada idioma**, sacada de
`get_permalink()` de cada traducción, no construida a mano.

---

## ⚠️ Lo que hay que arreglar en este entorno ANTES de aplicar nada

Medido el 15-oct. Son fallos de iDive, no del calendario, pero el calendario los hace más
graves porque mete una herramienta de cara al cliente:

### 1. El dev está abierto de par en par

`wp-idivebcn-dev` **no tiene basic auth, ni secret-scanner shield, ni guards de correo o de
compras**. Su único mu-plugin es `debug-redirect.php`. Si se levanta con una copia de la base
de datos de producción, queda en internet con la configuración de pagos y correos de prod: un
visitante despistado puede creer que ha reservado una inmersión que no existe.

Lo que se montó en SuperDive dev y hay que replicar aquí está en §2.5 del runbook del vault:
basic auth en nginx (no en Traefik, que obligaría a recrear el contenedor), `X-Robots-Tag` por
mu-plugin, `robots.txt`, `blog_public=0`, y un mu-plugin que deja cero pasarelas de pago.

⚠️ Esos mu-plugins **deben comprobar el host**: están en el repo, y el repo es lo que se
despliega. Sin esa comprobación llegan a producción en el siguiente pull y tumban la tienda.

### 2. Los headers de seguridad no llegan al navegador

Ni en dev ni en producción:

```
curl -sSI https://idivebcn.com/ | grep -iE "x-content-type|x-frame|referrer"   # vacío
```

`add_header` no se hereda en un `location` que define el suyo, y el de PHP define
`X-Cache-Status`. Causa, arreglo y estado por sitio en
`40-runbooks/headers-nginx-no-heredados.md` del vault.

### 3. Comprobar la ruta de nginx-helper

En SuperDive el botón «Purge Cache» llevaba tiempo purgando un directorio vacío **y
reportando éxito**, porque la constante se define con el default del plugin y no con el ajuste
del panel. Comprobar aquí:

```bash
docker exec -u www-data wp_id_dev wp --path=/var/www/html eval \
  'echo (defined("RT_WP_NGINX_HELPER_CACHE_PATH") ? RT_WP_NGINX_HELPER_CACHE_PATH : "no definida")."\n";'
```

---

## Cosas que ya sabemos y no hay que volver a descubrir

- **La caché fastcgi de nginx no se puede purgar desde PHP**: los ficheros son de `nginx`
  (uid 101) con directorios 0700 y php-fpm corre como `www-data`. Y el snippet que circulaba
  **lanza** una excepción, que en un endpoint REST es un 500 *después* de haber guardado. El
  `find -delete` funciona porque va como root desde el host. Hasta 60 min de desfase en la web
  pública; la herramienta avisa al cliente. Sin riesgo de sobreventa: EPO revalida las fechas
  al añadir al carrito.
- **`wp_slash()` obligatorio** antes de `update_post_meta($pid,'tm_meta',$m)`, o se comen las
  barras del resto del `tm_meta` y se rompe el formulario del producto.
- **Nada de flags de «ya lo hice»** para crear roles o reglas de reescritura: comprobar el
  estado real y arreglarlo si falta. Un flag dejó la página en 404 permanente en SuperDive.
- **`main` y `dev` son ramas de entornos distintos**, no una promoción: prod tiene
  `nginx/default.conf` donde dev tiene `default.conf.template`. Se promociona **por ficheros en
  un worktree**, nunca con `git merge`.
- **Lo que se arregle en producción, versionarlo el mismo día.**
- **Desplegar a producción exige confirmación explícita de Miquel.**
