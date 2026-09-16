# Repository Guidelines

## Project Structure & Module Organization

The application lives in `src/`; run application commands there. Backend code is under `src/app/`: HTTP classes in `app/Http/`, models in `app/Models/`, and integrations in `app/Services/`. Routes are in `src/routes/`; migrations, factories, and seeders are in `src/database/`.

The Vue 3 frontend is in `src/resources/js/`, grouped into pages, components, stores, routing, API clients, and shared types. Styles are in `src/resources/css/`. PHP tests are in `src/tests/{Feature,Unit}`; Vitest files are colocated as `*.test.ts`. Requirements and sample data are under `doc/`.

## Build, Test, and Development Commands

From `src/`:

- `composer setup` installs PHP/JS dependencies, creates `.env`, generates the app key, migrates, and builds assets.
- `composer dev` starts the Laravel development processes.
- `npm run dev` starts only the Vite frontend server.
- `npm run build` creates production frontend assets.
- `composer test` clears configuration and runs PHPUnit.
- `npm run check` runs Prettier checks, ESLint, TypeScript checks, and Vitest.
- `vendor/bin/pint --dirty --format agent` formats changed PHP files.

## Coding Style & Naming Conventions

Use UTF-8, LF endings, four-space indentation, and a final newline; YAML uses two spaces. PHP follows Laravel conventions and PSR-4 namespaces (`App\...`). Use `PascalCase` for PHP classes and Vue components, `camelCase` for TypeScript variables/functions, and descriptive service names such as `InventoryImportService`.

Prettier formats frontend code; ESLint enforces frontend rules. Do not reformat unrelated files.

## Testing Guidelines

Use PHPUnit for backend behavior and Vitest with Vue Test Utils for frontend behavior. Name PHP tests `*Test.php` with methods such as `test_import_rejects_invalid_quantity()`. Colocate frontend tests as `ComponentName.test.ts`. Cover changed branches, especially imports, validation, persistence, and API failures. Run focused tests first, then the full checks before submitting.

## Commit & Pull Request Guidelines

Recent history uses concise Japanese subjects such as `UI調整` and `DB実装`. Keep subjects short, imperative, and limited to one logical change; add detail in the body when migrations, configuration, or compatibility are affected.

Pull requests should explain the problem and solution, list verification commands, link related issues when available, and include screenshots for visible UI changes. Call out schema changes, environment variables, and operational steps explicitly.

## Security & Configuration

Never commit secrets or production credentials. Add documented placeholders to `src/.env.example`, keep local values in `.env`, and validate uploaded file content server-side. Preserve user data and unrelated working-tree changes while developing.
