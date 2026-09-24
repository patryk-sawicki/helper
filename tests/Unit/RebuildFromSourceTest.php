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
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

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
        // A 600 px size sits between the uploaded main file (480 wide) and the rebuilt one (960 wide),
        // so the thumbnail count shows the rebuild decides by the new size, not the old one.
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

        // Rebuilt at the source's full resolution, and converted.
        $this->assertSame('webp', $file->type);
        $this->assertSame([960, 1440], [$file->width, $file->height]);
        $this->assertMarked($file);

        // One thumbnail per configured size smaller than the rebuilt main file: 64x64, 374 and 600
        // wide, not 1088. The upload, 480 wide, had two.
        $this->assertCount(2, $oldThumbnails);
        $thumbnails = $file->thumbnails()->get();
        $this->assertCount(3, $thumbnails);
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
        $this->assertSame([960, 1440], [$file->width, $file->height]);
        $this->assertMarked($file);

        $thumbnails = $file->thumbnails()->get();
        $this->assertCount(3, $thumbnails);
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
        $this->assertCount(3, $thumbnails);
        foreach ($thumbnails as $thumbnail) {
            $this->assertUnmarked($thumbnail);
        }
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
     * Upload a 960x1440 portrait the way a project does, and check where it landed: off the local
     * storage_path('app') lookup, unless the test runs on that local disk on purpose.
     */
    private function upload(?UploadedFile $watermark, bool $onLocalDisk = false): RebuildableFile
    {
        $owner = RebuildOwner::create(['name' => 'owner']);

        $file = $owner->addUpload(
            uploadedFile: $this->imageFile($this->whiteImage(960, 1440), 'photo.png'),
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

    private function imageFile(ImageInterface $image, string $name): UploadedFile
    {
        // tempnam() creates the file it names; the PNG goes next to it, so both are removed. The
        // prefix is not the one rebuildFromSource() uses, so a leftover from a test cannot pass for
        // one from a rebuild.
        $base = tempnam(sys_get_temp_dir(), 'helper-upload-');
        $path = $base . '.png';
        array_push($this->temporaryFiles, $base, $path);
        $image->toPng()->save($path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
