#!/bin/bash
COOKIE_JAR="/tmp/admin_cookies.txt"
BASE_URL="http://localhost:3000"

echo "=== BUG 2 DETAILED VERIFICATION ==="
echo ""

# Authenticate
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" \
  -X POST "$BASE_URL/login.php" \
  -d "email=services@maventechsoftware.com&password=Admin@123" \
  -L -o /dev/null 2>&1

# Set NMI key
echo "Setting NMI live key in database..."
mysql -uroot ucode_store -e "INSERT INTO settings (k, v) VALUES ('gw_nmi_security_key_live', 'NMIKEY123SECRETABCDEF') ON DUPLICATE KEY UPDATE v='NMIKEY123SECRETABCDEF'"

# Load API page
echo "Loading API page..."
curl -sS -c "$COOKIE_JAR" -b "$COOKIE_JAR" "$BASE_URL/admin.php?tab=api&gw=card" > /tmp/api_test.html 2>&1

echo ""
echo "Checking for Security Key (live) field..."
grep -n "Security Key (live)" /tmp/api_test.html | head -3

echo ""
echo "Checking for saved-key-hint..."
grep -n "saved-key-hint" /tmp/api_test.html | head -5

echo ""
echo "Extracting hint content..."
grep "saved-key-hint" /tmp/api_test.html | sed 's/^[[:space:]]*//' | head -1

echo ""
echo "Checking DOM order around line 2511..."
sed -n '2509,2515p' /tmp/api_test.html

# Cleanup
mysql -uroot ucode_store -e "DELETE FROM settings WHERE k='gw_nmi_security_key_live'"
