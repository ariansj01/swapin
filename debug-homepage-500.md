# Debug Session: homepage-500

- Status: OPEN
- Symptom: `HTTP ERROR 500` on homepage after deployment to server
- Target: `index.php`

## Hypotheses

1. `index.php` contains a PHP syntax error and fails before runtime.
2. Bootstrap fails inside `includes/config.php` due to DB/session/env differences.
3. Temporary debug edits (`echo`, `exit`, nested `<?php`) broke the page structure.
4. Fatal errors are not being captured with enough context in file logs.
5. After syntax is fixed, a second fatal may still exist in render/query code.

## Evidence

- `php -l index.php` reported: `PHP Parse error: syntax error, unexpected token "<", expecting end of file` on line 98.
- Root cause was temporary debug code blocks (`STEP 1`, `STEP 2`, `STEP 3`, `STEP 4`) plus unmatched PHP block transitions in `index.php`.
- Because the parse error happened inside `index.php`, normal bootstrap execution could not continue far enough to render the page.

## Instrumentation

- Added request-scoped fatal/exception logging in `includes/config.php`.
- Added lightweight stage logs in `index.php` before homepage query execution.

## Fix

- Removed temporary `ini_set(...)`, `echo "STEP X"`, and `exit;` debug blocks from `index.php`.
- Confirmed `index.php` now passes PHP lint without syntax errors.

## Verification

- `php -l c:\xampp\htdocs\swaapin\index.php` => `No syntax errors detected`
- Next verification should be on the deployed server by loading `/` and reviewing `storage/logs/error.log`.
