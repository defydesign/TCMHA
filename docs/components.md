# Third-party components and licenses

The contract requires this list, with license terms and ongoing fees, before launch. Add every plugin, theme, library, or external service here when it is introduced.

| Component | Type | License | Fee | Paid by | Approved by ChangeLine |
| --- | --- | --- | --- | --- | --- |
| WordPress | Core | GPL-2.0-or-later | None | n/a | n/a |
| Hello Elementor | Parent theme | GPL-3.0 | None | n/a | Yes (contract) |
| Elementor / Elementor Pro | Page builder | GPL-3.0 / commercial | Pro license via Storiline multi-site license | Storiline | Yes (contract) |
| WP Engine | Hosting | Commercial | Existing plan | ChangeLine | Yes (contract) |
| Google Translate plugin | Translation | TBD | TBD | TBD | Pending |
| Lexend font | Font (self-hosted) | SIL Open Font License 1.1 | None | n/a | Not required (free, open license) |

## Plugins found active on Dev (not part of the build yet)

These came with the Dev environment (Elementor's hosting bundle). None are approved for production. Decide before launch whether to keep, replace, or remove each one.

| Plugin | What it does | Concern |
| --- | --- | --- |
| Cookie Consent (cookiez) | Cookie banner | Shows a banner and floating button on every page. Only needed if the site sets non-essential cookies. |
| Web Accessibility (pojo-accessibility) | Accessibility widget and scanner | Overlay widgets do not make a site accessible and can conflict with assistive tech. The scanner may be useful on Dev only. |
| Activity Log | Admin audit log | Fine on Dev. Check what it stores before production. |
| Email Deliverability (site-mailer) | Sends site email | May be useful for form notifications. Check data handling. |
| Image Optimization | WebP/AVIF conversion | Useful. Check whether it sends images to an external service. |
| Manage | Elementor site management | Check what it connects to. |
