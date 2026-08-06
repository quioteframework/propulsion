<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Query\ModelCriteria;
use Propulsion\Util\PropulsionModelPager;

/**
 * The navigation half of {@see PropulsionModelPager}: the link window, the
 * "showing X to Y of Z" indices, next/previous clamping, the record cap, and
 * what happens when the pager is misused.
 *
 * PropulsionModelPagerTest covers the counting and iteration side. This covers
 * the arithmetic a template actually calls, which is where paginators
 * traditionally go wrong: off-by-one at the boundaries, a link window that
 * slides past the last page, an index range that claims more rows than exist.
 */
class PropulsionModelPagerNavigationTest extends BookstoreEmptyTestBase
{
	private function createBooks(int $nb): void
	{
		BookQuery::create()->deleteAll();
		$books = new PropulsionObjectCollection();
		$books->setModel('Book');
		for ($i = 0; $i < $nb; $i++) {
			$b = new Book();
			$b->setTitle('Book' . $i);
			$b->setISBN('nav-' . $i);
			$books[] = $b;
		}
		$books->save();
	}

	private function pager(int $maxPerPage, int $page = 1): PropulsionModelPager
	{
		$pager = new PropulsionModelPager(BookQuery::create(), $maxPerPage);
		$pager->setPage($page);
		$pager->init();

		return $pager;
	}

	// ---- the link window --------------------------------------------------

	public function testLinksAreCenteredOnTheCurrentPage()
	{
		$this->createBooks(50);   // 10 pages of 5
		$pager = $this->pager(5, 5);

		$this->assertSame(array(3, 4, 5, 6, 7), $pager->getLinks(5), 'the current page sits in the middle');
		$this->assertSame(7, $pager->getCurrentMaxLink(), 'getCurrentMaxLink() reports the last link returned');
	}

	public function testLinksDoNotRunOffTheStart()
	{
		$this->createBooks(50);
		$pager = $this->pager(5, 1);

		// Centering would want pages -1..3; the window is clamped to start at 1
		// rather than emitting page numbers that do not exist.
		$this->assertSame(array(1, 2, 3, 4, 5), $pager->getLinks(5));
	}

	public function testLinksDoNotRunOffTheEnd()
	{
		$this->createBooks(50);
		$pager = $this->pager(5, 10);

		// Centering would want 8..12, but there are only 10 pages: the window
		// slides back so it still shows five real pages.
		$this->assertSame(array(6, 7, 8, 9, 10), $pager->getLinks(5));
		$this->assertSame(10, $pager->getCurrentMaxLink());
	}

	public function testLinkWindowShrinksWhenThereAreFewerPagesThanLinks()
	{
		$this->createBooks(12);   // 3 pages of 5
		$pager = $this->pager(5, 2);

		$this->assertSame(array(1, 2, 3), $pager->getLinks(5), 'never more links than pages');
	}

	public function testLinksOnAnEmptyPagerAreEmpty()
	{
		BookQuery::create()->deleteAll();
		$pager = $this->pager(5, 1);

		$this->assertSame(array(), $pager->getLinks(5));
		$this->assertSame(1, $pager->getCurrentMaxLink(), 'with no links at all, the max link falls back to 1');
	}

	public function testLinkWindowSizeIsHonoured()
	{
		$this->createBooks(50);
		$pager = $this->pager(5, 5);

		$this->assertCount(3, $pager->getLinks(3));
		$this->assertCount(9, $pager->getLinks(9));
	}

	// ---- "showing X to Y of Z" -------------------------------------------

	public function testIndicesOnAFullPage()
	{
		$this->createBooks(50);
		$pager = $this->pager(5, 3);

		$this->assertSame(11, $pager->getFirstIndex());
		$this->assertSame(15, $pager->getLastIndex());
	}

	public function testLastIndexIsClampedToTheNumberOfResults()
	{
		// 12 books, 5 per page: the last page holds 2, not 5. Reporting 15
		// here is the classic paginator bug this asserts against.
		$this->createBooks(12);
		$pager = $this->pager(5, 3);

		$this->assertSame(11, $pager->getFirstIndex());
		$this->assertSame(12, $pager->getLastIndex());
	}

	public function testIndicesWhenPaginationIsDisabled()
	{
		// maxPerPage 0 means "no pagination": one notional page holding
		// everything, so the range is the whole result set.
		$this->createBooks(12);
		$pager = $this->pager(0);

		$this->assertSame(0, $pager->getPage());
		$this->assertSame(1, $pager->getFirstIndex());
		$this->assertSame(12, $pager->getLastIndex());
	}

	// ---- next / previous --------------------------------------------------

	public function testNextAndPreviousPageClampAtTheEnds()
	{
		$this->createBooks(12);   // 3 pages of 5

		$first = $this->pager(5, 1);
		$this->assertSame(1, $first->getPreviousPage(), 'previous never goes below the first page');
		$this->assertSame(2, $first->getNextPage());

		$last = $this->pager(5, 3);
		$this->assertSame(3, $last->getNextPage(), 'next never goes past the last page');
		$this->assertSame(2, $last->getPreviousPage());
	}

	// ---- the record cap ---------------------------------------------------

	public function testMaxRecordLimitCapsTheReportedResultCount()
	{
		$this->createBooks(50);

		$pager = new PropulsionModelPager(BookQuery::create(), 5);
		$pager->setMaxRecordLimit(12);
		$pager->setPage(1);
		$pager->init();

		$this->assertSame(12, $pager->getMaxRecordLimit());
		$this->assertSame(12, $pager->getNbResults(), 'the cap, not the 50 rows that exist');
		$this->assertSame(3, $pager->getLastPage());
		$this->assertCount(5, $pager->getResults(), 'a full page is still a full page');
	}

	public function testMaxRecordLimitTruncatesTheLastPage()
	{
		// Page 3 of a 12-record cap starts at offset 10, so only 2 of the 5
		// rows that page would otherwise hold are within the cap.
		$this->createBooks(50);

		$pager = new PropulsionModelPager(BookQuery::create(), 5);
		$pager->setMaxRecordLimit(12);
		$pager->setPage(3);
		$pager->init();

		$this->assertCount(2, $pager->getResults(), 'the cap cuts the page short');
	}

	public function testWithoutAMaxRecordLimitEveryRowCounts()
	{
		$this->createBooks(50);
		$pager = $this->pager(5, 1);

		$this->assertFalse($pager->getMaxRecordLimit(), 'false is the "no cap" value');
		$this->assertSame(50, $pager->getNbResults());
	}

	// ---- misuse -----------------------------------------------------------

	public function testANegativeMaxPerPageIsNormalisedToOne()
	{
		$this->createBooks(12);

		$pager = new PropulsionModelPager(BookQuery::create(), 5);
		$pager->setMaxPerPage(-3);
		$pager->init();

		$this->assertSame(1, $pager->getMaxPerPage(), 'a negative page size would otherwise produce a negative LIMIT');
		$this->assertSame(1, $pager->getPage());
		$this->assertSame(12, $pager->getLastPage());
	}

	public function testAZeroMaxPerPageDisablesPagination()
	{
		$this->createBooks(12);

		$pager = new PropulsionModelPager(BookQuery::create(), 5);
		$pager->setMaxPerPage(0);
		$pager->init();

		$this->assertSame(0, $pager->getMaxPerPage());
		$this->assertSame(0, $pager->getPage());
		$this->assertSame(0, $pager->getLastPage());
		$this->assertCount(12, $pager->getResults(), 'every row, unpaginated');
	}

	public function testSettingAPageSizeAfterDisablingPaginationRestoresPageOne()
	{
		$pager = new PropulsionModelPager(BookQuery::create(), 0);
		$this->assertSame(0, $pager->getPage());

		$pager->setMaxPerPage(5);
		$this->assertSame(1, $pager->getPage(), 'page 0 is only meaningful while unpaginated');
	}

	public function testGetQueryReturnsTheQueryItWasBuiltWith()
	{
		$query = BookQuery::create();
		$pager = new PropulsionModelPager($query, 5);

		$this->assertInstanceOf(ModelCriteria::class, $pager->getQuery());
		$this->assertSame($query, $pager->getQuery());
	}
}
