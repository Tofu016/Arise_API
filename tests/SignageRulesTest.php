<?php
use PHPUnit\Framework\TestCase;

// Pins what a valid signage slide and signage settings are, and when a
// slide counts as live on the kiosk.
class SignageRulesTest extends TestCase
{
    private function refusal(callable $fn)
    {
        try {
            $fn();
        } catch (Api_abort $abort) {
            $this->assertSame(400, $abort->response()->status());
            return $abort->response()->body()['error'];
        }
        $this->fail('Expected a 400 refusal.');
    }

    private function slide(array $extra = array())
    {
        return array_merge(array('title' => 'Enrollment', 'media_path' => 'signage/enroll.mp4'), $extra);
    }

    // ---- slideFields ----------------------------------------------

    public function testCreateNeedsATitleAndAnUploadedFile()
    {
        $this->assertSame('title is required.', $this->refusal(function () {
            Signage_rules::slideFields(array('media_path' => 'signage/a.jpg'), true);
        }));
        $this->assertSame('title is required.', $this->refusal(function () {
            Signage_rules::slideFields($this->slide(array('title' => '   ')), true);
        }));
        $this->assertStringStartsWith('media_path must be an uploaded signage file', $this->refusal(function () {
            Signage_rules::slideFields(array('title' => 'x'), true);
        }));
    }

    public function testMediaMustBeASignagePathNotAnotherCategoryOrAGuess()
    {
        foreach (array('tourpanorama/a.jpg', 'signage/../a.jpg', 'a.jpg', 'signage/x/a.jpg') as $path) {
            $this->assertStringStartsWith('media_path', $this->refusal(function () use ($path) {
                Signage_rules::slideFields($this->slide(array('media_path' => $path)), true);
            }), $path);
        }
    }

    public function testAValidCreateIsTrimmedAndKeepsOnlyKnownFields()
    {
        $fields = Signage_rules::slideFields($this->slide(array('title' => '  Enrollment  ', 'bogus' => 1)), true);

        $this->assertSame(array('title' => 'Enrollment', 'media_path' => 'signage/enroll.mp4'), $fields);
    }

    public function testCategoryIsFooterOrStartingOnly()
    {
        $this->assertSame('starting', Signage_rules::slideFields($this->slide(array('category' => 'starting')), true)['category']);
        $this->assertSame(array('category' => 'footer'), Signage_rules::slideFields(array('category' => 'footer'), false));
        $this->assertStringStartsWith('category must be one of', $this->refusal(function () {
            Signage_rules::slideFields($this->slide(array('category' => 'header')), true);
        }));
    }

    public function testAnUpdateTakesAnySubsetAndCanBeEmpty()
    {
        $this->assertSame(array(), Signage_rules::slideFields(array('bogus' => 1), false));
        $this->assertSame(array('duration_seconds' => 15.0), Signage_rules::slideFields(array('duration_seconds' => '15'), false));
    }

    public function testCropIsAllOrNothing()
    {
        $this->assertSame('crop_x, crop_y, crop_w and crop_h must be sent together.', $this->refusal(function () {
            Signage_rules::slideFields(array('crop_x' => 0.1), false);
        }));
    }

    public function testCropMustStayInsideTheMedia()
    {
        $cases = array(
            'negative start' => array(-0.1, 0, 0.5, 0.5, 'The crop must start inside the media.'),
            'overhangs right' => array(0.6, 0, 0.5, 0.5, 'The crop must stay inside the media.'),
            'overhangs bottom' => array(0, 0.6, 0.5, 0.5, 'The crop must stay inside the media.'),
            'too small' => array(0, 0, 0.001, 0.5, 'The crop is too small.'),
            'not a number' => array('left', 0, 0.5, 0.5, 'crop_x must be a number between 0 and 1.'),
        );
        foreach ($cases as $name => list($x, $y, $w, $h, $error)) {
            $this->assertSame($error, $this->refusal(function () use ($x, $y, $w, $h) {
                Signage_rules::slideFields(array('crop_x' => $x, 'crop_y' => $y, 'crop_w' => $w, 'crop_h' => $h), false);
            }), $name);
        }
    }

    public function testACropFlushWithTheEdgeAbsorbsRoundingSlack()
    {
        $fields = Signage_rules::slideFields(array('crop_x' => 0.25, 'crop_y' => '0', 'crop_w' => 0.7500004, 'crop_h' => 1), false);

        $this->assertSame(array('crop_x' => 0.25, 'crop_y' => 0.0, 'crop_w' => 0.75, 'crop_h' => 1.0), $fields);
    }

    public function testDurationIsInTenthsOfASecondWithinRange()
    {
        $duration = function ($value) {
            return Signage_rules::slideFields(array('duration_seconds' => $value), false)['duration_seconds'];
        };

        $this->assertSame(3.0, $duration(3));
        $this->assertSame(600.0, $duration('600'));
        $this->assertSame(7.5, $duration('7.5'));
        // 7.3 * 10 is 72.999... in binary floating point; still one decimal.
        $this->assertSame(7.3, $duration(7.3));
        $this->assertSame(12.0, $duration('12.0'));
    }

    public function testDurationRefusesOutOfRangeOrTooPrecise()
    {
        $cases = array(
            'too short' => array(2.9, 'duration_seconds must be between 3 and 600 seconds.'),
            'too long' => array(600.1, 'duration_seconds must be between 3 and 600 seconds.'),
            'two decimals' => array('7.25', 'duration_seconds can have at most one decimal place (like 7.5).'),
            'not a number' => array('ten', 'duration_seconds must be a number of seconds.'),
            'a boolean' => array(true, 'duration_seconds must be a number of seconds.'),
        );
        foreach ($cases as $name => list($value, $error)) {
            $this->assertSame($error, $this->refusal(function () use ($value) {
                Signage_rules::slideFields(array('duration_seconds' => $value), false);
            }), $name);
        }
    }

    public function testIsActiveIsAStrictBoolean()
    {
        $this->assertSame(array('is_active' => 0), Signage_rules::slideFields(array('is_active' => false), false));
        $this->assertSame(array('is_active' => 1), Signage_rules::slideFields(array('is_active' => '1'), false));
        $this->assertSame('is_active must be true or false.', $this->refusal(function () {
            Signage_rules::slideFields(array('is_active' => 'yes'), false);
        }));
    }

    public function testDatesAcceptTheDatetimeLocalShapeAndClearWithBlank()
    {
        $fields = Signage_rules::slideFields(array('starts_at' => '2026-10-01T08:30', 'ends_at' => ''), false);

        $this->assertSame(array('starts_at' => '2026-10-01 08:30:00', 'ends_at' => null), $fields);
    }

    public function testImpossibleOrMalformedDatesAreRefused()
    {
        $this->assertSame('starts_at is not a real date and time.', $this->refusal(function () {
            Signage_rules::slideFields(array('starts_at' => '2026-02-30 08:00'), false);
        }));
        $this->assertStringStartsWith('ends_at must be a date and time', $this->refusal(function () {
            Signage_rules::slideFields(array('ends_at' => 'next week'), false);
        }));
    }

    public function testTheWindowMustEndAfterItStarts()
    {
        Signage_rules::requireWindowOrder(null, '2026-01-01 00:00:00');
        Signage_rules::requireWindowOrder('2026-01-01 00:00:00', null);
        Signage_rules::requireWindowOrder('2026-01-01 00:00:00', '2026-01-01 00:00:01');

        $this->assertSame('ends_at must be later than starts_at.', $this->refusal(function () {
            Signage_rules::requireWindowOrder('2026-01-01 00:00:00', '2026-01-01 00:00:00');
        }));
    }

    // ---- isLive ---------------------------------------------------

    public function testIsLiveNeedsTheSlideSwitchedOn()
    {
        $this->assertTrue(Signage_rules::isLive(array('is_active' => '1', 'starts_at' => null, 'ends_at' => null), '2026-10-01 12:00:00'));
        $this->assertFalse(Signage_rules::isLive(array('is_active' => '0', 'starts_at' => null, 'ends_at' => null), '2026-10-01 12:00:00'));
    }

    public function testTheWindowStartIsInclusiveAndTheEndExclusive()
    {
        $row = array('is_active' => 1, 'starts_at' => '2026-10-01 08:00:00', 'ends_at' => '2026-10-02 08:00:00');

        $this->assertFalse(Signage_rules::isLive($row, '2026-10-01 07:59:59'));
        $this->assertTrue(Signage_rules::isLive($row, '2026-10-01 08:00:00'));
        $this->assertTrue(Signage_rules::isLive($row, '2026-10-02 07:59:59'));
        $this->assertFalse(Signage_rules::isLive($row, '2026-10-02 08:00:00'));
    }

    // ---- reorderIds -----------------------------------------------

    public function testReorderMustNameEverySlideExactlyOnce()
    {
        $this->assertSame(array('3', '1', '2'), Signage_rules::reorderIds(array(3, 1, 2), array('1', '2', '3')));

        foreach (array(array(1, 2), array(1, 2, 3, 4), array(1, 1, 2), 'nope') as $bad) {
            $this->refusal(function () use ($bad) {
                Signage_rules::reorderIds($bad, array('1', '2', '3'));
            });
        }
    }

    // ---- settingsFields -------------------------------------------

    public function testSettingsAcceptOnlyKnownValues()
    {
        $this->assertSame(
            array('rotation_order' => 'shuffle', 'transition' => 'cut', 'default_duration_seconds' => 8.5),
            Signage_rules::settingsFields(array('rotation_order' => 'shuffle', 'transition' => 'cut', 'default_duration_seconds' => '8.5'))
        );

        $this->assertSame('No valid fields to update.', $this->refusal(function () {
            Signage_rules::settingsFields(array('bogus' => 1));
        }));
        $this->assertStringStartsWith('rotation_order must be one of', $this->refusal(function () {
            Signage_rules::settingsFields(array('rotation_order' => 'random'));
        }));
        $this->assertStringStartsWith('transition must be one of', $this->refusal(function () {
            Signage_rules::settingsFields(array('transition' => 'wipe'));
        }));
    }
}
