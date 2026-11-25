# PipeDoc IQ — Developer Documentation

```
pipedoc-iq.php            bootstrap
includes/class-pdq-registry.php   schema + entity metadata (single source of truth)
includes/class-pdq-db.php         generic prepared-statement CRUD, soft delete, versions, duplicate rules
includes/class-pdq-rest.php       REST namespace pdq/v1 (cookie auth + X-WP-Nonce, manage_options, rate limit)
includes/class-pdq-calc.php       deterministic engineering maths
includes/class-pdq-parser.php     description recognition + search
includes/class-pdq-gemini.php, -crypto.php   BYO-key AI (server side, encrypted)
includes/class-pdq-files.php      private storage, upload validation, template analysis
includes/class-pdq-generator.php  DOCX/XLSX fill, LibreOffice PDF
includes/class-pdq-import.php     CSV/JSON/XLSX import with mapping, duplicate detection
frontend/                 React + TS + Tailwind source (npm install && npm run build → assets/app)
tests/                    run.php (engines), integration.php (MySQL), generator.php (DOCX)
```

**Add an entity/table:** add it to `PDQ_Registry::entities()` — schema (dbDelta), REST CRUD, import/export and the generic UI appear automatically. Bump `PDQ_Installer::DB_VERSION` and add data steps under `pdq_migrate`.

**Future RBAC / multi-tenancy:** every row has `workspace_id` + `owner_id`. Filters: `pdq_current_workspace_id`, `pdq_current_owner_id`, `pdq_user_can` (single permission gate in `PDQ_REST::can`).

**Tests:** `php tests/run.php`; with MariaDB (db `wp`, user/pass `wp`): `php tests/integration.php`, `php tests/generator.php`. The integration tests use `tests/wp-shim.php`, a tiny WordPress stand-in — they validate SQL/CRUD logic but are not a substitute for testing inside real WordPress.

**Frontend:** `cd frontend && npm install && npm run build` (outputs `assets/app/app.js|css`).
