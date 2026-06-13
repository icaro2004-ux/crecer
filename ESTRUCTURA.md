# ESTRUCTURA — Encuéntralo (arquitectura + plan de diseño)

> El plano de las pantallas y cómo se navegan. Acompaña a MAPA.md (la visión)
> y se construye en el orden de las capas. Manuel dirige el diseño.

## Principio responsive (NO olvidar el PC)

- 📱 **Móvil** = aprobar al vuelo, revisar rápido (el dueño en la calle).
- 💻 **Desktop** = trabajo pesado: dashboard, **cuentas, agenda, analítica**
  (sobre todo Crecer Full). Esto NO es cómodo en celular.
- Mismo sistema de diseño (`assets/encuentralo-ui.css`); el layout se adapta:
  **sidebar en desktop**, **nav inferior en móvil**.

---

## DOS ZONAS

### 🌐 ZONA PÚBLICA — marketing + directorio (encuentralo.com)
Sirve a dos públicos, sin mezclarlos:

- `/` — **EL HUB**: lidera con dos puertas — 🔎 "Busco un servicio" (→ directorio)
  y 🚀 "Tengo un negocio" (→ Crecer). NO es un splash vacío de dos botones: es un
  landing real (hero con las 2 puertas + tira corta de "qué es" + prueba social).
  Cada puerta lleva a su landing específico.
- `/crecer` — **Landing de Crecer** (la página que VENDE a los dueños):
  pitch *"¿Cuánto quieres crecer?"* → los 3 niveles → el flywheel → precios →
  CTA "Empieza". *(Máquina de revenue — primera prioridad de build.)*
- `/buscar` — el **directorio** para consumidores (ya existe en Encuéntralo).
- `/negocio/{slug}` — perfil público del negocio (ficha + reviews).

### 🔐 ZONA APP — el panel del dueño (lo que paga; responsive)
- **Onboarding / Intake** — el negocio cuenta su voz, productos, fotos.
- **Dashboard** (home del panel) — resumen: qué aprobar, próximas publicaciones,
  órdenes abiertas, ingresos del mes (lo que aplique según nivel).
- **Contenido** — calendario + aprobación (✅ ya existe) + gráficas.
- **Marca** — identidad: logo, colores, portada (Nivel 1 · Básico).
- **Órdenes & Agenda** — recibir/gestionar órdenes, agendamiento (Nivel 2).
- **Clientela (CRM)** — clientes, historial.
- **Cuentas** — ingresos / gastos / ganancia (Nivel 3 · Avanzado).
- **Analítica** — ventas + flywheel de reviews (Nivel 3).
- **Configuración** — nivel/suscripción, datos, perfil de Encuéntralo.

> Las secciones se **desbloquean según el nivel** que el dueño tenga
> (Básico / Intermedio / Avanzado). Lo que no tiene, lo ve como "upgrade".

---

## ESTADO DE CONSTRUCCIÓN (¿qué hay y de qué plan es?)

| Pieza | Plan | ¿Construido? |
|---|---|---|
| Sitio público (hub, landing, precios) | — | ✅ |
| Intake "crear mi negocio" | entrada a todos | ✅ |
| Calendario + captions (IA escribe) | 🌿 Crecer $55 | ✅ |
| Panel de aprobación móvil | 🌿 Crecer $55 | ✅ |
| Generar 1er mes (botón mágico) | 🌿 Crecer $55 | ✅ |
| Logo/identidad con IA | 🌱 Arranca $25 | ⬜ |
| Gráficas con IA (imágenes desde sus fotos) | 🌿 Crecer $55 | ⬜ |
| IA responde WhatsApp/DMs (agente RESPONDER) | 🌿 Crecer $55 | ⬜ |
| Órdenes & Agenda (panel del dueño) | 🌿 Crecer $55 | ✅ |
| Página PÚBLICA de órdenes (link + QR, sin cuenta) | 🌿 Crecer $55 | ✅ |
| Pedir reseña al completar (flywheel) | 🌿 Crecer $55 | ✅ (vía WhatsApp) |
| Análisis + cuentas detalladas | 🚀 Despegar $75 | ⬜ |
| Dashboard del panel (shell PC+móvil) | todos | ✅ |

**Nota WhatsApp:** conectar la IA a WhatsApp real = WhatsApp Business API de Meta
(número de negocio, verificación, posible costo). Su propio montaje. Opciones van
desde "la IA redacta y el dueño pega" hasta integración full con Meta.

## ORDEN DE BUILD (la ruta, para no perdernos)

1. **Landing de Crecer** — la página que vende + que puedes enseñarle a la gente.
2. **Shell del panel + Dashboard** — el esqueleto responsive (sidebar/nav) y la
   pantalla home del dueño.
3. **Intake / Onboarding** — para meter un negocio real.
4. Rellenar módulos por capa: Contenido (✅) → Gráficas → Órdenes/Agenda →
   Clientela → Cuentas/Analítica.

Cada pantalla hereda el sistema de diseño (pin + Poppins + paleta tropical).
