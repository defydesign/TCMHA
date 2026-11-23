# Brand: Direction B "Neighbors"

Chosen November 23, 2026 (pending client review). Source of truth for code is `wp-content/themes/tcmha-child/assets/css/tokens.css`. Create the same values as **Elementor global variables** (Site Settings > Global Colors / Global Fonts, or V4 variables) using the names below.

## Colors

| Variable | Hex | Use | Contrast |
| --- | --- | --- | --- |
| `color-ink` | #1E1A22 | Body text | 17.1:1 on white |
| `color-primary` (TCMHA purple) | #625778 | Headings, links, primary buttons, hero band | 6.7:1 on white; white text on it 6.7:1 |
| `color-surface-alt` | #F4F1F8 | Light section backgrounds | Ink 15.3:1, purple 6.0:1 |
| `color-lavender` | #CCBFDD | Card and panel backgrounds **only**, with ink text | Ink 9.8:1 |
| `color-slate` | #546877 | Secondary buttons, cards with white text | 5.8:1 either way |
| `color-sunrise` | #F2A65A | Accent buttons and fills with ink text | Ink 8.5:1 |
| `color-crisis` | #A3261B | Crisis bar and crisis actions only | White text 7.4:1 |

## Rules

- Mostly light backgrounds. A purple hero band is fine; avoid large dark navy or slate areas.
- Never put text in lavender or sunrise on a light background. Never put purple text on lavender (3.8:1).
- Crisis red is reserved for crisis actions, so it always means "urgent help."
- Buttons are pills (`radius-pill`), cards are rounded (`radius`, 20px). Touch targets at least 44px.

## Type

- **Lexend**, self-hosted (`assets/fonts/lexend-latin-variable.woff2`, weights 400 to 700). No Google Fonts requests, so no visitor data goes to Google.
- Headings: Lexend Bold, purple. Body: Lexend Regular, 18px, line height 1.6.

## On Dev (Elementor V4)

Set up November 23, 2026 through the Elementor MCP.

- **Variables:** every color in the table above, plus `color-surface`, `color-on-primary`, `color-on-slate`, `color-on-sunrise`, `color-on-crisis`, `color-focus` (#1A5FB4), `color-ink-muted` (#4A4452), `color-border` (#D9D2E3, decorative card borders only); fonts `font-body` and `font-heading` (Lexend); sizes `font-size-sm/base/lg/h4/h3/h2/h1` (h1 and h2 fluid with `clamp`), `space-1` to `space-7`, `radius`, `radius-pill`, `target-min` (44px), `width-content` (72rem), `width-measure` (42rem).
- **Default tag styles:** h1 to h6 Lexend Bold purple, p Lexend 18px ink, links purple and underlined with a focus ring.
- **Global classes** (all `tcmha-`): layout `section`, `section-alt`, `container`, `measure`, `grid-2`, `grid-3`, `grid-4`; surfaces `hero`, `card`, `card-lavender`; text `eyebrow`, `lead`, `muted`, `on-dark`; buttons `btn-primary`, `btn-secondary`, `btn-accent`, `btn-outline`, `btn-crisis`; `badge`, `badge-now`, `badge-done`, `swatch`. Modifier classes sit at the top of the class order so they win over base classes.
- Lists inside a Paragraph element must use the `span` tag. A `<ul>` inside `<p>` is invalid HTML and the browser splits it.

## Logo

The two-circle mark is a concept. Final logo work is still open.
