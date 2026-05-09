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
    //
}
