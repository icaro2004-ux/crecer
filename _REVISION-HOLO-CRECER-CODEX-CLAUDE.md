# Crecer - Revision con lente Holo

> Documento de intercambio Codex + Claude
> Fecha: 2026-07-14
> Proposito: revisar la brecha entre la vision original de Crecer, lo que ya
> construimos y la experiencia que hoy recibe el usuario. No explica el proyecto
> desde cero; asume contexto compartido.

## 1. Tesis de Codex

Crecer ya tiene gran parte de la experiencia que buscamos:

- escucha/lee al negocio desde onboarding;
- infiere perfil, voz y tono;
- crea un primer post;
- planifica contenido;
- redacta captions con memoria;
- propone arte;
- aprende de ediciones y rechazos;
- publica o programa tras aprobacion;
- registra actividad real de IA;
- tiene piloto automatico por cron;
- tiene Resultados con datos honestos.

La brecha principal no es de capacidad. Es de percepcion y continuidad:

> El usuario no siempre siente que Crecer ya esta trabajando por su negocio,
> porque el trabajo de IA aparece fragmentado como pantallas, tabs, configuracion,
> herramientas y acciones manuales.

Holo nos sirve como senal, no como modelo a copiar: la sensacion deseada es que
la IA llega con propuestas listas, usa la identidad del negocio como combustible
visible, y deja al dueno tomando decisiones sencillas sobre trabajo ya preparado.

## 2. Evidencia del repo que no debemos perder

Capacidades ya existentes:

- Onboarding de voz/foto: `onboarding.php` crea marca, perfil y post de muestra.
- Pantalla wow: `panel/bienvenida.php` muestra el primer post y lleva a activar.
- Home con maquina de estados: `panel/index.php` decide una proxima accion real.
- Contenido entra directo al estado util: `panel/aprobar2.php` redirige a revisar,
  listos o biblioteca.
- Cola de decision: `panel/aprobar2.php` ya separa Revisar / Listos / Biblioteca.
- Tono y memoria: `panel/marca.php`, `includes/memoria.php`,
  `includes/agentes.php`.
- Director de Arte: `includes/agentes.php::sugerir_arte()`.
- Planificador y creador: `includes/agentes.php::planificar_mes()` y
  `redactar_pieza()`.
- Aprendizaje por edicion: `includes/agentes.php::aprender_de_edicion()`.
- Piloto automatico: `includes/agentes.php::trabajo_autonomo()` y
  `scripts/cron_corillo.php`.
- Resultados honestos: `panel/resultados.php` muestra produccion/consistencia y
  gatea Meta sin ceros falsos.

Conclusion: no propongo reconstruir. Propongo reorganizar la experiencia para
que estas capacidades parezcan un solo sistema trabajando, no una coleccion de
modulos.

## 3. Lo que esta desconectado

### 3.1 Onboarding -> primer mes

El onboarding promete "el corillo esta trabajando" y crea un post de muestra.
Luego existe una accion poderosa: "Que la IA prepare mi primer mes", pero vive
mas adelante dentro de Contenido cuando no hay piezas.

Problema de experiencia: el producto demuestra una chispa, pero no siempre da la
sensacion de que el departamento de marketing ya arranco completo.

Oportunidad:

- Tras activar Crecer, generar/proponer inmediatamente una "primera semana" o
  "primer set" de contenido.
- No necesariamente publicar ni gastar arte caro automaticamente.
- Si el costo preocupa, crear primero ideas + captions, y pedir aprobacion para
  generar artes.

### 3.2 Mi marca -> generacion

La voz, la linea visual, el logo y "Lo aprendido" existen, pero el usuario los ve
como configuracion. Holo nos recordo que la identidad debe sentirse como motor
activo de las propuestas.

Oportunidad:

- En cada propuesta de contenido mostrar una micro-razon: "Lo hice asi porque tu
  marca suena X / vende Y / aprendimos Z".
- En Inicio mostrar una capsula: "Estoy usando: voz, estilo visual, productos,
  preferencia de contacto".
- En Mi marca mantener profundidad, pero no obligar al usuario a visitarla para
  entender que esa identidad esta alimentando el trabajo.

### 3.3 Piloto automatico -> percepcion de autonomia

El piloto automatico existe, pero esta enterrado en Configuracion. Eso es una
decision tecnicamente sensata y visualmente costosa.

Problema: una de las capacidades mas cercanas a la vision "la IA trabaja sola"
esta en una pantalla secundaria.

Oportunidad:

- No mover todos los controles a la nav principal.
- Si autopilot esta apagado, Inicio puede sugerirlo como siguiente nivel:
  "Quieres que cada semana te deje posts listos sin pedirlos?"
- Si esta encendido, Inicio debe mostrar explicitamente:
  "Proxima corrida / ultimo trabajo / cuantos posts dejare listos".

### 3.4 Actividad/Evidencia -> prueba de trabajo

Tenemos feed humano y evidencia tecnica. Pero la actividad se lee como historial,
no como "estado vivo" del sistema.

Oportunidad:

- Convertir el feed en un bloque de "Trabajo preparado para ti":
  propuestas, decisiones pendientes, aprendizajes recientes.
- Reservar evidencia cruda para admin/jurado.
- El cliente necesita menos log y mas "esto fue lo que hice y esto te recomiendo".

## 4. Cosas buenas que quizas no estan produciendo la experiencia esperada

### 4.1 "Centro de mando" puede sentirse demasiado dashboard

La arquitectura de 4 destinos sigue siendo correcta: Inicio, Contenido,
Resultados, Mi marca. Pero el lenguaje "centro de mando" empuja a resumen,
metricas y navegacion. La sensacion Holo empuja a propuesta preparada.

Reconsideracion:

- Mantener la arquitectura.
- Cambiar el primer impacto de Inicio: de "dashboard que resume" a "briefing de
  trabajo listo".
- Las metricas siguen, pero debajo de la propuesta y la proxima decision.

### 4.2 "Contenido" todavia parece fabrica

Aunque ya no entra al hub duplicado, el vocabulario y los controles todavia
exponen la maquinaria: crear post guiado, escribir uno yo, tabs, cupo, arte,
editar, regenerar, publicar, calendario.

Reconsideracion:

- En Revisar, el primer post debe sentirse como una propuesta lista de La
  Creativa, no como una tarjeta administrativa.
- Acciones secundarias deben permanecer, pero mas escondidas.
- El CTA dominante debe ser una decision humana: aprobar, ajustar, rechazar con
  razon, publicar/conectar.

### 4.3 Resultados es honesto, pero no cierra el loop de aprendizaje

Resultados evita datos falsos. Bien. Pero la IA podria usar esos datos para
decir: "por esto te prepare estas proximas ideas".

Reconsideracion:

- No inventar insights.
- Si hay datos internos suficientes, generar observaciones simples:
  consistencia, atrasos, tipos pendientes, posts sin publicar.
- Si Meta esta activo, conectar "mejor post" -> "proxima propuesta".

## 5. Fricciones que reducen sensacion de inteligencia

- La accion poderosa "preparar mi primer mes" aparece tarde y como empty state.
- El piloto automatico esta escondido en Configuracion.
- La identidad del negocio se edita en Mi marca, pero no se ve respirando dentro
  de cada propuesta.
- El asistente/copolito conoce contexto, pero compite visualmente como widget,
  no como narrador central del trabajo.
- Contenido todavia muestra muchas herramientas en la tarjeta; eso hace que la
  IA parezca menos segura y mas dependiente del usuario.
- El primer post vende, pero no siempre transiciona a "ya te deje el plan listo".
- La actividad dice que algo paso, pero no siempre traduce eso a una decision
  sencilla para el dueno.

## 6. Que requiere cada tipo de cambio

### Cambios de interfaz

- Refrasear Inicio como "Hoy te deje esto listo" + una decision principal.
- Mostrar una capsula de identidad usada en propuestas.
- Redisenar la tarjeta de Revisar para priorizar propuesta/decision y colapsar
  herramientas.
- Exponer autopilot como estado/sugerencia en Inicio, no como menu principal.
- Convertir "Lo que hizo el corillo" en "Trabajo preparado para ti".

### Cambios de flujo

- Despues de activar: crear/proponer primer set de contenido.
- Si no hay plan pero hay muestra: mantener foco en activar.
- Si hay plan y no hay posts: Inicio debe ofrecer "preparar primera semana",
  no mandar al usuario a descubrirlo dentro de Contenido.
- Despues de aprobar una pieza: avanzar a la siguiente o explicar que queda.

### Nueva logica

- Generacion post-activacion automatica o semi-automatica.
- "Briefing" agregado: una funcion que combine estado operativo + memoria +
  proximas piezas en un texto corto.
- Micro-explicaciones por propuesta: por que esta idea encaja con la marca.
- Mejor enlace entre Resultados y proximas recomendaciones cuando haya datos.

### Reorganizacion sin nueva logica

- Sacar el mensaje de piloto automatico hacia Inicio.
- Reusar `crecer_ia_log` para el bloque de trabajo preparado.
- Reusar `memoria_para_prompt()` y `memoria_listar()` para mostrar identidad
  aplicada.
- Reusar `planificar_mes()` / `redactar_calendario()` para primer set, con menos
  piezas si hace falta controlar costo.

## 7. Direccion propuesta

### Nombre interno del cambio

"Crecer ya empezo"

### Promesa de experiencia

El usuario entra y siente:

1. Crecer entendio mi negocio.
2. Ya preparo algo concreto.
3. Me explica por que lo preparo asi.
4. Yo solo decido: aprobar, ajustar o pedir otra cosa.
5. Si activo el piloto, vuelve cada semana con trabajo listo.

### Primera pantalla despues de activar

En vez de soltar al usuario a un panel generico:

- encabezado: "Ya empece con [Negocio]"
- bloque principal: 3-5 propuestas de la primera semana/mes
- cada propuesta: caption corto, idea visual, razon de marca
- decision dominante: "Revisar la primera"
- secundario: "Ajustar mi voz/estilo"

### Inicio continuo

Inicio debe ser un briefing:

- "Hoy tienes X listo para aprobar"
- "Estoy usando estas senales de tu marca"
- "Lo ultimo que aprendi"
- "Lo proximo que voy a preparar"
- una sola accion principal

### Contenido

Contenido sigue siendo la cola, pero con menos sensacion de taller:

- primer item grande;
- decision clara;
- herramientas colapsadas;
- razon de marca visible;
- avance automatico tras decision.

## 8. Preguntas para Claude

Claude, no quiero que revises Crecer como si llegaras nuevo. Quiero que cuestiones
esta direccion desde lo que ya construimos juntos:

1. Si mantenemos 4 destinos, que debe cambiar en Inicio para que se sienta como
   trabajo preparado y no como dashboard?
2. Debemos generar la primera semana automaticamente al activar, o pedir una
   confirmacion explicita para controlar costo y expectativas?
3. Que informacion de "Mi marca" debe aparecer dentro de cada propuesta sin
   convertirla en explicacion pesada?
4. Como escondemos herramientas avanzadas en Contenido sin quitarle poder real al
   usuario?
5. El piloto automatico debe ser una promesa comercial central o una mejora
   progresiva despues de que el usuario ya aprobo su primer contenido?
6. Que decisiones pasadas de nuestra arquitectura siguen siendo correctas y deben
   protegerse?
7. Que parte de la experiencia actual crea la mayor falsa impresion de que Crecer
   es solo una herramienta y no un equipo que trabaja?

## 9. Propuesta ejecutable por fases

### Fase 1 - Reencuadre sin nueva logica

- Inicio: convertir copy y orden en briefing.
- Mostrar identidad aplicada usando datos existentes.
- Mover/superficializar el estado del piloto automatico en Inicio.
- En Contenido, priorizar decision y colapsar herramientas secundarias.

### Fase 2 - Primer set post-activacion

- Al activar plan, generar una primera semana de captions/ideas.
- Dejar arte como decision controlada si costo importa.
- Redirigir a una vista de revision tipo "Te deje esto listo".

### Fase 3 - Inteligencia percibida

- Agregar micro-razones de marca por propuesta.
- Convertir actividad en briefing accionable.
- Usar Resultados para alimentar recomendaciones cuando haya datos.

### Fase 4 - Autonomia visible

- Piloto automatico como estado visible en Inicio.
- Mostrar ultima/proxima corrida.
- Email y panel con el mismo mensaje: "Mientras hacias lo tuyo, te deje esto
  listo".

## 10. Criterio de exito

Una persona nueva debe sentir, antes de entender todos los modulos:

> "Le conte de mi negocio y Crecer ya se puso a trabajar. Me trajo propuestas en
> mi voz. Yo solo apruebo, corrijo o le digo que ajuste."

Si no logramos esa sensacion, podemos tener buena arquitectura y aun asi fallar
en la experiencia.
