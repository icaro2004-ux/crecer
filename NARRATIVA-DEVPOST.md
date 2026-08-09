# Narrativa para Devpost (500–1,000 palabras)

> Borrador v2 · 2026-08-08 · Para el campo "written narrative" del XPRIZE.
> En inglés porque así lo leen los jueces. Voz: humana, alegre, con la gente
> al frente — no "modo AI". Cada afirmación sigue siendo rastreable a evidencia
> (log, pantalla, Stripe o repo). Revisar y aprobar antes de pegar.

---

## Who this is for

In Puerto Rico we have a phrase for how thousands of families get by:
**"buscando el peso"** — out there every single day, hustling for every dollar.
The woman baking bizcochos in her kitchen, selling them over WhatsApp. The
barber whose entire storefront is an Instagram page. The DJ, the cake lady, the
guy who fixes your AC in August. They're *great* at what they do — and invisible
online, because marketing is a full-time job and they already have one of those.
They know they should post. They don't know what to write. And an agency costs
more than the rent.

Crecer gives them the thing they were never supposed to be able to afford: a
whole marketing department. Around here we'd call it your **corillo** — your
crew, the people who show up for you. Crecer's corillo is a team of AI agents
that runs the month like a real department would: it learns the business from
the owner's own voice, plans the calendar, writes captions in real Puerto Rican
Spanish (never translated, never AI slop — it says *bizcocho*, not *tarta*),
designs the art, publishes straight to Instagram and Facebook, reads the
numbers, and adjusts. The owner does exactly one thing: approve from the phone,
between customers. That first time someone hears their own voice in a caption
they didn't write? That's the product.

## The story

I spent months building Encuéntralo, a services directory for Puerto Rico that
never launched. When this competition said the business itself had to be
*operated* by AI, something clicked: forget the directory waiting for
businesses — build the AI department that makes those businesses stronger, and
let the directory come later, full of clients whose trust you already earned. I
pivoted in June, reused my own infrastructure (declared in the repo), and built
Crecer new. Best rule a competition ever forced on me.

## Where the AI is, every day

The onboarding is my favorite part: the crew *interviews* the owner — a real
conversation, spoken or typed. You talk, Gemini (via Vertex AI) transcribes you
— boricua and all — the crew answers out loud and asks its next question based
on what you just said. When the interview ends, it builds the structured brand
profile and hands the owner their first finished post. No forms. Then the crew
takes over: a strategist plans the month, a copywriter drafts in the owner's
voice and permanently learns every correction ("she says *china*, not
*naranja*" — noted, forever), a designer makes the art, a publisher posts to
Instagram and Facebook through Meta's API, an analyst watches what worked.

And Crecer runs *itself* on agents too: a support agent sweeps the platform
every 15 minutes, fixes what it can on its own, and only escalates to me when
it can't. An acquisition agent hunts and scores real Puerto Rican
microbusinesses and builds the outreach queue I work from. Every single model
call — prompt, model, tokens, cost, latency, outcome, including the failures —
lands in an audit log, and our submission includes a live evidence screen where
judges can watch the crew execute in real time, in production. I build with AI
daily too: this codebase was written working with AI coding agents, with the
same discipline of logs and tests. AI-native at build time, AI-native at runtime.

**My job as the human:** strategy, voice standards, sales, billing, legal, and
the final "ship it." The crew does the department's work; I do the owner's.

## The owner runs the show

Images are where you see the philosophy: **the owner runs the show, the crew
adapts.** A baker with a phone full of product photos drops them into her
business library and the crew builds the posts around them — her photos, as-is,
AI on caption duty. Can't afford a photographer? The crew takes her real photo
and gives it the studio look — enhancing what's there, never inventing the
product. Running a promo with no product photo to show? The AI designs the art
from scratch. These are options, not a flowchart — the crew follows the owner's
instinct, and nothing goes out without their say-so. The deal is simple: owners
answer for what they publish, we answer for what we produce — true to their real
business, never stock photos, never someone else's images.

## Honest numbers, real opportunity

Crecer charges $39/month through live Stripe billing, and I'm proudly its first
paying customer — I run my own acquisition marketing on Crecer and get charged
like anybody else (reported as related-party, separately, as the rules ask).
Because every AI call is metered, I know the real cost of serving a client
against that $39. This model doesn't need a pitch deck to defend itself; it has
receipts.

Nobody loses a job to Crecer — these businesses could never hire. What they get
back is the thing they're shortest on: hours, and a fighting chance online. And
the prize has a named destination in this same market: ATH Móvil (the payment
rail Puerto Rico actually uses), the WhatsApp agent (the channel where these
businesses actually live), and launching Encuéntralo on top of Crecer's
clients — the directory, finally, the right way around.

We're not submitting a demo. We're submitting a working business where the AI
does the working — with the receipts to prove it.

---

> **Conteo:** ~870 palabras (dentro del rango 500–1,000 — verificar al pegar en Devpost).
> **Checklist de reglas:** ✅ uso diario de IA · ✅ división humano vs. IA ·
> ✅ oportunidad económica/empleo · ✅ historia del build · ✅ related-party declarado.
