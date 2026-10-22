<?php

declare(strict_types=1);

// Gives phpstan-doctrine the real entity manager, so it knows the mapping and the repositories.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env', 'dev');
$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
