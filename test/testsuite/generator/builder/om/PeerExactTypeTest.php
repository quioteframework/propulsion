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
 * Coverage for the exact peer signatures that dropping the concrete_inheritance
 * peer chain bought back.
 *
 * addInstanceToPool() took the Poolable interface and doValidateThis() took no
 * type at all, both purely because a child peer inheriting from a parent peer
 * could not narrow a parameter without breaking contravariance. That chain is
 * gone, so the signatures name the model -- and each shed a runtime
 * instanceof-and-throw, one of them on the save path.
 *
 * Pinned here because nothing else would notice a silent revert: the widened
 * forms accepted everything the narrow ones do, so every existing test would
 * still pass.
 */
class PeerExactTypeTest extends TestCase
{
    private function buildPeer(): string
    {
        $schema = <<<XML
<database name="peer_exact_type_test" defaultIdMethod="native">
    <table name="peer_widget">
        <column name="id" type="INTEGER" primaryKey="true" autoIncrement="true" />
        <column name="label" type="VARCHAR" size="40" required="true" />
    </table>
</database>
XML;
        $builder = new PropulsionQuickBuilder();
        $builder->setSchema($schema);

        return $builder->getClasses();
    }

    public function testAddInstanceToPoolNamesTheModelNotThePoolableInterface()
    {
        $script = $this->buildPeer();

        $this->assertStringContainsString(
            'public static function addInstanceToPool(\PeerWidget $obj, ?string $key = null): void',
            $script
        );
        $this->assertStringNotContainsString('addInstanceToPool(Poolable $obj', $script);
    }

    public function testDoValidateThisNamesTheModelInsteadOfBeingUntyped()
    {
        $script = $this->buildPeer();

        $this->assertStringContainsString(
            'public static function doValidateThis(\PeerWidget $obj, mixed $cols = null)',
            $script
        );
        $this->assertStringNotContainsString('doValidateThis($obj, mixed $cols', $script);
    }

    /**
     * Both methods carried an instanceof check whose only job was to recover the
     * type the signature had given away. With real types they are dead weight,
     * and one of them ran on every save().
     */
    public function testTheRuntimeTypeGuardsAreGone()
    {
        $script = $this->buildPeer();

        $this->assertStringNotContainsString('can only derive a pool key from a', $script);
        $this->assertStringNotContainsString('::doValidateThis() expects a', $script);
    }

    /**
     * Poolable was imported by every generated peer solely for that parameter.
     */
    public function testPeersNoLongerImportPoolable()
    {
        $script = $this->buildPeer();

        $peerStart = strpos($script, 'abstract class BasePeerWidgetPeer');
        $this->assertNotFalse($peerStart);

        $peerHeader = substr($script, max(0, $peerStart - 2000), 2000);
        $this->assertStringNotContainsString('use Propulsion\OM\Poolable;', $peerHeader);
    }
}
