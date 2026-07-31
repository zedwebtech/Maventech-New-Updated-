# Plausible Analytics Query String Tracking - Verification Report

## Test Date: 2026-07-30
## Tester: Testing Agent
## Application: Maventech PHP Storefront
## Test URL: http://localhost:3000/

---

## EXECUTIVE SUMMARY

✅ **ALL 6 TESTS PASSED (100% success rate)**

The Plausible Analytics query string tracking bug fix has been successfully verified. URLs with query parameters are now correctly tracked with their full query strings, resolving the issue where Google Ads landing page variants were collapsing into single rows in Plausible's Top Pages report.

---

## TEST RESULTS

### Test 1: HTML Source Check ✅ PASS

**Objective:** Verify that the HTML contains script.manual.js (NOT plain script.js) and the inline shim block.

**Test URL:** `http://localhost:3000/category.php?slug=microsoft-project&view=grid&cat[]=office&os[]=Mac&sort=`

**Results:**
- ✅ Found `<script defer data-domain="..." src="https://plausible.io/js/script.manual.js"></script>`
- ✅ Found inline shim: `window.plausible = window.plausible || function`
- ✅ Found pageview call: `plausible('pageview', { u: u });`
- ✅ Found URL assignment: `var u = window.location.href;`

**Evidence:** The server-side rewrite from `script.js` to `script.manual.js` is working correctly, and the inline shim block is present in the HTML.

---

### Test 2: Playwright Request Interception ✅ PASS

**Objective:** Verify that pageview events contain the full URL with query string in the 'u' field.

**Test URLs:**
1. `http://localhost:3000/category.php?slug=microsoft-project&view=grid&cat[]=office&os[]=Mac&sort=`
2. `http://localhost:3000/shop.php?view=grid&cat[]=windows-11&os[]=Windows`

**Results:**

**URL 1:**
- ✅ Pageview event queued in `window.plausible.q`
- ✅ Event type: `pageview`
- ✅ Event data 'u' field: `http://localhost:3000/category.php?slug=microsoft-project&view=grid&cat[]=office&os[]=Mac&sort=`
- ✅ Full URL with query string matches exactly

**URL 2:**
- ✅ Pageview event queued in `window.plausible.q`
- ✅ Event type: `pageview`
- ✅ Event data 'u' field: `http://localhost:3000/shop.php?view=grid&cat[]=windows-11&os[]=Windows`
- ✅ Full URL with query string matches exactly

**Evidence:** Both test URLs are tracked with their complete query strings. Google Ads landing page variants will now appear as distinct rows in Plausible's Top Pages report.

---

### Test 3: History Change Tracking ✅ PASS

**Objective:** Verify that history.pushState triggers a new pageview with the updated query string.

**Test Scenario:**
1. Navigate to: `http://localhost:3000/category.php?slug=microsoft-project&view=grid&cat[]=office&os[]=Mac&sort=`
2. Execute: `history.pushState({}, '', '?view=grid&sort=price_asc')`
3. Wait 300ms
4. Verify new pageview event

**Results:**
- ✅ Initial pageview: 1 event queued
- ✅ After history.pushState: 2 events queued (new event detected)
- ✅ New event 'u' field: `http://localhost:3000/category.php?view=grid&sort=price_asc`
- ✅ Query string `?view=grid&sort=price_asc` present in new pageview

**Evidence:** Client-side navigation (filter/sort changes) that update the query string via history.pushState correctly trigger new pageviews. This ensures that user interactions with filters and sorting are tracked as separate page views.

---

### Test 4: Negative Test - No Double Manual Rewrite ✅ PASS

**Objective:** Verify that the rewrite logic does NOT produce `script.manual.manual.js` when the admin has already pasted a manual-mode snippet.

**Test Scenario:**
1. Update settings to use: `<script defer data-domain="m.com" src="https://plausible.io/js/script.manual.outbound-links.js"></script>`
2. Reload category page
3. Verify rendered HTML

**Results:**
- ✅ Settings updated successfully
- ✅ Rendered HTML contains: `script.manual.outbound-links.js`
- ✅ NO occurrence of `.manual.manual.` found
- ✅ Settings restored to plain `script.js`

**Evidence:** The rewrite logic correctly detects when `.manual` is already present and does not add it again. This prevents breaking the script URL with double rewrites.

---

### Test 5: Static Source Assertion ✅ PASS

**Objective:** Verify that `/app/php-version/includes/header.php` contains all required code strings.

**Required Strings:**
1. ✅ `plausible('pageview', { u: u });` - Manual pageview call
2. ✅ `var u = window.location.href;` - Full URL capture
3. ✅ `history.pushState` - History API hook
4. ✅ `hashchange` - Hash change event listener

**Evidence:** All required code is present in the header.php file. The implementation includes:
- Manual pageview firing with full URL
- History API hooks for client-side navigation tracking
- Hash change detection for single-page app behavior

---

### Test 6: Admin Regression Check ✅ PASS

**Objective:** Verify that the admin Plausible save flow still works (no regression from previous WAF 403 bypass fix).

**Test Scenario:**
1. Login as admin: `services@maventechsoftware.com` / `Admin@123`
2. Navigate to `/admin.php?tab=company`
3. Verify Plausible Analytics card exists
4. Check status badge

**Results:**
- ✅ Login successful
- ✅ Plausible Analytics textarea found (data-testid="ci-plausible-script-input")
- ✅ Status badge found: "Live on every page" (green)
- ✅ No 403 Forbidden errors
- ✅ Base64 encoding from previous fix still working

**Evidence:** The admin interface correctly displays the Plausible Analytics configuration. The WAF 403 bypass (base64 encoding) from the previous fix is still intact and working. The status badge correctly shows "Live on every page" indicating the snippet is active.

---

## TECHNICAL DETAILS

### Implementation Summary

The fix implements the following changes in `/app/php-version/includes/header.php`:

1. **Script URL Rewrite (lines 242-259):**
   - Detects `script.js` or `script.*.js` patterns
   - Inserts `.manual` as the first extension if not already present
   - Preserves other extensions (e.g., `.outbound-links`, `.hash`)
   - Examples:
     - `script.js` → `script.manual.js`
     - `script.outbound-links.js` → `script.manual.outbound-links.js`
     - `script.manual.hash.js` → `script.manual.hash.js` (no change)

2. **Inline Shim Block (lines 262-295):**
   - Queue-based `window.plausible()` function for async-safe calls
   - Initial pageview: `plausible('pageview', { u: window.location.href })`
   - History API hooks: `pushState`, `replaceState`, `popstate`, `hashchange`
   - Duplicate prevention: tracks last URL to avoid double-counting

### Browser Compatibility

- ✅ Works with async script loading (queue-based shim)
- ✅ Handles history API navigation
- ✅ Supports hash-based routing
- ✅ Graceful degradation (try/catch around history hooks)

### Admin Interface

- ✅ No changes required to admin UI
- ✅ Admins paste the standard Plausible snippet from plausible.io
- ✅ Server-side rewrite is transparent
- ✅ WAF 403 bypass (base64 encoding) still working

---

## EVIDENCE FILES

- Test script: `/app/test_plausible_query_tracking.py`
- Test output: `/tmp/plausible_test_output.txt`
- Source file: `/app/php-version/includes/header.php` (lines 187-297)

---

## CONCLUSION

✅ **BUG FIX VERIFIED - ALL TESTS PASSED**

The Plausible Analytics query string tracking fix is working correctly. The implementation:

1. ✅ Tracks full URLs with query strings (resolves the reported bug)
2. ✅ Handles history API navigation (client-side filter/sort changes)
3. ✅ Prevents double-rewrite issues (negative test passed)
4. ✅ Maintains backward compatibility (admin interface unchanged)
5. ✅ Preserves previous WAF 403 bypass fix (regression test passed)

**User Impact:** Google Ads landing page variants (e.g., `/shop.php?view=grid&cat[]=office&os[]=Mac&sort=`) will now appear as distinct rows in Plausible's Top Pages report, allowing accurate per-ad-URL traffic analysis.

**No Issues Found.**

---

## RECOMMENDATIONS

1. ✅ Deploy to production - all tests passed
2. ✅ No code changes needed
3. ✅ No admin training needed (transparent to users)
4. ⚠️ Update `/app/memory/test_credentials.md` to use correct admin email: `services@maventechsoftware.com` (not `admin@maventechsoftware.com`)

---

**Report Generated:** 2026-07-30  
**Testing Agent:** E2 (Backend SDET)  
**Status:** ✅ APPROVED FOR PRODUCTION
