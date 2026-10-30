<?php
// pg_compat.php
// Minimal mysqli-style wrapper over PDO/PostgreSQL so the existing code
// ($conn->query, ->prepare, ->real_escape_string, ->fetch_all ...) keeps working on Neon.

if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}

class PgCompatResult
{
    public $num_rows = 0;
    private $rows;
    private $pos = 0;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
        $this->num_rows = count($rows);
    }

    public function fetch_all($mode = MYSQLI_ASSOC)
    {
        return $this->rows;
    }

    public function fetch_assoc()
    {
        return $this->rows[$this->pos++] ?? null;
    }
}

class PgCompatStatement
{
    public $error = '';
    private $stmt;
    private $params = [];

    public function __construct(PDOStatement $stmt)
    {
        $this->stmt = $stmt;
    }

    // Mirrors mysqli_stmt::bind_param($types, &...$vars)
    public function bind_param($types, &...$vars)
    {
        $this->params = [];
        foreach ($vars as $i => &$v) {
            $this->params[$i] = &$v;
        }
        return true;
    }

    public function execute()
    {
        try {
            $vals = [];
            foreach ($this->params as $v) {
                $vals[] = $v;
            }
            return $this->stmt->execute($vals);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log('DB error: ' . $e->getMessage());
            return false;
        }
    }

    public function get_result()
    {
        return new PgCompatResult($this->stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function close()
    {
        $this->stmt = null;
    }
}

class PgCompatConnection
{
    public $connect_error = null;
    public $error = '';
    private $pdo;

    public function __construct($url)
    {
        $p = parse_url($url);
        if ($p === false || empty($p['host'])) {
            throw new InvalidArgumentException('Invalid DATABASE_URL');
        }
        parse_str($p['query'] ?? '', $q);
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $p['host'],
            $p['port'] ?? 5432,
            ltrim($p['path'] ?? '/postgres', '/'),
            $q['sslmode'] ?? (in_array($p['host'], ['localhost', '127.0.0.1'], true) ? 'prefer' : 'require')
        );
        $this->pdo = new PDO(
            $dsn,
            isset($p['user']) ? urldecode($p['user']) : null,
            isset($p['pass']) ? urldecode($p['pass']) : null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => PHP_SAPI !== 'cli']
        );
    }

    public function set_charset($charset)
    {
        $this->pdo->exec("SET client_encoding TO 'UTF8'");
        return true;
    }

    // MySQL LIKE is case-insensitive; Postgres LIKE is not.
    private function adapt($sql)
    {
        return preg_replace('/\bLIKE\b/i', 'ILIKE', $sql);
    }

    public function query($sql)
    {
        try {
            $stmt = $this->pdo->query($this->adapt($sql));
            return new PgCompatResult($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log('DB error: ' . $e->getMessage());
            return false;
        }
    }

    public function prepare($sql)
    {
        return new PgCompatStatement($this->pdo->prepare($this->adapt($sql)));
    }

    // Used only for values interpolated inside single quotes (standard_conforming_strings is on).
    public function real_escape_string($s)
    {
        return str_replace("'", "''", str_replace("\0", '', (string)$s));
    }

    // Run a multi-statement script (used by db_init.php)
    public function exec($sql)
    {
        return $this->pdo->exec($sql);
    }

    public function close()
    {
        $this->pdo = null;
        return true;
    }
}
