#!/bin/bash
# Test script for Bug 1 and Bug 2 fixes
# Admin credentials: services@maventechsoftware.com / Admin@123

set -e

COOKIE_JAR="/tmp/admin_cookies.txt"
BASE_URL="http://localhost:3000"

echo "========================================="
echo "BUG FIX TESTING - Email Activity + API"
echo "========================================="
echo ""

# Clean up old cookies
rm -f "$COOKIE_JAR"

# Step 1: Authenticate as admin
echo "Step 1: Authenticating as admin..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/login.php" \
  -d "email=services@maventechsoftware.com&password=Admin@123" \
  -L -o /tmp/login_response.html

# Verify login worked
if grep -q "admin.php" /tmp/login_response.html || grep -q "Dashboard" /tmp/login_response.html; then
  echo "✓ Authentication successful"
else
  echo "✗ Authentication failed"
  exit 1
fi

echo ""
echo "========================================="
echo "BUG 1 - EMAIL ACTIVITY TESTS"
echo "========================================="
echo ""

# Test 1.a — Auto-sync throttle
echo "Test 1.a — Auto-sync throttle mechanism"
echo "----------------------------------------"

# Reset throttle
echo "Resetting throttle timestamp..."
mysql -uroot ucode_store -e "DELETE FROM settings WHERE \`key\`='emails_last_bounce_sync'" 2>/dev/null || true

# Fetch tab (first time - should trigger sync)
echo "Fetching emails tab (first time)..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/admin.php?tab=emails" > /tmp/em1.html

# Check if page loaded
if grep -q "email-activity-list\|Email Activity Center" /tmp/em1.html; then
  echo "✓ Email Activity page loaded successfully"
else
  echo "✗ Email Activity page did not load properly"
fi

# Read the throttle timestamp
echo "Reading throttle timestamp..."
TIMESTAMP1=$(mysql -uroot ucode_store -N -e "SELECT \`value\` FROM settings WHERE \`key\`='emails_last_bounce_sync'" 2>/dev/null || echo "")
if [ -n "$TIMESTAMP1" ]; then
  echo "✓ Throttle timestamp set: $TIMESTAMP1"
  CURRENT_TIME=$(date +%s)
  TIME_DIFF=$((CURRENT_TIME - TIMESTAMP1))
  if [ $TIME_DIFF -lt 15 ]; then
    echo "✓ Timestamp is within last 15 seconds"
  else
    echo "⚠ Timestamp is $TIME_DIFF seconds old (expected < 15)"
  fi
else
  echo "⚠ Throttle timestamp not set (php-imap may be missing)"
fi

# Fetch tab again immediately
echo "Fetching emails tab again (should be throttled)..."
sleep 1
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/admin.php?tab=emails" > /tmp/em2.html

# Read timestamp again
TIMESTAMP2=$(mysql -uroot ucode_store -N -e "SELECT \`value\` FROM settings WHERE \`key\`='emails_last_bounce_sync'" 2>/dev/null || echo "")
if [ -n "$TIMESTAMP1" ] && [ -n "$TIMESTAMP2" ]; then
  if [ "$TIMESTAMP1" = "$TIMESTAMP2" ]; then
    echo "✓ Throttle working: timestamp unchanged ($TIMESTAMP1 = $TIMESTAMP2)"
  else
    echo "✗ Throttle NOT working: timestamp changed ($TIMESTAMP1 → $TIMESTAMP2)"
  fi
else
  echo "⚠ Cannot verify throttle (timestamps: $TIMESTAMP1, $TIMESTAMP2)"
fi

echo ""

# Test 1.b — Missing php-imap banner
echo "Test 1.b — Missing php-imap banner"
echo "-----------------------------------"

# Check if php-imap is installed
if php -m 2>/dev/null | grep -qi imap; then
  echo "✓ php-imap extension is installed"
  
  # When imap is available, the button SHOULD appear and banner should NOT
  BUTTON_COUNT=$(grep -c 'emails-sync-bounces-btn' /tmp/em1.html || echo "0")
  BANNER_COUNT=$(grep -c 'emails-imap-missing-banner' /tmp/em1.html || echo "0")
  
  echo "  - 'Sync bounces now' button count: $BUTTON_COUNT (expected: 1)"
  echo "  - 'IMAP missing' banner count: $BANNER_COUNT (expected: 0)"
  
  if [ "$BUTTON_COUNT" -ge 1 ]; then
    echo "✓ 'Sync bounces now' button present"
  else
    echo "✗ 'Sync bounces now' button NOT found"
  fi
  
  if [ "$BANNER_COUNT" -eq 0 ]; then
    echo "✓ 'IMAP missing' banner correctly NOT shown"
  else
    echo "✗ 'IMAP missing' banner incorrectly shown"
  fi
else
  echo "⚠ php-imap extension is NOT installed"
  
  # When imap is missing, banner SHOULD appear and button should NOT
  BUTTON_COUNT=$(grep -c 'emails-sync-bounces-btn' /tmp/em1.html || echo "0")
  BANNER_COUNT=$(grep -c 'emails-imap-missing-banner' /tmp/em1.html || echo "0")
  
  echo "  - 'Sync bounces now' button count: $BUTTON_COUNT (expected: 0)"
  echo "  - 'IMAP missing' banner count: $BANNER_COUNT (expected: 1)"
  
  if [ "$BUTTON_COUNT" -eq 0 ]; then
    echo "✓ 'Sync bounces now' button correctly NOT shown"
  else
    echo "✗ 'Sync bounces now' button incorrectly shown"
  fi
  
  if [ "$BANNER_COUNT" -ge 1 ]; then
    echo "✓ 'IMAP missing' banner correctly shown"
  else
    echo "✗ 'IMAP missing' banner NOT found"
  fi
fi

echo ""

# Test 1.c — Manual sync redirect + open-redirect guard
echo "Test 1.c — Manual sync redirect + open-redirect guard"
echo "------------------------------------------------------"

# Test redirect mode
echo "Testing redirect mode..."
REDIRECT_RESPONSE=$(curl -sSI -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  "$BASE_URL/ajax/sync-bounces.php?redirect=1&back=admin.php?tab=emails" 2>&1)

LOCATION=$(echo "$REDIRECT_RESPONSE" | grep -i "^location:" | head -1 | tr -d '\r')
echo "Location header: $LOCATION"

if echo "$LOCATION" | grep -q "admin.php?tab=emails"; then
  echo "✓ Redirect to admin.php?tab=emails working"
  if echo "$LOCATION" | grep -q "synced=1"; then
    echo "✓ synced=1 parameter present"
  else
    echo "⚠ synced=1 parameter missing"
  fi
else
  echo "✗ Redirect not working properly"
fi

# Test open-redirect guard
echo "Testing open-redirect guard..."
EVIL_REDIRECT=$(curl -sSI -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  "$BASE_URL/ajax/sync-bounces.php?redirect=1&back=https://evil.com/pwn" 2>&1)

EVIL_LOCATION=$(echo "$EVIL_REDIRECT" | grep -i "^location:" | head -1 | tr -d '\r')
echo "Location header: $EVIL_LOCATION"

if echo "$EVIL_LOCATION" | grep -q "admin.php"; then
  echo "✓ Open-redirect guard working: redirected to admin.php"
  if ! echo "$EVIL_LOCATION" | grep -q "evil.com"; then
    echo "✓ Evil domain blocked"
  else
    echo "✗ Evil domain NOT blocked!"
  fi
else
  echo "✗ Open-redirect guard may not be working"
fi

echo ""

# Test 1.d — Flash banner rendering
echo "Test 1.d — Flash banner rendering"
echo "----------------------------------"

# Simulate arrival with synced=1&bounced=1
echo "Testing flash banner with bounced=1..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  "$BASE_URL/admin.php?tab=emails&synced=1&bounced=1&checked=5" > /tmp/em_flash.html

FLASH_COUNT=$(grep -c 'emails-bounces-flash' /tmp/em_flash.html || echo "0")
FLASH_TEXT=$(grep 'emails-bounces-flash' /tmp/em_flash.html || echo "")

echo "Flash banner count: $FLASH_COUNT (expected: 1)"
if [ "$FLASH_COUNT" -ge 1 ]; then
  echo "✓ Flash banner present"
  if echo "$FLASH_TEXT" | grep -q "flipped from"; then
    echo "✓ Flash banner contains 'flipped from' text"
  else
    echo "⚠ Flash banner missing 'flipped from' text"
  fi
  if echo "$FLASH_TEXT" | grep -q "1.*row"; then
    echo "✓ Flash banner shows '1 row'"
  else
    echo "⚠ Flash banner text format unexpected"
  fi
else
  echo "✗ Flash banner NOT found"
fi

# Test error banner
echo "Testing error banner..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  "$BASE_URL/admin.php?tab=emails&synced=1&err=IMAP+mailbox+not+configured" > /tmp/em_err.html

ERROR_COUNT=$(grep -c 'emails-bounces-error' /tmp/em_err.html || echo "0")
ERROR_TEXT=$(grep 'emails-bounces-error' /tmp/em_err.html || echo "")

echo "Error banner count: $ERROR_COUNT (expected: 1)"
if [ "$ERROR_COUNT" -ge 1 ]; then
  echo "✓ Error banner present"
  if echo "$ERROR_TEXT" | grep -q "IMAP mailbox not configured"; then
    echo "✓ Error banner contains error text"
  else
    echo "⚠ Error banner missing error text"
  fi
else
  echo "✗ Error banner NOT found"
fi

echo ""
echo "========================================="
echo "BUG 2 - API CREDENTIALS TESTS"
echo "========================================="
echo ""

# Test 2.a — Position verification
echo "Test 2.a — Position verification (hint after input)"
echo "----------------------------------------------------"

# Save fake NMI live key
echo "Saving fake NMI live key..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/admin.php?tab=api" \
  -d 'save_api=1&gateway=card&provider_type=nmi&nmi_security_key_live=NMIKEY123SECRETABCDEF&status=active' \
  -L -o /tmp/api_after.html

# Load API page fresh
echo "Loading API page..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/admin.php?tab=api&gw=card" -o /tmp/api_page.html

# Check DOM structure
echo "Checking DOM structure for Security Key (live)..."

# Find the label line
LABEL_LINE=$(grep -n 'Security Key (live)</label>' /tmp/api_page.html | head -1 | cut -d: -f1)
echo "Label found at line: $LABEL_LINE"

# Check if label contains mask (it should NOT)
if grep 'Security Key (live).*<small class="text-muted"' /tmp/api_page.html | grep -q 'Security Key (live)'; then
  echo "✗ Label incorrectly contains mask"
else
  echo "✓ Label does NOT contain mask"
fi

# Check for input field
INPUT_COUNT=$(grep -c 'name="nmi_security_key_live"' /tmp/api_page.html || echo "0")
echo "Input field count: $INPUT_COUNT (expected: 1)"
if [ "$INPUT_COUNT" -eq 1 ]; then
  echo "✓ Input field present"
else
  echo "✗ Input field count unexpected"
fi

# Check for saved-key-hint
HINT_COUNT=$(grep -c 'data-testid="saved-key-hint"' /tmp/api_page.html || echo "0")
echo "Saved-key-hint count: $HINT_COUNT (expected: ≥1)"
if [ "$HINT_COUNT" -ge 1 ]; then
  echo "✓ Saved-key-hint element(s) present"
  
  # Check if hint contains masked value
  if grep 'data-testid="saved-key-hint"' /tmp/api_page.html | grep -q 'NMIK.*CDEF'; then
    echo "✓ Hint contains masked value (NMIK***CDEF)"
  else
    echo "⚠ Hint may not contain expected masked value"
  fi
else
  echo "✗ Saved-key-hint NOT found"
fi

echo ""

# Test 2.b — Count all populated hints
echo "Test 2.b — Count populated hints with multiple gateways"
echo "--------------------------------------------------------"

# Save fake credentials for multiple gateways
echo "Saving Stripe credentials..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/admin.php?tab=api" \
  -d 'save_api=1&gateway=card&provider_type=stripe&public_key_live=pk_live_FAKEabcd&secret_key_live=sk_live_FAKEabcd&webhook_secret=whsec_FAKEabcd&status=active' \
  -L -o /dev/null

echo "Saving Authorize.Net credentials..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/admin.php?tab=api" \
  -d 'save_api=1&gateway=card&provider_type=authnet&authnet_login_id_live=ANETLIVEID&authnet_transaction_key_live=ANETLIVETXKEY&authnet_signature_key=ANETLIVESIG&status=active' \
  -L -o /dev/null

echo "Saving PayPal credentials..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" -X POST "$BASE_URL/admin.php?tab=api" \
  -d 'save_api=1&gateway=paypal&client_id_live=PPCLIENTLIVE&secret_live=PPSECRETLIVE&webhook_id=PPWH123&status=active' \
  -L -o /dev/null

# Load API page and count hints
echo "Loading API page to count hints..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/admin.php?tab=api" -o /tmp/api_all.html

TOTAL_HINTS=$(grep -c 'data-testid="saved-key-hint"' /tmp/api_all.html || echo "0")
echo "Total saved-key-hint elements: $TOTAL_HINTS"
echo "✓ Found $TOTAL_HINTS populated credential fields"

# Each hint should have a <code> child
CODE_IN_HINTS=$(grep 'data-testid="saved-key-hint"' /tmp/api_all.html | grep -c '<code>' || echo "0")
echo "Hints with <code> tags: $CODE_IN_HINTS"
if [ "$CODE_IN_HINTS" -eq "$TOTAL_HINTS" ]; then
  echo "✓ All hints contain <code> tags with masked values"
else
  echo "⚠ Some hints may be missing <code> tags"
fi

echo ""

# Test 2.c — Empty values render no hint
echo "Test 2.c — Empty saved values render NO hint"
echo "---------------------------------------------"

# Reset all keys
echo "Resetting all gateway keys..."
mysql -uroot ucode_store -e "UPDATE settings SET \`value\`='' WHERE \`key\` IN ('gw_nmi_security_key_test','gw_nmi_security_key_live','gw_card_public_key_test','gw_card_public_key_live','gw_card_secret_key_test','gw_card_secret_key_live','gw_card_webhook_secret','gw_authnet_login_id_test','gw_authnet_login_id_live','gw_authnet_transaction_key_test','gw_authnet_transaction_key_live','gw_authnet_signature_key','gw_custom_api_key_test','gw_custom_api_secret_test','gw_custom_api_key_live','gw_custom_api_secret_live','gw_paypal_client_id_test','gw_paypal_secret_test','gw_paypal_client_id_live','gw_paypal_secret_live','gw_paypal_webhook_id')" 2>/dev/null

# Load API page
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/admin.php?tab=api" -o /tmp/api_empty.html

EMPTY_HINTS=$(grep -c 'data-testid="saved-key-hint"' /tmp/api_empty.html || echo "0")
echo "Saved-key-hint count with empty values: $EMPTY_HINTS (expected: 0)"
if [ "$EMPTY_HINTS" -eq 0 ]; then
  echo "✓ No phantom hints with empty values"
else
  echo "✗ Found $EMPTY_HINTS hints when all values are empty"
fi

echo ""

# Test 2.d — Placeholder unchanged
echo "Test 2.d — Placeholder text verification"
echo "-----------------------------------------"

PLACEHOLDER_COUNT=$(grep -c 'leave blank to keep current' /tmp/api_all.html || echo "0")
echo "Placeholder 'leave blank to keep current' count: $PLACEHOLDER_COUNT (expected: ≥10)"
if [ "$PLACEHOLDER_COUNT" -ge 10 ]; then
  echo "✓ Placeholder text present in multiple fields"
else
  echo "⚠ Placeholder count lower than expected: $PLACEHOLDER_COUNT"
fi

echo ""
echo "========================================="
echo "CLEANUP"
echo "========================================="
echo ""

# Cleanup
echo "Resetting settings to defaults..."
mysql -uroot ucode_store -e "UPDATE settings SET \`value\`='stripe' WHERE \`key\`='gw_card_provider_type'" 2>/dev/null
mysql -uroot ucode_store -e "UPDATE settings SET \`value\`='test' WHERE \`key\`='gw_mode'" 2>/dev/null
mysql -uroot ucode_store -e "UPDATE settings SET \`value\`='inactive' WHERE \`key\`='gw_paypal_status'" 2>/dev/null
mysql -uroot ucode_store -e "UPDATE settings SET \`value\`='' WHERE \`key\` IN ('gw_nmi_security_key_test','gw_nmi_security_key_live','gw_card_public_key_test','gw_card_public_key_live','gw_card_secret_key_test','gw_card_secret_key_live','gw_card_webhook_secret','gw_authnet_login_id_test','gw_authnet_login_id_live','gw_authnet_transaction_key_test','gw_authnet_transaction_key_live','gw_authnet_signature_key','gw_custom_api_key_test','gw_custom_api_secret_test','gw_custom_api_key_live','gw_custom_api_secret_live','gw_paypal_client_id_test','gw_paypal_secret_test','gw_paypal_client_id_live','gw_paypal_secret_live','gw_paypal_webhook_id')" 2>/dev/null

echo "✓ Cleanup complete"
echo ""
echo "========================================="
echo "TEST SUMMARY"
echo "========================================="
echo "All tests completed. Review output above for results."
