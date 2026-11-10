# FileHarbor

**Self-hosted file management over HTTP. Your server. Your files. Your API.**

Store application images, PDF documents, and MP4 videos on hosting you control. FileHarbor authenticates management requests and operates on physical files. Your application keeps its own metadata; FileHarbor serves public assets by default.

## Install from source or a release

Download the application ZIP from the project's Releases page and extract it, or extract the `php/` folder from a source checkout.

Requirements:

- PHP 8.3 or newer, with PDO SQLite and Fileinfo.
- Separate writable private-state and storage directories on the same local filesystem.
- Filesystem hard-link support for atomic upload commits.
- Apache with the supplied rewrite rules, or a suitable Nginx/FPM configuration.
- HTTPS for production API traffic.

There are no third-party runtime dependencies. You can upload the application through your hosting file manager or FTP. Composer is optional for local tooling; installing a registry package is not required.

## Configure the application

Copy `config/config.example.php` to `config/config.php`.

Generate a random setup secret locally:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Paste it into `FILEHARBOR_SETUP_TOKEN` in your private configuration. If you do not have terminal access on the host, generate the token locally or use a password manager's secure random generator.

The example uses local HTTP settings for testing. For deployment, set:

```php
'FILEHARBOR_ENV' => 'production',
'FILEHARBOR_URL' => 'https://files.example.com',
```

Keep the full application outside the public web root and point the domain or subdomain's document root at the configured public directory (`public/` by default). See the `public_html/` instructions below if your host uses that name. Do not expose the entire extracted folder.

## Try locally

From this folder:

```sh
php -d upload_max_filesize=26M -d post_max_size=27M -S 127.0.0.1:8080 -t public router.php
```

Open **http://127.0.0.1:8080/panel/**. Supply the setup token, create administrator credentials, and sign in. This local listener is for testing; use Apache or Nginx/FPM for deployment.

## Create an API account

Use the panel to create an account, assign permissions, and copy its generated API key and account ID. Keys are shown once. The panel can change permissions, disable/remove accounts, rotate keys, and change administrator credentials.

Account removal revokes access while preserving its files. Administrator credential changes invalidate sessions. Uploaded files are publicly accessible by default.

## Upload a file

Give the account `directories:create` and `files:upload` permissions:

```sh
curl -X POST https://files.example.com/fileharbor/ACCOUNT_ID/api/directories \
  -H 'Authorization: Bearer API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"source":"assets"}'

curl https://files.example.com/fileharbor/ACCOUNT_ID/api/upload \
  -H 'Authorization: Bearer API_KEY' \
  -F 'folder=assets' \
  -F 'file_name=clip.mp4' \
  -F 'file=@./clip.mp4'
```

The response contains `file_name`, `source`, `mime_type`, and `size` inside `data`. Files are physically stored at `STORAGE_PATH/<account_id>/<source>`. Store the account-relative source and account ID in your application and configure its public base URL separately.

See [the API reference](docs/api.md), [Guzzle and Symfony examples](examples/README.md), and [request handling](src/README.md).

## Settings and upload limits

Environment variables override values in `config/config.php`. Relative filesystem paths resolve from the server's working directory; the example uses absolute paths derived from the application directory.

| Setting | Purpose | Default |
| --- | --- | --- |
| `FILEHARBOR_DOCUMENT_ROOT` | Public folder name inside the application directory | `public` |
| `FILEHARBOR_ENV` | Use `production` for deployment | `production` |
| `FILEHARBOR_URL` | Exact HTTPS origin for the panel | Local example uses port 8080 |
| `FILEHARBOR_SETUP_TOKEN` | Random first-install secret | None |
| `SERVE_FILES` | Built-in public file serving; set `false` for external serving | `true` |
| `STORAGE_PATH` | Physical file storage | Application `storage/` |
| `FILEHARBOR_STATE_PATH` | Private SQLite/staging directory | Application `var/` |
| `MAX_FILE_SIZE_MB` | Maximum file size in MiB | **25** |
| `ALLOWED_MIME_TYPES` | Allowed content types | JPEG, PNG, WebP, PDF, MP4 |
| `API_RATE_LIMIT` | Rate limit per observed source address | 100 |
| `API_RATE_WINDOW_SECONDS` | Rate-limit window | 60 |

MP4 support checks the detected container type and extension; it does not decode video, inspect codecs, or transcode it.

PHP parses multipart requests before application code. Configure `file_uploads`, `upload_tmp_dir`, `upload_max_filesize`, `post_max_size`, request timeouts, and the web-server body limit. For the default 25 MiB application limit, use at least:

```ini
upload_max_filesize = 26M
post_max_size = 27M
```

Your host may enforce lower limits. An error generated by the proxy or PHP before application dispatch may use its own response rather than FileHarbor JSON. PHP normalizes multipart field names; clients should send exactly one `file` and at most one `folder` and `file_name`.

## Shared hosting and production deployment

1. Upload the application, including hidden `.htaccess` files.
2. Point the domain's document root at `public/`, or use the `public_html/` layout below.
3. Enable HTTPS and set the exact public origin in private configuration.
4. Ensure the process can write its private state and storage directories.
5. Configure PHP and web-server upload limits.
6. Keep built-in file serving enabled, or configure optional direct web-server delivery.
7. Set up backups of storage and the private SQLite registry and test restoration.

The panel stays small: `panel/index.php`, `style.css`, and `script.js` inside the configured public folder. Routine administration needs no SSH, background worker, Docker, or external database server.

The supplied Apache rules preserve Authorization headers and route requests to the front controller. The provider must allow the required rewrite and Options directives. If the provider fixes the document root, verify that source and private state can be kept outside it. Never solve a hosting restriction by exposing credentials or source files.

For Nginx, see [the deployment example](docs/nginx.md).

## Hosts that require public_html

In `config/config.php`, set:

```php
'FILEHARBOR_DOCUMENT_ROOT' => 'public_html',
```

Rename the supplied `public/` folder to `public_html/`, keeping its contents and hidden `.htaccess` file. Keep the other application folders next to it:

```text
hosting-account/
├── config/
│   └── config.php
├── src/
├── storage/
├── var/
└── public_html/       ← domain document root
    ├── .htaccess
    ├── index.php
    └── panel/
```

If your host already has a `public_html/` directory, place the contents of `public/` inside it instead of nesting another public folder. Keep `config/`, `src/`, storage, and private state in its parent directory. Preserve any existing site files when choosing where to install.

The setting selects the folder FileHarbor uses; it does not rename folders or change the hosting provider's document-root setting. Do both steps before using the panel. Use a single folder name, such as `public`, `public_html`, or `htdocs`; paths and application-internal folder names are rejected.

For local testing with the renamed folder:

```sh
php -d upload_max_filesize=26M -d post_max_size=27M -S 127.0.0.1:8080 -t public_html router.php
```

The default remains `public`. No source edits are needed when changing the folder name. The same setting is available as an environment variable and takes precedence over the configuration file.

## Serve public files

Files are public by default. After uploading `assets/photo.png`, open:

```text
https://files.example.com/ACCOUNT_ID/assets/photo.png
```

FileHarbor streams `STORAGE_PATH/ACCOUNT_ID/assets/photo.png`. No separate storage mapping is needed. The account ID is part of the URL, not a password. Viewing files requires no API key, and disabling or removing an API account does not make its existing files private.

Public responses support `GET`, `HEAD`, single byte ranges for video seeking, and conditional requests using ETag or Last-Modified. Caches must revalidate before reuse. Directory listings are not exposed through public URLs; use the authenticated listing API. Each download uses a PHP worker while streaming; configure hosting-level request limits or use direct web-server serving for heavier download traffic.

To serve files directly through Apache or Nginx, set `SERVE_FILES=false` and configure a static mapping to `STORAGE_PATH`. For example, mapping `/content/` produces `https://files.example.com/content/ACCOUNT_ID/assets/photo.png`. Update your application's public base URL to match. Disabling built-in serving alone does not configure a web-server mapping.

Disable script execution and directory indexing in that mapping, set correct content types, and send `X-Content-Type-Options: nosniff`. Keep source, credentials, SQLite, and staging files private.

## Security and maintenance

API keys are stored as verification hashes. Passwords are prehashed before `password_hash(PASSWORD_DEFAULT)` so long inputs are not truncated by bcrypt. SQLite holds account configuration, expiring sessions, and rate-limit counters, not file records.

Mutating panel requests require a session, CSRF token, and matching origin. Paths stay inside the authenticated account root. Uploads use private staging and an atomic no-overwrite commit. Directory deletion supports empty directories only. The `recursive` option is not implemented.

Signature checks do not provide malware scanning or full document/video validation. Restrict storage writes to trusted server processes. Back up files and private state together. A killed request can leave staging files: stop file operations before inspecting and removing stale `var/uploads/upload-*` directories.

## Tests and code style

From this folder:

```sh
php tests/run.php
php tools/lint.php
```

The suite contains native unit and HTTP tests. HTTP tests require the cURL extension, `proc_open`, and local socket access; they report a skip if those test prerequisites are unavailable. The running application does not require these test facilities.

Optional Composer shortcuts are `composer test` and `composer lint`.

See [the test guide](tests/README.md) and [source map](src/README.md).

For code style and pull requests, see [CONTRIBUTING.md](CONTRIBUTING.md).
