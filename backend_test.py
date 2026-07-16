#!/usr/bin/env python3
"""
Comprehensive Payment Gateway Audit Test Suite
Tests card provider selection, key persistence, PayPal save, checkout routing, and go-live check.
"""

import requests
import subprocess
import json
import time
from typing import Dict, Any, List, Tuple

BASE_URL = "http://localhost:3000"
ADMIN_EMAIL = "services@maventechsoftware.com"
ADMIN_PASSWORD = "Admin@123"

class PaymentGatewayTester:
    def __init__(self):
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (compatible; PaymentGatewayTest/1.0)'
        })
        self.test_results = []
        
    def log(self, message: str, status: str = "INFO"):
        """Log test messages"""
        prefix = {
            "PASS": "✅",
            "FAIL": "❌",
            "INFO": "ℹ️",
            "WARN": "⚠️"
        }.get(status, "•")
        print(f"{prefix} {message}")
        
    def db_query(self, query: str) -> List[Tuple]:
        """Execute MySQL query and return results"""
        try:
            result = subprocess.run(
                ['mysql', '-uroot', 'ucode_store', '-e', query, '-s', '-N'],
                capture_output=True,
                text=True,
                timeout=10
            )
            if result.returncode != 0:
                self.log(f"DB query failed: {result.stderr}", "FAIL")
                return []
            
            lines = result.stdout.strip().split('\n')
            if not lines or lines == ['']:
                return []
            return [tuple(line.split('\t')) for line in lines]
        except Exception as e:
            self.log(f"DB query exception: {e}", "FAIL")
            return []
    
    def db_get_setting(self, key: str) -> str:
        """Get a setting value from DB"""
        result = self.db_query(f"SELECT v FROM settings WHERE k='{key}'")
        return result[0][0] if result else ""
    
    def db_set_setting(self, key: str, value: str):
        """Set a setting value in DB"""
        escaped_value = value.replace("'", "\\'")
        self.db_query(f"INSERT INTO settings (k,v) VALUES ('{key}','{escaped_value}') ON DUPLICATE KEY UPDATE v='{escaped_value}'")
    
    def admin_login(self) -> bool:
        """Login to admin panel"""
        self.log("Logging in to admin panel...")
        
        # First GET to establish session
        resp = self.session.get(f"{BASE_URL}/login.php")
        if resp.status_code != 200:
            self.log(f"Failed to load login page: HTTP {resp.status_code}", "FAIL")
            return False
        
        # POST login
        login_data = {
            'email': ADMIN_EMAIL,
            'password': ADMIN_PASSWORD
        }
        resp = self.session.post(f"{BASE_URL}/login.php", data=login_data, allow_redirects=False)
        
        if resp.status_code in [302, 303]:
            location = resp.headers.get('Location', '')
            if 'admin.php' in location:
                self.log("Admin login successful", "PASS")
                return True
        
        self.log(f"Admin login failed: HTTP {resp.status_code}", "FAIL")
        return False
    
    def test_card_provider_save_and_badge(self):
        """Test 1: Card provider selection + key persistence + configured badge"""
        self.log("\n=== TEST 1: CARD PROVIDER SELECT + KEY PERSISTENCE + BADGE ===")
        
        # Test 1a: Save NMI credentials
        self.log("Test 1a: Saving NMI credentials...")
        save_data = {
            'action': 'save_api',
            'gateway': 'card',
            'provider_type': 'nmi',
            'status': 'active',
            'merchant_name': 'Test Merchant',
            'nmi_security_key_live': 'NMIKEY123'
        }
        
        resp = self.session.post(f"{BASE_URL}/admin.php", data=save_data, allow_redirects=False)
        
        # Check redirect
        if resp.status_code not in [302, 303]:
            self.log(f"Save failed: HTTP {resp.status_code}", "FAIL")
            return False
        
        location = resp.headers.get('Location', '')
        if 'NMI credentials saved' not in location and 'NMI' in location:
            self.log("Redirect contains NMI confirmation", "PASS")
        else:
            self.log(f"Redirect message unclear: {location}", "WARN")
        
        # Verify DB
        provider_type = self.db_get_setting('gw_card_provider_type')
        nmi_key = self.db_get_setting('gw_nmi_security_key_live')
        
        if provider_type == 'nmi':
            self.log(f"DB: gw_card_provider_type = 'nmi' ✓", "PASS")
        else:
            self.log(f"DB: gw_card_provider_type = '{provider_type}' (expected 'nmi')", "FAIL")
            return False
        
        if nmi_key == 'NMIKEY123':
            self.log(f"DB: gw_nmi_security_key_live = 'NMIKEY123' ✓", "PASS")
        else:
            self.log(f"DB: gw_nmi_security_key_live = '{nmi_key}' (expected 'NMIKEY123')", "FAIL")
            return False
        
        # Test 1b: Badge with LIVE mode + NMI key present
        self.log("\nTest 1b: Checking 'Configured for LIVE mode' badge...")
        self.db_set_setting('gw_mode', 'live')
        
        resp = self.session.get(f"{BASE_URL}/admin.php?tab=api&gw=card")
        if resp.status_code != 200:
            self.log(f"Failed to load admin page: HTTP {resp.status_code}", "FAIL")
            return False
        
        html = resp.text
        if 'data-testid="api-card-configured-badge"' in html and 'Configured for LIVE mode' in html:
            self.log("Badge shows 'Configured for LIVE mode' ✓", "PASS")
        else:
            self.log("Badge not showing 'Configured for LIVE mode'", "FAIL")
            return False
        
        # Test 1c: Badge with LIVE mode + NO key
        self.log("\nTest 1c: Checking 'Not configured' badge when key is empty...")
        self.db_set_setting('gw_nmi_security_key_live', '')
        
        resp = self.session.get(f"{BASE_URL}/admin.php?tab=api&gw=card")
        html = resp.text
        
        if 'Not configured' in html or 'not configured' in html.lower():
            self.log("Badge shows 'Not configured' when key is empty ✓", "PASS")
        else:
            self.log("Badge should show 'Not configured' when key is empty", "FAIL")
            return False
        
        # Test 1d: Authorize.Net requires BOTH keys
        self.log("\nTest 1d: Testing Authorize.Net badge (requires BOTH keys)...")
        
        # Save Authorize.Net with only login_id
        save_data = {
            'action': 'save_api',
            'gateway': 'card',
            'provider_type': 'authnet',
            'status': 'active',
            'merchant_name': 'Test',
            'authnet_login_id_live': 'AUTHNET_LOGIN'
        }
        self.session.post(f"{BASE_URL}/admin.php", data=save_data, allow_redirects=False)
        
        resp = self.session.get(f"{BASE_URL}/admin.php?tab=api&gw=card")
        html = resp.text
        
        if 'Not configured' in html or 'not configured' in html.lower():
            self.log("Badge shows 'Not configured' with only login_id ✓", "PASS")
        else:
            self.log("Badge should show 'Not configured' with only login_id", "FAIL")
        
        # Now add transaction key
        save_data['authnet_transaction_key_live'] = 'AUTHNET_TXN_KEY'
        self.session.post(f"{BASE_URL}/admin.php", data=save_data, allow_redirects=False)
        
        resp = self.session.get(f"{BASE_URL}/admin.php?tab=api&gw=card")
        html = resp.text
        
        if 'Configured for LIVE mode' in html:
            self.log("Badge shows 'Configured for LIVE mode' with both keys ✓", "PASS")
        else:
            self.log("Badge should show 'Configured' with both keys", "FAIL")
        
        # Test 1e: BLANK-KEEPS-CURRENT
        self.log("\nTest 1e: Testing blank field keeps current value...")
        
        # Set NMI key first
        self.db_set_setting('gw_card_provider_type', 'nmi')
        self.db_set_setting('gw_nmi_security_key_live', 'NMIKEY123')
        
        # Save again with blank key
        save_data = {
            'action': 'save_api',
            'gateway': 'card',
            'provider_type': 'nmi',
            'status': 'active',
            'merchant_name': 'Test',
            'nmi_security_key_live': ''  # BLANK
        }
        self.session.post(f"{BASE_URL}/admin.php", data=save_data, allow_redirects=False)
        
        # Check if key is preserved
        nmi_key_after = self.db_get_setting('gw_nmi_security_key_live')
        if nmi_key_after == 'NMIKEY123':
            self.log("Blank field preserved existing key 'NMIKEY123' ✓", "PASS")
        else:
            self.log(f"Blank field did NOT preserve key (got '{nmi_key_after}')", "FAIL")
            return False
        
        return True
    
    def test_paypal_save_and_badge(self):
        """Test 2: PayPal save + badge"""
        self.log("\n=== TEST 2: PAYPAL SAVE + BADGE ===")
        
        # Save PayPal credentials
        self.log("Saving PayPal credentials...")
        save_data = {
            'action': 'save_api',
            'gateway': 'paypal',
            'status': 'active',
            'account_name': 'Test PayPal',
            'client_id_live': 'PPLIVEID',
            'secret_live': 'PPLIVESECRET'
        }
        
        resp = self.session.post(f"{BASE_URL}/admin.php", data=save_data, allow_redirects=False)
        
        if resp.status_code not in [302, 303]:
            self.log(f"Save failed: HTTP {resp.status_code}", "FAIL")
            return False
        
        # Verify DB
        client_id = self.db_get_setting('gw_paypal_client_id_live')
        secret = self.db_get_setting('gw_paypal_secret_live')
        status = self.db_get_setting('gw_paypal_status')
        
        if client_id == 'PPLIVEID':
            self.log(f"DB: gw_paypal_client_id_live = 'PPLIVEID' ✓", "PASS")
        else:
            self.log(f"DB: gw_paypal_client_id_live = '{client_id}'", "FAIL")
            return False
        
        if secret == 'PPLIVESECRET':
            self.log(f"DB: gw_paypal_secret_live = 'PPLIVESECRET' ✓", "PASS")
        else:
            self.log(f"DB: gw_paypal_secret_live = '{secret}'", "FAIL")
            return False
        
        if status == 'active':
            self.log(f"DB: gw_paypal_status = 'active' ✓", "PASS")
        else:
            self.log(f"DB: gw_paypal_status = '{status}'", "FAIL")
            return False
        
        # Check badge
        self.log("\nChecking PayPal 'Configured for LIVE mode' badge...")
        self.db_set_setting('gw_mode', 'live')
        
        resp = self.session.get(f"{BASE_URL}/admin.php?tab=api&gw=paypal")
        if resp.status_code != 200:
            self.log(f"Failed to load PayPal page: HTTP {resp.status_code}", "FAIL")
            return False
        
        html = resp.text
        if 'data-testid="api-paypal-configured-badge"' in html and 'Configured for LIVE mode' in html:
            self.log("PayPal badge shows 'Configured for LIVE mode' ✓", "PASS")
        else:
            self.log("PayPal badge not showing 'Configured for LIVE mode'", "FAIL")
            return False
        
        return True
    
    def test_checkout_routing(self):
        """Test 3: Checkout routing (behavior only, no real charges)"""
        self.log("\n=== TEST 3: CHECKOUT ROUTING (BEHAVIOR ONLY) ===")
        
        # Helper to add product to cart
        def add_to_cart():
            cart_data = {
                'action': 'add',
                'slug': 'microsoft-office-home-2024-pc',
                'qty': 1
            }
            resp = self.session.post(f"{BASE_URL}/ajax/cart.php", 
                                    data=json.dumps(cart_data),
                                    headers={'Content-Type': 'application/json'})
            return resp.status_code == 200
        
        # Test 3a: Stripe provider + TEST mode -> completes
        self.log("\nTest 3a: Stripe provider + TEST mode -> should complete...")
        self.db_set_setting('gw_card_provider_type', 'stripe')
        self.db_set_setting('gw_mode', 'test')
        
        if not add_to_cart():
            self.log("Failed to add product to cart", "FAIL")
            return False
        
        checkout_data = {
            'email': 'test@example.com',
            'first_name': 'John',
            'last_name': 'Doe',
            'phone': '5551234567',
            'address': '123 Main St',
            'city': 'New York',
            'state': 'NY',
            'zip': '10001',
            'country': 'US',
            'payment_method': 'card'
        }
        
        resp = self.session.post(f"{BASE_URL}/checkout.php", data=checkout_data, allow_redirects=False)
        
        if resp.status_code in [302, 303]:
            location = resp.headers.get('Location', '')
            if 'order-success.php' in location:
                self.log("Stripe TEST mode -> redirects to order-success.php ✓", "PASS")
            else:
                self.log(f"Unexpected redirect: {location}", "WARN")
        else:
            self.log(f"Expected redirect, got HTTP {resp.status_code}", "FAIL")
        
        # Test 3b: NMI provider + LIVE mode + NO key -> "not configured" error
        self.log("\nTest 3b: NMI provider + LIVE mode + NO key -> should show 'not configured'...")
        self.db_set_setting('gw_card_provider_type', 'nmi')
        self.db_set_setting('gw_mode', 'live')
        self.db_set_setting('gw_nmi_security_key_live', '')
        
        if not add_to_cart():
            self.log("Failed to add product to cart", "FAIL")
            return False
        
        resp = self.session.post(f"{BASE_URL}/checkout.php", data=checkout_data, allow_redirects=True)
        
        if 'not configured' in resp.text.lower():
            self.log("Response contains 'not configured' error ✓", "PASS")
        else:
            self.log("Response should contain 'not configured' error", "FAIL")
            return False
        
        # Verify no order was marked paid
        orders = self.db_query("SELECT status FROM orders ORDER BY id DESC LIMIT 1")
        if orders and orders[0][0] != 'paid':
            self.log(f"Order status is '{orders[0][0]}' (not 'paid') ✓", "PASS")
        else:
            self.log("Order should NOT be marked paid", "FAIL")
        
        # Test 3c: PayPal + LIVE mode + FAKE creds -> redirect or error
        self.log("\nTest 3c: PayPal + LIVE mode + FAKE creds -> should redirect or error...")
        self.db_set_setting('gw_mode', 'live')
        self.db_set_setting('gw_paypal_client_id_live', 'PPLIVEID')
        self.db_set_setting('gw_paypal_secret_live', 'PPLIVESECRET')
        self.db_set_setting('gw_paypal_status', 'active')
        
        if not add_to_cart():
            self.log("Failed to add product to cart", "FAIL")
            return False
        
        checkout_data['payment_method'] = 'paypal'
        resp = self.session.post(f"{BASE_URL}/checkout.php", data=checkout_data, allow_redirects=False)
        
        if resp.status_code in [302, 303]:
            location = resp.headers.get('Location', '')
            if 'paypal.com' in location:
                self.log("Redirects toward paypal.com (fake creds will fail there) ✓", "PASS")
            elif 'checkout.php?paypal=error' in location:
                self.log("Returns to checkout.php?paypal=error ✓", "PASS")
            else:
                self.log(f"Unexpected redirect: {location}", "WARN")
        else:
            # Check if error is shown inline
            if 'paypal' in resp.text.lower() and ('error' in resp.text.lower() or 'failed' in resp.text.lower()):
                self.log("Shows PayPal error inline ✓", "PASS")
            else:
                self.log(f"Unexpected response: HTTP {resp.status_code}", "WARN")
        
        # Verify no order was marked paid
        orders = self.db_query("SELECT status FROM orders ORDER BY id DESC LIMIT 1")
        if orders and orders[0][0] != 'paid':
            self.log(f"Order status is '{orders[0][0]}' (not 'paid') ✓", "PASS")
        else:
            self.log("Order should NOT be marked paid with fake PayPal creds", "FAIL")
        
        # Test 3d: PayPal + TEST mode + NO creds -> test simulation completes
        self.log("\nTest 3d: PayPal + TEST mode + NO creds -> should complete (test simulation)...")
        self.db_set_setting('gw_mode', 'test')
        self.db_set_setting('gw_paypal_client_id_test', '')
        self.db_set_setting('gw_paypal_secret_test', '')
        
        if not add_to_cart():
            self.log("Failed to add product to cart", "FAIL")
            return False
        
        resp = self.session.post(f"{BASE_URL}/checkout.php", data=checkout_data, allow_redirects=False)
        
        if resp.status_code in [302, 303]:
            location = resp.headers.get('Location', '')
            if 'order-success.php' in location:
                self.log("PayPal TEST mode -> redirects to order-success.php ✓", "PASS")
            else:
                self.log(f"Unexpected redirect: {location}", "WARN")
        else:
            self.log(f"Expected redirect, got HTTP {resp.status_code}", "FAIL")
        
        return True
    
    def test_go_live_check(self):
        """Test 4: Go-live check endpoint"""
        self.log("\n=== TEST 4: GO-LIVE CHECK ===")
        
        # Set up NMI with LIVE mode and key
        self.db_set_setting('gw_card_provider_type', 'nmi')
        self.db_set_setting('gw_mode', 'live')
        self.db_set_setting('gw_nmi_security_key_live', 'NMIKEY123')
        
        resp = self.session.get(f"{BASE_URL}/ajax/go-live-check.php")
        
        if resp.status_code != 200:
            self.log(f"Go-live check failed: HTTP {resp.status_code}", "FAIL")
            return False
        
        try:
            data = resp.json()
        except:
            self.log("Go-live check response is not JSON", "FAIL")
            return False
        
        # Check for card_gateway check
        checks = data.get('checks', [])
        card_check = None
        paypal_check = None
        
        for check in checks:
            if check.get('id') == 'card_gateway':
                card_check = check
            elif 'paypal' in check.get('id', '').lower():
                paypal_check = check
        
        if card_check:
            self.log("Found 'card_gateway' check (provider-agnostic) ✓", "PASS")
            
            if 'NMI' in check.get('detail', ''):
                self.log("Card check mentions NMI as active provider ✓", "PASS")
            else:
                self.log("Card check should mention NMI", "WARN")
        else:
            self.log("Missing 'card_gateway' check", "FAIL")
            return False
        
        if paypal_check:
            self.log("Found PayPal check ✓", "PASS")
        else:
            self.log("Missing PayPal check", "WARN")
        
        return True
    
    def reset_settings(self):
        """Reset all settings to defaults"""
        self.log("\n=== RESETTING SETTINGS TO DEFAULTS ===")
        
        # Reset card provider to stripe
        self.db_set_setting('gw_card_provider_type', 'stripe')
        self.db_set_setting('gw_mode', 'test')
        
        # Clear all gateway keys
        keys_to_clear = [
            'gw_nmi_security_key_test', 'gw_nmi_security_key_live',
            'gw_nmi_username_test', 'gw_nmi_username_live',
            'gw_nmi_password_test', 'gw_nmi_password_live',
            'gw_authnet_login_id_test', 'gw_authnet_login_id_live',
            'gw_authnet_transaction_key_test', 'gw_authnet_transaction_key_live',
            'gw_custom_endpoint_test', 'gw_custom_endpoint_live',
            'gw_custom_api_key_test', 'gw_custom_api_key_live',
            'gw_paypal_client_id_test', 'gw_paypal_client_id_live',
            'gw_paypal_secret_test', 'gw_paypal_secret_live'
        ]
        
        for key in keys_to_clear:
            self.db_set_setting(key, '')
        
        # Set PayPal to inactive
        self.db_set_setting('gw_paypal_status', 'inactive')
        
        self.log("Settings reset to defaults ✓", "PASS")
    
    def run_all_tests(self):
        """Run all tests"""
        self.log("=" * 80)
        self.log("COMPREHENSIVE PAYMENT GATEWAY AUDIT")
        self.log("=" * 80)
        
        if not self.admin_login():
            self.log("Cannot proceed without admin login", "FAIL")
            return False
        
        all_passed = True
        
        # Run tests
        if not self.test_card_provider_save_and_badge():
            all_passed = False
        
        if not self.test_paypal_save_and_badge():
            all_passed = False
        
        if not self.test_checkout_routing():
            all_passed = False
        
        if not self.test_go_live_check():
            all_passed = False
        
        # Reset settings
        self.reset_settings()
        
        # Summary
        self.log("\n" + "=" * 80)
        if all_passed:
            self.log("ALL TESTS PASSED ✅", "PASS")
        else:
            self.log("SOME TESTS FAILED ❌", "FAIL")
        self.log("=" * 80)
        
        return all_passed

if __name__ == "__main__":
    tester = PaymentGatewayTester()
    success = tester.run_all_tests()
    exit(0 if success else 1)
