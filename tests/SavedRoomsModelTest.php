<?php
use PHPUnit\Framework\TestCase;

// Pins SavedRooms_Model against FakeDb: each read and write is scoped to
// one user, and the list comes back newest first with the room's current
// name read through placard_dialogs.
class SavedRoomsModelTest extends TestCase
{
    private function model()
    {
        $model = new SavedRoomsModelHarness();
        $model->db = new FakeDb();
        // Typed as the controller passes them — a user id straight from the
        // signed-in user row (a string), a room id cast to int — since
        // FakeDb compares strictly where MySQL wouldn't mind.
        $model->db->tables = array(
            'placard_dialogs' => array(
                array('id' => 3, 'room_name' => 'Drawing Room'),
                array('id' => 4, 'room_name' => 'Network Laboratory'),
                array('id' => 5, 'room_name' => 'Psychology Laboratory'),
            ),
            'saved_rooms' => array(
                array('id' => 1, 'user_id' => '7', 'placard_dialog_id' => 3, 'created_at' => '2026-09-01 10:00:00'),
                array('id' => 2, 'user_id' => '7', 'placard_dialog_id' => 5, 'created_at' => '2026-09-03 10:00:00'),
                array('id' => 3, 'user_id' => '9', 'placard_dialog_id' => 4, 'created_at' => '2026-09-05 10:00:00'),
            ),
        );
        return $model;
    }

    public function testGetForUserListsOnlyThatUsersRoomsNewestFirstWithTheirNames()
    {
        $this->assertSame(array(
            array('placard_dialog_id' => 5, 'room_name' => 'Psychology Laboratory', 'created_at' => '2026-09-03 10:00:00'),
            array('placard_dialog_id' => 3, 'room_name' => 'Drawing Room', 'created_at' => '2026-09-01 10:00:00'),
        ), $this->model()->getForUser('7'));
    }

    public function testGetForUserWithNothingSavedIsEmpty()
    {
        $this->assertSame(array(), $this->model()->getForUser('42'));
    }

    public function testCountForUserCountsOnlyThatUser()
    {
        $model = $this->model();
        $this->assertSame(2, $model->countForUser('7'));
        $this->assertSame(1, $model->countForUser('9'));
        $this->assertSame(0, $model->countForUser('42'));
    }

    public function testIsSavedIsPerUser()
    {
        $model = $this->model();
        $this->assertTrue($model->isSaved('7', 3));
        $this->assertFalse($model->isSaved('9', 3), "user 9 hasn't saved room 3");
        $this->assertFalse($model->isSaved('7', 4), 'room 4 is saved by user 9, not user 7');
    }

    public function testSaveInsertsARowForThatUser()
    {
        // FakeDb records inserts rather than storing them.
        $model = $this->model();
        $model->save('9', 3);
        $this->assertContains(
            array('insert', 'saved_rooms', array('user_id' => '9', 'placard_dialog_id' => 3)),
            $model->db->log
        );
    }

    public function testRemoveDeletesOnlyThatUsersRow()
    {
        $model = $this->model();
        // Both users have saved room 3.
        $model->db->tables['saved_rooms'][] = array('id' => 4, 'user_id' => '9', 'placard_dialog_id' => 3, 'created_at' => '2026-09-06 10:00:00');
        $model->remove('7', 3);
        $this->assertFalse($model->isSaved('7', 3));
        $this->assertTrue($model->isSaved('9', 3), "another user's saved room is untouched");
        $this->assertTrue($model->isSaved('7', 5), "the user's other saved rooms are untouched");
    }
}
