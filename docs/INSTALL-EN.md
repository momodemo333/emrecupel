# emRecupel — Installation and update

## Requirements

- Dolibarr 21 or later (tested on 23.0.1 and 21.0.1);
- PHP 8.1 or later (tested on 8.1 and 8.2); MySQL or MariaDB (PostgreSQL is not supported);
- modules Invoices, Third parties and Products enabled (Commercial proposals and
  Orders if you want the fee on these documents).

## Installation

1. Download `module_emrecupel-2.0.0.zip` from
   https://github.com/momodemo333/emrecupel/releases/latest and unzip it into
   `htdocs/custom/` (you get `htdocs/custom/emrecupel/`), or
   `git clone https://github.com/momodemo333/emrecupel.git htdocs/custom/emrecupel`,
   or use *Setup › Modules › Deploy/install an external module*. Nothing has to
   be built or installed on top.
2. *Setup › Modules › Financial modules*: enable **emRecupel**. Enabling creates
   the tables, the fields (role and display on the third-party card, hidden
   technical fields on document lines), the RECUPEL fee service, and loads the
   2026 and 2027 scales.
3. Module setup: company profile, default origin, display matrix (see the user guide).
4. Card of the **RECUPEL** service: set its sales accounting account.
5. Grant permissions to users (*Users › Permissions › emRecupel*).
6. Classify your products (*Products › Recupel › Products to classify* or CSV import).

Multi-company: the scale is shared by all entities; settings, product
classification and the declaration ledger follow the entity of the product or
invoice.

## Update

Replace the `htdocs/custom/emrecupel/` folder with the new one. Database
migrations run automatically on the next page load (diagnostic and "Check and
repair the database" button in the module setup). After a migration failure,
automatic retries stop after 3 attempts and the setup page shows it.

## Disable / uninstall

Disabling the module keeps its tables, data and settings: enabling it again
finds them back. Fee lines already on documents remain ordinary service lines.

To remove everything: disable the module, delete the folder, then the
`llx_emrecupel_*` tables, the `EMRECUPEL_%` constants and the `emrecupel_*`
extra fields.
