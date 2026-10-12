# Password Reset and Account Settings

## What it does

- **Forgot password**: ask for a reset link by email, open it, set a new password.
- **Change password**: needs the current password.
- **Change email**: the new address must be confirmed through a link before it replaces the old one.
- **Settings**: the time zone in which the app shows dates.

## Rules

| Rule | Value |
|------|-------|
| Reset link | random 256-bit token, valid 30 minutes, works once; only its SHA-256 hash is stored |
| Email confirmation link | same kind of token, valid 60 minutes, works once |
| Asking again | makes the earlier, still valid link of the same kind stop working |
| Password set by reset or change | 12–128 characters (counted as characters, not bytes) |
| Time zone | exact IANA name (`Europe/Kyiv`, `UTC`); case-sensitive, no abbreviations or offsets |

Sign-up still accepts passwords from 8 characters; this feature did not change that route.

### Session invalidation

Sessions are stateless JWTs. Each token carries the account's *token version*; a token with another
version is rejected with 401.

| Event | Effect on existing tokens |
|-------|---------------------------|
| Password reset completed | all rejected; sign in again |
| Password changed | all rejected; the response carries a new token for the session that made the change |
| Email change confirmed | all rejected; sign in with the new email |
| Reset or email change only requested, settings changed | none |

Tokens issued before this feature have no version and stay valid until one of the events above.

### Rate limits

Counted in a sliding 15-minute window; the answer is 429
`{"error": "Too many attempts. Try again later.", "retry_after": <seconds>}` with a `Retry-After` header.

| Action | Limit |
|--------|-------|
| Reset requests for one email (existing or not) | 3 |
| Reset requests from one client address | 20 |
| Failed reset confirmations from one client address | 10 |
| Failed email confirmations from one client address | 10 |
| Wrong current password (change password and change email together), per account | 5; a correct one resets the count |
| Email change requests per account | 5 |

While an account is locked out by wrong passwords, the right password is refused too.

## API

Errors use the shapes described in [sharing](notes-sharing-rbac.md#api); every response has `X-Correlation-ID`.
All routes are in [`/api/doc.json`](http://localhost:4444/api/doc.json) (tags *Password Reset*, *Account*).

### Public

| Route | Body | Success | Errors |
|-------|------|---------|--------|
| `POST /api/auth/password-reset/request` | `email` | 202 `{"message": "If an account exists for this email, a password reset link has been sent."}` | 400, 422 (malformed email), 429 |
| `POST /api/auth/password-reset/confirm` | `token`, `password` | 200 `{"message": "Password updated. Sign in with your new password."}` | 400, 422, 429 |
| `POST /api/account/email/confirm` | `token` | 200 `{"message": "Email address changed. Sign in with your new email."}` | 400, 409 (address taken meanwhile), 422, 429 |

- The reset request answers identically for an existing, unknown or unconfirmed email. Only a confirmed
  account gets an email; the link is `/app?reset_token=<token>`.
- A bad token is a 422 on `token`: *"This reset link has expired. Request a new one."* or
  *"This reset link is invalid or was already used. Request a new one."* A weak password is a 422 on
  `password` and does not use the link up.

### Signed in (`Authorization: Bearer <JWT>`)

| Route | Body | Success | Errors |
|-------|------|---------|--------|
| `GET /api/account/profile` | – | 200 `{"id", "email", "pending_email"}` | 401 |
| `PATCH /api/account/profile` | `email`, `current_password` | 202, profile with `pending_email` set | 400, 401, 403 (wrong password), 422, 429 |
| `POST /api/account/change-password` | `current_password`, `new_password` | 200 `{"message", "token"}` | 400, 401, 403 (wrong password), 422 (weak, or same as current), 429 |
| `GET /api/account/settings` | – | 200 `{"timezone"}` | 401 |
| `PATCH /api/account/settings` | `timezone` | 200 `{"timezone"}` | 400, 401, 422 |

- `PATCH /api/account/profile` never changes the email itself. It mails a link
  (`/app?email_change_token=<token>`) to the new address and answers the same way when that address
  already belongs to another account (then no mail is sent and the change can never be confirmed).
- Unknown fields are a 422 on every route.

## Data model

| Table / column | Notes |
|----------------|-------|
| `account_tokens` | `id`, `user_id` (FK, cascade), `purpose` (`password_reset`, `email_change`), `token_hash` (unique), `payload` (new email), `expires_at`, `used_at`, `created_at` |
| `throttle_hits` | `bucket`, `key_hash` (SHA-256 of the email, address or user ID), `created_at` |
| `users.timezone` | default `UTC` |
| `users.token_version` | default 0 |

A token is used up with a single conditional update, so two simultaneous confirmations cannot both succeed.

Audit events (`audit_events`, actor = the account): `account.password_reset_requested`,
`account.password_reset_completed`, `account.password_changed`, `account.email_change_requested`,
`account.email_changed`, `account.settings_updated`. Neither they nor the application log contain
tokens, passwords or email addresses.

### Migration and rollback

`migrations/Version20261012220000.php` adds the two tables and two columns; existing users get `UTC`
and version 0, so nobody is signed out by the migration.

```sh
make migrate
docker compose run --rm php-cli php bin/console doctrine:migrations:migrate prev   # roll back
```

Rolling back drops pending links, rate-limit counters and time zone choices. Passwords and emails stay
as they are; tokens rejected because of a password change become valid again until they expire.

## UI

- Sign-in card: **Forgot password?** opens the request form.
- A reset link opens **Reset Password** (new password twice). An expired or used link shows its message
  and **Request a new link** instead of the form. The token is removed from the address bar.
- **Profile**: email with the pending new address, **Change email** form, **Settings** (time zone),
  **Change password** dialog.
- Note timestamps are shown in the chosen time zone.
- When the server rejects the stored session (expired, or password or email changed elsewhere), the app
  returns to the sign-in form with *"Your session has ended. Sign in again."*

## Fixtures

`app:fixtures:sharing:seed <runId>` (manifest version 4):

- persona `unverified` (`unverified.<runId>@qa-fixtures.test`): never confirmed, cannot sign in, gets no
  reset mail;
- `reset_tokens.expired` and `reset_tokens.used`: two reset tokens of the `unrelated` persona that must be
  refused. A working reset link is never seeded; request one and read it from MailHog.

Purging a run also clears the rate-limit counters of its personas, and finds a persona even after a test
changed its email.

## Tests

`make test`; `tests/Application/Api/AccountTest.php`, `tests/Unit/PasswordPolicyTest.php`.
Mail is captured in the test environment (`MAILER_DSN=null://null`), not sent.

| Requirement | Tests |
|-------------|-------|
| FR-01 Generic response, unknown email indistinguishable | `testResetRequestAnswersTheSameForKnownUnknownAndUnconfirmedEmails` |
| FR-02 One-time random token, hash in DB | `testResetEmailCarriesAOneTimeTokenStoredOnlyAsHash` |
| FR-03 Expires after 30 minutes | `testExpiredTokenIsRejected`, `testResetEmailCarriesAOneTimeTokenStoredOnlyAsHash` |
| FR-04 Used tokens cannot be reused | `testValidResetUpdatesCredentialsAndSignsOutEverywhere`, `testNewerRequestReplacesOlderLink` |
| FR-05 Throttle per account and address | `testResetRequestsAreThrottledPerEmail`, `…PerAddress`, `testFailedConfirmationsAreThrottled`, `testWrongCurrentPasswordIsThrottled` |
| FR-06 Password length 12–128 | `testPasswordLengthBoundaries`, `testResetValidation`, `testChangePasswordValidation`, `PasswordPolicyTest` |
| FR-07 Change needs the old password | `testChangePassword` |
| FR-08 Session invalidation | `testValidResetUpdatesCredentialsAndSignsOutEverywhere`, `testChangePassword`, `testEmailChangeNeedsConfirmation` |
| FR-09 Email change needs verification | `testEmailChangeNeedsConfirmation`, `testEmailChangeRefusals`, `testEmailTakenBeforeConfirmationIsAConflict` |
| FR-10 IANA time zone | `testSettings` |
| FR-11 No secrets stored or logged | `testAuditRecordsAndStoredDataHoldNoSecrets` |
| Email sent | `testResetEmailCarriesAOneTimeTokenStoredOnlyAsHash` (captured), browser check against MailHog |
| OpenAPI | `testOpenApiDocumentsAccountRoutes` |

## Limitations

- The password policy is length only; no breach or complexity check.
- Rate limits are fixed in code, not configurable, and there is no CAPTCHA.
- The old address is not notified when an email change is requested or confirmed.
- Fixtures do not create or remove MailHog messages.
- No browser test suite in this repository; the UI flows (forgot, expired link, reset, change password,
  change email through MailHog, time zone, ended session) were checked manually with a headless browser.
