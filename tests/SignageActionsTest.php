<?php
// Pins Signage_API's guards and its media cleanup: a slide's file goes
// with the slide (or with the media it was replaced by), and only then.
class SignageActionsTest extends ActionTestCase
{
    private $row = array(
        'id' => '4',
        'title' => 'Enrollment',
        'media_path' => 'signage/enroll.mp4',
        'starts_at' => '2026-10-01 08:00:00',
        'ends_at' => '2026-10-31 17:00:00',
    );

    private function model(array $returns = array())
    {
        return array('Signage_Model' => array_merge(array(
            'find' => $this->row,
            'getSettings' => array('rotation_order' => 'sequence', 'transition' => 'fade', 'default_duration_seconds' => '12.5'),
            'allIds' => array('4', '7'),
            'reorder' => true,
        ), $returns));
    }

    private function modelCalls($method)
    {
        $calls = array();
        foreach ($this->controller->Signage_Model->calls as $call) {
            if ($call[0] === $method) {
                $calls[] = $call[1];
            }
        }
        return $calls;
    }

    /** @dataProvider replies */
    public function testReplies($action, array $params, array $body, array $returns, $status, $error)
    {
        $this->assertReply($this->call('SignageApiHarness', $action, $params, $body, $this->model($returns)), $status, $error);
    }

    public function replies()
    {
        $valid = array('title' => 'Open house', 'media_path' => 'signage/open.jpg');

        return array(
            'create: missing title' => array('create', array(), array('media_path' => 'signage/a.jpg'), array(), 400, 'title is required.'),
            'create: window backwards' => array('create', array(), $valid + array('starts_at' => '2026-10-02 00:00', 'ends_at' => '2026-10-01 00:00'), array(), 400, 'ends_at must be later than starts_at.'),
            'create: valid' => array('create', array(), $valid, array(), 200, null),

            'update: no id' => array('update', array(), array('title' => 'x'), array(), 400, 'Missing slide id.'),
            'update: unknown slide' => array('update', array('9'), array('title' => 'x'), array('find' => null), 404, 'Slide not found.'),
            'update: nothing to change' => array('update', array('4'), array('bogus' => 1), array(), 400, 'No valid fields to update.'),
            'update: new end before the stored start' => array('update', array('4'), array('ends_at' => '2026-09-30 00:00'), array(), 400, 'ends_at must be later than starts_at.'),
            'update: valid' => array('update', array('4'), array('is_active' => false), array(), 200, null),

            'delete: no id' => array('delete', array(), array(), array(), 400, 'Missing slide id.'),
            'delete: unknown slide' => array('delete', array('9'), array(), array('find' => null), 404, 'Slide not found.'),
            'delete: valid' => array('delete', array('4'), array(), array(), 200, null),

            'reorder: not every slide' => array('reorder', array(), array('ids' => array('7')), array(), 400, 'ids must list every slide exactly once. Reload the page and try again.'),
            'reorder: failed write' => array('reorder', array(), array('ids' => array('7', '4')), array('reorder' => false), 500, 'Could not save the new order.'),
            'reorder: valid' => array('reorder', array(), array('ids' => array('7', '4')), array(), 200, null),

            'settings: nothing valid' => array('settings', array(), array('bogus' => 1), array(), 400, 'No valid fields to update.'),
            'settings: valid' => array('settings', array(), array('transition' => 'cut'), array(), 200, null),
        );
    }

    public function testWritesAreAdminOnlyButThePublicListIsOpen()
    {
        foreach (array('getAll', 'upload', 'create', 'update', 'delete', 'reorder', 'settings') as $action) {
            $this->assertReply($this->call('SignageApiHarness', $action, array('4'), array(), $this->model(), null), 401, 'Not signed in.');
        }
        $this->assertReply($this->call('SignageApiHarness', 'getPublic', array(), array(), $this->model(), null), 200);
    }

    public function testCreateFallsBackToTheDefaultDuration()
    {
        $this->call('SignageApiHarness', 'create', array(), array('title' => 'a', 'media_path' => 'signage/a.jpg'), $this->model());

        $this->assertSame(12.5, $this->modelCalls('create')[0][0]['duration_seconds']);
    }

    public function testCreateKeepsAnExplicitDuration()
    {
        $this->call('SignageApiHarness', 'create', array(), array('title' => 'a', 'media_path' => 'signage/a.jpg', 'duration_seconds' => 30.5), $this->model());

        $this->assertSame(30.5, $this->modelCalls('create')[0][0]['duration_seconds']);
    }

    public function testDeletingASlideDiscardsItsMedia()
    {
        $this->call('SignageApiHarness', 'delete', array('4'), array(), $this->model());

        $this->assertSame(array('signage/enroll.mp4'), $this->controller->discarded);
    }

    public function testReplacingTheMediaDiscardsTheOldFileOnly()
    {
        $this->call('SignageApiHarness', 'update', array('4'), array('media_path' => 'signage/new.webm'), $this->model());
        $this->assertSame(array('signage/enroll.mp4'), $this->controller->discarded);

        $this->call('SignageApiHarness', 'update', array('4'), array('media_path' => 'signage/enroll.mp4', 'title' => 'x'), $this->model());
        $this->assertSame(array(), $this->controller->discarded);

        $this->call('SignageApiHarness', 'update', array('4'), array('title' => 'x'), $this->model());
        $this->assertSame(array(), $this->controller->discarded);
    }

    public function testAFailedValidationDiscardsNothing()
    {
        $this->call('SignageApiHarness', 'update', array('4'), array('media_path' => 'signage/new.webm', 'ends_at' => '2020-01-01 00:00'), $this->model());

        $this->assertSame(array(), $this->controller->discarded);
        $this->assertSame(array(), $this->modelCalls('update'));
    }
}
