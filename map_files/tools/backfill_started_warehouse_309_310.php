<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

fwrite(STDERR, "This one-off repair was completed and is permanently disabled.\n");
exit(1);
