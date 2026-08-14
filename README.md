# Crecer — a marketing department operated by AI agents

> **Build with Gemini XPRIZE** · *Small Business Services*
> Guide for judges and evaluators. Everything you need to understand and test the
> product in under five minutes.
>
> *Versión en español: [README.es.md](README.es.md)*

## What it is

Crecer gives a Puerto Rican microbusiness owner — a home baker, a barber, a food truck —
the marketing team they could never afford. It is not a tool the owner has to learn to
use: it is a **corillo** (a crew) of AI agents that plans the month, writes in the owner's
own voice, produces the artwork, publishes to their social accounts and reads the results.
The owner only approves, from their phone.

The model is *done-for-you*, not *do-it-yourself*. That distinction governs every product
decision: **if the owner has to understand the software, we failed.**

**The real audience:** survival-economy businesses that today live inside WhatsApp and
Instagram, with no agency budget and no time. In Puerto Rico, in authentic Puerto Rican
Spanish — not translated.

## Reading the app in English

The product is written in Puerto Rican Spanish because that is the product. For evaluation,
every screen carries an **ES | EN** switch — in the landing footer, on the login page, and
in the panel sidebar under *Idioma*. You can also append `?lang=en` to any URL; the choice
is remembered.

**What the switch does not translate, on purpose:** anything the AI wrote — captions,
plans, the crew's replies, the demo posts on the landing page. That output is the product
and it is the evidence for criterion #2. Translating it would show you a different product
than the one that exists. Interface chrome switches to English; the AI's work stays in
Puerto Rican Spanish.

Coverage is deliberately partial and degrades safely: any string without a translation
stays in Spanish rather than breaking. The entry flow, the navigation and the landing are
complete; deeper screens still show Spanish in places.

## How the agents operate it

**28 distinct agent names** write to `crecer_ia_log`. This is not one model call wrapped in
a UI: every step of the business is executed and recorded by an agent. (The production log
shows which ones have actually run and how many times — that is the difference between what
exists in the code and what does the work.)

| Agent | What it decides |
|---|---|
| `intake`, `genoma`, `voice_dna` | Learn the business and its voice from what the owner tells them |
| `provocador`, `estratega`, `creador`, `editor` | The war room that argues out and writes each piece |
| `director`, `director_editorial`, `director_imagen` | Decide the visual concept and art-direct the image |
| `carruselista`, `reels` | Multi-slide stories and video |
| `planificador` | The month's calendar |
| `analista`, `analitica` | Read the numbers and speak to the owner only when it is worth it |
| `aprendiz` | Learns from every edit the owner makes |
| `gerente`, `asistente` | Talk with the owner in La Sala (The Room) |
| `ayudante` | Support: diagnoses failures, repairs them, escalates what it cannot |
| `ops_retencion`, `ops_conversion`, `ops_soporte`, `reporte_diario` | Operate the **founder's** business, not the client's |

Every call is stored with prompt, model, tokens, cost, latency, status and error — **including
when it fails**. That log is the evidence, not decoration.

### The crew does not publish — it chases a number

Publishing content is easy to fake. What makes Crecer a *department* rather than a generator
is the closed loop:

1. **The owner names a goal** in plain words — *"I want more orders"* — with how much, by
   when, and what budget they have for ads.
2. **The Strategist diagnoses honestly** (*"that goal is ambitious with what you have"*) and
   builds a plan of concrete plays, each one specifying how many pieces it produces and
   exactly what it asks people to do.
3. **The crew executes the plays on its own**, in its weekly relay. Before spending, it
   inventories what already exists: approved posts not yet published, the business's real
   photos, and the posts that already measured well.
4. **Each play closes itself** when its pieces actually go live. The owner does not tick
   checkboxes for work the AI did; they only confirm what happens outside Crecer (placing
   the boost, talking to a partner).
5. **The plan is measured and leaves a lesson** — how much it moved the number — and **the
   next plan inherits it**: what did not work is not repeated.

That whole cycle lives in `crecer_meta`, `crecer_meta_plan` and `crecer_meta_tactica`, with
each piece bound to the plan that produced it. It is auditable piece by piece.

## Google Cloud / Gemini

- **Gemini** runs the entire text and decision layer: learning the business, planning,
  writing, conversing, analyzing. Transport is **Gemini API** or **Vertex AI** depending on
  the environment configuration; whichever is active is visible under *Operaciones → Salud*.
- **Images:** artwork from scratch uses OpenAI's `gpt-image-1` for compositional quality;
  when there is a real photo of the business, Gemini does the editing (it is more faithful
  to the original). We say it plainly because it is better said than discovered: the image
  layer is mixed.
- The requirement of at least one Gemini call in the deployed app is met many times over:
  it is the engine of all text and of every decision in the pipeline.

## Architecture, briefly

Plain PHP (no framework) · MySQL/MariaDB · Hostinger · Git deploy.

```
crecer.php            public landing
onboarding.php        the wizard that learns the business (voice or text)
panel/                the owner's app (Home · Create · Calendar · Results · The Room · Reels)
panel/*_worker.php    async workers (art, video, publishing, the crew) — key required
includes/             the engine: agents, genome, publisher, helper, billing
includes/i18n.php     the ES/EN interface layer (see "Reading the app in English")
scripts/cron_*.php    the work that runs itself (publish, metrics, weekly crew, support)
migrations/           manual SQL — the deploy does NOT apply schema automatically
```

Slow work (generating art, rendering video, publishing) goes to a queue with workers, never
in front of the owner. New tables carry the `crecer_` prefix.

**Reused code:** Crecer is a new repository (June 2026) that reuses infrastructure from
Encuéntralo — a services directory that never launched (zero users in production). What is
new and what is reused is declared in **[REUSE.md](REUSE.md)**.

## Trying it

### Production

**https://encuentraloahora.com/crecer/**

| | |
|---|---|
| **Evaluation account** | `_______________` |
| **Password** | `_______________` |
| **Test business** | clean account, no real customer data |
| **Access** | free and complete during evaluation (no charge) |

> If these credentials are blank at the time of evaluation, write to
> **icaro2004@gmail.com** and we will grant access the same day.

### ⚠️ Before you touch anything

This is an application **in production with real customers**. Please:

- **Do not run real charges.** Checkout is Stripe in live mode.
- **Do not publish to social accounts** from anything other than the evaluation account:
  it would really publish to a business's Instagram/Facebook.
- **Do not use the `/panel/admin_*` tools** or `_cache.php` / `_imgtry.php`: they are
  operational and some of them spend API credit.

### Suggested five-minute route

1. **Register** and complete onboarding — tell the crew about a made-up business (you can
   speak to it out loud).
2. Watch it **learn**: when you finish, it reflects back what it understood about your
   business.
3. It leaves you a **first post already made** — caption in your voice, generated artwork.
4. **Approve it or adjust it.** If you edit it, the `aprendiz` agent learns from that edit.
5. Go into **La Sala** (The Room) and ask for something in your own words: *"I need
   something for Mother's Day"*. You will see the chain of agents working.
6. **Set a goal** (*Tu Meta* / Your Goal in the menu): choose what you want to achieve, how
   much and by when. The Strategist builds the plan in front of you — including its honest
   diagnosis of whether the goal is reachable. Tap **"Que lo haga el corillo"** ("let the
   crew do it") on any play and the full production runs on its own. **This is the heart of
   the product:** the rest of the panel works for that number.
7. Under **Operaciones → Evidencia** (admin account) is the ledger: which agent decided
   what, with real cost and latency. And under **Armar el paquete de evidencia** are all the
   submission figures — real revenue with related-party separated out, API usage and the
   agentic cycle — ordered by criterion and exportable as JSON/CSV.

### Local install

```bash
# 1. Config
cp includes/config.local.example.php includes/config.local.php
#    Fill in: DB_*, GEMINI_API_KEY (or GCP_* for Vertex), and CRECER_WORKER_KEY.
#    CRECER_WORKER_KEY is MANDATORY: without it the async workers fail closed (503).

# 2. Schema — the deploy does NOT apply SQL. Run migrations/ in chronological order.
#    Start with 2026-06-13_crecer_schema.sql.

# 3. Tests (all must come out green, exit 0)
php tests/test_creative_thesis_unit.php
php tests/test_pipeline_tesis_integracion.php
php tests/test_creador_editorial.php
php tests/smoke_creative_thesis_funcional.php
php tests/test_i18n_unit.php
```

Without AI credentials the app runs in `mock` mode and says so: the model is recorded as
`mock` in the log, so a simulated response can never be mistaken for real evidence.

## What is live and what is roadmap

Honesty about the real state — the standard we hold ourselves to is not to present as
operational anything that does not yet govern production.

**Live in production:**

- Onboarding that learns the business (voice or text) and a brand profile
- Creation of posts, carousels and reels with generated artwork
- Owner approval (nothing publishes without their OK)
- Automatic publishing to Instagram and Facebook, video included
- Calendar, results with real Meta metrics, weekly report
- La Sala: conversation with the crew, by text or voice
- El Ayudante: support that diagnoses, repairs and escalates on its own
- Stripe billing · SMS verification (Twilio)
- The crew works on its own via a weekly cron
- **The Goal and its plan** (since Aug 12): the owner declares a number to chase, the
  Strategist builds the plan, the crew executes the plays in its relay, each one closes
  with proof of publication, and the closed plan's lesson feeds the next one
- **ES/EN interface switch** for evaluation (partial coverage; falls back to Spanish)

**Implemented but NOT governing production:**

- **Creative Thesis** (`includes/creative_thesis.php`) — the layer that decides *the idea*
  worth telling and abstains when it does not know enough. It is built and tested
  (`tests/smoke_creative_thesis_funcional.php`), but it lives behind the
  `VOICE_DNA_ONBOARDING_ENABLED` flag, which is **OFF**. It must not be presented as an
  active operation while that remains true.

**Roadmap, not built:**

- AI WhatsApp agent (needs a dedicated Cloud API number)
- ATH Móvil as a payment method (what the Puerto Rican customer actually uses)
- The "Despegar" plan — frozen (`activo=0`)
- Encuéntralo's two-sided marketplace (phase 2, outside the scope of this submission)

## Human vs. AI

**The AI does:** learn the business, decide the month's plan, write every caption in the
owner's voice, art-direct and generate the imagery, assemble carousels and reels, publish to
the social accounts, read the metrics and recommend, answer in La Sala, diagnose and repair
system failures, and watch retention/conversion/support for the founder's own business.

**The human does (Manuel, solo founder):** build the product, decide strategy and pricing,
find the customers, handle what the Ayudante escalates, and sign off on business decisions.
The **client business owner** approves the content — that approval is mandatory and cannot
be skipped.

**What the AI does not do, by our decision:** publish without the owner's approval, invent
products or prices the business does not have, or use third-party photos for a real business.

## Evidence and privacy

- `crecer_ia_log` — every model call, with cost, tokens and outcome.
- `crecer_incidencias` — what automated support could not fix.
- `pagos` — the revenue ledger, with `producto='crecer'` separated from the marketplace.
- *Operaciones → Evidencia* consolidates the view for judges.

**Privacy:** no screenshot, log or export in this submission contains personal data of a
real customer. The ~430 reviews tagged `[omega-seed-2026]` or with `*.mail.test` addresses
are **fictional seed data** and are never presented as real users. The landing page feed is
made of examples, labeled as such.

## License and repository terms

**Private repository. All rights reserved © 2026 Manuel Pardo / Encuéntralo.**

Shared with Build with Gemini XPRIZE evaluators for the sole purpose of judging this
submission. No license is granted to use, copy, modify or distribute. If the repository is
ever made public, its license will be decided before publication — no open license should be
assumed by default.

## Contact

Manuel Pardo · solo founder · Puerto Rico
