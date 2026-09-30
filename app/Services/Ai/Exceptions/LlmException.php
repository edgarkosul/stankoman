<?php

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/** Шлюз не ответил или ответил не тем. Для посетителя это мягкая эскалация. */
class LlmException extends RuntimeException {}
