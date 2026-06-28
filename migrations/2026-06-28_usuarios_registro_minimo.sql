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
