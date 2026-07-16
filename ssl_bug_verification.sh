#!/bin/bash
# SSL Bug Fix Verification Script
# Tests the canonical host redirect fix for NET::ERR_CERT_COMMON_NAME_INVALID

echo "=========================================="
echo "SSL BUG FIX VERIFICATION"
echo "=========================================="
echo ""

# (a) EMERGENT PREVIEW UNCHANGED
echo "(a) EMERGENT PREVIEW UNCHANGED"
echo "-------------------------------------------"
echo "Test 1: External preview URL (HTTPS)"
curl -sIL https://stripe-paypal-update.preview.emergentagent.com/ | head -20
echo ""
echo "Test 2: Internal curl with preview Host header"
curl -si -H "Host: 58485f15-d8bc-415a-9027-8cd21a31434f.preview.emergentagent.com" http://127.0.0.1:3000/ | head -20
echo ""

# (b) NEW DEFAULT DIRECTION — www → naked when HTTPS is present
echo "(b) NEW DEFAULT DIRECTION — www → naked when HTTPS"
echo "-------------------------------------------"
echo "Test 1: www.maventechsoftware.com with X-Forwarded-Proto: https"
curl -si -H "Host: www.maventechsoftware.com" -H "X-Forwarded-Proto: https" http://127.0.0.1:3000/ | head -20
echo ""
echo "Test 2: maventechsoftware.com (naked) with X-Forwarded-Proto: https"
curl -si -H "Host: maventechsoftware.com" -H "X-Forwarded-Proto: https" http://127.0.0.1:3000/ | head -20
echo ""

# (c) NO HTTP → HTTPS SCHEME COERCION
echo "(c) NO HTTP → HTTPS SCHEME COERCION"
echo "-------------------------------------------"
echo "Test: www.maventechsoftware.com WITHOUT X-Forwarded-Proto (plain HTTP)"
curl -si -H "Host: www.maventechsoftware.com" http://127.0.0.1:3000/ | head -20
echo ""

# (d) LOCALHOST + IP BYPASSES
echo "(d) LOCALHOST + IP BYPASSES"
echo "-------------------------------------------"
echo "Test 1: localhost"
curl -si -H "Host: localhost" http://127.0.0.1:3000/ | head -20
echo ""
echo "Test 2: 127.0.0.1"
curl -si -H "Host: 127.0.0.1" http://127.0.0.1:3000/ | head -20
echo ""

# (g) DB UNCHANGED
echo "(g) DB UNCHANGED"
echo "-------------------------------------------"
mysql -uroot ucode_store -e "SELECT COUNT(*) as products_count FROM products; SELECT COUNT(*) as orders_count FROM orders; SELECT COUNT(*) as settings_count FROM settings; SELECT k,v FROM settings WHERE k='seo_canonical_host_pref';"
echo ""

# (h) REGRESSION SPOT-CHECKS
echo "(h) REGRESSION SPOT-CHECKS"
echo "-------------------------------------------"
echo "Test 1: Homepage"
curl -so /dev/null -w "HTTP Code: %{http_code}, Size: %{size_download} bytes\n" http://127.0.0.1:3000/
echo ""
echo "Test 2: Product page (microsoft-office-2024-professional-plus-windows)"
curl -so /dev/null -w "HTTP Code: %{http_code}, Size: %{size_download} bytes\n" "http://127.0.0.1:3000/product.php?slug=microsoft-office-2024-professional-plus-windows"
echo ""

echo "=========================================="
echo "VERIFICATION COMPLETE"
echo "=========================================="
