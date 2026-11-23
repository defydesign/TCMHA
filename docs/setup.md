# Setup and deployment

## Environments

| Environment | Deploys from | How |
| --- | --- | --- |
| Dev | `main` | Automatic, via GitHub Actions on every merge |
| Staging | manual | Joseph, via WP Engine copy or manual run |
| Production | manual | Joseph, after ChangeLine approval |

## One-time setup for the Dev deploy

1. In WP Engine, add an SSH key for GitHub Actions (User Portal > Profile > SSH Keys). Use a new key just for this repo.
2. In GitHub, go to **Settings > Environments**, create an environment named `dev`, and add:
   - Secret `WPE_SSHG_KEY_PRIVATE`: the private half of that key.
   - Variable `WPE_DEV_ENV`: the WP Engine environment name (Dev is `tcmhanext`, https://tcmhanext.wpenginepowered.com).
3. On the Dev site, install and activate **Hello Elementor** (parent theme), **Elementor**, and **Elementor Pro**.
4. Merge to `main` (or run the workflow by hand from the Actions tab). Then activate the **TCMHA** theme and the **TCMHA Core** plugin in WP admin.

The deploy only adds or updates files from this repo. It never deletes other plugins, themes, or uploads.

## Elementor

- Build with **Atomic (V4) elements only**. The Elementor MCP can only edit V4 layouts.
- Define colors, type, and spacing as Elementor global variables using the same names as `themes/tcmha-child/assets/css/tokens.css`.
- Connect the Elementor MCP on Dev only (Elementor > Elementor MCP). Never paste the setup prompt or config in chat: it contains a live Application Password.
- `.mcp.json` points Claude at the Dev MCP endpoint without credentials. The `Authorization: Basic ...` value lives only in the Claude cloud environment as a **network secret** for host `tcmhanext.wpenginepowered.com`, so it never enters the repo.
- Use a dedicated WordPress user for the MCP (Editor or Administrator on Dev only), so its Application Password can be revoked without affecting anyone's login.

## Pre-launch visitor gate

`tcmha-core/includes/visitor-gate.php` keeps the build private until launch:

- Logged-in editors and administrators see every page.
- A page with a WordPress password (Page settings > Visibility > Password protected) can be opened by anyone who has the link and the password. The stakeholder progress page works this way.
- Everyone else is redirected to the published page with the slug `coming-soon`.

It is on in every environment except one whose WordPress environment type is `production`. To force it on or off, set `define( 'TCMHA_VISITOR_GATE', true );` (or `false`) in `wp-config.php`. **Turn it off at launch.**
