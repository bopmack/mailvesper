# Contributing to MailVesper

Thanks for helping. Bug reports, fixes and improvements are all welcome.

## Before you start

- **Bugs:** open an issue with the steps to reproduce, what you expected, and what happened. Remove any personal data, keys or email addresses first.
- **Larger changes:** open an issue to discuss the idea before writing the code, so your work fits where the project is going.
- **Security problems:** don't open an issue. Follow [SECURITY.md](SECURITY.md).

## Setting up

```sh
cd backend
composer install
cp .env.example .env && php artisan key:generate
composer check
```

The tests use SQLite in memory and fake every outside service (Amazon SES, SMTP servers, DNS, AI), so they need no keys or network.

## What a pull request needs

- **`composer check` passes.** It runs code style (Pint; fix it with `composer fix`), static analysis (Larastan) and the tests (Pest).
- **Tests for new behaviour,** in `backend/tests`, next to the tests for the same area.
- **The existing style.** API errors use `ApiException`, so there is one error shape. Settings an admin might change go in the admin panel (platform settings), not hard-coded values. The front end is plain JavaScript, with no build step.
- **One change per pull request,** with a short description of what it changes and why.

By sending a pull request, you confirm that the contribution is your own work, and you give the author of MailVesper a perpetual, worldwide, royalty-free license to use, change, sublicense and distribute it under any license, including in MailVesper Community and MailVesper Pro.
