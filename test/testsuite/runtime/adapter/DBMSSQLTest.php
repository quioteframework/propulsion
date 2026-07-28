<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Tests the DBMSSQL adapter
 *
 * @see        BookstoreDataPopulator
 */
class DBMSSQLTest extends BookstoreTestBase
{
	public function testSupportsUpsertAndUsesMerge()
	{
		$db = new DBMSSQL();
		$this->assertTrue($db->supportsUpsert());
		$this->assertTrue($db->usesMergeUpsert());
	}

	public function testGetMergeUpsertSql()
	{
		$db = new DBMSSQL();
		$sql = $db->getMergeUpsertSql('book', array('ID', 'TITLE', 'ISBN'), array('ID'), 'TITLE = :p4');
		$this->assertEquals(
			'MERGE INTO book USING (SELECT :p1 AS ID, :p2 AS TITLE, :p3 AS ISBN) AS s ON (book.ID = s.ID) WHEN MATCHED THEN UPDATE SET TITLE = :p4 WHEN NOT MATCHED THEN INSERT (ID, TITLE, ISBN) VALUES (s.ID, s.TITLE, s.ISBN);',
			$sql,
			'a trailing semicolon is required -- T-SQL error 10713 otherwise'
		);
	}

	public function testGetMergeUpsertSqlWithEmptySetClauseOmitsWhenMatched()
	{
		$db = new DBMSSQL();
		$sql = $db->getMergeUpsertSql('book', array('ID', 'TITLE'), array('ID'), '');
		$this->assertStringNotContainsString('WHEN MATCHED', $sql, 'an empty $setClause means "do nothing on conflict" -- no WHEN MATCHED clause at all');
		$this->assertStringContainsString('WHEN NOT MATCHED THEN INSERT (ID, TITLE) VALUES (s.ID, s.TITLE);', $sql);
	}

	public function testGetMergeUpsertSqlThrowsWithoutConflictColumns()
	{
		$db = new DBMSSQL();
		$this->expectException(PropulsionException::class);
		$db->getMergeUpsertSql('book', array('ID', 'TITLE'), array(), 'TITLE = :p3');
	}
}
