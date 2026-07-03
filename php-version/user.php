<?php
/**
 * Staff / sub-user login — simple URL: /user.php
 * This is the sign-in page for users created from the admin panel (role=staff,
 * with granular permissions). It shows "User login" branding (NOT "Admin login")
 * and, on success, drops the user into their first permitted panel.
 * It reuses the shared login controller/form; the $loginContext flag flips the
 * heading + page title to the user variant.
 */
$loginContext = 'user';
require __DIR__ . '/login.php';
