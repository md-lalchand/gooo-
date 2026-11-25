<?php

return [
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY', ''),
    // Legacy v1 manifests have no authenticity protection. Keep disabled unless
    // an operator explicitly accepts the risk to recover an old archive.
    'allow_unsigned_legacy' => env('BACKUP_ALLOW_UNSIGNED_LEGACY', false),
];
