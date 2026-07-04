<?php

/**
 * D E F A U L T   P R O P E R T I E S
 *
 * This file sets default build properties. You can override any of these by
 * specifying your new value in the build.properties (or build.php) file for
 * your project, or a top-level override file. Either way, you should not
 * need to edit this file.
 *
 * This is a plain PHP file returning a flat `['propulsion.foo' => ..., ...]`
 * array -- the modern replacement for the old Ant/Phing-style
 * `default.properties` text file (still supported for user-authored
 * build.properties overrides; see GeneratorConfig::parsePropertiesFile()).
 * `${propulsion.some.key}` placeholders are still resolved the same way they
 * always were, against the fully-merged flat array, regardless of which
 * format produced it.
 */
return [

    // -------------------------------------------------------------------
    //  B A S I C   P R O P E R T I E S
    // -------------------------------------------------------------------

    'propulsion.version' => '1.6.2-dev',

    'propulsion.home' => '.',

    // propulsion.project and propulsion.project.dir are intentionally NOT defaulted here --
    // see the matching <property> tasks at the top of build-propel.xml for why.
    //
    // propulsion.targetPackage is, for the same reason, NOT defaulted here as
    // "${propulsion.project}" either: the modern `bin/propulsion model:build` console path
    // (Propulsion\Generator\Manager\ModelManager) never sets propulsion.project at all (it
    // has no "project" concept -- see --namespace/--output-dir instead), so that template
    // would resolve to nothing and leave the literal "${propulsion.project}" as the target
    // package for any schema lacking its own `package` attribute. build-propel.xml
    // defaults propulsion.targetPackage from propulsion.project itself, the same way it does for
    // propulsion.project.dir, preserving that convenience for the Phing path (which always
    // requires propulsion.project) without the console path inheriting it.
    // PostgreSQL is this project's recommended/default database (see README.md) --
    // every fixture, CI job, and the test suite's default testcontainer target it,
    // and PgsqlPlatform gets the most feature-parity attention. Still a plain
    // per-project override like any other property: set propulsion.database in your
    // own build.properties, or pass --database on the console commands, to target
    // a different platform.
    'propulsion.database' => 'pgsql',
    'propulsion.targetPackage' => '',
    'propulsion.runOnlyOnSchemaChange' => 'false',

    // Default behavior settings
    //
    // Unset (rather than "php5") so GeneratorConfig::getBuilderClassname() falls
    // straight through to the unsuffixed propulsion.builder.*.class defaults below,
    // which (since Phase 3, see KNOWN_ISSUES.md) are the modern builders formerly
    // suffixed .php84. Explicitly set this to "php5" to opt back into the legacy
    // PHP5* builders via the propulsion.builder.*.php5.class overrides.
    'propulsion.targetPlatform' => '',
    'propulsion.packageObjectModel' => 'false',
    'propulsion.useDateTimeClass' => 'true',
    'propulsion.dateTimeClass' => 'DateTime',

    'propulsion.schema.validate' => 'true',
    'propulsion.schema.transform' => 'false',

    'propulsion.schema.autoPackage' => 'false',
    'propulsion.schema.autoNamespace' => 'false',
    'propulsion.schema.autoPrefix' => 'false',

    // controls what type of joins will be used in the doSelectJoin*() peer methods,
    // if set to true, LEFT JOINS will be used, INNER JOINS otherwise
    // see ticket #491, #588
    'propulsion.useLeftJoinsInDoJoinMethods' => 'true',

    // -------------------------------------------------------------------
    //  D A T A B A S E   S E T T I N G S
    // -------------------------------------------------------------------

    'propulsion.database.url' => '',
    'propulsion.database.buildUrl' => '${propulsion.database.url}',
    'propulsion.database.createUrl' => '${propulsion.database.buildUrl}',

    'propulsion.database.driver' => '',

    'propulsion.database.schema' => '',
    'propulsion.database.encoding' => '',
    'propulsion.database.manualCreation' => 'false',

    // if these arent blank then when we try to connect with insert-sql to a database
    // that doesnt require them and it isnt in the build.properties it sends
    // the ${blah} for the username and password
    'propulsion.database.user' => '',
    'propulsion.database.password' => '',

    // -------------------------------------------------------------------
    //  D A T A B A S E  T O  X M L  S E T T I N G S
    // -------------------------------------------------------------------

    'propulsion.samePhpName' => 'false',
    'propulsion.addVendorInfo' => 'false',
    'propulsion.addValidators' => 'none',

    // -------------------------------------------------------------------
    //  T E M P L A T E   V A R I A B L E S
    // -------------------------------------------------------------------

    'propulsion.addGenericAccessors' => 'true',
    'propulsion.addGenericMutators' => 'true',
    'propulsion.addSaveMethod' => 'true',
    'propulsion.addTimeStamp' => 'false',
    'propulsion.addValidateMethod' => 'true',
    'propulsion.addIncludes' => 'false',
    'propulsion.addHooks' => 'true',
    'propulsion.basePrefix' => 'Base',
    'propulsion.saveException' => 'PropulsionException',
    'propulsion.emulateForeignKeyConstraints' => 'false',

    // Identifier quoting may result in undesired behavior (especially in Postgres),
    // it can be disabled in DDL by setting this property to true in your build.properties file.
    'propulsion.disableIdentifierQuoting' => 'false',

    // These are the default formats that will be used when fetching values
    // from temporal columns in Propulsion. You can always specify these when
    // calling the methods directly, but for methods like getByName()
    // it is nice to change the defaults.

    'propulsion.defaultTimeStampFormat' => 'Y-m-d H:i:s',
    'propulsion.defaultTimeFormat' => '%X',
    'propulsion.defaultDateFormat' => '%x',

    'propulsion.namespace.om' => 'OM',
    'propulsion.namespace.map' => 'Map',
    'propulsion.namespace.autoPackage' => 'true',

    // -------------------------------------------------------------------
    //  D I R E C T O R I E S
    // -------------------------------------------------------------------

    'propulsion.conf.dir' => '${propulsion.project.dir}',
    'propulsion.schema.dir' => '${propulsion.project.dir}',
    'propulsion.templatePath' => '${propulsion.home}/templates',

    'propulsion.output.dir' => '${propulsion.project.dir}/build',
    'propulsion.php.dir' => '${propulsion.output.dir}/classes',
    'propulsion.phpconf.dir' => '${propulsion.output.dir}/conf',
    'propulsion.sql.dir' => '${propulsion.output.dir}/sql',
    'propulsion.migration.dir' => '${propulsion.output.dir}/migrations',

    'propulsion.graph.dir' => '${propulsion.output.dir}/graph',

    'propulsion.dbd2propel.dir' => '${propulsion.project.dir}/dbd',

    // -------------------------------------------------------------------
    //  D E F A U L T   F I L E   N A M E S
    // -------------------------------------------------------------------

    // propulsion.sqlfile

    'propulsion.runtime.conf.file' => 'runtime-conf.xml',
    'propulsion.runtime.phpconf.file' => '${propulsion.project}-conf.php',
    'propulsion.runtime.phpconf-classmap.file' => 'classmap-${propulsion.runtime.phpconf.file}',
    'propulsion.default.schema.basename' => 'schema',

    'propulsion.buildtime.conf.file' => 'buildtime-conf.xml',

    // Can't use because of inconsistencies in where the files
    // are named (some from build-propel.xml, but some from within templates)
    // propulsion.default.data.basename = ${propulsion.project}-data

    'propulsion.schema.xsd.file' => '${propulsion.home}/resources/xsd/database.xsd',
    'propulsion.schema.xsl.file' => '${propulsion.home}/resources/xsl/database.xsl',

    'propulsion.dbd2propel.xsl.file' => '${propulsion.home}/resources/xsl/dbd2propel.xsl',

    // -------------------------------------------------------------------
    //  I N C L U D E   A N D   E X C L U D E   S E T T I N G S
    // -------------------------------------------------------------------

    'propulsion.schema.sql.includes' => '*schema.xml',
    'propulsion.schema.sql.excludes' => '',
    'propulsion.schema.doc.includes' => '*schema.xml',
    'propulsion.schema.doc.excludes' => '',
    'propulsion.schema.create-db.includes' => '*schema.xml',
    'propulsion.schema.create-db.excludes' => '',
    'propulsion.schema.init-sql.includes' => '*schema.xml',
    'propulsion.schema.init-sql.excludes' => 'id-table-schema.xml',
    'propulsion.schema.om.includes' => '*schema.xml',
    'propulsion.schema.om.excludes' => 'id-table-schema.xml',
    'propulsion.schema.datadtd.includes' => '*schema.xml',
    'propulsion.schema.datadtd.excludes' => 'id-table-schema.xml',
    'propulsion.dbd2propel.includes' => '*.xml',

    // -------------------------------------------------------------------
    //  M A P P E R   S E T T I N G S
    // -------------------------------------------------------------------

    // (note: data xml files are selected based on datadbmap file)
    'propulsion.datasql.mapper.from' => '*.xml',
    'propulsion.datasql.mapper.to' => '*.sql',

    'propulsion.datadump.mapper.from' => '*schema.xml',
    'propulsion.datadump.mapper.to' => '*data.xml',

    'propulsion.datadtd.mapper.from' => '*.xml',
    'propulsion.datadtd.mapper.to' => '*.dtd',

    'propulsion.sql.mapper.from' => '*.xml',
    'propulsion.sql.mapper.to' => '*.sql',

    // -------------------------------------------------------------------
    //  M I G R A T I O N   S E T T I N G S
    // -------------------------------------------------------------------

    'propulsion.migration.editor' => '',
    'propulsion.migration.table' => 'propulsion_migration',
    'propulsion.migration.caseInsensitive' => 'true',

    // -------------------------------------------------------------------
    //  B U I L D E R   S E T T I N G S
    // -------------------------------------------------------------------

    // Object Model builders
    //
    // The PHP5 builders (PHP5PeerBuilder, PHP5ObjectBuilder, PHP5TableMapBuilder,
    // PHP5QueryBuilder, the node/nestedset family, and their .php5.class overrides)
    // have been removed from the codebase entirely -- see archaeology/php5-builders/
    // for the archived files and KNOWN_ISSUES.md for the fallout. Every
    // propulsion.builder.*.class key below is now unconditionally the modern (formerly
    // PHP84-suffixed) builder; propulsion.targetPlatform is effectively vestigial (there
    // is no alternate builder set left to select), kept only because
    // GeneratorConfig::getBuilderClassname() still supports platform-suffixed
    // overrides in general. The .php84.class entries are kept as an explicit,
    // redundant alias for anyone who still passes targetPlatform=php84 explicitly
    // (e.g. the namespaced test fixture does).
    //
    // NOTE: peer/object/objectstub/peerstub/objectmultiextend and the node/nestedset
    // family were, before this removal, considered "not ready to be default" because
    // of real completeness gaps relative to the removed PHP5 builders (temporal
    // column defaults needed a fix; the node/nestedset family in particular is full
    // of literal `{ /* Implementation */ }` no-op stub methods -- it's unclear
    // nested sets, specifically, have ever fully worked in this codebase). They are
    // default now anyway, since there's nothing left to fall back to. See
    // KNOWN_ISSUES.md for current known breakage.
    'propulsion.builder.peer.class' => 'Propulsion\Generator\Builder\OM\PeerBuilder',
    'propulsion.builder.object.class' => 'Propulsion\Generator\Builder\OM\ObjectBuilder',
    'propulsion.builder.objectstub.class' => 'Propulsion\Generator\Builder\OM\ExtensionObjectBuilder',
    'propulsion.builder.peerstub.class' => 'Propulsion\Generator\Builder\OM\ExtensionPeerBuilder',

    'propulsion.builder.objectmultiextend.class' => 'Propulsion\Generator\Builder\OM\MultiExtendObjectBuilder',

    'propulsion.builder.tablemap.class' => 'Propulsion\Generator\Builder\OM\TableMapBuilder',
    'propulsion.builder.query.class' => 'Propulsion\Generator\Builder\OM\QueryBuilder',
    'propulsion.builder.querystub.class' => 'Propulsion\Generator\Builder\OM\ExtensionQueryBuilder',
    'propulsion.builder.querystub.php84.class' => 'Propulsion\Generator\Builder\OM\ExtensionQueryBuilder',

    'propulsion.builder.interface.class' => 'Propulsion\Generator\Builder\OM\InterfaceBuilder',

    'propulsion.builder.node.class' => 'Propulsion\Generator\Builder\OM\NodeBuilder',
    'propulsion.builder.nodepeer.class' => 'Propulsion\Generator\Builder\OM\NodePeerBuilder',
    'propulsion.builder.nodestub.class' => 'Propulsion\Generator\Builder\OM\ExtensionNodeBuilder',
    'propulsion.builder.nodepeerstub.class' => 'Propulsion\Generator\Builder\OM\ExtensionNodePeerBuilder',

    'propulsion.builder.nestedset.class' => 'Propulsion\Generator\Builder\OM\NestedSetBuilder',
    'propulsion.builder.nestedsetpeer.class' => 'Propulsion\Generator\Builder\OM\NestedSetPeerBuilder',

    'propulsion.builder.queryinheritance.class' => 'Propulsion\Generator\Builder\OM\QueryInheritanceBuilder',
    'propulsion.builder.queryinheritancestub.class' => 'Propulsion\Generator\Builder\OM\ExtensionQueryInheritanceBuilder',

    // PHP 8.4 Object Model builders (explicit alias for the (now unconditionally default) builders above)
    'propulsion.builder.peer.php84.class' => 'Propulsion\Generator\Builder\OM\PeerBuilder',
    'propulsion.builder.object.php84.class' => 'Propulsion\Generator\Builder\OM\ObjectBuilder',
    'propulsion.builder.objectstub.php84.class' => 'Propulsion\Generator\Builder\OM\ExtensionObjectBuilder',
    'propulsion.builder.peerstub.php84.class' => 'Propulsion\Generator\Builder\OM\ExtensionPeerBuilder',
    'propulsion.builder.tablemap.php84.class' => 'Propulsion\Generator\Builder\OM\TableMapBuilder',
    'propulsion.builder.query.php84.class' => 'Propulsion\Generator\Builder\OM\QueryBuilder',
    'propulsion.builder.node.php84.class' => 'Propulsion\Generator\Builder\OM\NodeBuilder',
    'propulsion.builder.nodepeer.php84.class' => 'Propulsion\Generator\Builder\OM\NodePeerBuilder',
    'propulsion.builder.nestedset.php84.class' => 'Propulsion\Generator\Builder\OM\NestedSetBuilder',
    'propulsion.builder.nestedsetpeer.php84.class' => 'Propulsion\Generator\Builder\OM\NestedSetPeerBuilder',
    'propulsion.builder.objectmultiextend.php84.class' => 'Propulsion\Generator\Builder\OM\MultiExtendObjectBuilder',
    'propulsion.builder.nodestub.php84.class' => 'Propulsion\Generator\Builder\OM\ExtensionNodeBuilder',
    'propulsion.builder.nodepeerstub.php84.class' => 'Propulsion\Generator\Builder\OM\ExtensionNodePeerBuilder',
    'propulsion.builder.interface.php84.class' => 'Propulsion\Generator\Builder\OM\InterfaceBuilder',

    'propulsion.builder.pluralizer.class' => 'Propulsion\Generator\Builder\Util\DefaultEnglishPluralizer',

    // SQL builders
    //
    // Explicit per-database entries, same reasoning as propulsion.reverse.parser.*.class
    // above: Builder/SQL/'s directory/namespace casing (MySQL, PgSQL, MSSQL, ...)
    // never matches propulsion.database's lowercase values, so a "${propulsion.database}"
    // template can't resolve to a real class for any database.

    'propulsion.builder.datasql.mysql.class' => 'Propulsion\Generator\Builder\SQL\MySQL\MysqlDataSQLBuilder',
    'propulsion.builder.datasql.pgsql.class' => 'Propulsion\Generator\Builder\SQL\PgSQL\PgsqlDataSQLBuilder',
    'propulsion.builder.datasql.mssql.class' => 'Propulsion\Generator\Builder\SQL\MSSQL\MssqlDataSQLBuilder',
    'propulsion.builder.datasql.sqlsrv.class' => 'Propulsion\Generator\Builder\SQL\Sqlsrv\SqlsrvDataSQLBuilder',
    'propulsion.builder.datasql.oracle.class' => 'Propulsion\Generator\Builder\SQL\Oracle\OracleDataSQLBuilder',

    'propulsion.builder.datasql.class' => '${propulsion.builder.datasql.${propulsion.database}.class}',

    // Platform classes

    // Individual platform class mappings for proper capitalization
    'propulsion.platform.mysql.class' => 'Propulsion\Generator\Platform\MysqlPlatform',
    'propulsion.platform.pgsql.class' => 'Propulsion\Generator\Platform\PgsqlPlatform',
    'propulsion.platform.sqlite.class' => 'Propulsion\Generator\Platform\SqlitePlatform',
    'propulsion.platform.oracle.class' => 'Propulsion\Generator\Platform\OraclePlatform',
    'propulsion.platform.mssql.class' => 'Propulsion\Generator\Platform\MssqlPlatform',
    'propulsion.platform.sqlsrv.class' => 'Propulsion\Generator\Platform\SqlsrvPlatform',

    // Default platform class using dynamic property expansion
    'propulsion.platform.class' => '${propulsion.platform.${propulsion.database}.class}',

    // Schema Parser (reverse-engineering) classes
    //
    // Explicit per-database entries, same pattern as propulsion.platform.*.class above --
    // NOT a single "${propulsion.database}"-templated path, because the Reverse/ directory
    // and namespace casing (MySQL, PgSQL, MSSQL, SQLite, ...) doesn't match
    // propulsion.database's own lowercase values (mysql, pgsql, mssql, sqlite, ...), so a
    // generic per-character-cased template can never resolve to a real class for any
    // database.

    'propulsion.reverse.parser.mysql.class' => 'Propulsion\Generator\Reverse\MySQL\MysqlSchemaParser',
    // PostgreSQL 15+ is the minimum supported version (see KNOWN_ISSUES.md).
    // PgsqlSchemaParser uses pg_get_expr(adbin, adrelid) rather than the older
    // pg_attrdef.adsrc text column (dropped in Postgres 12) -- there used to be a
    // separate pre-12 parser variant (PgsqlSchemaParserV12Plus was the modern one),
    // but that's gone now that anything before 12 (let alone 15) isn't supported.
    'propulsion.reverse.parser.pgsql.class' => 'Propulsion\Generator\Reverse\PgSQL\PgsqlSchemaParser',
    'propulsion.reverse.parser.sqlite.class' => 'Propulsion\Generator\Reverse\SQLite\SqliteSchemaParser',
    'propulsion.reverse.parser.mssql.class' => 'Propulsion\Generator\Reverse\MSSQL\MssqlSchemaParser',
    'propulsion.reverse.parser.sqlsrv.class' => 'Propulsion\Generator\Reverse\Sqlsrv\SqlsrvSchemaParser',
    'propulsion.reverse.parser.oracle.class' => 'Propulsion\Generator\Reverse\Oracle\OracleSchemaParser',

    'propulsion.reverse.parser.class' => '${propulsion.reverse.parser.${propulsion.database}.class}',

    // -------------------------------------------------------------------
    //  M Y S Q L   S P E C I F I C   S E T T I N G S
    // -------------------------------------------------------------------

    // Default table type
    'propulsion.mysql.tableType' => 'MyISAM',
    // Keyword used to specify table type. MYSQL < 5 should use TYPE instead
    'propulsion.mysql.tableEngineKeyword' => 'ENGINE',

    // -------------------------------------------------------------------
    //  O R A C L E   S P E C I F I C   S E T T I N G S
    // -------------------------------------------------------------------

    // Pattern for sequences which will be used for autoincrement columns
    'propulsion.oracle.autoincrementSequencePattern' => '${table}_SEQ',

    // -------------------------------------------------------------------
    //  D B D E S I G N E R   2   P R O P E L   S E T T I N G S
    // -------------------------------------------------------------------

    // see propulsion.dbd2propel.dir defined in the DIRECTORIES section
    // see propulsion.dbd2propel.includes defined in the INCLUDES AND EXCLUDES section
    // see propulsion.dbd2propel.xsl.file defined in the DEFAULT FILE NAMES section

    // -------------------------------------------------------------------
    //  B E H A V I O R   S E T T I N G S
    // -------------------------------------------------------------------

    'propulsion.behavior.timestampable.class' => 'Propulsion\Generator\Behavior\TimestampableBehavior',
    'propulsion.behavior.alternative_coding_standards.class' => 'Propulsion\Generator\Behavior\AlternativeCodingStandardsBehavior',
    'propulsion.behavior.soft_delete.class' => 'Propulsion\Generator\Behavior\SoftDeleteBehavior',
    'propulsion.behavior.auto_add_pk.class' => 'Propulsion\Generator\Behavior\AutoAddPkBehavior',
    'propulsion.behavior.nested_set.class' => 'Propulsion\Generator\Behavior\NestedSet\NestedSetBehavior',
    'propulsion.behavior.sortable.class' => 'Propulsion\Generator\Behavior\Sortable\SortableBehavior',
    'propulsion.behavior.sluggable.class' => 'Propulsion\Generator\Behavior\Sluggable\SluggableBehavior',
    'propulsion.behavior.concrete_inheritance.class' => 'Propulsion\Generator\Behavior\ConcreteInheritance\ConcreteInheritanceBehavior',
    'propulsion.behavior.query_cache.class' => 'Propulsion\Generator\Behavior\QueryCache\QueryCacheBehavior',
    'propulsion.behavior.aggregate_column.class' => 'Propulsion\Generator\Behavior\AggregateColumn\AggregateColumnBehavior',
    'propulsion.behavior.versionable.class' => 'Propulsion\Generator\Behavior\Versionable\VersionableBehavior',
    'propulsion.behavior.i18n.class' => 'Propulsion\Generator\Behavior\I18n\I18nBehavior',
    'propulsion.behavior.delegate.class' => 'Propulsion\Generator\Behavior\DelegateBehavior',
    'propulsion.behavior.archivable.class' => 'Propulsion\Generator\Behavior\Archivable\ArchivableBehavior',

    'propulsion.om.BaseObject' => 'Propulsion\OM\BaseObject',
    'propulsion.om.Persistent' => 'Propulsion\OM\Persistent',

];
