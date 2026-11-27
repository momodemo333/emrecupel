# emRecupel 2.0 — User guide

> emRecupel helps you invoice and declare the Recupel fee; it does not replace
> your own check. Product classification, appliance origin and declared figures
> remain your responsibility. Check the codes in the official list and review
> the report before declaring.

## 1. Getting started (10 minutes)

### 1.1 Company profile

*Setup › Modules › emRecupel › Setup*, or *Products › Recupel › Setup*.

| Profile | Your situation | What the report shows |
|---|---|---|
| Reseller | You buy appliances from Belgian suppliers and sell them in Belgium. You pass the fee on and declare nothing. | A control statement of the fees passed on. |
| Reseller who exports | Same, but affiliated to Recupel to get the fee of appliances sold abroad refunded. | The "Returnable" column. |
| Importer or manufacturer | Recupel member: you declare the imported or produced appliances placed on the Belgian market. | "Brought to market" and "Returnable". |

The **default origin** of products follows the profile ("bought in Belgium" for
a reseller, "imported or produced" for an importer). A mixed company sets the
origin product by product (§ 2).

### 1.2 Display of the fee

Recupel rule (FAQ 2.13):

- appliance with an **all-in fee** (household appliances, and code 1.50 from
  2027) sold to a **reseller of electrical appliances**: the fee is shown
  separately, per appliance. The module then forces a **separate line**;
- every other case (end user, company or consumer; professional appliance):
  "Recupel fee included" is enough; a separate line remains allowed.

The criterion is not "company or consumer": a company buying a PC for its own
use is an end user.

Three renderings, set for each cell of the matrix:

- **Separate line**: a line "Recupel fee — Cat. 1.1 — …" right under the
  product, same quantity, same VAT; it is added to the total.
- **Included (mention under the line + total in the footer)**: your price
  already contains the fee. The PDF prints "Including Recupel fee: 10.00 EUR
  incl. VAT/piece (cat. 1.1)" under the product and "Recupel fees included in
  the prices: x.xx incl. VAT" in the footer. Nothing is added to the total.
- **Included (total in the footer only)**.

Outside Belgium, no fee is due: nothing is added. The country used is the one
of the **delivery contact** of the document, otherwise the customer's.

### 1.3 Customer role

Third-party card, fields:

- **Recupel role**: *End user* (default), *Reseller of electrical appliances*,
  *Exempt from the fee* (e.g. a Recupel member who exports);
- **Display of the Recupel fee**: forces a rendering for this customer (empty =
  setup rule). The mandatory separate line of a reseller cannot be removed.

### 1.4 Other settings

- Documents concerned: proposals, orders, invoices and credit notes.
- **Fee lines priced VAT included** (recommended): the official scale publishes
  a round amount incl. VAT; the line is built from it so that the customer pays
  10.00 and not 9.99. For a line at another rate than 21 %, the line starts from
  the amount excl. VAT and follows the product rate (CNC advice 2009/9: VAT on
  the fee follows the VAT of the appliance).
- **Service of the fee lines**: the RECUPEL service is created on activation.
  Set its accounting account (sub-account of 70) on its card.
- Line description per language: variables `{CODE}`, `{LABEL}`, `{AMOUNT}`,
  `{UNITS}`.

## 2. Classifying products

### 2.1 Recupel tab of a product

- **Status**: *To classify*, *Subject*, *Not subject* (accessory supplied with
  an appliance, component without its own function, USB stick, home battery,
  solar panel, service…).
- **Origin**: *imported or produced* (you declare it), *bought in Belgium* (your
  supplier charged you the fee), *Belgian second-hand* (already placed on the
  Belgian market: charging the fee is forbidden; the PDF says "Recupel fee
  included").
- **Declarable weight**: for professional appliances (product + accessories,
  cables included, without packaging, manual or batteries). Empty = weight of
  the product card.
- **Codes**: one or more, with the number of appliances per sold unit.
  Examples: luminaire with 4 LED lamps = fitting 5.6 ×1 + lamp 3.2 ×4; CCTV kit
  = monitor, recorder and each camera. The tab shows today's rate and the next
  scale's.

The "Official Recupel search engine" link opens the appliance search of
recupel.be. The module never classifies anything automatically.

A **variant** without its own classification uses its parent's.

### 2.2 Products to classify

*Products › Recupel › Products to classify*: filterable list (status, text,
Dolibarr category, goods only, sold this year). A banner shows the products sold
this year and not classified yet. Tick products, choose the action, *Apply*.

### 2.3 CSV import / export

*Products › Recupel › CSV import / export*. Format (UTF-8, `;`):

```
ref;code;quantity;origin
LAPTOP-14;2.4;1;local
FRIDGE-A;1.1;1;import
SPOT-KIT;5.6;1;import
SPOT-KIT;3.2;3;import
USB-CABLE;exempt;;
```

Several lines for the same reference give several codes; `exempt` = not
subject; origin `import`, `local`, `used`, empty = default. The codes of the
file replace those of the product. *Check* validates without writing.

## 3. Day to day

### 3.1 Documents

Add products as usual. Depending on the customer and the product, the fee line
appears under the product, or nothing is added and the PDF carries the mention.

- Changing the quantity updates the fee; deleting the product deletes its fee.
- Deleting a fee line by hand removes it for that product; the **Recalculate**
  button of the Recupel tab of the document brings it back.
- Changing the date, customer or delivery contact of a draft recalculates the fee.
- Proposal → order → invoice, clone, credit note with lines, replacement
  invoice, template invoice: fee lines follow, without duplicates; on a credit
  note they are negative. On invoicing, the rate is the one of the invoice date.
- A **validated document is never changed**.

The **Recupel** tab of a proposal, order or invoice details each product: code,
rate, decision and rendering; for a validated invoice, the appliances recorded
for the declaration.

Deposit and situation invoices carry no fee.

### 3.2 Scale and yearly switch

*Products › Recupel › Scale*: rates at a date, previous scale, history of a
code. Recupel changes its scale on 1 January or 1 July with six months' notice.
The module ships 2026 and 2027; a later scale can be imported as CSV (checked
before import, rejected as a whole on error).

*Products › Recupel › Switch assistant*: codes that change, catalogue products
whose fee changes, open proposals and orders dated before the switch. In 2027,
dishwashers move from 4.1.2 to 4.1.1: the assistant flags them.

## 4. Declaration

*Billing | Payment › Recupel declaration*: month, quarter or year; deadline on
the 20th of the following month; household and professional blocks per code:
**Brought to market** (number, weight for professional), **Returnable**, and
**Bought and sold in Belgium** (control). Estimated amount at the rate of each
invoice date; warning above the 10,000 EUR cap of administrative fees; detail
down to the invoices; CSV export; *Rebuild the period* after a late
classification. The declaration is entered on https://declarations.recupel.be.

## 5. Permissions

| Permission | Allows |
|---|---|
| Read Recupel data | scale, tabs, assistant |
| Classify products and recalculate | product tab, products to classify, import, "Recalculate" |
| Set up and import scales | setup, scale import |
| Read the declaration report | report, CSV export |
