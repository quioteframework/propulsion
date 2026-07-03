<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Test class for PropelOnDemandIterator.
 *
 * @author     Francois Zaninotto
 * @version    $Id: PropelObjectCollectionTest.php 1348 2009-12-03 21:49:00Z francois $
 * @package    runtime.collection
 */
class PropelOnDemandIteratorTest extends BookstoreEmptyTestBase
{
	protected function setUp(): void
	{
		parent::setUp();
		BookstoreDataPopulator::populate($this->con);
	}

	public function testInstancePoolingDisabled()
	{
		Propulsion::enableInstancePooling();
		$books = PropelQuery::from('Book')
			->setFormatter(ModelCriteria::FORMAT_ON_DEMAND)
			->find($this->con);
		foreach ($books as $book) {
			$this->assertFalse(Propulsion::isInstancePoolingEnabled());
		}
	}

	public function testInstancePoolingReenabled()
	{
		Propulsion::enableInstancePooling();
		$books = PropelQuery::from('Book')
			->setFormatter(ModelCriteria::FORMAT_ON_DEMAND)
			->find($this->con);
		foreach ($books as $book) {
		}
		$this->assertTrue(Propulsion::isInstancePoolingEnabled());

		Propulsion::disableInstancePooling();
		$books = PropelQuery::from('Book')
			->setFormatter(ModelCriteria::FORMAT_ON_DEMAND)
			->find($this->con);
		foreach ($books as $book) {
		}
		$this->assertFalse(Propulsion::isInstancePoolingEnabled());
		Propulsion::enableInstancePooling();
	}

}