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
            return new self($nombre, $pdo);
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
    }

    /**
     * Barre bases desechables que quedaron de corridas anteriores (un Ctrl-C a
     * media prueba deja la base viva). Solo las del prefijo, y solo por nombre.
     */
    public static function barrerHuerfanas(PDO $base): int
    {
        $n = 0;
        try {
            $todas = $base->query("SHOW DATABASES LIKE '" . self::PREFIJO . "%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($todas as $d) {
                if (strpos((string)$d, self::PREFIJO) !== 0) continue;
                $base->exec("DROP DATABASE `{$d}`"); $n++;
            }
        } catch (Throwable $e) { /* sin privilegios: nada que barrer */ }
        return $n;
    }
}
