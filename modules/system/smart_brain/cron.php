<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';

$service = new SmartBrainService();
return $service->run();
