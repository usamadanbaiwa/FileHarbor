# PHP source map

`App.php` connects the request handlers. `bootstrap.php` loads classes and runs a request. Classes use namespaces that match their folders, so Composer and the included autoloader can find them.

| Folder | What belongs here |
| --- | --- |
| `Config/` | Environment/private-file settings and startup validation |
| `Http/` | Requests, responses, and API errors |
| `Routes/` | File API and administrator panel handlers |
| `Security/` | Credentials, sessions, CSRF, and rate limits |
| `Storage/` | Filesystem operations and native PHP uploads |
| `Repositories/` | SQLite account and authentication-state queries |
| `Support/` | Reusable filesystem-path helpers |

Useful starting points:

- Upload: `Routes/FileRoutes.php` → `Storage/Upload.php` → `Storage/Storage.php`.
- Login: `Routes/PanelRoutes.php` → `Security/Credentials.php` → `Security/Sessions.php`.
- Account queries: `Repositories/Registry.php`.
- Directory configuration: `Config/Config.php` and `Support/Paths.php`.
- Error responses: `Http/Response.php` and `Http/ApiError.php`.

The panel's JavaScript sends requests to the panel endpoints. Credentials are managed on the server.
