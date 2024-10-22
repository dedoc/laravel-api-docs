<?php

namespace Dedoc\Scramble\Attributes;

use Attribute;

/**
 * Excludes a route from API documentation. Applies to controller's methods.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
class ExcludeRouteFromDocs {}
