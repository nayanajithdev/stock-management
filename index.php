<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

ob_start();
require $page['view'];
$pageContent = (string) ob_get_clean();

require __DIR__ . '/includes/layout_start.php';
echo $pageContent;
require __DIR__ . '/includes/layout_end.php';
