<?php

namespace TangibleDDD\Domain\Services;

/**
 * Marker interface for domain services.
 *
 * Domain services encapsulate domain logic that doesn't naturally
 * belong to a single entity or aggregate.
 *
 * Implementing it is what makes a class a domain service under the modeling
 * rules (docs/modeling-rules.md): only domain services may depend on
 * repository interfaces (DDD-L2).
 */
interface IDomainService {
}
