<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
 namespace Propulsion\Generator\Platform;
/**
 * Interface for RDBMS platform specific behaviour.
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @author     Martin Poeschl <mpoeschl@marmot.at> (Torque)
 * @version    $Revision$
 */

 use Propulsion\Generator\Config\GeneratorConfig;
 use Propulsion\Generator\Model\Domain;
 use Propulsion\Generator\Model\Column;
 use Propulsion\Generator\Model\Table;
 use Propulsion\Generator\Model\Diff\PropulsionDatabaseDiff;
interface PropulsionPlatformInterface
{

	/**
	 * Constant for auto-increment id method.
	 */
	const IDENTITY = "identity";

	/**
	 * Constant for sequence id method.
	 */
	const SEQUENCE = "sequence";

	/**
	 * Constant for serial id method (postgresql).
	 */
	const SERIAL = "serial";

	public function getSequenceName(Table $table): ?string;
	/**
	 * Sets a database connection to use (for quoting, etc.).
	 * @param      \PDO|null $con The database connection to use in this Platform class.
	 */
	public function setConnection(?\PDO $con = null): void;

	/**
	 * Returns the database connection to use for this Platform class.
	 * @return     \PDO|null The database connection or NULL if none has been set.
	 */
	public function getConnection();

	/**
	 * Sets the GeneratorConfig which contains any generator build properties.
	 *
	 * @param      GeneratorConfig $config
	 */
	public function setGeneratorConfig(GeneratorConfig $config): void;

	/**
	 * Returns the short name of the database type that this platform represents.
	 * For example MysqlPlatform->getDatabaseType() returns 'mysql'.
	 * @return     string
	 */
	public function getDatabaseType();

	/**
	 * Returns the native IdMethod (sequence|identity)
	 *
	 * @return     string The native IdMethod (PropulsionPlatformInterface:IDENTITY, PropulsionPlatformInterface::SEQUENCE).
	 */
	public function getNativeIdMethod();

	/**
	 * Returns the max column length supported by the db.
	 *
	 * @return     int The max column length
	 */
	public function getMaxColumnNameLength();

	/**
	 * Returns the db specific domain for a propelType.
	 *
	 * @param      string $propelType the Propulsion type name.
	 * @return     Domain The db specific domain.
	 */
	public function getDomainForType($propelType);

	/**
	 * @return     string The RDBMS-specific SQL fragment for <code>NULL</code>
	 * or <code>NOT NULL</code>.
	 */
	public function getNullString(bool $notNull);

	/**
	 * @return     string The RDBMS-specific SQL fragment for autoincrement.
	 */
	public function getAutoIncrement();

	/**
	 * Returns the DDL SQL for a Column object.
	 * @return     string
	 */
	public function getColumnDDL(Column $col);

	/**
	 * Returns the SQL for the default value of a Column object.
	 * @return     string
	 */
	public function getColumnDefaultValueDDL(Column $col);

	/**
	 * Creates a delimiter-delimited string list of column names, quoted using quoteIdentifier().
	 * @example
	 * <code>
	 * echo $platform->getColumnListDDL(array('foo', 'bar');
	 * // '"foo","bar"'
	 * </code>
	 * @param      Column[]|string[] $columns
	 * @param      string $delimiter The delimiter to use in separating the column names.
	 *
	 * @return     string
	 */
	public function getColumnListDDL($columns, $delimiter = ',');

	/**
	 * Returns the SQL for the primary key of a Table object
	 * @return     string
	 */
	public function getPrimaryKeyDDL(Table $table);

	/**
	 * Builds the DDL SQL to modify a database
	 * based on a PropulsionDatabaseDiff instance
	 *
	 * @return     string
	 */
	public function getModifyDatabaseDDL(PropulsionDatabaseDiff $databaseDiff);

	/**
	 * Returns if the RDBMS-specific SQL type has a size attribute.
	 *
	 * @param      string $sqlType the SQL type
	 * @return     boolean True if the type has a size attribute
	 */
	public function hasSize($sqlType);

	/**
	 * Returns if the RDBMS-specific SQL type has a scale attribute.
	 *
	 * @param      string $sqlType the SQL type
	 * @return     boolean True if the type has a scale attribute
	 */
	public function hasScale($sqlType);

	/**
	 * Quote and escape needed characters in the string for unerlying RDBMS.
	 * @param      string $text
	 * @return     string
	 */
	public function quote($text);

	/**
	 * Quotes identifiers used in database SQL.
	 * @param      string $text
	 * @return     string Quoted identifier.
	 */
	public function quoteIdentifier($text);

	/**
	 * Whether RDBMS supports native ON DELETE triggers (e.g. ON DELETE CASCADE).
	 * @return     boolean
	 */
	public function supportsNativeDeleteTrigger();

	/**
	 * Whether RDBMS supports INSERT null values in autoincremented primary keys
	 * @return     boolean
	 */
	public function supportsInsertNullPk();

	/**
	 * Whether RDBMS supports native schemas for table layout.
	 * @return boolean
	 */
	public function supportsSchemas();

	/**
	 * Whether RDBMS supports migrations.
	 * @return boolean
	 */
	public function supportsMigrations();

	/**
	 * Whether this RDBMS's DDL statements (CREATE TABLE, ALTER TABLE, ...) are
	 * genuinely transactional -- i.e. can be rolled back as part of a
	 * transaction if a later statement in the same migration fails, leaving
	 * no partial schema change behind.
	 *
	 * This is deliberately conservative: most RDBMS DDL is NOT safely
	 * transactional (e.g. MySQL's DDL causes an implicit commit), so the
	 * default implementation returns false. Only platforms with confirmed
	 * transactional DDL (Postgres, SQLite) override this to true.
	 *
	 * @return boolean
	 */
	public function supportsTransactionalDDL();

	/**
	 * Wether RDBMS supports VARCHAR without explicit size
	 * @return boolean
	 */
	public function supportsVarcharWithoutSize();
	
	/**
	 * Returns the boolean value for the RDBMS.
	 *
	 * This value should match the boolean value that is set
	 * when using Propulsion's PreparedStatement::setBoolean().
	 *
	 * This function is used to set default column values when building
	 * SQL.
	 *
	 * @param      mixed $tf A boolean or string representation of boolean ('y', 'true').
	 * @return     mixed
	 */
	public function getBooleanString($tf);

	/**
	 * Whether the underlying PDO driver for this platform returns BLOB columns as streams (instead of strings).
	 * @return     boolean
	 */
	public function hasStreamBlobImpl();

	/**
	 * Gets the preferred timestamp formatter for setting date/time values.
	 * @return     string
	 */
	public function getTimestampFormatter();

	/**
	 * Gets the preferred date formatter for setting time values.
	 * @return     string
	 */
	public function getDateFormatter();

	/**
	 * Gets the preferred time formatter for setting time values.
	 * @return     string
	 */
	public function getTimeFormatter();
}
