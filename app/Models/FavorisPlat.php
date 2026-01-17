<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FavorisPlat extends Model
{
    public $incrementing = false; // empêche l'auto-incrémentation
    protected $keyType = 'string'; // la clé primaire sera une string

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->getKey()) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }


    protected $fillable = [
        'id_plat',
        'id_client'
    ];

    public function plat(){
        return $this->belongsTo(Plat::class, 'id_plat');
    }

    public function client(){
        return $this->belongsTo(User::class, 'id_client');
    }

}
