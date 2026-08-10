-- ============================================================
--  FIX: métricas NUNCA se guardaban — "Illegal mix of collations"
--  2026-08-09 · Descubierto con _cache.php?test=metricas&gasta=1
--
--  crecer_metricas quedó en utf8mb4_unicode_ci (se creó después del
--  estándar del 2026-06-20, que puso todo en utf8mb4_general_ci).
--  Al unir m.plataforma = p.plataforma, MySQL revienta con error 1267
--  y metricas_refrescar_insights devolvía n=0 en silencio.
--
--  Correr en phpMyAdmin (idempotente — repetirlo no daña):
-- ============================================================

ALTER TABLE crecer_metricas      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
ALTER TABLE crecer_publicaciones CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- 2026-08-10 · PREVENTIVO: crecer_carrusel también nació unicode_ci (misma
-- familia del bug). Hoy no revienta (sus joins son por id), pero se alinea
-- al estándar antes de que alguien escriba el join de texto que explote.
ALTER TABLE crecer_carrusel      CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- Verificación (ambas deben decir utf8mb4_general_ci):
-- SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
--  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('crecer_metricas','crecer_publicaciones');
