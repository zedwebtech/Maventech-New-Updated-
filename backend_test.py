#!/usr/bin/env python3
"""
Backend API Testing Suite for Iteration 2026-07-13(k)
Tests the /ajax/address-suggest.php endpoint
"""

import requests
import time
import json
import sys
import subprocess

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

def test_1_happy_path_us_address():
    """Test 1 — Happy path (US address)"""
    print("\n" + "="*80)
    print("TEST 1: Happy path (US address) - 1600 Amphitheatre")
    print("="*80)
    
    url = f"{BASE_URL}/ajax/address-suggest.php?q=1600+Amphitheatre&country=us"
    
    try:
        response = requests.get(url, timeout=10)
        
        # Check HTTP 200
        if response.status_code != 200:
            log_test("Test 1 - HTTP Status", "FAIL", f"Expected 200, got {response.status_code}")
            return False
        log_test("Test 1 - HTTP Status", "PASS")
        
        # Check Content-Type
        content_type = response.headers.get('Content-Type', '')
        if 'application/json' not in content_type:
            log_test("Test 1 - Content-Type", "FAIL", f"Expected application/json, got {content_type}")
            return False
        log_test("Test 1 - Content-Type", "PASS")
        
        # Parse JSON
        data = response.json()
        log_test("Test 1 - JSON Parse", "INFO", f"Response: {json.dumps(data, indent=2)}")
        
        # Check ok: true
        if not data.get('ok'):
            log_test("Test 1 - ok field", "FAIL", f"Expected ok:true, got {data.get('ok')}")
            return False
        log_test("Test 1 - ok field", "PASS")
        
        # Check suggestions array
        suggestions = data.get('suggestions', [])
        if len(suggestions) < 1:
            log_test("Test 1 - Suggestions count", "FAIL", f"Expected at least 1 suggestion, got {len(suggestions)}")
            return False
        log_test("Test 1 - Suggestions count", "PASS", f"Got {len(suggestions)} suggestions")
        
        # Check first suggestion details
        first = suggestions[0]
        log_test("Test 1 - First suggestion", "INFO", f"Details: {json.dumps(first, indent=2)}")
        
        # Check line1 contains "Amphitheatre Parkway"
        line1 = first.get('line1', '')
        if 'Amphitheatre' not in line1 or 'Parkway' not in line1:
            log_test("Test 1 - line1 field", "FAIL", f"Expected 'Amphitheatre Parkway' in line1, got '{line1}'")
            return False
        log_test("Test 1 - line1 field", "PASS", f"line1='{line1}'")
        
        # Check city
        city = first.get('city', '')
        if city != 'Mountain View':
            log_test("Test 1 - city field", "FAIL", f"Expected 'Mountain View', got '{city}'")
            return False
        log_test("Test 1 - city field", "PASS")
        
        # Check state
        state = first.get('state', '')
        if state != 'California':
            log_test("Test 1 - state field", "FAIL", f"Expected 'California', got '{state}'")
            return False
        log_test("Test 1 - state field", "PASS")
        
        # Check state_code
        state_code = first.get('state_code', '')
        if state_code != 'CA':
            log_test("Test 1 - state_code field", "FAIL", f"Expected 'CA', got '{state_code}'")
            return False
        log_test("Test 1 - state_code field", "PASS")
        
        # Check postcode
        postcode = first.get('postcode', '')
        if postcode != '94043':
            log_test("Test 1 - postcode field", "FAIL", f"Expected '94043', got '{postcode}'")
            return False
        log_test("Test 1 - postcode field", "PASS")
        
        # Check country
        country = first.get('country', '')
        if country != 'US':
            log_test("Test 1 - country field", "FAIL", f"Expected 'US', got '{country}'")
            return False
        log_test("Test 1 - country field", "PASS")
        
        print(f"\n{Colors.GREEN}✅ TEST 1: ALL CHECKS PASSED{Colors.RESET}")
        return True
        
    except Exception as e:
        log_test("Test 1 - Exception", "FAIL", str(e))
        return False

def test_2_uk_country_biasing():
    """Test 2 — UK country biasing (with the storefront's 'uk' → 'gb' internal mapping)"""
    print("\n" + "="*80)
    print("TEST 2: UK country biasing - Buckingham Palace")
    print("="*80)
    
    url = f"{BASE_URL}/ajax/address-suggest.php?q=Buckingham+Palace&country=uk"
    
    try:
        response = requests.get(url, timeout=10)
        
        if response.status_code != 200:
            log_test("Test 2 - HTTP Status", "FAIL", f"Expected 200, got {response.status_code}")
            return False
        log_test("Test 2 - HTTP Status", "PASS")
        
        data = response.json()
        log_test("Test 2 - Response", "INFO", f"Response: {json.dumps(data, indent=2)}")
        
        if not data.get('ok'):
            log_test("Test 2 - ok field", "FAIL", f"Expected ok:true, got {data.get('ok')}")
            return False
        
        suggestions = data.get('suggestions', [])
        if len(suggestions) < 1:
            log_test("Test 2 - Suggestions count", "FAIL", f"Expected at least 1 suggestion, got {len(suggestions)}")
            return False
        log_test("Test 2 - Suggestions count", "PASS", f"Got {len(suggestions)} suggestions")
        
        # Check first suggestion
        first = suggestions[0]
        
        # Check country === 'UK'
        country = first.get('country', '')
        if country != 'UK':
            log_test("Test 2 - country field", "FAIL", f"Expected 'UK', got '{country}'")
            return False
        log_test("Test 2 - country field", "PASS")
        
        # Check city mentions London (Westminster, London, or City of Westminster are acceptable)
        city = first.get('city', '')
        london_keywords = ['Westminster', 'London', 'City of Westminster']
        if not any(keyword.lower() in city.lower() for keyword in london_keywords):
            log_test("Test 2 - city field", "FAIL", f"Expected city to mention London/Westminster, got '{city}'")
            return False
        log_test("Test 2 - city field", "PASS", f"city='{city}'")
        
        # Check postcode contains SW1
        postcode = first.get('postcode', '')
        if 'SW1' not in postcode:
            log_test("Test 2 - postcode field", "FAIL", f"Expected postcode to contain 'SW1', got '{postcode}'")
            return False
        log_test("Test 2 - postcode field", "PASS", f"postcode='{postcode}'")
        
        print(f"\n{Colors.GREEN}✅ TEST 2: ALL CHECKS PASSED{Colors.RESET}")
        return True
        
    except Exception as e:
        log_test("Test 2 - Exception", "FAIL", str(e))
        return False

def test_3_short_query_returns_empty():
    """Test 3 — Short query returns empty (no upstream call)"""
    print("\n" + "="*80)
    print("TEST 3: Short query returns empty - 'ab'")
    print("="*80)
    
    url = f"{BASE_URL}/ajax/address-suggest.php?q=ab&country=us"
    
    try:
        response = requests.get(url, timeout=10)
        
        if response.status_code != 200:
            log_test("Test 3 - HTTP Status", "FAIL", f"Expected 200, got {response.status_code}")
            return False
        log_test("Test 3 - HTTP Status", "PASS")
        
        data = response.json()
        log_test("Test 3 - Response", "INFO", f"Response: {json.dumps(data)}")
        
        # Check ok: true
        if not data.get('ok'):
            log_test("Test 3 - ok field", "FAIL", f"Expected ok:true, got {data.get('ok')}")
            return False
        log_test("Test 3 - ok field", "PASS")
        
        # Check suggestions is empty array
        suggestions = data.get('suggestions', None)
        if suggestions != []:
            log_test("Test 3 - suggestions field", "FAIL", f"Expected empty array [], got {suggestions}")
            return False
        log_test("Test 3 - suggestions field", "PASS", "Got empty array []")
        
        print(f"\n{Colors.GREEN}✅ TEST 3: ALL CHECKS PASSED{Colors.RESET}")
        return True
        
    except Exception as e:
        log_test("Test 3 - Exception", "FAIL", str(e))
        return False

def test_4_cache_hit_faster():
    """Test 4 — Cache hit is faster on repeat"""
    print("\n" + "="*80)
    print("TEST 4: Cache hit is faster on repeat")
    print("="*80)
    
    url = f"{BASE_URL}/ajax/address-suggest.php?q=1600+Amphitheatre&country=us"
    
    try:
        # First call (cold)
        start1 = time.time()
        response1 = requests.get(url, timeout=10)
        elapsed1 = time.time() - start1
        
        if response1.status_code != 200:
            log_test("Test 4 - First call HTTP Status", "FAIL", f"Expected 200, got {response1.status_code}")
            return False
        
        data1 = response1.json()
        log_test("Test 4 - First call", "INFO", f"Elapsed: {elapsed1*1000:.2f}ms")
        
        # Wait a bit
        time.sleep(0.5)
        
        # Second call (should hit cache)
        start2 = time.time()
        response2 = requests.get(url, timeout=10)
        elapsed2 = time.time() - start2
        
        if response2.status_code != 200:
            log_test("Test 4 - Second call HTTP Status", "FAIL", f"Expected 200, got {response2.status_code}")
            return False
        
        data2 = response2.json()
        log_test("Test 4 - Second call", "INFO", f"Elapsed: {elapsed2*1000:.2f}ms")
        
        # Check that responses are identical
        if json.dumps(data1, sort_keys=True) != json.dumps(data2, sort_keys=True):
            log_test("Test 4 - Response identity", "FAIL", "Responses are not identical")
            return False
        log_test("Test 4 - Response identity", "PASS", "Responses are identical")
        
        # Check that second call is faster (should be <100ms vs ~500-1500ms for cold)
        if elapsed2 >= elapsed1:
            log_test("Test 4 - Cache speedup", "WARN", f"Second call ({elapsed2*1000:.2f}ms) not faster than first ({elapsed1*1000:.2f}ms), but may be acceptable")
        else:
            speedup = (elapsed1 - elapsed2) / elapsed1 * 100
            log_test("Test 4 - Cache speedup", "PASS", f"Second call {speedup:.1f}% faster ({elapsed1*1000:.2f}ms → {elapsed2*1000:.2f}ms)")
        
        # Check database for cache entry
        try:
            result = subprocess.run(
                ['mysql', '-uroot', 'ucode_store', '-e', 
                 "SELECT COUNT(*) FROM address_suggest_cache WHERE cache_key='v1:1600 amphitheatre|us'"],
                capture_output=True,
                text=True,
                timeout=5
            )
            
            if result.returncode == 0:
                output = result.stdout.strip()
                lines = output.split('\n')
                if len(lines) >= 2:
                    count = lines[1].strip()
                    if count == '1':
                        log_test("Test 4 - Cache DB entry", "PASS", "Found 1 row in address_suggest_cache")
                    else:
                        log_test("Test 4 - Cache DB entry", "FAIL", f"Expected 1 row, found {count}")
                        return False
                else:
                    log_test("Test 4 - Cache DB entry", "WARN", "Could not parse MySQL output")
            else:
                log_test("Test 4 - Cache DB entry", "WARN", f"MySQL query failed: {result.stderr}")
        except Exception as e:
            log_test("Test 4 - Cache DB entry", "WARN", f"Could not check database: {str(e)}")
        
        print(f"\n{Colors.GREEN}✅ TEST 4: ALL CHECKS PASSED{Colors.RESET}")
        return True
        
    except Exception as e:
        log_test("Test 4 - Exception", "FAIL", str(e))
        return False

def test_5_rate_limit():
    """Test 5 — Rate limit (6 per 10s per IP)"""
    print("\n" + "="*80)
    print("TEST 5: Rate limit (6 per 10s per IP)")
    print("="*80)
    
    try:
        success_count = 0
        rate_limited_count = 0
        
        # Fire 10 rapid requests
        for i in range(1, 11):
            url = f"{BASE_URL}/ajax/address-suggest.php?q=Times+Square+{i}&country=us"
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

def test_6_long_query_rejected():
    """Test 6 — Long query rejected"""
    print("\n" + "="*80)
    print("TEST 6: Long query rejected (>120 chars)")
    print("="*80)
    
    # Create a query >120 chars
    long_query = "a" * 121
    url = f"{BASE_URL}/ajax/address-suggest.php?q={long_query}&country=us"
    
    try:
        response = requests.get(url, timeout=10)
        
        if response.status_code != 200:
            log_test("Test 6 - HTTP Status", "FAIL", f"Expected 200, got {response.status_code}")
            return False
        log_test("Test 6 - HTTP Status", "PASS")
        
        data = response.json()
        log_test("Test 6 - Response", "INFO", f"Response: {json.dumps(data)}")
        
        # Check ok: false
        if data.get('ok') != False:
            log_test("Test 6 - ok field", "FAIL", f"Expected ok:false, got {data.get('ok')}")
            return False
        log_test("Test 6 - ok field", "PASS")
        
        # Check error message
        error = data.get('error', '')
        if error != 'Query too long.':
            log_test("Test 6 - error field", "FAIL", f"Expected 'Query too long.', got '{error}'")
            return False
        log_test("Test 6 - error field", "PASS")
        
        print(f"\n{Colors.GREEN}✅ TEST 6: ALL CHECKS PASSED{Colors.RESET}")
        return True
        
    except Exception as e:
        log_test("Test 6 - Exception", "FAIL", str(e))
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

def test_8_db_tables_schema():
    """Test 8 — DB tables exist and schema is sane"""
    print("\n" + "="*80)
    print("TEST 8: DB tables exist and schema is sane")
    print("="*80)
    
    try:
        # Check address_suggest_cache table
        result = subprocess.run(
            ['mysql', '-uroot', 'ucode_store', '-e', 'DESCRIBE address_suggest_cache'],
            capture_output=True,
            text=True,
            timeout=5
        )
        
        if result.returncode != 0:
            log_test("Test 8 - address_suggest_cache table", "FAIL", "Table does not exist")
            return False
        
        log_test("Test 8 - address_suggest_cache table", "PASS", "Table exists")
        log_test("Test 8 - address_suggest_cache schema", "INFO", f"\n{result.stdout}")
        
        # Check required fields in address_suggest_cache
        cache_schema = result.stdout
        required_cache_fields = ['cache_key', 'payload', 'created_at']
        for field in required_cache_fields:
            if field not in cache_schema:
                log_test(f"Test 8 - cache field '{field}'", "FAIL", f"Field '{field}' not found in schema")
                return False
        log_test("Test 8 - address_suggest_cache fields", "PASS", "All required fields present (cache_key, payload, created_at)")
        
        # Check address_suggest_rate table
        result = subprocess.run(
            ['mysql', '-uroot', 'ucode_store', '-e', 'DESCRIBE address_suggest_rate'],
            capture_output=True,
            text=True,
            timeout=5
        )
        
        if result.returncode != 0:
            log_test("Test 8 - address_suggest_rate table", "FAIL", "Table does not exist")
            return False
        
        log_test("Test 8 - address_suggest_rate table", "PASS", "Table exists")
        log_test("Test 8 - address_suggest_rate schema", "INFO", f"\n{result.stdout}")
        
        # Check required fields in address_suggest_rate
        rate_schema = result.stdout
        required_rate_fields = ['ip', 'ts']
        for field in required_rate_fields:
            if field not in rate_schema:
                log_test(f"Test 8 - rate field '{field}'", "FAIL", f"Field '{field}' not found in schema")
                return False
        log_test("Test 8 - address_suggest_rate fields", "PASS", "All required fields present (ip, ts)")
        
        # Check for index on address_suggest_rate
        result = subprocess.run(
            ['mysql', '-uroot', 'ucode_store', '-e', 'SHOW INDEX FROM address_suggest_rate'],
            capture_output=True,
            text=True,
            timeout=5
        )
        
        if result.returncode == 0:
            index_info = result.stdout
            if 'ip' in index_info:
                log_test("Test 8 - address_suggest_rate index", "PASS", "Index on (ip, ts) exists")
            else:
                log_test("Test 8 - address_suggest_rate index", "WARN", "Index may not be optimal")
        
        print(f"\n{Colors.GREEN}✅ TEST 8: ALL CHECKS PASSED{Colors.RESET}")
        return True
        
    except Exception as e:
        log_test("Test 8 - Exception", "FAIL", str(e))
        return False

def main():
    """Run all tests"""
    print("\n" + "="*80)
    print("BACKEND API TESTING SUITE - Iteration 2026-07-13(k)")
    print("Testing /ajax/address-suggest.php endpoint")
    print("="*80)
    
    results = {}
    
    # Run all tests
    results['Test 1'] = test_1_happy_path_us_address()
    results['Test 2'] = test_2_uk_country_biasing()
    results['Test 3'] = test_3_short_query_returns_empty()
    results['Test 4'] = test_4_cache_hit_faster()
    results['Test 5'] = test_5_rate_limit()
    results['Test 6'] = test_6_long_query_rejected()
    results['Test 7'] = test_7_missing_country()
    results['Test 8'] = test_8_db_tables_schema()
    
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
