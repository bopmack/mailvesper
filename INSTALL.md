# Installing MailVesper

This guide installs MailVesper Community on your own hosting. Plan on about 10 minutes. If you get stuck, we can install it for you: see [Installation support](README.md#installation-support).

There are two ways:

- **[The zip, in the browser](#install-from-the-zip-in-the-browser)**: for shared hosting and cPanel, with no terminal. Everything is included, so you don't need Composer.
- **[The command line](#1-get-the-code)**: for a server you reach over SSH.

## What you need

- **Ordinary web hosting with cPanel** (or any server with PHP 8.2+ and MySQL). No Node.js and no command line.
- **A cron job**, which cPanel has under **Cron Jobs**. The installer walks you through it and checks that it works.
- **A (sub)domain** for MailVesper, such as `mail.example.com`, with HTTPS (cPanel's free AutoSSL is fine).

The installer checks the server for you and, if something is missing, gives you a message to send to your hosting company.

<details>
<summary>The technical list, for your host or a developer</summary>

| | |
|---|---|
| **PHP 8.2 or newer** | With these extensions: ctype, curl, dom, fileinfo, filter, iconv, json, mbstring, openssl, pdo (with pdo_mysql or pdo_sqlite), session, simplexml, sodium, tokenizer, xml. zip is optional: it's only used to read zipped DMARC reports. |
| **A database** | MySQL 8 or MariaDB 10.6+, with an empty database and a user that owns it. SQLite works for trying it out from the command line. |
| **A web server** | Apache (with `mod_rewrite` and `mod_headers`), LiteSpeed or nginx, with any PHP handler. |
| **Composer 2** | Only for installing from the code. Not needed for the zip. |
| **Cron** | One line, every minute. It sends scheduled campaigns, runs automations and backups. (If it ever stops, background work still runs after page visits as a safety net, late.) |

For CSV imports of up to 25 MB, set PHP's `upload_max_filesize` and `post_max_size` to at least 26M. MailVesper needs no Node.js, Redis, supervisor or root access.

</details>

## Install from the zip, in the browser

1. **Upload the zip.** In cPanel, open **File Manager**, go to your home folder (not into `public_html`), click **Upload**, and choose `mailvesper-community-<version>.zip`. Then select it and click **Extract**. You get a `mailvesper` folder.
2. **Make a (sub)domain for it.** In **Domains**, click **Create A New Domain**, type something like `mail.example.com`, untick “Share document root”, and set the document root to `mailvesper/public_html`. HTTPS is switched on for you within a few minutes.
3. **Open the installer** at `https://mail.example.com/install/` and follow the four steps:
   - **Server check.** Usually it just says your server is ready. If not, it shows a message to send to your hosting company.
   - **Database.** The installer shows how to make one with cPanel's **MySQL® Database Wizard**, then you type in its name, username and password.
   - **Address and email.** The address is filled in. MailVesper's own emails (password resets, invites) can be set up now or later.
   - **Your admin account.** Your name, email and a password. This login runs MailVesper; add as many workspaces (companies) as you like afterwards.

4. **Add the cron job.** The installer's last screen shows your command and checks that it runs. In cPanel:
   1. Open **Cron Jobs** (in the **Advanced** section, or type “cron” in cPanel's search box).
   2. Under **Add New Cron Job**, set **Common Settings** to **Once Per Minute (\* \* \* \* \*)**.
   3. Paste the command from the installer into **Command**. It looks like `cd /home/you/mailvesper/backend && /opt/alt/php83/usr/bin/php artisan schedule:run >> /dev/null 2>&1`.
   4. Click **Add New Cron Job**, go back to the installer and click **Check it's working**.

   The cron job sends scheduled campaigns, runs automations and makes the nightly backup. Until it runs, MailVesper shows its admin a reminder with these same steps (also in the app at any time).

That's it: log in.

Once the admin account exists, the installer closes for good: opening `/install/` again only says that MailVesper is installed. Check the installation any time from a terminal with `php artisan mailvesper:check`, or skip that and simply log in.

**What's where:** `mailvesper/public_html` is all the web can reach (the pages, the API at `/api` and uploaded images at `/media`). The app, its settings (`mailvesper/backend/.env`) and your data stay in `mailvesper/backend`, outside it.

## 1. Get the code

Clone the code somewhere outside your web server's document root, as the user that will run MailVesper:

```sh
cd ~
git clone https://github.com/bopmack/mailvesper.git mailvesper
cd mailvesper/backend
composer install --no-dev --optimize-autoloader
```

## 2. Run the installer

```sh
php artisan mailvesper:install
```

The installer asks for:

- **The address** people will open, for example `https://mail.example.com`.
- **The database** connection. The installer checks it before saving anything.
- **How MailVesper sends its own emails**: sign-in codes, password resets and team invites. Use any SMTP server, the server's `sendmail`, or "not yet". These emails are separate from the email you send to your contacts, which goes through Amazon SES or SMTP and is set up later in the app.
- **Your admin account**: email and password. This account is the installation's admin. Because it's created here, nobody can sign up before you.
- **The web root**: the folder your web server serves, for example `/var/www/mailvesper` or `~/public_html`.

The installer then:

- writes `backend/.env`;
- creates the encryption key and the database tables;
- builds the web root;
- offers to add the scheduler to your crontab. It adds one line and leaves the rest of the crontab alone.

### Scripted installs

Every question has an option. Secrets come from environment variables, so they never show up in your shell history or in the process list:

```sh
MAILVESPER_DB_PASSWORD='…' MAILVESPER_ADMIN_PASSWORD='…' php artisan mailvesper:install --no-interaction \
  --url=https://mail.example.com --db=mysql --db-name=mailvesper --db-user=mailvesper \
  --mail=smtp --mail-host=smtp.example.com --mail-port=587 --mail-user=apikey --mail-from=no-reply@example.com \
  --admin-email=you@example.com --webroot=/var/www/mailvesper --cron
```

`MAILVESPER_MAIL_PASSWORD` sets the SMTP password. Run `php artisan mailvesper:install --help` to see every option.

## 3. Point the web server at the web root

The web root holds the app pages at `/` and links to the API at `/api` (Laravel's `backend/public`) and to uploaded images at `/media`. Point your (sub)domain's document root at it, and turn on HTTPS. On most control panels that means:

1. setting the subdomain's document root to the web root;
2. issuing a free certificate (AutoSSL or Let's Encrypt).

### Apache or LiteSpeed

The web root comes with an `.htaccess` that sets the security headers, and `backend/public/.htaccess` routes the API. Apache must be allowed to follow the links and read `.htaccess` files:

```apache
<VirtualHost *:443>
    ServerName mail.example.com
    DocumentRoot /var/www/mailvesper
    <Directory /var/www/mailvesper>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    # SSLEngine on, SSLCertificateFile … (or let your control panel / certbot add these)
</VirtualHost>
```

Control panels that only allow `SymLinksIfOwnerMatch` work too, as long as the same user owns the code and the web root.

### nginx

```nginx
server {
    listen 443 ssl;
    server_name mail.example.com;
    root /var/www/mailvesper;
    index index.html;
    client_max_body_size 30m;

    # ssl_certificate …; ssl_certificate_key …;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    location /api/ {
        try_files $uri /api/index.php?$query_string;
    }
    location = /api/index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api/index.php;
        fastcgi_param SCRIPT_NAME /api/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # your PHP-FPM socket
    }
    location ~ \.php$ { return 404; }

    location ~ \.html$ {
        add_header X-Content-Type-Options nosniff always;
        add_header Referrer-Policy strict-origin-when-cross-origin always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' https: data: blob:; media-src 'self' https: blob:; connect-src 'self'; frame-src 'self'; worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'" always;
    }
}
```

PHP-FPM must run as a user that can write to `backend/storage` and `backend/bootstrap/cache`.

## 4. Check it

```sh
php artisan mailvesper:check
```

This checks the server, the settings, the database, background tasks, the queue, and whether the public address answers.

Then open `https://mail.example.com/app/`, log in, and name your first workspace. MailVesper walks you through connecting Amazon SES, or an SMTP server under **Sending → SMTP server**, and verifying your domain.

## The cron job

Required: it sends scheduled campaigns, runs automations and makes backups. On cPanel, see step 4 of the zip install above. Otherwise add this line with `crontab -e`, as the user that owns the files (the installer prints the exact paths for your server). If the cron job ever stops, MailVesper runs the background work after page visits as a safety net, later than it should, and shows its admin a warning; set `MAILVESPER_WEB_CRON=false` in `.env` to turn that safety net off.

```cron
* * * * * cd /home/you/mailvesper/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

It runs:

- the queue workers, which deliver email;
- scheduled campaigns and automation steps;
- domain and SES checks;
- engagement scores;
- the daily backup (MySQL/MariaDB);
- health alerts.

## Updating

```sh
cd ~/mailvesper
git pull
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

Back up the database first (see below). The web root links to the code, so it updates by itself.

## Backups

On MySQL and MariaDB, MailVesper backs up the database every night to `backend/storage/app/backups` (or `BACKUP_PATH`). It can also copy each backup to S3-compatible storage (AWS S3, Backblaze B2, Cloudflare R2, Wasabi and others): set `BACKUP_OFFSITE_BUCKET`, `BACKUP_OFFSITE_KEY`, `BACKUP_OFFSITE_SECRET` and, if needed, `BACKUP_OFFSITE_REGION`, `BACKUP_OFFSITE_ENDPOINT` and `BACKUP_OFFSITE_PREFIX` in `backend/.env`. (MailVesper Pro sets this in the browser and shows the backup status on its Platform dashboard.) Keep a copy of `backend/.env` somewhere safe too: its `APP_KEY` is needed to read the SES keys and passwords stored in the database.

## Changing settings later

The settings the app needs live in the app, under **Settings → Installation** (visible to the installation admin): the system email server, the AI provider and key, tracking domains, setup checks and the complaint mailbox. Server-level settings are in `backend/.env`. After editing that file, run `php artisan optimize`.

Social sign-in (Google, Microsoft, GitHub, Apple) is optional: see [docs/social-login.md](docs/social-login.md).

## Upgrading to Pro

MailVesper Pro installs on top of Community and uses the same database. Nothing is migrated or reinstalled.

1. Get Pro by emailing [akintomide852@gmail.com](mailto:akintomide852@gmail.com?subject=MailVesper%20Pro). You receive a license key and a download link.
2. Unzip the download inside `backend/`. It creates `backend/pro/`.
3. Run `php artisan migrate --force && php artisan optimize`.
4. In the app, open **Settings → General**, choose **I have a license key**, paste the key and save.

Pro features unlock immediately. The installation checks its license with the license server once a day. If it can't reach the server for a while, Pro keeps working through a grace period.

## Troubleshooting

- **The login page loads, but logging in fails or loops.** The address in your browser must match `FRONTEND_URL` exactly (https, and with or without `www`), and `SANCTUM_STATEFUL_DOMAINS` must contain that host. Run `php artisan mailvesper:check`.
- **`/api/v1/health` returns 404.** The web root's `api` link is missing, or the web server isn't following links or reading `.htaccess`. Run `php artisan mailvesper:webroot <path>` again and check step 3.
- **Emails stay "queued".** The cron job isn't running. Log in as the admin: the warning bar at the top shows the steps and checks it. Or run `php artisan mailvesper:check`.
- **"This design couldn't be turned into an email."** Undo the last change in the builder (a block's settings are probably out of range), or remove the block you just edited.
- **Errors.** Check `backend/storage/logs/`, and run `php artisan mailvesper:check`.
