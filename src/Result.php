<?php

namespace Clue\React\SQLite;

class Result
{
    /**
     * last inserted ID (if any)
     *
     * @var int
     */
    public $insertId = 0;

    /**
     * number of affected rows (for UPDATE, DELETE etc.)
     *
     * @var int
     */
    public $changed = 0;

    /**
     * result set column names or field names (if any)
     *
     * @var ?array|string[]|null
     */
    public $columns = null;

    /**
     * result set rows (if any)
     *
     * @var ?array|array[]|null
     */
    public $rows = null;
}
