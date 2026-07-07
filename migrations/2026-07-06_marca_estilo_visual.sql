-- Línea de diseño / estilo visual de la marca: se define una vez y se inyecta en
-- TODAS las imágenes generadas → feed consistente (la "línea de diseño" que se
-- conserva entre posts). Texto libre en lenguaje humano.
ALTER TABLE crecer_marca ADD COLUMN IF NOT EXISTS estilo_visual TEXT NULL;
