<?php

declare(strict_types=1);

use WeewxPhp\Extension\Context;
use WeewxPhp\Extension\Registration;
use WeewxPhp\Extensions\Forecast\Forecast;
use WeewxPhp\Frontend\{QueryError, Report, Series};

foreach (['Options', 'OpenMeteo', 'Data', 'Store', 'Reader', 'Forecast'] as $class) {
    require_once __DIR__ . '/src/' . $class . '.php';
}

return static function (Registration $registration): void {
    $forecast = new Forecast();
    $registration->tag('status', static function (Context $context, array $args) use ($forecast): Report {
        Forecast::arguments($args, []);
        return $forecast->read($context)->summary();
    });
    $registration->tag('day', static function (Context $context, array $args) use ($forecast): Report {
        Forecast::arguments($args, ['index']);
        return $forecast->read($context)->day(Forecast::integer($args, 'index', 0));
    });
    $registration->tag('hourly', static function (Context $context, array $args) use ($forecast): Series {
        Forecast::arguments($args, ['observation', 'hours']);
        $observation = $args['observation'] ?? 'outTemp';
        if (!is_string($observation)) {
            throw new QueryError('Invalid forecast observation');
        }
        return $forecast->read($context)->hourly($observation, Forecast::integer($args, 'hours', 48));
    });
    $registration->worker($forecast->run(...));
};
