# Changelog

## 1.7.0 (2025-01-17)

*   Feature: Improve PHP 8.4+ support by avoiding implicitly nullable types.
    (#72 and #73 by @clue)

*   Feature / Fix: Update close handler to avoid unhandled promise rejections.
    (#71 by @clue)

*   Minor documentation improvements.
    (#68 by @yadaiio)

## 1.6.0 (2023-05-12)

*   Feature: Forward compatibility with upcoming Promise v3.
    (#63 by @clue)

*   Feature: Full PHP 8.2+ compatibility.
    (#64 by @clue and #62 by @SimonFrings)

*   Improve test suite, ensure 100% code coverage and report failed assertions.
    (#65 and #66 by @clue)

## 1.5.0 (2022-02-15)

*   Feature: Improve PHAR support, support spawning worker process from PHARs without file extension.
    (#61 by @clue)

*   Improve test suite and update clue/phar-composer to avoid skipped tests on Windows.
    (#60 by @clue)

## 1.4.0 (2022-01-11)

*   Feature: Support running from PHAR.
    (#55 by @clue)

*   Feature: Simplify internal JSON-RPC protocol by avoiding unneeded escapes.
    (#56 by @clue)

## 1.3.0 (2021-11-12)

*   Feature: Support upcoming PHP 8.1 release.
    (#49 by @SimonFrings)

*   Feature: Support passing custom PHP binary as optional argument to `Factory`.
    (#45 and #46 by @clue)

    ```php
    // advanced usage: pass custom PHP binary to use when spawning child process
    $factory = new Clue\React\SQLite\Factory(null, '/usr/bin/php6.0');
    ```

*   Feature: Support using blocking SQLite adapter when using an empty binary path.
    (#48 by @clue)

    ```php
    // advanced usage: empty binary path runs blocking SQLite in same process
    $factory = new Clue\React\SQLite\Factory(null, '');
    ```

*   Feature: Use default `php` binary instead of respecting `PHP_BINARY` when automatic binary detection fails for non-CLI SAPIs.
    (#50 by @clue)

## 1.2.0 (2021-10-04)

*   Feature: Simplify usage by supporting new [default loop](https://reactphp.org/event-loop/#loop).
    (#39 by @clue and #44 by @SimonFrings)

    ```php
    // old (still supported)
    $factory = new Clue\React\SQLite\Factory($loop);

    // new (using default loop)
    $factory = new Clue\React\SQLite\Factory();
    ```

*   Feature: Reject null byte in path to SQLite database file.
    (#42 by @SimonFrings)

*   Maintenance: Improve documentation and examples.
    (#38 by @PaulRotmann and #43 by @SimonFrings)

## 1.1.0 (2020-12-15)

*   Improve test suite and add `.gitattributes` to exclude dev files from exports.
    Add PHP 8 support, update to PHPUnit 9 and simplify test setup.
    (#30, #31, #33, #34 and #37 by @SimonFrings)

## 1.0.1 (2019-05-17)

*   Fix: Fix result set rows for DDL queries to be null, instead of empty array.
    (#26 by @clue)

## 1.0.0 (2019-05-14)

*   First stable release, following SemVer
