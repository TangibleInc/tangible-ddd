<?php

namespace TangibleDDD\Application\Events;

/**
 * @deprecated 0.2.5 — renamed IntegrationEnvelope (the wire form joins its
 * family: IntegrationEvent/Behaviour/Listener/action/Envelope). This
 * autoloadable alias stub keeps unmigrated consumer code working through
 * the 0.2.x line; it dies in 0.3.
 */
class_alias(IntegrationEnvelope::class, __NAMESPACE__ . '\\TransportEnvelope');
