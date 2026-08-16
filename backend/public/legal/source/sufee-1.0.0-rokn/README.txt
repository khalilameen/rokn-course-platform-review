Sufee corresponding source for the CSS served by this deployment

The exact preferred SCSS source, modification notes, and license texts are published in this directory.
SOURCE_MANIFEST.json binds this source set to the SHA-256 of /admin/assets/scss/style.css.
Rebuild from the repository root with: npm ci && npm run build:admin-vendor-css
Modification details: removed unlicensed bundled fonts; use the Rokn system font stack; removed two missing decorative image URLs; retained animate.css through a route-safe absolute import.
