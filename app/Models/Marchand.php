<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Marchand extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;


    protected $fillable = [
        'nom_marchand',
        'email_marchand',
        'tel_marchand',
        'password_marchand',
        'code_otp',
        'otp_expire_at',
        'is_verify',
        'is_activate',
        'image_marchand',
        'id_abonnement',
        'date_abonnement',
        'id_commune',
        'password_reset_token'
    ];


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

    public function commune(){
        return $this->belongsTo(Commune::class, 'id_commune');
    }

    public function abonnement(){
        return $this->belongsTo(Abonnement::class, 'id_abonnement');
    }
}
