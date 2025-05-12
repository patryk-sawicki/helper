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
