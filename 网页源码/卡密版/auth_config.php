<?php
// Change this password before publishing the site.
define('AUTH_ADMIN_PASSWORD', 'admin123456');

define('AUTH_DATA_DIR', __DIR__ . '/data');
define('AUTH_CARDS_FILE', AUTH_DATA_DIR . '/cards.db.php');
define('AUTH_SESSIONS_FILE', AUTH_DATA_DIR . '/sessions.db.php');
define('AUTH_DATA_PREFIX', "<?php exit; ?>\n");

// Front-end login sessions are capped to this many seconds, but never exceed
// the card expiry time.
define('AUTH_SESSION_TTL', 86400 * 7);
?>
