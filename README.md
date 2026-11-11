![FileHarbor — Your server. Your files. Your API.](docs/assets/fileharbor-banner.svg)

<p align="center">
  <a href="https://github.com/usamadanbaiwa/FileHarbor/actions/workflows/tests.yml"><img src="https://github.com/usamadanbaiwa/FileHarbor/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="php/README.md"><img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&amp;logo=php&amp;logoColor=white" alt="PHP 8.3 or newer"></a>
  <a href="node/README.md"><img src="https://img.shields.io/badge/Node.js-22.13%2B-43853D?style=flat-square&amp;logo=nodedotjs&amp;logoColor=white" alt="Node.js 22.13 or newer"></a>
  <a href="CONTRIBUTING.md"><img src="https://img.shields.io/badge/Contributions-welcome-167D68?style=flat-square" alt="Contributions welcome"></a>
</p>

<p align="center">
  <strong>A self-hosted file management API for images, documents, and videos.</strong><br>
  Use your VPS or compatible shared hosting. Upload from any backend. Open uploaded files directly by URL.
</p>

<p align="center">
  <a href="php/README.md"><strong>Install PHP</strong></a> ·
  <a href="node/README.md"><strong>Install Node.js</strong></a> ·
  <a href="#upload-your-first-file">Upload example</a> ·
  <a href="https://github.com/usamadanbaiwa/FileHarbor/releases">Releases</a> ·
  <a href="CONTRIBUTING.md">Contribute</a>
</p>

---

## Why FileHarbor?

A blog needs images. An ecommerce application needs product photos. A mobile application needs profile pictures and notification assets. Many projects already have suitable hosting and simply need a manageable place to keep those files.

Managed storage services such as S3 and R2 offer useful capabilities. FileHarbor gives you another option when their integration, extra account management, or billing model does not fit your project. Use your existing hosting capacity and a small REST API. Your host's storage, transfer limits, permissions, and backup arrangements still apply.

## What you get

| | |
| --- | --- |
| **Upload from any backend** | Multipart HTTP uploads with Bearer API keys. No SDK required. |
| **A small admin panel** | Create accounts, assign permissions, and rotate or revoke keys. |
| **Separate application accounts** | Each account has its own storage directory and scoped access. |
| **Images, documents, and video** | JPEG, PNG, WebP, PDF, and MP4 by default. Enable GIF, audio, archives, or custom formats. **25 MiB** default limit. |
| **Ordinary files** | Create folders, list with pagination, and delete by relative path. |
| **Your own metadata** | Your application stores file records. SQLite holds authentication state. |
| **Public file URLs** | Built-in streaming by default. Optional direct Apache/Nginx delivery. |

No Docker, external database server, or object-storage account required.

## Choose your installation

Each implementation is a complete, separate application. Its source, tests, examples, configuration, and detailed documentation are inside its folder.

| Installation | Suitable hosting | Complete guide |
| --- | --- | --- |
| **PHP** | Compatible shared hosting, Apache/FPM, Nginx/FPM, VPS | [PHP installation and documentation](php/README.md) |
| **Node.js** | VPS, dedicated servers, or hosting that supports a persistent process | [Node.js installation and documentation](node/README.md) |

Download the matching archive from this repository's **Releases** page, or download the source repository and extract only the folder you need. You do not need to install both implementations.

## Quick start: PHP

PHP 8.3+, PDO SQLite, and Fileinfo are required. The application has no third-party runtime dependencies.

Inside the extracted PHP folder:

1. Copy `config/config.example.php` to `config/config.php`.
2. Generate a random setup token and place it in that private configuration.
3. For local testing, run:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
php -d upload_max_filesize=26M -d post_max_size=27M -S 127.0.0.1:8080 -t public router.php
```

Open `http://127.0.0.1:8080/panel/`, create the administrator, and generate an API account and key.

If your host requires `public_html/`, rename the PHP public folder and set `FILEHARBOR_DOCUMENT_ROOT=public_html`; see [shared-hosting setup](php/README.md#hosts-that-require-public_html).

For shared hosting and production deployment, point the web root at the configured public folder (`public/` by default), enable HTTPS, configure upload limits, and follow the [full PHP guide](php/README.md).

## Quick start: Node.js

Use a current patched LTS release with the required APIs. Inside the extracted application folder:

```sh
npm ci
```

Copy `.env.example` to `.env`, generate a random setup token, and save it as `FILEHARBOR_SETUP_TOKEN`:

```sh
node -e "console.log(require('node:crypto').randomBytes(32).toString('hex'))"
npm start
```

Open `http://127.0.0.1:7241/panel/`, create the administrator, and generate an API account and key. For HTTPS, service management, file serving, and backups, follow the [full Node.js guide](node/README.md).

## How a request works

![Request flow: backend to FileHarbor to storage, then web server to browser](docs/assets/request-flow.svg)

Your backend uploads a file. FileHarbor checks the account, permission, path, size, and content type, then returns its relative path. Your application saves that path. FileHarbor serves the public file directly by URL.

## Upload your first file

Create an account in the panel with `directories:create` and `files:upload` permissions. Replace `ACCOUNT_ID`, `API_KEY`, and the example domain below.

For example, first create an `assets` directory:

```sh
curl -X POST https://files.example.com/fileharbor/ACCOUNT_ID/api/directories \
  -H 'Authorization: Bearer API_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"source":"assets"}'
```

Then upload:

```sh
curl https://files.example.com/fileharbor/ACCOUNT_ID/api/upload \
  -H 'Authorization: Bearer API_KEY' \
  -F 'folder=assets' \
  -F 'file_name=profile.png' \
  -F 'file=@./profile.png'
```

The response has a consistent JSON structure:

```json
{
  "success": true,
  "data": {
    "file_name": "profile.png",
    "source": "assets/profile.png",
    "mime_type": "image/png",
    "size": 20480
  }
}
```

Store the returned source and account ID in your application. The file is publicly accessible at:

```text
https://files.example.com/ACCOUNT_ID/assets/profile.png
```

Public files have a one-day browser cache lifetime by default. Change `PUBLIC_CACHE_MAX_AGE` (seconds), or set it to `0` to require revalidation. Use a new filename when replacing a file; old cached copies can remain visible until their lifetime expires.

Built-in serving is enabled by default (`SERVE_FILES=true`). Set `SERVE_FILES=false` to use an Apache/Nginx static mapping instead; see your installation guide.

When moving servers, copy your files with the same directory structure and change the public base URL in your application.

## Use your usual HTTP client

There is no required FileHarbor SDK or framework. Use multipart uploads and the Bearer header:

| Application language | Example HTTP clients |
| --- | --- |
| PHP | Guzzle, Symfony HTTP Client, or cURL |
| JavaScript | Fetch or your preferred HTTP client |
| Python | Requests |
| Other languages | Any client that supports HTTP and multipart files |

See [client integration examples](docs/clients.md), or the `examples/` folder inside your chosen installation. Keep API keys on your application backend, not in public browser code.

## Deployment

Only the application's public folder belongs in its web root: `public/` by default, or the renamed folder configured for PHP. Keep credentials, SQLite, temporary uploads, and source code private. Use HTTPS and back up both files and authentication state.

File type inspection is not malware scanning or video transcoding. Public files are public: rotating a key does not revoke an existing public URL.

## File formats

Enable additional formats with `ALLOWED_MIME_TYPES`. GIF, MP3, WAV, ZIP, and GZIP have built-in extension mappings. Use `MIME_TYPE_EXTENSIONS` for other detectable binary formats. Uploads and built-in downloads share the same validation rules.

See [PHP format configuration](php/docs/formats.md) or [Node.js format configuration](node/docs/formats.md) for examples and restrictions.

## API reference

See the [PHP API reference](php/docs/api.md) or [Node.js API reference](node/docs/api.md) for all endpoints, permissions, and errors.

Directory deletion is empty-only by default. The Node.js API also supports `{"source":"assets","recursive":true}` to remove a folder and its contents.

## Contributing

Start with [CONTRIBUTING.md](CONTRIBUTING.md) for code style, project layout, testing, security expectations, and pull requests. Use [SECURITY.md](SECURITY.md) for vulnerability-reporting guidance.

The root `spec/` folder records the common HTTP contract. Each implementation includes its own documentation and tests and can be worked on independently.
