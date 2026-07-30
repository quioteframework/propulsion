<?php
namespace Propulsion\Generator\Behavior\Versionable;

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

/**
 * Keeps tracks of all the modifications in an ActiveRecord object
 *
 * @author    Francois Zaninotto
 * @version		$Revision$
 */
use Propulsion\Generator\Exception\EngineException;
use Propulsion\Generator\Model\Behavior;
use Propulsion\Generator\Model\Column;
use Propulsion\Generator\Model\Database;
use Propulsion\Generator\Model\ForeignKey;
use Propulsion\Generator\Model\Table;

class VersionableBehavior extends Behavior
{
  // default parameters value
  /** @var array<string, string> */
  protected $parameters = [
    "version_column" => "version",
    "version_table" => "",
    "log_created_at" => "false",
    "log_created_by" => "false",
    "log_comment" => "false",
    "version_created_at_column" => "version_created_at",
    "version_created_by_column" => "version_created_by",
    "version_comment_column" => "version_comment",
  ];

  protected ?Table $versionTable = null;
  protected ?VersionableBehaviorObjectBuilderModifier $objectBuilderModifier = null;
  protected ?VersionableBehaviorQueryBuilderModifier $queryBuilderModifier = null;
  protected ?VersionableBehaviorPeerBuilderModifier $peerBuilderModifier = null;

  /** @var int */
  protected $tableModificationOrder = 80;

  /**
   * The table this behavior is attached to. modifyTable() and the
   * add*() helpers below are only ever invoked after the behavior has
   * been attached to a table, but getTable() is nullable to also cover
   * database-level behaviors, so guard against the (should-never-happen)
   * unattached case with a clear error instead of a null dereference.
   */
  public function requireTable(): Table
  {
    $table = $this->getTable();
    if ($table === null) {
      throw new EngineException(
        "VersionableBehavior is not attached to a table",
      );
    }
    return $table;
  }

  private function requireDatabase(Table $table): Database
  {
    $database = $table->getDatabase();
    if ($database === null) {
      throw new EngineException(
        sprintf(
          "Table '%s' is not attached to a database",
          $table->getName(),
        ),
      );
    }
    return $database;
  }

  /**
   * The version table is only known once addVersionTable() has run as
   * part of modifyTable(); calling this before that point (or before
   * modifyTable() at all) is a programming error.
   */
  public function requireVersionTable(): Table
  {
    if ($this->versionTable === null) {
      throw new EngineException(
        "Version table has not been created yet; addVersionTable() must run before this call",
      );
    }
    return $this->versionTable;
  }

  public function requireForeignKeyTable(ForeignKey $fk): Table
  {
    $table = $fk->getTable();
    if ($table === null) {
      throw new EngineException(
        "ForeignKey is not attached to a parent table",
      );
    }
    return $table;
  }

  /**
   * getParameter() is typed to return mixed at the Behavior base class,
   * but this behavior's own $parameters map is declared
   * array<string, string>, so every value obtained through it is
   * actually a string. Centralize that cast here rather than sprinkling
   * (string) casts at each call site.
   */
  private function getStringParameter(string $name): string
  {
    $value = $this->getParameter($name);
    if (!is_string($value)) {
      throw new EngineException(
        sprintf("Parameter '%s' is expected to be a string", $name),
      );
    }
    return $value;
  }

  public function modifyTable(): void
  {
    $this->addVersionColumn();
    $this->addLogColumns();
    $this->addVersionTable();
    $this->addForeignKeyVersionColumns();
  }

  protected function addVersionColumn(): void
  {
    $table = $this->requireTable();
    // add the version column
    if (!$table->hasColumn($this->getStringParameter("version_column"))) {
      $table->addColumn([
        "name" => $this->getStringParameter("version_column"),
        "type" => "INTEGER",
        "default" => 0,
      ]);
    }
  }

  protected function addLogColumns(): void
  {
    $table = $this->requireTable();
    if (
      $this->getStringParameter("log_created_at") == "true" &&
      !$table->hasColumn($this->getStringParameter("version_created_at_column"))
    ) {
      $table->addColumn([
        "name" => $this->getStringParameter("version_created_at_column"),
        "type" => "TIMESTAMP",
      ]);
    }
    if (
      $this->getStringParameter("log_created_by") == "true" &&
      !$table->hasColumn($this->getStringParameter("version_created_by_column"))
    ) {
      $table->addColumn([
        "name" => $this->getStringParameter("version_created_by_column"),
        "type" => "VARCHAR",
        "size" => 100,
      ]);
    }
    if (
      $this->getStringParameter("log_comment") == "true" &&
      !$table->hasColumn($this->getStringParameter("version_comment_column"))
    ) {
      $table->addColumn([
        "name" => $this->getStringParameter("version_comment_column"),
        "type" => "VARCHAR",
        "size" => 255,
      ]);
    }
  }

  protected function addVersionTable(): void
  {
    $table = $this->requireTable();
    $database = $this->requireDatabase($table);
    $versionTableName = $this->getStringParameter("version_table")
      ? $this->getStringParameter("version_table")
      : $table->getName() . "_version";
    if (!$database->hasTable($versionTableName)) {
      $versionTableName = str_replace(
        $table->getSchema() . ".",
        "",
        $versionTableName,
      );
      // create the version table
      $versionTable = $database->addTable([
        "name" => $versionTableName,
        "phpName" => $this->getVersionTablePhpName(),
        "package" => $table->getPackage(),
        "schema" => $table->getSchema(),
        "namespace" => $table->getNamespace()
          ? "\\" . $table->getNamespace()
          : null,
      ]);
      // every behavior adding a table should re-execute database behaviors
      foreach ($database->getBehaviors() as $behavior) {
        $behavior->modifyDatabase();
      }
      // copy all the columns
      foreach ($table->getColumns() as $column) {
        $columnInVersionTable = clone $column;
        if ($columnInVersionTable->hasReferrers()) {
          $columnInVersionTable->clearReferrers();
        }
        if ($columnInVersionTable->isAutoincrement()) {
          $columnInVersionTable->setAutoIncrement(false);
        }
        $versionTable->addColumn($columnInVersionTable);
      }
      // create the foreign key
      $fk = new ForeignKey();
      $fk->setForeignTableCommonName($table->getCommonName());
      $fk->setForeignSchemaName($table->getSchema());
      $fk->setOnDelete("CASCADE");
      $fk->setOnUpdate(null);
      $tablePKs = $table->getPrimaryKey();
      foreach ($versionTable->getPrimaryKey() as $key => $column) {
        $fk->addReference($column, $tablePKs[$key]);
      }
      $versionTable->addForeignKey($fk);

      // add the version column to the primary key
      $versionColumnName = $this->getStringParameter("version_column");
      $versionColumn = $versionTable->getColumn($versionColumnName);
      if ($versionColumn === null) {
        throw new EngineException(
          sprintf(
            "Version column '%s' was not found on table '%s' right after being added",
            $versionColumnName,
            $versionTable->getName(),
          ),
        );
      }
      $versionColumn->setNotNull(true);
      $versionColumn->setPrimaryKey(true);
      $this->versionTable = $versionTable;
    } else {
      $this->versionTable = $database->getTable($versionTableName);
    }
  }

  public function addForeignKeyVersionColumns(): void
  {
    $versionTable = $this->requireVersionTable();
    foreach ($this->getVersionableFks() as $fk) {
      $fkVersionColumnName = $fk->getLocalColumnName() . "_version";
      if (!$versionTable->containsColumn($fkVersionColumnName)) {
        $versionTable->addColumn([
          "name" => $fkVersionColumnName,
          "type" => "INTEGER",
          "default" => 0,
        ]);
      }
    }
    foreach ($this->getVersionableReferrers() as $fk) {
      $fkTableName = $this->requireForeignKeyTable($fk)->getName();
      $fkIdsColumnName = $fkTableName . "_ids";
      if (!$versionTable->containsColumn($fkIdsColumnName)) {
        $versionTable->addColumn([
          "name" => $fkIdsColumnName,
          "type" => "ARRAY",
        ]);
      }
      $fkVersionsColumnName = $fkTableName . "_versions";
      if (!$versionTable->containsColumn($fkVersionsColumnName)) {
        $versionTable->addColumn([
          "name" => $fkVersionsColumnName,
          "type" => "ARRAY",
        ]);
      }
    }
  }

  public function getVersionTable(): ?Table
  {
    return $this->versionTable;
  }

  public function getVersionTablePhpName(): string
  {
    return $this->requireTable()->getPhpName() . "Version";
  }

  /** @return array<int, ForeignKey> */
  public function getVersionableFks(): array
  {
    $versionableFKs = [];
    foreach ($this->requireTable()->getForeignKeys() as $fk) {
      $foreignTable = $fk->getForeignTable();
      if (
        $foreignTable !== null &&
        $foreignTable->hasBehavior("versionable") &&
        !$fk->isComposite()
      ) {
        $versionableFKs[] = $fk;
      }
    }
    return $versionableFKs;
  }

  /** @return array<int, ForeignKey> */
  public function getVersionableReferrers(): array
  {
    $versionableReferrers = [];
    foreach ($this->requireTable()->getReferrers() as $fk) {
      if (
        $this->requireForeignKeyTable($fk)->hasBehavior("versionable") &&
        !$fk->isComposite()
      ) {
        $versionableReferrers[] = $fk;
      }
    }
    return $versionableReferrers;
  }

  public function getReferrerIdsColumn(ForeignKey $fk): Column
  {
    $fkTableName = $this->requireForeignKeyTable($fk)->getName();
    $fkIdsColumnName = $fkTableName . "_ids";
    return $this->requireVersionTableColumn($fkIdsColumnName);
  }

  public function getReferrerVersionsColumn(ForeignKey $fk): Column
  {
    $fkTableName = $this->requireForeignKeyTable($fk)->getName();
    $fkIdsColumnName = $fkTableName . "_versions";
    return $this->requireVersionTableColumn($fkIdsColumnName);
  }

  private function requireVersionTableColumn(string $name): Column
  {
    $column = $this->requireVersionTable()->getColumn($name);
    if ($column === null) {
      throw new EngineException(
        sprintf("Column '%s' was not found on the version table", $name),
      );
    }
    return $column;
  }

  /**
   * Non-nullable wrapper around getColumnForParameter(), for callers
   * (e.g. the builder modifiers) that only ever call this once the
   * behavior is fully attached and its parameters resolved to real
   * columns on the table.
   */
  public function requireColumnForParameter(string $param): Column
  {
    $column = $this->getColumnForParameter($param);
    if ($column === null) {
      throw new EngineException(
        sprintf(
          "Parameter '%s' does not reference an existing column",
          $param,
        ),
      );
    }
    return $column;
  }

  public function getObjectBuilderModifier(): VersionableBehaviorObjectBuilderModifier
  {
    if (is_null($this->objectBuilderModifier)) {
      $this->objectBuilderModifier = new VersionableBehaviorObjectBuilderModifier(
        $this,
      );
    }
    return $this->objectBuilderModifier;
  }

  public function getQueryBuilderModifier(): VersionableBehaviorQueryBuilderModifier
  {
    if (is_null($this->queryBuilderModifier)) {
      $this->queryBuilderModifier = new VersionableBehaviorQueryBuilderModifier(
        $this,
      );
    }
    return $this->queryBuilderModifier;
  }

  public function getPeerBuilderModifier(): VersionableBehaviorPeerBuilderModifier
  {
    if (is_null($this->peerBuilderModifier)) {
      $this->peerBuilderModifier = new VersionableBehaviorPeerBuilderModifier(
        $this,
      );
    }
    return $this->peerBuilderModifier;
  }
}
