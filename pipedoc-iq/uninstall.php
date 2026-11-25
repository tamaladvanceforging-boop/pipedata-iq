<?php
// Intentionally does NOT drop tables or delete uploads: engineering and certificate data must survive plugin removal.
// To purge, drop the {prefix}pdq_* tables and wp-content/uploads/pdq-private manually.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
