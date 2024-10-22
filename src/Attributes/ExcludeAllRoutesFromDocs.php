<?php

namespace Dedoc\Scramble\Attributes;

use Attribute;

/**
 * Excludes all routes of a controller from the API documentation. Applies to controller's methods.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ExcludeAllRoutesFromDocs {}
