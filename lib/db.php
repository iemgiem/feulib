<?php
declare(strict_types=1);

/**
 * Database access — single PDO connection + prepared-statement helpers.
 *
 * Usage:
 *   $row  = q_one('SELECT * FROM accounts WHERE id = ?', [$id]);
 *   $rows = q_all('SELECT * FROM lost_reports WHERE status = ?', ['open']);
 *   $val  = q_value('SELECT COUNT(*) FROM matches WHERE status = ?', ['pending']);
 *   q('INSERT INTO ...', [...]);
 *   $newId = db_last_id();
 *
 *   db_transaction(function () use ($foo) {
 *       q('INSERT INTO a ...', [...]);
 *       q('INSERT INTO b ...', [...]);
 *   });
 *
 * NEVER concatenate user input into $sql. Use parameter placeholders (?) only.
 */

/**
 * Return the singleton PDO connection. Lazily constructed on first call.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = cfg('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Execute a prepared statement and return the PDOStatement.
 * Use when you need fetch flexibility; otherwise prefer q_one / q_all / q_value.
 */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch a single row as an associative array, or null if no row matched.
 */
function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/**
 * Fetch all rows as a list of associative arrays.
 */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/**
 * Fetch the first column of the first row (useful for COUNT, EXISTS, MAX queries).
 */
function q_value(string $sql, array $params = [])
{
    $value = q($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Last insert ID as int. Call immediately after an INSERT.
 */
function db_last_id(): int
{
    return (int) db()->lastInsertId();
}

/**
 * Run a callable inside a database transaction.
 * Commits on success, rolls back + rethrows on any \Throwable.
 */
function db_transaction(callable $work)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
