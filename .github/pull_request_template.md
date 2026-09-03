<!-- Keep changes to one thin vertical slice where you can. -->

## What & why

<!-- What does this change, and what problem does it solve? Link an issue if there is one. -->

## Notes for the reviewer

<!-- Anything non-obvious: a schema change, a new gate, a deviation from docs/BLUEPRINT.md. -->

## Checklist

- [ ] Tests added or updated, and `php artisan test` passes
- [ ] `vendor/bin/pint` is clean
- [ ] Migrations rebuild from scratch (`php artisan migrate:fresh --seed`)
- [ ] No business logic added to Filament resources / Blade views
- [ ] No hardcoded fees; no stored derived values
- [ ] Server-side enforcement for any new permission (not just UI hiding)
- [ ] `docs/BLUEPRINT.md` updated if a rule changed
