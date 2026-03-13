<?php

declare(strict_types=1);

class ExampleService
{
    public function execute(): void
    {
        \Core\Logger\Logger::info('Example cron task executed');
    }
}
