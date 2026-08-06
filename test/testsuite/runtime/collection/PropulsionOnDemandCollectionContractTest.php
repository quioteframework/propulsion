<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Collection\PropulsionOnDemandCollection;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Propulsion\Query\ModelCriteria;
use Propulsion\Query\PropulsionQuery;

/**
 * What a {@see PropulsionOnDemandCollection} refuses to do, and why that
 * matters.
 *
 * It extends PropulsionCollection (an ArrayObject) but holds a live cursor
 * rather than an array: rows arrive one at a time and are gone once passed.
 * Most of the inherited ArrayObject surface therefore cannot be honoured --
 * sorting needs every element at once, offset access needs random access,
 * serialization needs a value not a cursor -- and each of those methods throws
 * instead.
 *
 * Testing that is not box-ticking. The alternative to throwing is far worse
 * than a missing feature: `asort()` on a partially-consumed cursor would
 * silently sort whatever happened to have been read so far and look like it
 * worked, and `serialize()` would emit a collection that hydrates to nothing.
 * The refusals are the contract, and a regression that quietly makes one of
 * them "work" would surface as wrong data rather than an error. They are
 * asserted as a group, driven from one list, rather than as twenty
 * copy-pasted test methods.
 */
class PropulsionOnDemandCollectionContractTest extends BookstoreEmptyTestBase
{
	private ?PropulsionOnDemandCollection $books = null;

	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::populate($this->con);
		Propulsion::disableInstancePooling();

		$collection = PropulsionQuery::from('Book')
			->setFormatter(ModelCriteria::FORMAT_ON_DEMAND)
			->find();
		$this->assertInstanceOf(PropulsionOnDemandCollection::class, $collection);
		$this->books = $collection;
	}

	protected function tearDown(): void
	{
		// See PropulsionOnDemandCollectionTest::tearDown() -- several tests here
		// never fully consume the cursor, and pdo_dblib fails the *next* test
		// with "results pending" if it is left open.
		$this->books?->getIterator()->closeCursor();
		$this->books = null;
		parent::tearDown();
		Propulsion::enableInstancePooling();
	}

	/**
	 * Every method that cannot be honoured against a forward-only cursor, and
	 * the reason each is refused.
	 *
	 * @return array<string, array{0: string, 1: array<int, mixed>, 2: string}>
	 */
	public static function unsupportedOperations(): array
	{
		$readOnly = 'read only';
		$noOffset = 'does not allow acces by offset';

		return array(
			// Mutation: there is no backing array to mutate.
			'append'         => array('append', array('x'), $readOnly),
			'prepend'        => array('prepend', array('x'), $readOnly),
			'exchangeArray'  => array('exchangeArray', array(array()), $readOnly),
			// Sorting: needs every element in hand at once.
			'asort'          => array('asort', array(), $readOnly),
			'ksort'          => array('ksort', array(), $readOnly),
			'natcasesort'    => array('natcasesort', array(), $readOnly),
			'natsort'        => array('natsort', array(), $readOnly),
			'uasort'         => array('uasort', array('strcmp'), $readOnly),
			'uksort'         => array('uksort', array('strcmp'), $readOnly),
			// Random access: the cursor only goes forward.
			'getArrayCopy'   => array('getArrayCopy', array(), $noOffset),
			'getFlags'       => array('getFlags', array(), $noOffset),
			'setFlags'       => array('setFlags', array(0), $noOffset),
		);
	}

	/**
	 * @param array<int, mixed> $args
	 * @dataProvider unsupportedOperations
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('unsupportedOperations')]
	public function testUnsupportedOperationsThrowRatherThanSilentlyMisbehaving(string $method, array $args, string $expectedMessage)
	{
		$this->assertNotNull($this->books);

		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage($expectedMessage);
		$this->books->{$method}(...$args);
	}

	public function testItCannotBeSerialized()
	{
		// A cursor has no value to serialize, and PHP's serialize() would
		// otherwise emit something that hydrates to an empty collection.
		$this->assertNotNull($this->books);

		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('cannot be serialized');
		$this->books->serialize();
	}

	public function testItCannotBeUnserialized()
	{
		$this->assertNotNull($this->books);

		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('cannot be serialized');
		$this->books->unserialize('anything');
	}

	public function testItCannotBeExported()
	{
		// exportTo() walks the collection more than once (once to work out the
		// shape, once to write it), which a forward-only cursor cannot do.
		$this->assertNotNull($this->books);

		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('cannot be exported');
		$this->books->exportTo('XML');
	}

	public function testItStillIteratesAndHydratesRealObjects()
	{
		// The point of all the refusals above: what it *can* do is stream.
		$this->assertNotNull($this->books);

		$titles = array();
		foreach ($this->books as $book) {
			$this->assertInstanceOf(Book::class, $book);
			$titles[] = $book->getTitle();
		}

		$this->assertNotEmpty($titles);
		$this->assertSame(
			BookQuery::create()->count(),
			count($titles),
			'streaming must yield every row exactly once'
		);
	}
}
