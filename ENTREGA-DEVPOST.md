# EL SOBRE — todo lo que se pega en Devpost

> Uno por campo, en el orden en que te los pide el formulario. Lo que está en
> bloque `>` se **copia y se pega tal cual**. Lo que está marcado `«...»` es lo
> único que hay que llenar antes.
>
> Entrada: [xprize.devpost.com](https://xprize.devpost.com/) → *Enter a submission*.
> **El draft no cuenta.** Hay un botón *Submit* al final, después de aceptar términos.
> Deadline: **lunes 17 ago, 1:00 PM PT** (4:00 PM PR). Meta: enviar a las 12:00 PM PT.

---

## 0. El orden de mañana

1. Subir el video a YouTube **público** (no *unlisted*) y copiar el link.
2. Llenar la plantilla P&L con los montos de las facturas → `php evidencia/pl.php`.
3. Abrir el formulario y pegar, campo por campo, lo de abajo.
4. Adjuntar los archivos de evidencia.
5. Aceptar términos → **Submit**.

---

## 1. Project name

> Crecer

## 2. Elevator pitch / tagline

> An AI crew that runs the marketing for Puerto Rico's smallest businesses: it plans, writes in the owner's own voice, designs, publishes and learns. The owner only approves, from their phone.

## 3. Category

> Small Business Services

## 4. About the project — *cómo el proyecto cumple los requisitos*

Este es el texto que las reglas piden por nombre: *"a text description how your
Project meets the above requirements... explain the relevance to the chosen
Category."* **No es la narrativa** — esa va aparte, en el punto 5.

> **What Crecer is.** Crecer is a marketing department operated by AI agents for
> the smallest businesses in Puerto Rico — the home baker selling through
> WhatsApp, the barber whose storefront is Instagram, the family business that
> cannot afford an agency. The model is done-for-you, not do-it-yourself: the AI
> crew learns the business, plans the month, writes in the owner's own Puerto
> Rican Spanish, produces the artwork, publishes to Instagram and Facebook, reads
> the results and adjusts. The owner only approves, from a phone.
>
> **Relevance to Small Business Services.** The businesses we serve are the
> survival economy of the island: they are excellent at their craft and have no
> time, no budget and no marketing skill. Existing tools sell them software they
> must learn to operate. Crecer sells them the outcome — the feeling of having
> people working for them. According to the U.S. Small Business Administration's
> 2025 Puerto Rico profile, 41,362 small employers on the island employ 407,296
> people, 98.4% of Puerto Rico's employers; the informal microbusinesses selling
> through social media are not even counted in that figure. This is the category,
> and it is underserved precisely where we operate.
>
> **AI agents operate the business, not just a feature of it.** 31 distinct
> agent names write to our `crecer_ia_log` table. Agents run the intake
> interview, build the business genome and voice profile, plan the month, argue
> out and write each piece, art-direct the images, publish on schedule, read the
> metrics, write the lesson the next plan inherits, answer WhatsApp, diagnose and
> repair platform failures, and prospect for new customers. Every model call is
> stored with agent, model, prompt, tokens, cost, latency, status and error —
> including its failures. Scheduled cron jobs run the weekly cycle in production
> without a human starting them.
>
> **Google Cloud requirement.** Gemini runs the entire text and decision layer of
> the product: learning the business, planning, writing, conversing and
> analyzing. Transport is the Gemini API or Vertex AI depending on environment
> configuration, and whichever is active is visible in the app under
> *Operaciones → Salud*. The requirement of at least one Gemini call in the
> deployed application is met many times over — it is the engine of the product.
> We state one thing plainly rather than let it be discovered: the image layer is
> mixed. Artwork generated from scratch uses OpenAI's `gpt-image-1` for
> compositional quality, while Gemini does the editing whenever there is a real
> photo of the business, because it is more faithful to the original.
>
> **New project.** The repository was created in June 2026, after the start of
> the Submission Period, and its entire git history is public.
>
> **Pre-existing work, declared.** Crecer reuses infrastructure from Encuéntralo,
> a Puerto Rico services directory that was in development and **never launched**
> — zero users and zero providers in production. What is reused is the plumbing:
> the PDO database connection and data helpers, security headers, the
> transactional email templates, the photo upload system, and pre-existing tables
> for authentication, payments, municipalities and categories. Everything that
> makes Crecer a product — the agentic marketing loop, the `crecer_*` tables, the
> Gemini integration, the AI logging, the business genome, Puerto Rican voice
> generation and mobile approval — is new and was built for this hackathon. The
> full line-by-line declaration is in `REUSE.md` at the root of the repository.
>
> **Access for judging.** The product is live at
> https://encuentraloahora.com/crecer/ and will remain available free of charge
> and without restriction through the end of the Judging Period on September 15,
> 2026. Evaluation account: `icaro2004+jurado@gmail.com`, password `crecer@1`.
> It lands you inside a business already set up and running — no signup, no
> onboarding, nothing to configure — and it is a courtesy account: never charged,
> never counted as a customer or as revenue. The entire interface carries an
> ES | EN switch, or append `?lang=en` to any URL. What the AI itself wrote is
> deliberately not translated — that output is the product and the evidence.

## 5. Written narrative (500–1000 palabras)

Pegar **completo y sin cambios** el cuerpo de [`NARRATIVA-DEVPOST.md`](NARRATIVA-DEVPOST.md)
— desde *"We arrived late..."* hasta *"¡Pa'lante!"*. Son **983 palabras**: dentro
del rango. No añadir párrafos; una palabra más de 1000 nos saca.

## 6. Built With (tags)

> gemini-api · google-cloud · vertex-ai · php · mysql · mariadb · javascript · stripe · meta-graph-api · instagram-graph-api · whatsapp-cloud-api · twilio · openai · hostinger

## 7. Try it out links

> Live product — https://encuentraloahora.com/crecer/
> Source code — https://github.com/icaro2004-ux/crecer

## 8. Video

> «LINK DE YOUTUBE»

**Público, no *unlisted*** — la regla dice *"made publicly visible"*. Menos de
3:00. Sin música con copyright. Probarlo en ventana de incógnito antes de pegarlo.

## 9. Thumbnail / galería

3:2, JPG/PNG/GIF, máx 5 MB. Sirve un frame limpio del video o una captura del Home.

---

## 10. Revenue evidence

Los cuatro números salen de `php evidencia/pl.php`. Adjuntar además la
**plantilla P&L oficial** llena y el export de Stripe.

> **Total Revenue (arms-length third-party customers): $0.00 USD.**
>
> **Revenue by month:** May 2026 $0.00 · June 2026 $0.00 · July 2026 $0.00 · August 2026 $0.00.
>
> **Related-Party Revenue: $0.00.** No revenue was earned from team members,
> family, related entities or pre-existing customer relationships. We deliberately
> did not run the founder's own card through checkout to print a revenue figure: a
> founder paying himself is not a customer, and a number that needs an asterisk is
> worth less than a zero that does not.
>
> Billing is built and live, not theoretical. Stripe runs in live mode and the
> $39/month price is verified against what the application promises — the app
> refuses to open a checkout session if those two numbers disagree, so a mismatch
> cannot reach a customer. A live checkout session opens. What has never happened
> is a completed payment.
>
> **Total Expenses: $444.34 USD**, cash basis, every line backed by a provider
> receipt. All receipts are attached.
>
> **By month:** May $111.50 · June $111.50 · July $142.09 · August $79.25.
>
> **Cost of building it — $334.50.** Claude Max, the AI tooling used to write the
> software, billed monthly at $111.50 including Puerto Rico sales tax: May 23,
> June 23 and July 23. The August 23 charge falls after the submission deadline
> and is therefore excluded.
>
> **Cost of running it in production — $109.84 for three months.** Google Cloud
> Gemini API $25.15; OpenAI API credits for image generation $30.00 on July 21
> and $15.10 on August 10; Twilio verification messaging $0.59; Shotstack video
> rendering $39.00. That is roughly thirty-seven dollars a month to operate the
> service, and it is the figure that determines whether $39/month is a viable
> price. Two thirds of it is image generation; the layer that plans, writes,
> analyzes and decides is the inexpensive part.
>
> Nothing above is estimated. Some receipts are billed to **Manuel Rivers**, a
> pseudonym of the entrant, Jesús Manuel Pérez Rivera — same person, same email
> and same address as the other receipts.
>
> **Pre-hackathon resource, declared.** The server this product runs on was not
> bought for the hackathon: Hostinger Business Web Hosting was paid on **April
> 25, 2026** — before the Submission Period opened — as an annual plan of $59.88
> covering April 2026 through June 2027. Under the cash-basis method the
> template requires, it falls outside the period and is therefore excluded from
> the P&L above; we disclose it here so the cost of running Crecer is not
> understated. Prorated across the plan, the hosting attributable to the
> hackathon window is roughly $13.
>
> **Marketing and Customer Acquisition Spend: $0.00.** We spent nothing on
> marketing or customer acquisition during the Hackathon period.

## 11. User evidence

> **Arms-length customers during the Hackathon period: 0.** We are being exact
> rather than generous. The accounts that exist in production are the founder's
> own test businesses, used to build and exercise the product end to end. We have
> no third-party customers to name, and therefore no customer contact information
> and no testimonials to submit. We would rather report a zero we can defend than
> a number that needs an asterisk.
>
> Our project database also contains approximately 430 seed reviews created
> during development of the underlying directory infrastructure, tagged
> `[omega-seed-2026]` and using `*.mail.test` addresses. They are fictitious, they
> are excluded from every figure in this submission, and we flag them here so no
> one mistakes them for users.
>
> **Who the users would be:** owner-operated microbusinesses in Puerto Rico —
> bakeries, food service, barbers, trades and family businesses — that today
> operate entirely through WhatsApp and Instagram, in Puerto Rican Spanish.

## 12. Product evidence — evidencia de que corre en producción

Adjuntar del paquete (`panel/admin_paquete.php`): el **JSON**, `crecer_uso_api.csv`,
el CSV del log de IA y `crecer_revenue_por_mes.csv`. Capturas: panel de Stripe en
live, un post publicado en Instagram con su permalink, y la pantalla de evidencia.

> Measured in production on August 16, 2026:
>
> - **9,132 model calls**, of which 8,882 succeeded and **250 failed — and the
>   failures are in the log too**, because a log that only records successes is
>   not evidence.
> - **3,886 calls to Gemini.** 3,236,247 input tokens and 831,680 output tokens.
> - **31 distinct agents** operating the business, each writing under its own name.
> - **537 content pieces produced**, 37 of them published by the AI to Instagram
>   and Facebook, with 50 successful platform confirmations recorded.
> - **32 inbound messages answered by the AI**, 1 escalated to a human.
> - **6 business goals** turned into **7 plans** by the strategist.
>
> Every call is recorded in `crecer_ia_log` with its agent, model, prompt, tokens
> in and out, latency, status and error message. Nothing in it was reconstructed
> for this submission: it is the table the application writes to as it works. The
> complete raw log is attached as CSV, alongside API usage by model and the
> revenue ledger by month. One note in the interest of precision: the cost column
> in that log is our own estimate from a price table and reads lower than what we
> actually paid — the provider invoices are what we report as expenses, and they
> are attached.
>
> Scheduled cron jobs run the publishing, metrics, weekly crew and support cycles
> on their own schedule, without a human starting them. Judges can watch agents
> execute live from inside the app; the credentials below open a real working
> account, not a demo mode.

Escrito sin cifras a propósito: el CSV adjunto las tiene y no hay que copiarlas
a mano. Si el paquete se baja a tiempo, se le puede añadir una línea con el
número de llamadas y de agentes distintos — **sacada del paquete, nunca inventada**.

## 13. Repository

> https://github.com/icaro2004-ux/crecer

Público y verificado desde incógnito. No hacen falta invitaciones a
`testing@devpost.com` ni a `judging@hacker.fund`.

## 14. Judge access

> Live product: https://encuentraloahora.com/crecer/
> Email: icaro2004+jurado@gmail.com
> Password: crecer@1
> Interface language: append `?lang=en` to any URL, or use the ES | EN switch.
>
> The account lands inside a business that is already set up and running — no
> signup, no onboarding, nothing to configure. It is a courtesy account: it is
> never charged, and it is reported as such rather than counted as a customer or
> as revenue. Access stays free and unrestricted through the end of the Judging
> Period on September 15, 2026.

Comprobar hoy que esa cuenta **no expira antes del 15 de septiembre** y que no
choca con el paywall (`includes/panel_guard.php`).

---

## Lo que NO se hace

1. No se afirma nada que no esté vivo en producción el día de entregar.
2. Las reseñas semilla no entran como usuarios ni como testimonios.
3. Si algo falla, se recorta el alcance — no se falsea.
4. Después de enviar no se puede editar la entrega. El tag `v1.0-xprize` congela
   lo que el jurado evalúa; producción puede seguir avanzando.
