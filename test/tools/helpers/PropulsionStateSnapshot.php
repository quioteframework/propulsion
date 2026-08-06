<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Adapter\DBAdapter;
use Propulsion\Config\PropulsionConfiguration;
use Propulsion\Exception\PropulsionException;
use Propulsion\Propulsion;

/**
 * Save and restore the process-global Propulsion state a test destroys by
 * reconfiguring.
 *
 * `Propulsion::setConfiguration()` does not merely replace the configuration:
 * it drops everything derived from the old one, which includes **the entire
 * adapter map**. Adapters that were described in the configuration get rebuilt
 * on demand by `getDB()`, so those survive. Adapters registered out of band
 * with `Propulsion::setDB()` -- which is how `PropulsionQuickBuilder` hands
 * every schema it builds to the runtime, and therefore how most of this
 * suite's non-bookstore fixtures work -- cannot be rebuilt from anything and
 * are simply gone.
 *
 * The configuration is captured and restored as the *object*, not as its
 * array of values. Restoring from an array builds a fresh
 * PropulsionConfiguration, and a connection that had already resolved the old
 * instance keeps reading that one -- which is its own order-dependent failure
 * (see ConnectionConfigurationFollowsPropulsionTest). Putting the original
 * instance back leaves identity intact.
 *
 * Restoring only the configuration array is therefore not enough, and looks
 * like it is: the reconfiguring test passes, and some later, unrelated test
 * fails with "Unable to find adapter for datasource [...]" naming a
 * datasource it never touched. PropulsionQuickBuilder's own
 * `buildClasses()` carries a long comment about this exact trap; this is the
 * same knowledge, made reusable.
 *
 * <code>
 * protected function setUp(): void { $this->state = PropulsionStateSnapshot::capture(); }
 * protected function tearDown(): void { $this->state->restore(); }
 * </code>
 */
final class PropulsionStateSnapshot
{
    /**
     * @param array<string, DBAdapter> $adapters
     */
    private function __construct(
        private readonly ?PropulsionConfiguration $configuration,
        private readonly array $adapters,
    ) {
    }

    public static function capture(): self
    {
        try {
            $configuration = Propulsion::getConfiguration(PropulsionConfiguration::TYPE_OBJECT);
        } catch (PropulsionException) {
            // Nothing configured yet; restore() will leave it that way.
            $configuration = null;
        }
        if (!$configuration instanceof PropulsionConfiguration) {
            $configuration = null;
        }

        $adapters = array();
        foreach (Propulsion::getRegisteredAdapterNames() as $name) {
            $adapters[$name] = Propulsion::getDB($name);
        }

        return new self($configuration, $adapters);
    }

    public function restore(): void
    {
        if ($this->configuration !== null) {
            Propulsion::setConfiguration($this->configuration);
        }

        // After the configuration, never before: setConfiguration() is what
        // clears the adapter map, so re-registering first would achieve
        // nothing.
        foreach ($this->adapters as $name => $adapter) {
            Propulsion::setDB($name, $adapter);
        }
    }
}
