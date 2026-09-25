<?php

namespace PatrykSawicki\Helper\Tests\Unit;

use Error;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Colors\Rgb\Channels\Green;
use Intervention\Image\Colors\Rgb\Channels\Red;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use Mockery;
use PatrykSawicki\Helper\Tests\Fixtures\RebuildableFile;
use PatrykSawicki\Helper\Tests\Fixtures\RebuildOwner;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TypeError;

/**
 * rebuildFromSource() on a disk other than the local one (AA-271).
 *
 * Up to 0.7.18 the source was looked up under storage_path('app'), so on S3 the method returned false
 * for every file and rebuilt nothing. The disk here is a fake S3, set as the default disk the way a
 * project on S3 has it; its root is not under storage_path('app'), and each test checks that, so none
 * can pass through a local lookup.
 *
 * The requirements are attributes for the reason given in WatermarkCoverageTest. imagewebp is needed
 * because addFile() converts the main file and the thumbnails to WebP.
 */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('pdo_sqlite')]
#[RequiresFunction('imagewebp')]
class RebuildFromSourceTest extends TestCase
{
    private ImageManager $manager;

    /** @var string[] */
    private array $temporaryFiles = [];

    /** Directory under storage_path('app') the local-disk test writes to, removed after it. */
    private ?string $localDirectory = null;

    /** Directory the rebuild copies the source to in the tests that set it, removed after them. */
    private ?string $copyDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ImageManager(new Driver());
        RebuildableFile::$lastTemporaryCopy = null;
        RebuildableFile::$removeSourceBeforeCopy = false;
        RebuildableFile::$throwOnThumbnails = false;
        RebuildableFile::$temporaryDirectory = null;
        RebuildableFile::$thumbnailOptions = [];

        // The package's files table, with the columns BaseFile needs (timestamps, soft deletes,
        // additional_properties) on top of the ones addFile() fills.
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('name', 127);
            $table->string('slug', 127)->unique();
            $table->string('type', 7);
            $table->string('mime_type', 63);
            $table->string('file', 255);
            $table->smallInteger('width')->unsigned()->nullable();
            $table->smallInteger('height')->unsigned()->nullable();
            $table->json('additional_properties')->nullable();
            $table->nullableMorphs('protected');
            $table->nullableMorphs('model');
            $table->string('relation_type', 63)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // The service provider is not loaded here, and addFile() needs the package's settings.
        config(['filesSettings' => require __DIR__ . '/../../src/config/filesSettings.php']);
        // The file reads FILES_SETTINGS_BLOCK_WEBP_CONVERSION; the tests convert to WebP whatever it says.
        config(['filesSettings.block_webp_conversion' => false]);
        // A 600 px size sits between the main file of the 960x1440 upload (480 wide) and the source,
        // so the thumbnail count shows which size the rebuild decides by (see
        // the_rebuild_follows_the_current_image_limits).
        config(['filesSettings.thumbnailSizes' => [
            ['width' => 64, 'height' => 64],
            ['width' => 374, 'height' => null],
            ['width' => 600, 'height' => null],
            ['width' => 1088, 'height' => null],
        ]]);

        config(['filesystems.default' => 's3']);
        Storage::fake('s3');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('files');

        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        if ($this->localDirectory !== null) {
            File::deleteDirectory($this->localDirectory);
        }

        if ($this->copyDirectory !== null) {
            File::deleteDirectory($this->copyDirectory);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_rebuild_with_a_watermark_marks_the_main_file_and_thumbnails_on_a_remote_disk(): void
    {
        $file = $this->upload(watermark: null);
        $source = $file->source()->first();
        $oldThumbnails = $file->thumbnails()->pluck('file')->all();
        $this->assertNotEmpty($oldThumbnails);

        $this->assertTrue($this->rebuild($file, $this->solidWatermark()));

        $file = RebuildableFile::find($file->id);

        // Rebuilt within images.max_width x max_height (1280x720), as uploaded, and converted.
        $this->assertSame('webp', $file->type);
        $this->assertSame([480, 720], [$file->width, $file->height]);
        $this->assertMarked($file);

        // One thumbnail per configured size smaller than the rebuilt main file: 64x64 and 374 wide,
        // not 600 or 1088.
        $this->assertCount(2, $oldThumbnails);
        $thumbnails = $file->thumbnails()->get();
        $this->assertCount(2, $thumbnails);
        foreach ($thumbnails as $thumbnail) {
            $this->assertMarked($thumbnail);
        }

        foreach ($oldThumbnails as $path) {
            Storage::assertMissing($path);
        }

        // The source is what rebuilds read from, so it has to come out as it went in.
        $this->assertSame([960, 1440], [$source->fresh()->width, $source->fresh()->height]);
        $this->assertUnmarked($source);
    }

    #[Test]
    public function a_rebuild_on_a_local_disk_rooted_at_storage_app_works_as_before(): void
    {
        // The case that already worked before 0.7.19: a local default disk rooted at
        // storage_path('app'), where the old file_exists() lookup found the source too. Everything is
        // written under a directory of its own there, removed in tearDown().
        $mainDir = 'helper-rebuild-test-' . bin2hex(random_bytes(4));
        $this->localDirectory = storage_path('app/' . $mainDir);
        config([
            'filesystems.default' => 'local',
            'filesystems.disks.local' => ['driver' => 'local', 'root' => storage_path('app'), 'throw' => false],
            'filesSettings.main_dir' => $mainDir,
        ]);
        Storage::forgetDisk('local');

        $file = $this->upload(watermark: null, onLocalDisk: true);

        $this->assertTrue($this->rebuild($file, $this->solidWatermark()));

        $file = RebuildableFile::find($file->id);
        $this->assertFileExists($file->fullStoragePatch());
        $this->assertSame([480, 720], [$file->width, $file->height]);
        $this->assertMarked($file);

        $thumbnails = $file->thumbnails()->get();
        $this->assertCount(2, $thumbnails);
        foreach ($thumbnails as $thumbnail) {
            $this->assertMarked($thumbnail);
        }
    }

    #[Test]
    public function a_rebuild_without_a_watermark_takes_it_off_on_a_remote_disk(): void
    {
        // A photographer turning the watermark off for a gallery.
        $file = $this->upload(watermark: $this->solidWatermark());
        $this->assertMarked($file);

        $this->assertTrue($this->rebuild($file, null));

        $file = RebuildableFile::find($file->id);
        $this->assertUnmarked($file);

        $thumbnails = $file->thumbnails()->get();
        $this->assertCount(2, $thumbnails);
        foreach ($thumbnails as $thumbnail) {
            $this->assertUnmarked($thumbnail);
        }
    }

    public static function orientations(): array
    {
        // Source size, then the size addFile() scales the main file to within 1280x720 - up to it for
        // the small one, which is converted to WebP while prevent_upscale is off.
        return [
            'landscape' => [1500, 1000, 1080, 720],
            'portrait' => [960, 1440, 480, 720],
            'square' => [1200, 1200, 720, 720],
            'small' => [400, 300, 960, 720],
        ];
    }

    #[Test]
    #[DataProvider('orientations')]
    public function a_rebuild_reproduces_what_the_upload_stored(
        int $width,
        int $height,
        int $storedWidth,
        int $storedHeight
    ): void {
        // Up to 0.7.19 the rebuilt main file came out at the source's full resolution, so the original
        // was served as the preview, and every thumbnail at the size of the uploaded main file.
        $file = $this->upload(watermark: null, width: $width, height: $height);
        $uploaded = $this->storedSizes($file);
        $this->assertSame([$storedWidth, $storedHeight], $uploaded['main']);
        $this->assertNotEmpty($uploaded['thumbnails']);

        $this->assertTrue($this->rebuild($file, $this->solidWatermark()));

        $rebuilt = $this->storedSizes(RebuildableFile::find($file->id));
        $this->assertSame($uploaded, $rebuilt);

        foreach ($rebuilt['thumbnails'] as [$thumbnailWidth, $thumbnailHeight]) {
            $this->assertLessThan($storedWidth, $thumbnailWidth);
            $this->assertLessThan($storedHeight, $thumbnailHeight);
        }
    }

    #[Test]
    public function the_rebuild_follows_the_current_image_limits(): void
    {
        // A project that raises the limits after its files were stored: the rebuild takes the limits
        // it runs with, and the thumbnails follow the size of the rebuilt main file, not the one it
        // replaces - 600 wide fits in the 960 wide main file, not in the uploaded one, 480 wide.
        $file = $this->upload(watermark: null);
        $this->assertCount(2, $file->thumbnails()->get());
        config(['filesSettings.images.max_height' => 1440]);

        $this->assertTrue($this->rebuild($file, null));

        $sizes = $this->storedSizes(RebuildableFile::find($file->id));
        $this->assertSame([960, 1440], $sizes['main']);
        $this->assertCount(3, $sizes['thumbnails']);
    }

    #[Test]
    public function a_small_source_keeps_its_size_when_upscaling_is_prevented(): void
    {
        // The other side of the small data set above: with prevent_upscale set, the upload keeps a
        // source smaller than the limits at its size, and so must the rebuild.
        config(['filesSettings.images.prevent_upscale' => true]);
        $file = $this->upload(watermark: null, width: 400, height: 300);
        $uploaded = $this->storedSizes($file);
        $this->assertSame([400, 300], $uploaded['main']);
        $this->assertCount(2, $uploaded['thumbnails']);

        $this->assertTrue($this->rebuild($file, $this->solidWatermark()));

        $this->assertSame($uploaded, $this->storedSizes(RebuildableFile::find($file->id)));
    }

    #[Test]
    public function the_thumbnails_are_given_the_options_passed_to_the_rebuild(): void
    {
        // As addUpload() gives them. Up to 0.7.19 they got none, so on S3 the disk's visibility,
        // whatever the caller passed for the main file. The fixture records what each thumbnail is
        // given: the fake disk is a local one, and a local file's visibility does not read back on
        // every platform.
        $file = $this->upload(watermark: null);
        RebuildableFile::$thumbnailOptions = [];

        $this->assertTrue($file->rebuildFromSource(
            location: 'uploads',
            relationName: 'files',
            options: ['visibility' => 'private']
        ));

        $this->assertSame(
            [['visibility' => 'private'], ['visibility' => 'private']],
            RebuildableFile::$thumbnailOptions
        );
    }

    #[Test]
    public function the_rebuilt_file_lists_its_new_thumbnails(): void
    {
        // The rebuild loads the thumbnails to delete them. Left loaded, the relation kept listing the
        // deleted ones on the instance it was called on, so srcset() or img() called on it cached them.
        $file = $this->upload(watermark: null);
        $old = $file->thumbnails()->pluck('id')->all();

        $this->assertTrue($this->rebuild($file, null));

        $new = $file->thumbnails()->pluck('id')->all();
        $this->assertNotEmpty($new);
        $this->assertSame([], array_intersect($old, $new));
        $this->assertSame($new, $file->thumbnails->pluck('id')->all());
    }

    #[Test]
    public function a_webp_source_larger_than_the_limit_is_resized_and_marked(): void
    {
        // A WebP source is not converted, so up to 0.7.19, when the main file was not resized either,
        // it was stored as an unmarked copy of the source, taking the mark off instead of putting it
        // on. Resizing it to the limits sends it through the watermark step.
        $file = $this->upload(watermark: null, format: 'webp');

        $this->assertTrue($this->rebuild($file, $this->solidWatermark()));

        $file = RebuildableFile::find($file->id);
        $this->assertSame([480, 720], $this->storedSizes($file)['main']);
        $this->assertMarked($file);

        $thumbnails = $file->thumbnails()->get();
        $this->assertCount(2, $thumbnails);
        foreach ($thumbnails as $thumbnail) {
            $this->assertMarked($thumbnail);
        }
    }

    #[Test]
    public function a_rebuild_without_the_conversion_of_a_source_larger_than_the_limits_throws(): void
    {
        // The known TypeError of 0.7.18: addFile() calls the encoder with null when it resizes
        // without converting, which resizing the main file now reaches with forceWebP: false. This
        // pins what CHANGELOG 0.7.20 and the docs say it leaves behind, and fails once the TypeError
        // is fixed, so they are updated with it.
        $file = $this->upload(watermark: null);
        $oldMain = $file->file;
        $oldThumbnails = $file->thumbnails()->pluck('file')->all();
        $filesBefore = Storage::allFiles();
        $level = DB::transactionLevel();

        try {
            $file->rebuildFromSource(location: 'uploads', relationName: 'files', forceWebP: false);
            $this->fail('The rebuild was expected to throw a TypeError.');
        } catch (TypeError) {
            // Expected, with the method's transaction left open.
            $this->assertSame($level + 1, DB::transactionLevel());
        } finally {
            // The method leaves its transaction open on an Error; see docs/rebuildFromSource.md.
            DB::rollBack($level);
        }

        // The record is rolled back to the old main file. The old files are gone and nothing was
        // written in their place, whatever path the new main file would have had.
        $this->assertSame($oldMain, RebuildableFile::find($file->id)->file);
        $filesAfter = Storage::allFiles();
        $this->assertSame([], array_values(array_diff($filesAfter, $filesBefore)), 'A file was written.');
        $this->assertEqualsCanonicalizing(
            array_map(fn ($path) => ltrim($path, '/'), [$oldMain, ...$oldThumbnails]),
            array_values(array_diff($filesBefore, $filesAfter))
        );
    }

    #[Test]
    public function a_missing_source_returns_false_and_keeps_the_files(): void
    {
        $file = $this->upload(watermark: $this->solidWatermark());
        $source = $file->source()->first();
        $thumbnails = $file->thumbnails()->get();

        Storage::delete($source->file);

        $this->assertFalse($this->rebuild($file, null));

        $file = RebuildableFile::find($file->id);
        Storage::assertExists($file->file);
        $this->assertMarked($file);
        $this->assertSame([480, 720], [$file->width, $file->height]);

        $this->assertSame($thumbnails->pluck('id')->all(), $file->thumbnails()->pluck('id')->all());
        foreach ($thumbnails as $thumbnail) {
            Storage::assertExists($thumbnail->file);
        }
    }

    #[Test]
    public function a_disk_error_while_checking_the_source_returns_false_and_keeps_the_files(): void
    {
        // The disk cannot tell whether the source exists, as S3 cannot when the connection drops.
        $file = $this->upload(watermark: $this->solidWatermark());
        $source = $file->source()->first();
        $thumbnails = $file->thumbnails()->get();

        $disk = Storage::disk('s3');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('exists')->once()->andThrow(UnableToCheckFileExistence::forLocation($source->file));
        Storage::set('s3', $failing);

        try {
            $this->assertFalse($this->rebuild($file, null));
        } finally {
            Storage::set('s3', $disk);
        }

        $file = RebuildableFile::find($file->id);
        Storage::assertExists($file->file);
        $this->assertMarked($file);
        $this->assertSame($thumbnails->pluck('id')->all(), $file->thumbnails()->pluck('id')->all());
        foreach ($thumbnails as $thumbnail) {
            Storage::assertExists($thumbnail->file);
        }
    }

    #[Test]
    public function an_unreadable_source_returns_false_and_keeps_the_files(): void
    {
        // The source is found on the disk, then cannot be read: the fixture removes it in between,
        // so the real copy fails. The copy is made before anything is deleted, so the stored files
        // have to survive; this fails if the copy moves after the deletion.
        $file = $this->upload(watermark: $this->solidWatermark());
        $thumbnails = $file->thumbnails()->get();
        $this->useCopyDirectory();
        RebuildableFile::$removeSourceBeforeCopy = true;

        $this->assertFalse($this->rebuild($file, null));
        $this->assertNull(RebuildableFile::$lastTemporaryCopy, 'The copy was expected to fail.');
        $this->assertCopyDirectoryEmpty();

        $file = RebuildableFile::find($file->id);
        Storage::assertExists($file->file);
        $this->assertMarked($file);
        $this->assertSame([480, 720], [$file->width, $file->height]);

        $this->assertSame($thumbnails->pluck('id')->all(), $file->thumbnails()->pluck('id')->all());
        foreach ($thumbnails as $thumbnail) {
            Storage::assertExists($thumbnail->file);
        }
    }

    #[Test]
    public function the_temporary_copy_is_removed(): void
    {
        $file = $this->upload(watermark: null);

        $this->assertTrue($this->rebuild($file, $this->solidWatermark()));

        $this->assertTemporaryCopyRemoved();
    }

    #[Test]
    public function the_temporary_copy_is_removed_when_the_rebuild_throws(): void
    {
        // The method catches only an Exception, so an Error - such as the known TypeError of 0.7.18
        // - propagates to the caller. The fixture throws one while the thumbnails are written, after
        // the main file has been rebuilt from the copy, to check the copy goes with it too.
        $file = $this->upload(watermark: null);
        RebuildableFile::$throwOnThumbnails = true;
        $level = DB::transactionLevel();

        try {
            $this->rebuild($file, null);
            $this->fail('The rebuild was expected to throw an Error.');
        } catch (Error) {
            // Expected.
        } finally {
            // The method leaves its transaction open on an Error; see docs/rebuildFromSource.md.
            DB::rollBack($level);
        }

        $this->assertTemporaryCopyRemoved();
    }

    #[Test]
    public function a_transaction_that_cannot_be_opened_leaves_the_callers_transaction_open(): void
    {
        // A caller rebuilding inside a transaction of its own, which the docs advise against but
        // which projects do, and the method's transaction cannot be opened (a lost connection, a
        // failed savepoint). The exception has to reach the caller with its transaction untouched:
        // caught by the method, its rollBack() would undo the caller's transaction instead.
        $file = $this->upload(watermark: $this->solidWatermark());
        $thumbnails = $file->thumbnails()->get();
        $level = DB::transactionLevel();

        DB::beginTransaction();
        $failing = true;
        DB::connection()->beforeStartingTransaction(function () use (&$failing) {
            if ($failing) {
                throw new RuntimeException('Thrown by the test while opening the transaction.');
            }
        });

        try {
            $this->rebuild($file, null);
            $this->fail('The rebuild was expected to throw.');
        } catch (RuntimeException $e) {
            $this->assertSame('Thrown by the test while opening the transaction.', $e->getMessage());
            $this->assertSame($level + 1, DB::transactionLevel(), "The caller's transaction was closed.");
        } finally {
            $failing = false;
            DB::rollBack($level);
        }

        $this->assertTemporaryCopyRemoved();
        $this->assertStoredFilesKept($file, $thumbnails);
    }

    #[Test]
    public function a_source_read_short_returns_false_and_keeps_the_files(): void
    {
        // The disk hands back a stream that ends early without an error. The copy must not pass for
        // the source: a PNG cut short fails to decode only after the old files have been deleted, and
        // a JPEG is rebuilt with its bottom missing.
        $file = $this->upload(watermark: $this->solidWatermark());
        $source = $file->source()->first();
        $thumbnails = $file->thumbnails()->get();
        $this->useCopyDirectory();

        $disk = Storage::disk('s3');
        $contents = $disk->get($source->file);
        $short = fopen('php://memory', 'r+');
        fwrite($short, substr($contents, 0, intdiv(strlen($contents), 2)));
        rewind($short);

        $failing = Mockery::mock($disk);
        $failing->shouldReceive('readStream')->once()->andReturn($short);
        Storage::set('s3', $failing);

        try {
            $this->assertFalse($this->rebuild($file, null));
        } finally {
            Storage::set('s3', $disk);
        }

        $this->assertCopyDirectoryEmpty();
        $this->assertStoredFilesKept($file, $thumbnails);
    }

    #[Test]
    public function a_disk_error_while_reading_the_source_returns_false_and_keeps_the_files(): void
    {
        // A disk set to throw, as a project's S3 disk usually is, reports a failed read with an
        // exception rather than null.
        $file = $this->upload(watermark: $this->solidWatermark());
        $source = $file->source()->first();
        $thumbnails = $file->thumbnails()->get();
        $this->useCopyDirectory();

        $disk = Storage::disk('s3');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('readStream')->once()->andThrow(UnableToReadFile::fromLocation($source->file));
        Storage::set('s3', $failing);

        try {
            $this->assertFalse($this->rebuild($file, null));
        } finally {
            Storage::set('s3', $disk);
        }

        $this->assertCopyDirectoryEmpty();
        $this->assertStoredFilesKept($file, $thumbnails);
    }

    #[Test]
    public function a_disk_error_while_checking_the_size_returns_false_and_keeps_the_files(): void
    {
        // The source is read whole, then the disk cannot report the size to check the copy against,
        // as S3 cannot when the connection drops between the two requests.
        $file = $this->upload(watermark: $this->solidWatermark());
        $source = $file->source()->first();
        $thumbnails = $file->thumbnails()->get();
        $this->useCopyDirectory();

        $disk = Storage::disk('s3');
        $failing = Mockery::mock($disk);
        $failing->shouldReceive('size')->once()->andThrow(UnableToRetrieveMetadata::fileSize($source->file));
        Storage::set('s3', $failing);

        try {
            $this->assertFalse($this->rebuild($file, null));
        } finally {
            Storage::set('s3', $disk);
        }

        $this->assertCopyDirectoryEmpty();
        $this->assertStoredFilesKept($file, $thumbnails);
    }

    #[Test]
    public function a_temporary_directory_that_cannot_be_used_does_not_stop_the_rebuild(): void
    {
        // tempnam() then creates the copy in the system's temporary directory and raises a notice,
        // which Laravel turns into an ErrorException; the rebuild has to go on with that copy, and
        // say where it went.
        $file = $this->upload(watermark: null);
        RebuildableFile::$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'helper-rebuild-missing-' . bin2hex(random_bytes(4));
        Log::spy();

        $this->assertTrue($this->rebuild($file, null));

        $this->assertTemporaryCopyRemoved();
        Log::shouldHaveReceived('warning')
            ->once()
            ->with(Mockery::on(fn ($message) => str_contains($message, 'Could not use the temporary directory')));
    }

    #[Test]
    public function the_default_temporary_directory_logs_no_warning(): void
    {
        // The fallback check compares the directory tempnam() used with the one asked for; with the
        // default one they have to come out equal, or every rebuild would log a false warning.
        $file = $this->upload(watermark: null);
        Log::spy();

        $this->assertTrue($this->rebuild($file, null));

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Upload an image, a 960x1440 portrait PNG unless told otherwise, the way a project does, and check
     * where it landed: off the local storage_path('app') lookup, unless the test runs on that local
     * disk on purpose.
     */
    private function upload(
        ?UploadedFile $watermark,
        bool $onLocalDisk = false,
        int $width = 960,
        int $height = 1440,
        string $format = 'png'
    ): RebuildableFile {
        $owner = RebuildOwner::create(['name' => 'owner']);

        $file = $owner->addUpload(
            uploadedFile: $this->imageFile($this->whiteImage($width, $height), 'photo.' . $format, $format),
            watermark: $watermark,
            watermarkOpacity: 100
        );

        $source = $file->source()->first();
        $this->assertNotNull($source);
        Storage::assertExists($source->file);
        if ($onLocalDisk) {
            $this->assertFileExists($source->fullStoragePatch());
        } else {
            $this->assertFileDoesNotExist($source->fullStoragePatch());
        }

        return RebuildableFile::find($file->id);
    }

    /**
     * Rebuild with the arguments a project passes when a gallery's watermark is switched on or off.
     */
    private function rebuild(RebuildableFile $file, ?UploadedFile $watermark): bool
    {
        return $file->rebuildFromSource(
            location: 'uploads',
            relationName: 'files',
            options: [],
            watermark: $watermark,
            watermarkOpacity: 100
        );
    }

    private function assertMarked(RebuildableFile $file): void
    {
        $image = $this->manager->read(Storage::get($file->file));

        foreach ($this->corners($image->width(), $image->height()) as $name => [$x, $y]) {
            $color = $image->pickColor($x, $y);

            // Stored as WebP, which is lossy: red comes back near red, white would keep green high.
            $this->assertGreaterThan(200, $color->channel(Red::class)->value(), "file {$file->id}, {$name}");
            $this->assertLessThan(60, $color->channel(Green::class)->value(), "file {$file->id}, {$name}");
        }
    }

    private function assertUnmarked(RebuildableFile $file): void
    {
        $image = $this->manager->read(Storage::get($file->file));

        foreach ($this->corners($image->width(), $image->height()) as $name => [$x, $y]) {
            // WebP is lossy, so white comes back near white rather than exact.
            $this->assertGreaterThan(240, $image->pickColor($x, $y)->channel(Green::class)->value(), "file {$file->id}, {$name}");
        }
    }

    private function assertTemporaryCopyRemoved(): void
    {
        $copy = RebuildableFile::$lastTemporaryCopy;

        $this->assertNotNull($copy, 'The rebuild did not copy the source.');
        $this->temporaryFiles[] = $copy;
        $this->assertFileDoesNotExist($copy);
    }

    /**
     * Have the rebuild copy the source to a directory of the test's own, so a failed copy that
     * stays behind shows.
     */
    private function useCopyDirectory(): void
    {
        $this->copyDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'helper-rebuild-test-' . bin2hex(random_bytes(4));
        File::makeDirectory($this->copyDirectory);
        RebuildableFile::$temporaryDirectory = $this->copyDirectory;
    }

    private function assertCopyDirectoryEmpty(): void
    {
        $this->assertSame([], File::files($this->copyDirectory), 'A failed copy was left behind.');
    }

    /**
     * The file, uploaded with a watermark, and its thumbnails, as they were before the rebuild.
     */
    private function assertStoredFilesKept(RebuildableFile $file, Collection $thumbnails): void
    {
        $file = RebuildableFile::find($file->id);
        Storage::assertExists($file->file);
        $this->assertMarked($file);
        $this->assertSame([480, 720], [$file->width, $file->height]);

        $this->assertSame($thumbnails->pluck('id')->all(), $file->thumbnails()->pluck('id')->all());
        foreach ($thumbnails as $thumbnail) {
            Storage::assertExists($thumbnail->file);
        }
    }

    /**
     * The four corners and the centre - the points an unmarked band would leave out.
     *
     * @return array<string, array{int, int}>
     */
    private function corners(int $width, int $height): array
    {
        return [
            'top-left' => [0, 0],
            'top-right' => [$width - 1, 0],
            'bottom-left' => [0, $height - 1],
            'bottom-right' => [$width - 1, $height - 1],
            'centre' => [intdiv($width, 2), intdiv($height, 2)],
        ];
    }

    private function whiteImage(int $width, int $height): ImageInterface
    {
        return $this->manager->create($width, $height)->fill('ffffff');
    }

    /**
     * A 3:2 watermark (1500x1000), opaque everywhere, so any unmarked pixel shows as white.
     */
    private function solidWatermark(): UploadedFile
    {
        return $this->imageFile($this->manager->create(1500, 1000)->fill('ff0000'), 'watermark.png');
    }

    /**
     * @param string $format png or webp
     */
    private function imageFile(ImageInterface $image, string $name, string $format = 'png'): UploadedFile
    {
        // tempnam() creates the file it names; the image goes next to it, so both are removed. The
        // prefix is not the one rebuildFromSource() uses, so a leftover from a test cannot pass for
        // one from a rebuild.
        $base = tempnam(sys_get_temp_dir(), 'helper-upload-');
        $path = $base . '.' . $format;
        array_push($this->temporaryFiles, $base, $path);
        ($format === 'webp' ? $image->toWebp(100) : $image->toPng())->save($path);

        return new UploadedFile($path, $name, 'image/' . $format, null, true);
    }

    /**
     * The size of the main file and of each thumbnail, each checked against the image on the disk.
     *
     * @return array{main: array{int, int}, thumbnails: list<array{int, int}>}
     */
    private function storedSizes(RebuildableFile $file): array
    {
        $thumbnails = $file->thumbnails()->get()->map(fn ($thumbnail) => $this->storedSize($thumbnail))->all();
        sort($thumbnails);

        return ['main' => $this->storedSize($file), 'thumbnails' => $thumbnails];
    }

    /**
     * @return array{int, int}
     */
    private function storedSize(RebuildableFile $file): array
    {
        $image = $this->manager->read(Storage::get($file->file));
        $size = [$image->width(), $image->height()];
        $this->assertSame([$file->width, $file->height], $size, "file {$file->id}: record and disk differ");

        return $size;
    }
}
