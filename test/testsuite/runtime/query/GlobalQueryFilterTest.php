<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Propulsion;
use Propulsion\Query\ModelCriteria;

/**
 * Global query filters: predicates applied to every query on a model unless
 * the query opts out.
 *
 * Live against the bookstore fixture rather than asserted as SQL strings,
 * because the properties that matter are behavioural -- that a filtered
 * DELETE really cannot reach an excluded row, that opting one filter out
 * leaves the others in place, and that running the same query twice does not
 * apply its filters twice.
 */
class GlobalQueryFilterTest extends BookstoreTestBase
{
	protected function setUp(): void
	{
		parent::setUp();

		Propulsion::clearGlobalQueryFilters();

		BookQuery::create()->deleteAll();
		AuthorQuery::create()->deleteAll();

		foreach (array('Kept' => 100, 'Hidden' => 200, 'AlsoHidden' => 200) as $title => $price) {
			$book = new Book();
			$book->setTitle($title);
			$book->setISBN('isbn-' . $title);
			$book->setPrice($price);
			$book->save();
		}
	}

	protected function tearDown(): void
	{
		Propulsion::clearGlobalQueryFilters();
		parent::tearDown();
	}

	/** "Soft delete": treat price 200 as hidden. Arbitrary, and the point -- a filter is just a predicate. */
	private function hideExpensive(string $name = 'not-hidden'): void
	{
		Propulsion::addGlobalQueryFilter('Book', $name, function (ModelCriteria $q) {
			$q->filterByPrice(200, \Propulsion\Query\Criteria::NOT_EQUAL);
		});
	}

	public function testNoFiltersRegisteredChangesNothing()
	{
		$this->assertCount(3, BookQuery::create()->find());
	}

	public function testAFilterNarrowsFind()
	{
		$this->hideExpensive();

		$books = BookQuery::create()->find();
		$this->assertCount(1, $books);
		$this->assertSame('Kept', $books[0]->getTitle());
	}

	public function testAFilterNarrowsCount()
	{
		$this->hideExpensive();
		$this->assertSame(1, BookQuery::create()->count());
	}

	public function testAFilterNarrowsFindOne()
	{
		$this->hideExpensive();
		$this->assertNull(BookQuery::create()->filterByTitle('Hidden')->findOne());
		$this->assertNotNull(BookQuery::create()->filterByTitle('Kept')->findOne());
	}

	public function testAFilterNarrowsDelete()
	{
		// The property that makes this worth having: an unfiltered DELETE
		// would reach rows a filtered SELECT hides, which for a tenancy filter
		// is worse than merely showing them.
		$this->hideExpensive();

		$this->assertSame(0, BookQuery::create()->filterByTitle('Hidden')->delete());
		$this->assertNotNull(
			BookQuery::create()->withoutGlobalFilters()->filterByTitle('Hidden')->findOne(),
			'the row must still be there'
		);
	}

	public function testAFilterNarrowsUpdate()
	{
		$this->hideExpensive();

		$this->assertSame(0, BookQuery::create()->filterByTitle('Hidden')->update(array('ISBN' => 'changed')));
		$book = BookQuery::create()->withoutGlobalFilters()->filterByTitle('Hidden')->findOne();
		$this->assertNotNull($book);
		$this->assertSame('isbn-Hidden', $book->getISBN());
	}

	public function testWithoutGlobalFiltersSeesEverything()
	{
		$this->hideExpensive();
		$this->assertCount(3, BookQuery::create()->withoutGlobalFilters()->find());
	}

	public function testWithoutGlobalFilterDropsOnlyTheNamedOne()
	{
		$this->hideExpensive('not-hidden');
		Propulsion::addGlobalQueryFilter('Book', 'not-kept', function (ModelCriteria $q) {
			$q->filterByTitle('Kept', \Propulsion\Query\Criteria::NOT_EQUAL);
		});

		// Both on: nothing matches.
		$this->assertCount(0, BookQuery::create()->find());

		// Drop only the price one: 'Kept' is still excluded by the other.
		$books = BookQuery::create()->withoutGlobalFilter('not-hidden')->find();
		$this->assertCount(2, $books);
		foreach ($books as $book) {
			$this->assertNotSame('Kept', $book->getTitle());
		}
	}

	public function testWithoutAnUnregisteredFilterNameIsNotAnError()
	{
		$this->hideExpensive();
		$this->assertCount(1, BookQuery::create()->withoutGlobalFilter('no-such-filter')->find());
	}

	public function testFiltersAreNotAppliedTwiceOnAReusedQuery()
	{
		// keepQuery(false) makes the termination methods operate on the query
		// object itself rather than a clone, so a second find() would re-add
		// the same conditions without the applied-once guard. Counting is what
		// catches it: a duplicated NOT_EQUAL is still correct, so only the
		// query object's own condition count shows the stacking.
		$this->hideExpensive();

		$query = BookQuery::create()->keepQuery(false);
		$this->assertCount(1, $query->find());
		$conditionsAfterFirst = $query->size();
		$this->assertCount(1, $query->find());
		$this->assertSame($conditionsAfterFirst, $query->size(), 'the filter must not stack');
	}

	public function testFiltersOnAnotherModelDoNotLeak()
	{
		Propulsion::addGlobalQueryFilter('Author', 'never', function (ModelCriteria $q) {
			$q->filterByFirstName('nobody-has-this-name');
		});

		$this->assertCount(3, BookQuery::create()->find(), 'a filter on Author must not touch Book');
	}

	public function testReRegisteringTheSameNameReplacesRatherThanStacks()
	{
		Propulsion::addGlobalQueryFilter('Book', 'f', function (ModelCriteria $q) {
			$q->filterByTitle('Kept');
		});
		Propulsion::addGlobalQueryFilter('Book', 'f', function (ModelCriteria $q) {
			$q->filterByTitle('Hidden');
		});

		$books = BookQuery::create()->find();
		$this->assertCount(1, $books);
		$this->assertSame('Hidden', $books[0]->getTitle(), 'the second registration replaced the first');
	}

	public function testRemoveAndClear()
	{
		$this->hideExpensive();
		$this->assertSame(array('not-hidden'), Propulsion::getGlobalQueryFilters()->names('Book'));

		Propulsion::removeGlobalQueryFilter('Book', 'not-hidden');
		$this->assertSame(array(), Propulsion::getGlobalQueryFilters()->names('Book'));
		$this->assertCount(3, BookQuery::create()->find());

		$this->hideExpensive();
		Propulsion::clearGlobalQueryFilters('Book');
		$this->assertCount(3, BookQuery::create()->find());

		$this->hideExpensive();
		Propulsion::clearGlobalQueryFilters();
		$this->assertTrue(Propulsion::getGlobalQueryFilters()->isEmpty());
	}

	public function testAFilterCanReadStateThatChangesBetweenRuns()
	{
		// The multi-tenancy shape: the closure is registered once and reads
		// whatever the current request says each time it runs. This is why a
		// callable, and not a pre-built condition, is what gets registered.
		$title = 'Kept';
		Propulsion::addGlobalQueryFilter('Book', 'current', function (ModelCriteria $q) use (&$title) {
			$q->filterByTitle($title);
		});

		$this->assertSame('Kept', BookQuery::create()->findOne()?->getTitle());
		$title = 'Hidden';
		$this->assertSame('Hidden', BookQuery::create()->findOne()?->getTitle());
	}

	public function testDeleteAllIsDeliberatelyNotFiltered()
	{
		// deleteAll() is the explicit "empty this table" operation; it has no
		// WHERE clause for a predicate to narrow, and quietly leaving rows
		// behind would be the more surprising behaviour.
		$this->hideExpensive();
		$this->assertSame(3, BookQuery::create()->deleteAll());
		$this->assertCount(0, BookQuery::create()->withoutGlobalFilters()->find());
	}
}
