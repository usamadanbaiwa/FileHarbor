# Contributing

Keep changes, tests, and examples inside this application folder.

## Checks

```sh
npm ci
npm test
npm run lint
npm run format:check
```

Use `npm run format` to apply the committed layout. Tests must create isolated state under `tests/.state/` and clean it afterward. Add coverage for permissions, paths, failures, interrupted requests, and boundaries when changing related behavior.

## Source layout

`app.js` wires the server. Put settings in `src/config/`, request/response handling in `http/`, endpoint flows in `routes/`, authentication controls in `security/`, filesystem work in `storage/`, SQL in `repositories/`, and reusable path helpers in `utils/`.

Use explicit blocks, four spaces, descriptive function names, and one statement per line. Separate logical steps with blank lines. Do not use optional chaining, nullish coalescing, ternaries, logical-assignment shortcuts, or implicit-return arrow functions. Comments should explain a decision, not repeat code.

Update local API docs and examples when behavior changes. Keep API keys out of logs, fixtures isolated from live data, and production configuration out of source control. Preserve backpressure, bounded work, path containment, and atomic no-overwrite commits.

## Pull requests

Open a pull request describing the problem, your changes, and the checks you ran. Include tests for changed behavior and update affected documentation.

Maintainers review and merge pull requests, then tag and publish releases. Contributors do not need to build release archives.
