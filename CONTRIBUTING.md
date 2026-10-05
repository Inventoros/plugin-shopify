# Contributing

Thanks for helping improve an Inventoros Official plugin.

## Ground rules

- Read [docs/CONVENTIONS.md](docs/CONVENTIONS.md) first. Names, tables, permissions and routes follow it.
- The plugin must stay independently installable, upgradeable and removable. Never edit Inventoros core from a plugin; when the SDK lacks something, open an issue on [Inventoros/Inventoros](https://github.com/Inventoros/Inventoros) describing the generic extension point instead.
- Keep core data safe: change stock, orders and other core records only through core services, inside database transactions, and never in an uninstall hook.
- Scope every row by `organization_id` and test that another organization cannot see or change it.
- Talk to external services through an interface with a fake for tests. Tests never call a live API.
- No secrets, credentials or customer data in the repository, fixtures or logs.
- User-facing copy: plain language, no em dashes. Add strings to the English locale files; other locales are welcome.

## Workflow

1. Fork and branch from `main`.
2. Write a failing test, then the change. Run the suite inside an Inventoros checkout (see the README's "Develop and test").
3. Rebuild the UI bundle if you touched `ui/` (`cd ui && npm ci && npm run build`).
4. Update `CHANGELOG.md` under `[Unreleased]`.
5. Open a pull request describing what changed, why, and how you tested it. CI must be green.

## Reporting security issues

Do not open a public issue. Email security@inventoros.com with the details.

## License

By contributing you agree that your contributions are licensed under the AGPL-3.0-or-later, the license of this repository.
