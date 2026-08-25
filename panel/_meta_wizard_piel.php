<?php
// ============================================================
//  CRECER — LA PIEL DE LOS WIZARDS DE TU META
//  panel/_meta_wizard_piel.php
//
//  Un solo sistema visual para los wizards de Tu Meta: crear la meta,
//  empezar un plan nuevo y cambiar de meta. Estaba dentro de
//  _meta_wizard.php; al aparecer el segundo y el tercero habia que elegir
//  entre copiarlo tres veces o sacarlo aqui. Copiado, el dia que cambie el
//  radio o el rosa del boton cambia en uno y se queda viejo en los otros.
//
//  Se incluye UNA sola vez por pagina — quien lo pide es el wizard que se
//  pinta, y solo se pinta uno.
//
//  Aqui no se decide nada del producto: son tokens, tipos y espacios.
// ============================================================
?>
<style>
  /* ══ CAPA 0 · EL WIZARD ═══════════════════════════════════════════════
     Va al final de la hoja a proposito: en CSS gana el ultimo y asi nadie
     tiene que adivinar el orden. Solo toca .wz — las otras dos capas no
     usan ninguna de estas clases. */
  /*  `hidden` TIENE QUE GANAR SIEMPRE.
      El atributo hidden se aplica con una regla del navegador
      (`[hidden]{display:none}`), y CUALQUIER regla nuestra que ponga display
      la pisa — las del autor van por delante de las del navegador. Asi que un
      `.su-pensando{display:flex}` dejaba el «Buscándote una alternativa»
      puesto encima de la alternativa ya pintada, aunque el guion lo hubiera
      escondido. Salio en una captura, no leyendo el codigo.

      Se arregla aqui, para todos los wizards, y no parcheando cada clase que
      un dia lleve display. */
  .wz [hidden]{display:none !important}

  .wz{
    --tm-rosa:#EF4375; --tm-rosa-tx:#C81E52; --tm-rosa-bt:#D42A5C; --tm-rosa-bt-h:#B81F4C;
    --tm-rosa-piel:#FDF0F4;
    --tm-teal:#00A49F; --tm-teal-tx:#00726F; --tm-teal-piel:#EDF7F6;
    --tm-aviso:#8A5310; --tm-aviso-piel:#FBF3E7;
    --tm-r:12px; --tm-r-bt:10px;
    max-width:560px;margin:0 auto;padding-bottom:var(--ah-zona,20px);
  }

  /* — LA SALIDA · primero y siempre visible —
     Una capa sin puerta deja al dueño encerrado con el boton del navegador, y
     el boton del navegador no le dice si lo que escribio se guarda o no. Este
     lo dice. */
  .wz-salir{display:inline-flex;align-items:center;gap:8px;min-height:44px;
    font-size:16px;font-weight:600;color:var(--tinta);text-decoration:none;margin-bottom:2px}
  .wz-salir .ic{width:17px;height:17px;stroke-width:2;transform:rotate(180deg)}
  .wz-salir:hover{color:var(--tm-rosa-tx)}
  .wz-salir:focus-visible{outline:2px solid var(--tinta);outline-offset:2px;border-radius:8px}

  /* — EL TREN · cuatro tramos, y ninguno es teal —
     Sin numeros escritos: el «Paso 2 de 4» de al lado ya lo dice, y meter
     cifras de 11px aqui era romper el suelo de 14px por decoracion. */
  .wz-tren{display:flex;gap:6px;list-style:none;margin:14px 0 0;padding:0}
  .wz-tren li{flex:1;min-width:0}
  .wz-tren li i{display:block;height:4px;border-radius:99px;background:var(--line)}
  .wz-tren li.ya i{background:var(--tinta)}
  .wz-tren li.on i{background:var(--tinta)}
  .wz-tren li.on span{color:var(--tinta);font-weight:600}
  .wz-tren li span{display:none;font-size:14px;line-height:1.4;color:var(--muted);margin-top:7px}

  .wz-et{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted);margin:18px 0 8px}
  .wz-q{font-family:var(--font-display,'Poppins',sans-serif);font-weight:700;
    font-size:26px;line-height:1.22;letter-spacing:-.022em;color:var(--tinta);
    margin:0;text-wrap:balance}
  .wz-ayuda{font-size:15px;line-height:1.55;color:var(--ink,#4A434F);margin:10px 0 0}
  .wz-ayuda b{color:var(--tinta)}

  .wz-p{display:none;margin-top:20px}
  .wz-p.on{display:block;animation:wzEntra .18s ease}
  @keyframes wzEntra{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
  @media (prefers-reduced-motion:reduce){.wz-p.on{animation:none}}

  /* — PASO 1 · escoger el norte —
     La explicacion completa se queda. Es lo que separa «mas pedidos» de «mas
     dinero», y quien no la lee escoge a ciegas. Se puede desplazar; no se puede
     adivinar. */
  .wz-objs{display:grid;gap:10px}
  .wz-obj{display:flex;gap:12px;align-items:flex-start;width:100%;text-align:left;
    border:1px solid var(--line);border-radius:var(--tm-r);background:var(--card,#fff);
    padding:14px;cursor:pointer;font-family:inherit;
    transition:border-color .14s ease, background .14s ease, transform .1s ease}
  .wz-obj:hover{border-color:var(--tm-rosa);background:var(--crema,#FAF7F4)}
  .wz-obj:active{transform:translateY(1px)}
  .wz-obj:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-obj.sel{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa);
    background:var(--tm-rosa-piel)}
  .wz-obj-ic{width:44px;height:44px;border-radius:9px;flex:none;background:#F1ECE6;
    display:flex;align-items:center;justify-content:center;color:#A79C90}
  .wz-obj.sel .wz-obj-ic{background:#fff;color:var(--tm-rosa-tx)}
  .wz-obj-ic .ic{width:20px;height:20px;stroke-width:1.8}
  .wz-obj-tx{flex:1;min-width:0}
  .wz-obj-tx b{display:block;font-size:16px;font-weight:600;line-height:1.3;color:var(--tinta)}
  .wz-obj-tx p{font-size:14px;line-height:1.5;color:var(--ink,#4A434F);margin:5px 0 0}
  .wz-obj-tx small{display:block;font-size:14px;line-height:1.45;color:var(--muted);margin-top:6px}
  .wz-obj-ok{flex:none;width:22px;height:22px;color:var(--tm-rosa-tx);opacity:0}
  .wz-obj.sel .wz-obj-ok{opacity:1}
  .wz-obj-ok .ic{width:22px;height:22px;stroke-width:2}

  /* — PASO 2 · el numero y el plazo — */
  .wz-num{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px}
  .wz-num input{width:132px;font-family:var(--font-display,'Poppins',sans-serif);
    font-size:34px;font-weight:700;color:var(--tinta);border:1px solid var(--line);
    border-radius:var(--tm-r);padding:8px 12px;min-height:60px;background:var(--card,#fff);
    -moz-appearance:textfield}
  .wz-num input::-webkit-outer-spin-button,
  .wz-num input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
  .wz-num input:focus{outline:2px solid var(--tm-rosa);outline-offset:1px;border-color:transparent}
  .wz-unidad{font-size:16px;font-weight:600;color:var(--ink,#4A434F)}
  .wz-nose{min-height:44px;padding:0 14px;border:1px solid var(--line);border-radius:99px;
    background:var(--card,#fff);font-family:inherit;font-size:14px;font-weight:600;
    color:var(--tinta);cursor:pointer;margin-left:auto}
  .wz-nose:hover{border-color:var(--tm-teal);color:var(--tm-teal-tx)}
  .wz-nose:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-tip{display:none;font-size:14px;line-height:1.5;color:var(--tm-teal-tx);
    background:var(--tm-teal-piel);border-radius:var(--tm-r-bt);padding:11px 13px;margin-top:12px}
  .wz-tip.on{display:block}

  .wz-sub{display:block;font-size:16px;font-weight:600;color:var(--tinta);margin:26px 0 10px}
  .wz-chips{display:flex;gap:9px;flex-wrap:wrap}
  .wz-chip{display:flex;flex-direction:column;align-items:flex-start;gap:2px;
    min-height:48px;padding:9px 15px;border:1px solid var(--line);border-radius:var(--tm-r-bt);
    background:var(--card,#fff);font-family:inherit;font-size:15px;font-weight:600;
    color:var(--tinta);cursor:pointer;transition:border-color .14s ease, background .14s ease}
  .wz-chip small{font-size:14px;font-weight:500;color:var(--muted);line-height:1.35}
  .wz-chip:hover{border-color:var(--tm-rosa)}
  .wz-chip:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-chip.sel{border-color:var(--tm-rosa);box-shadow:0 0 0 1px var(--tm-rosa);
    background:var(--tm-rosa-piel);color:var(--tm-rosa-tx)}
  .wz-chip.sel small{color:var(--tm-rosa-tx)}

  /* — PASO 3 · con que cuenta — */
  .wz-libre{width:100%;min-height:120px;border:1px solid var(--line);border-radius:var(--tm-r);
    padding:13px 14px;font-family:inherit;font-size:16px;line-height:1.5;color:var(--tinta);
    background:var(--card,#fff);resize:vertical}
  .wz-libre:focus{outline:2px solid var(--tm-rosa);outline-offset:1px;border-color:transparent}

  /* — PASO 4 · el repaso —
     Aqui no se pide nada nuevo. Se enseña lo escogido en las palabras del
     dueño, cada linea con su puerta de vuelta al paso que la decidio. */
  .wz-res{border:1px solid var(--line);border-radius:var(--tm-r);background:var(--card,#fff);
    overflow:hidden}
  .wz-fila{display:flex;align-items:flex-start;gap:12px;padding:13px 15px;
    border-bottom:1px solid var(--line)}
  .wz-fila:last-child{border-bottom:0}
  .wz-fila-tx{flex:1;min-width:0}
  .wz-fila-tx span{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;color:var(--muted)}
  .wz-fila-tx b{display:block;font-size:16px;font-weight:600;line-height:1.4;
    color:var(--tinta);margin-top:4px;overflow-wrap:anywhere}
  .wz-fila-tx b.suave{font-weight:500;color:var(--ink,#4A434F)}
  .wz-cambiar{flex:none;display:inline-flex;align-items:center;justify-content:center;
    min-width:44px;min-height:44px;padding:0 10px;border:0;background:none;font-family:inherit;
    font-size:14px;font-weight:600;color:var(--tm-rosa-tx);cursor:pointer;border-radius:8px}
  .wz-cambiar:hover{background:var(--tm-rosa-piel)}
  .wz-cambiar:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}

  .wz-bloque{border-radius:var(--tm-r);padding:14px 15px;margin-top:14px}
  /*  «>» y no un descendiente cualquiera: esta regla viste el TITULO del
      bloque -en bloque y en mayusculas- y estaba alcanzando tambien a
      cualquier <b> de enfasis dentro de una viñeta. «mas adelante» salia en
      su propia linea y gritando, partiendo la frase en tres. */
  .wz-bloque > b{display:block;font-size:14px;font-weight:600;letter-spacing:.06em;
    text-transform:uppercase;margin-bottom:8px}
  .wz-bloque li b,.wz-bloque p b{font-weight:600;color:inherit}
  .wz-bloque p{font-size:15px;line-height:1.55;margin:0}
  .wz-bloque ol,.wz-bloque ul{margin:0;padding-left:19px}
  .wz-bloque li{font-size:15px;line-height:1.55;margin-bottom:5px}
  .wz-bloque li:last-child{margin-bottom:0}
  .wz-bloque .nota{font-size:14px;line-height:1.5;margin-top:9px;opacity:.9}
  .wz-medir{background:var(--crema,#FAF7F4);color:var(--ink,#4A434F)}
  .wz-medir b{color:var(--muted)}
  .wz-medir.parcial{background:var(--tm-aviso-piel);color:var(--tm-aviso)}
  .wz-medir.parcial b{color:var(--tm-aviso)}
  .wz-luego{background:var(--tm-teal-piel);color:var(--tm-teal-tx)}
  .wz-luego b{color:var(--tm-teal-tx)}
  .wz-guarda{background:var(--crema,#FAF7F4);color:var(--ink,#4A434F)}
  .wz-guarda b{color:var(--muted)}

  /* — LA NAVEGACION · ANCLADA AL PIE —
     Medido: con los seis objetivos en pantalla, el boton de continuar caia en
     y=2050 con el suelo util en 729. O sea a tres pantallas de scroll. La
     regla de la casa dice que la accion principal se ve sin desplazar, y con
     contenido largo eso solo se consigue anclandola.
     `--wz-suelo` lo pone el guion con el alto real de la barra de abajo: en
     escritorio no hay barra y vale 0. */
  .wz-nav{display:flex;gap:10px;align-items:center;margin-top:22px;
    position:sticky;bottom:var(--wz-suelo,0px);z-index:4;
    background:var(--crema,#FAF7F4);padding:10px 0 12px;
    box-shadow:0 -12px 18px -16px rgba(0,0,0,.35)}
  .wz-atras{min-height:52px;padding:0 20px;border:1px solid var(--line);border-radius:var(--tm-r-bt);
    background:var(--card,#fff);font-family:inherit;font-size:16px;font-weight:600;
    color:var(--tinta);cursor:pointer}
  .wz-atras:hover{border-color:var(--raya-firme,#D8D3CC);background:var(--crema,#FAF7F4)}
  .wz-atras:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-nav .tm-btn{flex:1;margin-top:0}
  .wz-nav .tm-btn:disabled{background:#E4DED7;color:#8D857C;cursor:not-allowed}

  /* — EL FALLO, DONDE PASO —
     Con role=alert y el foco encima: quien no ve la pantalla tambien se entera.
     Y con boton propio, porque «intenta otra vez» sin donde pulsar no es una
     salida, es una queja. */
  .wz-err{display:none;gap:11px;align-items:flex-start;background:var(--tm-aviso-piel);
    border-radius:var(--tm-r);padding:14px 15px;margin-top:18px;color:var(--tm-aviso)}
  .wz-err.on{display:flex}
  .wz-err .ic{width:19px;height:19px;flex:none;margin-top:2px;stroke-width:2}
  .wz-err-tx{flex:1;min-width:0}
  .wz-err-tx b{display:block;font-size:16px;font-weight:700;line-height:1.3}
  .wz-err-tx p{font-size:15px;line-height:1.5;margin:5px 0 0;overflow-wrap:anywhere}
  .wz-err button{min-height:44px;margin-top:8px;padding:0 15px;border:1px solid currentColor;
    border-radius:var(--tm-r-bt);background:none;font-family:inherit;font-size:15px;
    font-weight:600;color:inherit;cursor:pointer}
  .wz-err button:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-err:focus-visible{outline:2px solid var(--tm-aviso);outline-offset:2px}

  /* — MIENTRAS TRABAJA —
     No dice «tu plan ya esta». Dice lo que esta pasando, que es otra cosa. */
  .wz-load{display:none;text-align:center;padding:36px 10px 10px}
  .wz-load.on{display:block}
  .wz-load .sp{width:34px;height:34px;margin:0 auto 16px;border-radius:50%;
    border:3px solid var(--line);border-top-color:var(--tm-rosa);animation:wzGira .8s linear infinite}
  @keyframes wzGira{to{transform:rotate(360deg)}}
  @media (prefers-reduced-motion:reduce){.wz-load .sp{animation-duration:2.4s}}
  .wz-load b{display:block;font-family:var(--font-display,'Poppins',sans-serif);
    font-size:19px;font-weight:700;color:var(--tinta)}
  .wz-load span{display:block;font-size:15px;line-height:1.55;color:var(--muted);margin-top:7px}

  /* — EL MATERIAL DEL DUEÑO —
     Ni caja de alarma ni caja de exito: es una invitacion. Por eso va en crema
     y no en rosa ni en teal, y los dos botones pesan lo mismo entre si — pero
     menos que la primaria del pie, que es la que cierra el paso. */
  .wz-mat{margin-top:16px}
  .wz-mat-est{display:block;font-size:15px;line-height:1.5;color:var(--tm-teal-tx);
    background:var(--tm-teal-piel);border-radius:var(--tm-r-bt);padding:10px 13px;margin-bottom:10px}
  .wz-mat-est:empty{display:none}
  .wz-mat-bt{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;
    min-height:52px;margin-top:9px;padding:0 16px;border:1px solid var(--line);
    border-radius:var(--tm-r-bt);background:var(--card,#fff);font-family:inherit;font-size:16px;
    font-weight:600;color:var(--tinta);cursor:pointer;text-decoration:none}
  .wz-mat-bt:hover{border-color:var(--tm-rosa);color:var(--tm-rosa-tx)}
  .wz-mat-bt:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-mat-bt:disabled{opacity:.6;cursor:default}
  .wz-mat-bt .ic{width:18px;height:18px;stroke-width:1.9}
  .wz-mat-err{margin:10px 0 0;font-size:15px;line-height:1.5;color:var(--tm-aviso);
    background:var(--tm-aviso-piel);border-radius:var(--tm-r-bt);padding:10px 13px}

  /* — LA PUERTA A LO AVANZADO —
     Una fila, no un boton: lo que hay detras es opcional y no debe competir
     con la decision del paso. */
  .wz-ajustes{display:flex;align-items:center;gap:12px;width:100%;text-align:left;min-height:64px;
    margin-top:16px;padding:12px 14px;border:1px solid var(--line);border-radius:var(--tm-r);
    background:var(--card,#fff);font-family:inherit;color:var(--tinta);cursor:pointer}
  .wz-ajustes:hover{border-color:var(--tm-rosa)}
  .wz-ajustes:focus-visible{outline:2px solid var(--tinta);outline-offset:2px}
  .wz-ajustes .ic{width:19px;height:19px;flex:none;color:var(--muted);stroke-width:1.9}
  .wz-ajustes .ic:last-child{margin-left:auto;color:#B8B2AB}
  .wz-ajustes .tx{flex:1;min-width:0}
  .wz-ajustes .tx b{display:block;font-size:16px;font-weight:600;line-height:1.3}
  .wz-ajustes .tx span{display:block;font-size:14px;line-height:1.4;color:var(--muted);margin-top:2px}

  /* — LA CAPA · el paso se queda detras —
     Misma hoja que la de la revision semanal: es la misma casa. */
  /*  La hoja se apoya en el fondo del viewport, y ahi vive la barra fija del
      panel: su pie quedaba DEBAJO, con el boton inalcanzable. `--wz-suelo`
      -el alto real de esa barra, medido por el guion- la levanta lo justo. En
      escritorio no hay barra y vale 0. */
  .wz-hoja-velo{position:fixed;inset:0;z-index:70;background:rgba(35,31,32,.44);
    display:none;align-items:flex-end;justify-content:center;
    padding-bottom:var(--wz-suelo,0px)}
  .wz-hoja-velo.on{display:flex}
  .wz-hoja{width:100%;max-width:560px;max-height:88vh;display:flex;flex-direction:column;
    background:var(--crema,#FAF7F4);border-radius:18px 18px 0 0;
    animation:wzSube .2s cubic-bezier(.22,1,.36,1)}
  @keyframes wzSube{from{transform:translateY(22px)}to{transform:none}}
  @media (prefers-reduced-motion:reduce){.wz-hoja{animation:none}}
  .wz-hoja .cab{flex:none;display:flex;align-items:center;gap:10px;padding:14px 16px 10px;
    border-bottom:1px solid var(--line)}
  .wz-hoja .cab h3{font-family:var(--font-display,'Poppins',sans-serif);font-size:19px;
    font-weight:700;margin:0;flex:1;line-height:1.25;color:var(--tinta)}
  .wz-hoja .cab button{width:44px;height:44px;flex:none;margin:-4px -4px -4px 0;border-radius:11px;
    border:1px solid var(--line);background:var(--card,#fff);color:var(--tinta);
    display:grid;place-items:center;cursor:pointer}
  .wz-hoja .cab button .ic{width:19px;height:19px;stroke-width:2}
  .wz-hoja .cuerpo{flex:1;min-height:0;overflow-y:auto;padding:14px 16px 18px}
  /*  El boton de la hoja va ANCLADO a su pie, no al final de lo que se
      desplaza: con el textarea lleno quedaba fuera de la parte visible de la
      propia hoja. La hoja es una pantalla pequeña y tiene la misma regla que
      la grande — su decision se ve sin buscarla. */
  .wz-hoja .pie2{position:sticky;bottom:0;margin-top:18px;padding:12px 0 2px;
    background:var(--crema,#FAF7F4);box-shadow:0 -12px 18px -16px rgba(0,0,0,.35)}
  .wz-hoja .pie2 .tm-btn{width:100%;margin-top:0}
  @media (min-width:1000px){
    .wz-hoja-velo{align-items:center}
    .wz-hoja{border-radius:18px;max-height:80vh}
  }

  /* — EL GLOSARIO · las palabras raras, a mano y plegadas — */
  .wz-glos{margin-top:26px;border-top:1px solid var(--line);padding-top:6px}
  .wz-glos summary{list-style:none;display:flex;align-items:center;gap:9px;min-height:52px;
    font-size:15px;font-weight:600;color:var(--tinta);cursor:pointer}
  .wz-glos summary::-webkit-details-marker{display:none}
  .wz-glos summary .ic{width:17px;height:17px;color:var(--muted);stroke-width:2;
    margin-left:auto;transition:transform .16s ease}
  .wz-glos[open] summary .ic{transform:rotate(90deg)}
  .wz-glos dl{margin:0 0 14px}
  .wz-glos dt{font-size:15px;font-weight:600;color:var(--tinta);margin-top:11px}
  .wz-glos dd{font-size:14px;line-height:1.55;color:var(--ink,#4A434F);margin:3px 0 0}

  /* ══ ESCRITORIO ═══════════════════════════════════════════════════════
     No es el movil estirado. Aqui hay sitio para COMPARAR —los seis objetivos
     de dos en dos, sin desplazarse— y para llevar el mapa entero a la vista:
     los cuatro tramos del tren con su nombre. El movil no puede con eso, y no
     lo necesita: alli manda ir a lo siguiente con una mano. */
  @media (min-width:1000px){
    .wz{max-width:680px}
    .wz-q{font-size:32px}
    .wz-tren li span{display:block}
    .wz-objs{grid-template-columns:1fr 1fr}
    .wz-nav{margin-top:26px}
    .wz-nav .tm-btn{flex:none;min-width:260px;margin-left:auto}
    .wz-atras{margin-right:auto}
  }
</style>
