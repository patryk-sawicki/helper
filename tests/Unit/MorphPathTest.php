<?php

namespace PatrykSawicki\Helper\Tests\Unit;

use PatrykSawicki\Helper\Tests\Fixtures\File;
use PatrykSawicki\Helper\Tests\Fixtures\FileWithTypeMethod;
use PatrykSawicki\Helper\Tests\Fixtures\Gate;
use PatrykSawicki\Helper\Tests\Fixtures\OtherOwner;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;
use PatrykSawicki\Helper\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

/**
 * The morph gate added in 0.7.17.
 *
 * getRelated() on a MorphTo answers with the parent model while the row's type column is empty, so
 * anything validated past such a segment was checked against a class that never holds it.
 */
class MorphPathTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new Gate();
    }

    private function fileOwnedByOwner(): File
    {
        $owner = Owner::create(['name' => 'Owner']);

        File::create(['name' => 'file.txt', 'model_type' => Owner::class, 'model_id' => $owner->id]);

        return File::with('model')->firstOrFail();
    }

    #[Test]
    public function a_column_on_the_real_morph_target_is_readable(): void
    {
        $file = $this->fileOwnedByOwner();

        $this->assertInstanceOf(Owner::class, $file->model);
        $this->assertTrue($this->gate->call('isReadableColumnPath', $file, 'model.name'));
        $this->assertTrue($this->gate->call('isReadableColumnPath', $file, 'model.id'));
    }

    #[Test]
    public function a_non_relation_method_of_the_real_target_is_refused(): void
    {
        $file = $this->fileOwnedByOwner();

        $this->assertFalse($this->gate->call('isReadableColumnPath', $file, 'model.dangerousMethod'));
        $this->assertFalse(Owner::$dangerousMethodRan);
    }

    #[Test]
    public function reading_that_path_directly_would_have_run_the_method(): void
    {
        // Not what the package does - this is the behaviour the gate exists to keep it away from.
        $file = $this->fileOwnedByOwner();

        try {
            data_get($file, 'model.dangerousMethod');
        } catch (LogicException) {
            // Eloquent complains only after calling it.
        }

        $this->assertTrue(Owner::$dangerousMethodRan);
    }

    #[Test]
    public function a_trailing_morph_stays_eager_loadable(): void
    {
        $this->assertTrue($this->gate->call('isAllowedRelationPath', new Owner(), 'files.model'));
        $this->assertSame(['files.model'], $this->gate->call('filterRelationPaths', new Owner(), ['files.model']));
    }

    #[Test]
    public function a_segment_past_an_unresolvable_morph_is_refused(): void
    {
        $this->assertFalse($this->gate->call('isAllowedRelationPath', new Owner(), 'files.model.files'));
        $this->assertFalse($this->gate->call('isReadableColumnPath', new Owner(), 'files.model.name'));
    }

    #[Test]
    public function a_morph_without_a_type_is_refused_as_a_column_prefix(): void
    {
        $file = File::create(['name' => 'orphan.txt']);

        $this->assertNull($file->model_type);
        $this->assertFalse($this->gate->call('isReadableColumnPath', $file, 'model.name'));
    }

    #[Test]
    public function a_verdict_from_one_row_does_not_answer_for_another(): void
    {
        [$pointingAtOwner, $pointingAtOther] = $this->twoFilesWithDifferentTargets();

        // Owner::files() is a relation; OtherOwner::files() returns a string. Asking in this
        // order used to store "true" under the File class and hand it to the second row.
        $this->assertTrue($this->gate->call('isAllowedRelationPath', $pointingAtOwner, 'model.files'));
        $this->assertFalse($this->gate->call('isAllowedRelationPath', $pointingAtOther, 'model.files'));
        $this->assertFalse(OtherOwner::$filesRan);
    }

    #[Test]
    public function the_same_holds_in_the_other_direction(): void
    {
        // A "false" learned from one row must not close a path the next row really has.
        [$pointingAtOwner, $pointingAtOther] = $this->twoFilesWithDifferentTargets();

        $this->assertFalse($this->gate->call('isAllowedRelationPath', $pointingAtOther, 'model.files'));
        $this->assertTrue($this->gate->call('isAllowedRelationPath', $pointingAtOwner, 'model.files'));
    }

    #[Test]
    public function a_path_only_some_rows_allow_is_dropped_before_load(): void
    {
        // load() takes one set of paths for the whole collection, and the request decides which
        // row comes first. Only what every row allows may survive.
        [$pointingAtOwner, $pointingAtOther] = $this->twoFilesWithDifferentTargets();
        $collection = File::orderBy('id')->get();

        $this->assertTrue($this->gate->call('isAllowedRelationPath', $pointingAtOwner, 'model.files'));

        $this->assertSame(
            [],
            $this->gate->call('filterRelationPathsForEveryRow', $collection, ['model.files'])
        );
        $this->assertFalse(OtherOwner::$filesRan);
    }

    #[Test]
    public function a_path_every_row_allows_survives(): void
    {
        $this->twoFilesWithDifferentTargets();
        $collection = File::orderBy('id')->get();

        $this->assertSame(
            ['model'],
            $this->gate->call('filterRelationPathsForEveryRow', $collection, ['model'])
        );
    }

    #[Test]
    public function related_model_for_path_refuses_a_segment_nobody_validated(): void
    {
        // Published protected surface that CALLS the method it is handed a name for.
        $this->assertNull($this->gate->call('relatedModelForPath', new Owner(), 'dangerousMethod'));
        $this->assertFalse(Owner::$dangerousMethodRan);
        $this->assertNull($this->gate->call('relatedModelForPath', new Owner(), 'nothingHere'));
    }

    private function twoFilesWithDifferentTargets(): array
    {
        $owner = Owner::create(['name' => 'Owner']);
        $other = OtherOwner::create(['name' => 'Other']);

        File::create(['name' => 'a.txt', 'model_type' => Owner::class, 'model_id' => $owner->id]);
        File::create(['name' => 'b.txt', 'model_type' => OtherOwner::class, 'model_id' => $other->id]);

        $rows = File::orderBy('id')->get();

        return [$rows[0], $rows[1]];
    }

    #[Test]
    public function reading_the_type_column_never_calls_a_method_named_after_it(): void
    {
        // getAttribute() falls through to relation resolution for a name it does not find among
        // the attributes, and that CALLS a method carrying that name. Asking it for the morph
        // type column made the gate invoke something it had not proven - and a model with a
        // method named like its own type column answered every table request with a 500.
        FileWithTypeMethod::$typeMethodRan = false;
        $prototype = new FileWithTypeMethod();

        $this->assertSame([], $prototype->getAttributes());
        $this->assertTrue($this->gate->call('isMorphTargetUnknown', $prototype, $prototype->model()));
        $this->assertFalse(FileWithTypeMethod::$typeMethodRan);
    }
}
