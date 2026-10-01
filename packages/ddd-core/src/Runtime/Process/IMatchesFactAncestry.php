<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/**
 * Capability marker for an IProcessStore (wave 4, fix round 1): its
 * find_waiting_for($eventClass) also returns processes whose `waiting_for` is
 * a parent class or an interface of $eventClass (pdo and the mem double
 * match class_parents / class_implements / is_a).
 *
 * The runner relies on it only as an optimisation. For a store without the
 * marker (an exact `waiting_for = class` match: the wp adapters, the
 * LegacyProcessStore bridge over a consumer's 0.6 repository), the runner
 * also asks find_waiting_for() for each ancestor of the fact that is an
 * IIntegrationEvent type, so an AwaitAny whose `waiting_for` is the
 * branches' common ancestor is found on every store. A store that declares
 * the marker gets one lookup per fact instead.
 */
interface IMatchesFactAncestry {
}
