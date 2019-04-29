# clue/reactphp-sqlite [![Build Status](https://travis-ci.org/clue/reactphp-sqlite.svg?branch=master)](https://travis-ci.org/clue/reactphp-sqlite)

Async SQLite database, lightweight non-blocking process wrapper around file-based database extension (`ext-sqlite3`),
built on top of [ReactPHP](https://reactphp.org/).

**Table of contents**

* [Quickstart example](#quickstart-example)
* [Usage](#usage)
  * [Factory](#factory)
    * [open()](#open)
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

$name = 'Alice';
$factory->open('users.db')->then(
    function (Clue\React\SQLite\DatabaseInterface $db) use ($name) {
        $db->exec('CREATE TABLE IF NOT EXISTS foo (id INTEGER PRIMARY KEY AUTOINCREMENT, bar STRING)');

        $db->query('INSERT INTO foo (bar) VALUES (?)', array($name))->then(
            function (Clue\React\SQLite\Result $result) use ($name) {
                echo 'New ID for ' . $name . ': ' . $result->insertId . PHP_EOL;
            }
        );

        $db->quit();
    },
    function (Exception $e) {
        echo 'Error: ' . $e->getMessage() . PHP_EOL;
    }
);

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

The `query(string $query, array $params = array()): PromiseInterface<Result>` method can be used to
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
supports running on legacy PHP 5.4 through current PHP 7+ and HHVM.
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
