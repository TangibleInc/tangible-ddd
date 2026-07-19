<?php

namespace TangibleDDD\WordPress;

/**
 * @deprecated 0.2.5 — moved to TangibleDDD\Infra\Consumers\ConsumerHandle (ddd-src):
 * Event::prefix() / Command::container() resolve through the registry, and
 * Domain must not depend on the WordPress layer. Alias dies in 0.3.
 */
class_alias(\TangibleDDD\Infra\Consumers\ConsumerHandle::class, __NAMESPACE__ . '\\ConsumerHandle');
