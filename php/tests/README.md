# Tests

Run from the application root:

```sh
php tests/run.php
php tools/lint.php
```

Optional shortcuts are `composer test` and `composer lint`. Unit tests use temporary files and SQLite databases under ignored `var/tests/`. HTTP tests use the cURL extension and `proc_open` to start this application's local server. If these test facilities are unavailable, the runner reports a skip. They are not runtime requirements for the deployed server.

| File | Coverage |
| --- | --- |
| `config.php` | Configuration, document-root names, directory isolation, JSON validation |
| `security.php` | Password hashes, keys, setup locking, database paths |
| `authentication.php` | Persistent rate limits, sessions, CSRF, stale credentials |
| `storage.php` | Paths, collisions, listings, deletion, error responses |
| `http.php` | Renamed public_html deployments, real HTTP requests, public downloads, HEAD, ranges, caching, scopes, setup, uploads, MP4, 25 MiB boundary |
| `HttpServer.php` | Local HTTP test-server and request helpers |
| `support.php` | Assertions and disposable fixtures |

The small MP4 fixture contains a container header for signature checks and is not a playable clip. Tests pad it on disk to check the exact file-size boundary. Upload validation does not decode codecs.

`tools/lint.php` checks PHP syntax and code style.

Format tests cover GIF, MP3, WAV, ZIP, GZIP, custom TIFF mapping, default rejection, MIME aliases, unsafe mappings, and mismatched extensions.
