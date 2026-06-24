<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PerformerProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'stage_name', 'bio', 'category', 'work_modes',
        'level', 'split_pct', 'rate_public', 'rate_private', 'rate_camera',
        'is_live', 'is_verified', 'avatar_path', 'cover_path',
    ];

    protected function casts(): array
    {
        return [
            'work_modes' => 'array',
            'is_live' => 'boolean',
            'is_verified' => 'boolean',
            'rating_avg' => 'decimal:2',
            'split_pct' => 'integer',
            'rate_public' => 'integer',
            'rate_private' => 'integer',
            'rate_camera' => 'integer',
            'rating_count' => 'integer',
            'followers_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
