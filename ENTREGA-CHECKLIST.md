# ENTREGA XPRIZE — el guion del día D

> **Deadline duro: LUNES 17 ago 2026, 1:00 PM PT** (= 4:00 PM en Puerto Rico).
> Ojo con el día de la semana — el 17 cae en **lunes**, no en domingo:
>
> | | |
> |---|---|
> | **Sábado 15** | el día de trabajo (video + evidencia + Devpost en draft) |
> | **Domingo 16** | revisión completa, con la cabeza fresca |
> | **Lunes 17** | envío antes de la 1:00 PM PT — con margen, no al filo |
>
> Este documento se marca a mano: cada `[ ]` que quede sin marcar el sábado por
> la noche es un riesgo que llega vivo al domingo.

---

## Lo que hay que tener antes del sábado (15 min por noche)

Son las cosas que **necesitan tus manos** y que si se dejan para el sábado, lo
queman entero.

- [ ] **Migraciones en producción** — un solo archivo, re-ejecutable:
      `migrations/_TODO-META-2026-08-12.sql` (junta las **6** del 12 de agosto en
      orden y comprueba cada paso antes de darlo, así que no se para a la mitad).
      Después corre `migrations/_verificar_meta.sql`: tiene que dar **16 OK**.
      *(El roadmap las da por hechas el 12 de agosto — esto es solo confirmar.)*
- [ ] **Redeploy** en hPanel + `_cache.php?k=crecer` para limpiar OPcache.
- [ ] **Stripe: precio $39 en LIVE** (si sigue abierto).
- [ ] **Cuenta de juez creada** y sus credenciales puestas en el `README.md`
      (la tabla ya está lista, solo hay que llenar los dos campos).
- [ ] **Repo compartido** con `testing@devpost.com` y `judging@hacker.fund`
      (o hacerlo público). Sin esto no se puede evaluar nada.
- [ ] **Rotar** `CRECER_WORKER_KEY` y `CRON_TOKEN` antes de abrir el repo.
- [ ] **Draft abierto en Devpost** con los campos que ya se pueden pegar.

---

## Sábado 15 — el orden importa

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
- [ ] **Leer los avisos amarillos de arriba antes de copiar nada.** Están sobre
      el bloque de Devpost a propósito. El que más importa: si tu suscripción
      (cuenta admin) no está marcada `es_early_adopter=1`, **tu propio pago se
      está reportando como cliente frío** — que es justo lo que la narrativa
      promete que no pasa. El aviso trae el UPDATE exacto para phpMyAdmin.
- [ ] Confirmar el corte con `_cache.php?k=crecer&test=paquete`: enseña fila por
      fila qué suscripción entra al MRR y cuál es de cortesía. La cuenta del
      **jurado** tiene que salir como *CORTESÍA — fuera del MRR*, nunca como
      cliente frío.
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

## Sábado 15, al cerrar — el tag de la entrega

- [ ] Sobre el commit exacto que se entrega (ni antes ni después):
      `git tag -a v1.0-xprize -m "Entrega Build with Gemini XPRIZE — ago 2026"`
      y `git push origin v1.0-xprize`.
- [ ] Mencionarlo en el `README.md`.
      **Para qué:** con el tag, `main` puede seguir avanzando después de entregar
      sin que el jurado vea algo distinto de lo que se le entregó.

---

## Domingo 16 — revisión con la cabeza fresca

- [ ] Leer la narrativa completa una vez, en voz alta. Si algo suena a promesa y
      no a hecho, se corrige.
- [ ] Abrir el link del repo **desde una ventana de incógnito** para confirmar
      que un juez puede entrar de verdad.
- [ ] Abrir el video desde incógnito.
- [ ] Entrar a la app con la cuenta de juez desde incógnito y hacer el recorrido
      completo: Home → poner meta → ver el plan → "Que lo haga el corillo" →
      aprobar un post.

---

## Lunes 17 — envío

- [ ] Última mirada al draft de Devpost (que no falte ningún adjunto).
- [ ] **SUBMIT antes de las 12:00 PM PT** — una hora de colchón sobre el
      deadline de la 1:00 PM PT (4:00 PM en Puerto Rico).

---

## Durante la ventana de evaluación (después de enviar)

Se puede seguir mejorando el producto, con tres reglas:

1. **El repo y la narrativa se congelan solos** gracias al tag. Producción **no**:
   si un juez entra y justo se está desplegando algo a medias, ve eso.
2. Cada despliegue de esos días pasa antes por el recorrido básico.
3. **Lo que sale en el video no se toca** hasta que pase la evaluación.

Deuda de desktop aceptada a propósito para después: Configuración, Mi marca y
Actividad se ven estrechas en pantalla grande. No están en el recorrido del
jurado.

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
