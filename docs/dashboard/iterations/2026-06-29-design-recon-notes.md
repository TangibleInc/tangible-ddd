# Design recon notes

## Site A — teamtangible.com (hand-built presentation site, the pre-LLM source)

**Mood:** warm, human, playful-but-credible. "Friendly expert." Editorial collage.

**Color**
- Ink near-black (#1a1a1a) for text + solid buttons.
- Warm paper background: cream→white soft gradient (#FFF9F2-ish).
- Accent set is a *rotating pastel palette*: coral/salmon (#FF5A4D-ish), grass green (#8BC34A / olive), lavender (#C9B8F0), peach, mint. No single brand blue — playful multi-accent.
- Accents appear as **marker-highlight swashes** behind/under keywords (hand-drawn rounded highlight, not flat). Accent color *cycles per rotating word* (LearnDash=coral, LifterLMS=green).

**Type**
- Display headline: heavy grotesk, tight tracking, very large, mixed-weight. Bold black.
- Body: clean humanist sans, medium gray, comfortable measure.
- Nav: small, medium weight.

**Components / motifs**
- Pill buttons: "Contact us" = white fill + thick (~2px) full-radius black outline. Primary CTA = solid black, ~8px radius, slight hard shadow, white text.
- **Tinted card blocks**: pastel-tinted rounded rectangles (green/lavender/peach), each with a **dashed-border icon badge** top-left (icon in dashed square).
- **Outline tag chips**: small white rounded-rect pills w/ thin border, tiny label — used to enumerate sub-features inside cards.
- **Hand-drawn marker arrows/squiggles** connecting sections + pointing at highlighted items. Signature motif.
- Stats: huge bold counters (0+, 0k+) + simple line chart.
- "Reach" list rendered as **staggered colored gradient pill bars** (like a soft bar chart), marker arrow annotating the key row.
- Decorative collage: cutout photography over pastel blobs + scattered iconographic mini-cards (WP gear, video card, checklist) with dashed borders.
- Generous whitespace + strong vertical rhythm. Dark CTA section near footer for contrast.

**Translatable to dashboard:** tinted status cards, dashed icon badges, outline tag chips (perfect for command/event/status tags), big metric counters, staggered bar visualization, marker accent on the ONE thing that needs attention. Restraint: marker/collage is charming but must be dialed WAY down for an operational tool — borrow the warmth + tag system + accent-on-attention, not the scribbles everywhere.

## Site B — staging.tangible.one (LLM-derived store) — SOURCE: /Users/titustc/tgbl/tangible-app

EXACT tokens (tailwind.config.js + design-preview/):
- Fonts: sans=League Spartan, display=Recoleta (serif). letter-spacing -0.005em on body.
- primary/brand indigo #6359D6 (50 #F0EEFD .. 900 #2D2A66). indigo-soft #F0EEFD.
- ink #000, warm #FDFAF8 (page bg), cream #FFF5E4 (hover/tint), cream-soft #FEECD0,
  mint #E8F0CC, olive #507F06, tobacco #936C21.
- danger 100 #FFF2F3 / 400 #C22F32. success 100 #EBF5DC / 400 #507F06(=olive).
- Radius hierarchy: pill 9999px (PRIMARY CTA ONLY) · card 12px · control 8px · input 6px.
- Stack: Tailwind + Flowbite/flowbite-react. darkMode: class.

Components (from design-preview/dashboard.html — codified CSS):
- eyebrow: uppercase, ls .2em, 600, .7rem, #6b7280.
- btn-pill: black fill + 2px black border, white text; HOVER INVERTS to white/black. outline variant mirror.
- nav-item: 8px radius, gray text, hover bg cream, ACTIVE = black bg/white text.
- cards: rounded-xl(12px) border-gray-200 bg-white; header w/ border-b gray-100 (eyebrow + display title + right meta).
- lists: divide-y gray-100, row hover bg-cream/50, trailing → arrow gray-300.
- section-band: tinted severity band inside card (red #FFEDED / indigo-soft / mint) + 22px icon-bubble.
- badges: soft (indigo-soft bg + indigo text) vs solid (indigo bg + white). small rounded.
- icon tiles: 10x10 rounded-lg tinted (indigo-soft/mint/cream-soft) w/ bold initials in accent.
- squiggle: indigo hand-drawn SVG underline that FADES IN ON HOVER on links — the ONE
  restrained nod to teamtangible's marker motif. Key "tech-ier but warm" move.
- step-ring: 20px circle, complete=olive/✓, pending=gray/number.
- LICENSING TABLE (the tabular keystone): eyebrow+serif title+count; search input + SHOW filter pills;
  table = uppercase muted col heads, primary/secondary text cells, MONOSPACE masked key + copy icon,
  TYPE badge pills (plugin=soft, bundle=solid), ACTIVATIONS "9/10" + inline progress meter,
  STATUS success badge, row-end pencil action, light separators, pagination. Maps ~1:1 to command_audit.

CONTINUITY teamtangible -> store: warm cream paper, pixel-square logo, black pill buttons,
League Spartan all carry over. Store systematizes it: indigo=functional primary, black=structural/active.

## USER STEER (mid-recon)
- Recoleta (groovy serif) likely WRONG for an ops dashboard — drop as workhorse. "invent" dash type.
- "WP-ish dash" — lives in wp-admin (mu-plugin tangible-dddash.php). Harmonize w/ WP admin chrome.
- => Type for dash: League Spartan (brand) headings/eyebrows ONLY + system/WP stack body + MONOSPACE data.

## DDD DASHBOARD — 4 directions to build (one brand skin, framed in slim wp-admin shell)
Domain vocab (real): commands EscalateDeliveryCommand/CreateSubscriptionCommand/MatchSubscriptionsOnCapture;
entities EventSubscription/Destination/DeliveryOutbox; tables command_audit/integration_outbox/
integration_dlq/long_processes/behaviour_workflows; trace = correlation_id/command_id/causation.
1. Operational Overview — KPI tiles + severity bands + recent-command feed. "is it healthy". low density.
2. Trace Workbench — command_audit dense table + click-row trace/causation drawer. "what happened". high.
3. Mission Control — metric grid + sparklines + streaming monospace event tail. "watch live". highest.
4. Entity Lifecycle Lens — one aggregate timeline + CAS version progression + causation graph. unique. medium.
Deliverable: ONE artifact, sticky top switcher between the 4. fonts embedded (League Spartan b64).
