<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * A subclass moving the reporting limit - the only supported way to move it.
 */
class GateWithHigherLimit extends Gate
{
    protected const MAX_REJECTION_REPORTS = 30;
}
