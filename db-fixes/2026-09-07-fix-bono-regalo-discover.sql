-- Fix bono de regalo en blanco: "Regalo Discover Scuba Diving" (PROD)
--
-- SÍNTOMA REPORTADO
--   "No se envían los emails con el enlace del PDF del bono".
--   En realidad los emails SÍ se envían (Resend, sin errores en el log de
--   WP Mail SMTP: 2 correos por pedido, comprador + destinatario, con adjunto).
--   Lo que fallaba es el PDF adjunto: salía en blanco.
--
-- CAUSA RAÍZ
--   El 2026-04-22 14:21 se subió a la plantilla de bono "Bono Bautizo"
--   (post 99, wc_voucher_template) un fichero PDF como imagen de fondo:
--     - 4971  Vale-open-water-SuperDive-copia.pdf  application/pdf  (SIN width/height)
--     - 4974  Vale-open-water-idive.png            image/png  873x619  (el correcto,
--             subido un minuto después, 14:22)
--   La plantilla quedó con `_image_ids = a:2:{i:0;i:4971;i:1;i:4974;}`.
--
--   Al haber 2 imágenes, la ficha de producto muestra un selector de radios
--   "Voucher Option 1 / 2" y la opción marcada por defecto es la PRIMERA
--   (`key( $images )` en src/functions/wc-pdf-product-vouchers-functions-template.php:80),
--   es decir el PDF. El generador calcula el tamaño de página con
--   `$image['width'] / $image['height']` (src/class-wc-pdf-product-vouchers-pdf-generator.php:87),
--   que en un adjunto application/pdf no existen  ->  página de 0 x 0 pt.
--
--   Evidencia: los PDF generados desde entonces pesan ~2 KB con
--   `MediaBox [0 0 0 0]`; los anteriores pesaban ~750 KB con
--   `MediaBox [0 0 595.44 419.28]` (A5 apaisado).
--
--   Vouchers afectados: 4979, 4986, 5063, 5183, 5286
--   (pedidos 4978, 4985, 5062, 5182, 5285). Se reparan en la fase 3.
--
-- DPI
--   El `_voucher_image_dpi` valía 307, heredado del fondo antiguo
--   (1970 Webbonoiidve.jpg, 2481x1747). Con el PNG nuevo de 873x619 el bono
--   habría salido de 72 x 51 mm. El PNG es el PDF de diseño rasterizado a
--   150 dpi (873 px / (419 pt / 72) = 150.0), y el PDF original mide
--   419 x 298 pt = A6 apaisado. Por eso se fija DPI = 150, que reproduce
--   exactamente el tamaño de diseño: 419.04 x 297.12 pt = 148 x 105 mm.
--   Tamaño de página = px * 72 / dpi (convert_pixels_to_points, línea 289).
--
-- APLICACIÓN
--   NO ejecutar este SQL directamente: el sitio usa Redis object cache y las
--   escrituras en postmeta por SQL dejarían la caché obsoleta. El fix se
--   aplicó con wp-cli (invalida la caché correctamente):
--
--     docker exec wp_id_prod wp eval '
--       update_post_meta(99, "_image_ids", [4974]);
--       update_post_meta(99, "_voucher_image_dpi", 150);
--     ' --allow-root
--
--   El SQL de abajo queda como documentación del cambio exacto y para
--   verificación / reproducción en dev (allí, hacer `wp cache flush` después).
--
-- VALORES PREVIOS (para rollback)
--   _image_ids        = a:2:{i:0;i:4971;i:1;i:4974;}
--   _voucher_image_dpi = 307
--   _thumbnail_id      = 4974  (ya era correcto, no se toca)

-- =====================================================================
-- Plantilla de bono 99 "Bono Bautizo"
-- =====================================================================

-- Dejar como único fondo el PNG correcto (4974) y quitar el PDF (4971).
-- Al quedar una sola imagen, la ficha de producto deja de mostrar el
-- selector y renderiza un input hidden con value="4974".
UPDATE wpidive_postmeta
   SET meta_value = 'a:1:{i:0;i:4974;}'
 WHERE post_id = 99
   AND meta_key = '_image_ids'
   AND meta_value = 'a:2:{i:0;i:4971;i:1;i:4974;}';

-- Ajustar el DPI al del fondo nuevo (150) para recuperar el tamaño de diseño.
UPDATE wpidive_postmeta
   SET meta_value = '150'
 WHERE post_id = 99
   AND meta_key = '_voucher_image_dpi'
   AND meta_value = '307';

-- =====================================================================
-- Verificación
-- =====================================================================
-- Esperado: a:1:{i:0;i:4974;}  |  150  |  4974
SELECT meta_key, meta_value
  FROM wpidive_postmeta
 WHERE post_id = 99
   AND meta_key IN ('_image_ids', '_voucher_image_dpi', '_thumbnail_id');

-- No debe quedar ningún bono nuevo apuntando al adjunto PDF 4971:
SELECT p.ID, p.post_title, p.post_date
  FROM wpidive_posts p
  JOIN wpidive_postmeta m ON m.post_id = p.ID AND m.meta_key = '_thumbnail_id'
 WHERE p.post_type = 'wc_voucher'
   AND m.meta_value = 4971
 ORDER BY p.ID DESC;
