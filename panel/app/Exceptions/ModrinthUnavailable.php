<?php

namespace App\Exceptions;

use RuntimeException;

// Modrinth did not answer or refused; kept apart from a node's failure, which reads the same.
class ModrinthUnavailable extends RuntimeException {}
