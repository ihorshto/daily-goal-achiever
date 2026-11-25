# Post-pipeline checks

- `./vendor/bin/pint --test` — passed
- `php artisan route:list` — passed (6 routes)
- `php artisan test` — passed: 151 tests, 150 passed, 1 skipped, 703 assertions

```json post-checks
[{"command": "./vendor/bin/pint --test", "exit_code": 0}, {"command": "php artisan route:list", "exit_code": 0}, {"command": "php artisan test", "exit_code": 0}]
```
