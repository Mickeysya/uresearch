# Proposal for Haziq: one terms-acceptance table

Agreed with Sharvin on 2026-11-08 in the table audit
([`../table-audit.md`](../table-audit.md), finding A). Your folder, so your
branch.

`ga_terms_acceptances` and `gra_terms_acceptances` have identical columns
(`id, user_id (unique), accepted_at, timestamps`) and are used the same way:
`GaApplicationController` / `GraApplicationController` check `exists()` for the
user and `firstOrCreate()` on acceptance.

## Change

1. **New migration** in `app/Modules/Haziq/Database/Migrations/` (don't edit
   or delete the two create migrations; everyone has run them):
   - create `terms_acceptances`: `id`, `user_id` FK, `scheme` (`'ga'`/`'gra'`),
     `accepted_at`, timestamps, **unique (`user_id`, `scheme`)** (replaces each
     old table's unique `user_id`);
   - copy rows: `INSERT … SELECT user_id, 'ga', accepted_at, … FROM ga_terms_acceptances`,
     same for `gra`;
   - drop the two old tables.
   - `down()`: recreate both and copy back by `scheme`.
2. **One model** `Models/TermsAcceptance.php` replacing `GaTermsAcceptance` and
   `GraTermsAcceptance`.
3. **Controllers**: each has 2 calls to change:
   - `GaApplicationController.php:107`: `TermsAcceptance::where('user_id', $user->id)->where('scheme', 'ga')->exists()`
   - `GaApplicationController.php:119`: `TermsAcceptance::firstOrCreate(['user_id' => …, 'scheme' => 'ga'], ['accepted_at' => now()])`
   - the same at `GraApplicationController.php:117` and `:134` with `'gra'`.
4. Run your GA/GRA tests, then `./sync.sh` applies it on everyone's machine.
