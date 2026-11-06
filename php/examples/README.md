# Upload from your application

FileHarbor accepts ordinary multipart HTTP requests. Use the HTTP client already installed in your application. These examples are client code; their libraries are not dependencies of the storage server.

Set private application environment variables:

```dotenv
FILEHARBOR_API_BASE=https://files.example.com/fileharbor/ACCOUNT_ID/api
FILEHARBOR_API_KEY=YOUR_PRIVATE_KEY
```

## Guzzle

Install in your application:

```sh
composer require guzzlehttp/guzzle
```

Copy `upload-guzzle.php` into your application and run it from that application's root so its `vendor/autoload.php` is found:

```sh
php upload-guzzle.php ./photo.png
php upload-guzzle.php ./clip.mp4
```

The example streams an open file resource into a multipart request.

## Symfony HTTP Client

Install in your application:

```sh
composer require symfony/http-client symfony/mime
```

Copy `upload-symfony.php` into your application and run:

```sh
php upload-symfony.php ./photo.png
```

It builds multipart headers and a body iterable with FormDataPart. The client supplies the multipart boundary.

Both examples create an `assets` folder and then upload the file, so the API account needs `directories:create` and `files:upload`. Store returned metadata in your own database. Never log the API key or expose it in public client code.

References: [Guzzle multipart requests](https://docs.guzzlephp.org/en/stable/quickstart.html#sending-form-files), [Symfony HTTP Client](https://symfony.com/doc/current/http_client.html).
