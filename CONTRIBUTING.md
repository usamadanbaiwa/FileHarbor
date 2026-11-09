# Contributing to FileHarbor

Bug fixes, documentation improvements, and tests are welcome. Open an issue before starting a large change.

## Repository layout

| Path | Responsibility |
| --- | --- |
| `php/` | Complete PHP application, tests, examples, and tools |
| `node/` | Complete Node.js application, tests, examples, and tools |
| `spec/` | Common HTTP contract and compatibility notes |
| `docs/` | Project architecture, client integration, and release guidance |
| `.github/workflows/` | Separate verification and archive-building jobs |

Each application must work after its folder is extracted by itself. Do not import source, tests, fixtures, tooling, or configuration from another implementation or from a root helper. Keep implementation-specific documentation inside that implementation. Cross-implementation explanations belong at the repository root.

## Start with one implementation

- [PHP contribution guide](php/CONTRIBUTING.md)
- [Node.js contribution guide](node/CONTRIBUTING.md)

Install only the runtime needed for the part you are changing. Root CI verifies each application separately.

## Propose a change

For a substantial change, open an issue describing the problem, proposed behavior, affected endpoints, and hosting impact. Small documentation corrections do not need an architecture discussion.

Keep pull requests focused. Explain:

1. The user-visible problem and resulting behavior.
2. Any API, configuration, or storage-layout changes.
3. Tests run and their results.
4. Documentation/examples updated.
5. Any remaining limitation or follow-up work.

## Preserve the HTTP contract

The applications expose the same documented endpoints, scopes, response envelopes, and normal request behavior. Public changes must update the root specification, each affected application's local API docs, and its own tests.

Tests are independent and stay with their application. Consistency does not require cross-runtime test launchers or imports. Document real hosting/parser differences in root compatibility notes.

## Write for the next maintainer

- Use explicit braces for control flow, including early returns.
- Put one statement on each line.
- Keep blank lines between functions and between logical steps.
- Choose names that explain the variable or function's purpose.
- Avoid optional chaining, nullish coalescing, ternary expressions, logical-assignment shortcuts, and implicit-return callbacks.
- Use comments for decisions or constraints, not obvious assignments.
- Group code by responsibility. Reusable helpers should have a specific purpose.
- Keep the panel small; do not introduce a frontend build system without a demonstrated need.

## Security expectations

Treat every request, path, filename, header, and uploaded file as untrusted. Keep authorization and account-root checks on every management operation. Preserve no-overwrite commits, safe cleanup, file/request limits, key hashing, and panel CSRF/session controls.

Never commit configured secrets, real keys, uploaded files, SQLite state, installed dependencies, or temporary test data. Use isolated fixtures with test credentials. Add meaningful failure-case tests when changing security boundaries.

Use [SECURITY.md](SECURITY.md) for vulnerability reporting.

## Documentation and examples

Write instructions that a developer can follow after extracting one application folder. Keep commands relative to that folder. Examples should use ordinary HTTP libraries, private backend credentials, and the exact implemented request/response fields.

## Releases

Maintainers review and merge pull requests, then push a version tag. GitHub Actions tests both applications and publishes one release with separate downloads. See [the release guide](docs/releases.md). Contributors do not need to prepare release packages.
