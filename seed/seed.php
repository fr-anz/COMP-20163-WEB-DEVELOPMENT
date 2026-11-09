<?php

declare(strict_types=1);

use Habi\Console\ReseedCommand;

require dirname(__DIR__) . '/vendor/autoload.php';

exit(ReseedCommand::run(in_array('--force', $argv, true), dirname(__DIR__)));
