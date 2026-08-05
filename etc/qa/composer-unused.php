<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;

return static fn (Configuration $config): Configuration => $config
    ->setAdditionalFilesFor('openapi-tools/configuration', [
        __DIR__ . '/../../tests/WebHookMiddlewareTest.php',
    ]);
