# Crecer — Roadmap

> **El norte (palabras de Manuel, 2026-08-10):** *"XPRIZE es la excusa para montar
> Crecer. Si ganamos, súper — igual ya ganamos democratizando el mercadeo."*
> El concurso es el forcing function y el primer hito; la misión es que cualquier
> microempresario boricua tenga el departamento de marketing (y de atención) que
> nunca pudo pagar. Se ejecuta **todos los días, aunque sea un poco**.
>
> El proyecto se organiza por **capacidades**, no por pantallas (ver ADR-0001).
> Una capacidad es lo que el producto sabe hacer; la implementación puede cambiar
> sin que cambie la capacidad. Decisiones grandes → ADR en `docs/adr/`.

## Estado de capacidades

| Capacidad | Estado | Implementación actual |
|---|---|---|
| **Comprender el negocio** (Business Genome) | ✅ Cerrada (motor inerte, flag OFF; C1 curado vivo) | Genome Engine + Entrevista adaptativa **con voz bidireccional** (habla y te habla, 2026-08-09) |
| **Idea antes que contenido** (Creative Thesis) | 🟡 ADR aceptado; compuerta de abstención VIVA | `creative_thesis.php` — prefiere abstenerse a inventar |
| **Producir contenido** | ✅ Viva | Posts, arte (gpt-image-1/Gemini), carrusel, Reels Studio, **video del dueño con texto de la IA** (2026-08-09) |
| **Publicar sola, a tiempo** | ✅ VIVA (2026-08-09 — el día del reloj) | `correr_publicador` + cron */10; puntualidad medible en `test=publicador` |
| **Medir de verdad** | ✅ Viva (2026-08-09 — fix collation + views IG) | `crecer_metricas` + Resultados por capas (IG vs FB, post por post) |
| **Aprender de los resultados** (Learning Engine) | ✅ **v1 VIVA** (2026-08-09) | **El Optimizador** — lecciones con evidencia → el plan del lunes las usa; `test=optimizador` |
| **Atender al cliente final** (el canal) | ✅ **VIVA en WhatsApp** (2026-08-09) | Conserje de WhatsApp: agente con memoria + compuerta + escalado + Bandeja + Vigía del token. Comentarios IG/FB construidos (pausa: permisos) |
| **Operarse sola** (ops) | ✅ Viva | 7 crons (publicar, métricas, corillo, reportes, prospector, ayudante) + Ayudante que arregla |
| **Conseguir clientes** (Growth Loop) | 🟡 v1 | Prospector (buscar→puntuar→cola de trabajo); Encuéntralo fase 2 = destino |
| **Memoria visual del negocio** (Biblioteca Inteligente) | ⏳ Futuro | Hoy: Biblioteca de activos real-photos-first en el Estudio |

## Horizonte 0 — LA ENTREGA (10–17 ago) · ritmo diario

> Manuel trabaja lu–vi: bloques de 15–30 min por noche. Sábado = día grande.
> Claude adelanta en semana lo que no necesita manos de Manuel.

| Día | Tarea de Manuel (corta) |
|---|---|
| **Lun 11** | Token de WhatsApp: verificar/extender a 60 días (5 min) — el Vigía avisa si muere |
| **Mar 12** | **Stripe LIVE: price $39** (CR-F02) — desbloquea el revenue (15 min) |
| **Mié 13** | Migraciones pendientes en phpMyAdmin + SQL de verificación de esquema (15 min) |
| **Jue 14** | Rotar `CRECER_WORKER_KEY` y `CRON_TOKEN` + compartir repo con `testing@devpost.com` y `judging@hacker.fund` (15 min) |
| **Vie 15** | Cuenta de juez (crear + credenciales al README) + abrir draft en Devpost, pegar campos listos (20 min) |
| **Sáb 16** | **EL VIDEO** (guion listo) + subir a YouTube · swap Twilio/realtor · token permanente · evidencia final (P&L, screenshots) · Devpost completo |
| **Dom 17 AM** | Revisión final y **SUBMIT antes de la 1:00 PM PT** (colchón, no deadline) |

**Claude en semana:** SABADO-CHECKLIST detallado · empaquetador de evidencia
(P&L + logs + usuarios → campos de Devpost) · README del juez · pasada final a la
narrativa v4 · mapa click-a-click del token permanente y del swap Twilio.

## Horizonte 1 — CERTIFICACIÓN (resto de agosto)

El producto ya corre; ahora que nada dependa de suerte:
- **Backups restaurables demostrados** (el hueco #1 del informe de auditoría).
- Camino crítico completo con cuenta nueva real (registro→pago→publicación).
- Deuda QA: locks de cron (CR-F10), `catch` vacíos con log (CR-F11).
- Conserje de comentarios: habilitar permisos en la app y encender.
- Bandeja de WhatsApp v2: fotos/documentos/audio (recibir + enviar; audio→Gemini).
- Oferta y precio finales ($39 vs $49, founder cupón) — una sola verdad.

## Horizonte 2 — EL CANAL COMO PRODUCTO (sep–oct)

*"Tu negocio con su propia línea que contesta sola"* — el add-on que se vende solo:
- **Multi-número**: rutear por `phone_number_id` → cada cliente su línea, su voz, su bandeja.
- **Provisioning en un click** (Twilio API): número nuevo ≈ $3–6/mes, sin SIM ni tienda.
- **Modo solopreneur**: UN número, varios sombreros — el hilo se clasifica por el
  botón de origen (atribución por `wa.me?text=` distinta en página/IG/QR) y queda marcado.
- **Reglas del bot**: lista de "míos" (la IA nunca les contesta) · humano entra →
  IA se calla 24–48h en ese hilo · desconocido → la IA toma la conversación.
- **Coexistencia** (app + API en el mismo número) vía registro como **Tech Provider**
  de Meta — el unlock para "no quiero perder mi teléfono".
- Benchmark: el mercado cobra $50–100/mes + setups de $300–1,500. Crecer: add-on
  $10–15/mes montado en un click.

## Horizonte 3 — LA PLATAFORMA (oct+)

- **Autonomía que se gana**: tras N aprobaciones sin ediciones, el corillo agenda
  con ventana de veto ("sale mañana 10 AM salvo que digas no").
- **La Temporada**: propuestas 2–3 semanas antes de fiestas patronales del
  municipio, madres, quincena, Navidad.
- **Biblioteca Inteligente**: la memoria visual del Genome; el corillo pide fotos
  proactivo y las usa solo en el plan semanal.
- **DMs de IG/FB** (permiso de mensajería) — el Conserje completo.
- **Encuéntralo fase 2**: el directorio, poblado por los clientes que Crecer hizo
  fuertes — el plan original, por fin al derecho.
- **Finanzas del micronegocio** (mismo cerebro, otro dominio — fuera del XPRIZE).

## Principios que no se negocian

- **Principio de Verdad**: nada se afirma que el motor no haga; la IA se abstiene
  antes que inventar; el revenue related-party se reporta aparte.
- **Opciones, no reglas** (doctrina de narrativa): el cliente hace lo que quiera;
  él responde por lo que publica, nosotros por lo que producimos.
- **El dueño manda**: arquitectura invisible, el negocio al frente, Crecer detrás.
- **Prueba viva o no existe**: toda capacidad con su `test=` en `_cache.php`.
- **Nada muere mudo**: vigías y avisos antes que incendios (lección del token).
