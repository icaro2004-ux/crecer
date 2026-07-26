-- ============================================================
--  CRECER — Idea del día (cache diario en el Home)
--  El Estratega da una idea corta para hoy; se cachea por marca+día
--  (cambia a diario, no re-llama a Gemini en cada carga del Home).
-- ============================================================
CREATE TABLE IF NOT EXISTS crecer_idea_dia (
  marca_id   INT PRIMARY KEY,
  dia        DATE NULL,
  texto      TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
