<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Personnel extends Model
{
    use HasFactory;

    protected $table = 'personnel';

    protected $fillable = [
        'customize_id',
        'person_uuid',
        'name',
        'person_type',
        'gender',
        'id_card',
        'tel_num',
        'address',
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
                $maxId = static::max('customize_id') ?? 100;
                $model->customize_id = $maxId + 1;
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
}
