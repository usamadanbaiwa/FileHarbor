# Response format

Management API success:

```json
{
  "success": true,
  "data": {
    "file_name": "example.png",
    "source": "assets/example.png"
  }
}
```

Management API failure:

```json
{
  "success": false,
  "error": {
    "code": "INVALID_PATH",
    "message": "The requested path is invalid."
  }
}
```

Both upload responses also include `mime_type` and `size`; see `api.md` and `openapi.yaml`. Use `source` as an account-relative forward-slash path; never expose absolute server paths. The application stores its own metadata. A response can include information computed during a request without creating a persistent file record.

Both implementations use the documented HTTP statuses, fields, error codes, and pagination. Production responses must not expose stack traces or secrets. Successful public file responses contain file bytes, not JSON. Public serving is enabled by default and can be disabled with `SERVE_FILES=false`. Infrastructure-generated errors and malformed multipart behavior have limits documented in `compatibility.md`.
