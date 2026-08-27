-- ============================================================
--  CRECER — QUE SON LOS ASIENTOS VIVOS DE LA CUOTA  ·  SOLO LECTURA
--  _DIAGNOSTICO-CUOTA-HISTORICA.sql
--
--  PARA QUE. Un asiento en 'reservado' RETIENE una unidad del mes del dueño.
--  Si la imagen llego y nadie cerro la unidad, volvera a pagarla la proxima vez
--  que intente esa pieza. Si NO llego y nadie la devolvio, esta pagando algo que
--  no recibio. Desde fuera los dos casos se ven exactamente igual.
--
--  ESTE ARCHIVO NO ESCRIBE NADA. Solo SET y SELECT. Ni INSERT, ni UPDATE, ni
--  DELETE, ni ALTER, ni CREATE, ni DROP, ni TRUNCATE, ni CALL. Se puede pegar
--  entero en phpMyAdmin de produccion sin miedo, y hay una prueba en la suite
--  que se pone roja si alguien mete aqui una sola sentencia de escritura.
--
--  COMO SE USA. Pegar entero y devolver el resultado de los ocho bloques. No
--  hace falta filtrar por marca; si se quiere, cambiar @marca abajo.
--
--  LO QUE **NO** HACE: no consulta al proveedor, no sondea trabajos, no genera
--  imagenes, no abre ni cierra asientos, no toca contenido, y no imprime
--  prompts, credenciales ni mensajes crudos del proveedor.
-- ============================================================

-- El umbral REAL de caducidad del dominio (CuotaImg::CADUCA_MIN). No se inventa
-- otro: es el mismo que usa barrerCaducadas(), y solo aplica a reservas SIN job.
SET @umbral_min := 45;

-- La fecha del arreglo del origen. Una operacion por pieza con origen 0 creada
-- DESPUES de esto no es historia: es una regresion.
SET @arreglo := '2026-08-26 00:00:00';

-- Poner un numero de marca para mirar solo esa. NULL = todas.
SET @marca := NULL;


-- ── 0 · DONDE ESTOY Y QUE HORA ES AQUI ──────────────────────────────────────
SELECT
    DATABASE()          AS base_de_datos,
    @@hostname          AS servidor,
    VERSION()           AS motor,
    NOW()               AS ahora_mysql,
    @@session.time_zone AS zona_horaria,
    @umbral_min         AS umbral_caducidad_min;


-- ── 1 · ASIENTOS POR ESTADO ─────────────────────────────────────────────────
SELECT
    estado,
    COUNT(*)                    AS asientos,
    COALESCE(SUM(unidades), 0)  AS unidades,
    MIN(created_at)             AS mas_antiguo,
    MAX(created_at)             AS mas_reciente
  FROM crecer_img_cuota_asiento
 WHERE (@marca IS NULL OR marca_id = @marca)
 GROUP BY estado
 ORDER BY asientos DESC;


-- ── 2 · LOS VIVOS: CUANTOS, DE QUE EDAD ─────────────────────────────────────
--  «Vivo» es reservado + riesgo: son los dos estados que retienen unidad.
SELECT
    COUNT(*)                                              AS asientos_vivos,
    COALESCE(SUM(unidades), 0)                            AS unidades_retenidas,
    MIN(TIMESTAMPDIFF(MINUTE, created_at, NOW()))         AS edad_min_min,
    MAX(TIMESTAMPDIFF(MINUTE, created_at, NOW()))         AS edad_max_min,
    ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, NOW())))  AS edad_media_min
  FROM crecer_img_cuota_asiento
 WHERE estado IN ('reservado', 'riesgo')
   AND (@marca IS NULL OR marca_id = @marca);


-- ── 3 · OPERACIONES POR PIEZA CON origen_id = 0 ─────────────────────────────
--  Una llave sin pieza dentro es LA MISMA para toda la marca: la segunda
--  publicacion reusaba la reserva de la primera. Se separa lo cerrado (historia,
--  no se toca) de lo vivo, y lo anterior al arreglo de lo posterior.
SELECT
    CASE WHEN estado IN ('reservado','riesgo') THEN 'vivo' ELSE 'cerrado' END AS vida,
    CASE WHEN created_at >= @arreglo THEN 'DESPUES del arreglo (regresion)'
         ELSE 'antes del arreglo (historia)' END                              AS momento,
    operacion,
    ruta,
    COUNT(*)         AS asientos,
    MIN(created_at)  AS desde,
    MAX(created_at)  AS hasta
  FROM crecer_img_cuota_asiento
 WHERE operacion IN ('arte_post', 'realce', 'slide')
   AND (origen_id IS NULL OR origen_id = 0)
   AND (@marca IS NULL OR marca_id = @marca)
 GROUP BY vida, momento, operacion, ruta
 ORDER BY vida DESC, momento DESC, asientos DESC;


-- ── 4 · CADA ASIENTO VIVO, CON SU EVIDENCIA ─────────────────────────────────
--  El job y la llave van RECORTADOS: identifican sin volcar el dato entero.
--  `costo_usd` es lo ANOTADO como potencial, no dinero cobrado.
SELECT
    a.id,
    a.marca_id,
    a.cubo,
    a.estado,
    a.unidades,
    a.operacion,
    a.origen_tipo,
    a.origen_id,
    LEFT(COALESCE(a.provider_job_id, ''), 12)  AS job_recortado,
    LEFT(a.idem, 12)                           AS idem_recortado,
    COALESCE(NULLIF(a.exencion, ''), 'ninguna') AS exencion,
    a.costo_usd                                AS costo_anotado_potencial,
    a.created_at,
    a.updated_at,
    TIMESTAMPDIFF(MINUTE, a.created_at, NOW()) AS edad_min,

    -- Evidencia de la pieza, cuando el origen es una publicacion
    c.id                                       AS contenido_id,
    c.estado                                   AS estado_pieza,
    CASE WHEN COALESCE(c.grafica_path, '') <> '' THEN 'SI' ELSE 'no' END AS pieza_tiene_imagen,
    c.img_estado,
    CASE WHEN COALESCE(c.img_job, '') <> '' THEN 'SI' ELSE 'no' END       AS pieza_tiene_job,
    c.img_error_clase,
    c.img_job_at,
    c.img_next_poll_at,
    c.tactica_id,
    c.plan_id,

    -- Evidencia del slide, cuando el origen es un slide de carrusel
    s.id                                       AS slide_id,
    s.contenido_id                             AS slide_de_pieza,
    CASE WHEN COALESCE(s.grafica_path, '') <> '' THEN 'SI' ELSE 'no' END  AS slide_tiene_imagen,
    sc.estado                                  AS estado_pieza_del_slide

  FROM crecer_img_cuota_asiento a
  LEFT JOIN crecer_contenido c
         ON a.origen_tipo <> 'slide' AND a.origen_id > 0 AND c.id = a.origen_id
  LEFT JOIN crecer_carrusel s
         ON a.origen_tipo  = 'slide' AND a.origen_id > 0 AND s.id = a.origen_id
  LEFT JOIN crecer_contenido sc
         ON sc.id = s.contenido_id
 WHERE a.estado IN ('reservado', 'riesgo')
   AND (@marca IS NULL OR a.marca_id = @marca)
 ORDER BY a.created_at ASC
 LIMIT 300;


-- ── 5 · LA MISMA CLASIFICACION QUE HACE LA HERRAMIENTA ──────────────────────
--  Es la version en SQL de includes/cuota_historica.php, para poder contrastar.
--  Ojo: aqui NO se puede comprobar la correlacion por nombre de archivo (hace
--  falta md5 del job), asi que «entregada» solo sale cuando la pieza sigue
--  apuntando al MISMO job. Lo demas cae del lado prudente, que es el correcto.
SELECT clase, COUNT(*) AS asientos, SUM(unidades) AS unidades
  FROM (
    SELECT
        a.unidades,
        CASE
          WHEN a.operacion IN ('arte_post','realce','slide')
               AND COALESCE(a.origen_id, 0) = 0
               AND a.created_at >= @arreglo                      THEN 'inconsistente_regresion'
          WHEN a.operacion IN ('arte_post','realce','slide')
               AND COALESCE(a.origen_id, 0) = 0                  THEN 'sin_atribucion_origen0'
          WHEN a.origen_id > 0 AND c.id IS NULL AND s.id IS NULL  THEN 'sin_atribucion_relacion_rota'
          WHEN COALESCE(c.grafica_path, '') <> ''
               AND COALESCE(a.provider_job_id, '') <> ''
               AND c.img_job = a.provider_job_id                 THEN 'entregada_sin_confirmar'
          WHEN COALESCE(c.grafica_path, '') = ''
               AND c.img_error_clase IN ('sin_credito','job_no_existe',
                                         'rechazado_confirmado','tope_fallos_consulta',
                                         'fbx:respaldo_fallo')   THEN 'fallo_terminal_sin_entrega'
          WHEN COALESCE(a.provider_job_id, '') = ''
               AND COALESCE(c.grafica_path, '') = ''
               AND TIMESTAMPDIFF(MINUTE, a.created_at, NOW()) > @umbral_min
               AND COALESCE(c.img_estado, '') NOT IN ('queued','working')
                                                                 THEN 'caducada_sin_job'
          WHEN TIMESTAMPDIFF(MINUTE, a.created_at, NOW()) <= @umbral_min
                                                                 THEN 'reserva_reciente'
          WHEN COALESCE(a.provider_job_id, '') <> ''             THEN 'job_posiblemente_vivo'
          ELSE 'sin_atribucion_evidencia_insuficiente'
        END AS clase
      FROM crecer_img_cuota_asiento a
      LEFT JOIN crecer_contenido c
             ON a.origen_tipo <> 'slide' AND a.origen_id > 0 AND c.id = a.origen_id
      LEFT JOIN crecer_carrusel s
             ON a.origen_tipo  = 'slide' AND a.origen_id > 0 AND s.id = a.origen_id
     WHERE a.estado IN ('reservado', 'riesgo')
       AND (@marca IS NULL OR a.marca_id = @marca)
  ) AS clasificados
 GROUP BY clase
 ORDER BY asientos DESC;


-- ── 6 · EL CUBO, Y LO QUE PASARIA (SIN QUE PASE) ────────────────────────────
--  DIFERENCIA = usadas - (confirmadas + vivas). Si no es 0, el cubo y el libro
--  no cuentan lo mismo, y eso hay que mirarlo antes de tocar nada.
--  Esto son UNIDADES del plan del dueño. No son dinero cobrado.
SELECT
    b.marca_id,
    b.cubo,
    b.limite,
    b.usadas,
    COALESCE(x.confirmadas, 0)                      AS unidades_confirmadas,
    COALESCE(x.vivas, 0)                            AS unidades_vivas,
    COALESCE(x.liberadas, 0)                        AS unidades_liberadas,
    b.usadas - (COALESCE(x.confirmadas,0) + COALESCE(x.vivas,0)) AS diferencia_contable
  FROM crecer_img_cuota_cubo b
  LEFT JOIN (
    SELECT marca_id, cubo,
           SUM(CASE WHEN estado = 'confirmado' THEN unidades ELSE 0 END)              AS confirmadas,
           SUM(CASE WHEN estado IN ('reservado','riesgo') THEN unidades ELSE 0 END)   AS vivas,
           SUM(CASE WHEN estado = 'liberado'  THEN unidades ELSE 0 END)               AS liberadas
      FROM crecer_img_cuota_asiento
     GROUP BY marca_id, cubo
  ) AS x ON x.marca_id = b.marca_id AND x.cubo = b.cubo
 WHERE (@marca IS NULL OR b.marca_id = @marca)
 ORDER BY b.cubo DESC, b.marca_id;


-- ── 7 · DOS ASIENTOS VIVOS CON LA MISMA INTENCION ───────────────────────────
--  No deberia haber ninguno: la reserva es idempotente por llave. Si aparece,
--  es inconsistencia y NO se toca automaticamente.
SELECT LEFT(idem, 12) AS idem_recortado, marca_id, operacion,
       origen_tipo, origen_id, COUNT(*) AS asientos_vivos
  FROM crecer_img_cuota_asiento
 WHERE estado IN ('reservado', 'riesgo')
   AND (@marca IS NULL OR marca_id = @marca)
 GROUP BY idem, marca_id, operacion, origen_tipo, origen_id
HAVING COUNT(*) > 1
 ORDER BY asientos_vivos DESC;


-- ── 8 · TRABAJO EN VUELO DE VERDAD ──────────────────────────────────────────
--  Piezas que todavia dicen estar trabajando. Un asiento vivo cuya pieza sigue
--  en vuelo NO es un resto: es trabajo. No se toca.
SELECT COUNT(*) AS piezas_con_job_abierto
  FROM crecer_contenido
 WHERE COALESCE(img_job, '') <> ''
   AND (@marca IS NULL OR marca_id = @marca);
