# Sufee admin stylesheet source

`style.scss` is the corresponding source for the compiled stylesheet served as
`public/admin/assets/scss/style.css`.

Upstream provenance:

- Project: Sufee Admin Dashboard 1.0.0
- Repository: https://github.com/puikinsh/sufee-admin-dashboard
- Baseline commit: `dcae40f7d2afea4fc0e8480fa4b3558ef4d2cc38`
- Upstream source path: `assets/scss/style.scss`

The upstream repository includes an MIT license, while the stylesheet's own
header states "GNU General Public License v2 or later". Rokn conservatively
preserves that file-level GPL-2.0-or-later notice, ships the complete GPL v2
text, and also preserves the upstream repository MIT license.

Local modification map:

- RTL layout and the historical Rokn admin rules are retained in `style.scss`.
- The unlicensed JF Flat and Helvetica Neue W23 `@font-face` declarations were
  removed. Text now uses the system stack `Tahoma, Arial, "Segoe UI", sans-serif`.
- Two missing Twitter-corner images were replaced by equivalent solid colors.
- `animate.css` remains a separate MIT-licensed absolute runtime import so it
  resolves correctly from every nested admin route.
- Project-owned refinements remain separate in `custom-global.css` and
  `admin-shell.css`.
- `public/admin/assets/js/main.js` is a readable project adaptation of the
  pinned upstream `assets/js/main.js` for the accessible navigation shell.
- `public/images/admin.jpg` is byte-identical to the pinned upstream asset.

Rebuild from the repository root with `npm run build:admin-vendor-css`.
