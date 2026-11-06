# PHP with Nginx

Replace the paths, domain, PHP-FPM socket, and TLS certificate settings for your server. This configuration serves the panel at `/panel/`.

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

    # Only static assets belong here; never point at private state or source.
    location ^~ /content/ {
        alias /srv/fileharbor/storage/;
        autoindex off;
        add_header X-Content-Type-Options nosniff always;
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

The static `^~ /content/` location must never fall through to a PHP handler. Review configured MIME mappings, provider restrictions, and filesystem ownership. PHP's `upload_max_filesize` and `post_max_size` must also be set; Nginx's body limit alone does not configure PHP.

Set `FILEHARBOR_URL=https://files.example.com` in the private PHP configuration and verify setup, login, Authorization forwarding, uploads, public serving, and static script non-execution before use.
