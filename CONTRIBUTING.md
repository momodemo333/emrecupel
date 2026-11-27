# Contributing to emRecupel

Thanks for your interest! Bug reports, corrections of the Recupel scale,
translations and pull requests are welcome.

- **Bugs**: open an issue with your Dolibarr and PHP versions, the steps to
  reproduce, what you expected and what happened (no customer data, please).
- **Scale / legal rules**: cite the official Recupel source (appliance list,
  FAQ page) for any change of a code, amount or display rule.
- **Pull requests**: one topic per PR; follow the Dolibarr coding style (tabs,
  `dol_include_once`, `$langs->trans()` with keys in the three language files
  `fr_FR`, `en_US`, `nl_NL`); add or update tests.

Before submitting:

```bash
composer install
make lint
php vendor/bin/phpunit -c phpunit.xml.dist
# on a test Dolibarr instance with the module enabled (database left unchanged):
php tests/Integration/run_integration.php --rollback
```

Version bumps touch four places together: `$this->version` in
`core/modules/modEmrecupel.class.php`, `EmrecupelMigrations::MODULE_VERSION`,
the version literal in `ActionsEmrecupel::printCommonFooter()`, and `VERSION`
(plus `CHANGELOG.md`). `make build-release` refuses to build when they differ.

By contributing, you agree that your contribution is licensed under the
GPL-3.0-or-later.
