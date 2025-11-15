<?php
/**
 * Application Version Configuration
 *
 * This file contains the application version number.
 * Update VERSION_BUILD when making changes to track deployments.
 */

// Semantic version
define('VERSION_MAJOR', 1);
define('VERSION_MINOR', 4);
define('VERSION_PATCH', 0);

// Build timestamp (update this when deploying changes)
define('VERSION_BUILD', '20250114-019');

// Full version string
define('APP_VERSION', VERSION_MAJOR . '.' . VERSION_MINOR . '.' . VERSION_PATCH . ' (Build ' . VERSION_BUILD . ')');

// Cache buster for static assets (use build timestamp)
define('CACHE_VERSION', VERSION_BUILD);
