# FileHarbor

### Your server. Your files. Your API.

**A self-hosted file management API for application assets, images, documents, and videos.**

Keep your application focused on its own work. Run FileHarbor on a separate hosting account or server, upload files over HTTP, and let your web server deliver them.

FileHarbor is for developers who want to use storage they control: a VPS, dedicated server, or compatible shared-hosting plan. It does not require a managed object-storage account, an S3-compatible service, Docker, or an external database server.

## Why FileHarbor?

A blog needs images. An ecommerce application needs product photos. A mobile application needs profile pictures and notification assets. Many projects already have suitable hosting and simply need a manageable place to keep those files.

Managed storage services such as S3 and R2 offer useful capabilities. FileHarbor gives you another option when their integration, extra account management, or billing model does not fit your project. Use your existing hosting capacity and a small REST API. Your host's storage, transfer limits, permissions, and backup arrangements still apply.

## What you get

- Upload files using any HTTP client.
- Create folders, list files with pagination, and delete exact relative paths.
- Delete an entire folder with `{"source":"assets","recursive":true}` in the Node.js API. The default is `false`; see [directory deletion](node/docs/api.md#delete-a-directory).
- Manage API accounts, permissions, and key rotation through a simple administrator panel.
- Keep applications isolated in separate account directories.
- Upload JPEG, PNG, WebP, PDF, and **MP4 video**.
- Enforce a **25 MiB default maximum file size**, with a configurable allowlist and limit.
- Keep files as ordinary filesystem files.
- Serve public assets directly through Apache, Nginx, or your hosting web server.
- Keep file metadata in your application's database.

SQLite stores authentication and essential configuration, not a record for every uploaded file.

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

For shared hosting and production deployment, point the web root at `public/`, enable HTTPS, configure upload limits and static serving, and follow the [full PHP guide](php/README.md).

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

Open `http://127.0.0.1:7241/panel/`, create the administrator, and generate an API account and key. For HTTPS, service management, static file delivery, and backups, follow the [full Node.js guide](node/README.md).

## How a request works

```text
Your application's backend
    → sends a Bearer API key and file over HTTPS
    → FileHarbor checks the account, permission, path, size, and content type
    → FileHarbor stores the file in the account's directory
    → your application saves the returned relative path
    → your web server delivers the public file
```

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

Store the returned source and account ID in your application. Configure the public base URL separately. If your web server maps `/content/` to FileHarbor's storage directory, the URL is:

```text
https://files.example.com/content/ACCOUNT_ID/assets/profile.png
```

Changing servers can then preserve the relative paths while your application changes its configured base URL. An automated migration endpoint is not part of the API.

## Integrate with the HTTP library you already use

There is no required FileHarbor SDK or framework. Use multipart uploads and the Bearer header:

| Application language | Example HTTP clients |
| --- | --- |
| PHP | Guzzle, Symfony HTTP Client, or cURL |
| JavaScript | Fetch or your preferred HTTP client |
| Python | Requests |
| Other languages | Any client that supports HTTP and multipart files |

See [client integration examples](docs/clients.md), or the `examples/` folder inside your chosen installation. Keep API keys on your application backend, not in public browser code.

## Deployment

Only the application's `public/` directory belongs in its web root. Keep credentials, SQLite, temporary uploads, and source code private. Configure static storage separately, disable uploaded-script execution, use HTTPS, and back up both files and authentication state.

File type inspection is not malware scanning or video transcoding. Public files are public: rotating a key does not revoke an existing static URL.

## Contributing

Start with [CONTRIBUTING.md](CONTRIBUTING.md) for code style, project layout, testing, security expectations, and pull requests. Use [SECURITY.md](SECURITY.md) for vulnerability-reporting guidance.

The root `spec/` folder records the common HTTP contract. Each implementation includes its own documentation and tests and can be worked on independently.
