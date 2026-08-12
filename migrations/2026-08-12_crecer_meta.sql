-- ============================================================
--  CRECER — LA META DEL NEGOCIO (el norte del corillo)
--  migrations/2026-08-12_crecer_meta.sql
--
--  El hueco que cierra: hasta hoy el corillo PRODUCÍA contenido
--  (la Estratega improvisaba un "enfoque de la semana" mirando el
--  perfil) pero nadie sabía PARA QUÉ NÚMERO trabajaba. Posts bonitos
--  que no mueven el negocio.
--
--  Con esto el negocio declara una meta real ("40 pedidos para el 30
--  de agosto") y esa meta GOBIERNA el motor: alimenta el enfoque de
--  la semana, el planificador, el CTA de cada pieza y la conversación
--  de la Sala.
--
--  Dos tablas:
--   1. crecer_meta          — la meta viva + el diagnóstico de la Estratega
--   2. crecer_meta_tactica  — las jugadas concretas para lograrla
--      (contenido, distribución, PAUTA con presupuesto, oferta, alianza).
--      Son entidades vivas con estado: el corillo las ejecuta y las cierra.
--
--  Regla de oro (igual que Métricas): NUNCA números falsos. Si el
--  objetivo no se puede medir todavía con señal real (ej. visitas a
--  una web que no tiene analítica), `medible=0` y la UI lo dice claro
--  en vez de inventar un progreso.
--
--  BD COMPARTIDA (encuentralo_db). InnoDB, utf8mb4_unicode_ci.
--  Idempotente: IF NOT EXISTS. Correr en phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;

-- ── 1. LA META ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS crecer_meta (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    marca_id          INT UNSIGNED NOT NULL,
    objetivo          VARCHAR(30) NOT NULL      COMMENT 'pedidos|ventas|conversaciones|alcance|comunidad|visitas_web|evento',
    titulo            VARCHAR(190) NOT NULL     COMMENT 'la meta dicha en cristiano, como la diría el dueño',
    cantidad          DECIMAL(12,2) NULL        COMMENT 'el número a alcanzar (NULL = dirección sin número)',
    unidad            VARCHAR(24) NOT NULL DEFAULT '' COMMENT 'pedidos, dolares, mensajes, personas',
    base_inicial      DECIMAL(12,2) NULL        COMMENT 'de dónde arrancó (medido al crear la meta) — el delta es lo que cuenta',
    fecha_inicio      DATE NOT NULL,
    fecha_limite      DATE NULL                 COMMENT 'NULL = meta abierta, sin fecha',
    presupuesto_pauta DECIMAL(8,2) NULL         COMMENT 'lo que el dueño PUEDE poner en boost al mes (0 = nada)',
    contexto          TEXT NULL                 COMMENT 'con qué cuenta: oferta, producto estrella, fecha, evento',
    diagnostico       TEXT NULL                 COMMENT 'lectura honesta de la Estratega: ¿se puede con lo que hay?',
    veredicto         VARCHAR(16) NULL          COMMENT 'alcanzable|ambiciosa|fuera_de_alcance — el juicio de la Estratega',
    medible           TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = hoy no hay señal real para medir este objetivo',
    como_medir        VARCHAR(190) NULL         COMMENT 'si medible=0: qué hace falta para poder medirlo',
    estado            ENUM('activa','lograda','pausada','vencida','cancelada') NOT NULL DEFAULT 'activa',
    ia_log_id         BIGINT UNSIGNED NULL      COMMENT 'la llamada que armó el plan (evidencia criterio #2)',
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_meta_marca (marca_id),
    KEY idx_meta_estado (marca_id, estado),
    CONSTRAINT fk_meta_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. LAS TÁCTICAS (las jugadas para lograrla) ─────────────
CREATE TABLE IF NOT EXISTS crecer_meta_tactica (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    meta_id      INT UNSIGNED NOT NULL,
    marca_id     INT UNSIGNED NOT NULL,
    orden        TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'el orden en que se atacan',
    semana       TINYINT UNSIGNED NULL     COMMENT 'semana del plan (1..N) — cuál toca ahora',
    tipo         VARCHAR(20) NOT NULL DEFAULT 'contenido' COMMENT 'contenido|distribucion|pauta|oferta|alianza|operacion',
    titulo       VARCHAR(190) NOT NULL     COMMENT 'la jugada en 4-8 palabras',
    que_hacer    TEXT NULL                 COMMENT 'la instrucción concreta (la ejecuta el corillo o el dueño)',
    por_que      TEXT NULL                 COMMENT 'por qué esta jugada mueve ESTE número',
    canal        VARCHAR(24) NOT NULL DEFAULT 'instagram' COMMENT 'instagram|facebook|whatsapp|ambas|fisico',
    cta          VARCHAR(190) NULL         COMMENT 'la acción que se le pide a la gente (el CTA que hereda cada pieza)',
    inversion    DECIMAL(8,2) NULL         COMMENT 'si tipo=pauta: cuánto poner, en dólares',
    quien        VARCHAR(12) NOT NULL DEFAULT 'corillo' COMMENT 'corillo = lo hace la IA | dueno = necesita sus manos',
    estado       ENUM('pendiente','en_curso','hecha','descartada') NOT NULL DEFAULT 'pendiente',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tac_meta (meta_id, orden),
    KEY idx_tac_marca (marca_id, estado),
    CONSTRAINT fk_tac_meta  FOREIGN KEY (meta_id)  REFERENCES crecer_meta(id)   ON DELETE CASCADE,
    CONSTRAINT fk_tac_marca FOREIGN KEY (marca_id) REFERENCES crecer_marca(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. El contenido sabe a qué meta sirve ───────────────────
--  Cada pieza que el corillo produzca bajo una meta queda amarrada a
--  ella y a su táctica. Así "Resultados" puede decir la verdad: de los
--  40 pedidos, estos vinieron de las piezas de la meta.
--  (ADD COLUMN no es idempotente en MySQL: si ya corriste esto, salta
--   el error 1060 "Duplicate column" — es seguro ignorarlo.)
ALTER TABLE crecer_contenido
  ADD COLUMN meta_id    INT UNSIGNED NULL COMMENT 'la meta que esta pieza empuja',
  ADD COLUMN tactica_id INT UNSIGNED NULL COMMENT 'la jugada concreta de la que nació';

ALTER TABLE crecer_contenido
  ADD KEY idx_cont_meta (meta_id);
