<?php

namespace PatrykSawicki\Helper\Tests\Unit;

use PatrykSawicki\Helper\Tests\Fixtures\Gate;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;
use PatrykSawicki\Helper\Tests\Fixtures\User;
use PatrykSawicki\Helper\Tests\Fixtures\UserDeclaringNothing;
use PatrykSawicki\Helper\Tests\Fixtures\UserHidingNote;
use PatrykSawicki\Helper\Tests\Fixtures\UserSearchingPassword;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithColumnMethod;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithSecretAccessor;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithEncryptedCast;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithHashedCast;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithNullSearchable;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithSearchableColumns;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithUninitializedSearchable;
use PatrykSawicki\Helper\Tests\Fixtures\UserWithVisible;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which column names a request may address.
 *
 * Without this list, a client free to name any column of the table turns the number of surviving
 * rows into an oracle over that column - password included. Several of the cases below are ways
 * the list was reopened after it was first written, each found by review rather than by the suite.
 */
class ColumnGateTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new Gate();
    }

    private function user(string $class = User::class, string $email = 'a@example.com')
    {
        return $class::create([
            'name' => 'Test',
            'email' => $email,
            'password' => 'hash',
            'remember_token' => 'token',
            'private_note' => 'note',
        ]);
    }

    #[Test]
    public function hidden_columns_are_not_searchable(): void
    {
        $columns = $this->gate->call('searchableColumns', new User());

        $this->assertNotContains('password', $columns);
        $this->assertNotContains('remember_token', $columns);
    }

    #[Test]
    public function ordinary_columns_stay_searchable(): void
    {
        $columns = $this->gate->call('searchableColumns', new User());

        $this->assertContains('id', $columns);
        $this->assertContains('name', $columns);
        $this->assertContains('email', $columns);
        $this->assertContains('private_note', $columns);
    }

    #[Test]
    public function a_hashed_cast_keeps_a_column_out_even_when_nothing_is_hidden(): void
    {
        $columns = $this->gate->call('searchableColumns', new UserWithHashedCast());

        $this->assertNotContains('private_note', $columns);
        $this->assertContains('email', $columns);
    }

    #[Test]
    public function a_declared_list_replaces_the_derived_one(): void
    {
        $columns = $this->gate->call('searchableColumns', new UserWithSearchableColumns());

        $this->assertSame(['name'], $columns);
        $this->assertFalse($this->gate->call('isSearchableColumn', new UserWithSearchableColumns(), 'email'));
    }

    #[Test]
    public function a_name_that_is_no_column_of_the_table_is_refused(): void
    {
        $this->assertFalse($this->gate->call('isSearchableColumn', new User(), 'not_a_column'));
        $this->assertFalse($this->gate->call('isSearchableColumn', new User(), ''));
    }

    #[Test]
    public function the_collection_path_refuses_a_hidden_attribute_it_really_carries(): void
    {
        $user = $this->user();

        $this->assertArrayHasKey('password', $user->getAttributes());
        $this->assertFalse($this->gate->call('isReadableColumnName', $user, 'password'));
        $this->assertFalse($this->gate->call('isReadableColumnName', $user, 'remember_token'));
        $this->assertTrue($this->gate->call('isReadableColumnName', $user, 'email'));
    }

    #[Test]
    public function the_collection_path_keeps_an_attribute_the_query_produced(): void
    {
        // No such column on the table - the derived list cannot see it, but the row carries it.
        $user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'hash']);
        $user->setAttribute('files_count', 3);

        $this->assertTrue($this->gate->call('isReadableColumnName', $user, 'files_count'));
    }

    #[Test]
    public function make_visible_on_one_row_does_not_open_the_gate_for_another(): void
    {
        // $hidden belongs to the instance. A list memoised per CLASS would let this row decide
        // for the next one, which is the gate opening on the column it exists to close.
        // On a column the always-blocked floor does not cover, so the floor is not what answers.
        $widened = $this->user(UserHidingNote::class, 'a@example.com');
        $widened->makeVisible('private_note');
        $ordinary = $this->user(UserHidingNote::class, 'b@example.com');

        // Ask about the widened row FIRST, through both paths: the collection one reads the
        // blocked list directly, the query one memoises a derived list. Either could carry the
        // answer over to the next row if it were keyed by the class alone.
        $this->assertTrue($this->gate->call('isReadableColumnName', $widened, 'private_note'));
        $this->gate->call('isSearchableColumn', $widened, 'private_note');

        $this->assertFalse($this->gate->call('isReadableColumnName', $ordinary, 'private_note'));
        $this->assertFalse($this->gate->call('isSearchableColumn', $ordinary, 'private_note'));
    }

    #[Test]
    public function a_visible_allow_list_blocks_everything_outside_it(): void
    {
        $user = $this->user(UserWithVisible::class);

        $this->assertSame([], $user->getHidden());
        $this->assertFalse($this->gate->call('isReadableColumnName', $user, 'password'));
        $this->assertFalse($this->gate->call('isReadableColumnName', $user, 'private_note'));
        $this->assertTrue($this->gate->call('isReadableColumnName', $user, 'email'));
    }

    #[Test]
    public function an_encrypting_cast_given_as_a_class_string_is_recognised(): void
    {
        $columns = $this->gate->call('searchableColumns', new UserWithEncryptedCast());

        $this->assertNotContains('private_note', $columns);
        $this->assertContains('email', $columns);
    }

    #[Test]
    public function a_declared_but_null_list_falls_back_to_the_derived_one(): void
    {
        // Declaring the property without choosing is not a choice to allow nothing.
        $columns = $this->gate->call('searchableColumns', new UserWithNullSearchable());

        $this->assertContains('email', $columns);
        $this->assertNotContains('password', $columns);
    }

    #[Test]
    public function an_uninitialized_typed_property_does_not_kill_the_request(): void
    {
        // ReflectionProperty::getValue() throws Error here, which catch (ReflectionException)
        // does not catch - a 500 on every table request for such a model.
        $model = new UserWithUninitializedSearchable();

        $this->assertNull($this->gate->call('declaredSearchableColumns', $model));

        $columns = $this->gate->call('searchableColumns', $model);

        $this->assertContains('email', $columns);
        $this->assertNotContains('password', $columns);
    }

    #[Test]
    public function a_visible_allow_list_also_blocks_on_the_query_path(): void
    {
        // The branch that reads $visible used to take its complement from the instance's
        // attributes, and every caller on the query path hands in a PROTOTYPE with none - so the
        // allow-list did nothing exactly where this release exists to act.
        $fresh = new UserWithVisible();

        $this->assertSame([], $fresh->getAttributes());
        $this->assertNotContains('password', $this->gate->call('searchableColumns', $fresh));
        $this->assertFalse($this->gate->call('isSearchableColumn', $fresh, 'password'));

        $query = UserWithVisible::query();
        $this->gate->filterQuery($query, 'password', 'a');
        $this->assertStringNotContainsString('password', $query->toSql());

        $sorted = UserWithVisible::query();
        $this->gate->call('applySortingToQuery', $sorted, 'password', 'asc');
        $this->assertStringNotContainsString('order by', $sorted->toSql());
    }

    #[Test]
    public function a_hidden_column_reached_through_a_relation_is_refused(): void
    {
        // The leaf of a dotted column is validated against a prototype of the RELATED model,
        // which carries no attributes - so gating the block on attribute presence made "not
        // loaded here" read as "not a column", and user.password sorted rows by the hash.
        $user = $this->user();
        $owner = Owner::create(['name' => 'Owner', 'user_id' => $user->id]);

        $this->assertTrue($this->gate->call('isReadableColumnPath', $owner, 'user.name'));
        $this->assertFalse($this->gate->call('isReadableColumnPath', $owner, 'user.password'));
        $this->assertFalse($this->gate->call('isReadableColumnPath', $owner, 'user.remember_token'));
    }

    #[Test]
    public function a_column_the_row_does_not_carry_is_still_judged_by_the_schema(): void
    {
        $prototype = new User();

        $this->assertSame([], $prototype->getAttributes());
        $this->assertFalse($this->gate->call('isReadableColumnName', $prototype, 'password'));
        $this->assertTrue($this->gate->call('isReadableColumnName', $prototype, 'email'));
    }

    #[Test]
    public function a_name_still_carrying_a_dot_is_refused(): void
    {
        // Every caller splits the path first, so a dotted name here is a caller that forgot to -
        // and `<table>.password` is neither an attribute nor a relation, so the fall-through
        // would have let it pass.
        $user = $this->user();

        $this->assertFalse($this->gate->call('isReadableColumnName', $user, 'helper_users.password'));
        $this->assertFalse($this->gate->call('isReadableColumnName', new User(), 'helper_users.password'));
    }

    #[Test]
    public function a_column_that_is_also_a_method_name_is_refused_when_the_row_lacks_it(): void
    {
        // Judging such a name by the schema alone skipped the relation gate: an instance without
        // the attribute sends the read down getRelationValue(), which CALLS the method. Measured
        // before this guard: verdict true, body ran, LogicException after it.
        UserWithColumnMethod::$nameMethodRan = false;
        $prototype = new UserWithColumnMethod();

        $this->assertSame([], $prototype->getAttributes());
        $this->assertFalse($this->gate->call('isReadableColumnName', $prototype, 'name'));
        $this->assertFalse(UserWithColumnMethod::$nameMethodRan);
    }

    #[Test]
    public function the_same_column_is_readable_once_the_row_carries_it(): void
    {
        // With the attribute present, Eloquent answers from the attribute array and calls
        // nothing - so the column stays usable, which is the point of not refusing it outright.
        UserWithColumnMethod::$nameMethodRan = false;
        $row = $this->user(UserWithColumnMethod::class, 'method@example.com');

        $this->assertTrue($this->gate->call('isReadableColumnName', $row, 'name'));
        $this->assertFalse(UserWithColumnMethod::$nameMethodRan);
    }

    #[Test]
    public function the_floor_holds_even_when_the_model_declares_nothing(): void
    {
        // The list is derived from $hidden, $visible and casts. A model declaring none of them
        // gets every column of its table - and such models exist, with a password among the rest.
        $bare = new UserDeclaringNothing();

        $this->assertSame([], $bare->getHidden());
        $this->assertSame([], $bare->getVisible());
        $this->assertFalse($this->gate->call('isSearchableColumn', $bare, 'password'));
        $this->assertFalse($this->gate->call('isSearchableColumn', $bare, 'remember_token'));
        $this->assertTrue($this->gate->call('isSearchableColumn', $bare, 'email'));

        $query = UserDeclaringNothing::query();
        $this->gate->filterQuery($query, 'password', 'a');
        $this->assertStringNotContainsString('password', $query->toSql());
    }

    #[Test]
    public function the_floor_holds_over_an_explicit_list_too(): void
    {
        // Otherwise "always blocked" would mean "unless someone writes it down".
        $model = new UserSearchingPassword();

        $this->assertNotContains('password', $this->gate->call('searchableColumns', $model));
        $this->assertFalse($this->gate->call('isSearchableColumn', $model, 'password'));
        $this->assertTrue($this->gate->call('isSearchableColumn', $model, 'name'));
    }

    #[Test]
    public function the_floor_also_catches_names_by_suffix(): void
    {
        $bare = new UserDeclaringNothing();

        foreach (['api_token', 'reset_secret', 'old_password'] as $name) {
            $this->assertTrue(
                $this->gate->call('isAlwaysBlockedColumn', $name),
                "{$name} should be caught by the floor"
            );
        }

        // Caught at the start of the name, not only at the end.
        foreach (['secret_note', 'secret_key'] as $name) {
            $this->assertTrue($this->gate->call('isAlwaysBlockedColumn', $name), "{$name} should be caught by the floor");
        }

        // And the two suffixes deliberately left out, because they are identifiers far more
        // often than secrets - see ALWAYS_BLOCKED_SUFFIXES.
        foreach (['short_hash', 'idempotency_key', 'private_note', 'email'] as $name) {
            $this->assertFalse($this->gate->call('isAlwaysBlockedColumn', $name), "{$name} must stay searchable");
        }
    }

    #[Test]
    public function the_floor_holds_on_a_name_that_is_no_column_of_the_table(): void
    {
        // Applied inside the attribute case, the floor covered one branch of four: an accessor
        // is no column, so it walked past into the fall-through and became searchable.
        $row = UserWithSecretAccessor::create([
            'name' => 'Test', 'email' => 'accessor@example.com', 'password' => 'hash',
        ]);

        $this->assertTrue($this->gate->call('isAlwaysBlockedColumn', 'api_secret'));
        $this->assertNotEmpty($row->api_secret);
        $this->assertFalse($this->gate->call('isReadableColumnName', $row, 'api_secret'));
    }
}
