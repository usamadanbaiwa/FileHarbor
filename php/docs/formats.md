# File formats

`ALLOWED_MIME_TYPES` controls both uploads and built-in public downloads. The defaults are JPEG, PNG, WebP, PDF, and MP4. Other registered formats must be explicitly enabled.

## Registered formats

| MIME type | Extensions |
| --- | --- |
| `image/jpeg` | jpg, jpeg |
| `image/png` | png |
| `image/webp` | webp |
| `application/pdf` | pdf |
| `video/mp4` | mp4 |
| `image/gif` | gif |
| `audio/mpeg` | mp3 |
| `audio/wav` | wav |
| `application/zip` | zip |
| `application/gzip` | gz |

Use lowercase MIME types and extensions. WAV aliases `audio/x-wav` and `audio/vnd.wave`, `application/x-gzip`, and `application/x-zip-compressed` are normalized to the types above, including in API responses.

## Custom formats

`MIME_TYPE_EXTENSIONS` is a JSON object mapping a MIME type to an array of extensions without dots. It adds or replaces entries in the format registry. Registering a type does not enable it: also include it in `ALLOWED_MIME_TYPES`.

For example, register TIFF with `{"image/tiff":["tif","tiff"]}` and add `image/tiff` to the allowlist.

A mapping does not add a content detector. The server must recognize the file's actual content as the registered type; unknown or mismatched content returns HTTP 415. File extensions and client-supplied MIME headers are not sufficient. Detector support can vary with installed versions and the files themselves.

Invalid JSON, unknown allowed types without a mapping, empty extension lists, wildcards, unsafe extensions, and invalid names fail configuration validation. Maximum: 64 custom entries and 16 extensions per entry. Extensions must be 1–12 lowercase letters/digits. MIME types must be lowercase image, audio, video, or application types.

## Public downloads

The same detected MIME, extension mapping, and allowlist are checked when serving a file. GIF, MP3, WAV, and the original default types can be displayed inline. ZIP, GZIP, and custom types are served with `Content-Disposition: attachment`; archives are not extracted.

Removing a type or changing its extension mapping can make previously uploaded files return 404 from built-in serving. Existing browser caches can retain copies until their cache lifetime expires. An external static web-server mapping follows its own rules and does not enforce this application's allowlist.

HTML, SVG, JavaScript, known executable MIME types, and common script/executable extensions are rejected. Arbitrary text files and generic `application/octet-stream` are not supported. Content detection is not malware scanning or full format validation.

## Configuration example

In `config/config.php`:

```php
'ALLOWED_MIME_TYPES' => 'image/jpeg,image/png,image/webp,application/pdf,video/mp4,image/gif,audio/mpeg,audio/wav,application/zip,application/gzip,image/tiff',
'MIME_TYPE_EXTENSIONS' => '{"image/tiff":["tif","tiff"]}',
```

Environment variables override the configuration file. Content detection uses the host's Fileinfo extension.
