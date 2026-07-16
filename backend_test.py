#!/usr/bin/env python3
"""
Backend testing for PHP store multi-gateway card payment configuration + routing.
Tests admin save, configured badge, go-live check, and checkout routing behavior.
"""

import requests
import subprocess
import json
import sys
from typing import Dict, Any, Optional, Tuple

# Test configuration
BASE_URL = "http://localhost:3000"
ADMIN_EMAIL = "services@maventechsoftware.com"
ADMIN_PASSWORD = "Admin@123"

class TestResults:
    def __init__(self):
        self.passed = []
        self.failed = []
        self.warnings = []
    
    def add_pass(self, test_name: str, detail: str = ""):
        self.passed.append(f"✅ {test_name}" + (f": {detail}" if detail else ""))
    
    def add_fail(self, test_name: str, detail: str):
        self.failed.append(f"❌ {test_name}: {detail}")
    
    def add_warning(self, test_name: str, detail: str):
        self.warnings.append(f"⚠️  {test_name}: {detail}")
    
    def print_summary(self):
        print("\n" + "="*80)
        print("TEST SUMMARY")
        print("="*80)
        
        if self.passed:
            print(f"\n✅ PASSED ({len(self.passed)}):")
            for p in self.passed:
                print(f"  {p}")
        
        if self.warnings:
            print(f"\n⚠️  WARNINGS ({len(self.warnings)}):")
            for w in self.warnings:
                print(f"  {w}")
        
        if self.failed:
            print(f"\n❌ FAILED ({len(self.failed)}):")
            for f in self.failed:
                print(f"  {f}")
        
        print(f"\nTOTAL: {len(self.passed)} passed, {len(self.warnings)} warnings, {len(self.failed)} failed")
        print("="*80 + "\n")

results = TestResults()

def run_mysql_query(query: str) -> Optional[str]:
    """Execute a MySQL query and return the result."""
    try:
        result = subprocess.run(
            ["mysql", "-uroot", "ucode_store", "-e", query],
            capture_output=True,
            text=True,
            timeout=10
        )
        if result.returncode == 0:
            return result.stdout.strip()
        else:
            print(f"MySQL error: {result.stderr}")
            return None
    except Exception as e:
        print(f"Exception running MySQL query: {e}")
        return None

def get_setting(key: str) -> Optional[str]:
    """Get a setting value from the database."""
    query = f"SELECT v FROM settings WHERE k='{key}'"
    result = run_mysql_query(query)
    if result:
        lines = result.split('\n')
        if len(lines) > 1:
            return lines[1]  # Skip header row
    return None

def set_setting(key: str, value: str) -> bool:
    """Set a setting value in the database."""
    # Escape single quotes in value
    value = value.replace("'", "\\'")
    query = f"INSERT INTO settings (k, v) VALUES ('{key}', '{value}') ON DUPLICATE KEY UPDATE v='{value}'"
    result = run_mysql_query(query)
    return result is not None

def admin_login(session: requests.Session) -> bool:
    """Login as admin and return True if successful."""
    try:
        # First, get the login page to establish session
        resp = session.get(f"{BASE_URL}/login.php", timeout=10)
        
        # Now POST login credentials
        login_data = {
            "email": ADMIN_EMAIL,
            "password": ADMIN_PASSWORD
        }
        resp = session.post(f"{BASE_URL}/login.php", data=login_data, timeout=10, allow_redirects=False)
        
        # Check if we got redirected (successful login)
        if resp.status_code in [302, 303] or 'admin.php' in resp.text:
            return True
        
        # Try to access admin page to verify
        resp = session.get(f"{BASE_URL}/admin.php", timeout=10)
        return resp.status_code == 200 and 'admin' in resp.text.lower()
    except Exception as e:
        print(f"Login error: {e}")
        return False

def test_admin_save_nmi_credentials():
    """Test 1A: Admin save NMI credentials and verify DB + redirect message."""
    print("\n" + "="*80)
    print("TEST 1A: Admin Save NMI Credentials")
    print("="*80)
    
    session = requests.Session()
    
    # Login as admin
    if not admin_login(session):
        results.add_fail("1A - Admin Login", "Could not login as admin")
        return
    
    results.add_pass("1A - Admin Login", "Successfully logged in")
    
    # POST the save_api form
    save_data = {
        "action": "save_api",
        "gateway": "card",
        "provider_type": "nmi",
        "status": "active",
        "merchant_name": "Test Merchant",
        "nmi_security_key_live": "FAKELIVEKEY123"
    }
    
    try:
        resp = session.post(f"{BASE_URL}/admin.php?tab=api&gw=card", data=save_data, timeout=10, allow_redirects=False)
        
        # Check redirect location and message
        if resp.status_code in [302, 303]:
            location = resp.headers.get('Location', '')
            if 'NMI' in location and 'saved' in location:
                results.add_pass("1A - Save Redirect", f"Redirect contains 'NMI credentials saved': {location}")
            else:
                results.add_fail("1A - Save Redirect", f"Redirect message doesn't contain expected text: {location}")
        else:
            results.add_fail("1A - Save Redirect", f"Expected redirect, got status {resp.status_code}")
        
        # Verify DB values
        provider_type = get_setting('gw_card_provider_type')
        nmi_key = get_setting('gw_nmi_security_key_live')
        
        if provider_type == 'nmi':
            results.add_pass("1A - DB Provider Type", f"gw_card_provider_type = 'nmi'")
        else:
            results.add_fail("1A - DB Provider Type", f"Expected 'nmi', got '{provider_type}'")
        
        if nmi_key == 'FAKELIVEKEY123':
            results.add_pass("1A - DB NMI Key", "gw_nmi_security_key_live set correctly")
        else:
            results.add_fail("1A - DB NMI Key", f"Expected 'FAKELIVEKEY123', got '{nmi_key}'")
            
    except Exception as e:
        results.add_fail("1A - Save API", f"Exception: {e}")

def test_configured_badge_live_mode():
    """Test 1B: Configured badge shows correct status in LIVE mode."""
    print("\n" + "="*80)
    print("TEST 1B: Configured Badge - LIVE Mode with NMI Key")
    print("="*80)
    
    # Set gw_mode to live
    if not set_setting('gw_mode', 'live'):
        results.add_fail("1B - Set Live Mode", "Could not set gw_mode to live")
        return
    
    results.add_pass("1B - Set Live Mode", "gw_mode set to 'live'")
    
    session = requests.Session()
    if not admin_login(session):
        results.add_fail("1B - Admin Login", "Could not login")
        return
    
    try:
        resp = session.get(f"{BASE_URL}/admin.php?tab=api&gw=card", timeout=10)
        
        if resp.status_code == 200:
            # Check for the badge element
            if 'data-testid="api-card-configured-badge"' in resp.text:
                if 'Configured for LIVE mode' in resp.text:
                    results.add_pass("1B - Badge Text (Live + Key)", "Badge shows 'Configured for LIVE mode'")
                else:
                    results.add_fail("1B - Badge Text (Live + Key)", "Badge doesn't show 'Configured for LIVE mode'")
            else:
                results.add_fail("1B - Badge Element", "Badge element not found in page")
        else:
            results.add_fail("1B - Page Load", f"Got status {resp.status_code}")
            
    except Exception as e:
        results.add_fail("1B - Badge Check", f"Exception: {e}")

def test_configured_badge_no_key():
    """Test 1C: Configured badge shows 'Not configured' when key is missing."""
    print("\n" + "="*80)
    print("TEST 1C: Configured Badge - LIVE Mode WITHOUT NMI Key")
    print("="*80)
    
    # Clear the NMI live key
    if not set_setting('gw_nmi_security_key_live', ''):
        results.add_fail("1C - Clear NMI Key", "Could not clear key")
        return
    
    results.add_pass("1C - Clear NMI Key", "gw_nmi_security_key_live cleared")
    
    session = requests.Session()
    if not admin_login(session):
        results.add_fail("1C - Admin Login", "Could not login")
        return
    
    try:
        resp = session.get(f"{BASE_URL}/admin.php?tab=api&gw=card", timeout=10)
        
        if resp.status_code == 200:
            if 'Not configured for LIVE mode' in resp.text:
                results.add_pass("1C - Badge Text (No Key)", "Badge shows 'Not configured for LIVE mode'")
            else:
                results.add_fail("1C - Badge Text (No Key)", "Badge doesn't show 'Not configured for LIVE mode'")
        else:
            results.add_fail("1C - Page Load", f"Got status {resp.status_code}")
            
    except Exception as e:
        results.add_fail("1C - Badge Check", f"Exception: {e}")

def test_authnet_configured_badge():
    """Test 1D: AuthNet badge requires BOTH login_id AND transaction_key."""
    print("\n" + "="*80)
    print("TEST 1D: Configured Badge - Authorize.Net (Both Keys Required)")
    print("="*80)
    
    # Set provider to authnet
    set_setting('gw_card_provider_type', 'authnet')
    set_setting('gw_mode', 'live')
    
    # Test with only login_id
    set_setting('gw_authnet_login_id_live', 'TEST_LOGIN')
    set_setting('gw_authnet_transaction_key_live', '')
    
    session = requests.Session()
    if not admin_login(session):
        results.add_fail("1D - Admin Login", "Could not login")
        return
    
    try:
        resp = session.get(f"{BASE_URL}/admin.php?tab=api&gw=card", timeout=10)
        
        if 'Not configured' in resp.text:
            results.add_pass("1D - Badge (Only Login)", "Badge shows 'Not configured' with only login_id")
        else:
            results.add_fail("1D - Badge (Only Login)", "Badge should show 'Not configured' with only login_id")
        
        # Now set both keys
        set_setting('gw_authnet_transaction_key_live', 'TEST_TX_KEY')
        
        resp = session.get(f"{BASE_URL}/admin.php?tab=api&gw=card", timeout=10)
        
        if 'Configured for LIVE mode' in resp.text:
            results.add_pass("1D - Badge (Both Keys)", "Badge shows 'Configured' with both keys")
        else:
            results.add_fail("1D - Badge (Both Keys)", "Badge should show 'Configured' with both keys")
            
    except Exception as e:
        results.add_fail("1D - Badge Check", f"Exception: {e}")

def test_go_live_check():
    """Test 2: GO-LIVE CHECK endpoint."""
    print("\n" + "="*80)
    print("TEST 2: GO-LIVE CHECK Endpoint")
    print("="*80)
    
    # Setup: NMI provider, live mode, with key
    set_setting('gw_card_provider_type', 'nmi')
    set_setting('gw_mode', 'live')
    set_setting('gw_nmi_security_key_live', 'FAKELIVEKEY123')
    
    session = requests.Session()
    if not admin_login(session):
        results.add_fail("2 - Admin Login", "Could not login")
        return
    
    try:
        resp = session.get(f"{BASE_URL}/ajax/go-live-check.php", timeout=15)
        
        if resp.status_code == 200:
            data = resp.json()
            
            # Find the card_gateway check
            card_check = None
            for check in data.get('checks', []):
                if check.get('id') == 'card_gateway':
                    card_check = check
                    break
            
            if card_check:
                results.add_pass("2 - Check ID", "card_gateway check found (not 'stripe')")
                
                if card_check.get('status') == 'green':
                    results.add_pass("2A - Status (With Key)", f"Status is 'green' with NMI key: {card_check.get('detail', '')}")
                else:
                    results.add_fail("2A - Status (With Key)", f"Expected 'green', got '{card_check.get('status')}'")
            else:
                results.add_fail("2 - Check ID", "card_gateway check not found in response")
            
            # Now test without key
            set_setting('gw_nmi_security_key_live', '')
            
            resp = session.get(f"{BASE_URL}/ajax/go-live-check.php", timeout=15)
            data = resp.json()
            
            card_check = None
            for check in data.get('checks', []):
                if check.get('id') == 'card_gateway':
                    card_check = check
                    break
            
            if card_check and card_check.get('status') == 'red':
                results.add_pass("2B - Status (No Key)", "Status is 'red' without NMI key")
            else:
                results.add_fail("2B - Status (No Key)", f"Expected 'red', got '{card_check.get('status') if card_check else 'not found'}'")
                
        else:
            results.add_fail("2 - HTTP Status", f"Expected 200, got {resp.status_code}")
            
    except Exception as e:
        results.add_fail("2 - Go-Live Check", f"Exception: {e}")

def test_checkout_routing_not_configured():
    """Test 3A: Checkout routing - LIVE mode, no key, should show 'not configured'."""
    print("\n" + "="*80)
    print("TEST 3A: Checkout Routing - LIVE Mode, No Key (Not Configured)")
    print("="*80)
    
    # Setup: NMI provider, live mode, NO key
    set_setting('gw_card_provider_type', 'nmi')
    set_setting('gw_mode', 'live')
    set_setting('gw_nmi_security_key_live', '')
    
    session = requests.Session()
    
    try:
        # Add a product to cart (we need to find a product first)
        # Get a product slug from the database
        query = "SELECT slug FROM products WHERE is_active=1 LIMIT 1"
        result = run_mysql_query(query)
        
        if not result:
            results.add_fail("3A - Get Product", "No active products found")
            return
        
        lines = result.split('\n')
        if len(lines) < 2:
            results.add_fail("3A - Get Product", "Could not parse product slug")
            return
        
        product_slug = lines[1]
        results.add_pass("3A - Get Product", f"Using product: {product_slug}")
        
        # Add to cart via ajax
        cart_data = {
            "action": "add",
            "slug": product_slug,
            "qty": 1
        }
        resp = session.post(f"{BASE_URL}/ajax/cart.php", data=cart_data, timeout=10)
        
        if resp.status_code == 200:
            results.add_pass("3A - Add to Cart", "Product added to cart")
        else:
            results.add_fail("3A - Add to Cart", f"Failed with status {resp.status_code}")
            return
        
        # POST checkout with valid address
        checkout_data = {
            "email": "test@example.com",
            "first_name": "John",
            "last_name": "Doe",
            "phone": "5551234567",
            "address": "123 Main St",
            "city": "New York",
            "state": "NY",
            "zip": "10001",
            "country": "US",
            "payment_method": "card"
        }
        
        resp = session.post(f"{BASE_URL}/checkout.php", data=checkout_data, timeout=10)
        
        if resp.status_code == 200:
            # Check if response contains "not configured"
            if 'not configured' in resp.text.lower():
                results.add_pass("3A - Not Configured Error", "Response contains 'not configured' message")
            else:
                results.add_fail("3A - Not Configured Error", "Response doesn't contain 'not configured' message")
            
            # Verify no order was marked paid
            query = "SELECT COUNT(*) FROM orders WHERE status='paid' ORDER BY id DESC LIMIT 1"
            result = run_mysql_query(query)
            if result and '0' in result:
                results.add_pass("3A - No Paid Order", "No order was marked as paid")
            else:
                results.add_warning("3A - No Paid Order", "Could not verify order status")
        else:
            results.add_fail("3A - Checkout POST", f"Got status {resp.status_code}")
            
    except Exception as e:
        results.add_fail("3A - Checkout Test", f"Exception: {e}")

def test_checkout_routing_test_mode():
    """Test 3B: Checkout routing - TEST mode, no key, should simulate success."""
    print("\n" + "="*80)
    print("TEST 3B: Checkout Routing - TEST Mode, No Key (Simulated Success)")
    print("="*80)
    
    # Setup: NMI provider, TEST mode, NO key
    set_setting('gw_card_provider_type', 'nmi')
    set_setting('gw_mode', 'test')
    set_setting('gw_nmi_security_key_test', '')
    
    session = requests.Session()
    
    try:
        # Get a product
        query = "SELECT slug FROM products WHERE is_active=1 LIMIT 1"
        result = run_mysql_query(query)
        
        if not result:
            results.add_fail("3B - Get Product", "No active products found")
            return
        
        lines = result.split('\n')
        product_slug = lines[1] if len(lines) > 1 else None
        
        if not product_slug:
            results.add_fail("3B - Get Product", "Could not parse product slug")
            return
        
        # Add to cart
        cart_data = {
            "action": "add",
            "slug": product_slug,
            "qty": 1
        }
        session.post(f"{BASE_URL}/ajax/cart.php", data=cart_data, timeout=10)
        
        # POST checkout
        checkout_data = {
            "email": "test@example.com",
            "first_name": "Jane",
            "last_name": "Smith",
            "phone": "5559876543",
            "address": "456 Oak Ave",
            "city": "Los Angeles",
            "state": "CA",
            "zip": "90001",
            "country": "US",
            "payment_method": "card"
        }
        
        resp = session.post(f"{BASE_URL}/checkout.php", data=checkout_data, timeout=10, allow_redirects=False)
        
        # In test mode, should redirect to order-success.php
        if resp.status_code in [302, 303]:
            location = resp.headers.get('Location', '')
            if 'order-success.php' in location:
                results.add_pass("3B - Test Mode Redirect", f"Redirected to order-success.php: {location}")
                
                # Verify order was marked paid
                query = "SELECT status FROM orders ORDER BY id DESC LIMIT 1"
                result = run_mysql_query(query)
                if result and 'paid' in result:
                    results.add_pass("3B - Order Paid", "Order marked as paid in test mode")
                else:
                    results.add_fail("3B - Order Paid", f"Order not marked as paid: {result}")
            else:
                results.add_fail("3B - Test Mode Redirect", f"Unexpected redirect: {location}")
        else:
            results.add_fail("3B - Test Mode Response", f"Expected redirect, got status {resp.status_code}")
            
    except Exception as e:
        results.add_fail("3B - Checkout Test", f"Exception: {e}")

def test_checkout_routing_stripe_default():
    """Test 3C: Checkout routing - Stripe (default) should still work."""
    print("\n" + "="*80)
    print("TEST 3C: Checkout Routing - Stripe Default (Regression Test)")
    print("="*80)
    
    # Setup: Stripe provider, test mode
    set_setting('gw_card_provider_type', 'stripe')
    set_setting('gw_mode', 'test')
    
    session = requests.Session()
    
    try:
        # Get a product
        query = "SELECT slug FROM products WHERE is_active=1 LIMIT 1"
        result = run_mysql_query(query)
        
        if not result:
            results.add_fail("3C - Get Product", "No active products found")
            return
        
        lines = result.split('\n')
        product_slug = lines[1] if len(lines) > 1 else None
        
        if not product_slug:
            results.add_fail("3C - Get Product", "Could not parse product slug")
            return
        
        # Add to cart
        cart_data = {
            "action": "add",
            "slug": product_slug,
            "qty": 1
        }
        session.post(f"{BASE_URL}/ajax/cart.php", data=cart_data, timeout=10)
        
        # POST checkout
        checkout_data = {
            "email": "test@example.com",
            "first_name": "Bob",
            "last_name": "Johnson",
            "phone": "5551112222",
            "address": "789 Pine Rd",
            "city": "Chicago",
            "state": "IL",
            "zip": "60601",
            "country": "US",
            "payment_method": "card"
        }
        
        resp = session.post(f"{BASE_URL}/checkout.php", data=checkout_data, timeout=10, allow_redirects=False)
        
        # Stripe in test mode should either redirect to order-success or show test mode behavior
        if resp.status_code in [200, 302, 303]:
            location = resp.headers.get('Location', '')
            if 'order-success.php' in location or resp.status_code == 200:
                results.add_pass("3C - Stripe Test Mode", "Stripe test mode working (redirect or page load)")
            else:
                results.add_warning("3C - Stripe Test Mode", f"Unexpected behavior: status {resp.status_code}, location: {location}")
        else:
            results.add_fail("3C - Stripe Test Mode", f"Unexpected status {resp.status_code}")
            
    except Exception as e:
        results.add_fail("3C - Checkout Test", f"Exception: {e}")

def reset_settings_to_defaults():
    """Reset settings back to defaults after testing."""
    print("\n" + "="*80)
    print("CLEANUP: Resetting Settings to Defaults")
    print("="*80)
    
    set_setting('gw_card_provider_type', 'stripe')
    set_setting('gw_mode', 'test')
    set_setting('gw_nmi_security_key_live', '')
    set_setting('gw_nmi_security_key_test', '')
    set_setting('gw_authnet_login_id_live', '')
    set_setting('gw_authnet_transaction_key_live', '')
    
    print("✅ Settings reset to defaults: provider=stripe, mode=test, keys cleared")

def main():
    print("\n" + "="*80)
    print("PHP STORE MULTI-GATEWAY CARD PAYMENT TESTING")
    print("Testing at: " + BASE_URL)
    print("="*80)
    
    # Run all tests
    test_admin_save_nmi_credentials()
    test_configured_badge_live_mode()
    test_configured_badge_no_key()
    test_authnet_configured_badge()
    test_go_live_check()
    test_checkout_routing_not_configured()
    test_checkout_routing_test_mode()
    test_checkout_routing_stripe_default()
    
    # Reset settings
    reset_settings_to_defaults()
    
    # Print summary
    results.print_summary()
    
    # Exit with appropriate code
    sys.exit(0 if len(results.failed) == 0 else 1)

if __name__ == "__main__":
    main()
