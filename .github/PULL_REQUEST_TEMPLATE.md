## Sottotask
ID: <v4.0.W?.?.?>
Plan ref: `plans/v4.0-week-?-detailed.md` sezione W?.?.?

## Summary
1-2 sentences description.

## Changes
- File modificati
- Migration aggiunta
- Test aggiunti

## Test gate
- [ ] PHPUnit verde (`vendor/bin/phpunit`)
- [ ] PHPStan level 8 (`vendor/bin/phpstan analyse`)
- [ ] Pint clean (`vendor/bin/pint --test`)
- [ ] Architecture tests verdi (R30/R31/R32 dove applicabili)
- [ ] Vitest verde (frontend, se applicabile)
- [ ] Playwright E2E verde (se applicabile)
- [ ] Eval tests verdi (se applicabile per agent/prompt changes)

## Architecture impact
[Brief: nessun nuovo coupling, R30 rispettato]

## Security impact
[Brief: nessun nuovo path PII, ACL rispettate]

## Risk
[Low/Medium/High + mitigation]

## Rollback plan
[Come revertire se serve]
