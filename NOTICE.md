# Notice

Propulsion is a hard fork of [Propel 1](https://github.com/propelorm/Propel1),
the PHP object-relational mapper originally created by Hans Lellelid, based
on the Apache Torque project, and later maintained by the Propel community
(notably David Zuelke, François Zaninotto, and William Durand). This
repository's `CHANGELOG` and `WHATS_NEW` files are carried over unedited from
that project and stop at Propel's 1.6.1 release (2011-06-14), which is the
point this fork branched from.

Propel 1 development had wound down and the project was effectively
unmaintained by the time this fork started. Propulsion renamed the project
(`Propel*` classes and namespaces became `Propulsion*`), replaced the
Phing-based build tooling with a plain console application, and continues
to receive bug fixes and modernization (current PHP syntax and types,
PostgreSQL promoted to the default/recommended database, and so on). See
`KNOWN_ISSUES.md` for a running, detailed log of what has changed since the
fork and what remains in progress.

## License

Propulsion is distributed under the same MIT license as Propel 1. See
`LICENSE` for the full text and copyright notice, which includes both the
original Propel copyright holders and the Propulsion contributors.

## Why "Propel 1"?

Propel 1 and Propel 2 are different, incompatible codebases; this fork is
based on Propel 1 (the Active Record-style ORM, configured via XML schema
files and `build.properties`), not Propel 2 (which uses a different,
Doctrine-inspired architecture). References to "Propel" throughout this
repository's history and comments mean Propel 1 unless stated otherwise.
