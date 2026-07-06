<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
namespace Propulsion\Generator\Behavior\Archivable;

/**
 * Keeps tracks of an ActiveRecord object, even after deletion
 *
 * @author     François Zaninotto
 */

use Propulsion\Generator\Builder\OM\ObjectBuilder;
use Propulsion\Generator\Model\Table;

class ArchivableBehaviorObjectBuilderModifier
{
	protected ArchivableBehavior $behavior;
	protected Table $table;
	protected ?ObjectBuilder $builder = null;

	public function __construct(ArchivableBehavior $behavior)
	{
		$this->behavior = $behavior;
		$this->table = $behavior->getTable();
	}

	/**
	 * @return mixed
	 */
	protected function getParameter(string $key)
	{
		return $this->behavior->getParameter($key);
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function objectAttributes(ObjectBuilder $builder): string
	{
		$script = '';
		if ($this->behavior->isArchiveOnInsert()) {
			$script .= "protected \$archiveOnInsert = true;
";
		}
		if ($this->behavior->isArchiveOnUpdate()) {
			$script .= "protected \$archiveOnUpdate = true;
";
		}
		if ($this->behavior->isArchiveOnDelete()) {
			$script .= "protected \$archiveOnDelete = true;
";
		}
		return $script;
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function postInsert(ObjectBuilder $builder): string
	{
		if ($this->behavior->isArchiveOnInsert()) {
			return "if (\$this->archiveOnInsert) {
	\$this->archive(\$con);
} else {
	\$this->archiveOnInsert = true;
}";
		}

		return '';
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function postUpdate(ObjectBuilder $builder): string
	{
		if ($this->behavior->isArchiveOnUpdate()) {
			return "if (\$this->archiveOnUpdate) {
	\$this->archive(\$con);
} else {
	\$this->archiveOnUpdate = true;
}";
		}

		return '';
	}

	/**
	 * Using preDelete rather than postDelete to allow user to retrieve 
	 * related records and archive them before cascade deletion.
	 *
	 * The actual deletion is made by the query object, so the AR class must tell 
	 * the query class to enable or disable archiveOnDelete.
	 *
	 * @return string the PHP code to be added to the builder
	 */
	public function preDelete(ObjectBuilder $builder): string
	{
		if ($this->behavior->isArchiveOnDelete()) {
			return $this->behavior->renderTemplate('objectPreDelete', array(
				'queryClassname' => $builder->getStubQueryBuilder()->getClassname(),
				'isAddHooks'     => $builder->getGeneratorConfig()->getBuildProperty('addHooks'),
			));
		}

		return '';
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function objectMethods(ObjectBuilder $builder): string
	{
		$this->builder = $builder;
		$script = '';
		$script .= $this->addGetArchive($builder);
		$script .= $this->addArchive($builder);
		$script .= $this->addRestoreFromArchive($builder);
		$script .= $this->addPopulateFromArchive($builder);
		if ($this->behavior->isArchiveOnInsert() || $this->behavior->isArchiveOnUpdate()) {
			$script .= $this->addSaveWithoutArchive($builder);
		}
		if ($this->behavior->isArchiveOnDelete()) {
			$script .= $this->addDeleteWithoutArchive($builder);
		}
		return $script;
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function addGetArchive(ObjectBuilder $builder): string
	{
		return $this->behavior->renderTemplate('objectGetArchive', array(
			'archiveTablePhpName'   => $this->behavior->getArchiveTablePhpName($builder),
			'archiveTableQueryName' => $this->behavior->getArchiveTableQueryName($builder),
		));
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function addArchive(ObjectBuilder $builder): string
	{
		return $this->behavior->renderTemplate('objectArchive', array(
			'archiveTablePhpName'   => $this->behavior->getArchiveTablePhpName($builder),
			'archiveTableQueryName' => $this->behavior->getArchiveTableQueryName($builder),
			'archivedAtColumn'      => $this->behavior->getArchivedAtColumn(),
		));
	}

	/**
	 *
	 * @return string the PHP code to be added to the builder
	 */
	public function addRestoreFromArchive(ObjectBuilder $builder): string
	{
		return $this->behavior->renderTemplate('objectRestoreFromArchive', array(
			'objectClassname' => $this->builder->getObjectClassname(),
		));
	}

	/**
	 * Generates a method to populate the current AR object based on an archive object.
	 * This method is necessary because the archive's copyInto() may include the archived_at column
	 * and therefore cannot be used. Besides, the way autoincremented PKs are handled should be explicit.
	 *
	 * @return string the PHP code to be added to the builder
	 */
	public function addPopulateFromArchive(ObjectBuilder $builder): string
	{
		return $this->behavior->renderTemplate('objectPopulateFromArchive', array(
			'archiveTablePhpName' => $this->behavior->getArchiveTablePhpName($builder),
			'usesAutoIncrement'   => $this->table->hasAutoIncrementPrimaryKey(),
			'objectClassname'     => $this->builder->getObjectClassname(),
			'columns'             => $this->table->getColumns(),
		));
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function addSaveWithoutArchive(ObjectBuilder $builder): string
	{
		return $this->behavior->renderTemplate('objectSaveWithoutArchive', array(
			'objectClassname'   => $this->builder->getObjectClassname(),
			'isArchiveOnInsert' => $this->behavior->isArchiveOnInsert(),
			'isArchiveOnUpdate' => $this->behavior->isArchiveOnUpdate(),
		));
	}

	/**
	 * @return string the PHP code to be added to the builder
	 */
	public function addDeleteWithoutArchive(ObjectBuilder $builder): string
	{
		return $this->behavior->renderTemplate('objectDeleteWithoutArchive', array(
			'objectClassname' => $this->builder->getObjectClassname(),
		));
	}

}
