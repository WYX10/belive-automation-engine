<?php

declare(strict_types=1);

defined('APP_BOOTED') || exit('No direct access.');

// Manual intake lives at the spec-fixed location; this route just forwards.
header('Location: /webhook/tiktok_fallback');
exit;
