<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : table payments — trace d'audit des paiements simulés.
 *
 * Stratégie : SkillHub n'utilise PAS de vrai processeur de paiement (PCI-DSS
 * coûteux + hors scope). Les paiements sont simulés en mock côté backend :
 * un apprenant retape son mot de passe pour confirmer son intention, et si
 * la ré-authentification réussit, l'inscription est créée et un record
 * est ajouté ici pour la traçabilité.
 *
 * Cette table sert :
 *  - de preuve d'achat (audit trail)
 *  - de base pour générer un reçu téléchargeable plus tard
 *  - de protection anti-doublon (vérifier si paiement déjà existant)
 *
 * IMPORTANT : on ne stocke JAMAIS les données de carte bancaire (numéro/CVV).
 * Seul le montant payé et le statut sont conservés. Si on devait passer en
 * production, il faudrait déléguer le PAN à un processeur compliant (Stripe,
 * Adyen, etc.) et stocker uniquement un payment_intent_id externe.
 */
return new class extends Migration
{
    /**
     * Crée la table payments avec FK user/formation et statut.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // FK utilisateur qui a payé. CASCADE : le RGPD exige que les
            // paiements soient supprimés si le compte disparait.
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // FK formation acquise. CASCADE pour la même raison.
            $table->foreignId('formation_id')
                ->constrained('formations')
                ->onDelete('cascade');

            // Montant facturé en roupies mauriciennes (DECIMAL(10,2) = jusqu'à
            // 99 999 999.99 Rs, largement suffisant pour des formations).
            // Recalculé côté serveur depuis formation.prix au moment du paiement
            // pour empêcher la falsification côté client (price tampering).
            $table->decimal('montant', 10, 2);

            // Statut du paiement. ENUM strict pour ne pas avoir de typos.
            // - reussi : ré-auth password OK, inscription créée
            // - echec  : ré-auth a échoué, aucune inscription
            $table->enum('statut', ['reussi', 'echec'])->default('reussi');

            // created_at = date du paiement, updated_at non utilisé mais
            // standard Laravel pour rester cohérent avec le reste du schéma.
            $table->timestamps();

            // Index sur (user_id, formation_id) pour répondre rapidement à la
            // question "ce user a-t-il déjà payé cette formation ?" (sert
            // côté contrôleur pour l'idempotence).
            $table->index(['user_id', 'formation_id']);
        });
    }

    /**
     * Drop la table payments (rollback).
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
