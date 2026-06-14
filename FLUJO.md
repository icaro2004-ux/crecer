# FLUJO — Las dos experiencias de Encuéntralo

> Cómo vive la gente el producto. Hay DOS actores, dos recorridos, que se cruzan
> en el flywheel. Mantenerlos separados al construir = no enredarnos.
> (✅ = construido · ⬜ = falta)

## El concepto (para nosotros y para el jurado XPRIZE)

Encuéntralo tiene **dos lados**:

- 🧑‍🍳 **EL PROVEEDOR** — el dueño de negocio. **Paga Crecer.** La IA le corre el
  marketing (planifica, escribe, crea gráficas, contesta, maneja órdenes).
- 🛒 **EL CLIENTE FINAL** — el consumidor. Descubre al proveedor y le **ordena**.

**Crecer es el MOTOR** (hace al proveedor digno de ser encontrado).
**Encuéntralo/directorio es el DESTINO** (donde el cliente lo encuentra).
El **flywheel** los conecta: mejor marketing → más clientes → más reseñas →
más reputación → más clientes. La IA opera el negocio (criterio #2 del concurso);
el revenue real = proveedores pagando Crecer.

---

## 🧑‍🍳 EXPERIENCIA A — EL PROVEEDOR (el que paga)

| # | Paso | Pantalla | Estado |
|---|------|----------|--------|
| A1 | Descubre Encuéntralo, escoge "Tengo un negocio" | `index.php` (hub) | ✅ |
| A2 | Ve la oferta y los niveles ("¿cuánto crecer?") | `crecer.php` | ✅ |
| A3 | Crea su cuenta | `registro.php` | ✅ |
| A4 | Le cuenta a la IA de su negocio (voz, productos) | `intake.php` | ✅ |
| A5 | (sube sus fotos — o la IA las genera) | *(módulo Marca)* | ⬜ |
| A6 | La IA le arma el mes (plan + captions + gráficas) | `panel/generar.php` | ✅ texto / ⬜ gráficas |
| A7 | Aprueba/rechaza desde el celular | `panel/aprobar2.php` | ✅ |
| A8 | (la IA publica en sus redes) | *(publicar)* | ⬜ |
| A9 | Recibe y maneja órdenes; comparte su link/QR | `panel/ordenes.php` | ✅ |
| A10 | Completa órdenes → pide reseña | `panel/ordenes.php` | ✅ |
| A11 | (la IA contesta sus DMs de WhatsApp) | *(agente responder)* | ⬜ |
| A12 | Ve su dashboard (qué aprobar, ingresos) | `panel/index.php` | ✅ |
| A13 | Paga su plan / sube de nivel | *(Stripe)* | ⬜ |

## 🛒 EXPERIENCIA B — EL CLIENTE FINAL (consumidor)

| # | Paso | Pantalla | Estado |
|---|------|----------|--------|
| B1 | Descubre un negocio: directorio… | `buscar.php` | ✅ |
| B1b | …o por link/QR que el negocio comparte (IG/FB/WhatsApp) | `ordenar.php?n=slug` | ✅ |
| B1c | …o por los posts que la IA publicó | *(redes del negocio)* | ⬜ |
| B2 | Ve el negocio y ordena (sin cuenta) | `ordenar.php` | ✅ |
| B3 | Recibe confirmación; explora más negocios | `ordenar.php?ok=1` | ✅ |
| B4 | Recibe su producto/servicio (mundo real) | — | — |
| B5 | Deja una reseña (cuando se la piden) | *(página de reseña)* | ⬜ |

---

## 🔁 DÓNDE SE CRUZAN — el flywheel

```
   [PROVEEDOR]  la IA crea contenido/presencia
        │                                   ▲
        ▼                                   │ más reputación → el directorio
   atrae al  [CLIENTE FINAL]                │ lo muestra a más gente
        │                                   │
        ▼                                   │
   el cliente ORDENA  ───►  cae en el panel del proveedor
        │                                   ▲
        ▼                                   │
   proveedor completa  ───►  pide RESEÑA  ──┘
```

El punto de cruce es **Encuéntralo (el directorio)**: el cliente final descubre,
y la reseña sube la reputación del proveedor ahí mismo.

---

## 🧭 REGLA PARA NO ENREDARNOS

Antes de construir CUALQUIER pantalla nueva, decidir primero:

1. **¿De quién es?** ¿Del proveedor (privada, en `/panel/`, requiere login) o del
   cliente final (pública, sin cuenta)?
2. **¿Dónde vive?** Proveedor → `panel/*`. Cliente → raíz pública (`buscar`, `ordenar`).
3. **¿Cómo se conecta al flywheel?** Toda pieza debe empujar la rueda.

> Pantallas del PROVEEDOR = login + `/panel/`. Pantallas del CLIENTE = públicas.
> Si una pantalla mezcla los dos públicos, casi siempre hay que separarla.
