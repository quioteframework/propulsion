<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Fixture-based test for StubBaseClassToGeneratedTraitRector: each
 * StubTraitFixture/*.php.inc file holds input code, a "-----" separator, then the
 * expected output. A fixture with no separator asserts the rule leaves it alone.
 */
class StubBaseClassToGeneratedTraitRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/StubTraitFixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/stub_trait_rule.php';
    }
}
