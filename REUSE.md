# REUSE.md — Código e infraestructura pre-existente de Encuéntralo

> Declaración requerida por las reglas del Build with Gemini XPRIZE: el código
> reusado de un proyecto anterior debe declararse. Crecer es un proyecto **nuevo**
> (repo creado en junio 2026) que reusa infraestructura de **Encuéntralo**, un
> directorio de servicios de PR que estaba en desarrollo y **nunca se lanzó**
> (cero usuarios, cero proveedores en producción).

## Qué es nuevo vs. qué se reusa

- **NUEVO (construido para el concurso, en este repo):** todo el producto Crecer
  — el loop agéntico de marketing, las tablas `crecer_*`, la integración Gemini
  vía Vertex AI, el logging de IA, el intake de marca, la generación de contenido
  boricua, la aprobación móvil.
- **REUSADO (pre-existente de Encuéntralo, declarado aquí):** la infraestructura
  de abajo.

## Base de datos (BD MariaDB compartida)

Crecer se conecta a la **misma BD** de Encuéntralo y reusa estas tablas/vistas
pre-existentes:

| Objeto | Uso reusado en Crecer |
|---|---|
| `usuarios` | Auth y perfil del dueño del negocio. |
| `pagos` (+ vista `v_ingresos_mes`) | Billing y revenue por mes (vía Stripe). |
| `fotos` | Sistema de uploads — reusado para el intake de fotos del cliente. |
| `municipios` (78) | Inyección geográfica para la voz boricua. |
| `categorias` / `categorias_padre` | Tipo de negocio. |
| `audit_log` | Base del logging (complementa `crecer_ia_log`). |
| `provider_outreach` | CRM de prospectos para conseguir clientes. |
| `reviews` | Testimonios con verificación por email. |
| `password_resets`, `login_attempts`, `email_verifications` | Seguridad de auth. |

Las tablas nuevas llevan prefijo `crecer_` y conviven con estas.

## Código (módulos PHP reusados de Encuéntralo)

| Componente | Origen | Uso |
|---|---|---|
| `includes/db.php` | Encuéntralo | Conexión PDO + carga de config. |
| `includes/funciones.php` | Encuéntralo | Helpers de datos (categorías, municipios, fotos, auth, audit). |
| `includes/security-headers.php` | Encuéntralo | Headers de seguridad. |
| `includes/email-templates.php` + `templates/` | Encuéntralo | Correo transaccional. |
| Sistema de uploads (`panel/fotos.php`, `api/foto_perfil.php`) | Encuéntralo | Base del intake de fotos. |
| Flujo de auth (`login.php`, `registro.php`, recuperación, verificación) | Encuéntralo | Acceso del dueño. |

## Nota legal/de fechas

El código de Encuéntralo es anterior al 19 de mayo de 2026 (migraciones desde
el 2 de mayo de 2026). Se declara explícitamente como **infraestructura
pre-existente reusada**, no como parte del trabajo nuevo del concurso. El
trabajo evaluable es lo construido en este repo de Crecer a partir de junio 2026.
