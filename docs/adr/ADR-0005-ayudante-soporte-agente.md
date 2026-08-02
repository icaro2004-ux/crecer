# ADR-0005 — El Ayudante: soporte operado por un agente

## Estado
Accepted (2026-08-01)

## Contexto
Crecer no tenía helper. Cuando algo se trababa —una imagen que no sale, una foto
que no sube, un post que no llega a Instagram— el dueño quedaba solo frente a una
pantalla que no explicaba nada. Lo único disponible era `soporte.php`: un chat
donde escribes y **esperas** a que un humano conteste. Para una repostera que
está en medio del día, esperar es lo mismo que abandonar.

Al mismo tiempo, el producto ya tenía todo lo necesario para arreglarse solo: los
workers async son re-disparables (`arte_disparar`, `carrusel_disparar`,
`sala_disparar`, `reels_disparar`, `publicar_disparar`), `publicar_pieza` ya sabe
reclamar piezas trabadas, y cada falla deja rastro en la BD. Lo que faltaba no era
capacidad de reparación, sino **alguien que mirara y decidiera**.

## Problema
- El soporte era **reactivo y humano**: sin fundador despierto, no pasa nada.
- Las fallas eran **silenciosas para el dueño y para el fundador** a la vez: el
  post se quedaba en `queued`, nadie se enteraba, y la primera señal era un
  cliente molesto (o peor: uno que se va sin decir nada).
- El `asistente_responder()` que existía era **advisory**: sabía explicar el app,
  no podía tocar nada. Un FAQ con buena voz sigue siendo un FAQ.
- Criterio #2 del XPRIZE pide que **los agentes corran el negocio**. Un soporte
  que depende de que el fundador lea un chat no es eso.

## Decisiones

1. **Tres capas, en orden estricto: escanear → arreglar → escalar.**
   El diagnóstico (`ayudante_escanear`) es **determinista, cero IA**: lee el
   estado real de la BD y del disco. La IA nunca inventa el problema; solo
   explica y decide sobre hallazgos que ya existen.

2. **Lista blanca de reparaciones.** `ayudante_acciones()` define las únicas
   siete acciones ejecutables (`reintentar_arte`, `reintentar_generacion`,
   `reintentar_carrusel`, `reintentar_sala`, `reintentar_reel`,
   `reintentar_publicacion`, `reparar_uploads`). Toda reparación va acotada a la
   `marca_id` dueña. El LLM **no puede inventar una acción**: escoge por número
   de una lista que el escáner produjo.

3. **Regla de verdad.** El Ayudante nunca dice "resuelto" si solo volvió a
   encolar. Dice qué hizo y qué va a pasar. Si intentó y falló, lo dice y
   escala. (Hereda el Principio de Verdad del producto.)

4. **Lo que no se arregla solo se ESCRIBE.** `crecer_incidencias` guarda el caso
   con diagnóstico, detalle técnico, qué se intentó y qué pasó. Al fundador le
   sale **email con la explicación completa + SMS corto** (Twilio Messages, no
   Verify). Ventana anti-spam de 6 h por (código, ref): el mismo caso no vuelve
   a sonar el teléfono.

5. **El dueño ve que su queja existe.** Cada incidencia deja un mensaje en su
   hilo de Soporte (`Caso #N`) y una notificación in-app. El caso no es un
   agujero negro.

6. **Distinción entre "no puedo" y "no me toca".** Conectar Instagram/Facebook o
   autorizar permisos de Meta **solo lo puede hacer el dueño**: eso se le explica
   con un link, y NO se le levanta un caso al fundador. Se escala lo que es
   nuestro.

7. **Proactivo, no solo reactivo.** `scripts/cron_ayudante.php` barre cada 15 min
   las cuentas con movimiento: arregla y escala sin que nadie abra nada. El
   helper flotante es la puerta; el barrido es el turno de noche.

8. **El soporte nunca se apaga.** El endpoint `panel/ayudante.php` va **sin**
   `panel_guard` a propósito: si la suscripción venció o el pago falló, el dueño
   igual tiene cómo pedir ayuda. Y si Gemini no responde, `ayudante_conversar`
   cae al parte determinista: el helper sigue diagnosticando y arreglando sin IA.

9. **Native Design.** Desktop = panel lateral (el dueño sigue viendo su trabajo al
   lado). Móvil = hoja que sube a 88dvh, botón sobre el bottom-nav, acción
   primaria antes del scroll.

## Consecuencias
- **A favor:** la mayoría de las trabas (que son colas async colgadas) se
  resuelven solas, de día y de noche, sin que nadie escriba un ticket. El
  fundador se entera por SMS del 5% que sí necesita mano humana, con el
  diagnóstico ya hecho.
- **Evidencia XPRIZE #2:** cada escaneo, cada arreglo y cada escalada queda en
  `crecer_ia_log` (agente `ayudante`) + `crecer_incidencias`. Es un agente
  operando el soporte del negocio, con bitácora.
- **Costo:** el diagnóstico y las reparaciones son SQL + curl (≈0). Solo el chat
  gasta tokens, topado a 25 llamadas/hora por negocio; al topar, el helper no se
  cae — se va directo a revisar y arreglar.
- **Riesgo aceptado:** abrir el helper dispara reparaciones automáticas. Es
  intencional (el dueño lo abre porque algo pasa) y las reparaciones son
  idempotentes: re-encolar algo que ya salió bien no rompe nada — el escáner
  ignora lo que ya tiene `grafica_path`.
- **Deuda:** `panel/asistente.php` y `asistente_responder()` quedan como el
  copiloto advisory anterior. El Ayudante los supersede; conviene retirarlos
  cuando se confirme que nadie los usa.

## Archivos
- `includes/ayudante.php` — motor (escanear, arreglar, reportar, conversar).
- `panel/ayudante.php` — endpoint JSON (revisar · chat · arreglar · reportar).
- `panel/_shell_foot.php` — helper flotante (vive en todo el panel).
- `scripts/cron_ayudante.php` — barrido proactivo (cada 15 min).
- `panel/admin_incidencias.php` — los casos, para el fundador.
- `includes/twilio.php` — `sms_texto()` (Messages API, texto libre).
- `migrations/2026-08-01_ayudante.sql` — `crecer_incidencias`.
