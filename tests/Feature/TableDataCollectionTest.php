<?php

namespace PatrykSawicki\Helper\Tests\Feature;

use Illuminate\Http\Request;
use PatrykSawicki\Helper\Tests\Fixtures\File;
use PatrykSawicki\Helper\Tests\Fixtures\Gate;
use PatrykSawicki\Helper\Tests\Fixtures\OtherOwner;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;
use PatrykSawicki\Helper\Tests\Fixtures\User;
use PatrykSawicki\Helper\Tests\Fixtures\UserDeclaringNothing;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * getTableDataForObjects() driven the way DataTables drives it.
 *
 * This is the entry point most consumers use, and its semantics differ from the query one: it
 * works on rows rather than on a prototype, and reads through data_get() rather than through SQL.
 * The helper tests prove the gates; this one proves the entry point is wired to them.
 */
class TableDataCollectionTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new Gate();
    }

    private function request(array $column, ?int $orderColumn = null): Request
    {
        return Request::create('/data', 'POST', array_filter([
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'order' => is_null($orderColumn) ? null : [['column' => $orderColumn, 'dir' => 'asc']],
            'columns' => [$column],
        ]));
    }

    #[Test]
    public function a_hidden_column_does_not_filter_the_collection(): void
    {
        User::create(['name' => 'Anna', 'email' => 'a@example.com', 'password' => 'aaa']);
        User::create(['name' => 'Bartek', 'email' => 'b@example.com', 'password' => 'zzz']);

        [$elements, , $total, $filtered] = $this->gate->getTableDataForObjects(
            $this->request(['name' => 'password', 'searchable' => '1', 'search' => ['value' => 'aaa']]),
            User::all()
        );

        $this->assertSame(2, $total);
        // Refused, so no row matches - the collection path has always narrowed on a refusal.
        $this->assertSame(0, $filtered);
        $this->assertCount(0, $elements);
    }

    #[Test]
    public function an_ordinary_column_still_filters_the_collection(): void
    {
        User::create(['name' => 'Anna', 'email' => 'a@example.com', 'password' => 'aaa']);
        User::create(['name' => 'Bartek', 'email' => 'b@example.com', 'password' => 'zzz']);

        [$elements, , , $filtered] = $this->gate->getTableDataForObjects(
            $this->request(['name' => 'name', 'searchable' => '1', 'search' => ['value' => 'Anna']]),
            User::all()
        );

        $this->assertSame(1, $filtered);
        $this->assertSame('Anna', $elements->first()->name);
    }

    #[Test]
    public function a_mixed_morph_collection_never_loads_a_path_only_some_rows_allow(): void
    {
        // The request decides which row comes first, and load() applies one set of paths to all
        // of them. OtherOwner::files() is not a relation, so resolving it would call it.
        $owner = Owner::create(['name' => 'Owner']);
        $other = OtherOwner::create(['name' => 'Other']);
        File::create(['name' => 'a.txt', 'model_type' => Owner::class, 'model_id' => $owner->id]);
        File::create(['name' => 'b.txt', 'model_type' => OtherOwner::class, 'model_id' => $other->id]);

        $request = Request::create('/data', 'POST', [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'columns' => [['name' => 'model.files', 'searchable' => '0', 'search' => ['value' => '']]],
        ]);

        [$elements] = $this->gate->getTableDataForObjects($request, File::orderBy('id')->get());

        $this->assertCount(2, $elements);
        $this->assertFalse(OtherOwner::$filesRan);
    }

    #[Test]
    public function sorting_the_collection_by_a_hidden_column_orders_nothing_and_calls_nothing(): void
    {
        User::create(['name' => 'Anna', 'email' => 'a@example.com', 'password' => 'zzz']);
        User::create(['name' => 'Bartek', 'email' => 'b@example.com', 'password' => 'aaa']);

        [$elements] = $this->gate->getTableDataForObjects(
            $this->request(['name' => 'password', 'searchable' => '0', 'search' => ['value' => '']], 0),
            User::all()
        );

        // Every sort key comes back null, so the rows keep the order they arrived in - they are
        // NOT ordered by the hash.
        $this->assertSame(['Anna', 'Bartek'], $elements->pluck('name')->all());
    }

    #[Test]
    public function the_global_search_cannot_read_a_secret_out_of_the_serialised_row(): void
    {
        // The global search matches a substring of the whole serialised row, and toArray() of a
        // model with no $hidden carries the password hash. Measured before the fix: 'bcrypt$X'
        // matched, 'bcrypt$XY' matched, 'bcrypt$QQ' did not - the hash, one character at a time.
        UserDeclaringNothing::create(['name' => 'Anna', 'email' => 'a@example.com', 'password' => 'bcrypt$SECRET']);
        UserDeclaringNothing::create(['name' => 'Bartek', 'email' => 'b@example.com', 'password' => 'other']);

        foreach (['bcrypt$S', 'bcrypt$SE', 'bcrypt$QQ'] as $needle) {
            $request = Request::create('/data', 'POST', [
                'draw' => 1, 'start' => 0, 'length' => 25,
                'search' => ['value' => $needle], 'columns' => [],
            ]);

            [, , , $filtered] = $this->gate->getTableDataForObjects($request, UserDeclaringNothing::all());

            $this->assertSame(0, $filtered, "the hash must not be searchable through '{$needle}'");
        }
    }

    #[Test]
    public function the_global_search_still_matches_an_ordinary_value(): void
    {
        UserDeclaringNothing::create(['name' => 'Anna', 'email' => 'a@example.com', 'password' => 'x']);
        UserDeclaringNothing::create(['name' => 'Bartek', 'email' => 'b@example.com', 'password' => 'x']);

        $request = Request::create('/data', 'POST', [
            'draw' => 1, 'start' => 0, 'length' => 25,
            'search' => ['value' => 'Anna'], 'columns' => [],
        ]);

        [, , , $filtered] = $this->gate->getTableDataForObjects($request, UserDeclaringNothing::all());

        $this->assertSame(1, $filtered);
    }

    #[Test]
    public function a_dotted_column_filter_runs_the_gate_per_row(): void
    {
        // filterTableDataForObjects() recurses through the relation per row, which is the only
        // place the morph gate acts on the FILTERING path - the load() test covers the other one.
        $owner = Owner::create(['name' => 'Szukany']);
        $other = OtherOwner::create(['name' => 'Inny']);
        File::create(['name' => 'a.txt', 'model_type' => Owner::class, 'model_id' => $owner->id]);
        File::create(['name' => 'b.txt', 'model_type' => OtherOwner::class, 'model_id' => $other->id]);

        OtherOwner::$filesRan = false;

        [, , , $filtered] = $this->gate->getTableDataForObjects(
            $this->request(['name' => 'model.name', 'searchable' => '1', 'search' => ['value' => 'Szukany']]),
            File::with('model')->orderBy('id')->get()
        );

        $this->assertSame(1, $filtered);
        $this->assertFalse(OtherOwner::$filesRan);
    }
}
