# clue/reactphp-sqlite [![Build Status](https://travis-ci.org/clue/reactphp-sqlite.svg?branch=master)](https://travis-ci.org/clue/reactphp-sqlite)

Async SQLite database, lightweight non-blocking process wrapper around file-based database extension (`ext-sqlite3`),
built on top of [ReactPHP](https://reactphp.org/).

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Factory](#factory)
    * [open()](#open)
    * [openLazy()](#openlazy)
  * [DatabaseInterface](#databaseinterface)
    * [exec()](#exec)
    * [query()](#query)
    * [quit()](#quit)
    * [close()](#close)
    * [Events](#events)
      * [error event](#error-event)
      * [close event](#close-event)
* [Install](#install)
* [Tests](#tests)
* [License](#license)

## Quickstart example

The following example code demonstrates how this library can be used to open an
existing SQLite database file (or automatically create it on first run) and then
`INSERT` a new record to the database:

```php
$loop = React\EventLoop\Factory::create();
$factory = new Clue\React\SQLite\Factory($loop);

$db = $factory->openLazy('users.db');
$db->exec('CREATE TABLE IF NOT EXISTS foo (id INTEGER PRIMARY KEY AUTOINCREMENT, bar STRING)');

$name = 'Alice';
$db->query('INSERT INTO foo (bar) VALUES (?)', [$name])->then(
    function (Clue\React\SQLite\Result $result) use ($name) {
        echo 'New ID for ' . $name . ': ' . $result->insertId . PHP_EOL;
    }
);

$db->quit();

$loop->run();
```

See also the [examples](examples).

## Usage

### Factory

The `Factory` is responsible for opening your [`DatabaseInterface`](#databaseinterface) instance.
It also registers everything with the main [`EventLoop`](https://github.com/reactphp/event-loop#usage).

```php
$loop = React\EventLoop\Factory::create();
$factory = new Clue\React\SQLite\Factory($loop);
```

#### open()

The `open(string $filename, int $flags = null): PromiseInterface<DatabaseInterface>` method can be used to
open a new database connection for the given SQLite database file.

This method returns a promise that will resolve with a `DatabaseInterface` on
success or will reject with an `Exception` on error. The SQLite extension
is inherently blocking, so this method will spawn an SQLite worker process
to run all SQLite commands and queries in a separate process without
blocking the main process. On Windows, it uses a temporary network socket
for this communication, on all other platforms it communicates over
standard process I/O pipes.

```php
$factory->open('users.db')->then(function (DatabaseInterface $db) {
    // database ready
    // $db->query('INSERT INTO users (name) VALUES ("test")');
    // $db->quit();
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

The `$filename` parameter is the path to the SQLite database file or
`:memory:` to create a temporary in-memory database. As of PHP 7.0.10, an
empty string can be given to create a private, temporary on-disk database.
Relative paths will be resolved relative to the current working directory,
so it's usually recommended to pass absolute paths instead to avoid any
ambiguity.

```php
$promise = $factory->open(__DIR__ . '/users.db');
```

The optional `$flags` parameter is used to determine how to open the
SQLite database. By default, open uses `SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE`.

```php
$factory->open('users.db', SQLITE3_OPEN_READONLY)->then(function (DatabaseInterface $db) {
    // database ready (read-only)
    // $db->quit();
}, function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

#### openLazy()

The `openLazy(string $filename, int $flags = null, array $options = []): DatabaseInterface` method can be used to
open a new database connection for the given SQLite database file.

```php
$db = $factory->openLazy('users.db');

$db->query('INSERT INTO users (name) VALUES ("test")');
$db->quit();
```

This method immediately returns a "virtual" connection implementing the
[`DatabaseInterface`](#databaseinterface) that can be used to
interface with your SQLite database. Internally, it lazily creates the
underlying database process only on demand once the first request is
invoked on this instance and will queue all outstanding requests until
the underlying database is ready. Additionally, it will only keep this
underlying database in an "idle" state for 60s by default and will
automatically end the underlying database when it is no longer needed.

From a consumer side this means that you can start sending queries to the
database right away while the underlying database process may still be
outstanding. Because creating this underlying process may take some
time, it will enqueue all oustanding commands and will ensure that all
commands will be executed in correct order once the database is ready.
In other words, this "virtual" database behaves just like a "real"
database as described in the `DatabaseInterface` and frees you from
having to deal with its async resolution.

If the underlying database process fails, it will reject all
outstanding commands and will return to the initial "idle" state. This
means that you can keep sending additional commands at a later time which
will again try to open a new underlying database. Note that this may
require special care if you're using transactions that are kept open for
longer than the idle period.

Note that creating the underlying database will be deferred until the
first request is invoked. Accordingly, any eventual connection issues
will be detected once this instance is first used. You can use the
`quit()` method to ensure that the "virtual" connection will be soft-closed
and no further commands can be enqueued. Similarly, calling `quit()` on
this instance when not currently connected will succeed immediately and
will not have to wait for an actual underlying connection.

Depending on your particular use case, you may prefer this method or the
underlying `open()` method which resolves with a promise. For many
simple use cases it may be easier to create a lazy connection.

The `$filename` parameter is the path to the SQLite database file or
`:memory:` to create a temporary in-memory database. As of PHP 7.0.10, an
empty string can be given to create a private, temporary on-disk database.
Relative paths will be resolved relative to the current working directory,
so it's usually recommended to pass absolute paths instead to avoid any
ambiguity.

```php
$$db = $factory->openLazy(__DIR__ . '/users.db');
```

The optional `$flags` parameter is used to determine how to open the
SQLite database. By default, open uses `SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE`.

```php
$db = $factory->openLazy('users.db', SQLITE3_OPEN_READONLY);
```

By default, this method will keep "idle" connection open for 60s and will
then end the underlying connection. The next request after an "idle"
connection ended will automatically create a new underlying connection.
This ensure you always get a "fresh" connection and as such should not be
confused with a "keepalive" or "heartbeat" mechanism, as this will not
actively try to probe the connection. You can explicitly pass a custom
idle timeout value in seconds (or use a negative number to not apply a
timeout) like this:

```php
$db = $factory->openLazy('users.db', null, ['idle' => 0.1]);
```

### DatabaseInterface

The `DatabaseInterface` represents a connection that is responsible for
comunicating with your SQLite database wrapper, managing the connection state
and sending your database queries.

#### exec()

The `exec(string $query): PromiseInterface<Result>` method can be used to
execute an async query.

This method returns a promise that will resolve with a `Result` on
success or will reject with an `Exception` on error. The SQLite wrapper
is inherently sequential, so that all queries will be performed in order
and outstanding queries will be put into a queue to be executed once the
previous queries are completed.

```php
$db->exec('CREATE TABLE test ...');
$db->exec('INSERT INTO test (id) VALUES (1)');
```

This method is specifically designed for queries that do not return a
result set (such as a `UPDATE` or `INSERT` statement). Queries that do
return a result set (such as from a `SELECT` or `EXPLAIN` statement) will
not allow access to this data, so you're recommended to use the `query()`
method instead.

```php
$db->exec($query)->then(function (Result $result) {
    // this is an OK message in response to an UPDATE etc.
    if ($result->insertId !== 0) {
        var_dump('last insert ID', $result->insertId);
    }
    echo 'Query OK, ' . $result->changed . ' row(s) changed' . PHP_EOL;
}, function (Exception $error) {
    // the query was not executed successfully
    echo 'Error: ' . $error->getMessage() . PHP_EOL;
});
```

Unlike the `query()` method, this method does not support passing an
array of placeholder parameters that will be bound to the query. If you
want to pass user-supplied data, you're recommended to use the `query()`
method instead.

#### query()

The `query(string $query, array $params = []): PromiseInterface<Result>` method can be used to
perform an async query.


This method returns a promise that will resolve with a `Result` on
success or will reject with an `Exception` on error. The SQLite wrapper
is inherently sequential, so that all queries will be performed in order
and outstanding queries will be put into a queue to be executed once the
previous queries are completed.

```php
$db->query('CREATE TABLE test ...');
$db->query('INSERT INTO test (id) VALUES (1)');
```

If this SQL statement returns a result set (such as from a `SELECT`
statement), this method will buffer everything in memory until the result
set is completed and will then resolve the resulting promise.

```php
$db->query($query)->then(function (Result $result) {
    if (isset($result->rows)) {
        // this is a response to a SELECT etc. with some rows (0+)
        print_r($result->columns);
        print_r($result->rows);
        echo count($result->rows) . ' row(s) in set' . PHP_EOL;
    } else {
        // this is an OK message in response to an UPDATE etc.
        if ($result->insertId !== 0) {
            var_dump('last insert ID', $result->insertId);
        }
        echo 'Query OK, ' . $result->changed . ' row(s) changed' . PHP_EOL;
    }
}, function (Exception $error) {
    // the query was not executed successfully
    echo 'Error: ' . $error->getMessage() . PHP_EOL;
});
```

You can optionally pass an array of `$params` that will be bound to the
query like this:

```php
$db->query('SELECT * FROM user WHERE id > ?', [$id]);
```

Likewise, you can also use named placeholders that will be bound to the
query like this:

```php
$db->query('SELECT * FROM user WHERE id > :id', ['id' => $id]);
```

All placeholder values will automatically be mapped to the native SQLite
datatypes and all result values will automatically be mapped to the
native PHP datatypes. This conversion supports `int`, `float`, `string`
and `null`. Any `string` that is valid UTF-8 without any control
characters will be mapped to `TEXT`, binary strings will be mapped to
`BLOB`. Both `TEXT` and `BLOB` will always be mapped to `string` . SQLite
does not have a native boolean type, so `true` and `false` will be mapped
to integer values `1` and `0` respectively.

#### quit()

The `quit(): PromiseInterface<void, Exception>` method can be used to
quit (soft-close) the connection.

This method returns a promise that will resolve (with a void value) on
success or will reject with an `Exception` on error. The SQLite wrapper
is inherently sequential, so that all commands will be performed in order
and outstanding commands will be put into a queue to be executed once the
previous commands are completed.

```php
$db->query('CREATE TABLE test ...');
$db->quit();
```

#### close()

The `close(): void` method can be used to
force-close the connection.

Unlike the `quit()` method, this method will immediately force-close the
connection and reject all oustanding commands.

```php
$db->close();
```

Forcefully closing the connection should generally only be used as a last
resort. See also [`quit()`](#quit) as a safe alternative.

#### Events

Besides defining a few methods, this interface also implements the
`EventEmitterInterface` which allows you to react to certain events:

##### error event

The `error` event will be emitted once a fatal error occurs, such as
when the connection is lost or is invalid.
The event receives a single `Exception` argument for the error instance.

```php
$db->on('error', function (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
});
```

This event will only be triggered for fatal errors and will be followed
by closing the connection. It is not to be confused with "soft" errors
caused by invalid SQL queries.

##### close event

The `close` event will be emitted once the connection closes (terminates).

```php
$db->on('close', function () {
    echo 'Connection closed' . PHP_EOL;
});
```

See also the [`close()`](#close) method.


## Install

The recommended way to install this library is [through Composer](https://getcomposer.org).
[New to Composer?](https://getcomposer.org/doc/00-intro.md)

This will install the latest supported version:

```bash
$ composer require clue/reactphp-sqlite:dev-master
```

This project aims to run on any platform and thus only requires `ext-sqlite3` and
supports running on legacy PHP 5.4 through current PHP 7+.
It's *highly recommended to use PHP 7+* for this project.

This project is implemented as a lightweight process wrapper around the `ext-sqlite3`
PHP extension, so you'll have to make sure that you have a suitable version
installed. On Debian/Ubuntu-based systems, you may simply install it like this:

```bash
$ sudo apt install php-sqlite3
```

## Tests

To run the test suite, you first need to clone this repo and then install all
dependencies [through Composer](https://getcomposer.org):

```bash
$ composer install
```

To run the test suite, go to the project root and run:

```bash
$ php vendor/bin/phpunit
```

## License

This project is released under the permissive [MIT license](LICENSE).

> Did you know that I offer custom development services and issuing invoices for
  sponsorships of releases and for contributions? Contact me (@clue) for details.
