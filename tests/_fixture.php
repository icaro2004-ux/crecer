<?php
// ============================================================
//  CRECER — FIXTURES DE PRUEBA, CON CANDADO
//  tests/_fixture.php
//
//  POR QUE EXISTE ESTE ARCHIVO. Una prueba necesitaba una marca con meta y
//  plan, y en vez de crearse la suya ADOPTO una que ya estaba: le cambio el
//  dueño a un usuario temporal. Al borrar ese usuario, la FK
//  `crecer_marca -> usuarios ON DELETE CASCADE` se llevo la marca entera con su
//  meta, su plan y sus tacticas. Eran datos de desarrollo irrepetibles.
//
//  LAS DOS REGLAS QUE SALEN DE AHI, y que este archivo hace cumplir:
//
//    1. Una prueba SIEMBRA lo suyo. Nunca adopta nada que ya exista, y no hay
//       aqui ninguna funcion que reasigne el dueño de una marca: si no se puede
//       escribir, no se puede equivocar.
//    2. Una prueba BORRA solo lo suyo. Y no se fia de su memoria para saber que
//       es suyo: la propiedad va SELLADA en el nombre del negocio, en la fila.
//       limpiar() sobre algo sin sello lanza y no toca nada.
//
//  El sello vive en el dato y no en una lista en memoria a proposito: si una
//  corrida se muere a la mitad, la lista se pierde y la fila queda huerfana —
//  pero el sello sigue ahi, y limpiarHuerfanas() puede barrerla despues.
//
//  La fixture reproduce la FORMA del caso auditado (una meta de pedidos, un
//  plan vigente de seis pasos, piezas en distintos estados). NO reproduce
//  contenido de nadie: los textos son de relleno y se ven que lo son.
// ============================================================

final class Fixture
{
    /** La unica prueba de propiedad. Va en nombre_negocio, en la fila. */
    public const SELLO = '[prueba]';

    /**
     * Siembra un negocio completo y devuelve sus identificadores.
     * Determinista: mismos textos, mismos estados, y las fechas relativas a
     * CURDATE() de MySQL — nunca a date() de PHP, que en este proyecto va en
     * otra zona horaria (ver el hotfix de sondeo del 19 de agosto).
     *
     * @param string $rol 'admin' para las pruebas que RENDERIZAN pantallas del
     *        panel: el candado de suscripcion las manda a la venta y no se
     *        estaria probando el recorrido sino el paywall.
     * @param bool $con_meta false = solo el negocio, sin meta ni plan. Lo piden
     *        las pruebas que siembran SUS propias metas y necesitan una marca
     *        limpia para afirmar «sin meta, el lector devuelve null».
     *
     * @return array{usuario_id:int,marca_id:int,meta_id:int,plan_id:int,tacticas:int[],piezas:int[]}
     */
    public static function crear(PDO $pdo, string $etiqueta = 'base', bool $con_meta = true,
                                 string $rol = 'proveedor'): array
    {
        $sufijo = $etiqueta . '-' . bin2hex(random_bytes(4));   // unico por corrida
        $email  = 'fixture.' . $sufijo . '@prueba.local';

        $pdo->prepare("INSERT INTO usuarios (nombre,email,password,rol,verificado,activo)
                       VALUES (?,?,?,?,1,1)")
            ->execute([self::SELLO . ' Dueña', $email, password_hash('fixture', PASSWORD_DEFAULT), $rol]);
        $uid = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO crecer_marca (usuario_id,nombre_negocio,descripcion)
                       VALUES (?,?,?)")
            ->execute([$uid, self::SELLO . ' Repostería ' . $sufijo,
                       'Negocio de relleno para pruebas. No es un cliente.']);
        $mid = (int)$pdo->lastInsertId();

        if (!$con_meta) {
            return ['usuario_id' => $uid, 'marca_id' => $mid, 'meta_id' => 0,
                    'plan_id' => 0, 'tacticas' => [], 'piezas' => []];
        }

        // La meta: la forma del caso auditado (pedidos, cantidad, ventana viva).
        $pdo->prepare("INSERT INTO crecer_meta
                 (marca_id,objetivo,titulo,cantidad,unidad,base_inicial,
                  fecha_inicio,fecha_limite,estado,medible,como_medir)
               VALUES (?, 'pedidos','Más pedidos',25,'pedidos',0,
                       DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL 23 DAY),
                       'activa',1,'pedidos registrados en Crecer')")
            ->execute([$mid]);
        $meta_id = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO crecer_meta_plan (meta_id,marca_id,version,estado,inicio_at,piezas)
                       VALUES (?,?,1,'activo', DATE_SUB(NOW(), INTERVAL 7 DAY), 6)")
            ->execute([$meta_id, $mid]);
        $plan_id = (int)$pdo->lastInsertId();

        // EL PLAN NACE YA PRESENTADO. Sin esto, toda fixture caeria en el estado C
        // -«tu camino esta listo»- y ninguna suite podria ejercitar lo que viene
        // despues: el estado C se come la pantalla hasta que alguien pulsa
        // Empezar. El caso sin presentar se pide a proposito con sinPresentar().
        try { $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NOW() WHERE id=?")->execute([$plan_id]); }
        catch (Throwable $e) {}   // sin la migracion no hay nada que marcar

        // Seis pasos con el vocabulario REAL del plan. Solo existen tres clases
        // -produccion, accion_dueno, regla- y las escribe meta_plan_generar().
        // Antes esta fixture usaba 'inversion', 'fisica' y 'medicion', que no
        // existen en ningun sitio: la maquina de estados mira clase=accion_dueno
        // (y si lleva inversion, la separa en H), asi que con las clases
        // inventadas los estados H e I no se ejercitaban nunca aunque la fixture
        // pareciera cubrirlos.
        $pasos = [
            [1, 1, 'produccion',   'Paso de relleno 1', 'hecha',     null],
            [2, 1, 'produccion',   'Paso de relleno 2', 'pendiente', null],
            [3, 2, 'accion_dueno', 'Paso de relleno 3', 'pendiente', 15.00],  // -> H, lleva dinero
            [4, 2, 'accion_dueno', 'Paso de relleno 4', 'pendiente', null],   // -> I, solo sus manos
            [5, 3, 'produccion',   'Paso de relleno 5', 'pendiente', null],
            [6, 3, 'regla',        'Paso de relleno 6', 'pendiente', null],
        ];
        $ins = $pdo->prepare("INSERT INTO crecer_meta_tactica
                 (meta_id,plan_id,marca_id,orden,semana,clase,titulo,que_hacer,estado,inversion)
               VALUES (?,?,?,?,?,?,?, 'Texto de relleno.', ?, ?)");
        $tacticas = [];
        foreach ($pasos as [$orden, $sem, $clase, $tit, $est, $inv]) {
            $ins->execute([$meta_id, $plan_id, $mid, $orden, $sem, $clase, $tit, $est, $inv]);
            $tacticas[] = (int)$pdo->lastInsertId();
        }

        // Dos piezas: una esperando el OK y otra pidiendo material.
        $piezas = [];
        // meta_id y plan_id NO son adorno: el lector trae las piezas POR PLAN
        // (WHERE c.plan_id = ?). Sin ellos la pieza existe pero el compositor
        // no la ve, y la fixture parecia sana enseñando otro estado.
        $pz = $pdo->prepare("INSERT INTO crecer_contenido
                 (marca_id,plataforma,tipo,caption,estado,meta_id,plan_id,tactica_id,fecha_programada)
               VALUES (?, 'instagram', ?, ?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 2 DAY))");
        $pz->execute([$mid, 'post', 'Texto de relleno para revisar.', 'borrador',
                      $meta_id, $plan_id, $tacticas[1]]);
        $piezas[] = (int)$pdo->lastInsertId();
        $pz->execute([$mid, 'reel', 'Texto de relleno que espera video.', 'borrador',
                      $meta_id, $plan_id, $tacticas[4]]);
        $piezas[] = (int)$pdo->lastInsertId();

        return ['usuario_id' => $uid, 'marca_id' => $mid, 'meta_id' => $meta_id,
                'plan_id' => $plan_id, 'tacticas' => $tacticas, 'piezas' => $piezas];
    }

    /** ¿Esta marca la sembró una prueba? Lo dice la fila, no la memoria. */
    public static function esNuestra(PDO $pdo, int $marca_id): bool
    {
        $q = $pdo->prepare("SELECT nombre_negocio FROM crecer_marca WHERE id=?");
        $q->execute([$marca_id]);
        $n = (string)($q->fetchColumn() ?: '');
        return $n !== '' && strncmp($n, self::SELLO, strlen(self::SELLO)) === 0;
    }

    /**
     * EL CANDADO. Lanza antes de que nadie escriba nada.
     * Existe porque «la prueba sabe lo que creó» resultó ser falso: basta un
     * SELECT que devuelva otra fila para que crea suyo lo que no lo es.
     */
    public static function exigirPropia(PDO $pdo, int $marca_id): void
    {
        if (!self::esNuestra($pdo, $marca_id)) {
            throw new RuntimeException(
                "La marca #{$marca_id} NO la sembró una prueba (su nombre no lleva «"
                . self::SELLO . "»). Una prueba no adopta ni borra marcas ajenas: "
                . 'siembra la suya con Fixture::crear().'
            );
        }
    }

    /**
     * Devuelve el plan de una fixture al estado «todavia no se le ha ensenado»,
     * que es como nacen los planes de verdad. Solo sobre marcas propias: el sello
     * se exige antes de tocar nada.
     */
    public static function sinPresentar(PDO $pdo, int $marca_id, int $plan_id): void
    {
        self::exigirPropia($pdo, $marca_id);
        $pdo->prepare("UPDATE crecer_meta_plan SET presentado_at=NULL WHERE id=? AND marca_id=?")
            ->execute([$plan_id, $marca_id]);
    }

    /** Borra una fixture entera. Solo suya, y solo si el sello lo confirma. */
    public static function limpiar(PDO $pdo, int $marca_id): void
    {
        self::exigirPropia($pdo, $marca_id);
        $u = $pdo->prepare("SELECT usuario_id FROM crecer_marca WHERE id=?");
        $u->execute([$marca_id]);
        $uid = (int)$u->fetchColumn();
        //  LO QUE LA CASCADA NO SE LLEVA. Borrar el usuario arrastra la marca
        //  por la FK, pero en este proyecto las tablas nuevas van SIN llaves
        //  foraneas —regla de Hostinger, donde una FK tumba el CREATE entero en
        //  silencio— asi que sus filas se quedaban vivas.
        //
        //  No era teorico: una tanda completa dejaba +30 activos, +7 asientos y
        //  +83 unidades sumadas al cubo del mes de marcas que ya no existen. El
        //  numero del mes es evidencia que se le enseña al jurado y a un cliente:
        //  ensuciarlo con fixtures es ensuciar la evidencia.
        //
        //  Se borra por marca_id y en este orden: primero lo que cuelga, luego la
        //  marca. Cada DELETE va en su try porque una tabla que todavia no exista
        //  en esta base no puede impedir que se limpie el resto.
        $porMarca = [
            'crecer_img_cuota_asiento', 'crecer_img_cuota_cubo', 'crecer_ia_log',
            'crecer_activos', 'crecer_graficas', 'crecer_memoria',
            //  El libro de semanas nace sin FK (regla de Hostinger), asi que
            //  tampoco lo arrastra el borrado del usuario: sin esta linea cada
            //  prueba del ciclo dejaba su fila de por vida.
            'crecer_meta_semana',
        ];
        foreach ($porMarca as $t) {
            try { $pdo->prepare("DELETE FROM {$t} WHERE marca_id=?")->execute([$marca_id]); }
            catch (Throwable $e) { /* tabla que no existe aqui: no es un fallo */ }
        }
        //  Los slides cuelgan de la pieza, no de la marca.
        try {
            $pdo->prepare("DELETE FROM crecer_carrusel WHERE contenido_id IN
                            (SELECT id FROM crecer_contenido WHERE marca_id=?)")
                ->execute([$marca_id]);
        } catch (Throwable $e) {}

        if ($uid) $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$uid]);
        else      $pdo->prepare("DELETE FROM crecer_marca WHERE id=?")->execute([$marca_id]);

        //  Y EL DISCO. Las fotos y los videos de la fixture viven en
        //  uploads/marca_N: sin esto, cada tanda deja su carpeta para siempre.
        self::borrarCarpeta(rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH
                                   : dirname(__DIR__) . '/uploads', '/\\')
                            . DIRECTORY_SEPARATOR . 'marca_' . $marca_id);
    }

    /**
     * Borra una carpeta entera, y SOLO si es una carpeta de marca dentro de
     * uploads. La guarda no es adorno: a esta funcion se le pasa una ruta
     * construida, y una construida mal borra lo que no debe.
     */
    private static function borrarCarpeta(string $dir): void
    {
        $real = @realpath($dir);
        $base = @realpath(rtrim(defined('UPLOADS_PATH') ? UPLOADS_PATH
                                 : dirname(__DIR__) . '/uploads', '/\\'));
        if ($real === false || $base === false) return;
        if (!str_starts_with($real, $base . DIRECTORY_SEPARATOR)) return;
        if (!preg_match('~[\\\\/]marca_\d+$~', $real)) return;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($real);
        } catch (Throwable $e) { /* si no se puede, se dice en los contadores */ }
    }

    /** Restos de corridas que se murieron a la mitad. Solo lo sellado. */
    public static function limpiarHuerfanas(PDO $pdo, int $horas = 6): int
    {
        $q = $pdo->prepare("SELECT m.id FROM crecer_marca m
                             WHERE m.nombre_negocio LIKE ?
                               AND m.created_at < (NOW() - INTERVAL ? HOUR)");
        $q->execute([self::SELLO . '%', $horas]);
        $n = 0;
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $mid) { self::limpiar($pdo, (int)$mid); $n++; }
        return $n;
    }

    /**
     * Lo de siempre cuando se puede: sembrar, mirar, deshacer. Sin dejar rastro
     * ni depender de que la limpieza corra.
     *
     * OJO: no sirve si lo que se prueba corre en OTRO proceso — una transacción
     * no se ve desde fuera. Para eso, crear() + limpiar() con sello.
     */
    public static function enTransaccion(PDO $pdo, callable $fn)
    {
        $pdo->beginTransaction();
        try { return $fn(); }
        finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }
}
