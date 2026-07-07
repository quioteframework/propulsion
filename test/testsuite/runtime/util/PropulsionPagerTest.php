<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test the utility class PropulsionPager
 *
 * @author     Niklas Närhinen <niklas@narhinen.net>
 * @version    $Id: PropulsionPagerTest.php
 */
class PropulsionPagerTest extends BookstoreEmptyTestBase
{
  private $authorId;
  private $books;

  protected function setUp(): void
  {
    parent::setUp();
		BookstoreDataPopulator::populate();

    $cr = new Criteria();
    $cr->add(AuthorPeer::LAST_NAME, "Rowling");
    $cr->add(AuthorPeer::FIRST_NAME, "J.K.");
    $rowling = AuthorPeer::doSelectOne($cr);
    $this->authorId = $rowling->getId();

    $book = new Book();
    $book->setTitle("Harry Potter and the Philosopher's Stone");
    $book->setISBN("1234");
    $book->setAuthor($rowling);
    $book->save();
    $this->books[] = $book->getId();

    $book = new Book();
    $book->setTitle("Harry Potter and the Chamber of Secrets");
    $book->setISBN("1234");
    $book->setAuthor($rowling);
    $book->save();
    $this->books[] = $book->getId();

    $book = new Book();
    $book->setTitle("Harry Potter and the Prisoner of Azkaban");
    $book->setISBN("1234");
    $book->setAuthor($rowling);
    $book->save();
    $this->books[] = $book->getId();

    $book = new Book();
    $book->setTitle("Harry Potter and the Goblet of Fire");
    $book->setISBN("1234");
    $book->setAuthor($rowling);
    $book->save();
    $this->books[] = $book->getId();

    $book = new Book();
    $book->setTitle("Harry Potter and the Half-Blood Prince");
    $book->setISBN("1234");
    $book->setAuthor($rowling);
    $book->save();
    $this->books[] = $book->getId();

    $book = new Book();
    $book->setTitle("Harry Potter and the Deathly Hallows");
    $book->setISBN("1234");
    $book->setAuthor($rowling);
    $book->save();
    $this->books[] = $book->getId();
  }

  protected function tearDown(): void
  {
    parent::tearDown();
    // parent::tearDown() (BookstoreTestBase) always runs, even when setUp() bailed
    // out early via markTestSkipped() (no Docker/PROPULSION_SKIP_INTEGRATION=1) --
    // in which case $this->books was never populated and there's nothing in the
    // (unreachable, no live conf) database to clean up.
    if (!$this->con || empty($this->books)) {
      return;
    }
    $cr = new Criteria();
    $cr->add(BookPeer::ID, $this->books, Criteria::IN);
    BookPeer::doDelete($cr);
  }

  public function testCountNoPageNoLimit()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect");
    $this->assertEquals(7, count($pager));
  }

  public function testCountFirstPageWithLimits()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect", 1, 5);
    $this->assertEquals(5, count($pager));
  }

  public function testCountLastPageWithLimits()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect", 2, 5);
    $this->assertEquals(2, count($pager));
  }

  public function testIterateAll()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect");
    $i = 0;
    foreach ($pager as $key => $book) {
      $i++;
    }
    $this->assertEquals(7, $i);
  }

  public function testIterateWithLimits()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect", 2, 5);
    $i = 0;
    foreach ($pager as $key => $book) {
      $i++;
    }
    $this->assertEquals(2, $i);
  }

  public function testIterateCheckSecond()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $cr->addAscendingOrderByColumn(BookPeer::TITLE);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect");
    $books = array();
    foreach($pager as $book) {
      $books[] = $book;
    }
    $this->assertEquals("Harry Potter and the Goblet of Fire", $books[2]->getTitle());
  }

  public function testIterateTwice()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $cr->addAscendingOrderByColumn(BookPeer::TITLE);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect");
    $i = 0;
    foreach($pager as $book) {
      $i++;
    }
    foreach($pager as $book) {
      $i++;
    }
    $this->assertEquals(14, $i);
  }

  private function makePager($page, $rowsPerPage)
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $cr->addAscendingOrderByColumn(BookPeer::TITLE);
    return new PropulsionPager($cr, "BookPeer", "doSelect", $page, $rowsPerPage);
  }

  public function testGettersAndSetters()
  {
    $pager = $this->makePager(1, 2);
    $cr = new Criteria();
    $pager->setCriteria($cr);
    $this->assertSame($cr, $pager->getCriteria());

    $pager->setPeerClass('AuthorPeer');
    $this->assertEquals('AuthorPeer', $pager->getPeerClass());

    $pager->setPeerSelectMethod('doSelectJoinFoo');
    $this->assertEquals('doSelectJoinFoo', $pager->getPeerSelectMethod());
    $this->assertEquals('doSelectJoinFoo', $pager->getPeerMethod());

    $pager->setPeerMethod('doSelect');
    $this->assertEquals('doSelect', $pager->getPeerSelectMethod());

    $pager->setPeerCountMethod('doCountFoo');
    $this->assertEquals('doCountFoo', $pager->getPeerCountMethod());

    $pager->setRowsPerPage(3);
    $this->assertEquals(3, $pager->getRowsPerPage());
  }

  public function testGuessesJoinCountMethodFromSelectMethod()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, $this->authorId);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelectJoinAuthor");
    $this->assertEquals('doCountJoinAuthor', $pager->getPeerCountMethod());
  }

  public function testFirstPageIsAlwaysOne()
  {
    $pager = $this->makePager(2, 2);
    $this->assertEquals(1, $pager->getFirstPage());
  }

  public function testAtFirstAndLastPage()
  {
    // 7 books (6 added here + 1 from BookstoreDataPopulator::populate()), 2 per page => 4 pages
    $pager = $this->makePager(1, 2);
    $this->assertTrue($pager->atFirstPage());
    $this->assertFalse($pager->atLastPage());

    $pager = $this->makePager(4, 2);
    $this->assertFalse($pager->atFirstPage());
    $this->assertTrue($pager->atLastPage());
  }

  public function testGetTotalPagesAndLastPage()
  {
    $pager = $this->makePager(1, 2);
    $this->assertEquals(4, $pager->getTotalPages());
    $this->assertEquals(4, $pager->getLastPage());
  }

  public function testGetLastPageIsOneWhenNoResults()
  {
    $cr = new Criteria();
    $cr->add(BookPeer::AUTHOR_ID, -1);
    $pager = new PropulsionPager($cr, "BookPeer", "doSelect", 1, 5);
    $this->assertEquals(1, $pager->getLastPage());
  }

  public function testGetPrevAndNext()
  {
    $pager = $this->makePager(1, 2);
    $this->assertFalse($pager->getPrev());
    $this->assertEquals(2, $pager->getNext());

    $pager = $this->makePager(2, 2);
    $this->assertEquals(1, $pager->getPrev());
    $this->assertEquals(3, $pager->getNext());

    $pager = $this->makePager(4, 2);
    $this->assertEquals(3, $pager->getPrev());
    $this->assertFalse($pager->getNext());
  }

  public function testGetPrevAndNextLinks()
  {
    // 7 books, 1 per page => 7 pages
    $pager = $this->makePager(3, 1);
    $this->assertEquals(array(1, 2), $pager->getPrevLinks());
    $this->assertEquals(array(4, 5, 6, 7), $pager->getNextLinks());
  }

  public function testGetPrevLinksRespectsRange()
  {
    $pager = $this->makePager(6, 1);
    $this->assertEquals(array(4, 5), $pager->getPrevLinks(3));
  }

  public function testIsLastPageComplete()
  {
    // 7 books, 7 per page => last (and only) page has exactly 7, so it's complete
    $pager = $this->makePager(1, 7);
    $this->assertTrue($pager->isLastPageComplete());

    // 7 books, 2 per page => last page has 1 of 2, so it's not complete
    $pager = $this->makePager(4, 2);
    $this->assertFalse($pager->isLastPageComplete());
  }
}
