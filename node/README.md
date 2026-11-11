# FileHarbor

**Self-hosted file management over HTTP. Your server. Your files. Your API.**

Upload images, PDF documents, and MP4 videos to storage you control. Manage accounts and scoped API keys in a small administrator panel, keep file metadata in your own application, and open public files directly by URL.

## Install from source or a release

Download the application archive from the project's Releases page and extract it, or extract the `node/` folder from a source checkout.

Requirements:

- A current patched Node.js LTS release. Required APIs are available from 22.13; use a maintained, patched LTS version for deployment.
- A persistent server process and HTTPS reverse proxy.
- Separate private-state and public-storage directories on the same local filesystem.
- A filesystem that supports hard links.

From this folder:

```sh
npm ci
```

Copy `.env.example` to `.env`. Generate your own setup token:

```sh
node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
```

Paste that value into `FILEHARBOR_SETUP_TOKEN`. Never commit a configured `.env` file.

```sh
npm start
```

For the example local configuration, open **http://127.0.0.1:7241/panel/**. Enter the setup token, create administrator credentials, and sign in.

## Create your first API account

1. Create an account with a descriptive name, such as `blog`.
2. Assign only the permissions that application needs.
3. Copy its generated account ID and API key. The key is displayed once.
4. Put the credentials in your application's private backend configuration.
5. Create a storage folder and upload a file.

You can change account permissions, disable an account, rotate its key, or remove it through the panel. Removing an account preserves its files. Changing administrator credentials signs out existing sessions.

## Upload a file

The account needs `directories:create` and `files:upload`:

```sh
curl -X POST http://127.0.0.1:7241/fileharbor/ACCOUNT_ID/api/directories \
  -H 'Authorization: Bearer API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"source":"assets"}'

curl http://127.0.0.1:7241/fileharbor/ACCOUNT_ID/api/upload \
  -H 'Authorization: Bearer API_KEY' \
  -F 'folder=assets' \
  -F 'file_name=clip.mp4' \
  -F 'file=@./clip.mp4'
```

The response contains `file_name`, account-relative `source`, detected `mime_type`, and `size` in bytes inside `data`. Files are stored at `STORAGE_PATH/<account_id>/<source>`. Your application saves its own metadata.

See [the complete API](docs/api.md).

## Configuration

| Setting | Purpose | Default |
| --- | --- | --- |
| `FILEHARBOR_ENV` | Use `production` for deployment | `production` |
| `FILEHARBOR_URL` | Exact panel origin; HTTPS in production | Local example uses port 7241 |
| `FILEHARBOR_SETUP_TOKEN` | Random first-install secret, at least 32 characters | None |
| `HOST`, `PORT` | Listener address | `127.0.0.1`, `7241` |
| `PUBLIC_CACHE_MAX_AGE` | Public file cache lifetime in seconds (0–31536000) | `86400` (one day) |
| `SERVE_FILES` | Built-in public file serving; set `false` for external serving | `true` |
| `STORAGE_PATH` | Physical file storage | `./storage` |
| `FILEHARBOR_STATE_PATH` | Private SQLite and staging directory | `./var` |
| `MAX_FILE_SIZE_MB` | Maximum file size in MiB | **25** |
| `ALLOWED_MIME_TYPES` | Comma-separated allowlist | JPEG, PNG, WebP, PDF, MP4 |
| `MAX_CONCURRENT_UPLOADS` | Active uploads per process | 4 |
| `API_RATE_LIMIT` | Request limit per observed source address | 100 |
| `API_RATE_WINDOW_SECONDS` | Rate-limit window | 60 |

The committed example uses local HTTP settings for testing. Change them for deployment. Restrict the allowlist if your application only needs particular types. MP4 support validates the detected container type and extension; it does not inspect codecs, transcode, or generate thumbnails.

## Production deployment

1. Set `FILEHARBOR_ENV=production` and your exact HTTPS `FILEHARBOR_URL`.
2. Keep the listener on loopback behind a TLS reverse proxy. Use a process manager or service unit to restart the process when needed.
3. Run one application process. Sessions, rate limits, and work limits are held in that process and reset on restart.
4. Put storage and private state outside the source and panel directories, on the same local filesystem.
5. Keep built-in file serving enabled, or configure optional direct web-server delivery.
6. Back up storage and the private SQLite registry, protect the backups, and test restoration.

## Serve public files

Files are public by default. After uploading `assets/photo.png`, open:

```text
https://files.example.com/ACCOUNT_ID/assets/photo.png
```

FileHarbor streams `STORAGE_PATH/ACCOUNT_ID/assets/photo.png`. No separate storage mapping is needed. The account ID is part of the URL, not a password. Viewing files requires no API key, and disabling or removing an API account does not make its existing files private.

Public responses support `GET`, `HEAD`, single byte ranges for video seeking, and conditional requests using ETag or Last-Modified. By default, browsers may reuse public files for one day without contacting the server. Set `PUBLIC_CACHE_MAX_AGE=0` to revalidate on every reuse, or choose another lifetime in seconds (up to 31536000). Once stale, cached files must be revalidated. Use a new filename when replacing content; deleted or replaced files can remain visible from cache until the lifetime expires. Directory listings are not exposed through public URLs; use the authenticated listing API. Public file requests count toward this process's request rate limit.

To serve files directly through Apache or Nginx, set `SERVE_FILES=false` and configure a static mapping to `STORAGE_PATH`. For example, mapping `/content/` produces `https://files.example.com/content/ACCOUNT_ID/assets/photo.png`. Update your application's public base URL to match. Disabling built-in serving alone does not configure a web-server mapping.

Disable script execution and directory indexing in that mapping, set correct content types, and send `X-Content-Type-Options: nosniff`. Keep source, credentials, SQLite, and staging files private.

The API's default limit is 25 MiB. Set your proxy body limit slightly higher, such as 26 MiB, to allow multipart overhead. Clients behind a proxy share its observed source address unless you configure a trusted deployment layer to handle limits. Do not trust arbitrary forwarded headers.

## Security and maintenance

API keys are random and stored as verification hashes. Administrator passwords use scrypt. Session cookies are HttpOnly, SameSite=Strict, and Secure in production. Mutating panel requests require a CSRF token and matching origin.

Uploads use private staging, bounded streams, and an atomic no-overwrite commit. Access is checked again before committing. Interrupted/rejected requests release their staging data and upload slot. Directory deletion is empty-only by default. Send `{"source":"assets","recursive":true}` to delete the folder and all its contents; see [directory deletion](docs/api.md#delete-a-directory).

Only trusted processes should write storage directories. Signature inspection is not malware scanning. Very large flat directories cost memory to list; organize files into folders. A killed process can leave staging directories: stop file operations before inspecting and removing stale `var/uploads/upload-*` entries.

## Tests and code style

```sh
npm test
npm run lint
npm run format:check
```

Tests create their own local servers and disposable `tests/.state/` directories. Tests include the exact 25 MiB boundary, MP4 type checks, account isolation, traversal, sessions, revocation, interruption, and concurrency.

Use `npm run format` to format your changes.

See [the source map](src/README.md) and [test guide](tests/README.md).

For code style and pull requests, see [CONTRIBUTING.md](CONTRIBUTING.md).
