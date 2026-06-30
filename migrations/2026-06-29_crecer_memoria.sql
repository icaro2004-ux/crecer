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
