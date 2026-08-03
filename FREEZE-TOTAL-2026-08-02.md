# Crecer — Verificación final y gobernanza del cierre

> *(El archivo se sigue llamando `FREEZE-TOTAL-...` por los enlaces que ya apuntan aquí.
> El estado vigente es el de abajo, no el del nombre.)*
>
> Deadline XPRIZE: 17 de agosto de 2026, 1:00 p. m. PDT
> Responsable de decisión: Manuel

## ESTADO VIGENTE · READY MODE (desde el 2 de agosto de 2026)

**El freeze total se difiere hasta ~48–72 h antes de la entrega (≈ 14 de agosto).**

Congelar hoy sería prematuro: todavía se está certificando producción, entran clientes y
aparecen bugs reales. Un freeze aquí convertiría el proceso en burocracia y retrasaría
correcciones necesarias. El objetivo de esta etapa **no es "no tocar código"** — es que
**cada cambio tenga una razón observable** y acerque el producto a clientes o a una
entrega confiable.

### Se permite en READY MODE

- Corregir bugs. **Basta con que el bug sea claro** — no hace falta que lo sufran dos
  clientes. Esa regla, que estaba en la versión anterior de este documento, era demasiado
  rígida: esperar a un segundo afectado es cobrarle a alguien el precio de nuestro proceso.
- Seguridad, pagos, evidencia y confiabilidad.
- Mejoras que salgan del uso real de un cliente.
- Ajustes pequeños de UX que desbloqueen conversión.

### Sigue fuera

Features grandes · agentes nuevos · rediseños · ampliaciones de alcance · refactors de
limpieza · dependencias nuevas · cambios de precio, onboarding o arquitectura sin motivo
verificado.

### Condiciones de cada cambio

Aislado · probado · reversible · con su razón observable escrita en el commit. Los cambios
de READY MODE se anotan en la bitácora del final de este documento — una línea cada uno.
Sin esa línea, la regla es un deseo; con ella, es verificable (y de paso es evidencia de
"qué cambió y por qué" para el jurado).

## Estados y cuándo cambia cada uno

```text
Ahora              READY MODE          bugs, seguridad, pagos, evidencia, UX que convierte
Antes de enviar    RELEASE CANDIDATE   solo lo que impida entregar
Últimas 48–72 h    FREEZE TOTAL        solo P0/P1 · smoke completo tras cada commit
Entrega            TAG                 XPRIZE-2026-FINAL
```

El **FREEZE TOTAL** se firma cuando estén los siete: precio reconciliado · producción
certificada · clientes y evidencia capturados · cuenta de juez funcionando · video casi
final · narrativa cerrada · backup y rollback verificados.

Durante esas 48–72 h: solo P0/P1 · ningún cambio visual innecesario · ninguna migración no
indispensable · no se tocan precios, onboarding ni arquitectura · smoke completo después
de cada commit · tag del release final.

> Lo que sigue abajo —la auditoría, la matriz de cierre y los criterios— **sigue vigente**:
> es lo que hay que terminar para poder firmar el freeze.

## Propósito

Este documento es la orden de trabajo para que Claude haga una última verificación integral de Crecer y deje evidencia reproducible antes de cerrar el desarrollo de producto.

La meta no es encontrar razones para añadir funcionalidades. La meta es confirmar que Crecer puede recibir clientes reales, cobrar, producir, publicar, recuperarse de fallos y generar evidencia confiable para el XPRIZE.

Al terminar esta auditoría:

1. Se revisan los hallazgos con Manuel.
2. Se corrigen únicamente bloqueadores P0/P1 aprobados.
3. Se ejecuta una verificación corta de regresión.
4. Entra en vigor el **freeze total**.

---

## Regla principal para Claude

> **Estado de esta regla:** la auditoría ya se hizo (2026-08-02) y está en
> `INFORME-VERIFICACION-FINAL-2026-08-02.md`. En READY MODE **sí se corrige código**,
> bajo las condiciones de arriba: aislado, probado, reversible y con su línea en la
> bitácora. Lo que sigue de este apartado gobierna cualquier auditoría futura y el modo
> de reportar hallazgos, que no cambia.

Primero audita y reporta. **No modifiques código durante la auditoría.**

No hagas refactors, rediseños, mejoras cosméticas ni features nuevas. No ejecutes cobros, publicaciones, SMS, borrados, reembolsos o acciones sobre clientes reales sin autorización explícita de Manuel.

Preserva todos los cambios existentes del worktree. Si encuentras un problema, documenta:

- severidad;
- evidencia exacta;
- archivo y línea;
- impacto para clientes/XPRIZE;
- reproducción segura;
- corrección mínima sugerida;
- verificación que probaría el arreglo.

No declares `PASS` basándote únicamente en que una página responde HTTP 200 o un script imprime “smoke limpio”. Verifica también la expectativa semántica del resultado.

---

## Definición del freeze total

> **Aplica en la VENTANA DE FREEZE (últimas 48–72 h), no ahora.** Hoy rige READY MODE.

Durante la ventana de freeze quedan prohibidos:

- features nuevas;
- nuevas pantallas o agentes;
- rediseños;
- cambios de arquitectura no indispensables;
- nuevos planes, precios o experimentos de monetización;
- refactors por limpieza;
- cambios de copy sin evidencia de clientes;
- dependencias nuevas;
- ampliaciones de alcance para el XPRIZE.

Solo se permiten cuatro excepciones:

1. Un fallo impide registrar, cobrar, generar, aprobar o publicar a un cliente real.
2. Existe riesgo de seguridad, privacidad, cobro incorrecto o pérdida de datos.
3. Un requisito verificable del XPRIZE está incompleto.
4. Un bug claro bloquea activación o retención. *(Antes esto exigía **dos** clientes
   afectados. Revocado el 2026-08-02: si el bug es claro, esperar a un segundo afectado
   es cobrarle a alguien el precio de nuestro proceso.)*

Toda excepción debe tener:

- issue concreto;
- alcance mínimo;
- aprobación de Manuel;
- prueba de regresión;
- commit aislado y reversible.

La respuesta predeterminada a cualquier otra idea es: **BACKLOG DESPUÉS DEL XPRIZE**.

---

# Auditoría final obligatoria

## 1. Estado del repositorio y reproducibilidad

Verificar:

- rama y sincronización con remoto;
- worktree limpio o cambios explicados;
- archivos ignorados que contienen configuración real;
- ausencia de secretos en archivos trackeados y en el historial relevante;
- que una instalación para jueces tenga instrucciones suficientes;
- que el repositorio privado esté compartido con los correos requeridos, o que el público tenga licencia y configuración segura.

Comandos base:

```powershell
git status -sb
git branch -vv
git remote -v
git log -10 --oneline
git ls-files
git check-ignore -v includes/config.local.PROD-LISTO.php
```

No imprimir valores de secretos en el reporte. Reportar únicamente nombre, presencia, longitud aproximada y si parece placeholder.

**Pasa si:** el código juzgable está sincronizado, no contiene credenciales y puede configurarse sin depender de archivos privados desconocidos.

## 2. Sintaxis y pruebas

Ejecutar lint sobre todos los PHP fuera de `vendor`:

```powershell
$php = 'C:\xampp\php\php.exe'
$files = rg --files -g '*.php' -g '!vendor/**'
foreach ($f in $files) { & $php -l $f }
```

Ejecutar pruebas unitarias e integración:

```powershell
& $php tests/test_creative_thesis_unit.php
& $php tests/test_pipeline_tesis_integracion.php
& $php tests/test_creador_editorial.php
& $php tests/smoke_creative_thesis_funcional.php   # CR-F04: ahora es bloqueante
```

Las cuatro deben terminar con **exit code 0**. El cuarto entró en la lista el 2 de agosto
(CR-F04): antes imprimía y siempre decía "smoke limpio"; ahora aserta y sale con 1 ante
cualquier expectativa incumplida. Corre determinista y sin costo por defecto; con `--vivo`
añade dos casos contra el modelo real.

Los smoke tests que llaman proveedores reales requieren autorización si generan costo o escriben datos. Si se autorizan, evaluar manualmente sus expectativas además del exit code.

### Creative Thesis: construido y probado, NO gobernando producción

Registro explícito para que no se cuente de más (2026-08-02):

`includes/creative_thesis.php` está implementado y cubierto por pruebas — incluida la
compuerta de suficiencia de CR-F03, que se abstiene antes de llamar al modelo cuando el
genoma no da para decir algo verdadero. **Pero vive detrás del feature flag
`VOICE_DNA_ONBOARDING_ENABLED`, que está OFF.** Hoy no decide el contenido que sale.

**Prohibido presentarlo en el video, en Devpost o en cualquier texto de la entrega como
operación activa.** Se puede mostrar como capacidad construida y probada; no como algo que
está gobernando el producto. Si el flag se enciende antes de la entrega, se actualiza este
registro con la fecha y se puede hablar de él en presente.

### Hallazgo conocido que debe verificarse

`tests/smoke_creative_thesis_funcional.php` declara que el genoma pobre “Mi Negocito / Vendo cosas” debe producir `abstained`, pero en la corrida del 2 de agosto Gemini devolvió `accepted` e inventó una narrativa sofisticada. El script terminó con “smoke limpio” porque imprime, pero no aserta, la expectativa.

Claude debe confirmar:

- si el caso pobre sigue siendo aceptado;
- si la compuerta determinista permite evidencia insuficiente;
- si el test puede fallar realmente ante la regresión;
- cuál es el arreglo mínimo, sin rediseñar el pipeline.

**Pasa si:** cero errores de sintaxis, todas las pruebas bloqueantes pasan y ninguna expectativa importante se valida solo visualmente.

## 3. Seguridad y aislamiento multi-tenant

Auditar todas las acciones mutables y endpoints JSON, especialmente los añadidos desde el 30 de julio.

Cada acción de cliente debe demostrar:

- sesión válida;
- CSRF para mutaciones desde navegador;
- pertenencia de `marca_id` al usuario, salvo admin explícito;
- pertenencia del recurso hijo a la marca (`contenido`, reel, carrusel, gráfica, evento, incidencia, etc.);
- consultas y updates acotados por tenant;
- respuesta 403 sin filtrar datos ante IDs ajenos.

Revisar particularmente:

- `panel/aprobar2.php`;
- `panel/ayudante.php`;
- `panel/tour_visto.php`;
- `panel/admin_incidencias.php`;
- workers de arte, carrusel, reels, Sala, publicación y generación;
- endpoints de Stripe, Meta y diagnóstico;
- archivos auxiliares `_cache.php` y `_imgtry.php`.

Confirmar que el arreglo de CR-QA-001 permanece efectivo.

**Pasa si:** una cuenta A no puede leer, modificar, regenerar, publicar, borrar ni gastar créditos sobre recursos de una cuenta B.

## 4. Llaves de workers y endpoints auxiliares

Hallazgo conocido: varios archivos trackeados conservan llaves fallback conocidas para workers. Esto solo es seguro si producción define una `CRECER_WORKER_KEY` aleatoria y no vacía.

Verificar sin revelar el valor:

- que producción define `CRECER_WORKER_KEY`;
- que no coincide con ningún fallback del repositorio;
- que fue rotada antes de hacer público el repo;
- que los workers fallan cerrado si falta configuración;
- que `_cache.php`, `_imgtry.php`, scripts de setup y diagnósticos exigen admin o token real;
- que ningún endpoint de diagnóstico permite mutaciones destructivas residuales.

Archivos relevantes:

- `includes/gen_async.php`;
- `includes/img_responses.php`;
- `includes/publicador.php`;
- `includes/carrusel.php`;
- `includes/reels.php`;
- `includes/sala_async.php`;
- `includes/relevo_demo.php`;
- `_cache.php`.

**Pasa si:** conocer el repositorio no permite invocar workers, gastar API, publicar ni alterar datos.

## 5. Migraciones y esquema de producción

El deploy actual no aplica SQL automáticamente. Confirmar que producción contiene todas las migraciones requeridas, especialmente:

- `migrations/2026-08-01_ayudante.sql`;
- `migrations/2026-08-02_tour_visto.sql`;
- las migraciones de publicación, métricas, carruseles, Sala, evidencia y billing.

No asumir que una ruta HTTP 200 demuestra que toda la tabla existe si el código captura la excepción y degrada silenciosamente.

Verificar:

- tablas y columnas;
- claves foráneas;
- índices usados por consultas frecuentes;
- migraciones idempotentes;
- rollback documentado;
- que un deploy de código no queda adelantado respecto al esquema.

**Pasa si:** todas las capacidades visibles operan con el esquema real y no dependen de fallbacks por tablas ausentes.

## 6. Camino crítico de cliente nuevo

Usar una cuenta nueva no-admin claramente marcada como prueba. No usar el bypass del fundador para certificar el flujo comercial.

Recorrido mínimo:

1. Registro.
2. Verificación de email.
3. Onboarding hablado o escrito.
4. Foto propia.
5. Creación de marca.
6. Primer post.
7. Generación de imagen.
8. Gateway/paywall.
9. Checkout Stripe.
10. Webhook y activación del plan.
11. Entrada al panel pagado.
12. Edición y aprendizaje.
13. Aprobación.
14. Conexión Meta.
15. Publicación IG/FB.
16. Métricas/resultados.
17. Reporte semanal.
18. Cancelación/portal sin pérdida de datos.

Para cada paso registrar:

- resultado;
- duración;
- captura o ID de evidencia;
- llamada externa involucrada;
- costo aproximado;
- recuperación disponible si falla.

No ejecutar checkout live, publicación real o mensajes externos sin autorización expresa.

**Pasa si:** una persona nueva puede llegar desde cero hasta valor real sin intervención técnica ni acceso administrativo.

## 7. Stripe y evidencia financiera

Verificar:

- modo live/test claramente identificado;
- precios autoritativos desde BD;
- consistencia entre landing, gateway, Stripe y narrativa;
- `checkout.session.completed`;
- `invoice.payment_succeeded`;
- `invoice.payment_failed`;
- deduplicación de webhooks;
- monto, moneda, producto, marca, plan y relación con usuario en `pagos`;
- recibos y portal;
- cancelación y reembolso solo por admin;
- separación de revenue de Crecer y Encuéntralo;
- separación de clientes arms-length y relacionados.

Resolver documentalmente la inconsistencia histórica entre precios de $49/$89, fallback de $39 y oferta founder de $29. Debe existir una sola oferta vigente y explicable.

**Pasa si:** un mismo evento repetido no cobra ni registra dos veces, y la evidencia mensual se puede reconciliar con Stripe.

## 8. IA, grounding y calidad

Verificar en muestras ricas, medias y pobres:

- abstención cuando no hay información suficiente;
- ausencia de productos, precios, ubicación, resultados o historia inventados;
- respeto por la voz declarada;
- distinción entre palabras reales del cliente e inferencias;
- aprendizaje de una edición aplicado a contenido posterior;
- límites de reintentos;
- fallback explícito y seguro;
- costo y latencia por etapa;
- log completo en `crecer_ia_log`;
- que los estados mostrados como “agente trabajando” correspondan con ejecución real.

**Pasa si:** Crecer prefiere pedir información o abstenerse antes que producir una pieza persuasiva basada en hechos inventados.

## 9. Concurrencia, idempotencia y escalabilidad

No hace falta reescribir infraestructura antes del XPRIZE. Sí hay que verificar los invariantes que evitan romperse con más clientes.

Auditar:

- locks de onboarding y pipelines;
- crons que puedan solaparse;
- reclamación atómica de jobs;
- reintentos con límite;
- jobs trabados y recuperación;
- webhooks duplicados o fuera de orden;
- publicación simultánea IG/FB;
- dos requests para generar la misma pieza;
- transacciones abiertas durante llamadas externas;
- consultas sin filtro por marca;
- consultas crecientes sin índice;
- límites por cliente, plan, hora, día y global;
- presupuesto de IA por cliente.

Pruebas mínimas seguras:

1. Ejecutar dos veces la misma operación idempotente.
2. Simular dos crons reclamando el mismo job.
3. Reenviar el mismo webhook de prueba.
4. Simular 429/500 de proveedor.
5. Interrumpir un job y confirmar recuperación.
6. Confirmar que ningún trabajo desaparece o queda eternamente `queued`.

**Pasa si:** las operaciones son observables, idempotentes, recuperables, aisladas y con costo limitado.

## 10. Backups y recuperación

Verificar:

- backup reciente de BD;
- backup de uploads;
- procedimiento de restauración;
- restauración probada en entorno separado;
- retención y ubicación del backup;
- rollback de código;
- recuperación ante worker fallido;
- registro de acciones administrativas.

Un backup no se considera verificado hasta demostrar que puede restaurarse.

**Pasa si:** se puede recuperar una marca, sus contenidos y archivos sin improvisar en producción.

## 11. UX móvil, accesibilidad y primer minuto

Revisar en al menos:

- 360×800;
- 390×844;
- 412×915;
- 768×1024;
- 1366×768;
- 1440×900.

Comprobar:

- cero overflow horizontal;
- campos y botones de al menos 44 px;
- foco visible y navegación por teclado;
- labels accesibles;
- contraste legible;
- errores junto al campo y ruta de recuperación;
- loading inmediato para operaciones largas;
- `prefers-reduced-motion`;
- ausencia de acciones esenciales dependientes de hover o swipe;
- tour saltable, no repetitivo y sin bloquear acciones;
- estados vacíos honestos;
- primer valor antes de complejidad avanzada;
- una sola acción primaria clara por pantalla.

Landing: medir antes de rediseñar el paso que solicita el nombre del negocio. Registrar visita → nombre → registro → primer post → checkout → pago.

**Pasa si:** una persona puede completar el recorrido principal con una mano y entender qué sucede sin explicación del fundador.

## 12. Imágenes, testimonios y honestidad pública

La landing usa un feed definido en código como demostración ficticia, pero con imágenes descritas como reales en commits. Verificar para cada recurso:

- origen;
- propietario;
- permiso de uso comercial;
- permiso para video público;
- si representa un cliente real o un ejemplo;
- consentimiento para nombre, testimonio y métricas.

Hasta tener consentimiento verificable, rotular discretamente el contenido como ejemplo y no insinuar que representa clientes, engagement o resultados reales.

Confirmar además:

- ninguna reseña semilla se presenta como real;
- ninguna métrica demo se mezcla con resultados de clientes;
- ninguna imagen generada se presenta como producto entregado sin aprobación;
- no se usa música, marca o material protegido sin permiso en el video.

**Pasa si:** toda afirmación pública se puede respaldar y toda persona/empresa mostrada dio permiso.

## 13. Documentación y narrativa única

Actualizar o marcar como histórico cualquier documento que contradiga el producto actual. Revisar especialmente:

- `CLAUDE.md`;
- `HANDOFF.md`;
- `PLAN.md`;
- `RESUMEN-PROYECTO.md`;
- `LIBRETO_VIDEO.md`;
- `DEPLOY-CRECER.md`;
- `QA_AUDIT_CRECER_2026-07-27.md`;
- `QA_BUGS_CRECER_2026-07-27.csv`.

Confirmar una sola verdad sobre:

- precio vigente;
- qué publica automáticamente;
- qué sigue siendo roadmap;
- qué corre en producción;
- qué modelos se usan;
- qué hace el humano y qué hace la IA;
- número de agentes que se presentará;
- relación Crecer/Encuéntralo;
- clientes, revenue, gastos y resultados reales.

**Pasa si:** código, demo, video y texto de Devpost cuentan exactamente el mismo producto.

## 14. Expediente comercial XPRIZE

Crear una fuente controlada de evidencia con una fila por prospecto/cliente y acceso restringido.

Campos mínimos:

| Campo | Descripción |
|---|---|
| Fecha | Primer contacto |
| Negocio | Nombre comercial |
| Contacto | Identificador privado |
| Origen | Referido, frío, orgánico, etc. |
| Relación | Arms-length, previo o relacionado |
| Estado | Contactado, demo, activado, pagó, publicó, renovó |
| Plan | Oferta comprada |
| Revenue | Monto efectivamente ganado |
| Gasto adquisición | Incluido aunque sea cero |
| Costo servicio | IA, SMS, email y trabajo externo |
| Tiempo a valor | Hasta primer post/publicación |
| Uso | Posts creados, aprobados y publicados |
| Resultado | Alcance, mensajes, órdenes, ahorro u otro dato verificable |
| Testimonio | Texto y consentimiento |
| Evidencia | IDs/capturas/documentos relacionados |

Además consolidar:

- revenue total y por mayo/junio/julio/agosto;
- gastos totales;
- marketing/CAC, incluso si es cero;
- usuarios reales y perfil general;
- revenue relacionado separado;
- logs de agentes;
- uso de Gemini/Google Cloud;
- capturas de producción;
- testimonios autorizados;
- economía unitaria por plan.

**Pasa si:** cada cifra de la entrega puede rastrearse hasta evidencia primaria.

## 15. Acceso de jueces y entrega

Verificar:

- URL estable de producción;
- HTTPS;
- cuenta de juez sin datos privados de clientes;
- acceso gratuito durante evaluación;
- instrucciones de prueba de menos de cinco minutos;
- repo público o acceso privado concedido a los correos oficiales;
- migraciones y setup documentados;
- video público menor de tres minutos;
- narrativa dentro del límite solicitado;
- ausencia de secretos o PII en capturas, logs, CSV y video;
- respuesta operativa posible dentro de dos días hábiles si los jueces piden verificación.

**Pasa si:** un juez puede entender y probar el valor central sin ayuda de Manuel y sin ejecutar acciones peligrosas.

---

# Clasificación de hallazgos

## P0 — Bloquea clientes o elegibilidad

Ejemplos:

- fuga cross-tenant;
- cobro incorrecto o duplicado;
- secreto expuesto;
- pérdida/corrupción de datos;
- flujo principal roto;
- ausencia de Gemini/Google Cloud en producción;
- evidencia falsa o no reconciliable;
- acceso de jueces imposible.

Debe corregirse antes del freeze.

## P1 — Riesgo serio de demo, confianza u operación

Ejemplos:

- resultado inventado aceptado por una compuerta;
- migración faltante;
- worker invocable mediante fallback público;
- cron duplicando acciones;
- publicación sin recuperación;
- documento que contradice la realidad presentada.

Corregir solo con cambio mínimo aprobado.

## P2 — Importante después del XPRIZE

Ejemplos:

- archivos grandes;
- refactor por dominios;
- cola administrada;
- almacenamiento de objetos;
- mejoras de performance no observadas;
- pulido visual sin impacto medido.

Va al backlog. No rompe el freeze.

## P3 — Preferencia

Copy alternativo, animación adicional, nuevo icono, feature sugerida o limpieza sin impacto verificable. Se congela automáticamente.

---

# Formato obligatorio del informe de Claude

Claude debe entregar un Markdown separado con esta estructura:

```markdown
# Informe de verificación final — Crecer

## Veredicto
GO | CONDITIONAL GO | NO-GO

## Resumen
- P0 abiertos:
- P1 abiertos:
- P2 documentados:
- Pruebas ejecutadas:
- Pruebas no ejecutadas y motivo:

## Hallazgos
### ID · Severidad · Título
- Evidencia:
- Reproducción:
- Impacto:
- Corrección mínima:
- Prueba de aceptación:

## Camino crítico
| Paso | Resultado | Evidencia | Observación |

## Seguridad
...

## Producción y datos
...

## XPRIZE
...

## Recomendación de freeze
- Correcciones autorizables antes del freeze:
- Todo lo demás enviado al backlog:
```

El informe debe distinguir claramente entre:

- verificado;
- inferido por código;
- no verificable sin credenciales;
- no ejecutado por riesgo o falta de autorización.

---

# Matriz de cierre

Estado de los hallazgos de la verificación final (ver
`INFORME-VERIFICACION-FINAL-2026-08-02.md`). "Corregido" = el código está en `main`.
"Cerrado" = verificado en **producción**.

| ID | Severidad | Qué | Commit | Estado |
|---|---|---|---|---|
| CR-F01 | P0 | `_cache.php` entregaba la llave de workers y la lista de clientes | `a14f9c2` | Corregido · **pendiente cierre en producción** |
| CR-F01b | P0 | Los 8 workers adoptaban en silencio una llave del repo si faltaba el config | `b694bb8` | Corregido · **pendiente rotar llave y probar los 8** |
| CR-F02 | **P0 CONFIRMADO** | La app anuncia $39 y el Price de Stripe cobra $49 | — | **ABIERTO** — cierre operacional · bloquea a CR-F07 |
| CR-F02b | P0 (clase) | Nada cruzaba el precio anunciado con el cobrado | `bd4c0a3` | Corregido · **pendiente verificar en cada entorno** |
| CR-F03 | P1 | La compuerta aceptaba narrativa inventada sobre negocios vacíos | `3e14fa4` | Corregido (flag OFF: no gobierna producción) |
| CR-F04 | P1 | El smoke no podía fallar | `3e14fa4` | Corregido |
| CR-F05 | P1 | Deduplicación de pagos no atómica | `58fd3c5` | Corregido · **pendiente migración en producción** |
| CR-F06 | P1 | Landing sin rótulo + procedencia de imágenes sin verificar | este commit | Rótulo corregido · **procedencia pendiente de Manuel** |
| CR-F07 | P1 | Documentos contradicen el precio vigente | — | **BLOQUEADO por CR-F02** — no unificar sobre una intención |
| CR-F08 | P1 | Migraciones pendientes en producción | — | **ABIERTO** — cierre operacional |
| CR-F09 | P1 | Sin README ni instrucciones para el jurado | este commit | Corregido |
| CR-F10 | P2 | Crons sin lock de solape | — | Backlog |
| CR-F11 | P2 | ~100 `catch` vacíos | — | Backlog |

## Cierre operacional pendiente (CR-F02) — el precio

**Regla que no se rompe:** test y live conservan **cada uno su propio `stripe_price_id`
en su propia base de datos**. Nunca se cambia la llave de Stripe apuntando a la BD de
producción "para probar los dos": eso cruza clientes, Prices y webhooks entre entornos.

La arquitectura ya lo separa: `includes/db.php` resuelve el config por rutas distintas
(prod fuera de `public_html`; local en `includes/config.local.php`), así que cada entorno
tiene su BD y su fila de `crecer_planes`. **Ojo: no hay staging en el servidor** — el
entorno "test" es el XAMPP local con llaves de Stripe de test y `stripe listen` del CLI.

El guardián de CR-F02b hace cumplir esta regla solo: si el Price es de `livemode` distinto
al de la llave configurada, bloquea el checkout.

**Orden confirmado — el Price se corrige ANTES de desplegar el guardián.** El código
actual ya consume `stripe_price_id`, así que actualizar la fila corrige el cobro de
inmediato y la ventana sin checkout se reduce a cero. El guardián llega después a vigilar
algo que ya está bien, en vez de a bloquear algo que está mal:

1. Crear Price de $39 · 2. Actualizar la BD · 3. Confirmar que el checkout muestra $39 ·
4. Archivar el de $49 · 5. Desplegar el guardián.

### Test (local)
1. Crear Price test de $39 en el producto test existente · 2. Actualizar la fila en la BD
de test · 3. Checkout test → $39 · 4. Archivar el Price test de $49 (**después** del
paso 2) · 5. Desplegar · 6. Salud → `coincide` · 7. Webhook test → fila identificable como
prueba · 8. Reenviarlo y confirmar deduplicación.

### Producción (live)
1. Confirmar que config y BD son las de producción · 2. Crear Price live de $39 en el
producto live existente · 3. Actualizar la fila de producción · 4. Salud → `coincide` con
`livemode=true` · 5. Archivar el Price live anterior, solo entonces · 6. Checkout live
controlado → $39 · 7. Completar un cobro autorizado · 8. Webhook → `pagos.monto=39` ·
9. Reconciliar con Stripe · 10. Reembolsar solo si ése era el plan, y **registrarlo como
prueba, no como revenue**.

**No se mezclan pagos de prueba, reembolsados o del fundador con la evidencia de revenue
externo.**

⚠️ Entre el deploy y la corrección del Price, **el checkout queda cerrado** (el guardián
bloquea, que es lo correcto). Hacer ambos pasos en una sesión corta para no dejar
prospectos fuera más tiempo del necesario.

Hasta que Salud muestre coincidencia y pasen checkout y webhook en cada entorno, **no se
actualiza ningún documento afirmando que el precio está reconciliado**.

## Cierre operacional pendiente (CR-F08)

El orden importa. La migración del índice va **antes** del deploy del webhook nuevo.

1. Preflight de duplicados en `pagos` (solo lectura). **Si devuelve filas, detenerse.**
2. Aplicar `migrations/2026-08-02_pagos_invoice_unico.sql`.
3. Verificar: `SHOW INDEX FROM pagos WHERE Key_name='uq_pagos_stripe_invoice_id';`
4. Aplicar/confirmar `migrations/2026-08-02_tour_visto.sql`.
5. Confirmar las tablas del Ayudante (`crecer_incidencias`).
6. Configurar la nueva `CRECER_WORKER_KEY` **antes o junto** con el deploy.
7. Deploy.
8. Smoke autenticado: `_cache.php` deslogueado → 403; con admin → corre y no muestra llave.
9. Probar los ocho workers con la llave nueva; confirmar que las colas avanzan y que no
   quedaron jobs viejos atascados; revisar logs por 503 posteriores al deploy.
10. Reenvío controlado de un webhook de prueba → **una fila y una sola notificación**.
11. Invalidar definitivamente la llave anterior.

> **La llave anterior NO es un plan de vuelta atrás.** Está quemada: la imprimió el
> diagnóstico y salió de la máquina. Conservarla en un registro seguro para diagnóstico
> está bien; **reactivarla en producción reabre CR-F01**. Producción acepta únicamente la
> nueva desde el momento de la rotación.
>
> Si hay que revertir: se restaura código y configuración que funcionen, y **se genera
> otra llave nueva** si hace falta. Nunca se vuelve a la quemada.
>
> Rotar es una sola edición: `worker_key()` es el único punto que lee `CRECER_WORKER_KEY`,
> y las siete constantes de los disparadores derivan de ella. Los dos lados —quien dispara
> y quien autoriza— cambian juntos; no hay un segundo sitio que pueda quedar desfasado.
> Si un worker falla tras rotar, se diagnostica **con la nueva**.
12. Registrar fecha, entorno y resultado en el informe de verificación.

# Criterio final para activar el freeze

El freeze total se activa cuando:

- no quedan P0;
- los P1 aceptados tienen corrección y regresión verificadas, o riesgo explícitamente aceptado por Manuel;
- el camino crítico funciona con cuenta no-admin;
- producción tiene esquema y configuración correctos;
- Stripe, publicación y evidencia están reconciliados;
- existe backup restaurable;
- comenzó el registro de adquisición y evidencia;
- la narrativa pública coincide con la realidad.

Una vez cumplido, cambiar el encabezado de este documento a:

> Estado: **FREEZE TOTAL ACTIVO**

y registrar debajo:

- fecha y hora;
- commit congelado;
- P1 aceptados, si alguno;
- persona que autorizó el freeze.

Desde ese momento, la prioridad oficial de Crecer es:

> **Clientes reales → activación → pago → publicación → resultado → testimonio → evidencia XPRIZE.**

---

# Bitácora de READY MODE

Una línea por cambio, con su **razón observable**. Sin esta línea la regla es un deseo;
con ella es verificable — y de paso es la evidencia de "qué cambió y por qué" que pide
la entrega.

Formato: `fecha · commit · qué · razón observable · cómo se verificó`

| Fecha | Commit | Qué cambió | Razón observable | Verificado |
|---|---|---|---|---|
| 2026-08-02 | `a14f9c2` | `_cache.php` exige admin y deja de imprimir la llave de workers | P0: el diagnóstico entregaba la `CRECER_WORKER_KEY` de producción a quien conociera `?k=crecer`, que está en el repo | 403 sin sesión en 5 URLs; con admin, la salida no contiene la llave |
| 2026-08-02 | `b694bb8` | Los 8 workers fallan cerrado sin llave configurada | P0: si el config desaparecía, adoptaban en silencio una llave literal del repo | 503 en 8/8 sin llave · 403 en 8/8 con llave mala · pasan 8/8 con la buena |
| 2026-08-02 | `3e14fa4` | Compuerta de suficiencia + smoke bloqueante | P1: la compuerta aceptaba narrativa inventada sobre negocios vacíos, y el smoke no podía fallar | 11 comprobaciones; corrido contra el módulo anterior da 4 fallos y exit 1 |
| 2026-08-02 | `58fd3c5` | Deduplicación atómica de pagos | P1: dos entregas del mismo webhook podían inflar el revenue | 12 comprobaciones, incluida concurrencia con dos procesos reales |
| 2026-08-02 | `f475d63` | Rótulo de ejemplos en la landing + README de jueces | P1: la landing insinuaba clientes reales; no había puerta de entrada para el jurado | Render con el rótulo; README con activo vs roadmap |
| 2026-08-02 | `bd4c0a3` | El checkout se bloquea si Stripe no cuadra con el precio anunciado | P0: la app anuncia $39 y Stripe cobra $49 | 14 comprobaciones; el escenario de hoy bloquea y el corregido permite |

**Pendiente de anotar aquí:** la corrección del Price ($39 en test y en live) y el cierre
operacional de producción, cuando se verifiquen.

