<?php

/**
 * Legacy (pre-namespace) global class name -> Propulsion\ FQCN map.
 *
 * Generated Object Model classes (both the archived PHP5 builders and the
 * current PHP84 ones) are emitted unnamespaced and reference these runtime
 * classes by their bare historic name (e.g. TableMap, PropelException),
 * because that was their actual global name before this fork renamed
 * Propel\ to Propulsion\. The autoloader registered at the bottom of
 * Propulsion.php uses this to alias them on demand, so already-generated code --
 * and anyone who has not regenerated their models yet -- keeps working.
 *
 * This map also carries a parallel set of entries keyed by the *new* bare
 * Propulsion* names (PropulsionPDO, PropulsionException, ...): the code
 * generator itself emits unnamespaced Object Model / behavior code that
 * references these runtime classes by bare name too, and that bare-name
 * resolution mechanism -- not a per-renamed-class class_alias() -- is what
 * makes freshly generated code work after the Propel* -> Propulsion*
 * runtime class rename. The original bare legacy keys (PropelPDO, ...) are
 * left exactly as they were for old/already-generated code; nothing here is
 * renamed, only added to.
 *
 * @return array<string,string>
 */
return [
    'BaseObject' => 'Propulsion\\OM\\BaseObject',
    'BasePeer' => 'Propulsion\\Util\\BasePeer',
    'BasicValidator' => 'Propulsion\\Validator\\BasicValidator',
    'ColumnMap' => 'Propulsion\\Map\\ColumnMap',
    'Criteria' => 'Propulsion\\Query\\Criteria',
    'Criterion' => 'Propulsion\\Query\\Criterion',
    'CriterionIterator' => 'Propulsion\\Query\\CriterionIterator',
    'DBAdapter' => 'Propulsion\\Adapter\\DBAdapter',
    'DBMSSQL' => 'Propulsion\\Adapter\\DBMSSQL',
    'DBMySQL' => 'Propulsion\\Adapter\\DBMySQL',
    'DBNone' => 'Propulsion\\Adapter\\DBNone',
    'DBOracle' => 'Propulsion\\Adapter\\DBOracle',
    'DBPostgres' => 'Propulsion\\Adapter\\DBPostgres',
    'DBSQLSRV' => 'Propulsion\\Adapter\\DBSQLSRV',
    'DBSQLite' => 'Propulsion\\Adapter\\DBSQLite',
    'DatabaseMap' => 'Propulsion\\Map\\DatabaseMap',
    'DebugPDO' => 'Propulsion\\Connection\\DebugPDO',
    'DebugPDOStatement' => 'Propulsion\\Connection\\DebugPDOStatement',
    'Join' => 'Propulsion\\Query\\Join',
    'MatchValidator' => 'Propulsion\\Validator\\MatchValidator',
    'MaxLengthValidator' => 'Propulsion\\Validator\\MaxLengthValidator',
    'MaxValueValidator' => 'Propulsion\\Validator\\MaxValueValidator',
    'MinLengthValidator' => 'Propulsion\\Validator\\MinLengthValidator',
    'MinValueValidator' => 'Propulsion\\Validator\\MinValueValidator',
    'ModelCriteria' => 'Propulsion\\Query\\ModelCriteria',
    'ModelCriterion' => 'Propulsion\\Query\\ModelCriterion',
    'ModelJoin' => 'Propulsion\\Query\\ModelJoin',
    'ModelWith' => 'Propulsion\\Formatter\\ModelWith',
    'MssqlDebugPDO' => 'Propulsion\\Adapter\\MSSQL\\MssqlDebugPDO',
    'MssqlPropelPDO' => 'Propulsion\\Adapter\\MSSQL\\MssqlPropulsionPDO',
    'MssqlPropulsionPDO' => 'Propulsion\\Adapter\\MSSQL\\MssqlPropulsionPDO',
    'NestedSetRecursiveIterator' => 'Propulsion\\OM\\NestedSetRecursiveIterator',
    'NodeObject' => 'Propulsion\\OM\\NodeObject',
    'NodePeer' => 'Propulsion\\Util\\NodePeer',
    'NotMatchValidator' => 'Propulsion\\Validator\\NotMatchValidator',
    'Persistent' => 'Propulsion\\OM\\Persistent',
    'PreOrderNodeIterator' => 'Propulsion\\OM\\PreOrderNodeIterator',
    'Propel' => 'Propulsion\\Propulsion',
    'PropelArrayCollection' => 'Propulsion\\Collection\\PropulsionArrayCollection',
    'PropelArrayFormatter' => 'Propulsion\\Formatter\\PropulsionArrayFormatter',
    'PropelAutoloader' => 'Propulsion\\Util\\PropulsionAutoloader',
    'PropelCSVParser' => 'Propulsion\\Parser\\PropulsionCSVParser',
    'PropelCollection' => 'Propulsion\\Collection\\PropulsionCollection',
    'PropelColumnTypes' => 'Propulsion\\Util\\PropulsionColumnTypes',
    'PropelConditionalProxy' => 'Propulsion\\Util\\PropulsionConditionalProxy',
    'PropelConfiguration' => 'Propulsion\\Config\\PropulsionConfiguration',
    'PropelConfigurationIterator' => 'Propulsion\\Config\\PropulsionConfigurationIterator',
    'PropelDateTime' => 'Propulsion\\Util\\PropulsionDateTime',
    'PropelException' => 'Propulsion\\Exception\\PropulsionException',
    'PropelFormatter' => 'Propulsion\\Formatter\\PropulsionFormatter',
    'PropelJSONParser' => 'Propulsion\\Parser\\PropulsionJSONParser',
    'PropelModelPager' => 'Propulsion\\Util\\PropulsionModelPager',
    'PropelObjectCollection' => 'Propulsion\\Collection\\PropulsionObjectCollection',
    'PropelObjectFormatter' => 'Propulsion\\Formatter\\PropulsionObjectFormatter',
    'PropelOnDemandCollection' => 'Propulsion\\Collection\\PropulsionOnDemandCollection',
    'PropelOnDemandFormatter' => 'Propulsion\\Formatter\\PropulsionOnDemandFormatter',
    'PropelOnDemandIterator' => 'Propulsion\\Collection\\PropulsionOnDemandIterator',
    'PropelPDO' => 'Propulsion\\Connection\\PropulsionPDO',
    'PropelPager' => 'Propulsion\\Util\\PropulsionPager',
    'PropelParser' => 'Propulsion\\Parser\\PropulsionParser',
    'PropelQuery' => 'Propulsion\\Query\\PropulsionQuery',
    'PropelSimpleArrayFormatter' => 'Propulsion\\Formatter\\PropulsionSimpleArrayFormatter',
    'PropelStatementFormatter' => 'Propulsion\\Formatter\\PropulsionStatementFormatter',
    'PropelXMLParser' => 'Propulsion\\Parser\\PropulsionXMLParser',
    'PropelYAMLParser' => 'Propulsion\\Parser\\PropulsionYAMLParser',
    'Propulsion' => 'Propulsion\\Propulsion',
    'PropulsionArrayCollection' => 'Propulsion\\Collection\\PropulsionArrayCollection',
    'PropulsionArrayFormatter' => 'Propulsion\\Formatter\\PropulsionArrayFormatter',
    'PropulsionAutoloader' => 'Propulsion\\Util\\PropulsionAutoloader',
    'PropulsionCSVParser' => 'Propulsion\\Parser\\PropulsionCSVParser',
    'PropulsionCollection' => 'Propulsion\\Collection\\PropulsionCollection',
    'PropulsionColumnTypes' => 'Propulsion\\Util\\PropulsionColumnTypes',
    'PropulsionConditionalProxy' => 'Propulsion\\Util\\PropulsionConditionalProxy',
    'PropulsionConfiguration' => 'Propulsion\\Config\\PropulsionConfiguration',
    'PropulsionConfigurationIterator' => 'Propulsion\\Config\\PropulsionConfigurationIterator',
    'PropulsionDateTime' => 'Propulsion\\Util\\PropulsionDateTime',
    'PropulsionException' => 'Propulsion\\Exception\\PropulsionException',
    'PropulsionFormatter' => 'Propulsion\\Formatter\\PropulsionFormatter',
    'PropulsionJSONParser' => 'Propulsion\\Parser\\PropulsionJSONParser',
    'PropulsionModelPager' => 'Propulsion\\Util\\PropulsionModelPager',
    'PropulsionObjectCollection' => 'Propulsion\\Collection\\PropulsionObjectCollection',
    'PropulsionObjectFormatter' => 'Propulsion\\Formatter\\PropulsionObjectFormatter',
    'PropulsionOnDemandCollection' => 'Propulsion\\Collection\\PropulsionOnDemandCollection',
    'PropulsionOnDemandFormatter' => 'Propulsion\\Formatter\\PropulsionOnDemandFormatter',
    'PropulsionOnDemandIterator' => 'Propulsion\\Collection\\PropulsionOnDemandIterator',
    'PropulsionPDO' => 'Propulsion\\Connection\\PropulsionPDO',
    'PropulsionPager' => 'Propulsion\\Util\\PropulsionPager',
    'PropulsionParser' => 'Propulsion\\Parser\\PropulsionParser',
    'PropulsionQuery' => 'Propulsion\\Query\\PropulsionQuery',
    'PropulsionSimpleArrayFormatter' => 'Propulsion\\Formatter\\PropulsionSimpleArrayFormatter',
    'PropulsionStatementFormatter' => 'Propulsion\\Formatter\\PropulsionStatementFormatter',
    'PropulsionXMLParser' => 'Propulsion\\Parser\\PropulsionXMLParser',
    'PropulsionYAMLParser' => 'Propulsion\\Parser\\PropulsionYAMLParser',
    'RelationMap' => 'Propulsion\\Map\\RelationMap',
    'RequiredValidator' => 'Propulsion\\Validator\\RequiredValidator',
    'TableMap' => 'Propulsion\\Map\\TableMap',
    'TypeValidator' => 'Propulsion\\Validator\\TypeValidator',
    'UniqueValidator' => 'Propulsion\\Validator\\UniqueValidator',
    'ValidValuesValidator' => 'Propulsion\\Validator\\ValidValuesValidator',
    'ValidationFailed' => 'Propulsion\\Validator\\ValidationFailed',
    'ValidatorMap' => 'Propulsion\\Map\\ValidatorMap',
];
