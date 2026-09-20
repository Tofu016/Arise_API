<?php
// Stands in for CodeIgniter's query builder on a model. Records every call
// in order, and behaves like a small in-memory database for the calls the
// models use: $tables maps a table name to its rows; reads honour pending
// where() equalities, order_by() and limit(); update() applies its data
// (and any set() values, including "column + N" increments) to the rows the
// pending where() equalities match.
class FakeDb
{
    public $log = array();
    public $tables = array();

    private $from;
    private $wheres = array();
    private $orders = array();
    private $sets = array();
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
        $this->orders[] = array($key, strtoupper($direction));
        $this->log[] = array('order_by', $key, $direction);
        return $this;
    }

    public function limit($count)
    {
        $this->log[] = array('limit', $count);
        $this->limit = $count;
        return $this;
    }

    // A value to write on the next update(). With $escape false the value
    // is a raw SQL expression; only "column + N" is understood.
    public function set($key, $value = '', $escape = true)
    {
        $this->sets[] = array($key, $value, $escape);
        $this->log[] = array('set', $key, $value, $escape);
        return $this;
    }

    public function get($table = null)
    {
        $table = $table !== null ? $table : $this->from;
        $this->log[] = array('get', $table);

        $rows = array();
        foreach ($this->matchingIndexes($table) as $i) {
            $rows[] = $this->tables[$table][$i];
        }
        $rows = $this->ordered($rows);
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

    public function update($table, $data = null)
    {
        $this->log[] = array('update', $table, $data);

        foreach ($this->matchingIndexes($table) as $i) {
            $row = &$this->tables[$table][$i];
            foreach ((array) $data as $key => $value) {
                $row[$key] = $value;
            }
            foreach ($this->sets as $set) {
                list($key, $value, $escape) = $set;
                if (!$escape && preg_match('/^(\w+)\s*\+\s*(\d+)$/', $value, $m)) {
                    $row[$key] = (int) $row[$m[1]] + (int) $m[2];
                } else {
                    $row[$key] = $value;
                }
            }
            unset($row);
        }
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

    // Indexes into $tables[$table] of the rows every pending where() matches.
    private function matchingIndexes($table)
    {
        $indexes = array();
        foreach (isset($this->tables[$table]) ? $this->tables[$table] : array() as $i => $row) {
            $matches = true;
            foreach ($this->wheres as $where) {
                // A null value matches a null column, like SQL's IS NULL.
                if (!array_key_exists($where[0], $row) || $row[$where[0]] !== $where[1]) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $indexes[] = $i;
            }
        }
        return $indexes;
    }

    // A stable sort by the pending order_by() keys, in the order given.
    private function ordered(array $rows)
    {
        if (!$this->orders) {
            return $rows;
        }
        $orders = $this->orders;
        $indexed = array_map(null, array_keys($rows), $rows);
        usort($indexed, function ($a, $b) use ($orders) {
            foreach ($orders as $order) {
                $cmp = ($a[1][$order[0]] ?? null) <=> ($b[1][$order[0]] ?? null);
                if ($cmp !== 0) {
                    return $order[1] === 'DESC' ? -$cmp : $cmp;
                }
            }
            return $a[0] <=> $b[0];
        });
        return array_column($indexed, 1);
    }

    private function reset()
    {
        $this->from = null;
        $this->wheres = array();
        $this->orders = array();
        $this->sets = array();
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
