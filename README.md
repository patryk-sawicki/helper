# Helper

A set of helpful tools for PHP.

## Requirements

* PHP 8.2 or higher with json extensions.
* Laravel 11, 12 or 13.

Laravel 13 requires PHP 8.3 or higher. On PHP 8.2 Composer resolves this package against Laravel 11
or 12 instead, which is intended rather than a conflict.

### Support matrix

| Laravel | Verified against | Notes |
| --- | --- | --- |
| 11.x | 11.56.1 | Line carries open security advisories, see below |
| 12.x | 12.69.2 | |
| 13.x | 13.32.0 | Requires PHP 8.3 or higher |

Each of the three was run through the same checks: the derived searchable column list, the refusal
of a hidden column in both `LIKE` and `ORDER BY`, the collection path, and the morph cases in
relation paths.

**Laravel 11 is outside the framework's security fix window.** The whole 11.x line carries open
advisories against `laravel/framework` with no 11.x release fixing them, including a high severity
CRLF injection in the default `email` validation rule (CVE-2026-48019); the fixes landed in
12.60/12.61 and 13.10/13.12. Composer refuses to install Laravel 11 unless `audit.block-insecure`
is set to false. This package keeps accepting `^11.00` so that its own security fixes reach the
projects still on that line, not as a statement that the line is safe to stay on.

### Tests

```bash
composer install
composer test
```

The suite runs on `orchestra/testbench` against an in-memory SQLite database and covers the
security gates in `tableData`: the relation allow-list, the searchable column list, and the morph
paths. `.github/workflows/tests.yml` runs it against Laravel 11, 12 and 13.

## Installation

The recommended way to install is through [Composer](http://getcomposer.org).

```bash
$ composer require patryk-sawicki/helper
```

## Usage

### tableData

Trait for getting data by api to data tables. A collection of models should be passed as the
elements variable.

**The column name and the searchable flag come from the request**, so the trait decides for itself
which columns a request may address, and which relations it may resolve. Two opt-in lists on the
model steer that decision.

#### `$searchableColumns` — which columns may be filtered and sorted

Left undeclared, the list is derived from the model: the columns the table really has, less the
ones the model declares as not for output through `$hidden`, less the ones outside a non-empty
`$visible`, less the ones cast as `hashed` or `encrypted`.

```php
class Customer extends Model
{
    // Replaces the derived list outright. Declaring it as null makes no choice and keeps the
    // derived one; an empty array allows nothing.
    public array $searchableColumns = ['name', 'email', 'created_at'];
}
```

> **The derived list is only as good as what your model declares.** A model with no `$hidden`, no
> `$visible` and no encrypting cast offers every column of its table to a search — including a
> password column, if it has one. A floor is refused regardless: `password`, `password_hash`,
> `remember_token`, `salt`, `secret`, `api_key`, `app_key`, `auth_key`, `hmac_key`,
> `signature_key`, `recovery_codes`, `two_factor_recovery_codes`, anything ending in `_token`,
> `_secret` or `_password`, and anything starting with `secret_`.
>
> It is a floor, not a review. Matching is on the whole name, so `_hash` and `_key` are
> deliberately NOT suffixes — they name identifiers far more often than secrets (`short_hash`,
> `pdf_hash`, `idempotency_key`, `checkout_key`), and blocking them would break working screens.
> A column of yours that holds a secret under some other name is yours to hide. Go through the
> models you list and give them a `$hidden`.

#### `$sortableRelations` — which relations may be resolved

A relation is resolvable when its method declares an Eloquent `Relation` return type. One declared
without a return type has to be opted in explicitly, because resolving a relation means CALLING
the method the request names.

```php
class Order extends Model
{
    public array $sortableRelations = ['customer', 'translations'];

    public function customer()          // no return type, hence the opt-in above
    {
        return $this->belongsTo(Customer::class);
    }
}
```

#### What a refusal looks like

A refused column filter returns an empty result rather than an unfiltered one, a refused sort
leaves the query unordered, and nothing throws. Every refusal is written to the log, which is what
to grep for when a list stops behaving after an update:

```
tableData: column rejected   {"model":"App\\Models\\Order","column":"customer.secret","reason":"column"}
```

`reason` is one of `column`, `relation`, `path`, `nested-sort` or `to-many-sort`. At most 20
records are written per request, followed by one saying the rest were dropped.

## Changelog

Changelog is available [here](CHANGELOG.md).
