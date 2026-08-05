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
use Propulsion\Query\GlobalQueryFilters;
use Propulsion\Query\ModelCriteria;

/**
 * The registry in isolation. Its behaviour *through a query* is
 * GlobalQueryFilterTest's job, live against the fixture; this covers the
 * bookkeeping, which needs no database and so runs in the no-Docker tier too.
 */
class GlobalQueryFiltersRegistryTest extends TestCase
{
	private GlobalQueryFilters $filters;

	protected function setUp(): void
	{
		parent::setUp();
		$this->filters = new GlobalQueryFilters();
	}

	private function noop(): callable
	{
		return static function (ModelCriteria $q): void {};
	}

	public function testStartsEmpty()
	{
		$this->assertTrue($this->filters->isEmpty());
		$this->assertSame(array(), $this->filters->forModel('Book'));
		$this->assertSame(array(), $this->filters->names('Book'));
	}

	public function testAddAndList()
	{
		$this->filters->add('Book', 'a', $this->noop());
		$this->filters->add('Book', 'b', $this->noop());
		$this->filters->add('Author', 'a', $this->noop());

		$this->assertFalse($this->filters->isEmpty());
		$this->assertSame(array('a', 'b'), $this->filters->names('Book'));
		$this->assertSame(array('a'), $this->filters->names('Author'));
	}

	public function testAnEmptyNameIsRejected()
	{
		// Without a name a query could not opt this filter out individually,
		// which is the difference between dropping one filter and dropping
		// the tenancy filter along with it.
		$this->expectException(PropulsionException::class);
		$this->expectExceptionMessage('non-empty name');
		$this->filters->add('Book', '', $this->noop());
	}

	public function testRemovingTheLastFilterEmptiesTheRegistry()
	{
		$this->filters->add('Book', 'a', $this->noop());
		$this->filters->remove('Book', 'a');

		$this->assertTrue($this->filters->isEmpty(), 'no empty per-model bucket must linger');
	}

	public function testRemovingSomethingUnregisteredIsANoOp()
	{
		$this->filters->remove('Book', 'nope');
		$this->filters->add('Book', 'a', $this->noop());
		$this->filters->remove('Author', 'a');

		$this->assertSame(array('a'), $this->filters->names('Book'));
	}

	public function testClearPerModelAndGlobally()
	{
		$this->filters->add('Book', 'a', $this->noop());
		$this->filters->add('Author', 'a', $this->noop());

		$this->filters->clear('Book');
		$this->assertSame(array(), $this->filters->names('Book'));
		$this->assertSame(array('a'), $this->filters->names('Author'));

		$this->filters->clear();
		$this->assertTrue($this->filters->isEmpty());
	}
}
