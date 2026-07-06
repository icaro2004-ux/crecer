-- Verificación de correo obligatoria (confirmar que es humano).
-- Añade el token y deja VERIFICADOS a todos los usuarios EXISTENTES (grandfather),
-- para no trancar a las cuentas ya creadas. Solo los NUEVOS registros arrancan sin
-- verificar (verificado=0 + verif_token). Seguro de correr más de una vez:
-- el backfill solo toca a los que NO tienen token (los existentes), no a los nuevos.
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS verif_token VARCHAR(64) NULL;

UPDATE usuarios
   SET verificado = 1,
       email_verificado_at = COALESCE(email_verificado_at, NOW())
 WHERE (verificado IS NULL OR verificado = 0)
   AND verif_token IS NULL;
