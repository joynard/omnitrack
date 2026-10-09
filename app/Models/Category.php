<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'color',
        'order_index',
    ];

    protected function casts(): array
    {
        return [
            'order_index' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Keep the slug in step with the name so agents can reference a
        // category by either identifier.
        static::saving(function (self $category): void {
            if (blank($category->slug) && filled($category->name)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
