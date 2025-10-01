<?php
// File: wp-content/plugins/custom-checkout-fields/includes/class-loader.php

if (!defined('ABSPATH')) {
    exit;
}

// Load all required class files for the plugin
require_once __DIR__ . '/class-donation-report-actions.php';
require_once __DIR__ . '/class-email-template-engine.php';
// Add more require_once lines as you add more classes

