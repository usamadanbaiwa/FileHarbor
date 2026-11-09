# Architecture

FileHarbor separates application metadata from physical file management.

The main application chooses filenames and relative folders, sends authenticated HTTP requests, and stores returned metadata alongside its own user/business records. FileHarbor manages physical files and access credentials. Built-in public serving streams files at `/{account_id}/{source}`. Set `SERVE_FILES=false` to use a separately configured web-server static route instead.

## Independent implementations

The `php/` and `node/` folders each contain a standalone application, installation guide, API reference, examples, and tests.

The root `spec/` directory defines their shared HTTP contract.

## Request flow

1. Read validated installation configuration.
2. Authenticate the API account and require its scope.
3. Validate the requested account-relative path.
4. Enforce file/request limits and inspect content.
5. Commit a complete staged file without overwrite.
6. Return a JSON envelope with the relative source and file information.
7. Clean temporary state after handled failures.

Uploads default to 25 MiB and permit JPEG, PNG, WebP, PDF, and MP4. Exact limits and hosting controls are explained inside each implementation.

## State and storage

Accounts map to generated storage prefixes. SQLite stores credentials and essential authentication/configuration state, not per-file metadata. Listings inspect the filesystem. Account removal preserves files, and directory deletion is empty-only by default. The Node.js API also accepts `recursive: true` to delete a directory and all its contents; this option is not implemented in PHP.

Keep private state, application source, and public storage separate. Files stay ordinary files so relative paths can survive a server move. Automated bulk migration is not an API feature.

Runtime-specific buffering, session persistence, and server configuration are documented in each installation's guide and root compatibility notes.
