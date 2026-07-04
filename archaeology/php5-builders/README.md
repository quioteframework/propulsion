# PHP5 generator builders (archived)

These are the original, pre-modernization PHP5-style object-model builders
(`PHP5PeerBuilder`, `PHP5ObjectBuilder`, `PHP5TableMapBuilder`,
`PHP5QueryBuilder`, and the rest of the `PHP5*` family), removed from the
active codebase and kept here for reference only.

**They are not autoloaded and not wired into anything.** This directory is
outside `generator/Lib/` (the PSR-4 root for the `Propulsion\Generator\`
namespace — see `composer.json`), so these classes cannot be instantiated
without an explicit `require` of the file. `generator/default.properties`
no longer has any `propel.builder.*.php5.class` overrides pointing at them,
and nothing in `generator/Lib/` or `test/` references them by name anymore.

## Why they existed

Phase 3 of the PHP5 -> PHP8.4 generator modernization (see
`KNOWN_ISSUES.md`) promoted the `PHP84*`-suffixed builders (e.g.
`PHP84PeerBuilder`) to be the canonical, unsuffixed builders (e.g.
`PeerBuilder`), but two of the "modern" builder families
(`ObjectBuilder`/`PeerBuilder` and the `node`/`nestedset` family) turned
out to have real completeness gaps relative to their PHP5 predecessors —
see the Phase 3 writeup in `KNOWN_ISSUES.md` for specifics (temporal
column defaults, a PHP engine segfault, and, for nested sets, dozens of
literal `{ /* Implementation */ }` no-op stub methods). Phase 3 therefore
kept those two families on the legacy PHP5 builders by default while the
rest of the generator switched over.

This directory exists because that safety net was removed: all PHP5
builders were ripped out of the active codebase in one pass, and
`peer`/`object`/`objectstub`/`peerstub`/`objectmultiextend` and
`node`/`nodepeer`/`nodestub`/`nodepeerstub`/`nestedset`/`nestedsetpeer`
now unconditionally use the (still-incomplete-in-places) promoted
builders. See `KNOWN_ISSUES.md` for the resulting fallout and what's still
broken.

## If you need to bring one back

Move the file back into `generator/Lib/Builder/OM/`, restore its
`propel.builder.*.class` (or `.php5.class`) entry in
`generator/default.properties`, and check whether anything it depended on
(`AbstractPeerBuilder`/`AbstractObjectBuilder` as its base class, methods
on `OMBuilder`/`DataModelBuilder`) has changed shape since it was archived.
