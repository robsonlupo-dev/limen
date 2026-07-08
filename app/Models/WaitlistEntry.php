<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaitlistEntry extends Model
{
    protected $fillable = ['name', 'email', 'role', 'world', 'source', 'age_confirmed'];

    protected $casts = ['age_confirmed' => 'boolean'];
}
