<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Personnel extends Model
{
    use HasFactory;

    protected $table = 'personnel';

    protected $appends = [
        'photo_url',
    ];

    protected $hidden = [
        'photo_base64',
    ];

    protected $fillable = [
        'customize_id',
        'person_uuid',
        'name',
        'person_type',
        'gender',
        'id_card',
        'tel_num',
        'address',
        'native',
        'notes',
        'mj_card_no',
        'mj_card_from',
        'birthday',
        'temp_valid',
        'valid_begin',
        'valid_end',
        'effect_number',
        'photo_path',
        'photo_base64',
    ];

    protected $casts = [
        'customize_id' => 'integer',
        'person_type' => 'integer',
        'gender' => 'integer',
        'mj_card_from' => 'integer',
        'temp_valid' => 'integer',
        'effect_number' => 'integer',
        'birthday' => 'date:Y-m-d',
        'valid_begin' => 'datetime',
        'valid_end' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->person_uuid)) {
                $model->person_uuid = (string) Str::uuid();
            }
            if (empty($model->customize_id)) {
                if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
                    $model->customize_id = (int) \Illuminate\Support\Facades\DB::scalar("SELECT nextval('personnel_customize_id_seq')");
                } else {
                    $maxId = static::max('customize_id') ?? 999;
                    $model->customize_id = max($maxId + 1, 1000);
                }
            }
        });
    }

    public function syncTasks(): HasMany
    {
        return $this->hasMany(SyncTask::class, 'personnel_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class, 'customize_id', 'customize_id');
    }

    public function employee(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function accessGroups(): BelongsToMany
    {
        return $this->belongsToMany(AccessGroup::class, 'access_group_personnel')
            ->withPivot('schedule_rule_id');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (!empty($this->photo_path)) {
            return asset('storage/' . ltrim($this->photo_path, '/'));
        }
        if (!empty($this->photo_base64)) {
            if (str_starts_with($this->photo_base64, 'data:image')) {
                return $this->photo_base64;
            }
            return 'data:image/jpeg;base64,' . $this->photo_base64;
        }
        return null;
    }
}
