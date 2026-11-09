# Node.js source map

`app.js` connects the server components. `server.js` starts the listener and handles shutdown. The remaining files are grouped by their job:

| Folder | What belongs here |
| --- | --- |
| `config/` | Environment settings and startup validation |
| `http/` | Request validation, JSON responses, and public errors |
| `routes/` | File API and administrator panel endpoints |
| `security/` | Credentials, sessions, permissions, rate limits, and work limits |
| `storage/` | Local filesystem operations and multipart uploads |
| `repositories/` | SQLite account and administrator queries |
| `utils/` | Reusable filesystem-path helpers |

Useful starting points:

- Public files: `routes/content.js` → `storage/local.js`.
- Upload: `routes/files.js` → `storage/upload.js` → `storage/local.js`.
- Login: `routes/panel.js` → `security/credentials.js` → `security/sessions.js`.
- API credentials: `repositories/registry.js`.
- Path validation: `security/paths.js`.
- Data-directory safety: `utils/paths.js`.
- JSON errors: `http/responses.js`.
