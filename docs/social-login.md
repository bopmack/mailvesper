# Social sign-in setup

Google, Microsoft, GitHub and Apple sign-in are built in and switched off. Each provider turns on as soon as its keys are in `backend/.env`, followed by `php artisan optimize`.

Below, `https://mail.example.com` stands for your MailVesper address (`FRONTEND_URL`).

Callback URLs to register (one per provider):

| Provider | Callback URL |
|---|---|
| Google | `https://mail.example.com/api/v1/auth/oauth/google/callback` |
| Microsoft | `https://mail.example.com/api/v1/auth/oauth/microsoft/callback` |
| GitHub | `https://mail.example.com/api/v1/auth/oauth/github/callback` |
| Apple | `https://mail.example.com/api/v1/auth/oauth/apple/callback` |

## Google
1. Google Cloud Console → APIs & Services → **OAuth consent screen**: External, app name "MailVesper", support email, logo, domain `mail.example.com`. Scopes: `openid`, `email`, `profile` (non-sensitive, so no Google review needed).
2. **Credentials → Create credentials → OAuth client ID** → Web application.
   - Authorised JavaScript origin: `https://mail.example.com`
   - Authorised redirect URI: the Google callback URL above
3. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`.
4. Publish the consent screen ("In production"), otherwise only test users can sign in.

## Microsoft (personal + work/school accounts)
1. Azure portal → **Microsoft Entra ID → App registrations → New registration**.
   - Supported account types: **Accounts in any organizational directory and personal Microsoft accounts**
   - Redirect URI: Web → the Microsoft callback URL above
2. **Certificates & secrets → New client secret** (note the expiry; set a reminder to rotate it).
3. API permissions: Microsoft Graph `User.Read` (delegated, default).
4. Set `MICROSOFT_CLIENT_ID` (Application ID) and `MICROSOFT_CLIENT_SECRET` (the secret *value*). `MICROSOFT_TENANT` stays `common`.

## GitHub
1. GitHub → Settings → Developer settings → **OAuth Apps → New OAuth App**.
   - Homepage URL: `https://mail.example.com`
   - Authorization callback URL: the GitHub callback URL above
2. Generate a client secret.
3. Set `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET`.

## Apple (needs an Apple Developer account, $99/year)
1. developer.apple.com → Certificates, IDs & Profiles → **Identifiers → App IDs**: create one with "Sign in with Apple" enabled.
2. **Identifiers → Services IDs**: create one (e.g. `com.sendline.web`), enable Sign in with Apple, configure:
   - Primary App ID: the App ID from step 1
   - Domains: `mail.example.com`
   - Return URL: the Apple callback URL above
3. **Keys → New key** with Sign in with Apple enabled → download the `.p8` file (only downloadable once).
4. Set `APPLE_CLIENT_ID` (the Services ID), `APPLE_TEAM_ID` and `APPLE_KEY_ID`. Store the `.p8` file outside the web root (e.g. `backend/storage/app/apple.p8`) and set `APPLE_PRIVATE_KEY_PATH` to its full path.

## How accounts are matched (security)
- Google, GitHub and Apple confirm the email is verified, so signing in with them can link to an existing MailVesper account with the same email.
- Microsoft can return unverified addresses, so it never links to an existing account automatically; the person signs in with password or email code first. New Microsoft sign-ups confirm their email with MailVesper's 6-digit code.
- Two-step verification and team invite links work the same as with password sign-in.
