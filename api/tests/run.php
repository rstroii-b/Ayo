<?php

declare(strict_types=1);

/**
 * Lanceur de tests minimal — sans PHPUnit, sans Composer.
 *
 * Pourquoi pas PHPUnit : les classes testées ici (calcul de prix, validation d'entrées,
 * matrice de transitions) n'ont besoin ni de base de données, ni de conteneur, ni d'autoloader.
 * Un lanceur de 60 lignes les exécute avec un simple `php api/tests/run.php`, y compris sur un
 * poste où `composer install` n'a jamais tourné — c'est-à-dire là où ces tests servent le plus :
 * juste avant un déploiement, sur la machine qui héberge.
 *
 * Le jour où la suite doit couvrir les contrôleurs (donc PDO et PSR-7), PHPUnit reprendra la
 * main ; les fichiers de test restent alors structurés de la même façon.
 *
 * Usage :
 *   php api/tests/run.php            # toute la suite
 *   php api/tests/run.php Pricing    # seuls les fichiers dont le nom contient « Pricing »
 */

$failures = [];
$assertions = 0;
$currentTest = '';

/** Enregistre un test. Les cas sont déclarés à plat, sans classe ni annotation. */
function test(string $name, callable $body): void
{
    global $failures, $currentTest;

    $currentTest = $name;

    try {
        $body();
        echo "  \033[32m✓\033[0m {$name}\n";
    } catch (Throwable $e) {
        $failures[] = ['test' => $name, 'message' => $e->getMessage()];
        echo "  \033[31m✗\033[0m {$name}\n      {$e->getMessage()}\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $context = ''): void
{
    global $assertions;
    $assertions++;

    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sattendu %s, obtenu %s',
            $context === '' ? '' : "{$context} : ",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $condition, string $context = 'condition fausse'): void
{
    global $assertions;
    $assertions++;

    if (!$condition) {
        throw new RuntimeException($context);
    }
}

/** Vérifie qu'un appel lève bien une exception du type attendu — la validation doit refuser. */
function assertThrows(string $expectedClass, callable $body, string $context = ''): void
{
    global $assertions;
    $assertions++;

    try {
        $body();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%sattendu %s, obtenu %s (%s)',
            $context === '' ? '' : "{$context} : ",
            $expectedClass,
            $e::class,
            $e->getMessage()
        ));
    }

    throw new RuntimeException(
        ($context === '' ? '' : "{$context} : ") . "aucune exception levée, {$expectedClass} attendue"
    );
}

function describe(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

// Autoloader PSR-4 réduit au namespace du projet — évite de dépendre de vendor/autoload.php.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Saveurs\\')) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Saveurs\\'))) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];

foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }

    require $file;
}

echo "\n";

if ($failures === []) {
    echo "\033[32m{$assertions} assertions, aucun échec.\033[0m\n";
    exit(0);
}

$count = count($failures);
echo "\033[31m{$count} test(s) en échec sur {$assertions} assertions :\033[0m\n";
foreach ($failures as $failure) {
    echo "  - {$failure['test']} : {$failure['message']}\n";
}

exit(1);
