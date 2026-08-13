<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Adapter\DBSQLSRV;
use Propulsion\Connection\GenericPropulsionPDO;
use Propulsion\Exception\PropulsionException;
use Propulsion\Map\ColumnMap;
use Propulsion\Map\DatabaseMap;
use Propulsion\Map\TableMap;
use Propulsion\Query\Criteria;

/**
 * DBSQLSRV is the MSSQL adapter for the pdo_sqlsrv driver, as opposed to
 * DBMSSQL's dblib. No CI job covers it -- pdo_sqlsrv is Windows/Microsoft-ODBC
 * only, so the MSSQL integration job connects through dblib and exercises
 * DBMSSQL instead -- which left this class at zero coverage everywhere despite
 * being reachable from ordinary configuration (`DBAdapter::factory('sqlsrv')`).
 *
 * Everything here is driver-independent by construction: the SQL rewriting and
 * parameter handling under test are pure string/map work. The two branches that
 * genuinely need the extension loaded (setCharset()'s utf-8/system cases, which
 * read PDO::SQLSRV_ATTR_ENCODING, and bindValue()'s binary-LOB bindParam(),
 * which reads PDO::SQLSRV_ENCODING_BINARY) are deliberately not asserted here --
 * those constants do not exist without pdo_sqlsrv, so a test touching them
 * would fail on the very platforms this suite runs on rather than testing
 * anything.
 */
class DBSQLSRVTest extends TestCase
{
    private DBSQLSRV $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new DBSQLSRV();
    }

    /**
     * Deliberately not DBMSSQL's MssqlPropulsionPDO: that class extends
     * \Pdo\Dblib, which throws if constructed against a non-dblib DSN, and this
     * adapter's DSN uses the sqlsrv driver. See the method's own comment.
     */
    public function testDefaultPdoClassIsDriverAgnosticRatherThanTheDblibOne()
    {
        $this->assertSame(GenericPropulsionPDO::class, $this->adapter->getDefaultPdoClass());
        $this->assertNotSame((new \Propulsion\Adapter\DBMSSQL())->getDefaultPdoClass(), $this->adapter->getDefaultPdoClass());
    }

    public function testInitConnectionForcesExceptionsAndUnstringifiedFetches()
    {
        $con = new PDO('sqlite::memory:');
        $con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->adapter->initConnection($con, array());
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $con->getAttribute(PDO::ATTR_ERRMODE));
    }

    /**
     * pdo_sqlsrv only offers UTF-8 and the system encoding, so anything else is
     * refused up front rather than silently connecting with the wrong one.
     */
    public function testSetCharsetRejectsAnEncodingTheDriverCannotProvide()
    {
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('only utf-8 or system encoding are supported by the pdo_sqlsrv driver');
        $this->adapter->setCharset(new PDO('sqlite::memory:'), 'latin1');
    }

    /**
     * The one real behavior of cleanupSQL(): a null value bound to a LOB column
     * is wrapped in CONVERT(VARBINARY(MAX), ...), working around a pdo_sqlsrv
     * bug that otherwise mis-inserts null blobs (see the method's own link).
     */
    public function testCleanupSqlWrapsANullLobParameterInAConvert()
    {
        $dbMap = $this->dbMapWithColumns(array('COVER' => true));
        $sql = 'INSERT INTO book (COVER) VALUES (:p1)';
        $params = array(array('table' => 'book', 'column' => 'COVER', 'value' => null));

        $this->adapter->cleanupSQL($sql, $params, new Criteria(), $dbMap);
        $this->assertSame('INSERT INTO book (COVER) VALUES (CONVERT(VARBINARY(MAX), :p1))', $sql);
    }

    public function testCleanupSqlLeavesANonNullLobParameterAlone()
    {
        $dbMap = $this->dbMapWithColumns(array('COVER' => true));
        $sql = 'INSERT INTO book (COVER) VALUES (:p1)';
        $params = array(array('table' => 'book', 'column' => 'COVER', 'value' => 'data'));

        $this->adapter->cleanupSQL($sql, $params, new Criteria(), $dbMap);
        $this->assertSame('INSERT INTO book (COVER) VALUES (:p1)', $sql);
    }

    public function testCleanupSqlLeavesANullNonLobParameterAlone()
    {
        $dbMap = $this->dbMapWithColumns(array('TITLE' => false));
        $sql = 'INSERT INTO book (TITLE) VALUES (:p1)';
        $params = array(array('table' => 'book', 'column' => 'TITLE', 'value' => null));

        $this->adapter->cleanupSQL($sql, $params, new Criteria(), $dbMap);
        $this->assertSame('INSERT INTO book (TITLE) VALUES (:p1)', $sql);
    }

    /**
     * The parameter counter advances over every param, mapped or not, so the
     * placeholder a later LOB column rewrites is the right one -- getting this
     * wrong would rewrite an unrelated :pN.
     */
    public function testCleanupSqlCountsUnmappedParametersWhenNumberingPlaceholders()
    {
        $dbMap = $this->dbMapWithColumns(array('COVER' => true));
        $sql = 'INSERT INTO book (TITLE, COVER) VALUES (:p1, :p2)';
        $params = array(
            array('table' => null, 'column' => null, 'value' => 'a literal'),
            array('table' => 'book', 'column' => 'COVER', 'value' => null),
        );

        $this->adapter->cleanupSQL($sql, $params, new Criteria(), $dbMap);
        $this->assertSame('INSERT INTO book (TITLE, COVER) VALUES (:p1, CONVERT(VARBINARY(MAX), :p2))', $sql);
    }

    public function testCleanupSqlRejectsNonStringTableOrColumnNames()
    {
        $dbMap = $this->dbMapWithColumns(array('COVER' => true));
        $sql = 'INSERT INTO book (COVER) VALUES (:p1)';
        $params = array(array('table' => 'book', 'column' => 42, 'value' => null));

        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('DBSQLSRV::cleanupSQL() expected param table/column names to be strings');
        $this->adapter->cleanupSQL($sql, $params, new Criteria(), $dbMap);
    }

    public function testBindValueBindsAnOrdinaryValueWithTheColumnsPdoType()
    {
        $con = new PDO('sqlite::memory:');
        $stmt = $con->prepare('SELECT :p1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $cMap = new ColumnMap('TITLE', new TableMap('book'));
        $cMap->setType('VARCHAR');

        $this->assertTrue($this->adapter->bindValue($stmt, ':p1', 'Harry Potter', $cMap));
        $stmt->execute();
        $this->assertSame('Harry Potter', $stmt->fetchColumn());
    }

    /**
     * A temporal column's value is normalized through formatTemporalValue()
     * before binding, so a DateTime (or a parseable string) reaches the server
     * in the platform's own format rather than PHP's default rendering.
     */
    public function testBindValueFormatsATemporalValueBeforeBinding()
    {
        $con = new PDO('sqlite::memory:');
        $stmt = $con->prepare('SELECT :p1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $cMap = new ColumnMap('CREATED_AT', new TableMap('book'));
        $cMap->setType('TIMESTAMP');

        $this->assertTrue($this->adapter->bindValue($stmt, ':p1', new DateTime('2026-08-13 10:11:12'), $cMap));
        $stmt->execute();
        $this->assertSame('2026-08-13 10:11:12', $stmt->fetchColumn());
    }

    /**
     * @param array<string, bool> $columns Column name => whether it is a LOB.
     */
    private function dbMapWithColumns(array $columns): DatabaseMap
    {
        $dbMap = new DatabaseMap('bookstore');
        $tableMap = new TableMap('book', $dbMap);
        foreach ($columns as $name => $isLob) {
            $tableMap->addColumn($name, ucfirst(strtolower($name)), $isLob ? 'BLOB' : 'VARCHAR');
        }
        $dbMap->addTableObject($tableMap);

        return $dbMap;
    }
}
