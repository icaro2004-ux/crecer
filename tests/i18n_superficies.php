<?php
// ============================================================
//  CRECER — EL MAPA DE SUPERFICIES  ·  tests/i18n_superficies.php
//
//  Qué es: la declaración de qué pantallas se auditan, en qué estado está cada
//  una, y qué queda fuera CON EL MOTIVO ESCRITO al lado.
//
//  POR QUÉ ESTO ES UN ARCHIVO Y NO UNAS LÍNEAS DENTRO DE LA PRUEBA:
//  una exclusión es la forma más fácil de poner una suite en verde sin arreglar
//  nada. Si viven sueltas dentro del test, nadie las vuelve a mirar. Aquí están
//  todas juntas, cada una con su razón, y la prueba las trata como afirmaciones
//  que también se pueden romper:
//
//    · Una excepción que apunta a un archivo que ya no existe → FALLA.
//      (Una exclusión muerta tapa lo que venga después con ese nombre.)
//    · Una excepción sin motivo escrito → FALLA.
//    · Un nombre propio declarado que ya no aparece en ninguna parte → FALLA.
//
//  Es decir: excluir cuesta mantenimiento. Que es exactamente lo que tiene que
//  costar, o se convierte en la salida fácil.
// ============================================================

// ════════════════════════════════════════════════════════════
//  1 · LAS FAMILIAS
// ════════════════════════════════════════════════════════════
//  estado:
//    'exigida'   — ya migrada. CERO cadenas fuera del catálogo. Sin margen.
//    'pendiente' — todavía no migrada. Se mide y se pone un tope: puede bajar,
//                  nunca subir. Es el trinquete que impide que el trabajo nuevo
//                  añada deuda mientras se limpia la vieja.
//    'interna'   — no la ve un cliente. Fuera de la auditoría pública, dicho.
//    'excepcion' — se ve, y aun así no se traduce. Motivo obligatorio.

const I18N_FAMILIAS = [

    'shell' => [
        'titulo'  => 'Shell, navegación y componentes compartidos',
        'estado'  => 'exigida',
        'porque'  => 'Es lo único que sale en las 71 pantallas. Si el menú está '
                   . 'mezclado, da igual lo limpia que esté la pantalla de abajo.',
        'globs'   => ['panel/_shell.php', 'panel/_shell_foot.php'],
    ],

    'panel' => [
        'titulo'  => 'Pantallas del panel',
        'estado'  => 'pendiente',
        'porque'  => 'El grueso del producto. Se migra por dominios, no de golpe.',
        'globs'   => ['panel/*.php'],
        'excepto' => ['panel/_shell*.php', 'panel/_ops_top.php', 'panel/admin*.php'],
    ],

    'publico' => [
        'titulo'  => 'Landing, registro y cuenta',
        'estado'  => 'pendiente',
        'porque'  => 'Es la primera pantalla que ve alguien que no es cliente todavía.',
        'globs'   => ['*.php'],
        'excepto' => ['privacidad.php', 'terminos.php', 'eliminar-datos.php',
                      '_cache.php', '_imgtry.php', 'webhook*.php'],
    ],

    'dominio' => [
        'titulo'  => 'Mensajes del dominio (includes/)',
        'estado'  => 'pendiente',
        'porque'  => 'Aquí nacen los errores y los correos. Hoy es la peor cobertura '
                   . 'del repo (1%) y es la que el cliente lee cuando algo falla.',
        'globs'   => ['includes/*.php'],
        'excepto' => ['includes/i18n.php', 'includes/config*.php'],
    ],

    // ── Fuera de la auditoría pública, con nombre y apellido ──
    'admin' => [
        'titulo'  => 'Centro de Operaciones (admin_*)',
        'estado'  => 'interna',
        'porque'  => 'Superficie INTERNA, exclusivamente en español. La usa el equipo '
                   . 'de Crecer, no un cliente ni un juez. Traducir 150 cadenas que '
                   . 'nadie va a leer en inglés es trabajo sin lector. Queda fuera de '
                   . 'la auditoría pública y se declara así, no se esconde.',
        // _ops_top.php es la barra de Operaciones: SOLO la incluyen los admin_*.
        // No es el shell del cliente, aunque viva en la misma carpeta.
        'globs'   => ['panel/admin*.php', 'panel/_ops_top.php'],
    ],

    'herramientas' => [
        'titulo'  => 'Herramientas de desarrollo',
        'estado'  => 'interna',
        'porque'  => '_cache.php es el panel de diagnóstico y _imgtry.php el '
                   . 'laboratorio de imágenes. Ninguno tiene ruta desde el producto: '
                   . 'se abren a mano, por quien los escribió.',
        'globs'   => ['_cache.php', '_imgtry.php'],
    ],

    'legal' => [
        'titulo'  => 'Documentos legales',
        'estado'  => 'excepcion',
        'porque'  => 'Un documento legal no se traduce cadena por cadena ni con IA: '
                   . 'se redacta entero por idioma y lo revisa alguien que responda '
                   . 'por él. Hasta que exista esa revisión, se sirve el español con '
                   . 'un aviso en inglés. Publicar un inglés sin revisar y dejar que '
                   . 'parezca jurídicamente equivalente es peor que no tenerlo.',
        'globs'   => ['privacidad.php', 'terminos.php', 'eliminar-datos.php'],
        // El aviso que tiene que salir mientras no haya versión inglesa revisada.
        // La prueba comprueba que esté, con estas palabras exactas.
        'aviso_en' => 'This legal document is currently available in Spanish only.',
    ],
];

// ════════════════════════════════════════════════════════════
//  2 · LO QUE NO SE TRADUCE AUNQUE ESTÉ EN UNA FAMILIA EXIGIDA
// ════════════════════════════════════════════════════════════
//  Nombres propios y marcas. No es una lista de conveniencia: cada uno está
//  aquí porque traducirlo sería un error, no porque dé pereza.
//
//  El criterio: si es el NOMBRE de algo, se queda. Si describe un ROL o una
//  ACCIÓN, se traduce. Por eso «Crecer» se queda y «Tu Meta» no: uno es cómo se
//  llama el producto, el otro es lo que hace la pantalla.

const I18N_NOMBRES_PROPIOS = [
    'Crecer'        => 'el nombre del producto',
    'Encuéntralo'   => 'el nombre de la empresa',
    'by Encuéntralo'=> 'la firma de marca del logotipo',
    '— Encuéntralo' => 'la firma de marca en el <title>',
    '© Encuéntralo · Crecer' => 'el aviso de copyright',
    'Instagram'     => 'nombre de la red',
    'Facebook'      => 'nombre de la red',
    'WhatsApp'      => 'nombre de la red',
    'Reels'         => 'formato de Instagram; en inglés se llama igual',
    'Stripe'        => 'nombre del proveedor de cobro',
    'ES'            => 'código de idioma del interruptor',
    'EN'            => 'código de idioma del interruptor',
    'admin'         => 'el rol, tal cual está en la base de datos',
];

// ════════════════════════════════════════════════════════════
//  3 · RESOLVER LAS FAMILIAS A ARCHIVOS
// ════════════════════════════════════════════════════════════

/** Los archivos de una familia, ya descontando sus excepciones. */
function i18n_archivos_de(array $fam, string $raiz): array {
    $todos = [];
    foreach ($fam['globs'] as $g) {
        foreach (glob($raiz . '/' . $g) ?: [] as $f) $todos[] = str_replace('\\', '/', $f);
    }
    foreach ($fam['excepto'] ?? [] as $g) {
        foreach (glob($raiz . '/' . $g) ?: [] as $f) {
            $f = str_replace('\\', '/', $f);
            $todos = array_values(array_filter($todos, fn($x) => $x !== $f));
        }
    }
    sort($todos);
    return array_values(array_unique($todos));
}

/** La ruta relativa a la raíz del repo, para que el informe sea legible. */
function i18n_rel(string $abs, string $raiz): string {
    $abs = str_replace('\\', '/', $abs);
    $raiz = str_replace('\\', '/', $raiz);
    return (strpos($abs, $raiz) === 0) ? ltrim(substr($abs, strlen($raiz)), '/') : $abs;
}

// ════════════════════════════════════════════════════════════
//  4 · EL TRINQUETE
// ════════════════════════════════════════════════════════════
//  El tope de deuda de cada familia todavía sin migrar. La prueba deja que
//  BAJE y falla si SUBE. No es una meta: es un techo.
//
//  Bajarlo es un cambio deliberado y se ve en el diff, que es justo lo que
//  tiene que pasar cuando se gana terreno. Subirlo, también — y ahí hay que
//  explicar por qué se añadió texto a mano en vez de migrar la pantalla.
const I18N_TOPES = [
    'panel'   => 2532,
    'publico' =>  321,
    'dominio' => 1691,
];
