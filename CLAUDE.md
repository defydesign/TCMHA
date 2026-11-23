# TCMHA Website: working rules

Teller County Mental Health Alliance site rebuild. Client of record: ChangeLine (CDPHE EPIC grant). Launch target: March 15, 2027.

## Stack
- WordPress on WP Engine (Dev, Staging, Production). Parent theme: Hello Elementor. Page building: Elementor Pro using **Atomic (V4) elements only**, so layouts stay editable through the Elementor MCP.
- `wp-content/themes/tcmha-child/`: brand tokens, accessible base styles, Elementor overrides. No business logic.
- `wp-content/plugins/tcmha-core/`: content types, taxonomy, crisis bar, submission/approval workflows, Community Connector sync, navigator rules.
- Pushes to `main` deploy to WP Engine **Dev** only. Staging and Production deploys are manual and done by Joseph.

## Non-negotiables
- Accessibility target: WCAG 2.2 AA (contract floor is 2.1 AA). Every template and component is keyboard, screen-reader, and 200% zoom tested. Touch targets at least 24x24 CSS px (aim for 44).
- Plain language: navigation, crisis steps, and forms at about a third-grade reading level.
- Never collect identifiable health information. No tracking on crisis actions beyond anonymous click counts.
- Crisis info must stay accurate: 911, 988 (call or text), Colorado Crisis Services 844-493-8255 / text TALK to 38255. The retired 1-800-273-8255 number must never appear.
- Minimize third-party plugins. Any new plugin, paid license, or external service needs ChangeLine approval and goes in `docs/components.md`.
- Prefix everything `tcmha_` / `TCMHA_`. Escape output, sanitize input, use nonces and capability checks.
