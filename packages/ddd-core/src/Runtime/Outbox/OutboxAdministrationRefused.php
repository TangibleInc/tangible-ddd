<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/** A repair was refused by its status or lease guard (leased row; accepted row without force). */
final class OutboxAdministrationRefused extends \DomainException {}
