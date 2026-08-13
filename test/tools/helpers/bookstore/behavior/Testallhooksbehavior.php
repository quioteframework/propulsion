<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

use Propulsion\Generator\Model\Behavior;

class TestAllHooksBehavior extends Behavior
{
  protected $tableModifier, $objectBuilderModifier, $peerBuilderModifier, $queryBuilderModifier;

  public function getTableModifier()
  {
    if (is_null($this->tableModifier))
    {
      $this->tableModifier = new TestAllHooksTableModifier($this);
    }
    return $this->tableModifier;
  }

  public function getObjectBuilderModifier()
  {
    if (is_null($this->objectBuilderModifier))
    {
      $this->objectBuilderModifier = new TestAllHooksObjectBuilderModifier($this);
    }
    return $this->objectBuilderModifier;
  }

  public function getPeerBuilderModifier()
  {
    if (is_null($this->peerBuilderModifier))
    {
      $this->peerBuilderModifier = new TestAllHooksPeerBuilderModifier($this);
    }
    return $this->peerBuilderModifier;
  }

  public function getQueryBuilderModifier()
  {
    if (is_null($this->queryBuilderModifier))
    {
      $this->queryBuilderModifier = new TestAllHooksQueryBuilderModifier($this);
    }
    return $this->queryBuilderModifier;
  }
}

class TestAllHooksTableModifier
{
  protected $behavior, $table;

  public function __construct($behavior)
  {
    $this->behavior = $behavior;
    $this->table = $behavior->getTable();
  }

  public function modifyTable()
  {
    $this->table->addColumn(array(
      'name' => 'test',
      'type' => 'TIMESTAMP'
    ));
  }
}

class TestAllHooksObjectBuilderModifier
{
  public function objectAttributes($builder)
  {
    return '/** @var int */
public $customAttribute = 1;
/** @var ?int */
public $preSave;
/** @var ?bool */
public $preSaveIsAfterSave;
/** @var ?string */
public $preSaveBuilder;
/** @var ?int */
public $postSave;
/** @var ?bool */
public $postSaveIsAfterSave;
/** @var ?string */
public $postSaveBuilder;
/** @var ?int */
public $preInsert;
/** @var ?bool */
public $preInsertIsAfterSave;
/** @var ?string */
public $preInsertBuilder;
/** @var ?int */
public $postInsert;
/** @var ?bool */
public $postInsertIsAfterSave;
/** @var ?string */
public $postInsertBuilder;
/** @var ?int */
public $preUpdate;
/** @var ?bool */
public $preUpdateIsAfterSave;
/** @var ?string */
public $preUpdateBuilder;
/** @var ?int */
public $postUpdate;
/** @var ?bool */
public $postUpdateIsAfterSave;
/** @var ?string */
public $postUpdateBuilder;
/** @var ?int */
public $preDelete;
/** @var ?bool */
public $preDeleteIsBeforeDelete;
/** @var ?string */
public $preDeleteBuilder;
/** @var ?int */
public $postDelete;
/** @var ?bool */
public $postDeleteIsBeforeDelete;
/** @var ?string */
public $postDeleteBuilder;';
  }

  public function preSave($builder)
  {
    return '$this->preSave = 1;$this->preSaveIsAfterSave = array_key_exists(\'affectedRows\', get_defined_vars());$this->preSaveBuilder="' . get_class($builder) . '";';
  }

  public function postSave($builder)
  {
    return '$this->postSave = 1;$this->postSaveIsAfterSave = array_key_exists(\'affectedRows\', get_defined_vars());$this->postSaveBuilder="' . get_class($builder) . '";';
  }

  public function preInsert($builder)
  {
    return '$this->preInsert = 1;$this->preInsertIsAfterSave = array_key_exists(\'affectedRows\', get_defined_vars());$this->preInsertBuilder="' . get_class($builder) . '";';
  }

  public function postInsert($builder)
  {
    return '$this->postInsert = 1;$this->postInsertIsAfterSave = array_key_exists(\'affectedRows\', get_defined_vars());$this->postInsertBuilder="' . get_class($builder) . '";';
  }

  public function preUpdate($builder)
  {
    return '$this->preUpdate = 1;$this->preUpdateIsAfterSave = array_key_exists(\'affectedRows\', get_defined_vars());$this->preUpdateBuilder="' . get_class($builder) . '";';
  }

  public function postUpdate($builder)
  {
    return '$this->postUpdate = 1;$this->postUpdateIsAfterSave = array_key_exists(\'affectedRows\', get_defined_vars());$this->postUpdateBuilder="' . get_class($builder) . '";';
  }

  public function preDelete($builder)
  {
    // Note: this reads $this->getId() rather than the raw $this->id property --
    // the promoted ObjectBuilder generates PhpName-cased properties (e.g. $Id),
    // unlike the archived PHP5ObjectBuilder, which used lowercase column-name
    // properties (e.g. $id). Using the getter keeps this test helper agnostic
    // to that internal representation.
    return '$this->preDelete = 1;$this->preDeleteIsBeforeDelete = (Table3Peer::getInstanceFromPool((string) $this->getId()) !== null);$this->preDeleteBuilder="' . get_class($builder) . '";';
  }

  public function postDelete($builder)
  {
    return '$this->postDelete = 1;$this->postDeleteIsBeforeDelete = (Table3Peer::getInstanceFromPool((string) $this->getId()) !== null);$this->postDeleteBuilder="' . get_class($builder) . '";';
  }

  public function objectMethods($builder)
  {
    return 'public function hello(): string { return "' . get_class($builder) .'"; }';
  }

  public function objectCall($builder)
  {
  	return 'if ($name == "foo") return "bar";';
  }

  public function objectFilter(&$string, $builder)
  {
    $string .= 'class testObjectFilter { const FOO = "' . get_class($builder) . '"; }';
  }
}

class TestAllHooksPeerBuilderModifier
{
  public function staticAttributes($builder)
  {
    return '/** @var int */
public static $customStaticAttribute = 1;
/** @var string */
public static $staticAttributeBuilder = "' . get_class($builder) . '";
/** @var int|string Records that (and by which builder) the preSelect hook ran. */
public static $preSelect = 0;';
  }

  public function staticMethods($builder)
  {
    return 'public static function hello(): string { return "' . get_class($builder) . '"; }';
  }

  public function preSelect($builder)
  {
    // A static on the generated peer, not $con->preSelect: PropulsionPDO is an
    // interface, so the connection has no such property, and writing one is a
    // dynamic-property assignment on a PDO subclass.
    return 'static::$preSelect = "' . get_class($builder) . '";';
  }

  public function peerFilter(&$string, $builder)
  {
    $string .= 'class testPeerFilter { const FOO = "' . get_class($builder) . '"; }';
  }
}

class TestAllHooksQueryBuilderModifier
{
	public function preSelectQuery($builder)
	{
		return '// foo';
	}

	public function preDeleteQuery($builder)
	{
		return '// foo';
	}

	public function postDeleteQuery($builder)
	{
		return '// foo';
	}

	public function preUpdateQuery($builder)
	{
		return '// foo';
	}

	public function postUpdateQuery($builder)
	{
		return '// foo';
	}
}