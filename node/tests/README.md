# Tests

Run from this folder's application root:

```sh
npm test
npm run lint
npm run format:check
```

HTTP tests start a local server and create temporary databases and files under `tests/.state/`.

| Files | Coverage |
| --- | --- |
| `config.test.js`, `paths.test.js` | Configuration, directory isolation, and database paths |
| `files.test.js`, `video.test.js` | Uploads, MP4, exact size boundary, pagination, paths, deletion |
| `content.test.js` | Public downloads, HEAD, ranges, cache validation, unsafe paths, and disabled serving |
| `panel.test.js`, `public.test.js` | Setup, credentials, accounts, sessions, and response contract |
| `security.test.js` | Revocation during uploads, interruptions, resource limits, private paths |
| `formats.test.js` | Configured formats, custom mappings, and download behavior |
| `support/` | HTTP fixture helpers and public-contract assertions |
| `fixtures/` | Test image and MP4 signature fixture |

The image is an ordinary PNG. The small MP4 fixture contains an ISO-BMFF header for content-type tests; it is not a playable clip. Padding it exercises the 25 MiB limit without storing a large video in the repository. Decoder/codec validation is outside the upload API.

Symlink tests may skip if the OS denies creating links. Run with normal local socket/filesystem permissions. No production credentials are used.

Format tests cover GIF, MP3, WAV, ZIP, GZIP, custom TIFF mapping, default rejection, MIME aliases, unsafe mappings, and mismatched extensions.
