<?php

/**
 * This file is part of the Propulsion package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */
 namespace Propulsion\Generator\Model;

/**
 * A class that maps PropulsionTypes to PHP native types, PDO types (and Creole types).
 *
 * @author     Hans Lellelid <hans@xmpl.org> (Propel)
 * @version    $Revision$
 */

 use PDO;
class PropulsionTypes
{

	const CHAR = "CHAR";
	const VARCHAR = "VARCHAR";
	const LONGVARCHAR = "LONGVARCHAR";
	const CLOB = "CLOB";
	const CLOB_EMU = "CLOB_EMU";
	const NUMERIC = "NUMERIC";
	const DECIMAL = "DECIMAL";
	const TINYINT = "TINYINT";
	const SMALLINT = "SMALLINT";
	const INTEGER = "INTEGER";
	const BIGINT = "BIGINT";
	const REAL = "REAL";
	const FLOAT = "FLOAT";
	const DOUBLE = "DOUBLE";
	const BINARY = "BINARY";
	const VARBINARY = "VARBINARY";
	const LONGVARBINARY = "LONGVARBINARY";
	const BLOB = "BLOB";
	const DATE = "DATE";
	const TIME = "TIME";
	const TIMESTAMP = "TIMESTAMP";
	const BU_DATE = "BU_DATE";
	const BU_TIMESTAMP = "BU_TIMESTAMP";
	const BOOLEAN = "BOOLEAN";
	const BOOLEAN_EMU = "BOOLEAN_EMU";
	const OBJECT = "OBJECT";
	const PHP_ARRAY = "ARRAY";
	const ENUM = "ENUM";
	const JSON = "JSON";
	const JSONB = "JSONB";
	const UUID = "UUID";
	const INTERVAL = "INTERVAL";
	const INET = "INET";
	const CIDR = "CIDR";
	const MACADDR = "MACADDR";
	const CITEXT = "CITEXT";
	const INT4RANGE = "INT4RANGE";
	const INT8RANGE = "INT8RANGE";
	const NUMRANGE = "NUMRANGE";
	const DATERANGE = "DATERANGE";
	const TSRANGE = "TSRANGE";
	const TSTZRANGE = "TSTZRANGE";
	const VECTOR = "VECTOR";
	const GEOMETRY = "GEOMETRY";

	/** @var array<int|string, string> */
	protected static array $creoleToPropulsionTypeMap = [];

	/**
	 * JSON columns are stored as real JSON text (via json_encode()/json_decode()),
	 * not PHP serialize() like OBJECT/PHP_ARRAY -- see ObjectBuilder::addHydrate()/
	 * addBuildCriteria() and BaseObject::decodeJsonColumn()/encodeJsonColumn().
	 *
	 * @var string[]
	 */
	private static array $JSON_TYPES = array(
		self::JSON, self::JSONB
	);

	/** @var string[] */
	private static array $TEXT_TYPES = array(
		self::CHAR, self::VARCHAR, self::LONGVARCHAR, self::CLOB, self::DATE, self::TIME, self::TIMESTAMP, self::BU_DATE, self::BU_TIMESTAMP, self::UUID, self::INTERVAL, self::INET, self::CIDR, self::MACADDR, self::CITEXT,
		self::INT4RANGE, self::INT8RANGE, self::NUMRANGE, self::DATERANGE, self::TSRANGE, self::TSTZRANGE, self::GEOMETRY
	);

	/**
	 * Postgres range types -- see Column::isRangeType()/ObjectBuilder's
	 * hydrate()/buildCriteria() branches, which map these to/from
	 * Propulsion\Type\Range instead of the plain string every other TEXT_TYPES
	 * entry gets.
	 *
	 * @var string[]
	 */
	private static array $RANGE_TYPES = array(
		self::INT4RANGE, self::INT8RANGE, self::NUMRANGE, self::DATERANGE, self::TSRANGE, self::TSTZRANGE
	);

	/** @var string[] */
	private static array $LOB_TYPES = array(
		self::VARBINARY, self::LONGVARBINARY, self::BLOB
	);

	/** @var string[] */
	private static array $TEMPORAL_TYPES = array(
		self::DATE, self::TIME, self::TIMESTAMP, self::BU_DATE, self::BU_TIMESTAMP
	);

	/** @var string[] */
	private static array $NUMERIC_TYPES = array(
		self::SMALLINT, self::TINYINT, self::INTEGER, self::BIGINT, self::FLOAT, self::DOUBLE, self::NUMERIC, self::DECIMAL, self::REAL
	);

	/** @var string[] */
	private static array $BOOLEAN_TYPES = array(
		self::BOOLEAN, self::BOOLEAN_EMU
	);

	const CHAR_NATIVE_TYPE = "string";
	const VARCHAR_NATIVE_TYPE = "string";
	const LONGVARCHAR_NATIVE_TYPE = "string";
	const CLOB_NATIVE_TYPE = "string";
	// Despite the "EMU" name suggesting the same kind of stream-backed emulation as
	// e.g. BLOB, CLOB_EMU's PHP-side representation is a plain string, same as CLOB
	// -- OraclePlatform aliases a schema CLOB column's domain to this type purely
	// so DBOracle::bindValue()/PropulsionColumnTypes can special-case its bind
	// parameter handling (see DBOracle::bindValue()'s own CLOB_EMU branch, which
	// throws unless given a string). Native type "resource" here was wrong: it made
	// isPhpPrimitiveType() report CLOB_EMU columns as non-primitive without them
	// ever being added to $LOB_TYPES either, so the generated lazy-loader's
	// non-LOB branch (see ObjectBuilder::addLazyLoader()) emitted an
	// (resource) $row[0] cast -- not a real PHP cast at all -- corrupting any
	// generated Base*.php for a table with a CLOB column under Oracle with a
	// syntax error.
	const CLOB_EMU_NATIVE_TYPE = "string";
	const NUMERIC_NATIVE_TYPE = "string";
	const DECIMAL_NATIVE_TYPE = "string";
	const TINYINT_NATIVE_TYPE = "int";
	const SMALLINT_NATIVE_TYPE = "int";
	const INTEGER_NATIVE_TYPE = "int";
	const BIGINT_NATIVE_TYPE = "int";
	const REAL_NATIVE_TYPE = "double";
	const FLOAT_NATIVE_TYPE = "double";
	const DOUBLE_NATIVE_TYPE = "double";
	const BINARY_NATIVE_TYPE = "string";
	const VARBINARY_NATIVE_TYPE = "string";
	const LONGVARBINARY_NATIVE_TYPE = "string";
	const BLOB_NATIVE_TYPE = "string";
	const BU_DATE_NATIVE_TYPE = "string";
	const DATE_NATIVE_TYPE = "string";
	const TIME_NATIVE_TYPE = "string";
	const TIMESTAMP_NATIVE_TYPE = "string";
	const BU_TIMESTAMP_NATIVE_TYPE = "string";
	const BOOLEAN_NATIVE_TYPE = "boolean";
	const BOOLEAN_EMU_NATIVE_TYPE = "boolean";
	const OBJECT_NATIVE_TYPE = "";
	const PHP_ARRAY_NATIVE_TYPE = "array";
	const ENUM_NATIVE_TYPE = "string";
	// Like OBJECT, a JSON document's decoded PHP shape isn't a single native type
	// (json_decode() can yield an array, a scalar, or null) -- see ObjectBuilder's
	// getPhp84TypeHint()/getPhp84PropertyType(), which special-case JSON/JSONB the
	// same way they already special-case OBJECT, to a `mixed` PHP type.
	const JSON_NATIVE_TYPE = "";
	const JSONB_NATIVE_TYPE = "";
	const UUID_NATIVE_TYPE = "string";
	// Stored as text (an ISO-8601 duration string, e.g. "P1DT2H") on every
	// platform -- see ObjectBuilder's getPhp84TypeHint()/getPhp84PropertyType(),
	// which special-case INTERVAL to the real ?DateInterval object the same way
	// TIMESTAMP/DATE/TIME are special-cased to ?DateTimeInterface.
	const INTERVAL_NATIVE_TYPE = "string";
	// Pg network types and citext -- no rich PHP value object for these (v1),
	// hydrated as plain strings the same way UUID is.
	const INET_NATIVE_TYPE = "string";
	const CIDR_NATIVE_TYPE = "string";
	const MACADDR_NATIVE_TYPE = "string";
	const CITEXT_NATIVE_TYPE = "string";
	// Range types are stored as a Postgres range literal string (e.g.
	// "[1,10)") but, like INTERVAL, hydrate to a real value object
	// (Propulsion\Type\Range) -- see ObjectBuilder's getPhp84TypeHint()/
	// getPhp84PropertyType().
	const INT4RANGE_NATIVE_TYPE = "string";
	const INT8RANGE_NATIVE_TYPE = "string";
	const NUMRANGE_NATIVE_TYPE = "string";
	const DATERANGE_NATIVE_TYPE = "string";
	const TSRANGE_NATIVE_TYPE = "string";
	const TSTZRANGE_NATIVE_TYPE = "string";
	// Same native PHP type as PHP_ARRAY (a plain array<float>) -- see
	// ObjectBuilder's addHydrate()/addBuildCriteria(), which json_encode()/
	// json_decode() it (reusing BaseObject::encodeJsonColumn()/
	// decodeJsonColumn()) rather than PHP_ARRAY's " | "-delimited format,
	// since a vector's wire format on every platform (pgvector, MariaDB/MySQL
	// VECTOR) is a bracketed comma-separated number list -- valid JSON already.
	const VECTOR_NATIVE_TYPE = "array";
	// Stored (and hydrated) as a plain WKT ("well-known text", e.g.
	// "POINT(1 2)") string on every platform -- deliberately emulated as text
	// everywhere rather than attempting each platform's real native geometry
	// column type (PostGIS geometry, MySQL GEOMETRY, MSSQL geometry, Oracle
	// SDO_GEOMETRY): none of those accept/return raw WKT text through a plain
	// parameterized bind the way this codebase's other "native" mappings do
	// (UUID, JSON, etc.) -- they need the bound value wrapped in a
	// platform-specific conversion function (ST_GeomFromText()/
	// STGeomFromText()/SDO_UTIL.FROM_WKTGEOMETRY()) at the SQL-statement
	// level, which is a query-layer change (BasePeer/Criteria column-specific
	// SQL rewriting), not a type-system one -- see PLATFORM_FEATURES.md.
	const GEOMETRY_NATIVE_TYPE = "string";

	/**
	 * Mapping between Propulsion types and PHP native types.
	 *
	 * @var        array<string, string>
	 */
	private static array $propelToPHPNativeMap = array(
			self::CHAR => self::CHAR_NATIVE_TYPE,
			self::VARCHAR => self::VARCHAR_NATIVE_TYPE,
			self::LONGVARCHAR => self::LONGVARCHAR_NATIVE_TYPE,
			self::CLOB => self::CLOB_NATIVE_TYPE,
			self::CLOB_EMU => self::CLOB_EMU_NATIVE_TYPE,
			self::NUMERIC => self::NUMERIC_NATIVE_TYPE,
			self::DECIMAL => self::DECIMAL_NATIVE_TYPE,
			self::TINYINT => self::TINYINT_NATIVE_TYPE,
			self::SMALLINT => self::SMALLINT_NATIVE_TYPE,
			self::INTEGER => self::INTEGER_NATIVE_TYPE,
			self::BIGINT => self::BIGINT_NATIVE_TYPE,
			self::REAL => self::REAL_NATIVE_TYPE,
			self::FLOAT => self::FLOAT_NATIVE_TYPE,
			self::DOUBLE => self::DOUBLE_NATIVE_TYPE,
			self::BINARY => self::BINARY_NATIVE_TYPE,
			self::VARBINARY => self::VARBINARY_NATIVE_TYPE,
			self::LONGVARBINARY => self::LONGVARBINARY_NATIVE_TYPE,
			self::BLOB => self::BLOB_NATIVE_TYPE,
			self::DATE => self::DATE_NATIVE_TYPE,
			self::BU_DATE => self::BU_DATE_NATIVE_TYPE,
			self::TIME => self::TIME_NATIVE_TYPE,
			self::TIMESTAMP => self::TIMESTAMP_NATIVE_TYPE,
			self::BU_TIMESTAMP => self::BU_TIMESTAMP_NATIVE_TYPE,
			self::BOOLEAN => self::BOOLEAN_NATIVE_TYPE,
			self::BOOLEAN_EMU => self::BOOLEAN_EMU_NATIVE_TYPE,
			self::OBJECT => self::OBJECT_NATIVE_TYPE,
			self::PHP_ARRAY => self::PHP_ARRAY_NATIVE_TYPE,
			self::ENUM => self::ENUM_NATIVE_TYPE,
			self::JSON => self::JSON_NATIVE_TYPE,
			self::JSONB => self::JSONB_NATIVE_TYPE,
			self::UUID => self::UUID_NATIVE_TYPE,
			self::INTERVAL => self::INTERVAL_NATIVE_TYPE,
			self::INET => self::INET_NATIVE_TYPE,
			self::CIDR => self::CIDR_NATIVE_TYPE,
			self::MACADDR => self::MACADDR_NATIVE_TYPE,
			self::CITEXT => self::CITEXT_NATIVE_TYPE,
			self::INT4RANGE => self::INT4RANGE_NATIVE_TYPE,
			self::INT8RANGE => self::INT8RANGE_NATIVE_TYPE,
			self::NUMRANGE => self::NUMRANGE_NATIVE_TYPE,
			self::DATERANGE => self::DATERANGE_NATIVE_TYPE,
			self::TSRANGE => self::TSRANGE_NATIVE_TYPE,
			self::TSTZRANGE => self::TSTZRANGE_NATIVE_TYPE,
			self::VECTOR => self::VECTOR_NATIVE_TYPE,
			self::GEOMETRY => self::GEOMETRY_NATIVE_TYPE,
	);

	/**
	 * Mapping between Propulsion types and Creole types (for rev-eng task)
	 *
	 * @var        array<string, string>
	 */
	private static array $propelTypeToCreoleTypeMap = array(

			self::CHAR => self::CHAR,
			self::VARCHAR => self::VARCHAR,
			self::LONGVARCHAR => self::LONGVARCHAR,
			self::CLOB => self::CLOB,
			self::NUMERIC => self::NUMERIC,
			self::DECIMAL => self::DECIMAL,
			self::TINYINT => self::TINYINT,
			self::SMALLINT => self::SMALLINT,
			self::INTEGER => self::INTEGER,
			self::BIGINT => self::BIGINT,
			self::REAL => self::REAL,
			self::FLOAT => self::FLOAT,
			self::DOUBLE => self::DOUBLE,
			self::BINARY => self::BINARY,
			self::VARBINARY => self::VARBINARY,
			self::LONGVARBINARY => self::LONGVARBINARY,
			self::BLOB => self::BLOB,
			self::DATE => self::DATE,
			self::TIME => self::TIME,
			self::TIMESTAMP => self::TIMESTAMP,
			self::BOOLEAN => self::BOOLEAN,
			self::BOOLEAN_EMU => self::BOOLEAN_EMU,
			self::OBJECT => self::OBJECT,
			self::PHP_ARRAY => self::PHP_ARRAY,
			self::ENUM => self::ENUM,
			self::JSON => self::JSON,
			self::JSONB => self::JSONB,
			self::UUID => self::UUID,
			self::INTERVAL => self::INTERVAL,
			self::INET => self::INET,
			self::CIDR => self::CIDR,
			self::MACADDR => self::MACADDR,
			self::CITEXT => self::CITEXT,
			self::INT4RANGE => self::INT4RANGE,
			self::INT8RANGE => self::INT8RANGE,
			self::NUMRANGE => self::NUMRANGE,
			self::DATERANGE => self::DATERANGE,
			self::TSRANGE => self::TSRANGE,
			self::TSTZRANGE => self::TSTZRANGE,
			self::VECTOR => self::VECTOR,
			self::GEOMETRY => self::GEOMETRY,
			// These are pre-epoch dates, which we need to map to String type
			// since they cannot be properly handled using strtotime() -- or even numeric
			// timestamps on Windows.
			self::BU_DATE => self::VARCHAR,
			self::BU_TIMESTAMP => self::VARCHAR,

	);

	/**
	 * Mapping between Propulsion types and PDO type contants (for prepared statement setting).
	 *
	 * @var        array<string, int>
	 */
	private static array $propelTypeToPDOTypeMap = array(
			self::CHAR => PDO::PARAM_STR,
			self::VARCHAR => PDO::PARAM_STR,
			self::LONGVARCHAR => PDO::PARAM_STR,
			self::CLOB => PDO::PARAM_STR,
			self::CLOB_EMU => PDO::PARAM_STR,
			self::NUMERIC => PDO::PARAM_INT,
			self::DECIMAL => PDO::PARAM_STR,
			self::TINYINT => PDO::PARAM_INT,
			self::SMALLINT => PDO::PARAM_INT,
			self::INTEGER => PDO::PARAM_INT,
			self::BIGINT => PDO::PARAM_INT,
			self::REAL => PDO::PARAM_STR,
			self::FLOAT => PDO::PARAM_STR,
			self::DOUBLE => PDO::PARAM_STR,
			self::BINARY => PDO::PARAM_STR,
			self::VARBINARY => PDO::PARAM_LOB,
			self::LONGVARBINARY => PDO::PARAM_LOB,
			self::BLOB => PDO::PARAM_LOB,
			self::DATE => PDO::PARAM_STR,
			self::TIME => PDO::PARAM_STR,
			self::TIMESTAMP => PDO::PARAM_STR,
			self::BOOLEAN => PDO::PARAM_BOOL,
			self::BOOLEAN_EMU => PDO::PARAM_INT,
			self::OBJECT => PDO::PARAM_STR,
			self::PHP_ARRAY => PDO::PARAM_STR,
			self::ENUM => PDO::PARAM_INT,
			self::JSON => PDO::PARAM_STR,
			self::JSONB => PDO::PARAM_STR,
			self::UUID => PDO::PARAM_STR,
			self::INTERVAL => PDO::PARAM_STR,
			self::INET => PDO::PARAM_STR,
			self::CIDR => PDO::PARAM_STR,
			self::MACADDR => PDO::PARAM_STR,
			self::CITEXT => PDO::PARAM_STR,
			self::INT4RANGE => PDO::PARAM_STR,
			self::INT8RANGE => PDO::PARAM_STR,
			self::NUMRANGE => PDO::PARAM_STR,
			self::DATERANGE => PDO::PARAM_STR,
			self::TSRANGE => PDO::PARAM_STR,
			self::TSTZRANGE => PDO::PARAM_STR,
			self::VECTOR => PDO::PARAM_STR,
			self::GEOMETRY => PDO::PARAM_STR,

			// These are pre-epoch dates, which we need to map to String type
			// since they cannot be properly handled using strtotime() -- or even numeric
			// timestamps on Windows.
			self::BU_DATE => PDO::PARAM_STR,
			self::BU_TIMESTAMP => PDO::PARAM_STR,
	);

	/**
	 * Return native PHP type which corresponds to the
	 * Creole type provided. Use in the base object class generation.
	 *
	 * @param      $propelType The Propulsion type name.
	 * @return     string Name of the native PHP type
	 */
	public static function getPhpNative(string $propelType)
	{
		return self::$propelToPHPNativeMap[$propelType];
	}

	/**
	 * Returns the correct Creole type _name_ for propel added types
	 *
	 * @param      string $type the propel added type.
	 * @return     string Name of the the correct Creole type (e.g. "VARCHAR").
	 */
	public static function getCreoleType(string $type)
	{
		return  self::$propelTypeToCreoleTypeMap[$type];
	}

	/**
	 * Resturns the PDO type (PDO::PARAM_* constant) value.
	 * @return     int
	 */
	public static function getPDOType(string $type)
	{
		return self::$propelTypeToPDOTypeMap[$type];
	}

	/**
	 * Returns Propulsion type constant corresponding to Creole type code.
	 * Used but Propulsion Creole task.
	 *
	 * @param      int $sqlType The Creole SQL type constant.
	 * @return     string|null The Propulsion type to use or NULL if none found.
	 */
	public static function getPropulsionType($sqlType)
	{
		if (isset(self::$creoleToPropulsionTypeMap[$sqlType])) {
			return self::$creoleToPropulsionTypeMap[$sqlType];
		}
		return null;
	}

	/**
	 * Get array of Propulsion types.
	 *
	 * @return     string[]
	 */
	public static function getPropulsionTypes()
	{
		return array_keys(self::$propelTypeToCreoleTypeMap);
	}

	/**
	 * Whether passed type is a temporal (date/time/timestamp) type.
	 *
	 * @param      string $type Propulsion type
	 * @return     boolean
	 */
	public static function isTemporalType($type)
	{
		return in_array($type, self::$TEMPORAL_TYPES);
	}

	/**
	 * Returns true if values for the type need to be quoted.
	 *
	 * @param      string $type The Propulsion type to check.
	 * @return     boolean True if values for the type need to be quoted.
	 */
	public static function isTextType($type)
	{
		return in_array($type, self::$TEXT_TYPES);
	}

	/**
	 * Returns true if values for the type are numeric.
	 *
	 * @param      string $type The Propulsion type to check.
	 * @return     boolean True if values for the type need to be quoted.
	 */
	public static function isNumericType($type)
	{
		return in_array($type, self::$NUMERIC_TYPES);
	}

	/**
	 * Returns true if values for the type are boolean.
	 *
	 * @param      string $type The Propulsion type to check.
	 * @return     boolean True if values for the type need to be quoted.
	 */
	public static function isBooleanType($type)
	{
		return in_array($type, self::$BOOLEAN_TYPES);
	}

	/**
	 * Returns true if type is a LOB type (i.e. would be handled by Blob/Clob class).
	 * @param      string $type Propulsion type to check.
	 * @return     boolean
	 */
	public static function isLobType($type)
	{
		return in_array($type, self::$LOB_TYPES);
	}

	/**
	 * Returns true if type is a JSON/JSONB type (stored as real JSON text via
	 * json_encode()/json_decode(), unlike OBJECT/PHP_ARRAY which use serialize()
	 * or a custom delimited format).
	 *
	 * @param      string $type The Propulsion type to check.
	 * @return     boolean
	 */
	public static function isJsonType($type)
	{
		return in_array($type, self::$JSON_TYPES);
	}

	/**
	 * Returns true if type is a Postgres range type (mapped to
	 * Propulsion\Type\Range, not a plain string).
	 *
	 * @param      string $type The Propulsion type to check.
	 * @return     boolean
	 */
	public static function isRangeType($type)
	{
		return in_array($type, self::$RANGE_TYPES);
	}

	/**
	 * Convenience method to indicate whether a passed-in PHP type is a primitive.
	 *
	 * @param      string $phpType The PHP type to check
	 * @return     boolean Whether the PHP type is a primitive (string, int, boolean, float)
	 */
	public static function isPhpPrimitiveType($phpType)
	{
		return in_array($phpType, array("boolean", "int", "double", "float", "string"));
	}

	/**
	 * Convenience method to indicate whether a passed-in PHP type is a numeric primitive.
	 *
	 * @param      string $phpType The PHP type to check
	 * @return     boolean Whether the PHP type is a primitive (string, int, boolean, float)
	 */
	public static function isPhpPrimitiveNumericType($phpType)
	{
		return in_array($phpType, array("boolean", "int", "double", "float"));
	}

	/**
	 * Convenience method to indicate whether a passed-in PHP type is an object.
	 *
	 * @param      string $phpType The PHP type to check
	 * @return     boolean Whether the PHP type is a primitive (string, int, boolean, float)
	 */
	public static function isPhpObjectType($phpType)
	{
		return (!self::isPhpPrimitiveType($phpType) && !in_array($phpType, array("resource", "array")));
	}
}
