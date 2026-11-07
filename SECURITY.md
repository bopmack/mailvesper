# Security policy

## Reporting a vulnerability

Please report security problems privately: open this repository's **Security** tab and choose **Report a vulnerability**. Don't open a public issue or pull request for a vulnerability.

Include what you found, how to reproduce it, and the version (`APP_VERSION` in `backend/.env`, or `GET /api/v1/health`). We will confirm we received it, keep you informed while we fix it, and credit you in the release notes if you'd like.

## Supported versions

Security fixes go into the latest release. Update with the steps in [INSTALL.md → Updating](INSTALL.md#updating).

## Keeping your installation safe

- Serve MailVesper over HTTPS only, and keep `APP_DEBUG=false`.
- Back up `backend/.env` together with the database. Its `APP_KEY` encrypts stored secrets such as SES keys and SMTP passwords; without it they can't be read.
- Keep PHP and the database updated. Run `php artisan mailvesper:check` after server changes.
