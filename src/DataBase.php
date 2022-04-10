<?php /** @noinspection SqlNoDataSourceInspection */

namespace splitbrain\blogrng;

/**
 * Helpers to access a SQLite Database with automatic schema migration
 *
 * @todo move to it's own library
 */
class DataBase
{
    /** @var \PDO */
    protected $pdo;

    /** @var string */
    protected $schemadir;

    /**
     * Constructor
     * @param string|\PDO $database filename or already intitialized PDO object
     * @param string $schemadir directory with schema migration files
     */
    public function __construct($database, $schemadir)
    {
        $this->schemadir = $schemadir;
        if (is_a($database, \PDO::class)) {
            $this->pdo = $database;
        } else {
            $this->pdo = new \PDO(
                'sqlite:' . $database,
                null,
                null,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
                ]
            );
        }

        // apply migrations if needed
        $currentVersion = $this->currentDbVersion();
        $migrations = $this->getMigrationsToApply($currentVersion);
        if ($migrations) {
            foreach ($migrations as $version => $database) {
                $this->applyMigration($database, $version);
            }
            $this->pdo->exec('VACUUM');
        }
    }

    // region public API

    /**
     * Direct access to the PDO object
     * @return \PDO
     */
    public function pdo()
    {
        return $this->pdo;
    }

    /**
     * Execute a statement
     *
     * Returns the last insert ID on INSERTs or the number of affected rows
     *
     * @param string $sql
     * @param array $parameters
     * @return int
     */
    public function exec($sql, $parameters = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);

        $count = $stmt->rowCount();
        if ($count && preg_match('/^INSERT /i', $sql)) {
            return $this->queryValue('SELECT last_insert_rowid()');
        }

        return $count;
    }

    /**
     * Simple query abstraction
     *
     * Returns all data
     *
     * @param string $sql
     * @param array $params
     * @return array|false
     * @throws \PDOException
     */
    public function queryAll($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $data;
    }

    /**
     * Query one single row
     *
     * @param string $sql
     * @param array $parameters
     * @return array|null
     */
    public function queryRecord($sql, $parameters = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);
        $row = $stmt->fetch();
        $stmt->closeCursor();
        if (is_array($row) && count($row)) return $row;
        return null;
    }

    /**
     * Insert or replace the given data into the table
     *
     * @param string $table
     * @param array $data
     * @param bool $replace Conflict resolution, replace or ignore
     * @return void
     */
    public function saveRecord($table, $data, $replace = true)
    {
        $columns = array_map(function ($column) {
            return '"' . $column . '"';
        }, array_keys($data));
        $values = array_values($data);
        $placeholders = array_pad([], count($columns), '?');

        if ($replace) {
            $command = 'REPLACE';
        } else {
            $command = 'INSERT OR IGNORE';
        }

        /** @noinspection SqlResolve */
        $sql = $command . ' INTO "' . $table . '" (' . join(',', $columns) . ') VALUES (' . join(',', $placeholders) . ')';
        $stm = $this->pdo->prepare($sql);
        $stm->execute($values);
        $stm->closeCursor();
    }

    /**
     * Execute a query that returns a single value
     *
     * @param string $sql
     * @param array $params
     * @return mixed|null
     */
    public function queryValue($sql, $params = [])
    {
        $result = $this->queryAll($sql, $params);
        if (is_array($result) && count($result)) return array_values($result[0])[0];
        return null;
    }

    /**
     * Get a config value from the opt table
     *
     * @param string $conf Config name
     * @param mixed $default What to return if the value isn't set
     * @return mixed
     */
    public function getOpt($conf, $default = null)
    {
        $value = $this->queryValue("SELECT val FROM opt WHERE conf = ?", [$conf]);
        if ($value === null) return $default;
        return $value;
    }

    /**
     * Set a config value in the opt table
     *
     * @param $conf
     * @param $value
     * @return void
     */
    public function setOpt($conf, $value)
    {
        $this->exec('REPLACE INTO opt (conf,val) VALUES (?,?)', [$conf, $value]);
    }

    // endregion

    // region migration handling

    /**
     * Read the current version from the opt table
     *
     * The opt table is created here if not found
     *
     * @return int
     */
    protected function currentDbVersion()
    {
        $sql = "SELECT val FROM opt WHERE conf = 'dbversion'";
        try {
            $version = $this->queryValue($sql);
            return (int)$version;
        } catch (\PDOException $ignored) {
            // add the opt table - if this fails too, let the exception bubble up
            $sql = "CREATE TABLE IF NOT EXISTS opt (conf TEXT NOT NULL PRIMARY KEY, val NOT NULL DEFAULT '')";
            $this->pdo->exec($sql);
            $sql = "INSERT INTO opt (conf, val) VALUES ('dbversion', 0)";
            $this->pdo->exec($sql);
            return 0;
        }
    }

    /**
     * Get all schema files that have not been applied, yet
     *
     * @param int $current
     * @return array
     */
    protected function getMigrationsToApply($current)
    {
        $files = glob($this->schemadir . '/*.sql');
        $upgrades = [];
        foreach ($files as $file) {
            $file = basename($file);
            if (!preg_match('/^(\d+)/', $file, $m)) continue;
            if ((int)$m[1] <= $current) continue;
            $upgrades[(int)$m[1]] = $file;
        }
        return $upgrades;
    }

    /**
     * Apply the migration in the given file, upgrading to the given version
     *
     * @param string $file
     * @param int $version
     */
    protected function applyMigration($file, $version)
    {
        $sql = file_get_contents($this->schemadir . '/' . $file);

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec($sql);
            $st = $this->pdo->prepare('REPLACE INTO opt ("conf", "val") VALUES (:conf, :val)');
            $st->execute([':conf' => 'dbversion', ':val' => $version]);
            $this->pdo->commit();
        } catch (\PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // endregion
}
