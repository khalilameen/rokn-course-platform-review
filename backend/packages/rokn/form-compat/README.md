# Rokn Form Compatibility Layer

This application-owned package implements only the legacy `Form` facade calls
that still exist in Rokn's admin Blade templates: `open`, `model`, `close`,
`text`, `email`, `password`, `number`, `hidden`, `date`, `textarea`, `select`,
`checkbox`, and `radio`.

The public method names intentionally match the formerly used
`laravelcollective/html` API so existing templates can be migrated without a
flag day. The implementation is original Rokn code and does not include source
from Laravel Collective. Laravel Collective and Laravel are trademarks of
their respective owners. This package is released under the MIT License.
