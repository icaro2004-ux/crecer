-- ============================================================
--  CRECER — EL LIBRO DE CAMBIOS DE LA META
--  migrations/2026-08-22_crecer_meta_cambio.sql
--
--  Fuente de dominio de la historia de una meta. audit_log recibe un
--  resumen para la vista de seguridad, pero la historia NO se reconstruye
--  parseando texto libre: se lee de aqui, con columnas.
--
--  El valor ANTERIOR se escribe ANTES de que el nuevo entre. Sin esta tabla
--  en la base, el wizard de ajustar NO SE OFRECE — no se degrada, se apaga:
--  un ajuste sin registro es exactamente la edicion silenciosa que este
--  contrato existe para impedir.
--
--  Se registran TAMBIEN los intentos rechazados por concurrencia. Sin eso,
--  «¿por que no se guardo mi cambio?» no tiene respuesta. Por eso el camino
--  del rechazo hace COMMIT y no ROLLBACK: la meta no se toca, pero la fila
--  del intento se queda.
--
--  token_antes NO es solo updated_at. `datetime` tiene resolucion de un
--  segundo, asi que dos escrituras en el mismo segundo darian el mismo
--  sello y una se perderia sin que nadie se entere. El token es un resumen
--  de updated_at MAS los cuatro campos ajustables: cualquier cambio real lo
--  invalida, caiga en el segundo que caiga.
--
--  SIN llaves foraneas (Hostinger, verificado 2026-08-12).
--  Correr desde panel/admin_migrar.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS crecer_meta_cambio (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  marca_id        INT UNSIGNED    NOT NULL,
  meta_id         INT UNSIGNED    NOT NULL,
  usuario_id      INT UNSIGNED    NULL   COMMENT 'quien lo pidio; NULL = automatico',
  tactica_id      INT UNSIGNED    NULL   COMMENT 'solo en jugada_sustituida',
  tipo            VARCHAR(28)     NOT NULL
    COMMENT 'meta_ajuste|meta_ajuste_deshecho|jugada_sustituida',
  campo           VARCHAR(32)     NULL
    COMMENT 'cantidad|fecha_limite|presupuesto_pauta|contexto',
  valor_antes     TEXT            NULL,
  valor_despues   TEXT            NULL,
  motivo          VARCHAR(190)    NULL   COMMENT 'lo que escribio el dueño, opcional',
  token_antes     VARCHAR(40)     NULL   COMMENT 'el sello que tenia al abrir el wizard',
  plan_solicitado TINYINT(1)      NOT NULL DEFAULT 0,
  plan_resultado  VARCHAR(12)     NULL   COMMENT 'no_pedido|ok|fallo',
  resultado       VARCHAR(24)     NOT NULL DEFAULT 'pendiente'
    COMMENT 'pendiente|aplicado|rechazado_concurrencia|fallo',
  detalle         VARCHAR(255)    NULL   COMMENT 'el error, si lo hubo',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cambio_meta  (meta_id, created_at),
  KEY idx_cambio_marca (marca_id, tipo, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REVERSA ──────────────────────────────────────────────────
--  Al quitarla, el wizard de ajustar se apaga solo (comprueba la tabla
--  antes de ofrecerse). No queda nada roto; se pierde el historial.
--
-- DROP TABLE crecer_meta_cambio;
