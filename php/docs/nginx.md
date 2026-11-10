# PHP with Nginx

Replace the paths, domain, PHP-FPM socket, and TLS certificate settings for your server. This configuration serves the panel at `/panel/`. If you rename `public/` to `public_html/`, set `FILEHARBOR_DOCUMENT_ROOT=public_html` and change the Nginx `root` directive to `/srv/fileharbor/public_html`.

```nginx
server {
    listen 443 ssl;
    server_name files.example.com;

    # Configure your actual certificate and key here.
    ssl_certificate /etc/ssl/example/fullchain.pem;
    ssl_certificate_key /etc/ssl/example/privkey.pem;

    root /srv/fileharbor/public;
    index index.php;

    # Match your configured maximum file size plus multipart overhead.
    client_max_body_size 26m;

    location /panel/ {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location / {
        rewrite ^ /index.php last;
    }

    location ~ ^/(index|panel/index)\.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\. {
        deny all;
    }
}
```

Set `FILEHARBOR_URL=https://files.example.com` in the private PHP configuration and verify setup, login, Authorization forwarding, uploads, public serving, and static script non-execution before use.

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
