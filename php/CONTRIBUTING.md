# Contributing

Keep changes, tests, and examples inside this application folder.

## Checks

```sh
php tests/run.php
php tools/lint.php
```

Composer shortcuts are `composer test` and `composer lint`. HTTP tests require the cURL extension, proc_open, and local socket access. Report skipped checks accurately.

Tests create isolated SQLite and storage fixtures under `var/tests/`. Never use live credentials or storage. Add failure and boundary cases when changing authentication, uploads, paths, limits, cleanup, or deletion.

## Source layout

`App.php` wires request handlers. Settings live in `src/Config/`, requests/responses in `Http/`, endpoint handling in `Routes/`, authentication in `Security/`, filesystem operations in `Storage/`, SQL in `Repositories/`, and reusable path helpers in `Support/`.

Follow PSR-12 with four-space indentation. Use braces for all control flow, one statement per line, descriptive methods, and blank lines between logical steps. Avoid nullish/nullsafe operators, ternaries, and arrow-function shortcuts. Nullable type declarations are fine.

Keep comments brief and useful. Update local API docs and client examples with public changes. Preserve credential hashing, scoped authorization, CSRF protection, account roots, no-overwrite commits, and safe temporary-file cleanup.

## Pull requests

Open a pull request describing the problem, your changes, and the checks you ran. Include tests for changed behavior and update affected documentation.

Maintainers review and merge pull requests, then tag and publish releases. Contributors do not need to build release archives.
