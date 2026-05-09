<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Payment : trace d'audit d'un paiement simulé pour une formation.
 *
 * Voir migration create_payments_table pour la stratégie produit (mock,
 * pas de PCI-DSS). Ce modèle sert principalement à :
 *  - Empêcher les double-paiements (vérification d'existence avant insert)
 *  - Générer des reçus
 *  - Tracer l'historique pour le formateur (combien de paiements pour ma formation ?)
 *
 * Relations Eloquent :
 *  - user      : l'apprenant qui a payé
 *  - formation : la formation acquise
 */
class Payment extends Model
{
    /**
     * Champs autorisés en mass-assignment.
     * Note : pas de "moyen_paiement", "numero_carte", etc. — on ne stocke
     * jamais aucune donnée bancaire conformément aux bonnes pratiques.
     */
    protected $fillable = [
        'user_id',
        'formation_id',
        'montant',
        'statut',
    ];

    /**
     * Casts : montant en float (string en BDD à cause de DECIMAL).
     */
    protected $casts = [
        'montant' => 'float',
    ];

    /**
     * Relation N-1 : le paiement appartient à un apprenant.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation N-1 : le paiement concerne une formation.
     */
    public function formation()
    {
        return $this->belongsTo(Formation::class);
    }
}
