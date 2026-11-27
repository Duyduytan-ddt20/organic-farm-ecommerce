<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    'api_path' => 'api',
    'api_domain' => null,
    'docs_path' => 'docs/api',
    'ui' => 'scalar',
    'theme' => 'light',
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],
    'servers' => [
        'Môi trường phát triển' => 'http://127.0.0.1:8000',
    ],
];
