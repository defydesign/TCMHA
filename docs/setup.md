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
   - Variable `WPE_DEV_ENV`: the WP Engine environment name (for example `tcmhadev`).
3. On the Dev site, install and activate **Hello Elementor** (parent theme), **Elementor**, and **Elementor Pro**.
4. Merge to `main` (or run the workflow by hand from the Actions tab). Then activate the **TCMHA** theme and the **TCMHA Core** plugin in WP admin.

The deploy only adds or updates files from this repo. It never deletes other plugins, themes, or uploads.

## Elementor

- Build with **Atomic (V4) elements only**. The Elementor MCP can only edit V4 layouts.
- Define colors, type, and spacing as Elementor global variables using the same names as `themes/tcmha-child/assets/css/tokens.css`.
- Connect the Elementor MCP on Dev only (Elementor > Elementor MCP). Never paste the setup prompt in chat: it contains a live Application Password.
