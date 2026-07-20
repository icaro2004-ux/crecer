-- ============================================================
--  CRECER — Verificación por SMS + anti-abuso del post gratis
--  2026-07-20_telefono_verificado.sql
--
--  Corre esto en phpMyAdmin de prod (una vez).
--  Modelo: el post gratis se desbloquea (descargar/publicar) tras verificar
--  el celular por SMS. 1 desbloqueo gratis por número (corta las 20 cuentas).
-- ============================================================

-- El número (E164) que desbloqueó el post gratis de ESTA marca. NULL = aún no verificado.
ALTER TABLE crecer_marca
  ADD COLUMN telefono_verificado VARCHAR(20) NULL AFTER whatsapp;

-- Dedup: 1 fila por número que ya reclamó su desbloqueo gratis. El PRIMARY KEY
-- garantiza que un mismo número NO pueda reclamar un segundo post gratis.
CREATE TABLE IF NOT EXISTS crecer_telefono_gratis (
  telefono   VARCHAR(20) NOT NULL,
  marca_id   INT NOT NULL,
  usuario_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (telefono),
  KEY idx_marca (marca_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
