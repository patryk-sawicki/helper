### 0.7.16

**Laravel 13 support.** The framework constraint now accepts `^13.00` alongside `^11.00` and
`^12.00`. Nothing else in the package changed for Laravel 13 itself: the sorting allow-list keys
off the declared return type of the model's own methods rather than framework behaviour, and it was
re-checked against v13.32.0. Note that Laravel 13 itself requires PHP >= 8.3, while this package still
declares `php: ^8.2` — on PHP 8.2 Composer keeps resolving to Laravel 12, which is intended.

**Security fix (tableData) — the column name paths over collections.** Upgrade from any earlier
version. Column names arriving in the request were read off the model without being checked first,
and in Eloquent reading an unknown name is not a plain property read: it falls through to relation
resolution, which calls the method being named. Three routes reached it, all of them operating on an
already-loaded collection, which is why 0.7.14 and 0.7.15 — which closed the query-builder and the
eager-loading routes — left them open:

- `filterTableDataForObjects()`, dotted branch: the part before the dot was read off the model to
  walk into the relation;
- `filterTableDataForObjects()`, final segment: the remaining name was read off the model the walk
  landed on;
- `getTableDataForObjects()`, collection sort: the name went to `sortBy()`, which reaches the same
  read through `data_get()`.

Methods a project mixes into its models through traits were reachable this way, including the ones
`SoftDeletes` contributes, because the framework's own guard only covers methods declared on `Model`
itself.

**What happens now.** A column name is checked before it is read. A real attribute and an
already-loaded relation are read as before — Eloquent resolves neither by calling a method, and a
column may well share its name with an ordinary method of the model. Only a name that Eloquent would
have to resolve as a relation has to clear the same allow-list that already guards sorting, `load()`
and `whereHas()`. A name that does not clear it drops the row out of the filter in the search path,
and sorts as `null` in the collection sort, leaving those rows in the order they arrived rather than
running something. Two details of how the gate is applied:

- the exemption for an already-loaded relation applies to the final name only: every segment before
  it has to pass the allow-list regardless, because the same gate also feeds paths that `load()` and
  `whereHas()` execute before anything is loaded;
- both paths check every row rather than the first one, because a collection may hold more than one
  class; the allow-list verdict is memoised per model class, opt-in list and path.

**Behaviour change:** searching and sorting a collection by a relation that is not already loaded
now require that relation to pass the allow-list — declared with a `Relation` return type, or listed
in the model's `$sortableRelations`. A relation declared without a return type and without that
opt-in stops matching and stops ordering, rather than being read; this is the same narrowing 0.7.14
applied to sorting in the query builder, and it applies to every segment of a dotted column such as
`translations.name`. Plain columns are unaffected, with one exception in the sort path described
below. Two narrower changes come with it: rows that are not Eloquent models no longer take part in
column search or ordering, and a column whose name is a `data_get()` wildcard (`*`, `{first}`,
`{last}`) no longer orders anything, having never named a column in the first place.

**Where a plain column does change — sorting by a dotted path.** The last segment of a dotted column
is validated against a fresh instance of the related model, which carries no attributes yet, so a
real column whose name is also an ordinary method of that model is not recognised as an attribute
and sorts as `null`: a `value` column on a model that also declares `value(): string`, for example.
Searching the same column is unaffected — there the last segment is checked against the rows that
were actually loaded, where the attribute is present.

**New trait surface.** The trait now contributes a protected property `$relationPathVerdicts` and
two protected methods, `isReadableColumnPath()` and `isReadableColumnName()`, to every class using
it. A class of your own declaring either method silently takes precedence over the trait's version
and reopens the gate; an incompatible property of the same name is a fatal error.

**Rejections are logged.** A refused column is written once per request as a `warning` with the
model class and the column name — never the search phrase. Without it the narrowing is
indistinguishable from missing data, and this release reaches most projects through a routine
`composer update` rather than a deliberate one.

**Known remaining gaps.** This release closes the routes that read a column name off an
already-loaded collection. Two known ones stay open and are being handled separately: a dotted path
that walks through a `morphTo` relation is validated against the parent class rather than the type
the row actually points at, and `filterQueryTableData()` still passes the requested column name to
`where(..., 'like', ...)`, so a caller can probe any column of the table through the result count.
Until both are closed, keep `searchable` in your table definitions limited to the columns that are
genuinely meant to be searched, rather than leaving it on by default.

**Verified against.** The gate depends on the order of checks inside Eloquent's `getAttribute()`.
That order was read in laravel/framework 11.48 and 12.56, and exercised on 13.32.0; the behaviour
is identical in all three, but only 13.32.0 was run.

### 0.7.15

**Security fix (tableData) — three more paths to the same primitive.** 0.7.14 closed the sorting
path; eager loading and column search reached the identical "call any no-argument method on the
model" primitive by other routes, all of them present since at least 0.6.0:

- `getTableRelations()` fed every dotted column name straight to `$elements->load()`, and
  `Builder::getRelation()` resolves a relation by calling `$model->newInstance()->$name()`.
  `columns[i][name]=save.id` alone — no sorting, no search — inserted an empty record.
- `filterQueryTableData()` passed the part before the last dot to `whereHas()`, which calls
  `$model->{$relation}()` with no check of its own.
- `filterTableDataForObjects()` invoked `$item->{$name}()` for any column name ending in
  parentheses, so `columns[i][name]=delete()` plus a search term deleted every row of the
  collection.

Relation paths handed to `load()` and `whereHas()` are now validated segment by segment with the
same allow-list as sorting, walking the relation chain. The `name()` column syntax is removed
outright: no caller used it, and it depended on PHPUnit's `stringEndsWith()` — a `require-dev`
package referenced from production code, so that branch fatally errored on any `--no-dev` install.

Also in this release: the leftover `logger($colName); logger($column);` debug calls are gone (they
wrote the operator's search term, which may be a customer's e-mail or phone number, to
`laravel.log`), and `$sortableRelations` is now read through reflection, so a `protected` or
`private` list works instead of silently resolving to an empty array through Eloquent's `__get()`.

### 0.7.14

**Security fix (tableData).** Sorting by a relation column invoked a model method whose name came
straight from the request. `joinRelationForSorting()` checked `method_exists()` and then called
`$model->{$name}()` **before** verifying the result was a relation, so `columns[i][name]=save.id`
made the sort parameter call `save()` — reproduced end to end on a `POST /admin/*/data` endpoint,
where it inserted an empty record and fired the model observers. Any no-argument method reachable
on the model was callable the same way, including `push()` and the `saveChanges()` /
`saveCreatedInfo()` / `saveDeletionInfo()` helpers projects add through traits. Introduced in 0.7.9
together with relation sorting; 0.7.8 and earlier passed the value to `orderBy()` only and are
unaffected.

A method is now invoked only once its **declared return type** proves it is an Eloquent relation —
an allow-list, decided without running the method — and the returned value is still checked with
`instanceof Relation` afterwards.

**Behaviour change:** relations declared **without** a return type are no longer sortable by
default. Either add the return type (`public function customer(): BelongsTo`) or list the method
in a public `$sortableRelations` array on the model.

### 0.7.13

**Security fix (BaseFile) — XSS in `img()` and `imgPreload()`.** Attribute values were interpolated
into the tag raw, so `alt` and `title` — which carry text entered in the admin panel — could close
their attribute and open another one; a payload needs no angle brackets to do it
(`Audi R8" onerror="…`). Every attribute value these two methods build is now escaped with `e()`:
`src`/`href`, `alt`, `title`, `style`, `loading`, `fetchpriority` and the inner value of `class`.
`srcset`, `sizes` and the `class` fragment itself stay as already-formed `attr="…"` strings, and
`width`/`height` are integers taken from the thumbnail.

### 0.7.12

PHP 8.4 compatibility: five parameters in `files` and `uploads` traits used the implicit-nullable
form (`int $x = null`), deprecated in 8.4 and a hard error in PHP 9. They are now written explicitly
(`?int`, `?Model`).

**No behaviour change.** PHP already resolved these parameters to nullable types, so the effective
signature is identical — verified by reflection before and after (`type=?int`, `allowsNull=true`,
`default=NULL`). Callers need no changes; the parameters accept exactly what they accepted before.

Affected: `files::addFile()` (`$max_width`, `$max_height`, `$fileModel`), `uploads::addUpload()`
(`$max_width`, `$max_height`).

### 0.7.11

**Breaking change (minor):** `addUploads()` method now returns `Collection` instead of `void`.
This is backward compatible - if you don't use the return value, nothing changes.

The returned collection contains all created file models, which can be used to:
- Get IDs of uploaded files immediately after upload
- Associate files with other entities without time-based queries
- Process uploaded files in the same request

### 0.7.10

Added `prevent_upscale` configuration option for images. When set to `true`, images smaller than max_width/max_height will not be upscaled during WebP conversion or other processing. Default value is `false` to maintain backward compatibility.

### 0.7.9

Table Data - Added support for sorting by relation columns (BelongsTo, HasOne).
Previously, sorting by relation columns like `customer.surname` caused SQL errors.
Now the trait automatically adds LEFT JOIN and properly references the related table column.

### 0.7.8

Handle visibility parameter for S3/B2 compatibility in file uploads

### 0.7.7

Modify Laravel framework requirement in composer.json to support version 12.00

### 0.7.6

Optimized configuration checking in the files trait. The `store_with_extension` configuration is now checked once and stored in a variable for reuse, improving performance by avoiding repeated configuration lookups.

### 0.7.5

Fixed file parameter update when converting images to WebP format. The file parameter now correctly includes the WebP extension when store_with_extension is enabled.

### 0.7.4

Added option to store files with their extensions. This can be enabled by setting the `store_with_extension` option to `true` in the filesSettings config or by setting the `FILES_SETTINGS_STORE_WITH_EXTENSION` environment variable to `true`. The option is disabled by default.

### 0.7.3

BaseFile - Refactored rebuildFromSource method to use functions from the files trait

### 0.7.2

BaseFile - Add rebuildFromSource method to rebuild a file and its thumbnails from source file

### 0.7.1

Add ability to customize watermark opacity (default: 70)

### 0.7.0

Add watermark support to file upload and regeneration methods

### 0.6.0

BaseFile – Add position column to fileables table for improved ordering.

### 0.5.4

BaseFile – Added support for full URLs in srcset and imgPreload methods.

### 0.5.3.1

tableData – Option to add parameter to scopes.

### 0.5.3.0

Add relation_type to files and improve file handling logic

Introduced the `relation_type` field in files table to better categorize file associations such as 'thumbnails' and '
source'. Enhanced file handling by restructuring methods for clarity, adding support for source files, refining
thumbnail regeneration, and improving WebP conversion logic. Loaded migrations in HelperServiceProvider and updated
related configurations.

### 0.5.2.3

BaseFile – Added fullStoragePatch function.

### 0.5.2.2

BaseFile – Added cache for basic functions.

### 0.5.2.1

BaseFile – Added imgPreload function.

### 0.5.2.0

BaseFile – Added additional_properties for eg title and alt.

### 0.5.1.6

BaseFile – Added function findBySlug.

### 0.5.1.5

files – trait – Added slug.

### 0.5.1.4

BaseFile – img – Fix for sizes.

### 0.5.1.3

BaseFile – Fix.

### 0.5.1.2

BaseFile – Fix for finding thumbnails.
BaseFile – Added fetchPriority.

### 0.5.1.1

files – trait – Fix for resizing images.

### 0.5.1.0

Command for rebuilding files with webp and thumbnails.

### 0.5.0.0

Changes in files class and upgraded intervention/image.

### 0.4.1.12

Laravel 11 support.

### 0.4.1.11

tableData - Changes for deprecated in PHP 8.2.

### 0.4.1.10

tableData - files - Fix for Amazon S3.

### 0.4.1.9

tableData - files - Fix for using storage.

### 0.4.1.8

tableData - uploads - Fix for options.

### 0.4.1.7

tableData - Fix for searching in objects.

### 0.4.1.5

refactor: files - change folder and files permissions

### 0.4.1.4

files - change folder and files permissions

### 0.4.1.3

uploads - trait - Regenerate thumbnails function.

### 0.4.1.2

uploads - trait - Fix for maximum thumbnails sizes.

### 0.4.1.1

uploads - trait - Fix for using files trait.

### 0.4.1.0

uploads - trait.

### 0.4.0.3

Table Data - getTableData - Added support for scopes.
Table Data - getCachedTableData - Added cache name modifier.

### 0.4.0.2

Laravel 10 support.

### 0.4.0.1

Table Data - getCachedTableData - Fix for cache name.

### 0.4.0.0

Table Data - New versions separated for searching in objects and classes.

### 0.3.12.0

Table Data - filterTableData - Fix for search in multiple one-to-many relations.

### 0.3.11.0

modelCache trait - Additional parameter and changed name of loadRelations parameter into withRelations.

### 0.3.10.0

Files trait - Added option to prevent resizes of images.

### 0.3.9.4

Files trait - Fix for saving properly file sizes after resize.

### 0.3.9.3

modelCache trait - Default empty value.

### 0.3.9.2

Table Data - getSearchingRelations - Fix for no column's data, when running tests.

### 0.3.9.1

Table Data - getTableRelations - Fix for no column's data, when running tests.

### 0.3.9

Table Data - Added loading of needed relations after searching.

### 0.3.8

modelCache trait - Trait for using cache in models.

### 0.3.7

Table Data - Added getSearchingRelations function.

### 0.3.6

Files trait - Added a list of forbidden extensions for converting to WebP.

### 0.3.5

Config - Added setting to block WebP conversion.

### 0.3.4

Files trait - Forcing images to be converted to WebP has been added.

### 0.3.3

Files trait - Added saving in local relation - fix.

### 0.3.2

Files trait - Added saving in local relation.

### 0.3.1

Table Data - Searching by relations.

### 0.3.0

Files trait - Added saving of mime_type values.

### 0.2.9

Marked support for Laravel 9.

### 0.2.8

Table Data - Fix for general search.

### 0.2.7

Table Data - Better support for searching in nested models.

### 0.2.6

Files - Parameters for thumbnail handling have been added.

### 0.2.5

Files trait - Added appending of file name to path.

### 0.2.4

Files trait - Added option to get relation name.
Files trait - addFile - Return created model.

### 0.1.0

* Init project
