<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Persistence;

/** An autowired private service nothing uses: the compiled container must drop it. */
final class UnusedReport {

  public function __construct(private readonly WidgetRepository $widgets) {}
}
