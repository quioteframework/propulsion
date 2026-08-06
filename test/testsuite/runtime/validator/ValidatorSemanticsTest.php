<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;
use Propulsion\Map\ValidatorMap;
use Propulsion\Validator\MatchValidator;
use Propulsion\Validator\MaxLengthValidator;
use Propulsion\Validator\MaxValueValidator;
use Propulsion\Validator\MinLengthValidator;
use Propulsion\Validator\MinValueValidator;
use Propulsion\Validator\NotMatchValidator;
use Propulsion\Validator\RequiredValidator;
use Propulsion\Validator\TypeValidator;
use Propulsion\Validator\ValidValuesValidator;

/**
 * Each validator on its own, against the values that decide whether a schema
 * author's `<validator>` rule does what they think it does.
 *
 * ValidatorTest already drives validation end to end through doValidate(), so
 * this is not about whether the plumbing works. It is about the individual
 * rules' semantics, several of which are surprising -- and several of which
 * were at 100% *line* coverage with nothing actually asserting them, because
 * one call with one value executes every line of a two-line method while
 * pinning none of its behaviour. Two validators (TypeValidator,
 * NotMatchValidator) had no coverage at all.
 *
 * Where a rule behaves in a way a schema author would not expect, the test
 * says so rather than quietly encoding it: those are the ones worth knowing
 * about before relying on them.
 */
class ValidatorSemanticsTest extends TestCase
{
    private function map(?string $value): ValidatorMap
    {
        $table = (new DatabaseMap('validator_semantics_test'))->addTable('t');
        $column = $table->addColumn('COL', 'Col', 'VARCHAR', false, 255);
        $this->assertInstanceOf(ColumnMap::class, $column);

        $map = new ValidatorMap($column);
        if ($value !== null) {
            $map->setValue($value);
        }

        return $map;
    }

    // ---- TypeValidator ----------------------------------------------------

    /**
     * @dataProvider typeCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('typeCases')]
    public function testTypeValidatorAcceptsOnlyTheNamedType(string $type, mixed $value, bool $expected)
    {
        // Data providers are static, so a closure fixture is substituted here.
        if ($value === 'THE_CLOSURE') {
            $value = static fn (string $s): string => $s;
        }

        $this->assertSame($expected, (new TypeValidator())->isValid($this->map($type), $value));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: bool}>
     */
    public static function typeCases(): array
    {
        return array(
            'array yes'          => array('array', array(1), true),
            'array no'           => array('array', 'x', false),
            'bool yes'           => array('bool', false, true),
            'boolean alias'      => array('boolean', true, true),
            'bool rejects 0'     => array('bool', 0, false),
            'float yes'          => array('float', 1.5, true),
            'float rejects int'  => array('float', 1, false),
            'int yes'            => array('int', 3, true),
            'integer alias'      => array('integer', 3, true),
            // The distinction schema authors trip over: a form field arrives
            // as a string, so `int` rejects it while `numeric` accepts it.
            'int rejects "3"'    => array('int', '3', false),
            'numeric accepts "3"'=> array('numeric', '3', true),
            'numeric accepts 3.5'=> array('numeric', 3.5, true),
            'numeric rejects "x"'=> array('numeric', 'x', false),
            'object yes'         => array('object', new \stdClass(), true),
            'object rejects array' => array('object', array(), false),
            'scalar yes'         => array('scalar', 'x', true),
            'scalar rejects array' => array('scalar', array(), false),
            'scalar rejects null'  => array('scalar', null, false),
            'string yes'         => array('string', 'x', true),
            'string rejects int' => array('string', 1, false),
            // 'function' means "names a callable function", not "is a closure".
            'function yes'       => array('function', 'strlen', true),
            'function rejects unknown name' => array('function', 'no_such_function_here', false),
            // Deliberately not "is callable": a Closure is rejected, because
            // the check is is_string() && function_exists().
            'function rejects a closure object' => array('function', 'THE_CLOSURE', false),
        );
    }

    public function testTypeValidatorAcceptsAResource()
    {
        $handle = fopen('php://memory', 'r');
        $this->assertNotFalse($handle);
        try {
            $this->assertTrue((new TypeValidator())->isValid($this->map('resource'), $handle));
            $this->assertFalse((new TypeValidator())->isValid($this->map('resource'), 'x'));
        } finally {
            fclose($handle);
        }
    }

    public function testTypeValidatorRejectsAnUnknownTypeName()
    {
        // A typo in the schema is a configuration error, not a validation
        // failure -- reporting it as "this value is invalid" would send the
        // author looking at their data instead of their schema.
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('nteger');
        (new TypeValidator())->isValid($this->map('nteger'), 1);
    }

    // ---- MatchValidator / NotMatchValidator -------------------------------

    public function testMatchValidatorAcceptsADelimitedPattern()
    {
        $validator = new MatchValidator();
        $this->assertTrue($validator->isValid($this->map('/^[a-z]+$/'), 'abc'));
        $this->assertFalse($validator->isValid($this->map('/^[a-z]+$/'), 'abc1'));
    }

    public function testMatchValidatorAddsMissingDelimiters()
    {
        // Schemas in the wild write both forms; an undelimited pattern must
        // not be handed to preg_match() raw, which would be a warning and a
        // false result rather than a match.
        $validator = new MatchValidator();
        $this->assertTrue($validator->isValid($this->map('^[a-z]+$'), 'abc'));
        $this->assertFalse($validator->isValid($this->map('^[a-z]+$'), 'ABC'));
    }

    public function testNotMatchValidatorIsTheExactInverse()
    {
        $validator = new NotMatchValidator();
        $this->assertFalse($validator->isValid($this->map('/[0-9]/'), 'abc1'));
        $this->assertTrue($validator->isValid($this->map('/[0-9]/'), 'abc'));
    }

    public function testNotMatchValidatorAlsoAddsMissingDelimiters()
    {
        $validator = new NotMatchValidator();
        $this->assertTrue($validator->isValid($this->map('[0-9]'), 'abc'));
        $this->assertFalse($validator->isValid($this->map('[0-9]'), 'abc1'));
    }

    public function testMatchValidatorsRequireAPattern()
    {
        // Without one, prepareRegexp() would index into a null string.
        foreach (array(new MatchValidator(), new NotMatchValidator()) as $validator) {
            try {
                $validator->isValid($this->map(null), 'anything');
                $this->fail(get_class($validator) . ' should require a "value" attribute');
            } catch (PropulsionException $e) {
                $this->assertStringContainsString('requires a "value" attribute', $e->getMessage());
            }
        }
    }

    // ---- ValidValuesValidator ---------------------------------------------

    public function testValidValuesAcceptsEitherSeparator()
    {
        $validator = new ValidValuesValidator();

        $this->assertTrue($validator->isValid($this->map('a,b,c'), 'b'));
        $this->assertTrue($validator->isValid($this->map('a|b|c'), 'b'));
        $this->assertTrue($validator->isValid($this->map('a,b|c'), 'c'), 'both separators in one list');
        $this->assertFalse($validator->isValid($this->map('a,b,c'), 'd'));
    }

    public function testValidValuesDoesNotTrimAroundSeparators()
    {
        // Worth knowing before writing "draft, live" in a schema: the space
        // is part of the value, so "live" would not match.
        $validator = new ValidValuesValidator();

        $this->assertFalse($validator->isValid($this->map('draft, live'), 'live'));
        $this->assertTrue($validator->isValid($this->map('draft, live'), ' live'));
    }

    public function testValidValuesRequiresAList()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('requires a "value" attribute');
        (new ValidValuesValidator())->isValid($this->map(null), 'a');
    }

    // ---- length validators ------------------------------------------------

    public function testLengthValidatorsCountCharactersNotBytes()
    {
        // 'héllo' is 5 characters but 6 bytes in UTF-8. Counting bytes would
        // reject a name that fits the column, which is the bug mb_strlen()
        // is there to avoid -- and nothing asserted it.
        $this->assertSame(5, mb_strlen('héllo'), 'guard: the fixture really is 5 chars');
        $this->assertSame(6, strlen('héllo'), 'guard: and 6 bytes');

        $this->assertTrue((new MaxLengthValidator())->isValid($this->map('5'), 'héllo'));
        $this->assertTrue((new MinLengthValidator())->isValid($this->map('5'), 'héllo'));
    }

    public function testMaxLengthIsInclusive()
    {
        $validator = new MaxLengthValidator();
        $this->assertTrue($validator->isValid($this->map('3'), 'abc'));
        $this->assertFalse($validator->isValid($this->map('3'), 'abcd'));
        $this->assertTrue($validator->isValid($this->map('3'), ''));
    }

    public function testMinLengthIsInclusive()
    {
        $validator = new MinLengthValidator();
        $this->assertTrue($validator->isValid($this->map('3'), 'abc'));
        $this->assertFalse($validator->isValid($this->map('3'), 'ab'));
        $this->assertFalse($validator->isValid($this->map('1'), ''));
    }

    // ---- value validators -------------------------------------------------

    public function testValueValidatorsAreInclusive()
    {
        $this->assertTrue((new MaxValueValidator())->isValid($this->map('10'), 10));
        $this->assertFalse((new MaxValueValidator())->isValid($this->map('10'), 11));
        $this->assertTrue((new MinValueValidator())->isValid($this->map('10'), 10));
        $this->assertFalse((new MinValueValidator())->isValid($this->map('10'), 9));
    }

    public function testValueValidatorsTruncateFractionsRatherThanComparingThem()
    {
        // Documented here because it is a trap, not because it is desirable:
        // both validators run the value through intval(), so 10.9 compares as
        // 10 and passes a max of 10. A `max-value` rule on a DECIMAL column
        // therefore does not do what its name suggests.
        $this->assertTrue(
            (new MaxValueValidator())->isValid($this->map('10'), 10.9),
            'intval() truncation: 10.9 is accepted by a max of 10'
        );
        $this->assertTrue(
            (new MinValueValidator())->isValid($this->map('10'), 10.9),
            'and 10.9 satisfies a min of 10, which is at least the right answer'
        );
        $this->assertFalse((new MinValueValidator())->isValid($this->map('10'), 9.9));
    }

    public function testValueValidatorsRejectNonNumericAndNull()
    {
        // Note the asymmetry with the length validators: a null here *fails*
        // rather than being skipped, so a max-value rule on a nullable column
        // rejects every empty value unless the column is also required.
        foreach (array(new MaxValueValidator(), new MinValueValidator()) as $validator) {
            $this->assertFalse($validator->isValid($this->map('10'), null), get_class($validator));
            $this->assertFalse($validator->isValid($this->map('10'), 'abc'), get_class($validator));
            $this->assertFalse($validator->isValid($this->map('10'), ''), get_class($validator));
        }
    }

    public function testValueValidatorsAcceptNumericStrings()
    {
        $this->assertTrue((new MaxValueValidator())->isValid($this->map('10'), '5'));
        $this->assertTrue((new MinValueValidator())->isValid($this->map('10'), '15'));
    }

    // ---- RequiredValidator ------------------------------------------------

    public function testRequiredRejectsOnlyNullAndTheEmptyString()
    {
        $validator = new RequiredValidator();

        $this->assertFalse($validator->isValid($this->map(null), null));
        $this->assertFalse($validator->isValid($this->map(null), ''));

        // Everything else counts as present -- including the values a naive
        // empty() check would reject, which is the point: "0" is a real answer
        // to a required question.
        $this->assertTrue($validator->isValid($this->map(null), '0'));
        $this->assertTrue($validator->isValid($this->map(null), 0));
        $this->assertTrue($validator->isValid($this->map(null), false));
        $this->assertTrue($validator->isValid($this->map(null), ' '), 'whitespace is not trimmed');
        $this->assertTrue($validator->isValid($this->map(null), array()));
    }
}
