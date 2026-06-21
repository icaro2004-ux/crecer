# Encuéntralo / Crecer — Resumen completo del proyecto
*(Documento para brainstorm. Estado al 14 de junio de 2026. Todo corre LOCAL todavía.)*

---

## 1. Qué es esto en una frase

**Un departamento de marketing completo, operado por IA, para microempresas boricuas (Puerto Rico).** No es una herramienta que el dueño "usa": es un equipo que **trabaja el negocio por él** — planifica, redacta, diseña, agenda y organiza — usando las fotos y la voz reales del negocio, en español puertorriqueño auténtico.

El nombre del producto público es **Encuéntralo**; el motor/dashboard interno se llama **Crecer**.

---

## 2. La visión y el "norte"

- **REAL, AUTÓNOMO, ÚNICO.** Nada genérico, nada de "AI slop". El contenido suena boricua de verdad (bizcocho, no "tarta"; chavos; nene/nena), usa las fotos reales del producto (la IA nunca inventa el producto), y la IA *opera* el negocio, no solo asiste.
- **Negocio primero, concurso después.** Existe un objetivo de entrar al *Build with Gemini XPRIZE* (deadline ago 2026), pero la regla es **no dejar que la fecha del concurso limite la ambición**. Estamos construyendo el negocio real; si entra al concurso, bien.
- **El "moat" (foso defensivo):** la meta es que el producto sea tan completo y útil que **cambiarse a un competidor duela**. Que quien venga después con algo parecido tenga que ser muy creativo para superarnos. Cada herramienta que añadimos (calendario, agenda, sincronización, aprendizaje de vocabulario) es un ladrillo de retención.
- **Dogfooding:** Encuéntralo va a ser **cliente de sí mismo** — usaremos el producto para promocionar el producto, generando evidencia real de uso e ingresos (eso será más adelante).

---

## 3. Público y contexto

- **Quién:** microempresas y dueños solos en Puerto Rico (panaderías, reposterías, food, servicios, etc.). Gente que **no sabe de marketing** y no tiene tiempo ni dinero para una agencia.
- **Dolor que resolvemos:** "sé que tengo que estar en redes pero no sé qué publicar, no tengo tiempo, y lo que publico se ve aficionado."
- **Tono del producto:** cercano, boricua, cálido. Que el dueño sienta que tiene un socio, no un software.

---

## 4. Stack técnico (realidad actual)

- **PHP plano** (sin framework) + **MySQL/MariaDB**, corriendo en **XAMPP** local (`localhost/crecer/`).
- Base de datos **compartida** con el proyecto original Encuéntralo (`encuentralo_db`): reutilizamos `usuarios`, municipios, etc.
- **IA: API de Gemini (tier pagado).**
  - `gemini-2.5-flash` → texto.
  - `gemini-2.5-flash-image` ("Nano Banana") → imágenes ~$0.039 c/u.
  - `gemini-3-pro-image` ("Nano Banana Pro") → imágenes ~$0.134 c/u, mejor con texto/letras.
- Patrón **PRG + AJAX** (fetch + JSON) para que nada recargue la página.
- Autenticación con sesiones PHP, `password_hash`, tokens CSRF.
- Exportación de calendario en formato **.ics** (VCALENDAR) para sincronizar con Outlook/Google.
- Repo privado en GitHub. Todo se desarrolla y prueba **en local**; nada se ha subido a producción aún.

---

## 5. Arquitectura de la app (mapa de piezas)

### Sitio público
- **Hub / landing** — explica el producto, CTAs a registro.
- **Directorio (`buscar`)** — listado de negocios.
- **Intake (wizard)** — el dueño cuenta de su negocio (nombre, descripción, voz/tono, productos, público, ofertas, IG/WhatsApp). Crea la "marca".
- **Página de orden pública (`ordenar`)** — por slug + QR, para que clientes finales hagan pedidos.
- **Registro / login / logout.**

### Motor de IA (`includes/agentes.php`) — cada función registra todo en un log de IA
- `crear_marca()` — guarda el perfil del negocio (idempotente), genera slug único.
- `generar_logo()` — **estudio de logo** con dirección de arte (sliders de tono/época/detalle, estilo, tipografía, color, instrucciones libres). Usa Nano Banana Pro.
- `generar_grafica()` — convierte una **foto real** del producto en arte de post profesional. Mantiene el producto real (regla de propiedad intelectual). Opciones: con/sin texto encima, estilo, incluir logo (marca de agua / esquina / integrado), instrucciones libres.
- `planificar_mes()` — el **planificador**: le pide a Gemini un plan de contenido del mes (variedad de plataformas y tipos, aprovecha cultura local, quincenas, fines de semana) y lo materializa como borradores.
- `redactar_pieza()` / `redactar_calendario()` — el **creador**: escribe los captions boricuas reales a partir de la idea de cada pieza.
- `redactar_sugerido()` — escribe un post **a partir del tema que sugiere el dueño**, o **mejora un borrador** que el dueño escribió (respetando su intención y datos).
- `aprender_de_edicion()` — **aprendizaje de vocabulario**: cuando el dueño edita un caption, la IA extrae la lección de vocabulario/voz (ej. "usa china, no naranja") y la guarda en el **glosario** del negocio. Desde ahí, *todos* los posts futuros respetan ese vocabulario. Esto es clave para el "ÚNICO" y para el moat.

### Panel del cliente (dentro de un shell con sidebar/drawer)
- **Inicio / dashboard.**
- **Contenido (`aprobar2.php`) — "la fábrica de posts"** (la pieza central, ver sección 6).
- **Gráficas (`graficas.php`) — "estudio de arte avanzado"**: subir/gestionar fotos, galería de artes, vista previa de cómo se vería en Instagram/Facebook, marcar como publicado.
- **Mi Marca (`marca.php`) — estudio de logo**: generar logos, elegir el final, descargar en varios formatos.
- **Órdenes (`ordenes.php`)** — gestión de pedidos + "flywheel" (el ciclo de negocio).
- **Calendario (`calendario.php`)** — vista de mes unificada (ver sección 7).
- Cambiador de negocio (un usuario puede tener varias marcas).

---

## 6. La "fábrica de posts" (el corazón del producto)

Antes, "Contenido" y "Gráficas" eran dos pantallas separadas que no se hablaban, y el dueño no entendía el flujo. Lo rediseñamos en una **cadena de montaje** donde un post nace, se le escribe el copy, se le monta la imagen y se aprueba — todo en un solo lugar.

**Cómo funciona hoy:**

1. **Pedir contenido a la IA.** Botón "✏️ Pedir un post a la IA" abre una ventana donde el dueño puede:
   - **Sugerir un tema** ("promo de bizcocho de guayaba para el Día de las Madres") → la IA redacta.
   - **Escribir un borrador feo** → la IA lo pule respetando su intención, precios y fechas.
   - **Dejarlo en blanco** → la IA inventa N posts (1/3/6) basándose en el perfil del negocio.
   - Elegir **varias plataformas a la vez** (Instagram, Facebook, WhatsApp-Estado) → crea **un post adaptado a cada red**.
   - Elegir **fecha** → cae directo en el calendario.
   - También hay "➕ Escribir uno yo (sin IA)" para control total.

2. **Cada post es una tarjeta** con un **checklist de completitud**: ✍️ Copy · 🎨 Arte · ✋ Aprobado.

3. **Estudio de arte inline** (sin salir del post). Un modal donde se puede:
   - **Reciclar** ♻️ un arte que ya se creó (no cuesta nada).
   - **Generar con IA** desde una foto (subir o escoger) o sin foto.
   - **Subir una imagen propia tal cual** 📎 (sin IA, sin límite) — para cuando el dueño tiene su propio diseño o lo generó en otro lado.

4. **Aprobar es inteligente.** Si el dueño aprueba un post **sin imagen**, en vez de aprobarlo a medias se abre el estudio de arte; al crear/elegir la imagen, el post **se aprueba solo**. (Con opción "aprobar solo texto" para los tercos.)

5. **Edición que enseña.** Editar un caption dispara el aprendizaje de vocabulario (glosario).

6. **Sub-tabs para no cargar todo:** "⏳ Por aprobar" (solo borradores) y "✅ Aprobados" (archivo **por mes**, se carga un mes a la vez). Los contadores se actualizan en vivo.

7. **Control de costos (límites como el logo):**
   - **2 generaciones de IA por post.** Al agotarse, el botón se deshabilita y solo quedan: reciclar o subir foto propia.
   - **10 imágenes por semana** (ventana móvil de 7 días), compartido con Gráficas, con fecha de recarga visible.

---

## 7. Calendario unificado

- Vista de **mes** con navegación (mes anterior/siguiente, "Hoy").
- **Unifica** en un solo calendario: posts de contenido (por fecha programada), órdenes/citas (por fecha de entrega) y **eventos propios** (ver abajo).
- **Tabs de filtro**: Todo / 📸 Contenido / 📦 Órdenes / 📌 Eventos.
- **Arrastrar y soltar** para reprogramar cualquier cosa.
- **Click → vista previa** del post/orden/evento.
- Abre por defecto en el **mes del próximo post programado** (para que veas lo que viene).
- **Agenda de trabajo (eventos propios):** el dueño puede agendar cualquier cosa de su negocio (ej. "cita con el contable", "comprar materiales"), con título, fecha, hora y nota. Botón "➕ Evento" o tocando un día vacío.
- **Sincronización con Outlook/Google** vía exportación **.ics**: todo el calendario (contenido + órdenes + eventos) se exporta, y los eventos llevan **recordatorio 30 min antes** (VALARM). Esto le da al dueño herramientas de gestión real de su negocio, no solo de marketing — parte del moat.

---

## 8. Lo que YA funciona (resumen)

- Registro/login, perfil de negocio (intake), multi-negocio por usuario.
- IA paga sin muros de cuota (tier pagado configurado).
- Generación de **logo** con dirección de arte + descarga en formatos.
- **Fábrica de posts** completa: pedir a la IA (tema/borrador/random), multi-plataforma, estudio de arte inline (generar / reciclar / subir propia), checklist, aprobar inteligente, sub-tabs pendientes/aprobados por mes.
- **Aprendizaje de vocabulario** (glosario) verificado funcionando.
- **Calendario** unificado con drag-drop, eventos propios y export .ics con recordatorios.
- **Límites de costo** de imágenes (2/post, 10/semana) con mensajería clara.
- Gestión de **órdenes** y página pública de pedidos con QR.

---

## 9. Lo que NO está hecho todavía (roadmap / pendientes)

- **Publicación real a las redes.** Hoy "publicar" marca el post como publicado y te da el copy + la imagen para subirla a mano. **Falta la integración con la API de Meta (Graph API)** para publicar/programar directo en Instagram y Facebook, y algo equivalente para WhatsApp.
- **Sincronización de 2 vías con Outlook** (Microsoft Graph): hoy es de una vía (exportamos .ics). Falta que lo que el dueño cree en Outlook regrese a la app.
- **Cobros / Stripe** — monetización.
- **Agente de WhatsApp / DMs** — que la IA conteste mensajes y cierre ventas.
- **CRM / Clientela** — base de clientes del negocio.
- **Analítica / "Cuentas"** — métricas, nivel de plan superior ("Despegar").
- **Publicación a producción** — todo está local; aún no hay hosting/deploy.

---

## 10. Modelo de negocio (esbozo, a discutir)

- **SaaS por suscripción** con niveles (ej. básico → "Despegar" con analítica/automatización).
- Posibles add-ons: paquetes de imágenes adicionales (los límites ya están puestos para soportar esto), publicación automática, agente de WhatsApp.
- Dogfooding como prueba social y motor de adquisición.

---

## 11. Decisiones de diseño/producto que ya tomamos (y por qué)

- **Límites de imágenes** (2/post, 10/semana) → controlar costo real de IA sin trancar al cliente (siempre puede reciclar o subir propia).
- **La IA nunca inventa el producto** → usa fotos reales; credibilidad y confianza.
- **El dueño tiene la última palabra** → todo es borrador hasta que aprueba; la IA propone, el humano dispone.
- **Aprendizaje de vocabulario** → cada negocio "entrena" su propia voz; mientras más lo usas, más tuyo se vuelve (retención/moat).
- **Todo en español boricua auténtico** → diferenciación cultural difícil de copiar genéricamente.

---

## 12. Preguntas abiertas para el brainstorm

1. **Prioridad #1 post-MVP:** ¿publicación automática a Meta, o agente de WhatsApp para ventas? ¿Cuál da más "wow" y retención?
2. **Monetización:** ¿precio y niveles? ¿freemium con límites o trial pago?
3. **El moat:** ¿qué otras herramientas de gestión (más allá de marketing) atan al dueño? (inventario, finanzas simples, recordatorios de cobro…)
4. **Onboarding:** ¿cómo logramos el "wow" en los primeros 5 minutos sin que el dueño tenga que escribir mucho?
5. **Confianza con la IA:** ¿cómo evitamos que el dueño sienta que pierde control, manteniendo la autonomía de la IA?
6. **Escala de contenido vs costo:** con límites de imágenes, ¿el valor sigue siendo alto? ¿deberíamos apostar más a texto + reciclaje de imágenes?
7. **Diferenciación regional:** ¿cómo profundizamos el "boricua de verdad" para que sea imposible de imitar por un competidor genérico?
8. **Dogfooding:** ¿cómo medimos y mostramos que Encuéntralo se vende a sí mismo?

---

*Fin del resumen. Todo lo descrito está implementado y probado en local salvo lo de la sección 9.*
