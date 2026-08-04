# CLAUDE.md

- Whenever a file is edited, it must target PHPStan Level 9. Fix real
  findings; don't suppress with `@phpstan-ignore` or widen types just to
  silence the tool.

- Commit messages must adhere to cliff formatting, so release notes can be
  generated from them. The subject line is
  `<type>(<scope>)<!>: <summary>` — e.g. `fix(cache): stop an evicted
  namespace version resurrecting retired entries`. `cliff.toml` groups on
  `feat`, `fix`, `perf`, `refactor`, `docs`, `style`, `test`, `chore`, `ci`
  and `revert`; the scope is optional but worth having, and a `!` before the
  colon marks a breaking change. Anything that doesn't parse as a
  conventional commit is dropped from the release notes entirely
  (`filter_unconventional = true`), so an unprefixed subject means the work
  silently does not appear in the release.

  Keep the existing habit of a body that explains the root cause and why the
  fix is shaped the way it is — cliff only renders the subject, but the body
  is what makes `git log` worth reading.

  Tags before and including `v2.0.0` predate this convention. That release
  ships the curated `CHANGELOG.md` section as hand-written notes via
  `.github/release-notes/v2.0.0.md`; see `.github/workflows/release.yml`.
