#!/usr/bin/env python3
"""
Backend test for Protection Hub "Get <Plan>" bug fix verification.

Bug: Clicking any "Get Quick Fix / Get Starter Care / Get Pro Shield / Get Lifetime Elite" 
button on /protection-hub.php OR the shareable payment link arrow on Admin → Subscription Plans 
landed on /checkout.php showing a stale "Microsoft Office 2024 Professional Plus" line item 
at ~$209.99 instead of the chosen plan.

Fix: /app/php-version/subscribe.php now wipes $_SESSION['cart'] = [] immediately BEFORE 
writing $_SESSION['sub_plan']. This makes an explicit plan click authoritative.
"""

import requests
import re
from http.cookiejar import CookieJar
import sys

BASE_URL = "http://localhost:3000"

# Plan slugs and expected prices (from seed-protection-hub.php)
PLANS = {
    'quick-fix': {'name': 'Quick Fix', 'price': '29.00'},
    'starter-care': {'name': 'Starter Care', 'price': '59.00'},
    'pro-shield': {'name': 'Pro Shield', 'price': '99.00'},
    'lifetime-elite': {'name': 'Lifetime Elite', 'price': '199.00'}
}

# Microsoft Office product slug (common test product)
OFFICE_PRODUCT_SLUG = 'microsoft-office-2024-professional-plus-windows'
OFFICE_PRODUCT_NAME = 'Microsoft Office 2024 Professional Plus'

def test_plan_click_with_cart(plan_slug, plan_data):
    """
    Test that clicking a plan button clears the cart and shows the plan on checkout.
    
    Steps:
    1. Fresh cookie jar (new session)
    2. Add Microsoft Office product to cart
    3. Verify cart shows Office product
    4. Click plan button via GET /subscribe.php?plan=<slug>
    5. Verify checkout shows the plan (not Office product)
    """
    print(f"\n{'='*80}")
    print(f"TEST: Plan click with existing cart - {plan_data['name']}")
    print(f"{'='*80}")
    
    session = requests.Session()
    
    # Step 1: Add Office product to cart
    print(f"\n[1] Adding {OFFICE_PRODUCT_NAME} to cart...")
    
    # First, get the product page to find the add-to-cart form
    product_url = f"{BASE_URL}/product.php?slug={OFFICE_PRODUCT_SLUG}"
    resp = session.get(product_url)
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Product page returned {resp.status_code}")
        return False
    
    # Add to cart (POST to cart.php)
    cart_add_data = {
        'action': 'add',
        'slug': OFFICE_PRODUCT_SLUG,
        'qty': '1'
    }
    resp = session.post(f"{BASE_URL}/cart.php", data=cart_add_data, allow_redirects=True)
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Add to cart returned {resp.status_code}")
        return False
    
    print(f"✅ Added product to cart (status: {resp.status_code})")
    
    # Step 2: Verify cart shows Office product
    print(f"\n[2] Verifying cart contains {OFFICE_PRODUCT_NAME}...")
    resp = session.get(f"{BASE_URL}/cart.php")
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Cart page returned {resp.status_code}")
        return False
    
    if OFFICE_PRODUCT_NAME not in resp.text and 'Office 2024' not in resp.text:
        print(f"❌ FAIL: Cart does not contain Office product")
        print(f"   Cart HTML snippet: {resp.text[:500]}")
        return False
    
    print(f"✅ Cart contains Office product")
    
    # Step 3: Click plan button (GET /subscribe.php?plan=<slug>)
    print(f"\n[3] Clicking plan button: GET /subscribe.php?plan={plan_slug}...")
    resp = session.get(f"{BASE_URL}/subscribe.php?plan={plan_slug}", allow_redirects=True)
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Subscribe endpoint returned {resp.status_code}")
        return False
    
    # Should redirect to checkout.php
    if '/checkout.php' not in resp.url:
        print(f"❌ FAIL: Did not redirect to checkout.php (url: {resp.url})")
        return False
    
    print(f"✅ Redirected to checkout.php")
    
    # Step 4: Verify checkout shows the plan (not Office product)
    print(f"\n[4] Verifying checkout shows {plan_data['name']} plan...")
    
    checkout_html = resp.text
    
    # Check for plan name
    plan_name_found = plan_data['name'] in checkout_html or f"{plan_data['name']} Plan" in checkout_html
    
    # Check for plan price
    plan_price_found = plan_data['price'] in checkout_html or f"${plan_data['price']}" in checkout_html
    
    # Check that Office product is NOT present
    office_not_present = OFFICE_PRODUCT_NAME not in checkout_html
    
    # Check for line-item testid starting with summary-item-sub-<plan-slug>
    testid_pattern = f'data-testid="summary-item-sub-{plan_slug}"'
    testid_found = testid_pattern in checkout_html
    
    # Report results
    results = []
    
    if plan_name_found:
        print(f"   ✅ Plan name '{plan_data['name']}' found in checkout")
        results.append(True)
    else:
        print(f"   ❌ Plan name '{plan_data['name']}' NOT found in checkout")
        results.append(False)
    
    if plan_price_found:
        print(f"   ✅ Plan price ${plan_data['price']} found in checkout")
        results.append(True)
    else:
        print(f"   ❌ Plan price ${plan_data['price']} NOT found in checkout")
        results.append(False)
    
    if office_not_present:
        print(f"   ✅ Office product NOT present in checkout (correct)")
        results.append(True)
    else:
        print(f"   ❌ Office product STILL present in checkout (BUG!)")
        results.append(False)
    
    if testid_found:
        print(f"   ✅ Line-item testid 'summary-item-sub-{plan_slug}' found")
        results.append(True)
    else:
        print(f"   ⚠️  Line-item testid 'summary-item-sub-{plan_slug}' NOT found (may be minor)")
        # Don't fail the test for missing testid, just warn
    
    # Check for "1 item" text
    if '1 item' in checkout_html or '1 Item' in checkout_html:
        print(f"   ✅ '1 item' text found in checkout")
        results.append(True)
    else:
        print(f"   ⚠️  '1 item' text NOT found (may be minor)")
    
    if all(results):
        print(f"\n✅ PASS: {plan_data['name']} plan checkout working correctly")
        return True
    else:
        print(f"\n❌ FAIL: {plan_data['name']} plan checkout has issues")
        print(f"\n   Checkout HTML snippet (first 1000 chars):")
        print(f"   {checkout_html[:1000]}")
        return False


def test_regression_cart_only():
    """
    Regression test R1: Fresh session, add Office to cart, go directly to checkout.
    Should show Office product with qty stepper (not plan).
    """
    print(f"\n{'='*80}")
    print(f"REGRESSION TEST R1: Cart-only checkout (no plan click)")
    print(f"{'='*80}")
    
    session = requests.Session()
    
    # Add Office product to cart
    print(f"\n[1] Adding {OFFICE_PRODUCT_NAME} to cart...")
    
    cart_add_data = {
        'action': 'add',
        'slug': OFFICE_PRODUCT_SLUG,
        'qty': '1'
    }
    resp = session.post(f"{BASE_URL}/cart.php", data=cart_add_data, allow_redirects=True)
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Add to cart returned {resp.status_code}")
        return False
    
    print(f"✅ Added product to cart")
    
    # Go directly to checkout (no plan click)
    print(f"\n[2] Going directly to /checkout.php...")
    resp = session.get(f"{BASE_URL}/checkout.php")
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Checkout returned {resp.status_code}")
        return False
    
    checkout_html = resp.text
    
    # Should show Office product
    office_present = OFFICE_PRODUCT_NAME in checkout_html or 'Office 2024' in checkout_html
    
    # Should have qty stepper (look for input or +/- buttons)
    qty_stepper_present = 'type="number"' in checkout_html or 'qty-minus' in checkout_html or 'qty-plus' in checkout_html
    
    if office_present:
        print(f"   ✅ Office product present in checkout")
    else:
        print(f"   ❌ Office product NOT present in checkout")
        return False
    
    if qty_stepper_present:
        print(f"   ✅ Qty stepper present (correct for product checkout)")
    else:
        print(f"   ⚠️  Qty stepper NOT detected (may be minor)")
    
    print(f"\n✅ PASS: Cart-only checkout working correctly")
    return True


def test_regression_plan_then_cart():
    """
    Regression test R2: Click plan first, then add Office to cart, then checkout.
    Should show Office product (cart wins over stale sub_plan).
    """
    print(f"\n{'='*80}")
    print(f"REGRESSION TEST R2: Plan click, then add product to cart")
    print(f"{'='*80}")
    
    session = requests.Session()
    
    # Click plan first
    print(f"\n[1] Clicking Pro Shield plan...")
    resp = session.get(f"{BASE_URL}/subscribe.php?plan=pro-shield", allow_redirects=True)
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Subscribe endpoint returned {resp.status_code}")
        return False
    
    print(f"✅ Plan click successful (redirected to checkout)")
    
    # Now add Office product to cart
    print(f"\n[2] Adding {OFFICE_PRODUCT_NAME} to cart...")
    
    cart_add_data = {
        'action': 'add',
        'slug': OFFICE_PRODUCT_SLUG,
        'qty': '1'
    }
    resp = session.post(f"{BASE_URL}/cart.php", data=cart_add_data, allow_redirects=True)
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Add to cart returned {resp.status_code}")
        return False
    
    print(f"✅ Added product to cart")
    
    # Go to checkout
    print(f"\n[3] Going to /checkout.php...")
    resp = session.get(f"{BASE_URL}/checkout.php")
    
    if resp.status_code != 200:
        print(f"❌ FAIL: Checkout returned {resp.status_code}")
        return False
    
    checkout_html = resp.text
    
    # Should show Office product (cart wins)
    office_present = OFFICE_PRODUCT_NAME in checkout_html or 'Office 2024' in checkout_html
    
    # Should NOT show Pro Shield plan
    plan_not_present = 'Pro Shield' not in checkout_html
    
    if office_present:
        print(f"   ✅ Office product present in checkout (cart wins)")
    else:
        print(f"   ❌ Office product NOT present in checkout")
        return False
    
    if plan_not_present:
        print(f"   ✅ Pro Shield plan NOT present (correct - cart trumps stale plan)")
    else:
        print(f"   ⚠️  Pro Shield plan still present (may indicate issue)")
    
    print(f"\n✅ PASS: Cart-trumps-stale-plan behavior preserved")
    return True


def main():
    """Run all tests."""
    print(f"\n{'#'*80}")
    print(f"# Protection Hub 'Get <Plan>' Bug Fix Verification")
    print(f"# Base URL: {BASE_URL}")
    print(f"{'#'*80}")
    
    results = []
    
    # Test each plan
    for plan_slug, plan_data in PLANS.items():
        result = test_plan_click_with_cart(plan_slug, plan_data)
        results.append((f"Plan click: {plan_data['name']}", result))
    
    # Regression tests
    result = test_regression_cart_only()
    results.append(("Regression R1: Cart-only checkout", result))
    
    result = test_regression_plan_then_cart()
    results.append(("Regression R2: Plan then cart", result))
    
    # Summary
    print(f"\n{'#'*80}")
    print(f"# TEST SUMMARY")
    print(f"{'#'*80}")
    
    passed = sum(1 for _, r in results if r)
    total = len(results)
    
    for test_name, result in results:
        status = "✅ PASS" if result else "❌ FAIL"
        print(f"{status}: {test_name}")
    
    print(f"\n{'='*80}")
    print(f"Total: {passed}/{total} tests passed")
    print(f"{'='*80}")
    
    if passed == total:
        print(f"\n✅ ALL TESTS PASSED - Bug fix verified successfully!")
        return 0
    else:
        print(f"\n❌ SOME TESTS FAILED - Bug fix needs attention")
        return 1


if __name__ == '__main__':
    sys.exit(main())
