<?php
/**
 * Pure-PHP test bootstrap for the Domain layer. Deliberately does NOT load
 * WordPress - that's the point of the domain/infrastructure split. We only
 * need ABSPATH defined so the `defined('ABSPATH') || exit;` guards at the
 * top of each domain file don't kill the test run.
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../vendor/autoload.php';
