<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

class UserWithSecretAccessor extends UserDeclaringNothing
{
    protected $appends = ['api_secret'];

    public function getApiSecretAttribute(): string
    {
        return 'SEKRET-' . $this->id;
    }
}
