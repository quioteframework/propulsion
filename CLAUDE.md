# CLAUDE.md

- Whenever a file is edited, try to get it clean at PHPStan level 9
  (`vendor/bin/phpstan analyse <file> --level 9`), even though the project's
  baseline config (`phpstan.neon`) currently targets level 5. Fix real
  findings; don't suppress with `@phpstan-ignore` or widen types just to
  silence the tool.
