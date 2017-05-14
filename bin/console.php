#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Commands\MongoBackup;
use App\Commands\MoviesUpdate;
use App\Commands\PostersUpdate;
use Symfony\Component\Console\Application;

/**
 * You can execute console commands with:
 * php bin/console.php
 *
 * For example:
 * php bin/console.php movies:update
 * php bin/console.php movies:posters-update --multi=10
 * php bin/console.php mongo:backup -g -l3
 */

set_time_limit(0);

$app = new Application();
$app->addCommands([
    new MongoBackup(),
    new MoviesUpdate(),
    new PostersUpdate()
]);
$app->run();