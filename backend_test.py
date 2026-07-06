#!/usr/bin/env python3
"""
Backend testing for Maventech PHP storefront bug fixes:
- Bug A: Text-selection highlight CSS fix
- Bug B: Remove support@maventechsoftware.com sitewide
"""

import requests
import subprocess
import sys
from typing import Dict, List, Tuple

# Base URL for testing
BASE_URL = "http://localhost:3000"

# Test results storage
test_results = {
    "passed": [],
    "failed": [],
    "warnings": []
}

def log_pass(test_name: str, details: str = ""):
    """Log a passing test"""
    msg = f"✅ PASS: {test_name}"
    if details:
        msg += f" - {details}"
    print(msg)
    test_results["passed"].append(test_name)

def log_fail(test_name: str, details: str):
    """Log a failing test"""
    msg = f"❌ FAIL: {test_name} - {details}"
    print(msg)
    test_results["failed"].append(f"{test_name}: {details}")

def log_warning(test_name: str, details: str):
    """Log a warning"""
    msg = f"⚠️  WARNING: {test_name} - {details}"
    print(msg)
    test_results["warnings"].append(f"{test_name}: {details}")

def test_css_selection_rules():
    """Test Bug A: CSS selection rules fixed"""
    print("\n" + "="*80)
    print("TEST 1: CSS SELECTION RULES (Bug A)")
    print("="*80)
    
    try:
        response = requests.get(f"{BASE_URL}/assets/css/style.css", timeout=10, allow_redirects=True)
        
        if response.status_code != 200:
            log_fail("CSS file fetch", f"HTTP {response.status_code}")
            return
        
        css_content = response.text
        
        # Check for the new selection rules with color declarations
        required_rules = [
            "::selection { background: rgba(6, 182, 212, .32); color: #fff; }",
            "::-moz-selection { background: rgba(6, 182, 212, .32); color: #fff; }",
            "[data-bs-theme=\"light\"] ::selection,",
            "html:not([data-bs-theme=\"dark\"]) ::selection { background: rgba(11, 92, 255, .18); color: #0f172a; }",
            "[data-bs-theme=\"light\"] ::-moz-selection,",
            "html:not([data-bs-theme=\"dark\"]) ::-moz-selection { background: rgba(11, 92, 255, .18); color: #0f172a; }"
        ]
        
        all_found = True
        for rule in required_rules:
            if rule in css_content:
                log_pass(f"CSS rule present", rule[:60] + "...")
            else:
                log_fail(f"CSS rule missing", rule[:60] + "...")
                all_found = False
        
        # Check that the old rule WITHOUT color is not the first occurrence
        old_rule = "::selection { background: rgba(11, 92, 255, .22); }"
        first_selection_idx = css_content.find("::selection")
        
        if first_selection_idx == -1:
            log_fail("CSS first ::selection", "No ::selection rule found at all")
        else:
            # Extract the first ::selection rule (up to closing brace)
            first_rule_start = first_selection_idx
            first_rule_end = css_content.find("}", first_rule_start) + 1
            first_rule = css_content[first_rule_start:first_rule_end]
            
            if "color:" in first_rule or "color :" in first_rule:
                log_pass("CSS first ::selection has color", "First ::selection rule includes color declaration")
            else:
                log_fail("CSS first ::selection missing color", f"First rule: {first_rule}")
        
        # Verify the old rule is NOT present (or only in zoom-ink section)
        if old_rule in css_content:
            # Check if it's only in the zoom-ink section
            old_rule_idx = css_content.find(old_rule)
            # Look for zoom-ink context around it
            context_start = max(0, old_rule_idx - 200)
            context_end = min(len(css_content), old_rule_idx + 200)
            context = css_content[context_start:context_end]
            
            if "zoom-ink" in context:
                log_pass("Old CSS rule", "Old rule only present in zoom-ink section (acceptable)")
            else:
                log_warning("Old CSS rule", "Old rule found outside zoom-ink section")
        else:
            log_pass("Old CSS rule removed", "Old rule without color not found")
            
    except Exception as e:
        log_fail("CSS test exception", str(e))

def test_email_sitewide(url_path: str, page_name: str) -> Tuple[int, int]:
    """
    Test a single page for email occurrences
    Returns: (support_count, services_count)
    """
    try:
        response = requests.get(f"{BASE_URL}{url_path}", timeout=10, allow_redirects=True)
        
        if response.status_code != 200:
            log_fail(f"Page fetch {page_name}", f"HTTP {response.status_code}")
            return (-1, -1)
        
        html_content = response.text
        
        support_count = html_content.count("support@maventechsoftware.com")
        services_count = html_content.count("services@maventechsoftware.com")
        
        return (support_count, services_count)
        
    except Exception as e:
        log_fail(f"Page test exception {page_name}", str(e))
        return (-1, -1)

def test_bug_b_email_replacement():
    """Test Bug B: support@ removed, services@ present"""
    print("\n" + "="*80)
    print("TEST 2: EMAIL REPLACEMENT SITEWIDE (Bug B)")
    print("="*80)
    
    # Pages to test
    test_pages = [
        ("/", "Homepage"),
        ("/contact.php", "Contact"),
        ("/shipping-delivery.php", "Shipping & Delivery"),
        ("/about-us.php", "About Us"),
        ("/shop.php", "Shop"),
        ("/product.php?slug=microsoft-office-home-2024-pc", "Product Page")
    ]
    
    # Pages that should show services@ email
    pages_with_email = ["/", "/contact.php", "/shipping-delivery.php", "/about-us.php"]
    
    all_passed = True
    
    for url_path, page_name in test_pages:
        print(f"\nTesting {page_name} ({url_path})...")
        support_count, services_count = test_email_sitewide(url_path, page_name)
        
        if support_count == -1:  # Error occurred
            all_passed = False
            continue
        
        # Check support@ count (should be 0)
        if support_count == 0:
            log_pass(f"{page_name} - support@ removed", f"0 occurrences")
        else:
            log_fail(f"{page_name} - support@ still present", f"{support_count} occurrences found")
            all_passed = False
        
        # Check services@ count (should be >= 1 for certain pages)
        if url_path in pages_with_email:
            if services_count >= 1:
                log_pass(f"{page_name} - services@ present", f"{services_count} occurrences")
            else:
                log_fail(f"{page_name} - services@ missing", f"Expected >= 1, found {services_count}")
                all_passed = False
        else:
            # For other pages, just report the count
            if services_count > 0:
                log_pass(f"{page_name} - services@ count", f"{services_count} occurrences")

def test_shipping_page_emails():
    """Test Bug B: Shipping page specific email checks"""
    print("\n" + "="*80)
    print("TEST 3: SHIPPING PAGE EMAIL LINKS (Bug B)")
    print("="*80)
    
    try:
        response = requests.get(f"{BASE_URL}/shipping-delivery.php", timeout=10, allow_redirects=True)
        
        if response.status_code != 200:
            log_fail("Shipping page fetch", f"HTTP {response.status_code}")
            return
        
        html_content = response.text
        
        # Count mailto links
        services_mailto_count = html_content.count("mailto:services@maventechsoftware.com")
        support_mailto_count = html_content.count("mailto:support@maventechsoftware.com")
        
        if services_mailto_count >= 3:
            log_pass("Shipping page - services@ mailto", f"{services_mailto_count} occurrences (expected >= 3)")
        else:
            log_fail("Shipping page - services@ mailto", f"{services_mailto_count} occurrences (expected >= 3)")
        
        if support_mailto_count == 0:
            log_pass("Shipping page - support@ mailto removed", "0 occurrences")
        else:
            log_fail("Shipping page - support@ mailto present", f"{support_mailto_count} occurrences")
            
    except Exception as e:
        log_fail("Shipping page test exception", str(e))

def test_contact_page_emails():
    """Test Bug B: Contact page specific email checks"""
    print("\n" + "="*80)
    print("TEST 4: CONTACT PAGE EMAIL DISPLAY (Bug B)")
    print("="*80)
    
    try:
        response = requests.get(f"{BASE_URL}/contact.php", timeout=10, allow_redirects=True)
        
        if response.status_code != 200:
            log_fail("Contact page fetch", f"HTTP {response.status_code}")
            return
        
        html_content = response.text
        
        services_count = html_content.count("services@maventechsoftware.com")
        support_count = html_content.count("support@maventechsoftware.com")
        
        if services_count >= 1:
            log_pass("Contact page - services@ present", f"{services_count} occurrences")
        else:
            log_fail("Contact page - services@ missing", f"Expected >= 1, found {services_count}")
        
        if support_count == 0:
            log_pass("Contact page - support@ removed", "0 occurrences")
        else:
            log_fail("Contact page - support@ present", f"{support_count} occurrences")
            
    except Exception as e:
        log_fail("Contact page test exception", str(e))

def test_database_settings():
    """Test Bug B: Database settings check"""
    print("\n" + "="*80)
    print("TEST 5: DATABASE EMAIL SETTINGS (Bug B)")
    print("="*80)
    
    try:
        # Run MySQL query
        query = "SELECT k,v FROM settings WHERE k IN ('support_email','company_email','contact_email');"
        result = subprocess.run(
            ["mysql", "-uroot", "ucode_store", "-e", query],
            capture_output=True,
            text=True,
            timeout=10
        )
        
        if result.returncode != 0:
            log_fail("Database query", f"MySQL error: {result.stderr}")
            return
        
        output = result.stdout
        print(f"Database output:\n{output}")
        
        # Parse the output
        lines = output.strip().split('\n')[1:]  # Skip header
        
        settings_found = {}
        for line in lines:
            if '\t' in line:
                key, value = line.split('\t', 1)
                settings_found[key] = value
        
        # Check each setting
        expected_email = "services@maventechsoftware.com"
        
        for key in ['support_email', 'contact_email', 'company_email']:
            if key in settings_found:
                if settings_found[key] == expected_email:
                    log_pass(f"DB setting {key}", f"= {expected_email}")
                else:
                    log_fail(f"DB setting {key}", f"= {settings_found[key]} (expected {expected_email})")
            else:
                log_warning(f"DB setting {key}", "Not found in database")
        
        # Verify no support@ in any setting
        if any("support@maventechsoftware.com" in v for v in settings_found.values()):
            log_fail("DB settings", "support@maventechsoftware.com found in database")
        else:
            log_pass("DB settings", "No support@maventechsoftware.com in database")
            
    except Exception as e:
        log_fail("Database test exception", str(e))

def print_summary():
    """Print test summary"""
    print("\n" + "="*80)
    print("TEST SUMMARY")
    print("="*80)
    
    print(f"\n✅ PASSED: {len(test_results['passed'])} tests")
    print(f"❌ FAILED: {len(test_results['failed'])} tests")
    print(f"⚠️  WARNINGS: {len(test_results['warnings'])} tests")
    
    if test_results['failed']:
        print("\nFailed tests:")
        for failure in test_results['failed']:
            print(f"  - {failure}")
    
    if test_results['warnings']:
        print("\nWarnings:")
        for warning in test_results['warnings']:
            print(f"  - {warning}")
    
    print("\n" + "="*80)
    
    # Return exit code
    return 0 if len(test_results['failed']) == 0 else 1

def main():
    """Main test execution"""
    print("="*80)
    print("MAVENTECH PHP STOREFRONT - BUG FIX VERIFICATION")
    print("Testing Bug A (CSS selection) and Bug B (email replacement)")
    print("="*80)
    
    # Run all tests
    test_css_selection_rules()
    test_bug_b_email_replacement()
    test_shipping_page_emails()
    test_contact_page_emails()
    test_database_settings()
    
    # Print summary and exit
    exit_code = print_summary()
    sys.exit(exit_code)

if __name__ == "__main__":
    main()
