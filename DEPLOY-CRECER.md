# Deploy de Crecer a producción (Hostinger + FileZilla)

> **Opción A** — la más fácil: Crecer vive en `public_html/crecer/` y el
> dominio redirige a `/crecer/`. **Cero cambios de código.**
> Todo lo que necesitas ya está en este bundle.

---

## Antes de empezar — ten a mano
- Acceso FTP/SFTP de Hostinger (lo usas con FileZilla).
- Acceso a **phpMyAdmin** de Hostinger.
- Tu **GEMINI_API_KEY** (cópiala de tu `includes/config.local.php` local).
- Datos de la **BD de producción** (Hostinger → Bases de datos MySQL: nombre, usuario, contraseña).

---

## 1) Base de datos (5 min)
1. Entra a **phpMyAdmin** en Hostinger y selecciona la BD de producción.
2. Pestaña **Importar** (o **SQL**) → sube/pega el archivo:
   **`migrations/_deploy_all_crecer.sql`**
3. Ejecuta. Crea/actualiza todas las tablas `crecer_*` (es idempotente —
   si ya existían, no rompe nada).

## 2) Subir los archivos por FileZilla (10 min)
1. Conéctate con FileZilla a tu Hostinger.
2. Sube **toda la carpeta local `crecer/`** a **`public_html/crecer/`**.
   - **NO subas** tu `includes/config.local.php` local (tiene rutas de
     Windows y llaves de prueba). Si FileZilla lo sube, lo reemplazas en
     el paso 3.
   - Puedes saltarte: `_deploy/`, `scripts/_test_*` (no existen, son de
     prueba), y `migrations/_backup_*`.
3. Asegúrate de que exista la carpeta **`public_html/crecer/uploads/`** y
   que tenga **permisos de escritura** (chmod **755** o **775**). Ahí caen
   las imágenes que genera el corillo.

## 3) Config de producción (5 min)
1. En el servidor, copia **`includes/config.prod.template.php`** como
   **`includes/config.local.php`**.
2. Edítalo (con el editor de Hostinger o súbelo ya editado) y rellena:
   - `BASE_URL` → tu dominio + `/crecer` (ej. `https://encuentralo.com/crecer`)
   - `DB_NAME / DB_USER / DB_PASS` → los de la BD de prod
   - `GEMINI_API_KEY` → tu key real
   - `CRON_TOKEN` → una clave larga al azar
   - Stripe / Meta → los llenas cuando toque (no bloquean el lanzamiento)

## 4) Redirect del dominio a Crecer (2 min)
Sube **`_deploy/root-index.php`** como **`public_html/index.php`**.
Así, quien entra al dominio cae directo en Crecer.
*(Alternativa: el `.htaccess` en `_deploy/htaccess-root.txt`.)*

## 5) Probar (5 min)
- Abre **`https://TU-DOMINIO/`** → debe mandarte a `…/crecer/` y cargar el landing.
- `…/crecer/registro.php` → crea una cuenta de prueba → onboarding.
- `…/crecer/panel/index.php` → el panel del cliente.
- `…/crecer/panel/admin.php` → tu Centro de Operaciones (necesitas una
  cuenta con `rol='admin'` — ver paso 6).

## 6) Cuenta admin + cron (10 min)
- **Admin:** en phpMyAdmin, pon `rol='admin'` en tu usuario operador
  (uno dedicado, no un cliente). En local usamos `jmp.arch.eng`; en prod
  crea/usa el tuyo.
- **Cron (Hostinger → Cron Jobs):**
  - Publicador (cada ~10 min):
    `https://TU-DOMINIO/crecer/scripts/cron_publicar.php?key=TU_CRON_TOKEN`
  - Corillo autónomo (1 vez por semana, ej. lunes 7am):
    `https://TU-DOMINIO/crecer/scripts/cron_corillo.php?key=TU_CRON_TOKEN`

---

## Pre-flight / ojo con esto (no rompe el deploy, pero atiéndelo)
- **APP_ENV=prod** ya oculta errores al público (está en la plantilla). ✔
- **Revenue en `pagos`:** el webhook de Stripe aún no escribe cada cobro en
  `pagos` (producto='crecer'). Para evidencia limpia del XPRIZE hay que
  cerrarlo. (Lo vemos juntos.)
- **Data semilla:** las ~430 reseñas ficticias y las marcas/subs de prueba
  no deben presentarse como reales. Idealmente arrancas clientes limpios.
- **Meta App Review:** empiézalo en paralelo (lento). Mientras, el botón
  **"Publicar"** ya pasa el post a IG/FB a mano — el loop cierra sin esperar.

---

## Cómo seguir trabajando después
Mantén tu carpeta local como la **fuente de la verdad**: editas en local,
pruebas, y subes por FileZilla solo los archivos que cambiaste. (Cuando
quieras, montamos git para que el deploy sea un `push` y no arrastrar
archivos a mano.)
