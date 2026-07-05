<?php
/**
 * Preferred URL for the Device Protection Hub — delegates to the existing
 * subscriptions.php handler so all comparison / checkout logic lives in one
 * place.  Both /protection-hub.php and /subscriptions.php serve identical
 * content; sitemap.xml lists /protection-hub.php as the canonical URL.
 */
require __DIR__ . '/subscriptions.php';
