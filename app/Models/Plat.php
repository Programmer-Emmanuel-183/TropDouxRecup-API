<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SoftDeletes;

class Plat extends Model
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
        'nom_plat',
        'description_plat',
        'image_couverture',
        'autre_image',
        'prix_origine',
        'prix_reduit',
        'quantite_plat',
        'quantite_disponible',
        'is_active',
        'id_categorie',
        'id_marchand'
    ];

    protected $casts = [
        'autre_image' => 'array',
    ];

    protected $dates = ['deleted_at'];

    public function marchand(){
        return $this->belongsTo(Marchand::class, 'id_marchand');
    }

    public function categorie(){
        return $this->belongsTo(Categorie::class, 'id_categorie');
    }
}
