# Security reporting

Do not post an exploitable vulnerability, live API key, password, or private file in a public issue. Use the repository's private vulnerability-reporting feature. Otherwise ask a maintainer for a private contact without disclosing the exploit.

Include affected versions, prerequisites, expected/actual behavior, and a minimal reproduction using test data. Explain whether the issue affects application logic, runtime dependencies, web-server configuration, or hosting behavior.

Security controls do not replace HTTPS, patched runtimes, private filesystem permissions, backups, and correct static-serving rules. File signatures are not malware scanning.

See [deployment security](docs/security.md) for hosting requirements.
