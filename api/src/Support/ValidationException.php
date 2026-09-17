<?php

declare(strict_types=1);

namespace Saveurs\Support;

/**
 * Échec de validation d'une entrée utilisateur — porte le nom du champ fautif pour que le
 * contrôleur renvoie un 422 exploitable côté front ("quel champ corriger ?") plutôt qu'un
 * message générique. Jamais utilisée pour une erreur interne : celles-là remontent telles
 * quelles au middleware d'erreur, qui ne les expose pas au client.
 */
final class ValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message
    ) {
        parent::__construct($message);
    }
}
