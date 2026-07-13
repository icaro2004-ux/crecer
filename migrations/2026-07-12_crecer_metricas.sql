-- ============================================================
--  CRECER — Métricas de rendimiento por post (alcance/interacciones)
--  migrations/2026-07-12_crecer_metricas.sql
--
--  Cachea lo que Meta (IG/FB) sabe de CADA post ya publicado:
--  alcance, me gusta, comentarios, guardados, compartidos. Se
--  refresca por cron (scripts/cron_metricas.php) o con el botón
--  "Actualizar" en Resultados. Una fila por (contenido, plataforma).
--
--  Regla de oro (igual que el resto del centro de mando): NUNCA
--  números falsos. Si Meta no devolvió una métrica, la columna
--  queda NULL y la UI muestra "—", no un cero inventado.
--
--  BD COMPARTIDA (encuentralo_db). InnoDB, utf8mb4_unicode_ci.
--  Idempotente: IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_metricas (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contenido_id    INT UNSIGNED NOT NULL,
    marca_id        INT UNSIGNED NOT NULL,
    plataforma      ENUM('instagram','facebook') NOT NULL,
    external_id     VARCHAR(80) NULL          COMMENT 'media_id (IG) o post_id (FB) que devolvió Meta',
    alcance         INT UNSIGNED NULL         COMMENT 'reach — personas alcanzadas',
    impresiones     INT UNSIGNED NULL         COMMENT 'impressions (opcional, no todas las cuentas)',
    me_gusta        INT UNSIGNED NULL         COMMENT 'likes / reactions',
    comentarios     INT UNSIGNED NULL,
    guardados       INT UNSIGNED NULL         COMMENT 'saved (solo IG)',
    compartidos     INT UNSIGNED NULL         COMMENT 'shares',
    interacciones   INT UNSIGNED NULL         COMMENT 'suma de like+coment+guard+comp (o total_interactions)',
    crudo           LONGTEXT NULL             COMMENT 'respuesta cruda de Meta (evidencia/debug)',
    actualizado_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_met_post_red (contenido_id, plataforma),
    KEY idx_met_marca (marca_id),
    KEY idx_met_actualizado (actualizado_at),
    CONSTRAINT fk_met_contenido FOREIGN KEY (contenido_id) REFERENCES crecer_contenido(id) ON DELETE CASCADE,
    CONSTRAINT fk_met_marca     FOREIGN KEY (marca_id)     REFERENCES crecer_marca(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
