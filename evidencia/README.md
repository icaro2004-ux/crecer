# Evidencia financiera — XPRIZE

La plantilla oficial del concurso ([Google Sheet][pl]) es un P&L de **base de
caja** partido en COGS / SG&A / Tokens, con columnas May · June · July · August ·
Full 90 Days. Este directorio produce exactamente eso, sin copiar números a mano.

[pl]: https://docs.google.com/spreadsheets/d/1pAJrEMo7_QID6V62sA4C8XwGBHkxDTVX3wtYNE2fulI/edit

## Cómo se arma

```
php evidencia/pl.php --total=132.68
```

| Archivo | Qué es | De dónde sale |
|---|---|---|
| `gastos-2026.csv` | **el libro de gastos** — una fila por cargo de factura | a mano, de las facturas de proveedor |
| `crecer_revenue_por_mes.csv` | revenue por mes, frío vs. allegado | lo baja `panel/admin_paquete.php` → *Revenue por mes (CSV)* |
| `PL-xprize.csv` | **la salida**, en el orden exacto de la plantilla | lo escribe `pl.php` |

El script también imprime, ya sumados, los cuatro números que Devpost pide por
nombre: Total Revenue (terceros), Related-Party Revenue, Total Expenses y
Marketing/Customer Acquisition Spend.

## Por qué el libro de gastos es un CSV a mano

El revenue y el costo por llamada de IA sí viven en la base de datos, y el
paquete de evidencia los saca solo. Los gastos de proveedor **no están en ninguna
tabla** — están en las facturas de Google Cloud, Hostinger, Twilio, Shotstack y
OpenAI. Este CSV es el único punto donde hace falta la mano, y a cambio queda
como registro auditable en el repo público: el jurado ve la misma fila que
alimentó la casilla.

## Las reglas que aplica el script

- **Base de caja.** `fecha_pago` es cuándo salió el dinero, no cuándo se usó el
  servicio. Lo dice la plantilla oficial.
- **Ventana 19 may – 17 ago 2026.** Un cargo fuera de esas fechas no entra al
  P&L; el script lo lista aparte en vez de comérselo en silencio.
- **COGS** = costo directo de operar el servicio en producción (tokens que
  producen el trabajo del cliente, hosting que sirve la app, SMS/WhatsApp,
  render de video). **SG&A** = lo que no escala por cliente.
- **`fuente=estimado`** marca lo que no tiene factura (la porción de OpenAI se
  calcula del uso). El script suma cuánto del total es estimado, para poder
  declararlo así en Devpost. La plantilla no distingue; nosotros sí.
- **Marketing y Personnel en cero se declaran igual**, con su fila: Devpost los
  exige *"even if zero"*.

## Cuadre

`--total=` compara la suma del libro contra el total que declara la narrativa.
Si no cuadra, o falta una factura o sobra una fila. Ese número no se fuerza: se
arregla el libro.
