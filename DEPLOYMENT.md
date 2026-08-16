# Deployment

Two parts: getting the code onto GitHub, then onto a server by hand. The server
walkthrough targets a fresh Ubuntu 24.04 VPS.

Read [Production checklist](#production-checklist) before you expose anything to
the internet — a few defaults that are convenient locally are dangerous in the
open.

---

## Part 1 — Push to GitHub

The repository is already initialised and committed locally, with a
`.gitignore` that keeps `api/.env` and the entire `pki/out/` directory (the CA
private key, the signing key and the PKCS#12 bundle) out of version control.

### 1. Create an empty repository

On GitHub, **New repository**. Do not add a README, licence or `.gitignore` —
the project already has them and an initial commit on the remote would force you
into a merge on your first push.

### 2. Point the local repo at it

```bash
cd "C:/dev/Dev Task"
git remote add origin https://github.com/<your-username>/signdesk.git
git branch -M main
```

### 3. Confirm nothing sensitive is tracked

Worth doing once, before the first push rather than after:

```bash
git ls-files | grep -E "\.env$|\.key$|\.p12$"
```

That must print nothing. If it prints a path, stop and remove it from the index
before pushing — a secret that reaches GitHub has to be treated as compromised
even if you delete it a minute later.

### 4. Push

```bash
git push -u origin main
```

GitHub no longer accepts account passwords over HTTPS. Either use a personal
access token as the password, or authenticate with the `gh` CLI:

```bash
gh auth login
```

### Later changes

```bash
git add -A
git commit -m "Describe the change"
git push
```

---

## Part 2 — Deploy to a server

Three processes run behind one nginx: the Laravel API on PHP-FPM, a queue worker,
and the Python sealing service. nginx also serves the built React SPA.

**Nothing but nginx should be reachable from outside.** The sealing service binds
to localhost only; it has no user authentication because it is not supposed to be
addressable.

### 1. System packages

```bash
sudo apt update
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update

sudo apt install -y \
  nginx postgresql redis-server \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
  python3.12 python3.12-venv git unzip curl

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

Node is only needed to build the SPA. If you would rather not install it on the
server, run `npm run build` locally and copy `web/dist` up instead.

### 2. Database

```bash
sudo -u postgres psql <<'SQL'
CREATE USER signdesk WITH PASSWORD 'use-a-real-password-here';
CREATE DATABASE signdesk OWNER signdesk;
SQL
```

### 3. Clone

```bash
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/<your-username>/signdesk.git
sudo chown -R $USER:www-data /var/www/signdesk
cd /var/www/signdesk
```

### 4. Signing certificate

**Do not deploy the development CA.** `pki/scripts/gen-pki.sh` exists so the
project runs on a laptop; its root is trusted by nobody, so every signature it
produces shows as untrusted in Adobe Reader.

For production you need one of:

- A **document-signing certificate** from a commercial CA (GlobalSign, Certum,
  eMudhra). Export it as PKCS#12 and place it at `pki/out/signer.p12`.
- For legal recognition under India's IT Act section 3A, an **eSign integration
  with a CCA-licensed ESP** (Digio, Leegality, NSDL-Protean) rather than your own
  certificate at all.

Whichever you use, the certificate's **CRL distribution point must be reachable
from the server**. PAdES B-LT and B-LTA embed revocation data, and if the CRL
cannot be fetched the sealing service silently degrades to a weaker level.

```bash
sudo mkdir -p /var/www/signdesk/pki/out
sudo cp /path/to/your/signer.p12 /var/www/signdesk/pki/out/signer.p12
sudo cp /path/to/your/ca-chain.pem /var/www/signdesk/pki/out/ca.pem
sudo chown root:www-data /var/www/signdesk/pki/out/signer.p12
sudo chmod 640 /var/www/signdesk/pki/out/signer.p12
```

### 5. Sealing service

```bash
cd /var/www/signdesk/sign-service
python3.12 -m venv .venv
./.venv/bin/pip install -r requirements.txt
./.venv/bin/python scripts/fetch_fonts.py
```

Create `/etc/systemd/system/signdesk-sign.service`:

```ini
[Unit]
Description=SignDesk PAdES sealing service
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/signdesk/sign-service
Environment="PKI_DIR=/var/www/signdesk/pki/out"
Environment="TSA_URL=http://timestamp.digicert.com"
Environment="SIGN_SERVICE_SECRET=REPLACE_WITH_A_LONG_RANDOM_STRING"
Environment="SIGNER_P12_PASSPHRASE=REPLACE_WITH_YOUR_P12_PASSPHRASE"
ExecStart=/var/www/signdesk/sign-service/.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8001
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`--host 127.0.0.1` is deliberate. Bind it to `0.0.0.0` and anyone who can reach
the port can seal documents with your certificate.

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now signdesk-sign
curl -s http://127.0.0.1:8001/health
```

### 6. API

```bash
cd /var/www/signdesk/api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```ini
APP_NAME=SignDesk
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sign.example.com
SPA_URL=https://sign.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=signdesk
DB_USERNAME=signdesk
DB_PASSWORD=use-a-real-password-here

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=signdesk-documents
AWS_USE_PATH_STYLE_ENDPOINT=false

MAIL_MAILER=smtp
MAIL_HOST=email-smtp.ap-south-1.amazonaws.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@example.com"

SIGN_SERVICE_URL=http://127.0.0.1:8001
SIGN_SERVICE_SECRET=THE_SAME_STRING_AS_THE_SYSTEMD_UNIT
SIGN_PADES_LEVEL=b-lta

# Removes the reviewer demo routes entirely. One of them returns a live
# one-time passcode, so this must be false anywhere reachable.
DEMO_MODE=false
```

Then:

```bash
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 7. Queue worker

Sealing runs here. Without it, documents are signed but never sealed or emailed.

`/etc/systemd/system/signdesk-queue.service`:

```ini
[Unit]
Description=SignDesk queue worker
After=network.target redis-server.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/signdesk/api
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=300 --sleep=3
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now signdesk-queue
```

After any deploy that changes PHP code, restart it — a long-running worker holds
the old code in memory:

```bash
sudo systemctl restart signdesk-queue
```

### 8. Build the SPA

```bash
cd /var/www/signdesk/web
npm ci
npm run build
```

Output lands in `web/dist`.

### 9. nginx

`/etc/nginx/sites-available/signdesk`:

```nginx
server {
    listen 80;
    server_name sign.example.com;

    root /var/www/signdesk/web/dist;
    index index.html;

    client_max_body_size 25M;

    # API first, so it wins over the SPA fallback below.
    location /api {
        try_files $uri /index.php?$query_string;
    }

    location ~ ^/index\.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/signdesk/api/public/index.php;
        fastcgi_param DOCUMENT_ROOT /var/www/signdesk/api/public;
        fastcgi_read_timeout 300;
    }

    # Client-side routing: unknown paths are SPA routes, not 404s.
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

`client_max_body_size` must exceed your largest upload or nginx rejects it before
PHP ever sees it. `fastcgi_read_timeout` covers the sealing round trip.

```bash
sudo ln -s /etc/nginx/sites-available/signdesk /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 10. HTTPS

Not optional. Signing links and one-time passcodes travel over this connection,
and a signature ceremony conducted over plain HTTP is trivially interceptable.

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d sign.example.com
```

### 11. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Postgres, Redis and the sealing service stay on localhost. Nothing else opens.

### 12. First admin

There is no public registration. Create the account from the server:

```bash
cd /var/www/signdesk/api
php artisan tinker --execute="
  \App\Models\User::create([
    'name' => 'Your Name',
    'email' => 'you@example.com',
    'password' => bcrypt('a-strong-password'),
    'email_verified_at' => now(),
  ]);
"
```

---

## Production checklist

Go through this before sharing the URL.

- [ ] `DEMO_MODE=false` — with it on, the API hands out live one-time passcodes
      and anyone who can reach it can complete somebody else's signature
- [ ] `APP_DEBUG=false` and `APP_ENV=production` — debug mode renders stack traces
      with environment variables in them
- [ ] `APP_KEY` freshly generated on the server, not copied from a laptop
- [ ] `SIGN_SERVICE_SECRET` long and random, identical in the systemd unit and `.env`
- [ ] Sealing service bound to `127.0.0.1`, verified with `ss -tlnp | grep 8001`
- [ ] Real document-signing certificate in place; the development CA deleted
- [ ] The certificate's CRL distribution point reachable from the server
- [ ] `pki/out/signer.p12` is `chmod 640`, owned `root:www-data`
- [ ] S3 bucket is private with no public read
- [ ] HTTPS enforced, HTTP redirecting
- [ ] Queue worker running — check with `systemctl status signdesk-queue`
- [ ] Database and object storage both backed up

---

## Verifying the deployment

```bash
curl -s http://127.0.0.1:8001/health              # sealing service
systemctl status signdesk-queue --no-pager        # worker
sudo -u www-data php artisan signdesk:verify-audit
```

Then in a browser: sign in, send a document to an address you control, sign it,
and confirm the sealed copy arrives. Open the attachment in Adobe Acrobat Reader
and check the signature panel — with a real certificate it should validate
without you having to trust anything manually.

---

## Deploying an update

```bash
cd /var/www/signdesk
git pull

cd api
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

cd ../web && npm ci && npm run build

sudo systemctl restart signdesk-queue signdesk-sign php8.3-fpm
```

The queue worker restart matters. Skip it and the old code keeps running against
the new database schema.

---

## A note on scale

This is a single-server layout, which is the right shape for a first deployment.
The pieces that would move first under load are the queue workers (run several,
they are stateless) and the sealing service (also stateless — put two behind a
local load balancer). The database and object storage are the only stateful
parts.
