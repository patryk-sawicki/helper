<?php

namespace PatrykSawicki\Helper\Tests\Unit;

use Illuminate\Support\Facades\Log;
use PatrykSawicki\Helper\Tests\Fixtures\Gate;
use PatrykSawicki\Helper\Tests\Fixtures\GateWithHigherLimit;
use PatrykSawicki\Helper\Tests\Fixtures\Owner;
use PatrykSawicki\Helper\Tests\Fixtures\User;
use PatrykSawicki\Helper\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The trace a refusal leaves.
 *
 * A gate that goes quiet is indistinguishable from missing data, so every refusal is logged - but
 * both the name AND the number of names come from the request, so both are amplifiers.
 */
class RejectionLogTest extends TestCase
{
    private Gate $gate;

    /** @var array<int, array<string, mixed>> */
    private array $records = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = new Gate();
        $this->records = [];

        Log::listen(function ($message) {
            $this->records[] = $message->context;
        });
    }

    #[Test]
    public function a_newline_in_the_column_name_cannot_forge_a_second_record(): void
    {
        // Laravel's LineFormatter runs with allowInlineLineBreaks, which turns an escaped \n in
        // the JSON context back into a real newline - so the name wrote a physical line of its
        // own that reads like a log record.
        $query = User::query();
        $this->gate->filterQuery($query, "password\nproduction.EMERGENCY: forged", 'a');

        $this->assertNotEmpty($this->records);
        $this->assertStringNotContainsString("\n", $this->records[0]['column']);
        $this->assertStringContainsString('forged', $this->records[0]['column']);
    }

    #[Test]
    public function a_very_long_column_name_is_truncated(): void
    {
        $query = User::query();
        $this->gate->filterQuery($query, str_repeat('a', 8000), 'x');

        $this->assertNotEmpty($this->records);
        $this->assertLessThan(400, mb_strlen($this->records[0]['column']));
    }

    #[Test]
    public function one_request_cannot_write_an_unbounded_number_of_records(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $query = User::query();
            $this->gate->filterQuery($query, 'not_a_column_' . $i, 'x');
        }

        // 20 refusals plus one record saying the rest were dropped.
        $this->assertCount(21, $this->records);
        $this->assertSame(20, $this->records[20]['reported']);
    }

    #[Test]
    public function an_ordinary_refusal_still_names_the_column(): void
    {
        $query = User::query();
        $this->gate->filterQuery($query, 'password', 'a');

        $this->assertSame('password', $this->records[0]['column']);
        $this->assertSame(User::class, $this->records[0]['model']);
    }

    #[Test]
    public function line_separators_outside_ascii_are_stripped_too(): void
    {
        // U+2028 and U+0085 are line breaks to a good part of the log tooling.
        $query = User::query();
        $this->gate->filterQuery($query, "password\u{2028}forged\u{0085}again", 'a');

        $this->assertStringNotContainsString("\u{2028}", $this->records[0]['column']);
        $this->assertStringNotContainsString("\u{0085}", $this->records[0]['column']);
        $this->assertStringContainsString('forged', $this->records[0]['column']);
    }

    #[Test]
    public function a_malformed_byte_does_not_erase_the_name_from_the_record(): void
    {
        // preg_replace with /u returns null on malformed input, and turning that into '' would
        // let one bad byte delete the trace on exactly the input that most deserves one.
        $query = User::query();
        $this->gate->filterQuery($query, "pass\x85word_bogus", 'a');

        $this->assertNotEmpty($this->records);
        $this->assertNotSame('', $this->records[0]['column']);
        $this->assertStringContainsString('word_bogus', $this->records[0]['column']);
    }

    #[Test]
    public function a_subclass_may_move_the_limit(): void
    {
        // The CHANGELOG says a subclass can redefine the constants and that the trait honours it.
        // That only holds while the trait reads them through static:: - with self:: the
        // redefinition is ignored in silence, which is worse than not offering the knob.
        $gate = new GateWithHigherLimit();

        for ($i = 0; $i < 40; $i++) {
            $query = User::query();
            $gate->filterQuery($query, 'not_a_column_' . $i, 'x');
        }

        $this->assertCount(31, $this->records);
    }

    #[Test]
    public function the_record_names_which_gate_refused(): void
    {
        // Most refusals after 0.7.17 do not come from the relation allow-list, and a trace
        // pointing at the wrong gate sends whoever reads it the wrong way.
        $query = User::query();
        $this->gate->filterQuery($query, 'password', 'a');
        $this->assertSame('column', $this->records[0]['reason']);

        $this->records = [];
        $query = Owner::query();
        $this->gate->filterQuery($query, 'dangerousMethod.name', 'a');
        $this->assertSame('relation', $this->records[0]['reason']);

        $this->records = [];
        $query = Owner::query();
        $this->gate->call('applySortingToQuery', $query, 'files.name', 'asc');
        $this->assertSame('to-many-sort', $this->records[0]['reason']);

        // A dotted column on the collection path could have been refused by either half, so the
        // trace says `path` rather than naming a gate it did not check.
        $this->records = [];
        $row = User::create(['name' => 'T', 'email' => 'p@example.com', 'password' => 'h']);
        $this->gate->call('isReadableColumnPath', $row, 'nothingHere.name');
        $this->gate->call('reportRejectedColumn', $row, 'nothingHere.name', 'path');
        $this->assertSame('path', $this->records[0]['reason']);
    }
}
