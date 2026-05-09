<?php

namespace App\Http\Controllers;

/**
 * Contrôleur de base abstrait dont héritent tous les contrôleurs de SkillHub.
 *
 * Laravel 11 a retiré la classe Controller historique avec ses traits par défaut.
 * Cette version vide laisse le projet libre d'ajouter ses propres helpers
 * (ex. méthodes de réponse standardisées, gestion d'erreurs commune) si besoin.
 *
 * Tous les contrôleurs de l'API SkillHub étendent cette classe :
 *  - AuthController         (inscription/login/profil/logout/upload photo)
 *  - FormationController    (CRUD formations + filtres + PDF)
 *  - ModuleController       (CRUD modules + marquage "terminé")
 *  - InscriptionController  (inscription/désinscription apprenant)
 *  - MessageController      (messagerie 1:1 + conversations)
 */
abstract class Controller
{
    //
}
