<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * A fault-injected "process died here". In-process hosts throw it past
 * every catch of the relay step; separate-process hosts end the child
 * process instead and raise this in the parent.
 */
final class SimulatedCrash extends \RuntimeException {}
