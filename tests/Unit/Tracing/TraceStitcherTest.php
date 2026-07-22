<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Tracing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Tracing\TraceStitcher;

final class TraceStitcherTest extends TestCase
{
    public function test_it_stitches_a_cross_consumer_handoff_without_local_id_collisions(): void
    {
        $trace = (new TraceStitcher())->stitch([
            $this->fragment('lms', [
                $this->command('cmd-lms', 'Lms\\CompleteCourse', null, null, '2026-07-22 10:00:00'),
            ], [
                $this->event('evt-completed', 'Lms\\CourseCompleted', 'cmd-lms', '2026-07-22 10:00:01'),
            ], [
                $this->process(7, 'Lms\\CompletionProcess', '2026-07-22 10:00:00', 'evt-completed'),
            ]),
            $this->fragment('cred', [
                $this->command(
                    'cmd-cred',
                    'Cred\\IssueCredential',
                    'evt-completed',
                    'integration_event',
                    '2026-07-22 10:00:02',
                ),
            ], [], [
                $this->process(7, 'Cred\\IssuanceProcess', '2026-07-22 10:00:02'),
            ]),
        ]);

        self::assertSame(
            ['lms:c:cmd-lms', 'lms:e:evt-completed', 'lms:p:7', 'cred:c:cmd-cred', 'cred:p:7'],
            array_keys($trace['nodes']),
        );
        self::assertSame('lms:c:cmd-lms', $trace['nodes']['lms:e:evt-completed']['parent']);
        self::assertSame('lms:e:evt-completed', $trace['nodes']['lms:p:7']['parent']);
        self::assertSame('lms:e:evt-completed', $trace['nodes']['cred:c:cmd-cred']['parent']);
        self::assertSame('lms', $trace['nodes']['cred:c:cmd-cred']['parent_consumer']);
        self::assertSame('#2271b1', $trace['nodes']['cred:c:cmd-cred']['parent_accent']);
        self::assertTrue($trace['nodes']['cred:c:cmd-cred']['cross_consumer']);
        self::assertSame(['lms', 'cred'], array_keys($trace['participants']));
    }

    public function test_it_keeps_a_recorded_but_unresolved_parent_visible(): void
    {
        $trace = (new TraceStitcher())->stitch([
            $this->fragment('quiz', [
                $this->command(
                    'cmd-grade',
                    'Quiz\\GradeAttempt',
                    'evt-missing',
                    'integration_event',
                    '2026-07-22 10:00:00',
                ),
            ]),
        ]);

        self::assertSame('missing:e:evt-missing', $trace['nodes']['quiz:c:cmd-grade']['parent']);
        self::assertTrue($trace['nodes']['missing:e:evt-missing']['unresolved']);
        self::assertSame('evt-missing', $trace['nodes']['missing:e:evt-missing']['id']);
        self::assertSame('Recorded integration event was not found in any consumer', $trace['warnings'][0]['message']);
    }

    public function test_it_does_not_guess_between_colliding_cross_consumer_process_ids(): void
    {
        $trace = (new TraceStitcher())->stitch([
            $this->fragment('lms', [], [], [
                $this->process(7, 'Lms\\CompletionProcess', '2026-07-22 10:00:00'),
            ]),
            $this->fragment('quiz', [], [], [
                $this->process(7, 'Quiz\\AttemptProcess', '2026-07-22 10:00:00'),
            ]),
            $this->fragment('cred', [
                $this->command(
                    'cmd-cred',
                    'Cred\\IssueCredential',
                    '7',
                    'long_process',
                    '2026-07-22 10:00:01',
                ),
            ]),
        ]);

        self::assertSame('ambiguous:p:7', $trace['nodes']['cred:c:cmd-cred']['parent']);
        self::assertTrue($trace['nodes']['ambiguous:p:7']['unresolved']);
        self::assertSame('ambiguous_parent', $trace['warnings'][0]['code']);
        self::assertSame(['lms:p:7', 'quiz:p:7'], $trace['warnings'][0]['candidates']);
    }

    public function test_same_consumer_process_wins_over_a_foreign_id_collision(): void
    {
        $trace = (new TraceStitcher())->stitch([
            $this->fragment('lms', [
                $this->command(
                    'cmd-lms',
                    'Lms\\ContinueJourney',
                    '7',
                    'long_process',
                    '2026-07-22 10:00:01',
                ),
            ], [], [
                $this->process(7, 'Lms\\CompletionProcess', '2026-07-22 10:00:00'),
            ]),
            $this->fragment('quiz', [], [], [
                $this->process(7, 'Quiz\\AttemptProcess', '2026-07-22 10:00:00'),
            ]),
        ]);

        self::assertSame('lms:p:7', $trace['nodes']['lms:c:cmd-lms']['parent']);
        self::assertFalse($trace['nodes']['lms:c:cmd-lms']['cross_consumer']);
        self::assertSame([], $trace['warnings']);
    }

    public function test_it_resolves_a_unique_process_to_command_handoff_across_consumers(): void
    {
        $trace = (new TraceStitcher())->stitch([
            $this->fragment('lms', [], [], [
                $this->process(42, 'Lms\\CompletionProcess', '2026-07-22 10:00:00'),
            ]),
            $this->fragment('cred', [
                $this->command(
                    'cmd-cred',
                    'Cred\\IssueCredential',
                    '42',
                    'long_process',
                    '2026-07-22 10:00:01',
                ),
            ]),
        ]);

        self::assertSame('lms:p:42', $trace['nodes']['cred:c:cmd-cred']['parent']);
        self::assertTrue($trace['nodes']['cred:c:cmd-cred']['cross_consumer']);
        self::assertSame([], $trace['warnings']);
    }

    public function test_it_attaches_recorded_aggregate_touches_to_the_matching_fact_node(): void
    {
        $trace = (new TraceStitcher())->stitch([
            $this->fragment('lms', [
                $this->command('cmd-lms', 'Lms\\CompleteCourse', null, null, '2026-07-22 10:00:00'),
            ], [
                $this->event('evt-completed', 'Lms\\CourseCompleted', 'cmd-lms', '2026-07-22 10:00:01'),
            ], [], [
                $this->touch('evt-completed', 'cmd-lms'),
            ]),
        ]);

        $touches = $trace['nodes']['lms:e:evt-completed']['touches'];
        self::assertCount(1, $touches);
        self::assertSame('lms.learning_journey', $touches[0]['aggregate']);
        self::assertSame('journey-42', $touches[0]['aggregate_id']);
        self::assertSame(4, $touches[0]['version']);
        self::assertSame('lms', $touches[0]['consumer']);
        self::assertSame('#2271b1', $touches[0]['accent']);
        self::assertArrayNotHasKey('touches', $trace['nodes']['lms:c:cmd-lms']);
    }

    /**
     * @param list<array<string, mixed>> $commands
     * @param list<array<string, mixed>> $events
     * @param list<array<string, mixed>> $processes
     * @param list<array<string, mixed>> $touches
     * @return array<string, mixed>
     */
    private function fragment(
        string $consumer,
        array $commands = [],
        array $events = [],
        array $processes = [],
        array $touches = [],
    ): array {
        return [
            'consumer' => [
                'key' => $consumer,
                'label' => ucfirst($consumer),
                'accent' => $consumer === 'lms' ? '#2271b1' : '#9a6700',
                'ghost' => false,
            ],
            'commands' => $commands,
            'events' => $events,
            'processes' => $processes,
            'workflows' => [],
            'touches' => $touches,
        ];
    }

    /** @return array<string, mixed> */
    private function command(
        string $id,
        string $name,
        ?string $causationId,
        ?string $causationType,
        string $startedAt,
    ): array {
        return [
            'command_id' => $id,
            'correlation_id' => 'corr-mega',
            'command_name' => $name,
            'status' => 'success',
            'source' => 'system',
            'source_id' => null,
            'causation_id' => $causationId,
            'causation_type' => $causationType,
            'duration_ms' => '10',
            'peak_memory_bytes' => '1000',
            'started_at' => $startedAt,
            'parameters' => '{}',
            'events' => '[]',
            'error' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $name, string $commandId, string $createdAt): array
    {
        return [
            'event_id' => $id,
            'event_type' => $name,
            'status' => 'completed',
            'command_id' => $commandId,
            'sequence' => '1',
            'attempts' => '1',
            'created_at' => $createdAt,
        ];
    }

    /** @return array<string, mixed> */
    private function process(int $id, string $class, string $createdAt, ?string $ignitedBy = null): array
    {
        return [
            'id' => (string) $id,
            'process_class' => $class,
            'status' => 'running',
            'step_name' => 'next',
            'waiting_for' => null,
            'ignited_by_event_id' => $ignitedBy,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /** @return array<string, mixed> */
    private function touch(string $eventId, string $commandId): array
    {
        return [
            'id' => '12',
            'aggregate' => 'lms.learning_journey',
            'aggregate_id' => 'journey-42',
            'op' => 'updated',
            'version' => '4',
            'event_name' => 'course_completed',
            'event_id' => $eventId,
            'command_id' => $commandId,
            'correlation_id' => 'corr-mega',
            'occurred_at' => '2026-07-22 10:00:01',
        ];
    }
}
