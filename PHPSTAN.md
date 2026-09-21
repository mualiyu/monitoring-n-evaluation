# Running PHPStan on this project

**Use `composer stan`.** Not `vendor/bin/phpstan` directly.

```
composer stan     # static analysis, alone
composer gate     # migrate:fresh --seed, pest, pint --test, stan — the whole gate
```

## Why the wrapper exists

Two things make the bare command lie to you, and both fail *silently*:

1. **`--no-parallel` is not a PHPStan option.** It looks plausible, and PHPStan
   exits non-zero when given it — so a script that only checks the exit code sees
   "errors" and a human who only reads stdout sees nothing. Several runs were
   reported as clean here that had in fact analysed nothing at all.

2. **`laravel/pao` silences stdout and stderr.** It forces `--error-format=json`,
   captures the output, parses it and re-prints a one-line summary. When the
   capture does not parse — which is exactly what happens when PHPStan wrote a
   usage error instead of a report — it prints **nothing** and leaves the exit
   code non-zero. `PAO_DISABLE=1` turns it off.

Without the memory limit, the parallel workers OOM on this codebase and you get
`Child process error (exit code 255)` — or, through pao, nothing.

Both scripts start with `Composer\Config::disableProcessTimeout`: Composer kills
a child at 300 seconds by default, and the suite runs longer than that. Without
it the gate dies mid-run and still reports success.

## Verifying the checker is actually checking

If a run ever comes back clean and you are surprised, prove it with a canary:

```php
// app/Support/__Canary.php
class __Canary { public function typeMismatch(): int { return 'a string'; } }
```

`composer stan` must report `should return int but returns string`. Delete it
afterwards. A gate you have not seen fail is not a gate.
