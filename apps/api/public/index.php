<?php
declare(strict_types=1);

// Point d'entrée unique. En production (Camoo) ce fichier vit dans /api/ et le code est dans /api-core/
// (protégé par .htaccess). En développement, le code est dans le dossier parent.
$core = is_dir(__DIR__ . '/../api-core') ? __DIR__ . '/../api-core' : dirname(__DIR__);

require $core . '/vendor/autoload.php';

$app = \Sanara\Bootstrap::createApp($core);
$app->run();
