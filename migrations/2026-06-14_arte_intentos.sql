-- Contador de generaciones de IA por post (límite por pieza)
ALTER TABLE crecer_contenido
  ADD COLUMN arte_intentos TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER grafica_path;
