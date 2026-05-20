-- Fix enlace "Cuestionario médico" en idivebcn.com (PROD)
-- Mismo bug que en SuperDive (db-fixes/2026-05-19-fix-certificado-link-prod.sql)
-- pero con diferencias propias de iDive:
--   - Solo 3 filas afectadas, todas en `wpidive_postmeta._elementor_data`
--     (templates elementor_library IDs 1639, 1973, 3246).
--   - Texto en MINÚSCULAS: 'registro-de-discover scuba diving' (no capitalizado).
--   - Sin filas en `wpidive_posts` ni `wpidive_icl_strings` (no requiere
--     UPDATE allí, solo verificación final).
--
-- Origen del bug: enlace apuntaba a /registro-de-discover scuba diving/
-- (URL inexistente con espacios sin codificar, 404).
-- Destino correcto: https://idivebcn.com/certificado/ (página existente, HTTP 200).
--
-- ORDEN IMPORTA: plural (Divings) primero, singular (Diving) después.
-- En prod la variante plural devuelve 0 rows, pero la mantenemos por idempotencia
-- con el patrón validado en dev/SuperDive.
--
-- Encoding: las 3 filas están en `_elementor_data` (JSON-escapado con \/).

-- =====================================================================
-- wpidive_postmeta._elementor_data (JSON-escapado \/, minúsculas)
-- =====================================================================
UPDATE wpidive_postmeta SET meta_value = REPLACE(meta_value,
    'https:\\/\\/idivebcn.com\\/registro-de-discover scuba divings',
    'https:\\/\\/idivebcn.com\\/certificado')
WHERE meta_key='_elementor_data'
  AND meta_value LIKE '%idivebcn.com\\\\/registro-de-discover scuba divings%';

UPDATE wpidive_postmeta SET meta_value = REPLACE(meta_value,
    'https:\\/\\/idivebcn.com\\/registro-de-discover scuba diving',
    'https:\\/\\/idivebcn.com\\/certificado')
WHERE meta_key='_elementor_data'
  AND meta_value LIKE '%idivebcn.com\\\\/registro-de-discover scuba diving%';

-- =====================================================================
-- Verificación final (debería devolver 0 en las 3 filas, case-insensitive)
-- =====================================================================
SELECT 'posts' AS src, SUM(post_content LIKE '%registro-de-discover%') AS n FROM wpidive_posts
UNION ALL SELECT 'postmeta', SUM(meta_value LIKE '%registro-de-discover%') FROM wpidive_postmeta
UNION ALL SELECT 'icl_strings', SUM(value LIKE '%registro-de-discover%') FROM wpidive_icl_strings;
