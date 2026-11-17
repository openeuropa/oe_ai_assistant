<?php

/**
 * @file
 * Boots Drupal for the neuron CLI's --autoload-file option.
 *
 * Pass the evaluation path as an absolute one, since the CLI resolves it
 * after the chdir() below has run:
 *
 *   vendor/bin/neuron evaluation $PWD/evaluators \
 *     --autoload-file=evaluators/bootstrap.php
 */

declare(strict_types=1);

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require __DIR__ . '/../vendor/autoload.php';

// Drupal resolves some module paths (a contrib module's YAML definition
// files, for one) against the working directory, which is the web root
// whenever the site runs under a web server or drush. Boot from anywhere
// else and the evaluators fail with file-not-found.
chdir(__DIR__ . '/../web');

// The CLI looks for evaluation.php in the working directory as well, so
// after the chdir() it looks in web/, which is gitignored and can't hold
// our tracked config. This throwaway shim sends the lookup back to the
// real evaluation.php at the module root.
$shim = __DIR__ . '/../web/evaluation.php';
if (!file_exists($shim)) {
  file_put_contents($shim, "<?php\n\nreturn require __DIR__ . '/../evaluation.php';\n");
}

$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$kernel->preHandle($request);
