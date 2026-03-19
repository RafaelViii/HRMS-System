<?php
/**
 * Lightweight MySQLi-compatibility layer backed by PDO for MySQL/PostgreSQL.
 * Only implements the subset used by this application.
 */

// Define MYSQLI_ASSOC if mysqli is absent
if (!defined('MYSQLI_ASSOC')) { define('MYSQLI_ASSOC', 1); }

class DbMysqliResultCompat {
    private PDOStatement $stmt;
    private ?array $rows = null;
    private int $idx = 0;
    public function __construct(PDOStatement $stmt) { $this->stmt = $stmt; }
    private function ensureBuffered(): void {
        if ($this->rows === null) { $this->rows = $this->stmt->fetchAll(PDO::FETCH_ASSOC); $this->idx = 0; }
    }
    public function fetch_all($mode = null) {
        $this->ensureBuffered();
        return $this->rows;
    }
    public function fetch_assoc() {
        $this->ensureBuffered();
        if ($this->idx >= count($this->rows)) return null;
        return $this->rows[$this->idx++];
    }
    public function fetch_row() {
        $row = $this->fetch_assoc();
        return $row !== null ? array_values($row) : null;
    }
    public function __get($name) {
        if ($name === 'num_rows') { $this->ensureBuffered(); return count($this->rows); }
        return null;
    }
}

class DbMysqliStmtCompat {
    private PDO $pdo;
    private ?PDOStatement $stmt = null;
    private string $sql;
    private string $driver;
    private array $bound = [];
    public int $insert_id = 0;
    private string $lastError = '';

    public function __construct(PDO $pdo, string $sql, string $driver) {
        $this->pdo = $pdo; $this->sql = $sql; $this->driver = $driver;
    }

    // MySQLi-style: bind_param('is', $id, $name)
    public function bind_param(string $types, &...$vars): bool {
        // store by reference to reflect later changes before execute
        $this->bound = &$vars;
        return true;
    }

    public function execute(): bool {
        try {
            $sql = $this->sql;
            $isInsert = (bool)preg_match('/^\s*insert\b/i', $sql);
            // Auto-append RETURNING id for pgsql INSERTs without RETURNING
            if ($this->driver === 'pgsql' && $isInsert) {
                $trim = ltrim($sql);
                if (stripos($trim, ' returning ') === false) {
                    $sql .= ' RETURNING id';
                }
            }
            $this->stmt = $this->pdo->prepare($sql);
            // Convert references to values at execution time
            $params = [];
            foreach ($this->bound as &$ref) { $params[] = $ref; }
            $ok = $this->stmt->execute($params);
            // last insert id
            if ($ok && $isInsert) {
                if ($this->driver === 'pgsql') {
                    // If RETURNING id was used, fetch it
                    if (stripos($sql, ' returning ') !== false) {
                        $row = $this->stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && isset($row['id'])) { $this->insert_id = (int)$row['id']; }
                    } else {
                        // Fallback to lastInsertId() which maps to LASTVAL()
                        $this->insert_id = (int)$this->pdo->lastInsertId();
                    }
                } else {
                    $this->insert_id = (int)$this->pdo->lastInsertId();
                }
            }
            return $ok;
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    public function get_result(): DbMysqliResultCompat {
        if ($this->stmt === null) {
            // Prepare/execute without params
            $this->stmt = $this->pdo->prepare($this->sql);
            $this->stmt->execute();
        }
        return new DbMysqliResultCompat($this->stmt);
    }

    public function close(): void { $this->stmt = null; }
    public function __get($name) {
        if ($name === 'error') return $this->lastError;
        if ($name === 'insert_id') return $this->insert_id;
        return null;
    }
}

class DbMysqliCompat {
    private PDO $pdo;
    private string $driver; // 'mysql' or 'pgsql'
    private string $lastError = '';

    public function __construct(PDO $pdo, string $driver) {
        $this->pdo = $pdo; $this->driver = $driver;
    }

    public function getDriver(): string { return $this->driver; }

    public function set_charset(string $charset): bool {
        try {
            if ($this->driver === 'mysql') {
                $this->pdo->exec("SET NAMES '" . str_replace("'", "''", $charset) . "'");
            }
            return true;
        } catch (Throwable $e) { return false; }
    }

    public function begin_transaction(): bool { return $this->pdo->beginTransaction(); }
    public function commit(): bool { return $this->pdo->commit(); }
    public function rollback(): bool { return $this->pdo->rollBack(); }

    public function query(string $sql): DbMysqliResultCompat {
        try {
            $sql = $this->rewriteSqlIfNeeded($sql);
            $stmt = $this->pdo->query($sql);
            return new DbMysqliResultCompat($stmt);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }

    public function prepare(string $sql): DbMysqliStmtCompat {
        $sql = $this->rewriteSqlIfNeeded($sql);
        return new DbMysqliStmtCompat($this->pdo, $sql, $this->driver);
    }

    public function real_escape_string(string $value): string {
        $q = $this->pdo->quote($value);
        // strip surrounding quotes
        return substr($q, 1, -1);
    }

    private function rewriteSqlIfNeeded(string $sql): string {
        if ($this->driver !== 'pgsql') return $sql;
        $out = $sql;
        // 1) Backticks to double quotes (basic)
        $out = str_replace('`', '"', $out);
        // 2) IFNULL(x,y) -> COALESCE(x,y)
        $out = preg_replace('/\bIFNULL\s*\(/i', 'COALESCE(', $out);
        // 3) DATE_SUB(CURDATE(), INTERVAL N DAY)
        $out = preg_replace_callback('/DATE_SUB\s*\(\s*CURDATE\s*\(\)\s*,\s*INTERVAL\s+(\d+)\s+DAY\s*\)/i', function($m){
            return "(CURRENT_DATE - INTERVAL '" . $m[1] . " day')";
        }, $out);
        // 4) CURDATE() -> CURRENT_DATE
        $out = preg_replace('/\bCURDATE\s*\(\s*\)/i', 'CURRENT_DATE', $out);
        // 5) DATE(col) -> col::date
        $out = preg_replace('/\bDATE\s*\(\s*([a-zA-Z_\."]+)\s*\)/i', '$1::date', $out);
        // 6) SUM(col='value') -> SUM(CASE WHEN col='value' THEN 1 ELSE 0 END)
        $out = preg_replace('/SUM\s*\(\s*([a-zA-Z_\."]+)\s*=\s*\'([^\']+)\'\s*\)/i', 'SUM(CASE WHEN $1=\'$2\' THEN 1 ELSE 0 END)', $out);
        return $out;
    }

    public function __get($name) {
        if ($name === 'insert_id') return (int)$this->pdo->lastInsertId();
        if ($name === 'error') return $this->lastError;
        return null;
    }
}
