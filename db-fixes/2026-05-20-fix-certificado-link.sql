-- ============================================================
-- Fix iDiveBCN dev: enlace "Certificado Médico" en /discover-scuba-diving/
-- Fecha: 2026-05-20
-- ============================================================
-- 1) posts.post_content
UPDATE wpidive_posts SET post_content = REPLACE(post_content,
    'https://idivebcn.com/registro-de-discover scuba divings',
    'https://dev.idivebcn.com/certificado')
WHERE post_content LIKE '%idivebcn.com/registro-de-discover scuba divings%';

UPDATE wpidive_posts SET post_content = REPLACE(post_content,
    'https://idivebcn.com/registro-de-discover scuba diving',
    'https://dev.idivebcn.com/certificado')
WHERE post_content LIKE '%idivebcn.com/registro-de-discover scuba diving%';

UPDATE wpidive_posts SET post_content = REPLACE(post_content,
    'https://dev.idivebcn.com/registro-de-discover scuba divings',
    'https://dev.idivebcn.com/certificado')
WHERE post_content LIKE '%dev.idivebcn.com/registro-de-discover scuba divings%';

UPDATE wpidive_posts SET post_content = REPLACE(post_content,
    'https://dev.idivebcn.com/registro-de-discover scuba diving',
    'https://dev.idivebcn.com/certificado')
WHERE post_content LIKE '%dev.idivebcn.com/registro-de-discover scuba diving%';

-- 2) postmeta._elementor_data
UPDATE wpidive_postmeta SET meta_value = REPLACE(meta_value,
    'https:\\/\\/idivebcn.com\\/registro-de-discover scuba divings',
    'https:\\/\\/dev.idivebcn.com\\/certificado')
WHERE meta_key='_elementor_data' AND meta_value LIKE '%registro-de-discover scuba divings%';

UPDATE wpidive_postmeta SET meta_value = REPLACE(meta_value,
    'https:\\/\\/idivebcn.com\\/registro-de-discover scuba diving',
    'https:\\/\\/dev.idivebcn.com\\/certificado')
WHERE meta_key='_elementor_data' AND meta_value LIKE '%registro-de-discover scuba diving%';

UPDATE wpidive_postmeta SET meta_value = REPLACE(meta_value,
    'https:\\/\\/dev.idivebcn.com\\/registro-de-discover scuba divings',
    'https:\\/\\/dev.idivebcn.com\\/certificado')
WHERE meta_key='_elementor_data' AND meta_value LIKE '%registro-de-discover scuba divings%';

UPDATE wpidive_postmeta SET meta_value = REPLACE(meta_value,
    'https:\\/\\/dev.idivebcn.com\\/registro-de-discover scuba diving',
    'https:\\/\\/dev.idivebcn.com\\/certificado')
WHERE meta_key='_elementor_data' AND meta_value LIKE '%registro-de-discover scuba diving%';

-- 3) icl_strings (WPML)
UPDATE wpidive_icl_strings SET value = REPLACE(value,
    'https://idivebcn.com/registro-de-discover scuba divings',
    'https://dev.idivebcn.com/certificado')
WHERE value LIKE '%idivebcn.com/registro-de-discover scuba divings%';

UPDATE wpidive_icl_strings SET value = REPLACE(value,
    'https://idivebcn.com/registro-de-discover scuba diving',
    'https://dev.idivebcn.com/certificado')
WHERE value LIKE '%idivebcn.com/registro-de-discover scuba diving%';

UPDATE wpidive_icl_strings SET value = REPLACE(value,
    'https://dev.idivebcn.com/registro-de-discover scuba divings',
    'https://dev.idivebcn.com/certificado')
WHERE value LIKE '%dev.idivebcn.com/registro-de-discover scuba divings%';

UPDATE wpidive_icl_strings SET value = REPLACE(value,
    'https://dev.idivebcn.com/registro-de-discover scuba diving',
    'https://dev.idivebcn.com/certificado')
WHERE value LIKE '%dev.idivebcn.com/registro-de-discover scuba diving%';

-- 4) Verificación
SELECT 'posts'      src, SUM(post_content LIKE '%registro-de-discover%') n FROM wpidive_posts
UNION ALL SELECT 'postmeta',   SUM(meta_value LIKE '%registro-de-discover%') FROM wpidive_postmeta
UNION ALL SELECT 'icl_strings', SUM(value LIKE '%registro-de-discover%') FROM wpidive_icl_strings;
