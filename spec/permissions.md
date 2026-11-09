# API permissions

| Permission | Intended operation |
| --- | --- |
| `files:upload` | Upload files within the assigned root |
| `files:delete` | Delete an exact relative file path |
| `files:list` | List files and inspect filesystem information |
| `directories:create` | Create a directory within the assigned root |
| `directories:list` | List directories within the assigned root |
| `directories:delete` | Delete a directory; `recursive: true` also removes its contents (currently Node.js only). Never the account root |
| `files:transfer` | Initiate a future bulk transfer |

Permissions are deny-by-default. Every operation also requires account authentication and path containment. A permission never grants access outside the account's root. `files:transfer` is reserved and cannot be assigned in the current implementation. Public file access is independent of API permissions, account revocation, and account removal.
