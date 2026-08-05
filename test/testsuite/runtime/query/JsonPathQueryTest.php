<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Propulsion;
use Propulsion\Query\Criteria;
use Propulsion\Query\JsonExpression;

/**
 * JSON path extraction against whichever database the run targets, using the
 * bookstore fixture's `book2.metadata` JSON column.
 *
 * String-shape coverage lives in JsonPathTest; what this adds is the part no
 * string assertion can give -- that the platform's own function actually
 * returns the value, and returns it *unquoted* for the text form, which is
 * the whole reason getJsonExtractSql() takes an $asText flag.
 */
class JsonPathQueryTest extends BookstoreTestBase
{
	protected function setUp(): void
	{
		parent::setUp();

		if (!Propulsion::getDB(Book2Peer::DATABASE_NAME)->supportsJsonPath()) {
			$this->markTestSkipped('This platform has no JSON path support.');
		}

		Book2Query::create()->deleteAll();

		$book = new Book2();
		$book->setTitle('Nested');
		$book->setMetadata(array(
			'author' => array('name' => 'Ursula', 'awards' => 3),
			'tags' => array('scifi', 'classic'),
		));
		$book->save();

		$other = new Book2();
		$other->setTitle('Other');
		$other->setMetadata(array('author' => array('name' => 'Someone Else', 'awards' => 0), 'tags' => array()));
		$other->save();
	}

	public function testWithColumnReadsANestedScalar()
	{
		$book = Book2Query::create()
			->withColumn(JsonExpression::text('Book2.Metadata', '$.author.name'), 'AuthorName')
			->filterByTitle('Nested')
			->findOne();

		$this->assertNotNull($book);
		$this->assertSame('Ursula', $book->getVirtualColumn('AuthorName'), 'the text form must come back unquoted');
	}

	public function testWithColumnReadsAnArrayElement()
	{
		$book = Book2Query::create()
			->withColumn(JsonExpression::text('Book2.Metadata', '$.tags[0]'), 'FirstTag')
			->filterByTitle('Nested')
			->findOne();

		$this->assertNotNull($book);
		$this->assertSame('scifi', $book->getVirtualColumn('FirstTag'));
	}

	public function testWhereJsonPathFiltersOnANestedScalar()
	{
		$books = Book2Query::create()->whereJsonPath('Book2.Metadata', '$.author.name', 'Ursula')->find();

		$this->assertCount(1, $books);
		$this->assertSame('Nested', $books[0]->getTitle());
	}

	public function testWhereJsonPathFiltersOnAnArrayElement()
	{
		$books = Book2Query::create()->whereJsonPath('Book2.Metadata', '$.tags[0]', 'scifi')->find();

		$this->assertCount(1, $books);
		$this->assertSame('Nested', $books[0]->getTitle());
	}

	public function testWhereJsonPathWithNullMeansIsNull()
	{
		// Neither row has $.publisher at all, so both match IS NULL and
		// neither matches IS NOT NULL. This is the branch that would silently
		// produce "= NULL" (never true anywhere) without the special case.
		$this->assertCount(2, Book2Query::create()->whereJsonPath('Book2.Metadata', '$.publisher', null)->find());
		$this->assertCount(
			0,
			Book2Query::create()->whereJsonPath('Book2.Metadata', '$.publisher', null, Criteria::NOT_EQUAL)->find()
		);
	}

	public function testWhereJsonPathWithIn()
	{
		$books = Book2Query::create()
			->whereJsonPath('Book2.Metadata', '$.author.name', array('Ursula', 'Nobody'), Criteria::IN)
			->find();

		$this->assertCount(1, $books);
		$this->assertSame('Nested', $books[0]->getTitle());
	}

	public function testJsonFormKeepsTheDocument()
	{
		// The json() form of an object must survive as a document rather than
		// collapsing to NULL the way asking for it as a scalar would on the
		// JSON_VALUE platforms.
		$book = Book2Query::create()
			->withColumn(JsonExpression::json('Book2.Metadata', '$.author'), 'Author')
			->filterByTitle('Nested')
			->findOne();

		$this->assertNotNull($book);
		$decoded = json_decode((string) $book->getVirtualColumn('Author'), true);
		$this->assertIsArray($decoded);
		$this->assertSame('Ursula', $decoded['name']);
	}

	public function testAMalformedPathFailsBeforeReachingTheServer()
	{
		$this->expectException(\Propulsion\Exception\PropulsionException::class);
		Book2Query::create()->whereJsonPath('Book2.Metadata', '$.tags[*]', 'x');
	}
}
