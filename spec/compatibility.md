# Implementation compatibility

The applications expose the same normal management endpoints, account prefixes, permissions, and JSON envelopes. They have independent test suites and do not launch each other or share runtime/test imports.

| Area | PHP | Node.js |
| --- | --- | --- |
| Credentials and configuration | Local SQLite | Local SQLite |
| Sessions and rate counters | SQLite | Process memory |
| Upload parsing | Native multipart parser | Streaming multipart parser |
| Content inspection | Fileinfo | file-type |
| Default file limit | 25 MiB | 25 MiB |
| Default media | JPEG, PNG, WebP, PDF, MP4 | JPEG, PNG, WebP, PDF, MP4 |
| Directory deletion | Empty directories only | Empty by default; `recursive: true` deletes all contents |
| Additional formats | GIF, MP3, WAV, ZIP, GZIP; custom mappings | GIF, MP3, WAV, ZIP, GZIP; custom mappings |
| Commit | Atomic local hard link | Atomic local hard link |
| Public delivery | Built-in streaming; optional web-server mapping | Built-in streaming; optional web-server mapping |

Database files are implementation-specific. Move ordinary files and preserve relative paths when changing storage locations; do not assume SQLite files can be exchanged between implementations.

Clients must send one file and at most one occurrence of each documented multipart field. Native parser normalization and malformed-request errors can differ. Signature detection may classify unusual or ambiguous files differently. WAV and archive MIME aliases are normalized. Custom MIME mappings still require detection support in each runtime. Configured allowlists and extension maps apply to built-in public downloads as well as uploads; external static serving has its own rules.

Hosting-level limits can reject requests before application dispatch. Configure file limits, request overhead, TLS, process/worker limits, and static-serving rules as described in the selected application's README.

Both test suites exercise uploads and the exact 25 MiB boundary. File type checks do not decode video or scan for malware.
