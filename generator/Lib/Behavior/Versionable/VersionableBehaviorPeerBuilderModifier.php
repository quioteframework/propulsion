<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\Versionable;

/**
 * Behavior to add versionable columns and abilities
 *
 * @author     François Zaninotto
 */
class VersionableBehaviorPeerBuilderModifier
{
  protected VersionableBehavior $behavior;
  protected \Propulsion\Generator\Model\Table $table;

  public function __construct(VersionableBehavior $behavior)
  {
    $this->behavior = $behavior;
    $this->table = $behavior->getTable();
  }

  public function staticAttributes(): string
  {
    return "
/**
 * Whether the versioning is enabled
 */
static \$isVersioningEnabled = true;
";
  }

  public function staticMethods(): string
  {
    $script = "";
    $this->addIsVersioningEnabled($script);
    $this->addEnableVersioning($script);
    $this->addDisableVersioning($script);

    return $script;
  }

  public function addIsVersioningEnabled(string &$script): void
  {
    $script .= "
/**
 * Checks whether versioning is enabled
 *
 * @return boolean
 */
public static function isVersioningEnabled()
{
	return self::\$isVersioningEnabled;
}
";
  }

  public function addEnableVersioning(string &$script): void
  {
    $script .= "
/**
 * Enables versioning
 */
public static function enableVersioning()
{
	self::\$isVersioningEnabled = true;
}
";
  }

  public function addDisableVersioning(string &$script): void
  {
    $script .= "
/**
 * Disables versioning
 */
public static function disableVersioning()
{
	self::\$isVersioningEnabled = false;
}
";
  }
}
