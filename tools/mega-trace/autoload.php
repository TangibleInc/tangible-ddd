<?php

declare(strict_types=1);

$megaTracePrefixes = [
    'TangibleDDD\\MegaTrace\\' => __DIR__ . '/src/',
    'Tangible\\LMS\\MegaTrace\\' => __DIR__ . '/src/Lms/',
    'Tangible\\Quiz\\MegaTrace\\' => __DIR__ . '/src/Quiz/',
    'Tangible\\Cred\\MegaTrace\\' => __DIR__ . '/src/Cred/',
    'Tangible\\Datastream\\MegaTrace\\' => __DIR__ . '/src/Datastream/',
];

$megaTraceBundles = [
    'Tangible\\LMS\\MegaTrace\\Domain\\Aggregates\\' => __DIR__ . '/src/Lms/Domain/Aggregates.php',
    'Tangible\\LMS\\MegaTrace\\Domain\\Events\\' => __DIR__ . '/src/Lms/Domain/Events.php',
    'Tangible\\LMS\\MegaTrace\\Application\\Commands\\' => __DIR__ . '/src/Lms/Application/Commands.php',
    'Tangible\\LMS\\MegaTrace\\Application\\Process\\' => __DIR__ . '/src/Lms/Application/Process/CertificationJourneyProcess.php',
    'Tangible\\Quiz\\MegaTrace\\Domain\\Aggregates\\' => __DIR__ . '/src/Quiz/Domain/Aggregates.php',
    'Tangible\\Quiz\\MegaTrace\\Domain\\Events\\' => __DIR__ . '/src/Quiz/Domain/Events.php',
    'Tangible\\Quiz\\MegaTrace\\Application\\Commands\\' => __DIR__ . '/src/Quiz/Application/Commands.php',
    'Tangible\\Quiz\\MegaTrace\\Application\\Process\\' => __DIR__ . '/src/Quiz/Application/Process/AdaptiveAssessmentProcess.php',
    'Tangible\\Cred\\MegaTrace\\Domain\\Aggregates\\' => __DIR__ . '/src/Cred/Domain/Aggregates.php',
    'Tangible\\Cred\\MegaTrace\\Domain\\Events\\' => __DIR__ . '/src/Cred/Domain/Events.php',
    'Tangible\\Cred\\MegaTrace\\Domain\\Behaviours\\' => __DIR__ . '/src/Cred/Domain/Behaviours.php',
    'Tangible\\Cred\\MegaTrace\\Application\\Commands\\' => __DIR__ . '/src/Cred/Application/Commands.php',
    'Tangible\\Cred\\MegaTrace\\Application\\Process\\' => __DIR__ . '/src/Cred/Application/Process/CredentialIssuanceProcess.php',
    'Tangible\\Datastream\\MegaTrace\\Domain\\Aggregates\\' => __DIR__ . '/src/Datastream/Domain/Aggregates.php',
    'Tangible\\Datastream\\MegaTrace\\Domain\\Events\\' => __DIR__ . '/src/Datastream/Domain/Events.php',
    'Tangible\\Datastream\\MegaTrace\\Application\\Commands\\' => __DIR__ . '/src/Datastream/Application/Commands.php',
    'Tangible\\Datastream\\MegaTrace\\Application\\Process\\' => __DIR__ . '/src/Datastream/Application/Process/EvidenceExportProcess.php',
];

spl_autoload_register(static function (string $class) use ($megaTracePrefixes, $megaTraceBundles): void {
    foreach ($megaTraceBundles as $prefix => $file) {
        if (str_starts_with($class, $prefix)) {
            require_once $file;
            return;
        }
    }

    foreach ($megaTracePrefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $directory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
