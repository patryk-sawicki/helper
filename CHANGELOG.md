### 0.7.20

**Fix (files) — `rebuildFromSource()` stores the sizes `addUpload()` does.** The main file was rebuilt
with `preventResizing: true` and no limits, so it came out at the source's full resolution: a
3000×2000 original replaced its 1080×720 main file, and the original was served in place of the
preview. The thumbnails went through `addFiles()`, which passes no size, so `addFile()` fell back to
`images.max_width`×`images.max_height` and every thumbnail came out as large as the uploaded main
file: with thumbnail sizes 80, 400 and 800 wide, three of 1080×720 instead of 80×53, 400×267 and
800×533. The main file is now scaled to fit within `images.max_width`×`images.max_height`, and each
thumbnail is cut from the source at its size in `thumbnailSizes`, the way `addUpload()` does both.

What changes, for projects that call `rebuildFromSource()`:

- the rebuilt main file and thumbnails get the sizes an upload of the source would get. The limits
  are the ones configured when the rebuild runs, so a file uploaded with its own `max_width` and
  `max_height` passed to `addUpload()` comes out at the configured ones, and after the limits have
  been raised the rebuilt main file is larger than the one it replaces, with the thumbnails that fit
  in it. Do not rebuild files uploaded with smaller limits than the configured ones: their main file
  would come out larger, and one whose source fits within the configured limits would show the
  source in full;
- as in an upload, a source smaller than the limits is scaled up to them when it is converted to
  WebP, unless `images.prevent_upscale` is set; the main file used to keep the source's size;
- the main file of a source larger than the limits is always resized now, so with the conversion on
  it is marked when a watermark is passed, also when the source is WebP, which used to come out as
  an unmarked copy of itself (with the conversion off it throws, see below). A source that fits and
  is WebP, or is not converted, is still stored unmarked (the known gap of 0.7.18). The thumbnails
  of a WebP source are scaled down from it, so they are marked too, except one for a size in
  `thumbnailSizes` with neither a width nor a height: it is cut at the main-file limits, so from a
  WebP source that fits them it is stored as a copy, as the main file is;
- the thumbnails are stored with the `options` passed to the method, as the main file is and as
  `addUpload()` stores them; they used to get the default ones (with the default disk named `s3`,
  that disk's `visibility`, or `public` when it sets none). A caller passing `[]` sees no
  difference. Pass the options the file was uploaded with: `[]` stores the rebuilt files with that
  default visibility, whatever they had before;
- the watermark and the encoding work on the scaled image, so a call takes less memory and time
  after the source has been decoded; the source is still decoded at its full resolution, once for
  the main file and once for each thumbnail, as in `addUpload()`;
- `rebuildFromSource()` no longer calls `addFiles()`. A file model that overrides `addFiles()` to
  act on the rebuilt thumbnails has to override `addFile()` instead, which each thumbnail now goes
  through with `relationName: 'thumbnails'`;
- after a rebuild, the `thumbnails` relation of the file it was called on lists the new thumbnails.
  The method loaded the relation to delete the old ones and left it loaded, so `srcset()`, `img()`
  or `thumbnail()` called on the same instance right after listed the deleted thumbnails, and the
  first two cached them for the cache's lifetime (`app.cache_default_ttl`, a day by default).

**Breaking for callers passing `forceWebP: false`, for GIF sources, and with the conversion blocked
— the `TypeError` of 0.7.18 moves and widens.** With the conversion off, resizing an image throws it, as the encoder is called
with `null`. With `forceWebP: false` it now hits the main file of any source larger than the limits,
before the main file is written, where the call used to return `true` with an unmarked
full-resolution copy. With the conversion blocked in the configuration (`block_webp_conversion`, or
the source's extension listed in `forbidden_webp_extensions`, where `gif` is by default) it hits
nearly every image, on the main file or on the first thumbnail, as it does in `addUpload()`; a
source that fitted within the limits used to be rebuilt without it. Either way the old files are
already deleted and the transaction is left open (see `docs/rebuildFromSource.md`). Do not call the
method for such files; the `TypeError` itself will be fixed separately.

**Files rebuilt before 0.7.20.** Updating does not change files already rebuilt: a file rebuilt by
0.7.19 on any disk, or by an earlier version on a local disk rooted at `storage_path('app')`, keeps
a main file at the source's full resolution, so the original is still served as the preview, and
thumbnails as large as an uploaded main file, until it is rebuilt again. Such a file has a main file
wider or taller than the limits it was rebuilt under, or, when its source fitted within them, a
thumbnail as wide as its main file or wider (a size in `thumbnailSizes` with neither a width nor a
height aside). Rebuild such files with 0.7.20, passing the options and the watermark they were
uploaded with, except those the `TypeError` hits. This includes files uploaded with smaller limits
than the configured ones: kept out of a first rebuild (see above), once 0.7.19 has rebuilt them a
rebuild with 0.7.20 can only bring a source larger than the configured limits down to them.
`regenerateThumbnails()` does not clean them up: it soft-deletes thumbnails of sizes no longer
configured without deleting their files.

**Still to check before calling it.** The other limits listed in 0.7.18 and 0.7.19 stay: the old
files are deleted before the new ones are written, so an `Exception` midway makes the method return
`false` with them gone (the source is kept, and a later successful rebuild brings them back); the
file needs an owner through its `model` morph, and the owner has to drop the key that
`addFile(externalRelation: false)` writes onto it; do not call it inside a transaction of your own;
decoding a large source still needs memory for the whole image at its full resolution, once for the
main file and once for each thumbnail; the 0.7.18 figure for a large portrait, which includes the
watermark at that resolution, is an upper bound.

**Not changed.** The signatures of `rebuildFromSource()`, `addFile()`, `addFiles()` and
`addUpload()`. `regenerateThumbnails()`, `rebuildFiles()` and the `files:rebuild` command are
untouched and still read from `storage_path('app')`.

**Tests.** `RebuildFromSourceTest` checks that a rebuild stores the same sizes as the upload it
replaces, the main file and each thumbnail, for a landscape, a portrait and a square source larger
than the limits and for a small one scaled up to them, each size checked against the image stored
on the disk, and for a small one kept at its size while `images.prevent_upscale` is set; that a
rebuild takes the limits configured when it runs and decides the thumbnails by the rebuilt main
file; that a WebP source larger than the limits is resized and marked; that the file it was called
on lists its new thumbnails; that a rebuild with `forceWebP: false` of a source larger than the
limits throws the `TypeError`, with the old files deleted and no main file written (this one fails
once the `TypeError` is fixed, so the docs are updated with it); and that the thumbnails are given
the `options` passed to the rebuild. That last one checks what the test fixture passes to
`addFile()`, not the visibility stored: the fake disk is a local one, and on Windows a local file
reads back as public whatever it was stored as. The existing tests now expect the main file within
the limits (480×720 for their 960×1440 source) and two thumbnails.

### 0.7.19

**Fix (files) — `rebuildFromSource()` works on S3 and any other disk.** The source was looked up
under `storage_path('app')` with `file_exists()`, while `addFile()` stores files through `Storage`,
on the default disk. On S3 or any other disk than a local one rooted at `storage_path('app')` the
method logged `Source file does not exist` and returned `false` for every file, rebuilding nothing.
It now checks the source with `Storage::exists()` and copies it through `Storage::readStream()` to a
temporary file (in `sys_get_temp_dir()` by default, see `temporaryDirectory()` below), which image
processing needs a local path for. The copy is made before anything is deleted, and its length has
to match `Storage::size()`, so a source that cannot be read, or comes back shorter than the disk
reports, leaves the stored files as they were. (An S3 object stored with `Content-Encoding: gzip`
may come back decoded, at another length, and then fails that check and is not rebuilt; images are
not normally stored that way.) The copy is removed when the method returns or throws; only a fatal
error, such as running out of memory, leaves it behind. Its name starts with `helper-rebuild-` (on
Windows, where `tempnam()` keeps only the first three characters of the prefix, it starts with
`hel` and ends in `.tmp`, so check a file before deleting it). The size of the rebuilt main file,
which decides which thumbnails to create, is taken from the file's record, which `addFile()` fills
with the size it stored, instead of reading the stored file back from the local disk.

What changes, for projects that call `rebuildFromSource()`:

- on S3 (or another remote disk) the main file and the thumbnails are now rebuilt, so a rebuild run
  after switching a watermark on or off changes the files stored there too; on a local disk rooted
  at `storage_path('app')`, where the method already worked, the result is unchanged;
- a local default disk with another root than `storage_path('app')`, such as
  `storage_path('app/private')`, the default since Laravel 11, works too, as the source is read from
  the disk it was written to;
- each rebuild downloads the source once and needs room for it in the temporary directory
  (`sys_get_temp_dir()` by default);
- a call is no longer cheap on S3, where it used to return `false` at once: each one downloads,
  decodes, marks and encodes the source at its full resolution, then uploads the main file and every
  thumbnail. Rebuilding many files, such as a whole gallery, in one HTTP request can run past
  `max_execution_time` or a proxy timeout and leave the gallery partly rebuilt, the file it stopped
  on possibly with its old files deleted and the new ones not written, so run it in a queued job,
  one file per job;
- do not call it inside a transaction of your own, such as one around a loop over a gallery. The
  method's own commit then commits nothing (the outer transaction decides), so when the outer
  transaction is rolled back afterwards (an exception after the loop, a timeout, a fatal error, a
  lost connection), the records of **every** file rebuilt in it go back to their old files, which
  are already deleted, and the new files stay on the disk as orphans. The sources are kept, so
  rebuilding those files again brings them back;
- when the disk reports an error while checking or reading the source, such as a lost connection to
  S3, the method logs it and returns `false` before anything has been changed, as for a missing source.

**Still to check before calling it.** Every other limit listed in 0.7.18 stays: the main file is
rebuilt at the source's full resolution, the thumbnails at the main-file size, the old files are
deleted before the new ones are written, the mark is taken off instead of put on when the main file
is not converted to WebP, the `TypeError` with the conversion blocked, and the memory a large
portrait takes. On S3 they now apply, since the method used to stop before them.

Two more, which 0.7.18 did not list, apply on every disk and make every call fail for the models they
concern, after the old files have been deleted:

- the file has to belong to an owner through its `model` morph, and the owner has to be found. The
  main file is rebuilt through `$this->model->addFile()`, so for a file without an owner, or with a
  soft-deleted one, the method throws an `Error` with its transaction still open;
- the owner has to drop a key silently. With `externalRelation: false`, which the method passes,
  `addFile()` writes the relation's key onto the owner, and for the default `files` relation, or any
  other `morphMany` or `hasMany` one, that is the key on the file's side (`model_id`), a column the
  owner does not have. A `$fillable` that leaves it out, or a `$guarded` that lists some columns,
  drops it. With `$guarded = []` or after `Model::unguard()` it reaches the query and fails; with
  neither `$fillable` nor `$guarded` set, or with `Model::preventSilentlyDiscardingAttributes()`
  (part of `Model::shouldBeStrict()`) on, a `MassAssignmentException` is thrown. The method then
  returns `false`, and the records it rolls back point to files it has already deleted.

**Not changed.** `regenerateThumbnails()`, `rebuildFiles()` and the `files:rebuild` command still
read files from `storage_path('app')`, so on a remote disk they skip every file.
`FileController::downloadById()`, behind the `/file/{file}` route that `url()` and `srcset()` link
to, still builds its path from `../storage/app`, so it is local only too. `fullStoragePatch()` and
the signature of `rebuildFromSource()` are unchanged. `BaseFile` gains three protected methods,
`sourceExistsOnDisk()`, `copySourceToTemporaryFile()` and `temporaryDirectory()` (where the copy is
made, `sys_get_temp_dir()` by default; when the directory it returns cannot be used, the copy is
made in `sys_get_temp_dir()` and a warning is logged); a file model that already has methods with
those names now overrides them and has to keep their signatures. Disk errors, and a temporary file
that cannot be created, are now logged as warnings, not as info, the disk errors with the exception
in the log context; the error logged when the rebuild throws an `Exception` now carries it in the
context too, and the message about the temporary file names the directory.

**Tests.** `RebuildFromSourceTest` runs `addUpload()` and `rebuildFromSource()` on a fake S3 set as
the default disk, and checks that the source is not under `storage_path('app')`, so a local lookup
cannot pass it: a rebuild with a watermark marks the main file and the thumbnails, one without takes
the mark off, the thumbnails follow the size of the rebuilt main file, not of the one it replaces,
a missing or unreadable source, one read short, or a disk error while checking or reading it,
returns `false` and keeps the files without leaving a copy behind, the temporary copy is removed,
also when the rebuild throws an `Error`, a temporary directory that cannot be used does not stop the
rebuild and is logged (the default one is not), and a transaction that cannot be opened propagates
its exception and leaves the caller's transaction open. One more runs on a local default disk
rooted at `storage_path('app')`, where the method already worked, to check the result there is
unchanged. They are skipped without `gd`, `pdo_sqlite` or WebP support in GD.

### 0.7.18

**Fix (files) — the watermark now covers the whole image.** `addFile()` scaled the watermark to the
image's width and placed it at the top-left corner, so the watermark's height followed its own aspect
ratio, not the image's. With a 3:2 watermark a 2:3 portrait was marked on its top 44% only, a 9:16 one
on 37.5%, and the rest of the frame could be cropped off unmarked. Thumbnails had the same gap, since
each goes through `addFile()` with its own width. The watermark is now scaled to **cover** both
dimensions and placed in the **centre**, with the overflow cropped evenly on both sides.

What changes in the output, for projects that pass `watermark:`:

- an image taller than the watermark's aspect ratio (a portrait, for a landscape watermark) is marked
  top to bottom, and shows the middle slice of the watermark at the same scale as a landscape of
  the same height with the watermark's own aspect ratio — so the pattern is larger than in 0.7.17,
  which fitted it to the width, and is cropped evenly at the sides;
- an image with exactly the watermark's aspect ratio comes out identical to 0.7.17;
- an image wider than the watermark's aspect ratio was already covered, but the overflow was cut off
  the bottom; it is now cut evenly from the top and bottom, so the pattern shifts up by half of that.

If you design the watermark image: `cover` crops it to each image's aspect ratio, cutting the sides
on images taller than the watermark and the top and bottom on wider ones. Keep anything that must
stay visible, such as a logo or a name, near the middle, or use a pattern that repeats across the
whole watermark.

**Files already stored are not touched.** Only files processed from now on get the new composition.
`BaseFile::rebuildFromSource()` can re-mark an existing file from its source, but check its limits
first. It reads the source from the local disk only (`storage_path('app')`), so on S3 or another
remote disk it returns `false` without changing anything. It rebuilds the main file at the source's
full resolution, not within `max_width`×`max_height`. It recreates the thumbnails at the main-file
size (`images.max_width`×`images.max_height`) instead of the sizes in `thumbnailSizes`. It
deletes the old files before writing the new ones: if an exception stops it midway, the database is
rolled back but the deleted files are not restored (the source is kept, so a later successful
rebuild brings them back). An `Error`, such as the `TypeError` described below, is not caught at
all: the method throws with its transaction still open. And without a conversion it takes the
watermark off instead of putting it on (see the known gap below). These limits predate 0.7.18 and
will be addressed separately.

**Thumbnails regenerated from an older file.** `regenerateThumbnails()`, and `rebuildFiles()`, which
calls it, cut new thumbnails from the stored main file, which already carries its watermark, and
mark them again when you pass `watermark:`. For a file stored before 0.7.18 the two marks no longer
line up, the old one fitted to the width from the top and the new one covering the frame from the
centre, unless the image has the watermark's own aspect ratio. Only missing thumbnails are
created, so this shows when you add a size to `thumbnailSizes` or thumbnails were removed.

**Known gap, not fixed here.** The watermark is applied only when `addFile()` processes the image,
that is when it has to resize it or convert it to WebP. An image that needs neither is stored as
uploaded, without the watermark, whatever its format: one that is already WebP, or one for which the
conversion is off (`forceWebP: false`, `block_webp_conversion`, an extension listed in
`forbidden_webp_extensions`), as long as it fits within `max_width`×`max_height` (a larger one with
the conversion off is not stored at all, see the known problem below). In `rebuildFromSource()` this
goes further. The source is the original as uploaded, and the main file is rebuilt from it with
`preventResizing: true`, so without a conversion the main file is replaced by an unmarked copy of the
source at full resolution, even if it was marked before. Calling `rebuildFromSource(watermark: ...)`
on such a file takes the mark off the main file, and off the thumbnails too when the source is WebP
or blocked from the conversion in the configuration and fits within
`images.max_width`×`images.max_height`.

**Known problem, not fixed here.** When `addFile()` has to resize an image without converting it to
WebP, saving it fails with a `TypeError`, with or without a watermark, after the file's database
record has been created: the encoder is called with `null`, which intervention/image 3 does not
accept. It dates from 0.5.0.0, which upgraded intervention/image; 0.7.18 does not change it. It hits
every image that `addFile()` resizes while the conversion is off: every GIF, which
`forbidden_webp_extensions` lists by default, every image once `block_webp_conversion` is set, and
any image passed to `addFile()` with `forceWebP: false`. A
main file is resized only when it is larger than `max_width`×`max_height`, and such an image is not
stored at all. Thumbnails are always resized, to the sizes in `thumbnailSizes`, so `addUpload()` and
`addUploads()` also fail on an image that fits, on the first thumbnail they create: with the default
sizes, on any such image wider and taller than 64 px, or wider than 374 px. The main file and the
source are stored by then, and the thumbnail's record is left pointing to a file that was never
written. `regenerateThumbnails()`, and `rebuildFiles()`, which calls it, fail the same way whenever
they create a thumbnail for a GIF main file, or for any main file once `block_webp_conversion` is
set. `rebuildFromSource()` hits it on the thumbnails of a source larger than
`images.max_width`×`images.max_height` when the conversion is blocked in the configuration, after it
has written the main file as an unmarked copy of the source; `docs/rebuildFromSource.md` describes
what that leaves behind.

**Transparency under a translucent watermark.** Below 100% opacity the GD driver places the
watermark through an intermediate copy without an alpha channel, so transparent areas of the image
come out opaque where the watermark covers them. That was already so, but only across the top band
the watermark used to reach; it now applies to the whole image. Photos carry no transparency and
are unaffected; a PNG or WebP with a transparent background is.

**Memory on large portraits.** The watermark is now scaled to the full size of the image, so on an
image taller than the watermark's aspect ratio it takes more memory than before. Measured with GD
at 70% opacity, a 4000×6000 portrait peaks at about 290 MB instead of 190 MB — the same as a
6000×4000 landscape already did. This matters in `rebuildFromSource()`, which works at the source's
full resolution; uploads resized to `max_width`×`max_height` are unaffected. Check `memory_limit`
if you rebuild large portrait photos.

**Tests.** The watermark is checked both on the composition and on the file `addFile()` stores, so
the tests fail if `addFile()` stops marking images, or starts marking a source it converts to WebP
(`addUpload()` stores the source unconverted, so it never reaches the watermark step). They are
skipped without `gd` or `pdo_sqlite`, and the ones through `addFile()` also without WebP support in
GD, which they convert to.

The composition now lives in the protected `files::placeWatermarkOnImage()`. The signatures of
`addFile()`, `addFiles()`, `addUpload()` and `rebuildFromSource()` are unchanged.

### 0.7.17

**At a glance**

- two security fixes in `tableData`: relation paths crossing a `MorphTo`, and the column name
  reaching `LIKE`, `ORDER BY` and `whereHas` unchecked;
- one more, found late: the collection's global search matched a substring of the whole serialised
  row, password hash included;
- **behaviour change** — a refused column filter now returns an empty list instead of an
  unfiltered one;
- **behaviour change** — sorting by a column of a to-many relation, or by a nested relation path,
  no longer orders (it never ordered correctly);
- **behaviour change** — a column the model hides, or that is no column of the table, stops being
  searchable; a small floor of names is refused whatever the model declares;
- **something to do, not just to read**: the gate follows your models' `$hidden`/`$visible`/casts.
  Go through the models you list.

**Security fix (tableData) — relation paths that cross a `MorphTo`.** Upgrade from any earlier
version. The gate that proves each segment of a dotted column is a real relation walked the path
with `$current->{$segment}()->getRelated()`. Eloquent resolves a `MorphTo` against the type column
of the row the relation was built from, so on an instance without that column filled — which is
every instance `getRelated()` itself produces — it hands back the parent model. Everything past
such a segment was therefore proven against a class that never holds it, while `data_get()` read
the same path off the real target at runtime and called the method it named there. The package
ships `BaseFile::model(): MorphTo`, so `<files relation>.model.<anything>` was reachable.

**What happens now.** A `MorphTo` whose type column is filled is followed to its real target, and
the rest of the path is proven against that. A `MorphTo` whose type column is empty ends the path:
`isAllowedRelationPath()` still allows one as the final segment, because nothing is validated past
it and eager loading a morph is a legitimate thing to ask for, and refuses one anywhere earlier.
`isReadableColumnPath()` refuses it outright, since there the segment is always followed by the
leaf. Nothing is queried to decide this — the type is already on the row.

**Behaviour change:** a column that walks through a morph relation reached from another relation,
such as `files.model.name`, stops matching and stops ordering. The single-segment case a table
actually uses — a column like `model.name` on a row that carries its `model_type` — is unaffected.

**Two consequences of that rule, both of which used to leak across rows.** A verdict about a path
that crosses a morph belongs to the ROW, because the target comes from the row's own type column:

- the memoised verdicts are keyed by model class, so such a verdict is now returned and thrown
  away rather than stored. Stored, the first row asked answered for every later one in both
  directions — a `true` opening a path the next row does not have, and a `false` closing one it
  does;
- both entry points proved the eager-loading paths on one model — `$elements->first()` in
  `getTableDataForObjects()`, the query's prototype in `getTableData()` — and handed them to
  `load()`, which applies them to the whole collection. A collection holds more than one
  class and more than one morph target, and the request decides which row is first, through the
  sort, the search and the page. Paths are now the intersection of what every row allows.

**Security fix (tableData) — the column name as a LIKE and ORDER BY identifier.** Upgrade from any
earlier version. `filterQueryTableData()` put `columns[i][name]` straight into
`where($name, 'like', …)`, and `applySortingToQuery()` put it into `orderBy()`. The identifier is
wrapped by the query grammar, so this was never injection: it is that a client free to name any
column of the table turns the number of rows that survive the filter into an oracle and reads a
secret out of it one character per request — `password` and `remember_token` included. The
`searchable` flag beside the name gated nothing, arriving in the same request. The column on the
far side of a `whereHas()` was not checked at all, so a name that is no column at all reached SQL.
The same reading existed over collections, where `isReadableColumnName()` accepted any attribute
the row carried.

**What happens now.** Both paths consult an allow-list derived from the model rather than from the
request, and each path derives it from what that path can legitimately address:

- a query filter or sort accepts the columns the table really has, less the ones the model declares
  as not for output through `$hidden` and the ones cast as `hashed` or `encrypted`. A schema that
  cannot be read yields an empty list rather than a guess;
- a collection filter or sort judges a real column of the table the same way, by the allow-list.
  Only a name that is NO column — a counted relation, an accessor materialised into the
  attributes — is judged by what the row carries, since there is no schema entry to look up.

A model that needs a different set declares a public `$searchableColumns`, which replaces the
derived list outright, the way `$sortableRelations` opts relations in. Declaring it as `null`
makes no choice and falls back to the derived list; an empty array does mean "allow nothing".
Every refusal goes through `reportRejectedColumn()`, so it appears in the log rather than looking
like missing data.

**Details of the two lists.** `$hidden`, `$visible` and `$casts` belong to the instance, not to
the class, so the derived list is not memoised by class name; the schema read, which is the
expensive part, is keyed by those lists as well. A `$visible` allow-list is narrowed against the
SCHEMA, because every caller on the query path hands in a prototype — `Builder::getModel()`,
`Relation::getRelated()` — that carries no attributes to take a complement from. An encrypting
cast given as a class string is recognised too, since `AsEncryptedArrayObject::class` does not
begin with the word `encrypted`.

**A column is judged by the table, not by the row.** A name the table really has clears or fails
the allow-list whether or not this instance carries it as an attribute — the leaf of a dotted
column is validated against a prototype, so "not loaded here" must never read as "not a column".
Only a name that is NO column of the table — an accessor, a counted relation — is judged by what
the row carries. Where a column shares its name with a method, the relation gate still applies,
because on a row without that attribute Eloquent resolves the read by calling it.

**ORDER BY needed more than the plain branch.** `applySortingToQuery()` checks the column when it
has no dot, but `joinRelationForSorting()` answered `"$relationName.$column"` in four branches
where it could not build a join — a nested path, a name that is no relation, a name that resolves
to no `Relation`, and any to-many relation. The grammar wraps those two halves as table and
column, so the string was not a fallback: `helper_users.password` sorted real rows by the hash
without touching a relation, and a name that is no relation ordered by a table that does not
exist, which is a 500 the request chose. All four branches now refuse, and the caller leaves the
query unordered. **Behaviour change:** sorting by a column of a to-many relation, or by a nested
relation path, stops ordering — neither ever produced a correct order.

**Behaviour change:** searching or sorting by a column the model hides, or by a name that is no
column of the table, stops working. In practice this is `password` and `remember_token`; a project
that lists a column outside its table — an accessor addressed through the query builder — has to
declare `$searchableColumns` for it.

**A floor under the derived list.** The list follows the model's own `$hidden`, `$visible` and
casts — so a model that declares none of them still exposes every column of its table, and models
like that exist, with a password column among the rest. A small set of names is therefore refused
whatever the model says: `password`, `password_hash`, `remember_token`, `salt`, `secret`, `api_key`, `app_key`, `auth_key`,
`hmac_key`, `signature_key`, `recovery_codes` and `two_factor_recovery_codes`, plus anything
ending in `_token`, `_secret` or `_password` and anything starting with `secret_`. `_hash` and
`_key` are deliberately NOT suffixes: they name identifiers far more often than secrets, and a
survey of the consumer migrations on hand found `short_hash`, `pdf_hash`, `idempotency_key` and
`checkout_key`, all of them columns a list is searched by. This is not a deny-list standing in for
the allow-list — it only ever removes from
what the allow-list already permits, including from an explicitly declared `$searchableColumns`,
since "always blocked" cannot mean "unless someone writes it down". The names live in
`ALWAYS_BLOCKED_COLUMNS` and `ALWAYS_BLOCKED_SUFFIXES`, read through `static::`, so a subclass can
narrow or empty them.

**Still, read this before assuming you are done.** Outside that floor, what the gate protects is
exactly what your models declare. A model listed by `getTableData()` or `getTableDataForObjects()`
that declares no `$hidden`, no `$visible` and no encrypting cast still offers every one of its
columns to a search — go through the models you list and give them one.

**A refused filter narrows; it no longer disappears.** Returning without adding a condition left
the surrounding `where()` group empty, and an empty group is dropped — so the filter vanished and
the list came back UNFILTERED, with `recordsFiltered` equal to `recordsTotal` and nothing visibly
wrong. On the one path where the client is asking for FEWER rows that is the wrong direction, and
it disagreed with the collection path, which has always dropped the row on a refusal. Both now
narrow. **Behaviour change:** a column filter the gate refuses returns an empty list rather than
an unfiltered one. In the global search, where each column is one member of an OR, a refused
column simply contributes nothing and the others still match.

**The collection's global search had no gate at all.** It matches a substring of the whole
serialised row, and `toArray()` of a model that declares no `$hidden` carries the password hash —
so the search read that hash one character per request, for exactly the population the floor above
exists for. Measured: `bcrypt$X` matched, `bcrypt$XY` matched, `bcrypt$QQ` did not. The row is now
serialised through the floor before being matched, at every level, relations included. Eloquent's
own `__toString()` only repeats `toJson()`, so it is folded in only where a model declares one of
its own.

**The refusal trace is no longer an amplifier.** A refused column is logged, because a gate that
goes quiet is indistinguishable from missing data — but the column name comes from the request,
and so does the number of names. Laravel's `LineFormatter` runs with `allowInlineLineBreaks`,
which turns an escaped newline in the context back into a real one, so a name carrying one wrote
a second physical line that reads like a log record of its own. Control characters are stripped
and the name is truncated; a single request writes at most 20 records plus one saying the rest
were dropped. Measured before the change: 1000 refused columns in one request wrote 1000 records
and 179 kB, and an 8000-character name wrote an 8 kB line. The record also carries a `reason` now
— `relation`, `column`, `path`, `nested-sort` or `to-many-sort` — because after this release most
refusals no longer come from the relation allow-list, and a trace naming the wrong gate sends
whoever reads it the wrong way.

**Reading a morph type no longer calls anything.** `getAttribute()` falls through to relation
resolution for a name it does not find among the attributes, and that calls a method carrying that
name. The morph check asked it for the type column, so a model with a method named like its own
type column answered every table request with an uncaught `LogicException`. The type is read
straight from the attribute array now.

**New trait surface.** The trait now contributes three protected properties —
`$searchableColumnCache`, `$tableColumnCache` and `$rejectionsReported` — four protected
constants — `MAX_REJECTION_REPORTS`, `MAX_REJECTION_NAME_LENGTH`, `ALWAYS_BLOCKED_COLUMNS` and
`ALWAYS_BLOCKED_SUFFIXES` — and eleven protected methods: `declaredSearchableColumns()`,
`blockedColumns()`, `searchableColumns()`, `tableColumns()`, `isSearchableColumn()`,
`isAlwaysBlockedColumn()`, `isMorphTargetUnknown()`, `relatedModelForPath()`, `sanitiseForLog()`,
`matchNoRows()`, `searchableRepresentation()`, `stripAlwaysBlocked()` and
`filterRelationPathsForEveryRow()`. `reportRejectedColumn()` takes a third argument, the reason.
The default (`unspecified`) lets existing CALLS keep working; an override declaring two parameters
is incompatible with the trait's signature regardless of defaults and has to be widened.

A class of your own declaring any of those METHODS silently takes precedence over the trait's
version, as with the surface 0.7.16 added. The two CONSTANTS behave differently, and neither way
is "taking precedence": a class using the trait and declaring one of them is a fatal error at
composition, while a subclass may redefine it — the trait reads them through `static::`, so that
redefinition is honoured and is the supported way to move the limits.

`joinRelationForSorting()` now returns `?string` rather than `string`, returning null whenever it
refuses. Return types are covariant, so an override declaring `: string` stays legal and needs no
change — but it can no longer express a refusal, and one that keeps answering with a column name
puts that name back into `ORDER BY`.

**Verified on** Laravel 11.56.1, 12.69.2 and 13.32.0, each with the same set of checks run against
the same synthetic models: the derived column list, the refusal of `password` in both LIKE and
ORDER BY, the collection path, and all four morph cases. Results were identical across the three.

**The package has tests now.** `phpunit.xml` described an application rather than this package —
it pointed at a `tests/` directory that did not exist, measured coverage over `./app`, and carried
attributes PHPUnit 10 removed; `phpunit/phpunit ^9.5` could not run on a Laravel 13 stack at all.
It is now a package configuration, with `orchestra/testbench` and an in-memory SQLite database,
and the security gates that 0.7.14, 0.7.15, 0.7.16 and this release put in place have regression
tests: the relation allow-list, the searchable column list, the sorting gate, the morph paths and
the refusal log — including the test that proves reading such a path directly really does run the
method, which is what the gate exists to prevent. `.github/workflows/tests.yml` runs the suite
against Laravel 11, 12 and 13.

**A note on Laravel 11.** The constraint still accepts `^11.00`, deliberately: the projects still
on that line are the ones this fix matters to most. That line is outside the framework's own
security fix window — see the support matrix in the README before staying on it.

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
