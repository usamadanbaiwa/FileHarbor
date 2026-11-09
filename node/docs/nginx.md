# Nginx deployment

Replace domains, filesystem paths, certificates, and service-management details with your installation's values. Configure the application with `FILEHARBOR_ENV=production`, `FILEHARBOR_URL=https://files.example.com`, and a listener on loopback.

```nginx
server {
    listen 443 ssl;
    server_name files.example.com;

    ssl_certificate /etc/ssl/example/fullchain.pem;
    ssl_certificate_key /etc/ssl/example/privkey.pem;

    client_max_body_size 26m;

    location / {
        proxy_pass http://127.0.0.1:7241;
        proxy_set_header Host $host;
        proxy_set_header Authorization $http_authorization;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

The 26 MiB request allowance leaves room around the application's 25 MiB file limit. Verify the actual host configuration and use a service manager to keep the listener running. Sessions and application limits are per process; configure proxy-wide abuse limits where appropriate.

## Optional direct file serving

Built-in serving works with the configuration above. To let Nginx serve storage directly, set `SERVE_FILES=false` and add this location inside the server block:

```nginx
    location ^~ /content/ {
        alias /srv/fileharbor/storage/;
        autoindex off;
        add_header X-Content-Type-Options nosniff always;
    }
```

Public URLs then use `/content/ACCOUNT_ID/assets/photo.png`. Only map the storage directory. Keep directory indexing and script execution disabled, configure correct MIME types, and keep private state and source outside the mapping.
