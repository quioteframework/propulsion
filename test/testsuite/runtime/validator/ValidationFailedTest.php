<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * Test class for ValidationFailed.
 */
class ValidationFailedTest extends TestCase
{
    public function testConstructorSetsFields()
    {
        $validator = new MinLengthValidator();
        $failure = new ValidationFailed('book.TITLE', 'too short', $validator);

        $this->assertSame('book.TITLE', $failure->getColumn());
        $this->assertSame('too short', $failure->getMessage());
        $this->assertSame($validator, $failure->getValidator());
    }

    public function testConstructorDefaultsValidatorToNull()
    {
        $failure = new ValidationFailed('book.TITLE', 'too short');
        $this->assertNull($failure->getValidator());
    }

    public function testSetters()
    {
        $failure = new ValidationFailed('book.TITLE', 'too short');
        $failure->setColumn('book.ISBN');
        $failure->setMessage('invalid ISBN');
        $validator = new RequiredValidator();
        $failure->setValidator($validator);

        $this->assertSame('book.ISBN', $failure->getColumn());
        $this->assertSame('invalid ISBN', $failure->getMessage());
        $this->assertSame($validator, $failure->getValidator());
    }

    public function testSetValidatorAcceptsNull()
    {
        $failure = new ValidationFailed('book.TITLE', 'too short', new RequiredValidator());
        $failure->setValidator(null);
        $this->assertNull($failure->getValidator());
    }

    public function testToStringReturnsMessage()
    {
        $failure = new ValidationFailed('book.TITLE', 'too short');
        $this->assertSame('too short', (string) $failure);
        $this->assertSame('too short', $failure->__toString());
    }
}
