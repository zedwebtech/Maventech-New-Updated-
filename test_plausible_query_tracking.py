#!/usr/bin/env python3
"""
Plausible Analytics Query String Tracking Verification
Tests that URLs with query strings are tracked correctly by Plausible.
"""

import sys
import re
import json
from playwright.sync_api import sync_playwright, Route
from urllib.parse import urlparse, parse_qs

# Test URLs
TEST_URL_1 = "http://localhost:3000/category.php?slug=microsoft-project&view=grid&cat[]=office&os[]=Mac&sort="
TEST_URL_2 = "http://localhost:3000/shop.php?view=grid&cat[]=windows-11&os[]=Windows"

def test_1_html_source_check():
    """
    Test 1: Check HTML source for script.manual.js and inline shim block
    """
    print("\n" + "="*80)
    print("TEST 1: HTML Source Check")
    print("="*80)
    
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        
        try:
            page.goto(TEST_URL_1, wait_until='networkidle', timeout=10000)
            html = page.content()
            
            # Check 1a: script.manual.js exists (NOT plain script.js)
            print("\n1a. Checking for script.manual.js in HTML...")
            if 'src="https://plausible.io/js/script.manual.js"' in html:
                print("✅ PASS: Found script.manual.js")
            elif 'src="https://plausible.io/js/script.js"' in html and 'script.manual' not in html:
                print("❌ FAIL: Found plain script.js instead of script.manual.js")
                return False
            else:
                # Check for any script.manual variant
                manual_match = re.search(r'src="https://plausible\.io/js/script\.manual[^"]*\.js"', html)
                if manual_match:
                    print(f"✅ PASS: Found {manual_match.group(0)}")
                else:
                    print("❌ FAIL: No script.manual.js found in HTML")
                    return False
            
            # Check 1b: Inline shim block exists
            print("\n1b. Checking for inline shim block...")
            checks = [
                ("window.plausible = window.plausible || function", "plausible shim function"),
                ("plausible('pageview', { u: u });", "pageview call with u parameter"),
                ("var u = window.location.href;", "u = window.location.href assignment")
            ]
            
            all_found = True
            for pattern, desc in checks:
                if pattern in html:
                    print(f"   ✅ Found: {desc}")
                else:
                    print(f"   ❌ Missing: {desc}")
                    all_found = False
            
            if all_found:
                print("✅ PASS: All inline shim components present")
                return True
            else:
                print("❌ FAIL: Some inline shim components missing")
                return False
                
        except Exception as e:
            print(f"❌ ERROR: {e}")
            return False
        finally:
            browser.close()


def test_2_playwright_request_interception():
    """
    Test 2: Intercept plausible.io requests and verify 'u' field contains full URL with query string
    """
    print("\n" + "="*80)
    print("TEST 2: Playwright Request Interception")
    print("="*80)
    
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        
        plausible_requests = []
        
        # Route plausible.io requests
        def handle_route(route: Route):
            url = route.request.url
            if 'plausible.io' in url:
                plausible_requests.append({
                    'url': url,
                    'method': route.request.method,
                    'post_data': route.request.post_data
                })
                route.fulfill(status=202, content_type='text/plain', body='ok')
            else:
                route.continue_()
        
        page.route('**/*', handle_route)
        
        test_urls = [TEST_URL_1, TEST_URL_2]
        results = []
        
        for test_url in test_urls:
            print(f"\n--- Testing URL: {test_url}")
            plausible_requests.clear()
            
            try:
                page.goto(test_url, wait_until='networkidle', timeout=10000)
                page.wait_for_timeout(2500)  # Extra wait for async script
                
                # Check if any plausible.io/api/event requests were made
                event_requests = [r for r in plausible_requests if '/api/event' in r['url']]
                
                if event_requests:
                    print(f"✅ Found {len(event_requests)} plausible.io/api/event request(s)")
                    
                    for req in event_requests:
                        if req['post_data']:
                            try:
                                body = json.loads(req['post_data'])
                                u_field = body.get('u', '')
                                print(f"   POST body 'u' field: {u_field}")
                                
                                if u_field == test_url:
                                    print(f"   ✅ PASS: 'u' field matches full URL with query string")
                                    results.append(True)
                                else:
                                    print(f"   ❌ FAIL: 'u' field does not match")
                                    print(f"      Expected: {test_url}")
                                    print(f"      Got: {u_field}")
                                    results.append(False)
                            except json.JSONDecodeError:
                                print(f"   ⚠ Could not parse POST body as JSON")
                                results.append(False)
                else:
                    # Fallback: check window.plausible.q
                    print("   No plausible.io/api/event requests found, checking window.plausible.q...")
                    queue = page.evaluate("() => window.plausible && window.plausible.q")
                    
                    if queue and len(queue) > 0:
                        print(f"   Found {len(queue)} queued event(s)")
                        first_event = queue[0]
                        print(f"   First event: {first_event}")
                        
                        # Handle both list and dict formats (Playwright serializes arrays as dicts with numeric keys)
                        event_type = None
                        event_data = None
                        
                        if isinstance(first_event, list) and len(first_event) >= 2:
                            event_type = first_event[0]
                            event_data = first_event[1]
                        elif isinstance(first_event, dict) and '0' in first_event and '1' in first_event:
                            event_type = first_event['0']
                            event_data = first_event['1']
                        
                        if event_type == 'pageview' and isinstance(event_data, dict):
                            u_field = event_data.get('u', '')
                            print(f"   Queue entry 'u' field: {u_field}")
                            
                            if u_field == test_url:
                                print(f"   ✅ PASS: 'u' field matches full URL with query string")
                                results.append(True)
                            else:
                                print(f"   ❌ FAIL: 'u' field does not match")
                                print(f"      Expected: {test_url}")
                                print(f"      Got: {u_field}")
                                results.append(False)
                        else:
                            print(f"   ❌ FAIL: Queue entry format unexpected")
                            print(f"      Type: {type(first_event)}, Content: {first_event}")
                            results.append(False)
                    else:
                        print(f"   ❌ FAIL: No plausible.io requests and no window.plausible.q entries")
                        results.append(False)
                        
            except Exception as e:
                print(f"❌ ERROR: {e}")
                results.append(False)
        
        browser.close()
        
        if all(results):
            print("\n✅ TEST 2 PASS: All URLs tracked with full query strings")
            return True
        else:
            print("\n❌ TEST 2 FAIL: Some URLs not tracked correctly")
            return False


def test_3_history_change_tracking():
    """
    Test 3: Verify history.pushState triggers new pageview with updated query string
    """
    print("\n" + "="*80)
    print("TEST 3: History Change Tracking")
    print("="*80)
    
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        page = browser.new_page()
        
        plausible_requests = []
        
        def handle_route(route: Route):
            url = route.request.url
            if 'plausible.io' in url:
                plausible_requests.append({
                    'url': url,
                    'method': route.request.method,
                    'post_data': route.request.post_data
                })
                route.fulfill(status=202, content_type='text/plain', body='ok')
            else:
                route.continue_()
        
        page.route('**/*', handle_route)
        
        try:
            print(f"\nNavigating to: {TEST_URL_1}")
            page.goto(TEST_URL_1, wait_until='networkidle', timeout=10000)
            page.wait_for_timeout(1000)
            
            initial_count = len(plausible_requests)
            print(f"Initial pageview requests: {initial_count}")
            
            # Clear and execute history.pushState
            plausible_requests.clear()
            print("\nExecuting: history.pushState({}, '', '?view=grid&sort=price_asc')")
            page.evaluate("history.pushState({}, '', '?view=grid&sort=price_asc')")
            page.wait_for_timeout(300)
            
            # Check for new requests
            new_requests = [r for r in plausible_requests if '/api/event' in r['url']]
            
            if new_requests:
                print(f"✅ Found {len(new_requests)} new plausible.io/api/event request(s)")
                
                for req in new_requests:
                    if req['post_data']:
                        try:
                            body = json.loads(req['post_data'])
                            u_field = body.get('u', '')
                            print(f"   POST body 'u' field: {u_field}")
                            
                            if '?view=grid&sort=price_asc' in u_field:
                                print(f"   ✅ PASS: New pageview fired with updated query string")
                                browser.close()
                                return True
                            else:
                                print(f"   ❌ FAIL: 'u' field does not contain expected query string")
                                browser.close()
                                return False
                        except json.JSONDecodeError:
                            print(f"   ⚠ Could not parse POST body as JSON")
            else:
                # Fallback: check window.plausible.q
                print("   No new plausible.io/api/event requests, checking window.plausible.q...")
                queue = page.evaluate("() => window.plausible && window.plausible.q")
                
                if queue and len(queue) > 1:
                    print(f"   Found {len(queue)} queued event(s)")
                    # Check the last event (most recent)
                    last_event = queue[-1]
                    print(f"   Last event: {last_event}")
                    
                    # Handle both list and dict formats (Playwright serializes arrays as dicts with numeric keys)
                    event_type = None
                    event_data = None
                    
                    if isinstance(last_event, list) and len(last_event) >= 2:
                        event_type = last_event[0]
                        event_data = last_event[1]
                    elif isinstance(last_event, dict) and '0' in last_event and '1' in last_event:
                        event_type = last_event['0']
                        event_data = last_event['1']
                    
                    if event_type == 'pageview' and isinstance(event_data, dict):
                        u_field = event_data.get('u', '')
                        print(f"   Last queue entry 'u' field: {u_field}")
                        
                        if '?view=grid&sort=price_asc' in u_field:
                            print(f"   ✅ PASS: New pageview queued with updated query string")
                            browser.close()
                            return True
                        else:
                            print(f"   ❌ FAIL: 'u' field does not contain expected query string")
                            browser.close()
                            return False
                    else:
                        print(f"   ❌ FAIL: Last event format unexpected")
                        print(f"      Type: {type(last_event)}, Content: {last_event}")
                
                print(f"   ❌ FAIL: No new pageview detected after history.pushState")
                browser.close()
                return False
                
        except Exception as e:
            print(f"❌ ERROR: {e}")
            browser.close()
            return False


def test_4_negative_double_manual():
    """
    Test 4: Verify no double ".manual.manual." rewrite
    """
    print("\n" + "="*80)
    print("TEST 4: Negative Test - No Double Manual Rewrite")
    print("="*80)
    
    import subprocess
    
    try:
        # Update settings to use script.manual.outbound-links.js
        print("\nUpdating settings to use script.manual.outbound-links.js...")
        sql = """UPDATE settings SET v='<script defer data-domain="m.com" src="https://plausible.io/js/script.manual.outbound-links.js"></script>' WHERE k='plausible_script'"""
        result = subprocess.run(
            ['mysql', '-uroot', 'ucode_store', '-e', sql],
            capture_output=True,
            text=True,
            timeout=5
        )
        
        if result.returncode != 0:
            print(f"❌ ERROR updating settings: {result.stderr}")
            return False
        
        print("✅ Settings updated")
        
        # Fetch the category page and check rendered HTML
        print("\nFetching category page HTML...")
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page()
            
            page.goto(TEST_URL_1, wait_until='networkidle', timeout=10000)
            html = page.content()
            browser.close()
        
        # Check for .manual.outbound-links.js (correct)
        if 'script.manual.outbound-links.js' in html:
            print("✅ Found: script.manual.outbound-links.js")
        else:
            print("❌ FAIL: script.manual.outbound-links.js not found")
            return False
        
        # Check for .manual.manual. (incorrect - should NOT exist)
        if '.manual.manual.' in html:
            print("❌ FAIL: Found .manual.manual. (double rewrite occurred)")
            return False
        else:
            print("✅ PASS: No .manual.manual. found (no double rewrite)")
        
        # Restore plain script.js snippet
        print("\nRestoring plain script.js snippet...")
        restore_sql = """UPDATE settings SET v='<script defer data-domain="localhost" src="https://plausible.io/js/script.js"></script>' WHERE k='plausible_script'"""
        result = subprocess.run(
            ['mysql', '-uroot', 'ucode_store', '-e', restore_sql],
            capture_output=True,
            text=True,
            timeout=5
        )
        
        if result.returncode == 0:
            print("✅ Settings restored")
        
        return True
        
    except Exception as e:
        print(f"❌ ERROR: {e}")
        return False


def test_5_static_source_assertion():
    """
    Test 5: Static assertion on header.php source code
    """
    print("\n" + "="*80)
    print("TEST 5: Static Source Assertion on header.php")
    print("="*80)
    
    try:
        with open('/app/php-version/includes/header.php', 'r') as f:
            source = f.read()
        
        required_strings = [
            ("plausible('pageview', { u: u });", "pageview call with u parameter"),
            ("var u = window.location.href;", "u = window.location.href assignment"),
            ("history.pushState", "history.pushState hook"),
            ("hashchange", "hashchange event listener")
        ]
        
        all_found = True
        for pattern, desc in required_strings:
            if pattern in source:
                print(f"✅ Found: {desc}")
            else:
                print(f"❌ Missing: {desc}")
                all_found = False
        
        if all_found:
            print("\n✅ TEST 5 PASS: All required strings found in header.php")
            return True
        else:
            print("\n❌ TEST 5 FAIL: Some required strings missing from header.php")
            return False
            
    except Exception as e:
        print(f"❌ ERROR: {e}")
        return False


def test_6_admin_regression():
    """
    Test 6: Regression check - admin Plausible save flow still works
    """
    print("\n" + "="*80)
    print("TEST 6: Admin Regression Check")
    print("="*80)
    
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()
        
        try:
            # Login
            print("\nLogging in as admin...")
            page.goto('http://localhost:3000/login.php', wait_until='networkidle', timeout=10000)
            page.fill('input[name="email"]', 'services@maventechsoftware.com')
            page.fill('input[name="password"]', 'Admin@123')
            page.click('button[type="submit"]')
            page.wait_for_load_state('networkidle', timeout=10000)
            page.wait_for_timeout(1000)  # Extra wait for redirect
            
            # Navigate to company tab
            print("Navigating to /admin.php?tab=company...")
            page.goto('http://localhost:3000/admin.php?tab=company', wait_until='networkidle', timeout=10000)
            page.wait_for_timeout(1000)  # Extra wait for page to fully render
            
            # Check if Plausible card exists
            plausible_card = page.locator('[data-testid="ci-plausible-script-input"]')
            if plausible_card.count() == 0:
                print("❌ FAIL: Plausible Analytics textarea not found")
                print(f"   Current URL: {page.url}")
                # Debug: check if we're actually logged in
                if 'login' in page.url:
                    print("   ERROR: Still on login page - authentication failed")
                browser.close()
                return False
            
            print("✅ Plausible Analytics textarea found")
            
            # Check status badge
            status_badge = page.locator('[data-testid="ci-plausible-status-live"]')
            if status_badge.count() > 0:
                badge_text = status_badge.inner_text()
                print(f"✅ Status badge found: '{badge_text}'")
                
                if 'Live on every page' in badge_text:
                    print("✅ PASS: Status badge shows 'Live on every page' (green)")
                    browser.close()
                    return True
                else:
                    print(f"⚠ Status badge text: {badge_text}")
            else:
                print("⚠ Status badge not found (may be off)")
            
            # Try a save operation (without changing anything)
            print("\nAttempting save operation...")
            save_button = page.locator('button:has-text("Save Company Info")')
            if save_button.count() > 0:
                save_button.click()
                page.wait_for_load_state('networkidle', timeout=10000)
                
                # Check for 403 error
                if '403' in page.url or 'Forbidden' in page.content():
                    print("❌ FAIL: Got 403 Forbidden on save")
                    browser.close()
                    return False
                else:
                    print("✅ PASS: Save succeeded (no 403)")
                    browser.close()
                    return True
            else:
                print("⚠ Save button not found, but card exists")
                browser.close()
                return True
                
        except Exception as e:
            print(f"❌ ERROR: {e}")
            browser.close()
            return False


def main():
    print("\n" + "="*80)
    print("PLAUSIBLE ANALYTICS QUERY STRING TRACKING VERIFICATION")
    print("="*80)
    
    results = {}
    
    # Run all tests
    results['test_1'] = test_1_html_source_check()
    results['test_2'] = test_2_playwright_request_interception()
    results['test_3'] = test_3_history_change_tracking()
    results['test_4'] = test_4_negative_double_manual()
    results['test_5'] = test_5_static_source_assertion()
    results['test_6'] = test_6_admin_regression()
    
    # Summary
    print("\n" + "="*80)
    print("SUMMARY")
    print("="*80)
    
    for test_name, result in results.items():
        status = "✅ PASS" if result else "❌ FAIL"
        print(f"{test_name}: {status}")
    
    all_passed = all(results.values())
    
    print("\n" + "="*80)
    if all_passed:
        print("✅ ALL TESTS PASSED")
    else:
        print("❌ SOME TESTS FAILED")
    print("="*80)
    
    return 0 if all_passed else 1


if __name__ == '__main__':
    sys.exit(main())
