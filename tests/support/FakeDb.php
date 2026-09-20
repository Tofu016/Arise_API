<?php
// Stands in for CodeIgniter's query builder on a model. Records every call
// in order, and serves canned rows for reads: $tables maps a table name to
// its rows, and pending where() equalities filter them, the way the real
// builder would. Only the calls the models use are implemented.
class FakeDb
{
    public $log = array();
    public $tables = array();

    private $from;
    private $wheres = array();
    private $limit = null;

    public function select($columns = '*')
    {
        $this->log[] = array('select', $columns);
        return $this;
    }

    public function from($table)
    {
        $this->from = $table;
        $this->log[] = array('from', $table);
        return $this;
    }

    public function where($key, $value = null)
    {
        $this->wheres[] = array($key, $value);
        $this->log[] = array('where', $key, $value);
        return $this;
    }

    public function where_in($key, $values)
    {
        $this->log[] = array('where_in', $key, $values);
        return $this;
    }

    public function order_by($key, $direction = 'ASC')
    {
        $this->log[] = array('order_by', $key, $direction);
        return $this;
    }

    public function limit($count)
    {
        $this->log[] = array('limit', $count);
        $this->limit = $count;
        return $this;
    }

    public function get($table = null)
    {
        $table = $table !== null ? $table : $this->from;
        $this->log[] = array('get', $table);

        $rows = isset($this->tables[$table]) ? $this->tables[$table] : array();
        foreach ($this->wheres as $where) {
            $rows = array_values(array_filter($rows, function ($row) use ($where) {
                // A null value matches a null column, like SQL's IS NULL.
                return array_key_exists($where[0], $row) && $row[$where[0]] === $where[1];
            }));
        }
        if ($this->limit !== null) {
            $rows = array_slice($rows, 0, $this->limit);
        }
        $this->reset();

        return new FakeDbResult($rows);
    }

    public function insert($table, $data)
    {
        $this->log[] = array('insert', $table, $data);
        return true;
    }

    public function update($table, $data)
    {
        $this->log[] = array('update', $table, $data);
        $this->reset();
        return true;
    }

    public function delete($table)
    {
        $this->log[] = array('delete', $table);
        $this->reset();
        return true;
    }

    public function insert_id()
    {
        return 1;
    }

    private function reset()
    {
        $this->from = null;
        $this->wheres = array();
        $this->limit = null;
    }
}

class FakeDbResult
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function result_array()
    {
        return $this->rows;
    }

    public function row_array()
    {
        return $this->rows ? $this->rows[0] : null;
    }

    public function num_rows()
    {
        return count($this->rows);
    }
}
