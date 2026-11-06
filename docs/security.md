# Security

- Require HTTPS and authenticate all management operations using scoped account keys.
- Never treat the account ID as a credential.
- Reject traversal, absolute paths, unsafe names, and symlink escapes; enforce account roots on every operation.
- Deny upload collisions by default and make writes and cleanup safe under concurrency.
- Apply configurable request, file-size, and content-type limits. Client MIME declarations and filename extensions are insufficient validation.
- Disable execution in public upload directories. Define safe handling of browser-rendered active content before allowing it.
- Keep SQLite, temporary files, credentials, and configuration outside public storage.
- Store safe credential verification material, suppress secrets in logs, and reveal generated keys only once.
- Protect setup and panel sessions, state-changing forms, and login attempts.
- Avoid revealing paths, stack traces, or credentials in public errors.
- Treat public static URLs as public; API revocation cannot revoke static access.
- Back up files and SQLite configuration together, and document restore procedures.

File type checks do not scan for malware. Keep the server patched, restrict filesystem access, and maintain backups.
