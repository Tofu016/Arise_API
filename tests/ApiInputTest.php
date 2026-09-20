<?php
use PHPUnit\Framework\TestCase;

class ApiInputTest extends TestCase
{
    // The reply a helper refused with, or null if it let the input through.
    private function refusal(callable $check)
    {
        $reply = Api_response::run(function () use ($check) {
            $check();
        });
        return $reply;
    }

    private function assertRefused($reply, $message)
    {
        $this->assertNotNull($reply, 'Expected the input to be refused.');
        $this->assertSame(400, $reply->status());
        $this->assertSame($message, $reply->body()['error']);
        $this->assertFalse($reply->body()['success']);
    }

    // ---- requireId -------------------------------------------------

    /** @dataProvider missingIds */
    public function testRequireIdRefusesAMissingId($id)
    {
        $this->assertRefused($this->refusal(function () use ($id) {
            Api_input::requireId($id, 'node');
        }), 'Missing node id.');
    }

    public function missingIds()
    {
        return array('null' => array(null), 'empty string' => array(''), 'zero string' => array('0'), 'zero' => array(0));
    }

    public function testRequireIdNamesWhatIsMissing()
    {
        $this->assertRefused($this->refusal(function () {
            Api_input::requireId(null, 'marker');
        }), 'Missing marker id.');
    }

    public function testRequireIdAcceptsARealId()
    {
        $this->assertNull($this->refusal(function () {
            Api_input::requireId('gd1_f1_hallway02', 'node');
            Api_input::requireId('7', 'marker');
        }));
    }

    // ---- requirePresent (isset) -----------------------------------

    public function testRequirePresentNamesTheFirstMissingFieldInOrder()
    {
        $this->assertRefused($this->refusal(function () {
            Api_input::requirePresent(array('a' => 1), array('a', 'b', 'c'));
        }), 'Missing field: b');
    }

    public function testRequirePresentTreatsNullAsMissing()
    {
        $this->assertRefused($this->refusal(function () {
            Api_input::requirePresent(array('a' => null), array('a'));
        }), 'Missing field: a');
    }

    public function testRequirePresentAcceptsZeroFalseAndEmptyString()
    {
        $this->assertNull($this->refusal(function () {
            Api_input::requirePresent(array('a' => 0, 'b' => '', 'c' => false, 'd' => '0'), array('a', 'b', 'c', 'd'));
        }));
    }

    public function testRequirePresentWithNoFieldsAcceptsAnything()
    {
        $this->assertNull($this->refusal(function () {
            Api_input::requirePresent(array(), array());
        }));
    }

    // ---- requireFilled (empty) ------------------------------------

    /** @dataProvider unfilledValues */
    public function testRequireFilledRefusesAnyEmptyField($value)
    {
        $this->assertRefused($this->refusal(function () use ($value) {
            Api_input::requireFilled(array('a' => 'x', 'b' => $value), array('a', 'b'), 'a and b are both required.');
        }), 'a and b are both required.');
    }

    public function unfilledValues()
    {
        return array('empty string' => array(''), 'zero string' => array('0'), 'null' => array(null), 'zero' => array(0), 'false' => array(false));
    }

    public function testRequireFilledRefusesAnAbsentField()
    {
        $this->assertRefused($this->refusal(function () {
            Api_input::requireFilled(array('a' => 'x'), array('a', 'b'), 'both.');
        }), 'both.');
    }

    public function testRequireFilledAcceptsFilledFields()
    {
        $this->assertNull($this->refusal(function () {
            Api_input::requireFilled(array('a' => 'x', 'b' => ' '), array('a', 'b'), 'both.');
        }));
    }

    // ---- patch -----------------------------------------------------

    public function testPatchKeepsOnlyAllowedFieldsWithTheirValues()
    {
        $patch = Api_input::patch(array('name' => 'X', 'id' => 'hijack', 'floor' => 0, 'bogus' => 1), array('name', 'floor'));

        $this->assertSame(array('name' => 'X', 'floor' => 0), $patch);
    }

    public function testPatchKeepsAnAllowedFieldWhoseValueIsEmpty()
    {
        $this->assertSame(array('section_id' => ''), Api_input::patch(array('section_id' => ''), array('section_id')));
        $this->assertSame(array('label' => null), Api_input::patch(array('label' => null), array('label')));
    }

    public function testPatchRefusesWhenNoAllowedFieldIsPresent()
    {
        $this->assertRefused($this->refusal(function () {
            Api_input::patch(array('bogus' => 1), array('name'));
        }), 'No valid fields to update.');
        $this->assertRefused($this->refusal(function () {
            Api_input::patch(array(), array('name'));
        }), 'No valid fields to update.');
    }

    public function testPatchAcceptsAnEmptyPatchWhenTheRequestCarriesAnotherChange()
    {
        $this->assertSame(array(), Api_input::patch(array('photos' => array()), array('label'), true));
    }

    public function testAnotherChangeDoesNotHideARealPatch()
    {
        $this->assertSame(array('label' => 'x'), Api_input::patch(array('label' => 'x'), array('label'), true));
    }
}
