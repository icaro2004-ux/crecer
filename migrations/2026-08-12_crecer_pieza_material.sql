-- ============================================================
--  CRECER — PIEZAS QUE NECESITAN MATERIAL DEL DUEÑO
--  migrations/2026-08-12_crecer_pieza_material.sql
--
--  EL PROBLEMA: cuando el plan pedía un REEL, el motor creaba la pieza y le
--  generaba una IMAGEN. Decía "reel" y era una foto. Una promesa falsa que
--  el dueño descubre en el peor momento: cuando va a publicar.
--
--  LA VERDAD: Crecer SÍ produce reels — Gemini analiza los clips y Shotstack
--  los monta (crecer_reels) — pero NO inventa video. Necesita el material
--  crudo del negocio, que solo el dueño puede grabar.
--
--  Así que la pieza aprende a decir qué le falta:
--    · necesita_material = 'video'  → el corillo ya escribió el guion, faltan
--      los clips. La pieza NO se publica hasta que exista de verdad.
--    · NULL → la pieza está completa (el caso normal).
--
--  Con esto la jugada del plan puede decir la verdad en pantalla: "te escribí
--  el guion, grábame 3 clips y lo monto" en vez de fingir que ya está.
--
--  BD COMPARTIDA (encuentralo_db). Correr en phpMyAdmin.
-- ============================================================

SET NAMES utf8mb4;

-- Nota: los COMMENT van sin punto y coma dentro del texto a propósito — hay
-- herramientas (y scripts) que parten el archivo por ';' y romperían la
-- sentencia a la mitad.
ALTER TABLE crecer_contenido
  ADD COLUMN necesita_material VARCHAR(16) NULL
    COMMENT 'video = falta que el dueno grabe los clips. NULL = pieza completa',
  ADD COLUMN guion TEXT NULL
    COMMENT 'el guion que escribio el corillo para que el dueno sepa que grabar';

ALTER TABLE crecer_contenido
  ADD KEY idx_cont_material (marca_id, necesita_material);
