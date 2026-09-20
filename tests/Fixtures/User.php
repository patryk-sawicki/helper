<?php

namespace PatrykSawicki\Helper\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'helper_users';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];
}
