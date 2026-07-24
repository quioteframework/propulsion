<?php

use PHPUnit\Framework\TestCase;
/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Verifies the TableMap metadata generated for the `book2` Bookstore fixture
 * table's JSON/JSONB columns (test/fixtures/bookstore/schema.xml). Like
 * CriteriaTest, this only needs the Bookstore fixture *classes* -- generated
 * unconditionally by IntegrationDatabase::ensureClassesGenerated() in
 * bootstrap.php, pure schema-XML-to-PHP codegen -- not a live DB/Docker, so it
 * extends TestCase directly rather than BookstoreTestBase and runs the same
 * with or without Docker.
 */
class Book2JsonColumnTableMapTest extends TestCase
{
	protected TableMap $tableMap;

	public function setUp(): void
	{
		if (!class_exists('Book2TableMap')) {
			$this->markTestSkipped('Bookstore fixture classes are not available: ' . IntegrationDatabase::classesDir());
		}
		$this->tableMap = Book2Peer::getTableMap();
	}

	public function testMetadataColumnIsMappedAsJson()
	{
		$column = $this->tableMap->getColumn('metadata');
		$this->assertSame('JSON', $column->getType());
		$this->assertFalse($column->isNotNull());
	}

	public function testMetadataBinaryColumnIsMappedAsJsonb()
	{
		$column = $this->tableMap->getColumn('metadata_binary');
		$this->assertSame('JSONB', $column->getType());
		$this->assertFalse($column->isNotNull());
	}

	public function testGeneratedColumnConstants()
	{
		$this->assertSame('book2.METADATA', Book2Peer::METADATA);
		$this->assertSame('book2.METADATA_BINARY', Book2Peer::METADATA_BINARY);
	}

	public function testGeneratedAccessorsExist()
	{
		$this->assertTrue(method_exists('Book2', 'getMetadata'));
		$this->assertTrue(method_exists('Book2', 'setMetadata'));
		$this->assertTrue(method_exists('Book2', 'getMetadataBinary'));
		$this->assertTrue(method_exists('Book2', 'setMetadataBinary'));
	}
}
