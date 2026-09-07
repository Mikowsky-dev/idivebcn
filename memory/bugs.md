# Bugs y soluciones — iDive BCN Prod

## [2026-09-07] Bono de regalo Discover: PDF en blanco (reportado como "no llegan los emails")

**Contexto:** Reportado como "no se envían los emails con el enlace del PDF del bono"
del producto 58 «Regalo Discover Scuba Diving». Los emails SÍ se enviaban: el log de
WP Mail SMTP (`wpidive_wpmailsmtp_emails_log`) muestra 2 correos por pedido
(comprador + destinatario) enviados por Resend sin errores y con adjunto. Lo que
fallaba era el PDF adjunto, que salía en blanco.

**Causa:** El 22/04/2026 se subió un fichero **PDF** (adjunto 4971,
`application/pdf`) como imagen de fondo de la plantilla de bono 99 «Bono Bautizo».
Quedó el primero en `_image_ids`, y como la ficha de producto preselecciona la
primera imagen (`key($images)`, functions-template.php:80), todos los bonos lo
usaban. Un adjunto PDF no tiene `width`/`height` en su metadata, y el generador
calcula el tamaño de página con esos valores → `MediaBox [0 0 0 0]`.
PDFs de 2 KB en blanco desde entonces (antes ~750 KB).

**Decisión:** `_image_ids` = solo el PNG 4974; `_voucher_image_dpi` 307 → 150
(el PNG es el PDF de diseño rasterizado a 150 dpi; da 419x297 pt = A6 apaisado,
el tamaño nativo). Reposicionado `_voucher_number_pos` a `518,543,210,26` + align
`left` porque el código caía fuera de la caja negra del diseño.

**Ubicación:** `db-fixes/2026-09-07-fix-bono-regalo-discover.sql` (documentación
completa, valores de rollback y verificación).

**Notas:**
- **Trampa al regenerar bonos:** el generador no renderiza en local, hace un
  `wp_remote_get()` a `https://idivebcn.com/?post_type=wc_voucher&p=<id>&voucher_key=...`
  y pasa ese HTML a dompdf. nginx cachea esa URL, así que tras tocar la plantilla
  hay que purgar la entrada o el PDF sale idéntico. Purga:
  `docker exec nginx_id_prod sh -c 'grep -rl "p=<id>" /var/cache/nginx/fastcgi | xargs rm -f'`
- Escribir postmeta por SQL directo deja Redis obsoleto: usar `wp eval` / wp-cli.
- **No es un bug:** `_message_is_enabled=1` con `_message_pos` vacío hace que la
  dedicatoria se renderice con `display:none`. Es intencionado: en el bono solo
  van el nombre del destinatario y el código. La dedicatoria viaja en el cuerpo
  del email al destinatario (email-voucher-recipient.php:171-230). No tocar.
- Los emails de bono salen en inglés en una tienda en español (cadenas del plugin
  sin traducir).
- El texto "Este vale es válido hasta fin de 2022" está quemado en la imagen de
  fondo. Pendiente de rediseño por parte del cliente (invierno 2026).
