<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\MegaTrace\Module\ModuleManifest;

final class IntegrationEventRoundTripTest extends TestCase
{
    public function test_every_scenario_fact_survives_the_wire_codec(): void
    {
        $count = 0;

        foreach (ModuleManifest::definitions() as $definition) {
            foreach ($definition->events as $event_class) {
                $reflection = new ReflectionClass($event_class);
                $arguments = [];
                foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                    $type = $parameter->getType();
                    self::assertInstanceOf(ReflectionNamedType::class, $type);
                    $arguments[] = match ($type->getName()) {
                        'int' => 42,
                        'float' => 4.2,
                        'bool' => true,
                        'string' => $parameter->getName() . '-value',
                        default => self::fail(sprintf(
                            'Add a wire fixture for %s::$%s (%s)',
                            $event_class,
                            $parameter->getName(),
                            $type->getName(),
                        )),
                    };
                }

                $event = $reflection->newInstanceArgs($arguments);
                self::assertInstanceOf(IIntegrationEvent::class, $event);
                $hydrated = $event_class::from_payload($event->integration_payload());

                self::assertSame($event_class, $hydrated::class);
                self::assertSame($event->integration_payload(), $hydrated->integration_payload());
                $count++;
            }
        }

        self::assertSame(29, $count);
    }
}
