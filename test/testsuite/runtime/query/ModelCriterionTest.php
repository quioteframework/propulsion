<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;
use Propulsion\Query\Criteria;
use Propulsion\Query\ModelCriteria;
use Propulsion\Query\ModelCriterion;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;
use Propulsion\Adapter\DBPostgres;
use Propulsion\Adapter\DBSQLite;

/**
 * Unit-level coverage for ModelCriterion, the internal "inner class" that builds
 * the actual prepared-statement fragment for each WHERE clause added through
 * ModelCriteria (with()/where()/filterBy...()). Exercised only indirectly and
 * partially through ModelCriteriaTest's higher-level assertions; this targets the
 * comparison-type dispatch, the Postgres-specific case-insensitive LIKE rewrite,
 * the BETWEEN/IN edge cases (including the empty-array short circuits), and
 * equals()/hashCode() used for query deduplication.
 */
class ModelCriterionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Propulsion::setDB('model_criterion_test_pg', new DBPostgres());
        Propulsion::setDB('model_criterion_test_sqlite', new DBSQLite());
    }

    private function makeCriterion($clause, $value = null, $comparison = null, $dbName = 'model_criterion_test_sqlite')
    {
        $criteria = new Criteria($dbName);
        $comparison = $comparison ?? ModelCriteria::MODEL_CLAUSE;
        return new ModelCriterion($criteria, 'book.TITLE', $value, $comparison, $clause);
    }

    public function testConstructorSplitsDottedColumnIntoTableAndColumn()
    {
        $criterion = $this->makeCriterion('book.TITLE = ?', 'foo');
        $this->assertSame('book', $criterion->getTable());
        $this->assertSame('TITLE', $criterion->getColumn());
    }

    public function testConstructorWithoutDotTreatsColumnAsAliased()
    {
        $criteria = new Criteria('model_criterion_test_sqlite');
        $criterion = new ModelCriterion($criteria, 'TITLE', 'foo', ModelCriteria::MODEL_CLAUSE, 'TITLE = ?');
        $this->assertNull($criterion->getTable());
        $this->assertSame('TITLE', $criterion->getColumn());
    }

    public function testGetClauseReturnsOriginalClause()
    {
        $criterion = $this->makeCriterion('book.TITLE = ?', 'foo');
        $this->assertSame('book.TITLE = ?', $criterion->getClause());
    }

    public function testAppendModelClauseToPsWithValue()
    {
        $criterion = $this->makeCriterion('book.TITLE = ?', 'foo');
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);
        $this->assertSame('book.TITLE = :p1', $sb);
        $this->assertCount(1, $params);
        $this->assertSame('foo', $params[0]['value']);
        $this->assertSame('TITLE', $params[0]['column']);
    }

    public function testAppendModelClauseToPsWithNullValueSkipsBinding()
    {
        // No '?' in the clause and a null value means no param is bound at all,
        // e.g. a clause like 'book.TITLE IS NULL' built without a placeholder.
        $criterion = $this->makeCriterion('book.TITLE IS NULL', null);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);
        $this->assertSame('book.TITLE IS NULL', $sb);
        $this->assertCount(0, $params);
    }

    public function testAppendModelClauseLikeToPsRewritesToILikeOnPostgresWhenIgnoringCase()
    {
        $criterion = $this->makeCriterion('book.TITLE LIKE ?', '%foo%', ModelCriteria::MODEL_CLAUSE_LIKE, 'model_criterion_test_pg');
        $criterion->setIgnoreCase(true);

        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);

        $this->assertSame('book.TITLE ILIKE :p1', $sb);
        $this->assertSame('%foo%', $params[0]['value']);
    }

    public function testAppendModelClauseLikeToPsLeavesLikeUnchangedOnNonPostgres()
    {
        $criterion = $this->makeCriterion('book.TITLE LIKE ?', '%foo%', ModelCriteria::MODEL_CLAUSE_LIKE, 'model_criterion_test_sqlite');
        $criterion->setIgnoreCase(true);

        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);

        $this->assertSame('book.TITLE LIKE :p1', $sb);
    }

    public function testAppendModelClauseLikeToPsLeavesLikeUnchangedWhenNotIgnoringCase()
    {
        $criterion = $this->makeCriterion('book.TITLE LIKE ?', '%foo%', ModelCriteria::MODEL_CLAUSE_LIKE, 'model_criterion_test_pg');
        // ignoreStringCase left at its default (false)

        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);

        $this->assertSame('book.TITLE LIKE :p1', $sb);
    }

    public function testAppendModelClauseSeveralToPsBuildsBetweenClause()
    {
        $criterion = $this->makeCriterion('book.ID BETWEEN ? AND ?', array(1, 10), ModelCriteria::MODEL_CLAUSE_SEVERAL);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);

        $this->assertSame('book.ID BETWEEN :p1 AND :p2', $sb);
        $this->assertCount(2, $params);
        $this->assertSame(1, $params[0]['value']);
        $this->assertSame(10, $params[1]['value']);
    }

    public function testAppendModelClauseSeveralToPsThrowsOnNullValue()
    {
        $criterion = $this->makeCriterion('book.ID BETWEEN ? AND ?', array(1, null), ModelCriteria::MODEL_CLAUSE_SEVERAL);
        $sb = '';
        $params = array();
        $this->expectException(PropulsionException::class);
        $this->expectExceptionMessage('Null values are not supported inside BETWEEN clauses');
        $criterion->appendPsTo($sb, $params);
    }

    public function testAppendModelClauseArrayToPsBuildsInClause()
    {
        $criterion = $this->makeCriterion('book.TITLE IN ?', array('foo', 'bar', 'baz'), ModelCriteria::MODEL_CLAUSE_ARRAY);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);

        $this->assertSame('book.TITLE IN (:p1,:p2,:p3)', $sb);
        $this->assertCount(3, $params);
        $this->assertSame('bar', $params[1]['value']);
    }

    public function testAppendModelClauseArrayToPsWithEmptyArrayOnInIsAlwaysFalse()
    {
        // An empty IN (...) list can never match anything -- short-circuit to a
        // condition that is always false, rather than emitting invalid "IN ()" SQL.
        $criterion = $this->makeCriterion('book.TITLE IN ?', array(), ModelCriteria::MODEL_CLAUSE_ARRAY);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);

        $this->assertSame('1<>1', $sb);
        $this->assertCount(0, $params);
    }

    public function testAppendModelClauseArrayToPsWithEmptyArrayOnNotInIsAlwaysTrue()
    {
        // An empty NOT IN (...) list excludes nothing -- short-circuit to a
        // condition that is always true.
        $criterion = $this->makeCriterion('book.TITLE NOT IN ?', array(), ModelCriteria::MODEL_CLAUSE_ARRAY);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);

        $this->assertSame('1=1', $sb);
        $this->assertCount(0, $params);
    }

    public function testDispatchesCustomComparisonWithoutParameterBinding()
    {
        // For Criteria::CUSTOM, the custom SQL expression is the *value*, not the clause.
        $criteria = new Criteria('model_criterion_test_sqlite');
        $criterion = new ModelCriterion($criteria, 'book.TITLE', "book.TITLE = 'foo'", Criteria::CUSTOM);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);
        $this->assertSame("book.TITLE = 'foo'", $sb);
        $this->assertCount(0, $params);
    }

    public function testDispatchesInComparisonToTraditionalInHandling()
    {
        $criteria = new Criteria('model_criterion_test_sqlite');
        $criterion = new ModelCriterion($criteria, 'book.ID', array(1, 2, 3), Criteria::IN);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);
        $this->assertStringContainsString('book.ID IN', $sb);
        $this->assertCount(3, $params);
    }

    public function testDefaultComparisonDispatchesToBasicHandling()
    {
        $criteria = new Criteria('model_criterion_test_sqlite');
        $criterion = new ModelCriterion($criteria, 'book.PRICE', 10, Criteria::GREATER_THAN);
        $sb = '';
        $params = array();
        $criterion->appendPsTo($sb, $params);
        $this->assertSame('book.PRICE>:p1', $sb);
    }

    public function testEqualsIsTrueForSameInstance()
    {
        $criterion = $this->makeCriterion('book.TITLE = ?', 'foo');
        $this->assertTrue($criterion->equals($criterion));
    }

    public function testEqualsIsFalseForNonModelCriterion()
    {
        $criterion = $this->makeCriterion('book.TITLE = ?', 'foo');
        $this->assertFalse($criterion->equals(null));
        $this->assertFalse($criterion->equals('not a criterion'));
    }

    public function testEqualsIsTrueForEquivalentCriteria()
    {
        $a = $this->makeCriterion('book.TITLE = ?', 'foo');
        $b = $this->makeCriterion('book.TITLE = ?', 'foo');
        $this->assertTrue($a->equals($b));
    }

    public function testEqualsIsFalseWhenValueDiffers()
    {
        $a = $this->makeCriterion('book.TITLE = ?', 'foo');
        $b = $this->makeCriterion('book.TITLE = ?', 'bar');
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsIsFalseWhenTableDiffers()
    {
        $criteria = new Criteria('model_criterion_test_sqlite');
        $a = new ModelCriterion($criteria, 'book.TITLE', 'foo', ModelCriteria::MODEL_CLAUSE, 'book.TITLE = ?');
        $b = new ModelCriterion($criteria, 'author.TITLE', 'foo', ModelCriteria::MODEL_CLAUSE, 'author.TITLE = ?');
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsIsFalseWhenAttachedClauseCountDiffers()
    {
        $a = $this->makeCriterion('book.TITLE = ?', 'foo');
        $b = $this->makeCriterion('book.TITLE = ?', 'foo');
        $b->addAnd($this->makeCriterion('book.ISBN = ?', 'bar'));
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsComparesAttachedClausesByIdentityNotEquivalence()
    {
        // equals() compares $this->clauses[$i] === $critClauses[$i] -- strict object
        // identity, not ->equals(). Two separately-built (but content-equivalent)
        // attached sub-criterion are therefore NOT considered equal, unlike the
        // top-level table/column/clause/comparison/value fields compared just above.
        $a = $this->makeCriterion('book.TITLE = ?', 'foo');
        $a->addAnd($this->makeCriterion('book.ISBN = ?', 'bar'));
        $b = $this->makeCriterion('book.TITLE = ?', 'foo');
        $b->addAnd($this->makeCriterion('book.ISBN = ?', 'bar'));
        $this->assertFalse($a->equals($b));
    }

    public function testEqualsIsTrueWhenAttachedClauseIsTheSameSharedInstance()
    {
        $shared = $this->makeCriterion('book.ISBN = ?', 'bar');

        $a = $this->makeCriterion('book.TITLE = ?', 'foo');
        $a->addAnd($shared);
        $b = $this->makeCriterion('book.TITLE = ?', 'foo');
        $b->addAnd($shared);

        $this->assertTrue($a->equals($b));
    }

    public function testHashCodeIsStableForSameCriterion()
    {
        $criterion = $this->makeCriterion('book.TITLE = ?', 'foo');
        $this->assertSame($criterion->hashCode(), $criterion->hashCode());
    }

    public function testHashCodeDiffersForDifferentValues()
    {
        $a = $this->makeCriterion('book.TITLE = ?', 'foo');
        $b = $this->makeCriterion('book.TITLE = ?', 'bar');
        $this->assertNotSame($a->hashCode(), $b->hashCode());
    }

    public function testHashCodeIncludesAttachedClauses()
    {
        $a = $this->makeCriterion('book.TITLE = ?', 'foo');
        $withoutClause = $a->hashCode();

        $a->addAnd($this->makeCriterion('book.ISBN = ?', 'bar'));
        $withClause = $a->hashCode();

        $this->assertNotSame($withoutClause, $withClause);
    }
}
