<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PerformerProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'stage_name', 'slug', 'bio', 'category', 'work_modes',
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

    public function scopePublicCatalog(Builder $query): Builder
    {
        return $query
            ->whereHas('user', fn (Builder $q) => $q->where('status', 'active'))
            ->where('is_verified', true)
            ->whereNotNull('slug');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class);
    }

    public static function generateSlug(string $stageName): string
    {
        $base = Str::slug($stageName);
        do {
            $slug = $base . '-' . strtolower(Str::random(4));
        } while (static::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
