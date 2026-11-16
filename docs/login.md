# Login

One user, `martin`. No sign-up, no user administration on the website, no
e-mail reset, no CAPTCHA or two-factor login.

## How it works

- Passwords are stored with `password_hash()` and checked with
  `password_verify()` (table `users`).
- Logging in (`POST /api/auth/login`) creates a session on the server
  (table `auth_sessions`): the browser gets a random token in the cookie
  `mr_session` (HttpOnly, Secure, SameSite=Strict); the database keeps only a
  SHA-256 hash of its secret half. The session lasts 30 days from the login,
  or until you log out (`POST /api/auth/logout`) or change the password. The
  password is never stored in the browser.
- Failed logins: at most 3 per client address per 5 minutes (and 30 from all
  addresses together). The block always ends by itself after 5 minutes - the
  account cannot be locked permanently. Only a hash of the address is stored
  (table `login_attempts`).
- The client address is the one the web server sees (`REMOTE_ADDR`). A header
  like `X-Forwarded-For` is used only when that address is a proxy listed in
  `auth.trusted_proxies` (empty by default), and then its right-most address
  that is not such a proxy - a client cannot pick its own address. The daily
  import's summary shows how PHP sees the GitHub runner ("This runner as PHP
  sees it"), next to the runner's public address: the same = PHP sees real
  client addresses; another = a shared proxy, whose range then belongs in
  `auth.trusted_proxies`.
- Settings is shown only when logged in; the password change
  (`POST /api/auth/password`: current, new, confirm) needs a session. A wrong
  current password counts as a failed login. After a change, every other
  device is logged out and this browser gets a fresh session. New passwords
  must have at least 8 characters.
- Changes need JSON from this website (Origin check); with the SameSite
  cookie, other sites cannot act on your behalf.
- The tables are created on first use (`CREATE TABLE IF NOT EXISTS` only).

## What needs the login

Everything that changes your data, checked on the server:

| Endpoint | Login |
|---|---|
| `POST /api/feedback/{id}` - ratings, Not interested, Save for later | required (401 without) |
| `POST /api/auth/password` - Settings | required |
| `POST /api/auth/login`, `/logout` | - |
| `GET /api/recommendations`, `/search`, `/feedback`, `/health`, `/auth/me` | open: anyone can read |
| `/api/internal/*` - import, profile refresh, migration, creating the user | the secret import token (machines, not people) |

Interested/Listened come from your Last.fm profile (weekly refresh through
the internal endpoints) - there is no website function that changes them.

## Creating the user (once)

The start password is never in Git, the code or a log.

On the server (no SSH on one.com):

1. GitHub -> Settings -> Secrets and variables -> Actions: add the secret
   `INITIAL_PASSWORD` with the start password.
2. Actions -> "Initialize login" -> Run workflow. The server creates
   `martin` only while no user exists; running it again changes nothing.
3. Delete the secret `INITIAL_PASSWORD` again.

Locally (the password is read from standard input):

```
docker compose exec php php /var/www/api/bin/create-user.php
```

Tests: `docker compose exec php php /var/www/api/bin/test-auth.php` (separate
test database).
