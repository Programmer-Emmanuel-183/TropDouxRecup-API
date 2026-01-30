<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaiementAbonnement extends Model
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
        'data',
        'prix',
        'id_marchand',
        'id_abonnement',
        'statut'
    ];

    protected $casts = [
        'data' => 'array'
    ];




    public function marchand(){
        return $this->belongsTo(Marchand::class, 'id_marchand');
    }

    public function abonnement(){
        return $this->belongsTo(Abonnement::class, 'id_abonnement');
    }
}
