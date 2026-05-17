<?php
declare(strict_types=1);

/**
 * Front controller unico dell'applicazione.
 *
 * Tutte le richieste HTTP transitano da qui: bootstrap dell'ambiente,
 * caricamento configurazione, costruzione del Kernel e dispatch della rotta.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Kernel;

$kernel = new Kernel();
$kernel->handle();
