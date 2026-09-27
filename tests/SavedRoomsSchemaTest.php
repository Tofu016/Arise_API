<?php
use PHPUnit\Framework\TestCase;

// saved_rooms relies on its keys for what the code doesn't check itself:
// one row per user and room, and rows removed along with their user or the
// room's details. schema.sql is the source of truth, so read it.
class SavedRoomsSchemaTest extends TestCase
{
    // Every line inside CREATE TABLE `saved_rooms` ( ... ).
    private function tableLines()
    {
        $lines = array();
        $inTable = false;
        foreach (file(__DIR__ . '/../schema.sql') as $line) {
            $line = trim($line);
            if (preg_match('/^CREATE TABLE `saved_rooms`/', $line)) {
                $inTable = true;
            } elseif ($inTable && preg_match('/^\)/', $line)) {
                break;
            } elseif ($inTable) {
                $lines[] = rtrim($line, ',');
            }
        }
        return $lines;
    }

    public function testTheTableExists()
    {
        $this->assertNotEmpty($this->tableLines());
    }

    public function testEachUserCanSaveARoomOnlyOnce()
    {
        $this->assertContains('UNIQUE KEY `user_room` (`user_id`,`placard_dialog_id`)', $this->tableLines());
    }

    public function testRowsGoWithTheirUser()
    {
        $this->assertContains(
            'CONSTRAINT `saved_rooms_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
            $this->tableLines()
        );
    }

    public function testRowsGoWithTheRoomsDetails()
    {
        $this->assertContains(
            'CONSTRAINT `saved_rooms_ibfk_2` FOREIGN KEY (`placard_dialog_id`) REFERENCES `placard_dialogs` (`id`) ON DELETE CASCADE',
            $this->tableLines()
        );
    }
}
