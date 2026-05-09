<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Formation : représente une formation publiée par un formateur.
 *
 * Une formation a :
 *  - des informations descriptives (titre, description, catégorie, niveau, prix, durée)
 *  - un fichier PDF de cours optionnel (chemin stocké dans fichier_pdf)
 *  - une image d'illustration optionnelle (chemin stocké dans image)
 *  - un compteur de vues uniques (nombre_de_vues)
 *  - un formateur propriétaire (formateur_id)
 *  - des modules (leçons) ordonnés
 *  - des inscriptions d'apprenants
 *  - des vues uniques pour le comptage non répété
 */
class Formation extends Model
{
    /**
     * Champs autorisés en mass-assignment.
     * Note : nombre_de_vues est inclus pour permettre l'incrémentation directe.
     */
    protected $fillable = [
        'titre',
        'description',
        'categorie',
        'niveau',
        'prix',
        'duree_heures',
        'fichier_pdf',
        'image',
        'nombre_de_vues',
        'formateur_id',
    ];

    /**
     * Relation N-1 : la formation appartient à un formateur (User avec role=formateur).
     */
    public function formateur()
    {
        return $this->belongsTo(User::class, 'formateur_id');
    }

    /**
     * Relation 1-N : modules de la formation, triés par ordre pédagogique.
     * Le orderBy('ordre') garantit que les modules sont toujours retournés dans l'ordre voulu.
     */
    public function modules()
    {
        return $this->hasMany(Module::class, 'formation_id')->orderBy('ordre');
    }

    /**
     * Relation 1-N : inscriptions des apprenants à cette formation.
     */
    public function inscriptions()
    {
        return $this->hasMany(Inscription::class, 'formation_id');
    }

    /**
     * Relation 1-N : vues uniques de la formation (utilisé pour comptage anti-doublon).
     */
    public function vues()
    {
        return $this->hasMany(FormationVue::class, 'formation_id');
    }
}
