# AGENTS.md

## Project overview

This repository is a legacy PHP application for bo.vm, with page-based server-rendered screens instead of a framework. Most business logic lives in raw PHP files under the `public/` and `app/` directories, and the database access pattern is direct PDO usage.

## Architecture and entry points

- `public/index.php` is the public login page and default entry point.
- `public/admin/` contains the admin portal pages.
- `public/user/` contains the customer portal pages.
- `config/bootstrap.php` is the central bootstrap file; it loads configuration, auth helpers, upload helpers, and Composer autoloading.
- `config/db.php` defines the MySQL connection and provides `get_pdo()`.
- `app/auth.php` contains session-based auth helpers such as `current_user()`, `require_login()`, and `require_admin()`.
- `app/helpers.php` holds shared helper functions used across the app (including URL generation and view helpers).

## Important conventions

- Prefer the existing legacy PHP style over introducing a framework or new abstraction layer.
- Keep compatibility with the current direct `PDO` and session-based patterns.
- Reuse existing helper functions and shared bootstrap logic instead of creating parallel patterns.
- Preserve backward compatibility with legacy auth behavior, including the compatibility checks for older plain-text password hashes.
- Follow the repository’s existing naming conventions: `snake_case` functions, direct query usage, and explicit prepared statements.

## Typical development flow

1. Start from the relevant page in `public/` or the supporting helper in `app/`.
2. Check whether the page already loads `config/bootstrap.php` or a sibling helper before adding new logic.
3. Keep database queries prepared and explicit; avoid unguarded raw SQL unless it matches existing patterns.
4. Respect the current split between admin and customer portals; do not bypass role checks.

## Validation

There is no project-level PHPUnit or Node test suite in this repo. For PHP changes, validate with syntax checks such as:

- `php -l path/to/file.php`
- `php -S 127.0.0.1:8000 -t public` for a quick local smoke test when a page needs runtime validation

## Files to inspect first

- `config/bootstrap.php`
- `config/db.php`
- `app/auth.php`
- `public/index.php`
- `app/helpers.php`

## Change guidance for coding agents

- Keep edits small and surgical.
- Match the surrounding style rather than refactoring broadly.
- When a page or helper is unclear, follow the existing call chain from the entry file into the shared helpers before inventing new patterns.
- Do not assume the app uses modern framework conventions; it is an older PHP codebase with direct includes and page-level logic.
