<?php
// ============================================================
//  CRECER — UNA BASE DE USAR Y TIRAR, PARA PROBAR ESQUEMAS VIEJOS
//  tests/_esquema_desechable.php
//
//  POR QUE EXISTE ESTO. Para comprobar que el codigo nuevo aguanta el esquema
//  viejo hay que tener delante un esquema viejo de verdad. La primera version
//  de la prueba lo consiguio de la peor forma posible: quitando la columna de
//  la base local COMPARTIDA y volviendola a poner en un finally. Aunque el
//  finally corra, los valores ya se perdieron —un DROP COLUMN no se deshace— y
//  ademas el DDL hace COMMIT implicito, asi que cualquier prueba que estuviera
//  dentro de una transaccion se veria confirmada a media faena.
//
//  La regla, despues del incidente de la marca 126 y de este: NINGUNA prueba
//  toca destructivamente el esquema compartido. Si hace falta cambiar la forma
//  de una tabla, se cambia la de una COPIA en una base propia que nace y muere
//  con la prueba.
//
//  LO QUE HACE. Crea `crecer_prueba_<pid>_<azar>`, clona ahi la ESTRUCTURA de
//  las tablas que se le pidan (CREATE TABLE ... LIKE: estructura, cero filas) y
//  devuelve una conexion apuntando a esa base. Sobre esa copia se puede quitar
//  una columna, romper un indice o lo que haga falta.
//
//  LA GUARDA. soltar() solo suelta bases cuyo nombre empieza por el prefijo. Un
//  fallo de programacion que le pasara otro nombre no puede tumbar nada: la
//  clase se niega y lo dice.
//
//  SI NO HAY PERMISOS para crear bases, crear() devuelve null y la prueba se
//  SALTA diciendolo. Saltarse una prueba es honesto; tocar la base compartida
//  para no saltarsela, no.
// ============================================================

final class EsquemaDesechable
{
    /** Todo nombre gestionado aqui empieza asi. La guarda de soltar() lo exige. */
    public const PREFIJO = 'crecer_prueba_';

    /**
     * Las que ESTA corrida tiene abiertas ahora mismo.
     *
     * Sirve para dos cosas: que el barrido no se lleve la propia, y que si
     * al proceso lo matan a media faena se suelte sola al salir. Una muerte
     * interceptable tiene que limpiar lo suyo; lo que no se puede
     * interceptar —un kill duro, un corte de luz— lo recoge la corrida
     * siguiente por edad.
     */
    private static array $abiertas = [];

    private string $nombre;
    private PDO    $pdo;
    private bool   $viva = true;

    private function __construct(string $nombre, PDO $pdo)
    {
        $this->nombre = $nombre;
        $this->pdo    = $pdo;
    }

    /**
     * @param PDO      $base    conexion a la base de verdad (de ahi se clona)
     * @param string[] $tablas  nombres a clonar; '' vacio = todas las crecer_* + usuarios
     * @return self|null  null si el usuario de base de datos no puede crear bases
     */
    public static function crear(PDO $base, array $tablas = []): ?self
    {
        //  EL PUNTO MAS ESTRECHO PARA BARRER: aqui, y solo aqui. Se recoge lo
        //  que otra corrida dejo tirado justo cuando ya se iba a crear una
        //  base de todos modos — nunca desde una pagina, nunca en produccion.
        //  Este archivo vive en tests/ y no lo incluye nada del producto.
        self::barrerHuerfanas($base);

        $origen = (string)$base->query('SELECT DATABASE()')->fetchColumn();
        if ($origen === '') return null;

        $nombre = self::PREFIJO . getmypid() . '_' . bin2hex(random_bytes(3));
        if (!preg_match('/^' . self::PREFIJO . '[a-z0-9_]+$/', $nombre)) return null;

        try {
            $base->exec("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            return null;                       // sin privilegios: la prueba se salta
        }

        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $nombre . ';charset=' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );

            if (!$tablas) {
                $tablas = $base->query("SHOW TABLES LIKE 'crecer_%'")->fetchAll(PDO::FETCH_COLUMN);
                $tablas[] = 'usuarios';        // la fixture siembra el dueño ahi
            }
            //  CREATE TABLE ... LIKE copia la estructura y NO copia las llaves
            //  foraneas, que aqui es justo lo que se quiere: sin ellas no hay
            //  orden obligatorio de creacion y la copia no arrastra la cascada.
            foreach ($tablas as $t) {
                if (!preg_match('/^[a-z0-9_]+$/i', (string)$t)) continue;
                $pdo->exec("CREATE TABLE `{$nombre}`.`{$t}` LIKE `{$origen}`.`{$t}`");
            }
            //  SE APUNTA COMO ABIERTA y se deja dicho como soltarla si a este
            //  proceso lo matan. Una muerte interceptable limpia lo suyo; lo
            //  que no se puede interceptar lo recoge la corrida siguiente por
            //  edad, que es para lo que sirve el barrido de arriba.
            self::$abiertas[$nombre] = true;
            $yo = new self($nombre, $pdo);
            register_shutdown_function(function () use ($yo, $base) {
                try { $yo->soltar($base); } catch (Throwable $e) { /* ya estaba suelta */ }
            });
            return $yo;
        } catch (Throwable $e) {
            try { $base->exec("DROP DATABASE `{$nombre}`"); } catch (Throwable $e2) {}
            throw $e;
        }
    }

    public function pdo(): PDO       { return $this->pdo; }
    public function nombre(): string { return $this->nombre; }

    /** DDL sobre la COPIA. Es el unico sitio del arnes donde esto es legitimo. */
    public function ejecutar(string $sql): void { $this->pdo->exec($sql); }

    /** La suelta entera. Solo si el nombre lleva el prefijo: guarda dura. */
    public function soltar(PDO $base): void
    {
        if (!$this->viva) return;
        if (strpos($this->nombre, self::PREFIJO) !== 0) {
            throw new RuntimeException("Me niego a soltar «{$this->nombre}»: no es una base desechable.");
        }
        $base->exec("DROP DATABASE `{$this->nombre}`");
        $this->viva = false;
        unset(self::$abiertas[$this->nombre]);
    }

    /**
     * Barre bases desechables que quedaron de corridas anteriores (un Ctrl-C a
     * media prueba deja la base viva). Solo las del prefijo, y solo por nombre.
     */
    /**
     * RECOGE LAS BASES QUE OTRA CORRIDA DEJO TIRADAS.
     *
     * POR QUE HACE FALTA. Una suite que muere a la mitad —un Ctrl-C, MySQL que
     * suelta la conexion, un fatal— deja su base de copia viva. La siguiente
     * corrida se la encuentra y se pone roja por algo que no tiene nada que ver
     * con lo que estaba probando. Eso ya paso, y un rojo que no significa nada
     * enseña a ignorar los rojos.
     *
     * LAS CUATRO GUARDAS, y ninguna es adorno:
     *
     *   1 · EL NOMBRE COMPLETO, no «algo parecido». Se exige la forma exacta
     *       que produce crear(): prefijo + pid + guion bajo + seis bytes en hex.
     *       Un barrido por nombres parecidos es como se borra la base de otro.
     *   2 · NUNCA LA BASE EN USO. Ni la que esta conectada ahora, ni la que
     *       declara la configuracion. Se comprueban las dos por separado.
     *   3 · NI LA VIVA DE OTRA SUITE. Dos corridas a la vez son normales aqui,
     *       y borrarle la base a la otra es peor que no barrer nada. Solo se
     *       recoge lo que lleva un rato quieto: la EDAD sale de cuando se
     *       crearon sus tablas, no del nombre.
     *   4 · Y LO PROPIO NO SE TOCA por edad: esta corrida se limpia sola al
     *       salir, aunque la maten.
     *
     * @param int $minutos edad minima. Por debajo, se asume que alguien la usa.
     * @return int cuantas recogio
     */
    public static function barrerHuerfanas(PDO $base, int $minutos = 30): int
    {
        //  El 0 existe SOLO para que la prueba pueda comprobar las otras tres
        //  guardas sin esperar media hora. Ninguna llamada del arnes lo usa: el
        //  valor por defecto es 30 minutos, que es lo que protege a la base viva
        //  de otra corrida.
        $minutos = max(0, $minutos);
        $n = 0;
        try {
            $actual = (string)$base->query('SELECT DATABASE()')->fetchColumn();
            $config = defined('DB_NAME') ? (string)DB_NAME : '';
            $todas  = $base->query("SHOW DATABASES LIKE '" . self::PREFIJO . "%'")
                           ->fetchAll(PDO::FETCH_COLUMN);

            foreach ($todas as $d) {
                $d = (string)$d;
                //  1 · la forma exacta que produce crear(). Nada mas.
                if (!preg_match('/^' . preg_quote(self::PREFIJO, '/') . '\d+_[0-9a-f]{6}$/', $d)) continue;
                //  2 · jamas la base en uso ni la que declara la configuracion.
                if ($d === $actual || ($config !== '' && $d === $config)) continue;
                //  4 · ni las que esta corrida tiene abiertas.
                if (isset(self::$abiertas[$d])) continue;

                //  3 · la edad, sacada de sus propias tablas. Sin tablas no hay
                //  forma de saber si es de hace un minuto o de hace una semana,
                //  y ante la duda no se borra: dejar una de mas es molesto,
                //  borrar la de otro es perderle el trabajo.
                $q = $base->prepare("SELECT MAX(CREATE_TIME) FROM information_schema.TABLES
                                      WHERE TABLE_SCHEMA = ?");
                $q->execute([$d]);
                $creada = $q->fetchColumn();
                if (!$creada) continue;
                $edad = (int)floor((time() - strtotime((string)$creada)) / 60);
                if ($edad < $minutos) continue;

                try { $base->exec("DROP DATABASE `{$d}`"); $n++; }
                catch (Throwable $e) { /* otra corrida la solto entre medias */ }
            }
        } catch (Throwable $e) { /* sin privilegios: nada que barrer */ }
        return $n;
    }
}
