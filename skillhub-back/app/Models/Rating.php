<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Rating : note + avis donnés par un apprenant sur une formation.
 *
 * Une note va de 1 à 5 (validation côté contrôleur + contrainte BDD via tinyInt).
 * Un commentaire libre est optionnel.
 *
 * Contrainte d'unicité (user_id, formation_id) : un apprenant ne peut noter
 * une même formation qu'une seule fois. Définie dans la migration et
 * re-vérifiée côté service pour un message d'erreur explicite.
 *
 * Relations Eloquent :
 *  - user       : l'apprenant auteur de la note
 *  - formation  : la formation notée
 */
class Rating extends Model
{
    /**
     * Champs autorisés en mass-assignment.
     */
    protected $fillable = [
        'user_id',
        'formation_id',
        'note',
        'commentaire',
    ];

    /**
     * Casts automatiques : note doit être un entier (sinon JSON renvoie un string).
     */
    protected $casts = [
        'note' => 'integer',
    ];

    /**
     * Relation N-1 : la note est attachée à un utilisateur (l'apprenant).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation N-1 : la note concerne une formation.
     */
    public function formation()
    {
        return $this->belongsTo(Formation::class);
    }
}
