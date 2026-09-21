<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

/**
 * Carries a method named exactly like its own morph type column.
 *
 * Reading that column through getAttribute() on an instance that does not have it loaded falls
 * through to relation resolution, which calls this method.
 */
class FileWithTypeMethod extends File
{
    public static bool $typeMethodRan = false;

    public function model_type(): string
    {
        static::$typeMethodRan = true;

        return 'called';
    }
}
