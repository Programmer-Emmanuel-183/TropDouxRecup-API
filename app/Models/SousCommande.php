<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SousCommande extends Model
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
        'commission',
        'id_commande',
        'id_client',
        'id_plat',
        'quantite_plat',
        'id_marchand',
        'statut',
        'code_commande',
        'code_qr'
    ];

    protected $casts = [
        'date_de_recuperation' => 'datetime',
    ];


    public function plat(){
        return $this->belongsTo(Plat::class, 'id_plat');
    }

    public function marchand(){
        return $this->belongsTo(Marchand::class, 'id_marchand');
    }

    public function client(){
        return $this->belongsTo(User::class, 'id_client');
    }

    public function commande(){
        return $this->belongsTo(Commande::class, 'id_commande');
    }
}
