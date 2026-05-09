<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table messages — messagerie 1:1 entre apprenants et formateurs.
 *
 * Modèle simple : un message a un expéditeur, un destinataire et un drapeau "lu".
 * Côté frontend, MessagerieModal regroupe les messages par interlocuteur pour
 * afficher des conversations. Pas de pièce jointe, pas de groupe (1:1 only).
 */
return new class extends Migration
{
    /**
     * Crée la table messages avec les deux FK et le drapeau de lecture.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // FK vers users : auteur du message. CASCADE = supprime les messages
            // si l'auteur est supprimé (RGPD).
            $table->foreignId('expediteur_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // FK vers users : destinataire du message. CASCADE pour la même raison.
            $table->foreignId('destinataire_id')
                  ->constrained('users')
                  ->onDelete('cascade');

            // Texte du message (TEXT — jusqu'à 64 KB).
            $table->text('contenu');

            // Drapeau "lu" : passe à true quand le destinataire ouvre la conversation.
            // Utilisé pour le badge "messages non lus" du dashboard.
            $table->boolean('lu')->default(false);

            // created_at = date d'envoi (utilisée pour trier la conversation).
            $table->timestamps();
        });
    }

    /**
     * Drop la table messages (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
