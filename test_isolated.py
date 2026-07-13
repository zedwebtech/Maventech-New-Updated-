#!/usr/bin/env python3
"""
Isolated tests for rate limit and missing country
"""

import requests
import time
import json
import sys

BASE_URL = "http://localhost:3000"

class Colors:
    GREEN = '\033[92m'
    RED = '\033[91m'
    YELLOW = '\033[93m'
    BLUE = '\033[94m'
    RESET = '\033[0m'

def log_test(test_name, status, message=""):
    """Log test results with color coding"""
    if status == "PASS":
        print(f"{Colors.GREEN}✅ {test_name}: PASS{Colors.RESET}")
    elif status == "FAIL":
        print(f"{Colors.RED}❌ {test_name}: FAIL{Colors.RESET}")
        if message:
            print(f"   {Colors.RED}{message}{Colors.RESET}")
    elif status == "INFO":
        print(f"{Colors.BLUE}ℹ️  {test_name}: {message}{Colors.RESET}")
    else:
        print(f"{Colors.YELLOW}⚠️  {test_name}: {message}{Colors.RESET}")

def test_5_rate_limit():
    """Test 5 — Rate limit (6 per 10s per IP)"""
    print("\n" + "="*80)
    print("TEST 5: Rate limit (6 per 10s per IP) - ISOLATED")
    print("="*80)
    
    try:
        success_count = 0
        rate_limited_count = 0
        
        # Fire 10 rapid requests
        for i in range(1, 11):
            url = f"{BASE_URL}/ajax/address-suggest.php?q=Times+Square+Test5+{i}&country=us"
            response = requests.get(url, timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('ok'):
                    success_count += 1
                    log_test(f"Test 5 - Request {i}", "INFO", f"Success (ok:true)")
                else:
                    error = data.get('error', '')
                    if 'Too many requests' in error:
                        rate_limited_count += 1
                        log_test(f"Test 5 - Request {i}", "INFO", f"Rate limited: {error}")
                    else:
                        log_test(f"Test 5 - Request {i}", "WARN", f"Unexpected error: {error}")
            else:
                log_test(f"Test 5 - Request {i}", "WARN", f"HTTP {response.status_code}")
        
        log_test("Test 5 - Summary", "INFO", f"Success: {success_count}, Rate-limited: {rate_limited_count}")
        
        # Expected: exactly 6 successful and 4 rate-limited
        if success_count == 6 and rate_limited_count == 4:
            log_test("Test 5 - Rate limit enforcement", "PASS", "Exactly 6 successful and 4 rate-limited")
            print(f"\n{Colors.GREEN}✅ TEST 5: ALL CHECKS PASSED{Colors.RESET}")
            return True
        else:
            log_test("Test 5 - Rate limit enforcement", "FAIL", 
                    f"Expected 6 successful and 4 rate-limited, got {success_count} successful and {rate_limited_count} rate-limited")
            return False
        
    except Exception as e:
        log_test("Test 5 - Exception", "FAIL", str(e))
        return False

def test_7_missing_country():
    """Test 7 — Missing country still returns results"""
    print("\n" + "="*80)
    print("TEST 7: Missing country still returns results - Eiffel Tower")
    print("="*80)
    
    url = f"{BASE_URL}/ajax/address-suggest.php?q=Eiffel+Tower"
    
    try:
        response = requests.get(url, timeout=10)
        
        if response.status_code != 200:
            log_test("Test 7 - HTTP Status", "FAIL", f"Expected 200, got {response.status_code}")
            return False
        log_test("Test 7 - HTTP Status", "PASS")
        
        data = response.json()
        log_test("Test 7 - Response", "INFO", f"Response: {json.dumps(data, indent=2)}")
        
        # Check ok: true
        if not data.get('ok'):
            log_test("Test 7 - ok field", "FAIL", f"Expected ok:true, got {data.get('ok')}")
            return False
        log_test("Test 7 - ok field", "PASS")
        
        # Check at least 1 suggestion
        suggestions = data.get('suggestions', [])
        if len(suggestions) < 1:
            log_test("Test 7 - Suggestions count", "FAIL", f"Expected at least 1 suggestion, got {len(suggestions)}")
            return False
        log_test("Test 7 - Suggestions count", "PASS", f"Got {len(suggestions)} suggestions")
        
        # Check that country ISO code is included in each row
        for i, suggestion in enumerate(suggestions):
            country = suggestion.get('country', '')
            if not country:
                log_test("Test 7 - Country field", "FAIL", f"Suggestion {i} missing country field")
                return False
        log_test("Test 7 - Country field", "PASS", "All suggestions have country field")
        
        print(f"\n{Colors.GREEN}✅ TEST 7: ALL CHECKS PASSED{Colors.RESET}")
        return True
        
    except Exception as e:
        log_test("Test 7 - Exception", "FAIL", str(e))
        return False

def main():
    """Run isolated tests"""
    print("\n" + "="*80)
    print("ISOLATED TESTS - Rate Limit and Missing Country")
    print("="*80)
    
    results = {}
    
    # Run Test 5 first
    results['Test 5'] = test_5_rate_limit()
    
    # Wait 15 seconds for rate limit to reset
    print(f"\n{Colors.YELLOW}Waiting 15 seconds for rate limit to reset...{Colors.RESET}")
    time.sleep(15)
    
    # Run Test 7
    results['Test 7'] = test_7_missing_country()
    
    # Summary
    print("\n" + "="*80)
    print("TEST SUMMARY")
    print("="*80)
    
    passed = sum(1 for v in results.values() if v)
    total = len(results)
    
    for test_name, result in results.items():
        status = f"{Colors.GREEN}PASS{Colors.RESET}" if result else f"{Colors.RED}FAIL{Colors.RESET}"
        print(f"{test_name}: {status}")
    
    print(f"\n{Colors.BLUE}Total: {passed}/{total} tests passed{Colors.RESET}")
    
    if passed == total:
        print(f"\n{Colors.GREEN}🎉 ALL TESTS PASSED!{Colors.RESET}\n")
        return 0
    else:
        print(f"\n{Colors.RED}❌ SOME TESTS FAILED{Colors.RESET}\n")
        return 1

if __name__ == "__main__":
    sys.exit(main())
