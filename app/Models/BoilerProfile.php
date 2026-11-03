<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Instantané de réglages de la chaudière : profil enregistré à la main (source manual), relevé automatique
 * (source auto, créé uniquement quand un réglage a changé) ou import de l'ancienne application (legacy).
 */
class BoilerProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'changes' => 'array'];
    }

    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->where('source', 'auto');
    }
}
