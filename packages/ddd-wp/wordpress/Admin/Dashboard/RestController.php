<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

use TangibleDDD\WordPress\Admin\Dashboard\Query\BiographyQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\CommandAuditQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\DeadLetterQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\MetricsQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\OutboxQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\ProcessQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\TracesQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\UnifiedTraceQuery;
use TangibleDDD\WordPress\Admin\Dashboard\Query\WorkflowQuery;

final class RestController
{
    public const NAMESPACE = 'tangible-ddd/v1';

    public function __construct(
        private readonly ConsumerCatalog $consumers,
        private readonly Database $db,
        private readonly ActionDispatcher $actions,
    ) {
    }

    public function registerRoutes(): void
    {
        $this->register('/audit', 'GET', [$this, 'audit']);
        $this->register('/trace/(?P<corr>[A-Za-z0-9\-]+)', 'GET', [$this, 'trace']);
        $this->register('/traces', 'GET', [$this, 'traces']);
        $this->register('/biographies', 'GET', [$this, 'biographies']);
        $this->register('/biography', 'GET', [$this, 'biography']);
        $this->register('/overview', 'GET', [$this, 'overview']);
        $this->register('/processes', 'GET', [$this, 'processes']);
        $this->register('/workflows', 'GET', [$this, 'workflows']);
        $this->register('/outbox', 'GET', [$this, 'outbox']);
        $this->register('/dlq', 'GET', [$this, 'deadLetters']);
        $this->register('/actions/(?P<action>[a-z_]+)', 'POST', [$this, 'act']);
    }

    public function audit(\WP_REST_Request $request): mixed
    {
        $consumer = $this->consumer($request);
        $config = $this->consumers->config($consumer);
        if ($config === null) {
            return $this->consumerError($consumer);
        }
        return rest_ensure_response((new CommandAuditQuery($config, $this->db))->run([
            'status' => $request->get_param('status'),
            'source' => $request->get_param('source'),
            'search' => $request->get_param('search'),
            'from' => $request->get_param('from'),
            'to' => $request->get_param('to'),
            'orderby' => $request->get_param('orderby'),
            'order' => $request->get_param('order'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ]));
    }

    public function trace(\WP_REST_Request $request): mixed
    {
        // Unified: the trace stitches ALL consumers' fragments for the
        // correlation, so no consumer parameter is consulted or validated.
        return rest_ensure_response(
            (new UnifiedTraceQuery($this->consumers, $this->db))->assemble((string) $request['corr'])
        );
    }

    public function traces(\WP_REST_Request $request): mixed
    {
        $consumer = $this->consumer($request);
        $config = $this->consumers->config($consumer);
        if ($config === null) {
            return $this->consumerError($consumer);
        }
        return rest_ensure_response((new TracesQuery($config, $this->db))->recent());
    }

    public function biographies(\WP_REST_Request $request): mixed
    {
        $consumer = $this->consumer($request);
        $config = $this->consumers->config($consumer);
        if ($config === null) {
            return $this->consumerError($consumer);
        }
        return rest_ensure_response((new BiographyQuery($config, $this->db))->recent([
            'search' => $request->get_param('search'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ]));
    }

    public function biography(\WP_REST_Request $request): mixed
    {
        $consumer = $this->consumer($request);
        $config = $this->consumers->config($consumer);
        if ($config === null) {
            return $this->consumerError($consumer);
        }
        $aggregate = trim((string) $request->get_param('aggregate'));
        $aggregateId = trim((string) $request->get_param('aggregate_id'));
        if ($aggregate === '' || $aggregateId === '') {
            return new \WP_Error(
                'tddd_bad_biography',
                'aggregate and aggregate_id are required',
                ['status' => 400],
            );
        }
        return rest_ensure_response((new BiographyQuery($config, $this->db))->read($aggregate, $aggregateId, [
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ]));
    }

    public function overview(\WP_REST_Request $request): mixed
    {
        $consumer = $this->consumer($request);
        $config = $this->consumers->config($consumer);
        if ($config === null) {
            return $this->consumerError($consumer);
        }
        return rest_ensure_response((new MetricsQuery($config, $this->db))->overview());
    }

    public function processes(\WP_REST_Request $request): mixed
    {
        $config = $this->consumers->config($this->consumer($request));
        if ($config === null) {
            return new \WP_Error('tddd_no_consumer', 'consumer not available', ['status' => 400]);
        }
        return rest_ensure_response((new ProcessQuery($config, $this->db))->list([
            'status' => $request->get_param('status'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ]));
    }

    public function workflows(\WP_REST_Request $request): mixed
    {
        $config = $this->consumers->config($this->consumer($request));
        if ($config === null) {
            return new \WP_Error('tddd_no_consumer', 'consumer not available', ['status' => 400]);
        }
        return rest_ensure_response((new WorkflowQuery($config, $this->db))->list([
            'state' => $request->get_param('state'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ]));
    }

    public function outbox(\WP_REST_Request $request): mixed
    {
        $config = $this->consumers->config($this->consumer($request));
        if ($config === null) {
            return new \WP_Error('tddd_no_consumer', 'consumer not available', ['status' => 400]);
        }
        return rest_ensure_response((new OutboxQuery($config, $this->db))->list([
            'status' => $request->get_param('status'),
            'from' => $request->get_param('from'),
            'to' => $request->get_param('to'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ]));
    }

    public function deadLetters(\WP_REST_Request $request): mixed
    {
        $config = $this->consumers->config($this->consumer($request));
        if ($config === null) {
            return new \WP_Error('tddd_no_consumer', 'consumer not available', ['status' => 400]);
        }
        return rest_ensure_response((new DeadLetterQuery($config, $this->db))->list([
            'from' => $request->get_param('from'),
            'to' => $request->get_param('to'),
            'page' => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
        ]));
    }

    public function act(\WP_REST_Request $request): mixed
    {
        $prefix = $this->consumers->prefix($this->consumer($request));
        if ($prefix === null) {
            return new \WP_Error('tddd_no_consumer', 'unknown consumer', ['status' => 400]);
        }

        $action = (string) $request['action'];
        try {
            $result = $this->actions->dispatch(
                $action,
                $prefix,
                (int) $request->get_param('id'),
                (int) ($request->get_param('days') ?: 30),
            );
        } catch (\InvalidArgumentException $exception) {
            return new \WP_Error('tddd_bad_action', $exception->getMessage(), ['status' => 400]);
        } catch (\Throwable $exception) {
            return new \WP_Error('tddd_action_failed', $exception->getMessage(), ['status' => 500]);
        }
        return rest_ensure_response($result);
    }

    private function register(string $route, string $method, callable $callback): void
    {
        register_rest_route(self::NAMESPACE, $route, [
            'methods' => $method,
            'permission_callback' => static fn (): bool => current_user_can('manage_options'),
            'callback' => $callback,
        ], true);
    }

    private function consumer(\WP_REST_Request $request): string
    {
        return (string) ($request->get_param('consumer') ?: 'datastream');
    }

    private function consumerError(string $consumer): \WP_Error
    {
        return new \WP_Error(
            'tddd_no_consumer',
            "Consumer '{$consumer}' not available",
            ['status' => 400],
        );
    }
}
