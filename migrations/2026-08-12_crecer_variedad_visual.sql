-- ============================================================
--  CRECER — MEMORIA VISUAL ANTI-REPETICIÓN (matar el AI slop)
--  migrations/2026-08-12_crecer_variedad_visual.sql
--
--  EL BUG QUE ARREGLA (reportado 2026-08-12): las imágenes de un
--  mismo negocio salían todas iguales cambiando solo el objeto —
--  "una mano de tez trigueña agarrando una taza de café", después
--  "...agarrando una varita", después "...agarrando un móvil".
--  Cosmética distinta, misma idea. Eso es AI slop y quema clientes.
--
--  CAUSA: el pipeline de arte (includes/direccion_arte.php) corre sin
--  memoria. Cada imagen se diseña como si fuera la primera, así que el
--  modelo cae siempre en su composición favorita (su atractor).
--
--  ARREGLO: cada imagen deja una HUELLA (qué lente/encuadre usó, qué
--  sujeto). Antes de diseñar la próxima, el Director ve las últimas y
--  tiene PROHIBIDO repetirlas — y se le asigna por rotación un lente
--  que no haya usado recientemente.
--
--  IMPORTANTE: el ESTILO de marca (luz, paleta, tratamiento por
--  industria) NO rota — eso es identidad. Lo que rota es la IDEA.
--
--  BD COMPARTIDA (encuentralo_db). Idempotente. Correr en phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_visual_huella (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id     INT UNSIGNED NOT NULL,
    contenido_id INT UNSIGNED NULL      COMMENT 'la pieza para la que se hizo (si aplica)',
    lente        VARCHAR(40) NOT NULL   COMMENT 'la aproximación visual asignada (banco de variedad_visual.php)',
    sujeto       VARCHAR(190) NULL      COMMENT 'primary_subject del brief — lo que se ve',
    composicion  VARCHAR(190) NULL      COMMENT 'encuadre/cámara del brief',
    escenario    VARCHAR(190) NULL      COMMENT 'background del brief',
    resumen      VARCHAR(255) NULL      COMMENT 'la idea en una línea, para enseñársela al Director como "ya hiciste esto"',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hue_marca (marca_id, created_at),
    KEY idx_hue_lente (marca_id, lente),
    CONSTRAINT fk_hue_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
