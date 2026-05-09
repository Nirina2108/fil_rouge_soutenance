<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Classe de base pour TOUS les tests Laravel (Feature et Unit).
 *
 * Étend la TestCase de Laravel qui apporte :
 *  - le boot de l'application (container, config, services),
 *  - les helpers HTTP ($this->get, $this->post, $this->actingAs, …),
 *  - les assertions DB ($this->assertDatabaseHas, …).
 *
 * Pour partager du setUp/tearDown ou des helpers entre tests, les ajouter ici.
 * Actuellement vide : tous les tests héritent juste du comportement Laravel par défaut.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou : force la connexion DB des tests sur sqlite :memory:
     * AVANT que Laravel ne boote l'app. Ceci empeche RefreshDatabase
     * de wiper la BDD MySQL de prod si les env Docker (DB_CONNECTION=mysql)
     * fuitent dans le contexte de test malgre phpunit.xml.
     *
     * Defense en profondeur : phpunit.xml definit deja sqlite via <server>,
     * et ce setUp re-applique au cas ou l'XML serait mal interprete par
     * une version specifique de PHPUnit.
     */
    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION']    = 'sqlite';
        $_ENV['DB_DATABASE']      = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE']   = ':memory:';

        parent::setUp();
    }
}
