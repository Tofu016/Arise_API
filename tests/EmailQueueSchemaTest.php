<?php
use PHPUnit\Framework\TestCase;

// The email queue's retry bookkeeping lives in columns the code writes
// and orders by. schema.sql is the source of truth for the table, so a
// test that reads it catches code and schema drifting apart.
class EmailQueueSchemaTest extends TestCase
{
    // column name => its definition line, for the email_queue table.
    private function columns()
    {
        $columns = array();
        $inTable = false;
        foreach (file(__DIR__ . '/../schema.sql') as $line) {
            $line = rtrim($line);
            if (preg_match('/^CREATE TABLE `email_queue`/', $line)) {
                $inTable = true;
            } elseif ($inTable && preg_match('/^\)/', $line)) {
                break;
            } elseif ($inTable && preg_match('/^\s*`([^`]+)`\s+(.*?),?$/', $line, $m)) {
                $columns[$m[1]] = $m[2];
            }
        }
        return $columns;
    }

    public function testTheSchemaParserFindsTheQueueTable()
    {
        $this->assertArrayHasKey('sent_at', $this->columns());
    }

    public function testAttemptsIsANonNegativeCounterThatStartsAtZero()
    {
        $this->assertSame('int(10) unsigned NOT NULL DEFAULT 0', $this->columns()['attempts']);
    }

    public function testLastErrorIsOptionalAndHoldsFiveHundredCharacters()
    {
        $this->assertSame('varchar(500) DEFAULT NULL', $this->columns()['last_error']);
    }

    public function testExistingRowsAndInsertsNeedNoMentionOfTheNewColumns()
    {
        // enqueue() writes only to_email, subject and body_html; both new
        // columns must have a default so old-style inserts keep working.
        foreach (array('attempts', 'last_error') as $column) {
            $this->assertMatchesRegularExpression('/DEFAULT/', $this->columns()[$column], $column);
        }
    }
}
