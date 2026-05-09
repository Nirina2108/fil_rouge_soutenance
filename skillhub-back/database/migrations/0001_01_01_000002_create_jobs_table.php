<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration : tables techniques du driver de queue "database" Laravel.
 *
 * Quand QUEUE_CONNECTION=database, les jobs asynchrones (envoi de mail,
 * traitement d'image, etc.) sont stockés ici en attendant qu'un worker
 * `php artisan queue:work` les exécute. Trois tables sont créées :
 *  - jobs        : file d'attente des jobs en cours/en attente.
 *  - job_batches : suivi des lots (Bus::batch) — progression d'un groupe de jobs.
 *  - failed_jobs : archivage des jobs ayant échoué après tous les retries.
 */
return new class extends Migration
{
    /**
     * Crée les trois tables système de la file d'attente Laravel.
     */
    public function up(): void
    {
        // Table principale de la queue : un enregistrement par job en attente d'exécution.
        Schema::create('jobs', function (Blueprint $table) {
            // PK auto-incrémentée.
            $table->id();

            // Nom de la queue (ex. "default", "emails", "high"). Indexé pour le pop rapide.
            $table->string('queue')->index();

            // Payload sérialisé du job (objet PHP serialize()).
            $table->longText('payload');

            // Compteur de tentatives — comparé à $tries du job pour décider du replay vs failed.
            $table->unsignedTinyInteger('attempts');

            // Timestamp UNIX où un worker a réservé ce job (NULL = libre, à dispatcher).
            $table->unsignedInteger('reserved_at')->nullable();

            // Timestamp UNIX à partir duquel le job peut être exécuté (delay).
            $table->unsignedInteger('available_at');

            // Date de création (UNIX), utilisé pour FIFO.
            $table->unsignedInteger('created_at');
        });

        // Table de suivi des lots Bus::batch() — Laravel met à jour les compteurs.
        Schema::create('job_batches', function (Blueprint $table) {
            // UUID du batch (string explicite, pas auto-increment).
            $table->string('id')->primary();

            // Nom métier du batch (ex. "Envoi newsletters mensuelles").
            $table->string('name');

            // Compteurs : initialisé une fois, décrémentés au fil des jobs.
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');

            // IDs des jobs ayant échoué (JSON sérialisé).
            $table->longText('failed_job_ids');

            // Options sérialisées du batch (callbacks then/catch/finally).
            $table->mediumText('options')->nullable();

            // Timestamp UNIX d'annulation (Bus::cancel) — NULL si non annulé.
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            // Timestamp UNIX où tous les jobs du batch ont été traités.
            $table->integer('finished_at')->nullable();
        });

        // Cimetière des jobs : un job qui a dépassé $tries atterrit ici.
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();

            // UUID stable du job (utilisable pour `php artisan queue:retry <uuid>`).
            $table->string('uuid')->unique();

            // Nom de la connexion (ex. "database", "redis").
            $table->text('connection');

            // Nom de la queue d'origine.
            $table->text('queue');

            // Payload sérialisé pour permettre le replay.
            $table->longText('payload');

            // Stack trace de l'exception finale.
            $table->longText('exception');

            // Date de l'échec (auto: NOW() à l'insert).
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Drop des trois tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
