-- ============================================================
--  CRECER — LA JUGADA SE EJECUTA (y se cierra sola)
--  migrations/2026-08-12_crecer_jugada_ejecuta.sql
--
--  EL PROBLEMA (2026-08-12): las jugadas del plan eran TEXTO. El dueño
--  leía "publica el bizcocho tres veces" y tenía que hacerlo él... y
--  encima marcar un checkbox para declarar que lo hizo. Si vendemos
--  automatización, hay que dar automatización.
--
--  PRINCIPIO: una jugada NO se marca — se CUMPLE SOLA. La verdad la da
--  la evidencia del sistema (piezas publicadas con su permalink real en
--  crecer_publicaciones), no la declaración del dueño. Él solo confirma
--  lo que pasa FUERA de Crecer (poner el boost, hablar con el vecino).
--
--  Para eso cada jugada declara su CONTRATO:
--   · clase       — qué naturaleza tiene y por tanto cómo cierra
--   · piezas_meta — cuánto trabajo es (3 posts = 3)
--   · formato     — en qué formato se produce
--
--  Las tres clases:
--   · produccion   → la ejecuta el corillo; cierra cuando sus piezas
--                    están publicadas. NADIE marca nada.
--   · accion_dueno → pasa fuera de Crecer (boost, alianza, foto);
--                    cierra cuando el dueño confirma. Lo ÚNICO que marca.
--   · regla        → no es una jugada, es una forma de operar
--                    ("contesta el mismo día"). NO cierra nunca y por eso
--                    NO puede trabar el plan: se cuenta aparte.
--
--  Y `crecer_meta_jobs`: producir 3 piezas toma minutos (escribir + arte),
--  así que se encola y un worker lo corre por detrás. La pantalla nunca
--  se queda colgada esperando (misma idea que crecer_sala_jobs).
--
--  BD COMPARTIDA (encuentralo_db). Correr DESPUÉS de
--  2026-08-12_crecer_meta_plan.sql. Correr en phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. El contrato de cada jugada ───────────────────────────
--  (error 1060 "Duplicate column" = ya la corriste; seguro ignorarlo)
ALTER TABLE crecer_meta_tactica
  ADD COLUMN clase       VARCHAR(16) NOT NULL DEFAULT 'produccion'
      COMMENT 'produccion | accion_dueno | regla — decide CÓMO cierra',
  ADD COLUMN piezas_meta TINYINT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'cuántas piezas pide esta jugada (0 = no es de producir)',
  ADD COLUMN formato     VARCHAR(16) NOT NULL DEFAULT 'post'
      COMMENT 'post | reel | carrusel | historia | mixto',
  ADD COLUMN ejecutado_at DATETIME NULL
      COMMENT 'cuándo el corillo produjo el trabajo de esta jugada';

-- Las jugadas que ya existían: se les infiere la clase por quién ejecuta,
-- para que el historial no quede a medias.
UPDATE crecer_meta_tactica
   SET clase = CASE WHEN quien = 'dueno' THEN 'accion_dueno' ELSE 'produccion' END
 WHERE clase = 'produccion' AND quien = 'dueno';

-- Las de tipo 'operacion' son reglas de cómo operar, no tareas que cierran.
UPDATE crecer_meta_tactica
   SET clase = 'regla'
 WHERE tipo = 'operacion';

-- A las de producción viejas se les pone una meta razonable de piezas
-- (no tenían el dato; 2 es el promedio de lo que pedía la Estratega).
UPDATE crecer_meta_tactica
   SET piezas_meta = 2
 WHERE clase = 'produccion' AND piezas_meta = 0;

-- ── 2. La cola de ejecución ─────────────────────────────────
--  Producir las piezas de una jugada tarda 1-3 min: se encola y el worker
--  la corre por detrás. El front sondea. Estados: queued→working→done|failed.
CREATE TABLE IF NOT EXISTS crecer_meta_jobs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id    INT UNSIGNED NOT NULL,
    tactica_id  INT UNSIGNED NOT NULL,
    estado      ENUM('queued','working','done','failed') NOT NULL DEFAULT 'queued',
    resultado   TEXT NULL      COMMENT 'lo que el corillo reporta al dueño, en cristiano',
    creadas     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'piezas producidas',
    recicladas  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'de esas, cuántas salieron de material que YA existía',
    error_msg   TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mjob_marca (marca_id, estado),
    KEY idx_mjob_tactica (tactica_id),
    CONSTRAINT fk_mjob_marca   FOREIGN KEY (marca_id)   REFERENCES crecer_marca(id)         ON DELETE CASCADE,
    CONSTRAINT fk_mjob_tactica FOREIGN KEY (tactica_id) REFERENCES crecer_meta_tactica(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
