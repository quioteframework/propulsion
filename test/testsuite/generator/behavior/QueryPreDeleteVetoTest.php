<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use PHPUnit\Framework\TestCase;

/**
 * Coverage for the query-level preDelete() hook: that it runs at all, and that
 * returning false prevents the delete.
 *
 * Both were broken. ModelCriteria::delete() ran
 * `if (!$affectedRows = $criteria->basePreDelete($con))`, which treats a false
 * return as "nothing happened, carry on and delete" -- so a hook written to
 * block a deletion was silently ignored. On a soft_delete table it was worse:
 * the generated basePreDelete() put the behavior's code first, and that code
 * returns from both branches, so preDelete() was unreachable and never called.
 */
class QueryPreDeleteVetoTest extends TestCase
{
    private function buildSchema(string $extraTableXml = ''): string
    {
        $schema = <<<XML
<database name="predelete_veto_test" defaultIdMethod="native">
    <table name="veto_widget">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" size="40" />
    </table>
$extraTableXml
</database>
XML;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);

        return $builder->getClasses();
    }

    /**
     * On a table with no behavior, no basePreDelete() is generated at all and
     * ModelCriteria's own is used -- so the veto has to work there too.
     */
    public function testPlainTableInheritsTheBaseHook()
    {
        $script = $this->buildSchema();

        $this->assertStringNotContainsString('protected function basePreDelete', $script);
    }

    /**
     * The hook must be called before the behavior's code, because soft_delete's
     * code returns unconditionally.
     */
    public function testSoftDeleteTableCallsPreDeleteBeforeTheBehavior()
    {
        $script = $this->buildSchema(<<<XML
    <table name="veto_soft">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" size="40" />
        <behavior name="soft_delete" />
    </table>
XML);

        $this->assertStringContainsString('protected function basePreDelete', $script);

        $start = strpos($script, 'protected function basePreDelete');
        $this->assertNotFalse($start);
        $body = substr($script, $start, 900);

        $hookPos = strpos($body, '$this->preDelete($con)');
        $behaviorPos = strpos($body, 'isSoftDeleteEnabled()');
        $this->assertNotFalse($hookPos, 'basePreDelete() must call the preDelete() hook');
        $this->assertNotFalse($behaviorPos, 'basePreDelete() must still contain the soft_delete code');
        $this->assertLessThan(
            $behaviorPos,
            $hookPos,
            'the preDelete() hook has to run before the behavior code, which returns unconditionally'
        );
    }

    public function testFalseFromTheHookIsReturnedAsAVeto()
    {
        $script = $this->buildSchema(<<<XML
    <table name="veto_soft">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" size="40" />
        <behavior name="soft_delete" />
    </table>
XML);

        $this->assertMatchesRegularExpression(
            '/if \(\$this->preDelete\(\$con\) === false\) \{\s*return false;\s*\}/',
            $script
        );
    }

    /**
     * The trailing `return $this->preDelete($con);` that used to sit after the
     * behavior code -- unreachable, and the reason the hook never fired -- must
     * not come back.
     */
    public function testNoUnreachableTrailingHookCall()
    {
        $script = $this->buildSchema(<<<XML
    <table name="veto_soft">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" size="40" />
        <behavior name="soft_delete" />
    </table>
XML);

        $start = strpos($script, 'protected function basePreDelete');
        $body = substr($script, (int) $start, 900);
        $behaviorPos = strpos($body, 'isSoftDeleteEnabled()');
        $this->assertNotFalse($behaviorPos);

        $afterBehavior = substr($body, (int) $behaviorPos);
        $this->assertStringNotContainsString('return $this->preDelete($con);', $afterBehavior);
    }
}
