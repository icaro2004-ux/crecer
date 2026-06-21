-- ============================================================
--  CRECER — Fix de colación en PRODUCCIÓN (Hostinger)
--  2026-06-20_fix_collation_prod.sql
--
--  Problema: las tablas crecer_* se crearon en utf8mb4_unicode_ci,
--  pero la BD de Hostinger (usuarios, etc.) usa utf8mb4_general_ci.
--  Al comparar strings entre ellas → "Illegal mix of collations".
--
--  Solución: alinear las tablas crecer_* a utf8mb4_general_ci
--  (la misma del resto de la BD de prod). Correr UNA vez en phpMyAdmin.
--  Las FKs son sobre columnas INT, así que la conversión es segura.
-- ============================================================

ALTER TABLE crecer_marca          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_ia_log         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_calendario     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_contenido      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_mensajes       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_ordenes        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_logos          CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_graficas       CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_eventos        CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_planes         CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_suscripciones  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_conexiones     CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_publicaciones  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
