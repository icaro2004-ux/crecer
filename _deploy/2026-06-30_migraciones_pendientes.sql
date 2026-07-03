-- ============================================================
-- CRECER — Migraciones pendientes en PROD (correr de una vez)
-- phpMyAdmin → BD u785811842_encuentralo → pestaña SQL → pegar → Continuar
-- Todas son seguras de correr aunque alguna ya se haya corrido.
-- ============================================================


-- ########## 2026-06-25_marca_tono.sql ##########
-- ============================================================
--  CRECER — Tono de voz por marca (4 ejes 0-100 + preset)
--  Alimenta el prompt del CREADOR (La Creativa) para que cada
--  post respete la voz que el dueño definió con los sliders.
-- ============================================================
ALTER TABLE crecer_marca
  ADD COLUMN IF NOT EXISTS tono_boricua TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER voz,
  ADD COLUMN IF NOT EXISTS tono_formal  TINYINT UNSIGNED NOT NULL DEFAULT 30 AFTER tono_boricua,
  ADD COLUMN IF NOT EXISTS tono_venta   TINYINT UNSIGNED NOT NULL DEFAULT 55 AFTER tono_formal,
  ADD COLUMN IF NOT EXISTS tono_ingenio TINYINT UNSIGNED NOT NULL DEFAULT 60 AFTER tono_venta,
  ADD COLUMN IF NOT EXISTS tono_preset  VARCHAR(20) NULL              AFTER tono_ingenio;


-- ########## 2026-06-28_usuarios_registro_minimo.sql ##########
-- ============================================================
--  CRECER — Registro mínimo (nombre · email · contraseña)
--  El WhatsApp se pide tras la muestra / en activación, y el
--  municipio pertenece al NEGOCIO (crecer_marca), no al usuario
--  (un usuario podría manejar negocios de pueblos distintos).
--  → usuarios.telefono y usuarios.municipio_id pasan a NULLABLE.
--  La FK fk_usr_municipio no estorba: NULL está exento de FK.
--  Idempotente en la práctica (re-correr no daña datos).
-- ============================================================
ALTER TABLE usuarios MODIFY COLUMN telefono     VARCHAR(20)          NULL;
ALTER TABLE usuarios MODIFY COLUMN municipio_id TINYINT(3) UNSIGNED  NULL;


-- ########## 2026-06-28_congelar_despegar.sql ##########
-- ============================================================
--  CRECER — Congelar el plan "Despegar" (decisión de producto)
--  La activación vende UN solo producto: "Activar Crecer".
--  Despegar vendía features no-vivas (piloto automático, clientela,
--  analítica) → se congela hasta que esas capacidades estén vivas.
--  precios.php y bienvenida.php ya filtran por activo=1, así que
--  esto lo saca del flujo de activación sin tocar código.
--  Reversible: UPDATE ... SET activo=1 WHERE slug='despegar'.
-- ============================================================
UPDATE crecer_planes SET activo = 0 WHERE slug = 'despegar';


-- ########## 2026-06-28_backfill_publicado_at.sql ##########
-- ============================================================
--  CRECER — Backfill de publicado_at (centro de mando / Resultados)
--  Posts ya 'publicado' que quedaron con publicado_at NULL (el marcar
--  manual no lo completaba). La analítica de "publicados este mes"
--  depende de publicado_at, así que se rellena con un PROXY prudente:
--    1) fecha_programada, si ya pasó (lo más cercano a cuándo salió);
--    2) si no, created_at.
--  NUNCA se usa updated_at (no refleja la fecha de publicación).
--  Idempotente: solo toca filas con publicado_at IS NULL.
-- ============================================================
UPDATE crecer_contenido
SET publicado_at = COALESCE(
    CASE WHEN fecha_programada IS NOT NULL AND fecha_programada <= NOW()
         THEN fecha_programada END,
    created_at
)
WHERE estado = 'publicado' AND publicado_at IS NULL;


-- ########## 2026-06-29_crecer_memoria.sql ##########
-- ============================================================
--  CRECER — El Cerebro del Negocio (Business Memory) · tabla base
--  migrations/2026-06-29_crecer_memoria.sql
--
--  Memoria viva del negocio: conocimiento estructurado que la IA
--  acumula y consulta (RAG) antes de actuar. Generaliza lo que hoy
--  hace `glosario` (texto plano) hacia memorias con confianza, peso,
--  vigencia, supersede y control del usuario.
--
--  FASE 1 = solo dominio 'marketing' (datos reales hoy). Los dominios
--  finanzas/ventas/operaciones existen en el modelo pero NO se activan
--  hasta que haya datos (no inventar memorias en cuartos vacíos).
--  Idempotente: CREATE TABLE IF NOT EXISTS.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crecer_memoria (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id          INT UNSIGNED NOT NULL,

  -- Qué tipo de conocimiento es (FASE 1: preferencia/tono/marca/decision/
  -- patron/conversacion/hito). finanzas/ventas/operaciones = fase 2.
  tipo              VARCHAR(30) NOT NULL,
  dominio           VARCHAR(20) NOT NULL DEFAULT 'marketing',

  -- El conocimiento, en lenguaje humano.
  titulo            VARCHAR(180) NOT NULL,         -- corto, para tarjetas
  detalle           TEXT NOT NULL,                 -- la memoria completa
  porque            TEXT DEFAULT NULL,             -- el "porqué"/contexto (clave)

  -- De dónde salió.
  fuente            VARCHAR(40) DEFAULT NULL,       -- edicion|rechazo|aprobacion|tono|intake|asistente|consolidador|evento
  fuente_id         BIGINT UNSIGNED DEFAULT NULL,   -- ref a contenido/ia_log/evento

  -- Cuánta certeza y cuánto debe influir en los prompts.
  confianza         TINYINT UNSIGNED NOT NULL DEFAULT 50,   -- 0-100
  peso              TINYINT UNSIGNED NOT NULL DEFAULT 50,    -- 0-100 (corrección > aprobación)

  -- Ciclo de vida.
  estado            ENUM('activa','superseded','descartada','pendiente_revision') NOT NULL DEFAULT 'activa',
  superseded_by     BIGINT UNSIGNED DEFAULT NULL,

  -- Control del usuario (su conocimiento → puede verlo/corregirlo).
  visible_usuario   TINYINT(1) NOT NULL DEFAULT 1,
  editable_usuario  TINYINT(1) NOT NULL DEFAULT 1,

  -- Datos crudos de respaldo (refs, métricas, conteos de señal). LONGTEXT
  -- por portabilidad (evita quirks de tipo JSON entre versiones de MariaDB).
  datos_json        LONGTEXT DEFAULT NULL,

  -- Vigencia: memorias temporales (mejores horas/tendencias) pueden expirar;
  -- las permanentes (identidad/decisiones) no llevan valid_until.
  valid_from        DATETIME DEFAULT NULL,
  valid_until       DATETIME DEFAULT NULL,

  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_marca (marca_id),
  KEY idx_marca_dom_est (marca_id, dominio, estado),
  KEY idx_tipo (tipo),
  KEY idx_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nota: no hay FK a crecer_marca a propósito (memorias pueden sobrevivir a
-- cambios y el módulo debe poder DROP-earse sin tocar el resto del producto).

