-- CRECER — glosario/vocabulario que la IA aprende de las ediciones del dueño
ALTER TABLE crecer_marca ADD COLUMN glosario TEXT NULL AFTER voz;
