# Integrate through HTTP

Your application's language does not have to match the server implementation. FileHarbor is a REST API. Use the HTTP client you already maintain, send a Bearer key, and upload files as multipart/form-data.

- PHP applications can use [Guzzle or Symfony HTTP Client](../php/examples/README.md).
- JavaScript applications can use Fetch with the multipart fields in the [API reference](../node/docs/api.md).
- Python applications can use Requests, as shown below.
- cURL works for shell scripts and troubleshooting.

All examples use an account-specific API base such as `https://files.example.com/fileharbor/ACCOUNT_ID/api`. Configure this and the key privately in your main application.

## Python with Requests

Install Requests in your application environment:

```sh
python -m pip install requests
```

```python
import os
import sys

import requests

base = os.environ["FILEHARBOR_API_BASE"].rstrip("/")
key = os.environ["FILEHARBOR_API_KEY"]
filename = sys.argv[1]
headers = {"Authorization": "Bearer " + key}

created = requests.post(
    base + "/directories",
    headers=headers,
    json={"source": "assets"},
    timeout=30,
)
created.raise_for_status()

with open(filename, "rb") as stream:
    uploaded = requests.post(
        base + "/upload",
        headers=headers,
        data={"folder": "assets"},
        files={"file": (os.path.basename(filename), stream)},
        timeout=60,
    )
    uploaded.raise_for_status()
    print(uploaded.json()["data"])
```

The account needs directory-creation and upload scopes. Do not manually set multipart Content-Type: the library must include the correct boundary. Requests may buffer multipart content on the client; choose a streaming multipart client for larger client-side workloads.

A successful upload returns its relative `source`, filename, detected MIME type, and size. Your application stores those fields and its own user associations. Static public URLs come from your web-server mapping, not from a download proxy inside FileHarbor.

Reference: [Requests multipart uploads](https://requests.readthedocs.io/en/latest/user/quickstart/#post-a-multipart-encoded-file).
