<?php
// ============================================================
//  CRECER — Plantilla de configuración local
//  includes/config.local.example.php
//
//  Copiar a `config.local.php` y rellenar con credenciales reales.
//  config.local.php NO se versiona (.gitignore) ni se sube por FTP
//  a producción desde local — cada ambiente tiene su propio archivo.
// ============================================================

// 'local' o 'prod'. Controla error reporting y comportamiento de BASE_URL.
define('APP_ENV', 'local');

// Si comentas esta línea, db.php detecta BASE_URL desde HTTP_HOST.
define('BASE_URL', 'http://localhost/crecer');

// ── DB (compartida con Encuéntralo) ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'encuentralo_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── Uploads (opcional; db.php los deriva si no los defines) ──
// LOCAL: define('UPLOADS_PATH', 'C:/xampp/htdocs/crecer/uploads');
//        define('UPLOADS_URL',  '/crecer/uploads');

// ── Gemini / Vertex AI ──
// Opción rápida: GEMINI_API_KEY de AI Studio (un solo valor).
define('GEMINI_API_KEY', '');
// Opción Vertex: ruta al JSON del service account (NO versionar).
define('GOOGLE_APPLICATION_CREDENTIALS', '');
define('GCP_PROJECT_ID', '');
define('GCP_LOCATION',   'us-central1');
define('GEMINI_MODEL',   'gemini-2.5-flash'); // 2.0-flash no tiene free tier; 2.5 sí

// ── OpenAI (imágenes) — OPCIONAL ──
// El Director de Arte (Gemini) enruta el arte conceptual DESDE CERO a OpenAI y
// deja la edición de fotos reales en Gemini (Nano Banana). Si dejas esto vacío,
// TODO corre en Gemini (nada se rompe). Con respaldo automático entre motores.
// La llave sale de platform.openai.com → API keys (requiere billing activo).
define('OPENAI_API_KEY',  '');
define('OPENAI_IMG_MODEL','gpt-image-1');
// Copiloto de Encuentralo: limites de costo/abuso. Puedes usar otro modelo
// solo para el chat sin afectar planificador, creador ni imagenes.
// define('CRECER_COPILOTO_MODEL', 'gemini-2.5-flash-lite');
// define('CRECER_COPILOTO_HORA', 3);         // por negocio con plan
// define('CRECER_COPILOTO_DIA', 8);          // por negocio con plan
// define('CRECER_COPILOTO_FREE_DIA', 3);     // por negocio sin plan
// define('CRECER_COPILOTO_GLOBAL_DIA', 80);  // todo Crecer

// ── CRECER Kernel v1 (orquestación experimental) ────────────────────────────
// OFF por defecto. Al prenderlo, Inicio puede consumir el briefing del Kernel.
// define('CRECER_KERNEL_V1_ENABLED', false);

// ── Stripe ──
define('STRIPE_SECRET_KEY',      '');
define('STRIPE_PUBLISHABLE_KEY',  '');
define('STRIPE_WEBHOOK_SECRET',   '');

// ── Meta (Instagram + Facebook) — publicación automática ──
// De tu app en developers.facebook.com. Publicar a nombre de clientes
// requiere App Review con Advanced Access de los permisos en META_SCOPES.
define('META_APP_ID',       '');
define('META_APP_SECRET',    '');
// A dónde regresa Meta tras el login (debe coincidir EXACTO con el que
// registres en la app de Meta). En prod usa https://tu-dominio/...
define('META_REDIRECT_URI', BASE_URL . '/panel/conectar.php');
define('META_GRAPH_VERSION', 'v21.0');

// ── Cron del publicador ──
// Si corres el cron por URL (no CLI), protégelo con este token:
//   https://tu-dominio/crecer/scripts/cron_publicar.php?key=XXXX
define('CRON_TOKEN', '');

// Correo del FUNDADOR para el reporte diario de operaciones (cron_reporte_diario.php).
// Si se deja vacío, cae a jmp.arch.eng@gmail.com.
define('REPORTE_EMAIL', '');

// ── META PIXEL (medición de conversión) ──────────────────────────────────────
// El ID vive en includes/meta_pixel.php (no es secreto: va en claro en el HTML).
// Estas dos líneas solo hacen falta si quieres CAMBIARLO por entorno:
//
//   define('META_PIXEL_ID',    '1514881943771970');  // '' lo apaga por completo
//   define('META_PIXEL_PANEL', true);                // false = solo páginas públicas
//
// META_PIXEL_PANEL controla si el Pixel entra también al app del cliente. Ahí ya no
// hay marketing que medir —es alguien que ya pagó— y cada pantalla que visita se le
// reporta a Meta. Ponlo en false si prefieres medir solo el embudo.

// ── EL AYUDANTE (helper que arregla y escala) ────────────────────────────────
// Cuando el Ayudante no puede arreglar algo solo, levanta el caso y AVISA aquí:
// email con la explicación completa + SMS corto. Sin estas constantes el caso
// igual queda escrito en crecer_incidencias, pero el aviso no sale.
//   define('CRECER_FUNDADOR_EMAIL', 'tucorreo@gmail.com');   // vacío → usa REPORTE_EMAIL
//   define('CRECER_FUNDADOR_SMS',   '787-555-1234');         // tu celular
//
// El TEXTO al celular sale por una de dos rutas (se intenta en este orden):
//
//  1) CORREO→TEXTO (gratis, sin papeleo) — RECOMENDADO para avisos al fundador.
//     El buzón de tu compañía celular entrega el correo como SMS. Pon el dominio:
//       define('CRECER_SMS_GATEWAY', 'tmomail.net');   // T-Mobile
//     También acepta apodo ('tmobile', 'att', 'verizon') o la dirección completa.
//     Otra compañía: busca "<tu compañía> email to SMS gateway" y pon ese dominio.
//
//  2) TWILIO MESSAGES — lo formal, si algún día hace falta mandarle a clientes.
//     Verify NO sirve (solo manda códigos) y esto exige número propio + registro
//     A2P 10DLC. Usa UNO de los dos:
//       define('TWILIO_FROM',          '+17875550100');       // número comprado
//       define('TWILIO_MESSAGING_SID', 'MGxxxxxxxxxxxxxxxx'); // Messaging Service

// ── OBLIGATORIA EN PRODUCCIÓN ────────────────────────────────────────────────
// Llave de los workers async internos (arte, gen, carrusel, reels, sala, publicar,
// relevo). SIN ella los ocho workers FALLAN CERRADO: responden 503 y NO ejecutan
// trabajo. No hay literal de respaldo — antes lo había y era una trampa: si el
// config desaparecía tras un deploy, los workers adoptaban en silencio una llave
// que vive en el repo (CR-F01b, 2026-08-02).
//
// Genérala aleatoria y no la compartas:  php -r "echo bin2hex(random_bytes(16));"
// Rótala si alguna vez se imprimió, se pegó en un chat o viajó por una URL.
//
// Vacía = local sin workers async (el sweep recoge el trabajo igual, más lento).
define('CRECER_WORKER_KEY', '');

// ── MODO PRUEBA (sandbox) — activar "Activar Crecer" SIN Stripe ──────────────
// Para probar el flujo completo con cuentas de prueba, sin cobrar.
//
//  · LOCAL (tu XAMPP): activa TODAS las cuentas. NO usar en producción.
//      define('CRECER_DEV_ACTIVAR', true);
//
//  · PRODUCCIÓN (probar en el celular): activa SOLO estos emails. Seguro —
//    los usuarios reales siguen pagando por Stripe. Registra cuentas de prueba
//    con estos emails y actívalas gratis desde el teléfono.
//    Estas cuentas, además, ENTRAN SIN VERIFICAR POR CORREO (registro directo),
//    así puedes probar el flujo completo desde la página aunque el email falle.
//      define('CRECER_TEST_EMAILS', 'tucorreo+prueba1@gmail.com, tucorreo+prueba2@gmail.com, tucorreo+prueba3@gmail.com');

// ── EMAIL / SMTP (activación de cuentas y avisos) ────────────────────────────
// SIN estas constantes, crecer_enviar_email() cae a mail() de PHP, que NO
// entrega a Gmail/Yahoo → "los correos de creación de cuenta no llegan".
// ARREGLO EN PROD: copia los valores EXACTOS del config de Encuéntralo que YA
// funciona (mismo servidor Hostinger): abre en hPanel el
// includes/config.local.php de Encuéntralo y copia SMTP_HOST / SMTP_USER /
// SMTP_PASS aquí. Puerto 465 (SSL). El USER es la cuenta de correo completa.
define('SMTP_HOST',     '');                          // ej. smtp.hostinger.com
define('SMTP_PORT',     465);
define('SMTP_USER',     '');                          // ej. hola@encuentraloahora.com
define('SMTP_PASS',     '');                          // contraseña de esa cuenta
define('SMTP_FROM',     'admin@encuentraloahora.com'); // remitente visible
define('SMTP_FROMNAME', 'Encuéntralo Crecer');

// ── CREAR unificado (opcional) ──
// ON: todo "crear un post" (Idea del día, botones del Estudio) abre el wizard
// DENTRO de El Estudio (propuestas.php) — una sola superficie, una sola piel.
// OFF/ausente: los enlaces van a aprobar2.php como siempre (comportamiento viejo).
// define('CRECER_CREAR_UNIFICADO', true);
