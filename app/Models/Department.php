<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'parent_id',
        'head_id',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id')->with('children');
    }

    /**
     * Recursively retrieve all descendant IDs (sub-departments).
     * Prevents circular parent-child loops during updates.
     */
    public function getDescendantIds(): array
    {
        $ids = [];
        $children = Department::where('parent_id', $this->id)->get();

        foreach ($children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getDescendantIds());
        }

        return $ids;
    }

    /**
     * Retrieve ancestral chain up to the root department.
     */
    public function getAncestors(): Collection
    {
        $ancestors = new Collection;
        $curr = $this->parent;

        while ($curr) {
            $ancestors->push($curr);
            $curr = $curr->parent;
        }

        return $ancestors;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
