<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Commande extends Model
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
        'statut',
    ];

    public function sousCommandes(){
        return $this->hasMany(SousCommande::class, 'id_commande', 'id');
    }

    public function client()
    {
        return $this->belongsTo(User::class, 'id_client');
    }

    public function marchand()
    {
        return $this->belongsTo(Marchand::class, 'id_marchand');
    }

}
