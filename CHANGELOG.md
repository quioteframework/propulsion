## [3.0.0] - 2026-08-14

### 🐛 Bug Fixes

- *(generator)* Import bare class-name type hints in namespaced generated code
- *(behavior)* Give delegate targets real forwarding methods, not just __call
- *(behavior)* Qualify bare type hints copied into delegate forwarders
- *(generator)* Preserve collection generics in delegate-forwarded getters

### 🧪 Testing

- *(adapter,reverse)* Cover the defaults and helpers no platform job reaches
- *(runtime,generator)* Cover three classes that reported zero coverage

### ⚙️ Miscellaneous Tasks

- *(codecov)* Report the merged five-platform coverage, not the first job's
- *(tests)* Install apcu in the jobs that actually measure coverage
## [3.0.0-RC2] - 2026-08-13

### 🐛 Bug Fixes

- *(ci)* Publish a tag with a SemVer pre-release identifier as a pre-release
- *(generator)* Bump the version to 3.0.0-RC1, and stop it living in two places
- *(rector)* Make the stub migration work on namespaced projects
- *(doc)* CHANGELOG for v3.0.0-RC2

### 📚 Documentation

- *(readme)* Add a PHPStan level 9 badge, and say what backs it
## [3.0.0-RC1] - 2026-08-13

### 🚀 Features

- *(generator)* [**breaking**] Emit the object model as a trait instead of a base class
- *(generator)* [**breaking**] Remove the concrete_inheritance behavior
- *(generator)* [**breaking**] Emit the query model as a trait instead of a base class
- *(generator)* [**breaking**] Emit the node model as traits instead of base classes
- *(rector)* Ship a migration rule for the stub-to-trait change

### 🐛 Bug Fixes

- *(generator)* Correct return and parameter types across four behaviors
- *(generator)* Drop a dead break, and record three findings not worth guessing at
- *(generator)* Make primary-key types agree between the object and the query
- *(generator)* Stop reading rows and primary keys as mixed
- *(generator)* Refuse to move an unplaced node, and type the nested_set row reads
- *(generator)* [**breaking**] Honour a query preDelete() veto, unify tree returns, type filterBy
- *(generator)* Type array columns, and stop the suite inheriting a leaked container
- *(generator)* Let a rank move and an aggregate update take a nullable connection
- *(generator)* Guard nullable relations, LOB writes and read-only validation
- *(generator)* Stop coercing mixed values through columns and tree lookups
- *(generator)* Clear the last generated-code level 9 findings

### 🚜 Refactor

- *(generator)* [**breaking**] Stop concrete_inheritance chaining peer classes

### 📚 Documentation

- *(generator)* Record the trait collision audit, which comes back clear
- *(known-issues)* Drop the section the traits work superseded

### 🧪 Testing

- *(generator)* Pin the contracts the traits work established
- *(runtime)* Cover PropulsionArrayCollection's re-keying API

### ⚙️ Miscellaneous Tasks

- *(phpstan)* Gate generated code at level 9, not just the source that emits it
## [pre-traits-checkpoint] - 2026-08-12

### 🚀 Features

- *(om)* Add Poolable, the type a Peer's instance pool actually accepts
- *(collection)* Make the collections generic in their element type
- *(generator)* [**breaking**] Remove the NestedSet treeMode and the 1.4 nested-set proxies

### 🐛 Bug Fixes

- *(query)* Accept a fluent arrow function as a sub-query callback
- *(generator)* Import the runtime classes generated code names
- *(bench)* Instantiate a concrete PDO class, not the PropulsionPDO interface
- *(generator)* Let generated Peer/Object code prove its own row and session types
- *(om)* Declare the left/right accessors NodeObject is already used through
- *(generator)* Narrow a resultset cell before casting it
- *(map)* A built relation always has both tables, so stop typing them nullable
- *(generator)* Say that validation failures are ValidationFailed objects
- *(behavior)* Return static from the fluent behavior methods, and guard a missing neighbour
- *(generator)* Check what came out of the pool in the doSelectJoin paths
- *(generator)* Establish what doValidateThis(), prepare() and doDelete() hand back
- *(generator)* Confirm the connection is a PropulsionPDO after resolving it
- *(generator)* Emit parseable @method tags for typeless columns, and tidy three property types
- *(generator)* Give temporal hydration a scalar guard, and read nullables once

### 🚜 Refactor

- *(cache)* Return what the formatters return from remember()
- *(generator)* Drop a null check the enclosing branch already made
- *(connection)* [**breaking**] Require a PropulsionPDO, once, where the connection is built

### 📚 Documentation

- *(generator)* Type the parameters, returns and properties the behaviors emit
- *(known-issues)* Write up the Base<Model> relation-parameter problem
- *(known-issues)* Strike the per-model interface, and name the FK cache property
- *(generator)* Plan the move from generated base classes to traits
## [2.1.1] - 2026-08-10

### 🐛 Bug Fixes

- *(runtime)* Roll back pooled connections before dropping them
- *(ci)* Use checkout v5 instead of checkout v4
## [2.1.0] - 2026-08-06

### 🚀 Features

- *(connection)* Detect dropped connections where statements actually run, and recover
- *(query)* Let an adapter rewrite a column's SQL, not just bind to it
- *(query)* Query vectors by distance, and index them at all
- *(connection)* Add cross-platform named (advisory) locks
- *(query)* Query into JSON columns instead of only storing them
- *(query)* Apply global filters to every query on a model
- *(observability)* Notify observers around every query
- *(mysql)* Cover MySQL 9's native VECTOR, not just MariaDB's

### 🐛 Bug Fixes

- *(cache)* Follow nested queries when collecting a result's table dependencies
- *(cache)* Let every formatter share one shared-tier entry for the same rows
- *(config)* Resolve bare PDO option names, and accept the PropulsionPDO name as a classname
- *(test)* Give the MySQL testcontainer long enough to finish its first boot
- *(criteria)* Compare a criterion's chained clauses by value, not by identity
- *(om)* Read the modified-column list generated code still writes
- *(query)* Find the query class of a namespaced relation again
- *(generator)* Stop each quick build unregistering the last one's adapter
- *(connection)* Make advisory locks work on MSSQL and MariaDB at all
- *(connection)* Stop a connection pinning the configuration it was built with
- *(reverse)* Stop MySQL reverse-engineering losing decimals and defaults
- *(harness)* Make the no-Docker suite order-independent

### 🚜 Refactor

- *(config)* Extract the strict readers QueryCacheConfig grew
- *(connection)* Drop the engine branch from GET_LOCK's infinite wait

### 📚 Documentation

- Record the connection-config, reconfiguration and criterion-equality fixes
- *(known-issues)* Sharpen the order-dependence entry, and how to measure it

### 🧪 Testing

- *(harness)* Name the test that leaks a transaction, instead of timing out 300 others
- *(observability)* Stop asserting an unquoted table name in observed SQL
- Cover the pager, the on-demand collection and the config contract
- *(validator)* Assert what the validators actually do
- *(harness)* Name and repair the test that unregisters others' adapters
- *(harness)* Stop the fixture-switching tests dropping everyone's adapters

### ⚙️ Miscellaneous Tasks

- *(mariadb)* Give MariaDB its own full-suite job, and fix the test it caught
- Measure coverage on every database job, with pcov
## [2.0.0] - 2026-08-04

### 🚀 Features

- Add opt-in query result cache, replacing the broken legacy QueryCacheBehavior

### 🐛 Bug Fixes

- *(test)* Stop a dead testcontainer skipping the integration tier into a green run
- *(test)* Resolve the container host port from the binding, not from NetworkSettings
- *(test)* Reach the container by whichever route actually accepts a connection

### 💼 Other

- Fix level 8 nullability (28 -> 0)
- Fix level 8 nullability (36 -> 0)
- Fix level 8 nullability (50 -> 0)
- Fix level 8 nullability (39 -> 0)
- Fix level 8 nullability (26 -> 0)
- Fix level 8 nullability (7 -> 0)
- Fix level 8 nullability findings (21 -> 0)
- Fix level 8 nullability findings (16 -> 0)
- Fix level 8 nullability findings (9 -> 0)
- Fix level 8 nullability findings (21 -> 0)
- Fix level 8 nullability findings (23 -> 0)
- Fix level 8 nullability findings (81 -> 0)
- Fix level 8 nullability findings (64 -> 0)
- Fix level 8 nullability findings (39 -> 0)
- Fix level 8 nullability findings (39 -> 0)
- Fix level 8 nullability findings (21 -> 0)
- Fix level 8 nullability findings (15 -> 0)
- Fix level 8 nullability findings (21 -> 0)
- Fix level 8 nullability findings (10 -> 0)
- Fix level 8 nullability findings (26 -> 0)
- Fix level 8 nullability findings (25 -> 0)
- Fix level 8 nullability findings in Timestampable/AutoAddPk (9 -> 0)
- Fix level 8/9 nullability findings (19 -> 0)
- Fix level 8/9 nullability findings in the small builder files (~20 -> 0)
- Fix level 8/9 nullability findings (6 -> 0)
- Fix level 8/9 nullability findings (20 -> 0)
- Fix level 8/9 nullability findings (29 -> 0)
- Fix level 8/9 nullability findings (69 -> 0)
- Fix level 8/9 nullability findings (80 -> 0)
- Fix level 9 str_repeat() mixed arg
- Fix level 9 findings in IdMethodParameter, Index, Unique, ScopedElement, VendorInfo (32 -> 0)
- Fix level 9 findings in Inheritance, Rule, Validator (26 -> 0)
- Fix level 9 findings in Exclusion, PhpNameGenerator, ConstraintNameGenerator (23 -> 0)
- Fix level 9 findings (11 -> 0)
- Fix level 9 findings (8 -> 0)
- Fix level 9 findings (19 -> 0)
- Fix level 9 findings (20 -> 0)
- Fix level 9 findings (10 -> 0)
- Fix level 9 findings in all 4 comparator/diff classes (18 -> 0)
- Fix level 9 findings (GeneratorConfig.php 38 -> 0, QuickGeneratorConfig.php 5 -> 0)
- Fix level 9 findings across all Command/* classes (61 -> 0)
- Fix level 9 findings across all Util/* classes (30 -> 0)
- Fix level 9 findings (XmlToAppData.php ~44 -> 0, StandardEnglishPluralizer.php 3 -> 0)
- Fix level 9 findings in PeerBuilder.php (85 -> 0), ObjectBuilder.php (78 -> 0)
- Fix level 9 findings in QueryBuilder.php (34 -> 0), TableMapBuilder.php (23 -> 0), QueryInheritanceBuilder.php (9 -> 0)
- Fix remaining level 9 findings in OMBuilder.php and its subclasses (60 -> 0)
- Fix level 9 findings (ColumnMap 6->0, DatabaseMap 5->0, RelationMap 5->0, TableMap 14->0)
- Fix level 9 findings (XML 9->0, CSV 7->0, JSON 1->0, YAML 1->0, base parser 1->0)
- Fix level 9 findings (ModelCriteria 1->0, ModelCriterion 6->0, PropulsionQuery ->0)
- Fix level 9 findings (BasePeer, PropulsionDateTime 2->0, PropulsionModelPager 3->0, PropulsionPager 5->0)
- Fix level 9 findings (24 -> 0)
- Fix level 9 findings (21 -> 0)
- Fix level 9 findings (14 -> 0)
- Fix level 9 findings (14 -> 0)
- Fix last project-wide level 9 finding (1 -> 0)

### 📚 Documentation

- PHPStan Level 9
- Record the review findings left deliberately unfixed
- Record the worker-mode contract, and this pass's findings

### ⚙️ Miscellaneous Tasks

- Enforce PHPStan and the worker matrix, fix Oracle pdo_oci build
- *(release)* Generate release notes from conventional commits
- *(oracle)* Put the Instant Client on the loader path, not one step's environment
- *(release)* Require the integration tier to actually run before publishing
## [1.0.0] - 2026-07-07

### 💼 Other

- Wire up testcontainers-backed integration tests, fix PHPUnit bootstrap
- PostgreSQL as documented default database, PgsqlPlatform parity fixes

### 🧪 Testing

- More comprehensive test suites
