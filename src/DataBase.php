<?php /** @noinspection SqlNoDataSourceInspection */

namespace splitbrain\blogrng;

class DataBase
{
    /** @var \PDO */
    protected $db;

    /**
     * Constructor
     */
    public function __construct($file)
    {
        $exists = file_exists($file);
        $this->db = new \PDO(
            'sqlite:' . $file,
            null,
            null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]
        );

        if (!$exists) {
            $this->initTables();
        }
    }

    public function pdo() {
        return $this->db;
    }

    /**
     * Simple query abstraction
     *
     * @param string $sql
     * @param array $params
     * @return array|false
     * @throws \PDOException
     */
    public function query($sql, $params = [])
    {
        $stm = $this->db->prepare($sql);
        $stm->execute($params);
        $data = $stm->fetchAll(\PDO::FETCH_ASSOC);
        $stm->closeCursor();
        return $data;
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
        $stm = $this->db->prepare($sql);
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
        $result = $this->query($sql, $params);
        if (!$result) return null;
        return array_values($result[0])[0];
    }

    /**
     * Initializes the database from schema
     */
    protected function initTables()
    {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $sql = explode(';', $sql);
        foreach ($sql as $statement) {
            $this->db->exec($statement);
        }
    }
}
