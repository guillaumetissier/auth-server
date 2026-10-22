# Migrating existing users

## Importing accounts

Accounts are imported with their current password hashes: nobody has to choose a new password.

1. Export your users to a CSV file with a header row `username,external_user_id,password_hash`.
   From a Laravel application, for example:

   ```sql
   SELECT username, id AS external_user_id, password AS password_hash FROM users;
   ```

   `external_user_id` is the id of the user in your application: it becomes the `sub` claim of the tokens.

2. Check the file without writing anything:

   ```bash
   make import FILE=users.csv FLAG=--dry-run
   ```

3. Import:

   ```bash
   make import FILE=users.csv
   ```

Only bcrypt (`$2y$…`, Laravel's default) and Argon2 hashes are accepted, never a clear-text password. The
import can be run again: rows whose username or id already exist are skipped. bcrypt hashes are replaced by
Argon2id hashes at each user's first successful login.

## Rolling out two-factor authentication

`APP_2FA_MODE` decides what happens to users who have not set up 2FA yet:

| Mode | User without 2FA | Can disable 2FA |
|---|---|---|
| `optional` | Never asked; can enable it from `/account`. | Yes, with a valid code. |
| `grace` (default) | Sees the enrollment page at every login, with a **Later** button. From `APP_2FA_DEADLINE` on, the button disappears: 2FA is required. | No |
| `required` | Must enroll at the next login. | No |

Leave `APP_2FA_DEADLINE` empty until the date is decided: the page then says 2FA will "soon" be required.
Once it is set (and the container recreated with `docker compose up -d`), the page shows the date and the
number of days left.

Follow the roll-out with `make 2fa-status`; `make 2fa-status FLAG=--pending` lists the accounts still
without 2FA, how often they postponed and when they last signed in.

## Importing domains and rights

See [Domains and rights](domains-and-rights.md#importing-from-another-system).
