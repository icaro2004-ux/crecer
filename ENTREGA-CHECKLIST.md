# ENTREGA XPRIZE — el guion del día D

> **Deadline duro: domingo 17 ago 2026, 1:00 PM PT** (= 4:00 PM en Puerto Rico).
> El sábado 16 es el día de trabajo; el domingo AM es colchón, no plan.
> Este documento se marca a mano: cada `[ ]` que quede sin marcar el sábado en la
> noche es un riesgo que llega vivo al domingo.

---

## Lo que hay que tener antes del sábado (15 min por noche)

Son las cosas que **necesitan tus manos** y que si se dejan para el sábado, lo
queman entero.

- [ ] **Migraciones en producción** (phpMyAdmin, en este orden):
      `2026-08-12_crecer_meta.sql` → `2026-08-12_crecer_variedad_visual.sql` →
      `2026-08-12_crecer_meta_plan.sql` → `2026-08-12_crecer_jugada_ejecuta.sql`.
      Marca **"Continuar aunque haya errores"** (los 1060 son inofensivos) y al
      final corre `migrations/_verificar_meta.sql` hasta que todo diga OK.
- [ ] **Redeploy** en hPanel + `_cache.php?k=crecer` para limpiar OPcache.
- [ ] **Stripe: precio $39 en LIVE** (si sigue abierto).
- [ ] **Cuenta de juez creada** y sus credenciales puestas en el `README.md`
      (la tabla ya está lista, solo hay que llenar los dos campos).
- [ ] **Repo compartido** con `testing@devpost.com` y `judging@hacker.fund`
      (o hacerlo público). Sin esto no se puede evaluar nada.
- [ ] **Rotar** `CRECER_WORKER_KEY` y `CRON_TOKEN` antes de abrir el repo.
- [ ] **Draft abierto en Devpost** con los campos que ya se pueden pegar.

---

## Sábado 16 — el orden importa

### 1. Certificar que el producto está vivo (45 min)

No se graba nada hasta que esto pase. Grabar sobre algo roto cuesta el doble.

- [ ] Entrar con la **cuenta de juez** desde el **teléfono** y hacer el recorrido
      completo: registro → onboarding por voz → primer post → aprobar.
- [ ] **Tu Meta**: crear una meta, ver el plan, tocar *"Que lo haga el corillo"*
      en una jugada y esperar el reporte. Aprobar y publicar una pieza.
- [ ] Volver a **Tu Meta** y confirmar que el punto de esa jugada se puso en verde
      **sin haber marcado nada**. (Si esto falla, el ciclo no está vivo y hay que
      quitar ese párrafo de la narrativa — ver el checklist de honestidad ahí.)
- [ ] Pruebas vivas en verde: `_cache.php?k=crecer&test=meta`, `&test=variedad`,
      `&test=publicador`, `&test=optimizador`, `&test=ayudante`.
- [ ] Confirmar que los **7 crons** están sonando (hPanel).

### 2. El video (3–4 horas, es lo más largo)

Guion completo en `LIBRETO_VIDEO.md`. Antes de grabar:

- [ ] Cuenta de demo con datos bonitos y **reales** (nada de lorem ipsum).
- [ ] Cerrar notificaciones, poner el teléfono en No molestar, limpiar el escritorio.
- [ ] Grabar la pantalla del **corillo EN VIVO** (`evidencia.php`) — es el plano que
      prueba el criterio #2: agentes ejecutando en producción, con tokens y costo
      subiendo en tiempo real.
- [ ] Grabar el ciclo de **Tu Meta** — es lo que ningún competidor va a tener:
      una IA que persigue un número, no que genera contenido.
- [ ] Máximo **3:00**. Subir a YouTube **sin listar** y probar el link en incógnito.

### 3. La evidencia (30 min, casi todo automático)

- [ ] Abrir **Operaciones → Armar el paquete de evidencia**
      (`/crecer/panel/admin_paquete.php`).
- [ ] Bajar el **JSON** y los tres **CSV** (revenue, uso de API, log de IA).
- [ ] Copiar el bloque *"Para pegar en Devpost"* — ya viene con el revenue de
      clientes fríos separado del related-party.
- [ ] Capturas: panel de Stripe (cobro real), un post publicado en Instagram con
      su permalink, y la pantalla de evidencia.
- [ ] **Verificar que ninguna reseña de la semilla** (`[omega-seed-2026]`,
      correos `*.mail.test`) se cuele como testimonio real.

### 4. Devpost (45 min)

- [ ] Narrativa: pegar `NARRATIVA-DEVPOST.md` (983 palabras) y **verificar el
      contador de Devpost**. Antes de pegar, correr su checklist de honestidad:
      si el ciclo de la meta no quedó vivo en producción, ese párrafo se borra.
- [ ] Link del video, link del repo, links de la app.
- [ ] Campos financieros con las cifras del paquete — related-party declarado
      aparte, sin excepción.
- [ ] Adjuntar el JSON y los CSV de evidencia.
- [ ] **Guardar el draft.** No enviar todavía.

---

## Domingo 17 AM — revisión y envío

- [ ] Leer la narrativa completa una vez, en voz alta. Si algo suena a promesa y
      no a hecho, se corrige.
- [ ] Abrir el link del repo **desde una ventana de incógnito** para confirmar
      que un juez puede entrar de verdad.
- [ ] Abrir el video desde incógnito.
- [ ] Entrar a la app con la cuenta de juez desde incógnito.
- [ ] **SUBMIT antes de las 12:00 PM PT** (una hora de colchón sobre el deadline).

---

## Las reglas que no se rompen ni a las 11 de la noche

1. **Nada que no esté vivo en producción se afirma.** Si una capacidad no corre el
   día de entregar, se borra de la narrativa y del video. Sin excepciones.
2. **El revenue del fundador va declarado como related-party**, siempre, aunque
   haga ver el número más chico.
3. **Las reseñas de la semilla son ficticias.** No entran como usuarios ni como
   testimonios en ningún campo.
4. **Si algo falla el sábado, se recorta el alcance — no se falsea.** Un producto
   más pequeño y verdadero puntúa mejor que uno grande e inventado, y es lo único
   que se puede defender si preguntan.
