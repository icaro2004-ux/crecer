# Conectar Meta (Instagram + Facebook) — guía de setup

> Para habilitar la **publicación automática** del agente publicador.
> El código (conector, OAuth, cron) ya está listo y espera estas credenciales.
> Orden recomendado: 1 → 2 → 3 → 4. App Review (5) es lo más lento: déjalo
> corriendo en paralelo desde temprano.

---

## 0. Antes de empezar — la parte del CLIENTE (se la explicas a cada uno)

Cada negocio que quiera publicación automática DEBE tener:
- Una **Página de Facebook** del negocio.
- Su **Instagram en modo Business o Creator**, **conectado a esa Página**.
  (IG personal NO se puede publicar por API — es regla de Meta, no nuestra.)

Eso se hace gratis desde la app de Instagram → Configuración → Cuenta →
"Cambiar a cuenta profesional", y luego vincularla a la Página de FB.

---

## 1. Crear la app de Meta

1. Entra a **https://developers.facebook.com** con tu cuenta de Facebook.
2. Arriba a la derecha: **My Apps → Create App**.
3. Tipo de app / caso de uso: escoge **"Other" → "Business"** (te da acceso a
   los permisos de Páginas e Instagram).
4. Ponle nombre (ej. "Crecer / Encuéntralo") y asóciala a tu **Business
   Manager** (créalo si no tienes — lo necesitarás para App Review).

## 2. Agregar los productos y permisos

Dentro de la app:
1. **Add Product → Facebook Login for Business** (o "Facebook Login").
2. **Add Product → Instagram** (la opción "Instagram API con Facebook Login").
3. Los permisos que tu app va a pedir (ya están en `includes/meta.php`):
   - `pages_show_list`
   - `pages_read_engagement`
   - `pages_manage_posts`
   - `instagram_basic`
   - `instagram_content_publish`
   - `business_management`

## 3. Configurar credenciales y redirect

1. **App Settings → Basic:**
   - Copia el **App ID** y el **App Secret**.
   - Llena **App Domains** (tu dominio), **Privacy Policy URL** (obligatoria —
     debe estar publicada y accesible), ícono y categoría.
2. **Facebook Login → Settings → Valid OAuth Redirect URIs:**
   - Agrega EXACTO: `https://TU-DOMINIO/crecer/panel/conectar.php`
   - (Debe coincidir carácter por carácter con `META_REDIRECT_URI`.)
3. En `includes/config.local.php` del **servidor de producción** pon:
   ```php
   define('META_APP_ID',       'TU_APP_ID');
   define('META_APP_SECRET',   'TU_APP_SECRET');
   define('META_REDIRECT_URI', 'https://TU-DOMINIO/crecer/panel/conectar.php');
   define('META_GRAPH_VERSION','v21.0');
   define('CRON_TOKEN',        'una-clave-larga-al-azar'); // para el cron por URL
   ```
   > Nota: el OAuth necesita **HTTPS** y un dominio real (no localhost). Por eso
   > esta parte se prueba ya desplegado, no en XAMPP local.

## 4. Probar YA con tus clientes (sin esperar App Review)

En **App Mode** la app empieza en **Development**. En ese modo, SOLO la gente
con rol en la app puede usarla — perfecto para tu fase de feedback:

1. **App Roles → Roles:** agrega a tus clientes como **Testers** (o
   "Instagram Testers" si aparece esa sección).
2. El cliente recibe una invitación y la **acepta** (en su configuración de
   Facebook → Business Integrations, o en el email).
3. Ya puedes correr el flujo real: el cliente entra a **Configuración → Redes →
   Conectar**, autoriza, elige su Página, y el corillo publica a sus cuentas
   reales. (Límite ~25 usuarios en este modo — suficiente para empezar.)

## 5. App Review (para escalar a clientes fuera de la lista de testers)

Cuando quieras onboarding abierto (cualquier cliente, sin agregarlo a mano):
1. **Business Verification** de tu Business Manager (Meta verifica tu negocio).
2. **App Review → Permissions and Features:** pide **Advanced Access** de los 6
   permisos de la sección 2.
3. Te pedirán: política de privacidad publicada, descripción del uso, y un
   **screencast** mostrando el flujo completo (login → elegir página → publicar).
4. Tiempo: de días a semanas. Por eso conviene empezarlo temprano y mientras
   tanto operar con testers (sección 4).

---

## Checklist rápido

- [ ] App creada + Business Manager asociado
- [ ] Productos Facebook Login + Instagram añadidos
- [ ] App ID / App Secret en `config.local.php` de producción
- [ ] Redirect URI registrado = `META_REDIRECT_URI` (exacto)
- [ ] Privacy Policy URL publicada
- [ ] Desplegado con HTTPS + `uploads/` público
- [ ] Cron de `scripts/cron_publicar.php` activo (cada ~10 min)
- [ ] Clientes agregados como Testers (fase feedback)
- [ ] Business Verification + App Review iniciados (para escalar)
