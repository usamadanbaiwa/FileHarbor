# Initial API contract

PHP and Node.js implement this HTTP contract. All management successes use `{ "success": true, "data": ... }`. Failures use `{ "success": false, "error": { "code": "...", "message": "..." } }`. See [compatibility notes](compatibility.md) for runtime and hosting boundaries, including malformed multipart handling.

## Public file access

`GET /{account_id}/{source}` serves file bytes without authentication. For example, `/ACCOUNT_ID/assets/photo.png` reads `STORAGE_PATH/ACCOUNT_ID/assets/photo.png`. The account ID is the generated 22-character ID; `source` may contain nested folders. This route is enabled by default and returns 404 when `SERVE_FILES=false`.

Only JPEG, PNG, WebP, PDF, and MP4 content is served. Paths use the same safety rules as the management API. Directories, symlinks, and unsupported content are not served. This is public access: key rotation, account disabling, and account removal do not revoke file URLs. Delete the file or disable serving to remove access through this route.

- `GET`: 200 with file bytes; `HEAD`: the same headers without a body.
- `Range: bytes=0-1023`, `bytes=1024-`, or `bytes=-1024`: 206 for a satisfiable single range; 416 for an unsatisfiable range. Multiple ranges are not supported and may be ignored or rejected.
- `ETag` and `Last-Modified` allow conditional requests; a matching condition returns 304.
- `Content-Type` is detected from file content. Responses include `X-Content-Type-Options: nosniff`, a sandbox content policy, and `Cache-Control: public, max-age=0, must-revalidate`.

File URLs use the server origin, not the management API prefix. Direct Apache/Nginx delivery is optional and uses the URL mapping configured by the operator.

## File API

Base: `/fileharbor/{account_id}/api`. Every request requires `Authorization: Bearer <API_KEY>`. The key must belong to the account and the account must be enabled. Keep API keys on the application backend.

| Method / path | Scope | Input | Success |
| --- | --- | --- | --- |
| POST `/upload` | `files:upload` | multipart `file`, optional `folder`, optional `file_name` | 201: `file_name`, `source`, `mime_type`, `size` |
| GET `/files` | `files:list` | query `folder`, `page`, `limit` | 200: `items`, `pagination` |
| DELETE `/files` | `files:delete` | JSON `source` | 200: `deleted: true` |
| POST `/directories` | `directories:create` | JSON `source` | 201: `created: true` |
| GET `/directories` | `directories:list` | query `folder`, `page`, `limit` | 200: directory-only `items`, `pagination` |
| DELETE `/directories` | `directories:delete` | JSON `source`, optional `recursive` (default `false`) | 200: `deleted: true` |

Paths are relative to the account root. Upload folders must already exist. Directory creation creates missing parents and is idempotent for existing directories. Deletion never removes the account root; directory deletion is empty-only unless `recursive: true` is supplied. Upload collisions give 409; there is no overwrite or rename operation.

## Delete a directory

Recursive deletion is currently implemented in Node.js only. PHP still supports empty directories only.

Send a DELETE request to `/fileharbor/ACCOUNT_ID/api/directories` with the `directories:delete` permission.

By default, `recursive` is `false`: only an empty directory can be deleted. A non-empty directory returns HTTP 409.

To delete a directory and everything inside it, including nested directories, send:

```json
{
  "source": "assets",
  "recursive": true
}
```

`source` is relative to the account's storage directory. The account root cannot be deleted. Recursive deletion permanently removes the contents; it does not move them to a recycle bin.

```sh
curl -X DELETE https://files.example.com/fileharbor/ACCOUNT_ID/api/directories \
  -H 'Authorization: Bearer API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"source":"assets","recursive":true}'
```

Omit `recursive` or send `false` to keep the empty-directory-only behavior.

Listing defaults: empty folder (root), page 1, limit 50. Limit range 1–100; page range 1–1000000. Entries are sorted by case-sensitive filename code-unit order. Listings are non-recursive and omit symlinks. Each item contains `file_name`, `source`, `type` (`file` or `directory`), and filesystem `size` in bytes. Directory size is not recursive usage. Pagination is `{page, limit, total}`. Pages beyond the end return empty items; mutations can shift page boundaries.

Uploads accept one `file` part. `file_name` overrides the multipart filename; otherwise that filename is used. Fields may arrive after the file. Allowed types default to JPEG (`jpg`, `jpeg`), PNG (`png`), WebP (`webp`), and PDF (`pdf`), and MP4 (`mp4`). Content signatures and extensions must agree. The configured allowlist can narrow these types. Client MIME headers are not trusted. Default maximum size is 25 MiB; multipart requests are capped at file size plus 16 KiB overhead. JSON bodies are capped at 16 KiB.

Node.js additionally bounds active uploads with `MAX_CONCURRENT_UPLOADS` (default four). Requests exceeding that bound receive 429 without being queued. It rechecks the current key and upload scope before submitting a file commit. See `compatibility.md` for PHP buffering and hosting limits.

Path segments start with an ASCII letter/digit and contain only ASCII letters/digits, dot, underscore and hyphen. Maximum segment length: 120; maximum relative path length: 1024. Reserved Windows names, trailing dots, dot segments, absolute paths, empty segments and backslashes are rejected.

## Panel API

Browser endpoints under `/panel-api`, with the same JSON envelopes. The simple panel is at `/panel/`. These endpoints use administrator sessions, not API keys.

| Method / path | Access | Input / result |
| --- | --- | --- |
| GET `/status` | Public | 200: `setup_required` |
| POST `/setup` | One-time setup secret | `setup_token`, `username`, `password`; 201: `created` |
| POST `/login` | Public; exact Origin required | `username`, `password`; 200: `csrf_token` and session cookie |
| GET `/session` | Admin | 200: `csrf_token` |
| POST `/logout` | Admin + CSRF | 200: `logged_out` |
| GET `/accounts` | Admin | 200: `accounts`, available `permissions` |
| POST `/accounts` | Admin + CSRF | `name`, `permissions`; 201: `id`, `name`, `permissions`, `enabled`, `api_key`, `public_prefix` |
| PATCH `/accounts/{id}` | Admin + CSRF | `name`, `permissions`, boolean `enabled`; 200: `updated` |
| POST `/accounts/{id}/rotate` | Admin + CSRF | 200: one-time `api_key` |
| DELETE `/accounts/{id}` | Admin + CSRF | 200: `removed`, `files_retained` |
| POST `/credentials` | Admin + CSRF | `current_password`, new `username`, new `password`; 200: `updated`, `login_required` |

Authenticated mutating requests require `X-CSRF-Token` from login/session and an `Origin` equal to configured `FILEHARBOR_URL`. Cookies use HttpOnly, SameSite=Strict and Secure in production. Sessions expire after one hour; changing credentials invalidates all sessions. Rotating keys immediately invalidates the previous key. Keys are returned only on creation/rotation.

Account IDs are generated and map to isolated public/storage prefixes; administrator-selected custom roots are not implemented. Account names must be nonempty and at most 80 characters. Scopes are explicit; empty scopes grant no file operations. Username: 3–80 ASCII letters/digits or `. _ @ -`; password: 12–256 characters.

## Health and errors

Public `GET /api/health` returns 200 with `{ "status": "ok" }` inside `data`. This is liveness, not a storage integrity check.

| HTTP | Codes |
| --- | --- |
| 400 | `INVALID_REQUEST`, `INVALID_PATH`, `INVALID_FILE` |
| 401 | `UNAUTHORIZED` |
| 403 | `FORBIDDEN` |
| 404 | `FILE_NOT_FOUND`, `ACCOUNT_NOT_FOUND`, `NOT_FOUND` |
| 409 | `FILE_EXISTS`, `CONFLICT`, `SETUP_COMPLETE` |
| 413 | `FILE_TOO_LARGE` |
| 415 | `UNSUPPORTED_FILE_TYPE` |
| 429 | `RATE_LIMIT_EXCEEDED` |
| 500 | `STORAGE_ERROR` |

Cross-account, disabled and invalid keys give 401. Missing scope gives 403. Missing paths give 404. Non-empty directory deletion gives 409. Error messages must not disclose secrets or absolute paths. Clients should branch on codes, not exact human-readable messages.
