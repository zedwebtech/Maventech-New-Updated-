#====================================================================================================
# START - Testing Protocol - DO NOT EDIT OR REMOVE THIS SECTION
#====================================================================================================

# THIS SECTION CONTAINS CRITICAL TESTING INSTRUCTIONS FOR BOTH AGENTS
# BOTH MAIN_AGENT AND TESTING_AGENT MUST PRESERVE THIS ENTIRE BLOCK

# Communication Protocol:
# If the `testing_agent` is available, main agent should delegate all testing tasks to it.
#
# You have access to a file called `test_result.md`. This file contains the complete testing state
# and history, and is the primary means of communication between main and the testing agent.
#
# Main and testing agents must follow this exact format to maintain testing data. 
# The testing data must be entered in yaml format Below is the data structure:
# 
## user_problem_statement: {problem_statement}
## backend:
##   - task: "Task name"
##     implemented: true
##     working: true  # or false or "NA"
##     file: "file_path.py"
##     stuck_count: 0
##     priority: "high"  # or "medium" or "low"
##     needs_retesting: false
##     status_history:
##         -working: true  # or false or "NA"
##         -agent: "main"  # or "testing" or "user"
##         -comment: "Detailed comment about status"
##
## frontend:
##   - task: "Task name"
##     implemented: true
##     working: true  # or false or "NA"
##     file: "file_path.js"
##     stuck_count: 0
##     priority: "high"  # or "medium" or "low"
##     needs_retesting: false
##     status_history:
##         -working: true  # or false or "NA"
##         -agent: "main"  # or "testing" or "user"
##         -comment: "Detailed comment about status"
##
## metadata:
##   created_by: "main_agent"
##   version: "1.0"
##   test_sequence: 0
##   run_ui: false
##
## test_plan:
##   current_focus:
##     - "Task name 1"
##     - "Task name 2"
##   stuck_tasks:
##     - "Task name with persistent issues"
##   test_all: false
##   test_priority: "high_first"  # or "sequential" or "stuck_first"
##
## agent_communication:
##     -agent: "main"  # or "testing" or "user"
##     -message: "Communication message between agents"

# Protocol Guidelines for Main agent
#
# 1. Update Test Result File Before Testing:
#    - Main agent must always update the `test_result.md` file before calling the testing agent
#    - Add implementation details to the status_history
#    - Set `needs_retesting` to true for tasks that need testing
#    - Update the `test_plan` section to guide testing priorities
#    - Add a message to `agent_communication` explaining what you've done
#
# 2. Incorporate User Feedback:
#    - When a user provides feedback that something is or isn't working, add this information to the relevant task's status_history
#    - Update the working status based on user feedback
#    - If a user reports an issue with a task that was marked as working, increment the stuck_count
#    - Whenever user reports issue in the app, if we have testing agent and task_result.md file so find the appropriate task for that and append in status_history of that task to contain the user concern and problem as well 
#
# 3. Track Stuck Tasks:
#    - Monitor which tasks have high stuck_count values or where you are fixing same issue again and again, analyze that when you read task_result.md
#    - For persistent issues, use websearch tool to find solutions
#    - Pay special attention to tasks in the stuck_tasks list
#    - When you fix an issue with a stuck task, don't reset the stuck_count until the testing agent confirms it's working
#
# 4. Provide Context to Testing Agent:
#    - When calling the testing agent, provide clear instructions about:
#      - Which tasks need testing (reference the test_plan)
#      - Any authentication details or configuration needed
#      - Specific test scenarios to focus on
#      - Any known issues or edge cases to verify
#
# 5. Call the testing agent with specific instructions referring to test_result.md
#
# IMPORTANT: Main agent must ALWAYS update test_result.md BEFORE calling the testing agent, as it relies on this file to understand what to test next.

#====================================================================================================
# END - Testing Protocol - DO NOT EDIT OR REMOVE THIS SECTION
#====================================================================================================



#====================================================================================================
# Testing Data - Main Agent and testing sub agent both should log testing data below this section

user_problem_statement: |
  Scan manuals.winandoffice.com and, for every product we sell, capture the
  Activation/Sign-in URL, the Installation Guide URL, and the one-click Installer
  Download URL (English, 64-bit). Save them into each product (activation_url /
  install_guide_url / installer_url, mode switched to 'manual') so they appear in
  the delivery email + order/account pages, and add a UI block on the product page.
  Products with no exact manual → closest manual. Bitdefender + McAfee → skipped
  (no manuals on the site).

backend:
  - task: "Seed per-product manual URLs (activation / install-guide / installer) from manuals.winandoffice.com"
    implemented: true
    working: true
    file: "php-version/scripts/seed-manual-urls.php, php-version/database.sql, php-version/start.sh"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Mapped 30 non-antivirus products to their (closest) manual page on manuals.winandoffice.com and seeded activation_url + install_guide_url + installer_url, flipping activation_url_mode/install_url_mode to 'manual'. Office/Project/Visio activation=https://setup.office.com; Windows activation=https://account.microsoft.com/account. Office-for-Mac (8 products) have NULL installer_url (installer is obtained after sign-in). All 7 antivirus products (Bitdefender x6, McAfee x1) intentionally skipped — no manuals exist. Idempotent: only fills rows whose install_guide_url is empty (never clobbers admin edits). Wired into start.sh (runs every boot) and appended to database.sql (fresh imports). Verified via curl: 30 rows have install_url_mode='manual', antivirus rows still NULL. Installer/guide/activation links return HTTP 200/206."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - Database seeding working correctly. Confirmed: (1) Exactly 30 products have install_url_mode='manual' with non-empty install_guide_url. (2) All 8 Office-for-Mac products have non-NULL activation_url + install_guide_url but NULL installer_url (correct - Mac has no one-click installer). (3) All 7 antivirus products (6 Bitdefender + 1 McAfee) have NULL activation_url/install_guide_url/installer_url (correct - intentionally skipped). (4) Spot-checked key products: microsoft-office-2024-professional-plus-windows has correct URLs (Office_2024_EN_64Bits.exe, manuals.winandoffice.com/o24pp/, setup.office.com); windows-11-pro has MediaCreationTool.exe + w11p/ + account.microsoft.com; project-2024 and visio-2021 have correct exe + guide URLs. (5) Link liveness confirmed: Office 2024 installer returns HTTP 206, manual page returns HTTP 200, setup.office.com returns HTTP 200."

frontend:
  - task: "Product page 'Download, install & activate' block (Download installer / Installation guide / Activate-Sign in buttons)"
    implemented: true
    working: true
    file: "php-version/product.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Added a visible card after the product tabs that renders up to 3 buttons from the product's installer_url / install_guide_url / activation_url. Shows all 3 for Windows/PC Office/Project/Visio; for Mac shows Installation guide + Activate only (no one-click installer) plus a hint note; hidden entirely for products without any manual (e.g. Bitdefender). Verified via curl on Office PP (3 buttons, exe + manuals/o24pp + setup.office.com), Mac (2 buttons, no download), Bitdefender (block absent), and a screenshot."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - Product page install blocks rendering correctly via HTTP GET tests. Confirmed: (1) microsoft-office-2024-professional-plus-windows shows 3 buttons with correct hrefs: install-download-btn → Office_2024_EN_64Bits.exe, install-guide-btn → manuals.winandoffice.com/o24pp/, install-activate-btn → setup.office.com. (2) windows-11-pro shows 3 buttons: MediaCreationTool.exe, w11p/, account.microsoft.com/account. (3) microsoft-project-2024-professional-pc shows 3 buttons: project_2024_EN_64Bits.exe, p24p/, setup.office.com. (4) microsoft-visio-2021-professional-windows-pc shows 3 buttons: visio_2021_EN_pro_64Bits.exe, v21p/, setup.office.com. (5) microsoft-office-home-business-2024-mac shows ONLY 2 buttons (install-guide-btn + install-activate-btn), install-download-btn correctly ABSENT. (6) bitdefender-antivirus-for-mac-1-mac-1-year has NO install block (data-testid='product-install-block' not present) - correct."
  - task: "Order-success per-item install/download/activate buttons now populated from manuals data"
    implemented: true
    working: true
    file: "php-version/order-success.php"
    stuck_count: 0
    priority: "medium"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "No code change needed — order-success.php and the delivery email (includes/email.php build_installation_guide_cards) already read activation_url/install_guide_url/installer_url per order item. Now that products are seeded, these surfaces auto-show the manuals links + one-click installer for Microsoft products."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - Order-success page buttons working correctly. Tested demo order MVT-DEMO-002 (contains microsoft-office-home-business-2024-pc). Found 4 buttons total: (1) success-installer-btn → Office_2024_EN_standard_64Bits.exe, (2) success-activate-btn → setup.office.com, (3) success-installguide-btn → manuals.winandoffice.com/o24s/, (4) guide-installer-btn → Office_2024_EN_standard_64Bits.exe. All buttons present with correct hrefs from seeded product data."
  - task: "Native on-site installation guide pages (/install-guide.php) with flowchart + screenshots"
    implemented: true
    working: true
    file: "php-version/install-guide.php, php-version/includes/install-guides.php, php-version/uploads/guides/*"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Built our OWN installation-guide pages (no longer link out to manuals.winandoffice.com). install-guide.php?slug=<slug> renders a branded guide with site header/footer: hero (Download installer + Activate/Sign in buttons), a CSS flowchart stepper, numbered step-by-step instructions with REAL screenshots self-hosted at /uploads/guides/{office,retail,mac,windows}/, system-requirements card, license & activation card, Need-help CTA, plus HowTo JSON-LD. 4 install-flow templates (office_key, office_retail, office_mac, windows) cover all 30 products, personalised with each product's name + its own installer/activation links. Antivirus (no template) gets a graceful fallback."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - All native guide pages working correctly. Tested: (1) microsoft-office-2024-professional-plus-windows: HTTP 200, data-testid='install-guide' present, flowchart present, 6 steps with 5 screenshots in /uploads/guides/office/ (all HTTP 200), hero has Download installer → Office_2024_EN_64Bits.exe + Activate → setup.office.com. (2) microsoft-office-2021-home-business-windows: HTTP 200, 6 steps, ISO/retail flow with 5 screenshots (2 retail/, 3 office/), all HTTP 200. (3) microsoft-office-home-business-2024-mac: HTTP 200, 8 steps, NO Download installer button (correct for Mac), has Activate/Sign in → setup.office.com, 7 screenshots in /uploads/guides/mac/ (all HTTP 200). (4) windows-11-pro: HTTP 200, 6 steps, Download installer → MediaCreationTool.exe + Activate → account.microsoft.com/account, 5 screenshots in /uploads/guides/windows/ (all HTTP 200). (5) bitdefender-antivirus-for-mac-1-mac-1-year: HTTP 200, NO data-testid='install-guide' (correct graceful fallback), shows 'Browse products' + 'Contact support' buttons."
  - task: "Repoint install_guide_url to our own guide page + absolutize for email"
    implemented: true
    working: true
    file: "php-version/scripts/seed-manual-urls.php, php-version/database.sql, php-version/includes/functions.php, php-version/includes/email.php, php-version/email-view.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "main"
        -comment: "Migrated all 30 products install_guide_url from external manuals.winandoffice.com to our own relative /install-guide.php?slug=<slug> (0 external links remain in DB or database.sql). Added mv_absolute_url() helper, applied in email.php + email-view.php so relative guide/installer links become absolute (current host) inside emails."
        -working: true
        -agent: "testing"
        -comment: "✅ VERIFIED - URL migration complete and working correctly. Database checks: (1) 30 products have install_guide_url LIKE '/install-guide.php%' ✅. (2) 0 products have install_guide_url LIKE '%manuals.winandoffice.com%' ✅. (3) 7 antivirus products (6 Bitdefender + 1 McAfee) correctly have NULL install_guide_url/installer_url/activation_url ✅. Product page test (microsoft-office-2024-professional-plus-windows): Installation guide button (data-testid='install-guide-btn') now points to /install-guide.php?slug=microsoft-office-2024-professional-plus-windows (our own page) ✅, while Download installer + Activate buttons remain external (Office_2024_EN_64Bits.exe, setup.office.com) ✅. Order-success page test (MVT-DEMO-002 with microsoft-office-home-business-2024-pc): Installation guide button (data-testid='guide-installguide-btn') points to /install-guide.php?slug=microsoft-office-home-business-2024-pc ✅, Download installer → Office_2024_EN_standard_64Bits.exe ✅, Install & sign in → setup.office.com ✅."


  - task: "Country-scoped license-key inventory (US/UK/CA/AU/EU): separate key pool per product per country + country-aware assignment"
    implemented: true
    working: true
    file: "php-version/includes/regions.php, php-version/includes/email.php, php-version/admin.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "Feature: each product now has a SEPARATE license-key pool per country (US, UK, Canada=CA, Australia=AU, Europe=EU). (1) regions.php: added Australia (AUD/A$) + made AU & EU active; added mv_sales_regions()/mv_normalize_region()/mv_region_label() helpers + AUD FX rate. (2) email.php fulfill_order(): key assignment now filters `AND region = <order country>` so a paid order ONLY ever consumes a key from its own country's pool (US key never goes to UK/AU/CA/EU buyer); order.region is stamped from billing country. STRICT separation — if the country's pool is empty the order is left delivery_status='pending' (backorder), it never falls back to another country. (3) admin.php inventory modal (?tab=products&inv=SLUG): added a Country-pool selector (5 pills US/UK/CA/AU/EU with per-pool available counts) so admin adds/deletes keys for any country pool independently of the storefront region; add_keys/delete_key handlers use the posted key_region. VERIFIED by main via CLI script exercising real fulfill_order(): AU order→AU key, EU order→EU key, US order→US key, US pool consumed exactly 1 (AU/EU never touched US), CA order (no stock)→NO key + delivery_status='pending'. Admin flow verified via authenticated curl: modal renders all 5 pool pills; add_keys with key_region=AU/UK lands keys in the correct pool, US pool untouched. No storefront regression (products use region IN active-regions; US stays active)."
        -working: true
        -agent: "testing"
        -comment: "✅ COMPREHENSIVE VALIDATION COMPLETE - All 4 test items PASSED. (1) REGION CONFIG: Confirmed all 5 regions exist and active=1 via DB query: US(USD), UK(GBP), CA(CAD), AU(AUD), EU(EUR). (2) ADMIN KEY MANAGEMENT: Verified admin inventory page (admin.php?tab=products&inv=windows-11-pro&invregion=AU) contains all 5 country-pool pills (data-testid='inv-region-US/UK/CA/AU/EU'), label 'Country pool <strong>AU', hidden field name='key_region' value='AU', and button 'Add to AU Inventory'. Successfully added 2 keys to AU pool (QATEST-AU-0001, QATEST-AU-0002) and 1 key to UK pool (QATEST-UK-0001) via POST action=add_keys. DB verification: all 3 keys have correct region (AU/UK) and status='available'. US pool for windows-11-pro remained unchanged (6 available keys). (3) COUNTRY-SCOPED ASSIGNMENT: Created AU order (id=9, country='AU', status='paid') for windows-11-pro and triggered fulfillment via admin resend_email action. Verified: order received QATEST-AU-0001 (region='AU', status='sold', order_id=9), NO US key assigned (0 US keys with order_id=9), delivery_status='delivered'. Created CA order (id=10, country='CA', status='paid') for windows-11-pro when NO CA keys exist and triggered fulfillment. Verified: NO key assigned (0 keys with order_id=10), delivery_status='pending' (backorder), did NOT consume any US/AU/UK/EU key (strict separation maintained). (4) CLEANUP: Successfully deleted all QATEST-% license keys, test orders (9, 10), and order_items. DB verification: 0 remaining QATEST keys, 0 remaining QATEST orders. Database restored to original state. STRICT COUNTRY SEPARATION CONFIRMED: Orders only consume keys from their own country pool, no fallback to other countries when pool is empty."

metadata:
  created_by: "main_agent"
  version: "1.0"
  test_sequence: 3
  run_ui: false

  - task: "UI fixes: (a) policy pages phone consistency, (b) admin per-country stock breakdown on product cards, (c) merged checkout Details+Payment into one card"
    implemented: true
    working: true
    file: "php-version/includes/functions.php, php-version/page.php, php-version/admin.php, php-version/checkout.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "(a) PHONE CONSISTENCY BUG: policy pages had the support phone HARDCODED in stored page content (+1 888-632-9902), so Admin→Company Info updates didn't reach them (header showed company_phone=1-805-823-9961). Fix: mv_placeholderize_legacy_page_phones() (functions.php) — a one-time, settings-flag-guarded migration that rewrites the legacy literal number in pages.content to the dynamic {{support_phone}}/{{support_phone_tel}} placeholders; called from page.php. Now Company Info is the single source of truth everywhere. VERIFIED via curl. (b) PER-COUNTRY STOCK: admin.php products tab now shows a per-country available-key breakdown badge row on each product card (US/UK/CA/AU/EU with counts) via a grouped query. VERIFIED via curl: windows-11-pro shows 'US 6, UK 0, CA 0, AU 0, EU 0'. (c) CHECKOUT MERGE: merged 'Your Details' + 'Payment' into ONE card (removed card-1 close + card-2 open, added .co-merge-divider). VERIFIED via curl. (d) ADMIN MODAL OVERLAP (Edit Product etc.): root cause = admin content wrapper is a stacking context (z-index:1) below the sticky admin top bar (z-index:1030), so bootstrap modals (.modal.d-block, z-index 1055) were trapped and their header/X tucked under the top bar. Fix: liftModals script (end of admin.php) moves every .modal.d-block to <body> on load (escapes the stacking context → renders above the top bar) — same proven technique the codebase already uses for the chat overlay — and adds modal-dialog-centered to scrollable modals so they sit in the middle. Edit modal markup also updated to modal-dialog-centered. Covers ALL admin modals (edit-product, inventory/country-pool, staff, plan, subscription). VERIFIED via authenticated curl: edit modal renders 'modal-dialog-scrollable modal-dialog-centered' + liftModals script present. NOTE: automation browser cannot hold cart/admin session against this preview (tool quirk) so screenshots of authed/cart pages redirect; all verification done server-side via curl + DB."

test_plan:
  current_focus: []
  stuck_tasks: []
  test_all: false
  test_priority: "high_first"

  - task: "Responsive product thumbnails (PageSpeed image delivery) — cached on-the-fly resizer + srcset"
    implemented: true
    working: true
    file: "php-version/img.php, php-version/includes/functions.php, php-version/index.php"
    stuck_count: 0
    priority: "medium"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "PageSpeed flagged oversized images (720x720 products shown ~106-200px; 240x240 MS icons shown ~48-90px). Added img.php — a cached WebP thumbnailer (?s=<local path>&w=<width>) via GD, with traversal guards, width whitelist, no-upscale, long cache headers, cached under uploads/cache/thumbs/{w}/. SAFE FALLBACK: if GD unavailable/source missing/already small it 302-redirects to the original, and thumb_url() returns the original when GD isn't loaded — zero regression on any host. Helpers thumb_url()/product_srcset()/product_img_attrs() added. Updated product card renderers (shop grid+rows), homepage featured/related/list images, and hero MS icons to emit src+srcset (1x/2x) at display size + lazy/async + width/height. Added uploads/cache/ to .gitignore. VERIFIED via curl: product 720²→320px 10.7KB→4.4KB (-59%); MS icon 240² PNG 9KB→96px 1.36KB (-85%); shop/home/product render srcset via img.php + 200; traversal blocked (400). NOTE: real host needs PHP-GD (standard) for thumbnails else originals served (no breakage)."

  - task: "PageSpeed mobile: reduce render-blocking CSS + fix non-composited animation & forced reflow (scroll3d)"
    implemented: true
    working: true
    file: "php-version/includes/header.php, php-version/assets/css/scroll3d.css, php-version/assets/js/scroll3d.js"
    stuck_count: 0
    priority: "medium"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "From PageSpeed Insights mobile report (Perf 86, TBT 510ms). Safe fixes for items partly introduced by the scroll3d enhancement: (1) scroll3d.css now loads async via preload+onload swap (+<noscript> fallback) instead of a render-blocking <link> — removes it from the critical render chain (~997ms flagged). (2) scroll3d.css .s3d-tilt no longer transitions box-shadow — only transform is animated (GPU-composited) to satisfy 'avoid non-composited animations'. (3) scroll3d.js tilt caches getBoundingClientRect on mouseenter instead of reading layout every mousemove (fixes 'forced reflow'). VERIFIED via curl: homepage emits the async preload link for scroll3d.css (no blocking stylesheet ref except noscript), assets serve 200, box-shadow transitions removed. NOTE to user: remaining big PageSpeed items are third-party/infra — ~520KiB unused JS is from Google Tag Manager/gtag (would require trimming analytics) and ~147KiB image savings needs resized/responsive product images (image pipeline); left as-is to avoid risk."

  - task: "inventory.php product License Key Inventory — per-country pools (US/UK/CA/AU/EU) filter + region-scoped add/list"
    implemented: true
    working: true
    file: "php-version/inventory.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "Bug: the Inventory Mgmt product page (inventory.php ?view=product&tab=keys) added keys with NO region (all defaulted to US) and listed/counted ALL keys ignoring country, so a US key appeared available for AU too. Fix: add_keys inserts with posted key_region (mv_normalize_region); keys tab has a Country pool selector (US/UK/CA/AU/EU pills w/ per-country available counts); keys list + Available/Sold/Expired + header total scoped to selected country; add form posts key_region + 'Add to <XX> Inventory'; delete/expire preserve country. VERIFIED via curl on windows-11-pro: US pool 'License Keys — US (6)'; adding 2 keys with key_region=AU stored region=AU; AU pool shows those 2 while US still 6 with NO leakage. Cleaned up."

  - task: "Sales Detail customer-lookup filter (name/email/phone/order#/date)"
    implemented: true
    working: true
    file: "php-version/admin.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "Added a filter bar at the top of Admin → Sales Detail. UPDATED: split into SEPARATE fields — Name, Email, Phone, Order# — plus a Region dropdown (All / US / UK / CA / AU / EU) and From/To date range (no longer one combined box). Region dropdown filters to a specific region; 'All regions' searches everywhere; with no filter it keeps the region-scoped default. Amount shows each order's own currency. Result count + empty state included. VERIFIED via authenticated curl: all 8 fields render; f_name=Priya→MVT-DEMO-003; f_email=markhopes0909→MV26070178A5A; f_order=MVT-DEMO-002→that order; f_region=US shows 'in region US' note; f_region=AU→'No orders match'. Date range + combos verified earlier."

agent_communication:
    -agent: "main"
    -message: |
      Please validate (PHP store on the preview URL; backend = PHP endpoints via curl/HTTP):
      1) Product pages render the new install block correctly:
         - microsoft-office-2024-professional-plus-windows → 3 buttons: Download installer (…/Office_2024_EN_64Bits.exe), Installation guide (manuals.winandoffice.com/o24pp/), Activate/Sign in (setup.office.com)
         - windows-11-pro → Download installer (…/MediaCreationTool.exe), guide (…/w11p/), Activate (account.microsoft.com)
         - microsoft-visio-2021-professional-windows-pc and microsoft-project-2024-professional-pc → 3 buttons with visio/project EN 64-bit exe
         - microsoft-office-home-business-2024-mac → ONLY Installation guide + Activate (NO Download installer button)
         - bitdefender-antivirus-for-mac-1-mac-1-year → install block MUST be absent (data-testid="product-install-block" not present)
      2) Confirm the seeded DB values: 30 products have install_url_mode='manual' and non-empty install_guide_url; antivirus products remain NULL.
      3) Spot-check that a couple of installer URLs + guide URLs return HTTP 200/206 (live links).
      4) (If feasible) Place a demo-mode order for an Office product and confirm the order-success page shows the per-item 'Download installer' / 'Install & sign in' / 'Installation guide' buttons.
      Do NOT test or modify anything related to Bitdefender/McAfee mapping — they are intentionally skipped.
    -agent: "testing"
    -message: |
      ✅ ALL BACKEND TESTS PASSED - Complete validation successful.
      
      BACKEND TASK: "Seed per-product manual URLs" - ✅ WORKING
      - Database state verified: 30 products with install_url_mode='manual'
      - All 8 Mac Office products correctly have NULL installer_url
      - All 7 antivirus products (6 Bitdefender + 1 McAfee) correctly have NULL for all manual fields
      - Sample products verified with correct URLs (Office 2024 PP, Windows 11 Pro, Project 2024, Visio 2021)
      - Link liveness confirmed (HTTP 200/206 responses)
      
      FRONTEND TASK: "Product page install block" - ✅ WORKING (tested via HTTP)
      - microsoft-office-2024-professional-plus-windows: 3 buttons with correct hrefs ✅
      - windows-11-pro: 3 buttons with correct hrefs ✅
      - microsoft-project-2024-professional-pc: 3 buttons with correct hrefs ✅
      - microsoft-visio-2021-professional-windows-pc: 3 buttons with correct hrefs ✅
      - microsoft-office-home-business-2024-mac: 2 buttons only (download button correctly absent) ✅
      - bitdefender-antivirus-for-mac-1-mac-1-year: install block correctly absent ✅
      
      FRONTEND TASK: "Order-success buttons" - ✅ WORKING (tested via HTTP)
      - Tested demo order MVT-DEMO-002 (Office Home & Business 2024 PC)
      - Found 4 buttons with correct hrefs: installer, activate, guide, guide-installer
      - All buttons populated from seeded product data ✅
      
      All requirements from the review request have been validated and are working correctly.

    -agent: "main"
    -message: |
      NEW WORK TO VALIDATE — we replaced the external manuals link with our OWN guide pages.
      PHP store on the preview URL; "backend" = PHP endpoints via curl/HTTP. You may read MariaDB locally (mysql -uroot ucode_store).

      1) NATIVE GUIDE PAGE — GET /install-guide.php?slug=<slug> and assert HTTP 200 + the page contains:
         data-testid="install-guide", a flowchart (data-testid="guide-flowchart"), numbered steps (data-testid="guide-step-N"),
         and locally-hosted screenshots (img src starting with /uploads/guides/...). Verify each <img> under /uploads/guides/ returns HTTP 200.
         Test one slug per family:
           - microsoft-office-2024-professional-plus-windows (office_key: 6 steps, Download+Activate buttons, installer .../Office_2024_EN_64Bits.exe)
           - microsoft-office-2021-home-business-windows (office_retail: ISO mount steps)
           - microsoft-office-home-business-2024-mac (office_mac: 8 steps, has Activate/Sign in; Mac has NO one-click installer button on the guide hero)
           - windows-11-pro (windows: MediaCreationTool installer, account.microsoft.com activate)
         And bitdefender-antivirus-for-mac-1-mac-1-year → graceful fallback page (HTTP 200, NO data-testid="install-guide", shows "Contact support").
      2) URL MIGRATION — confirm in DB: SELECT COUNT(*) FROM products WHERE install_guide_url LIKE '/install-guide.php%' == 30, and COUNT(*) WHERE install_guide_url LIKE '%manuals.winandoffice.com%' == 0. Antivirus rows still NULL.
      3) PRODUCT PAGE — GET /product.php?slug=microsoft-office-2024-professional-plus-windows: the "Installation guide" button (data-testid="install-guide-btn") href must now be /install-guide.php?slug=microsoft-office-2024-professional-plus-windows (our own page), while Download installer + Activate stay external.
      4) ORDER-SUCCESS — for demo order MVT-DEMO-002, the "Installation guide" button href should now point to our /install-guide.php?slug=... page (relative), not manuals.winandoffice.com.
      Do NOT modify code. Report pass/fail per item with actual hrefs/status codes observed.

    -agent: "testing"
    -message: |
      ✅ ALL TESTS PASSED - Native guide pages and URL migration fully validated.
      
      FRONTEND TASK: "Native on-site installation guide pages" - ✅ WORKING
      All guide pages tested via HTTP with complete validation:
      
      1) microsoft-office-2024-professional-plus-windows (office_key):
         - HTTP 200 ✅
         - data-testid="install-guide" present ✅
         - data-testid="guide-flowchart" present ✅
         - 6 steps (guide-step-1 through guide-step-6) ✅
         - 5 screenshots in /uploads/guides/office/ (step-run.jpg, step-install.jpg, step-open.jpg, step-changekey.jpg, step-enterkey.jpg) - all HTTP 200 ✅
         - Hero: Download installer → https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_64Bits.exe ✅
         - Hero: Activate → https://setup.office.com ✅
      
      2) microsoft-office-2021-home-business-windows (office_retail):
         - HTTP 200 ✅
         - 6 steps present ✅
         - 5 screenshots (2 in retail/, 3 in office/) - all HTTP 200 ✅
         - ISO/retail flow confirmed ✅
      
      3) microsoft-office-home-business-2024-mac (office_mac):
         - HTTP 200 ✅
         - 8 steps present ✅
         - NO Download installer button (correct for Mac - installer obtained after sign-in) ✅
         - Activate/Sign in button present → https://setup.office.com ✅
         - 7 screenshots in /uploads/guides/mac/ (mac-1.jpg, mac-2.png, mac-3.png, mac-4.png, mac-6.png, mac-7.png, mac-8.png) - all HTTP 200 ✅
      
      4) windows-11-pro (windows):
         - HTTP 200 ✅
         - 6 steps present ✅
         - Download installer → https://download.winandoffice.com/Retail/Desktop/MediaCreationTool.exe ✅
         - Activate → https://account.microsoft.com/account ✅
         - 5 screenshots in /uploads/guides/windows/ (step-media.png, step-settings.jpg, step-change.jpg, step-key.jpg, step-activated.jpg) - all HTTP 200 ✅
      
      5) bitdefender-antivirus-for-mac-1-mac-1-year (no template - graceful fallback):
         - HTTP 200 ✅
         - NO data-testid="install-guide" (correct - graceful fallback page) ✅
         - Shows "Browse products" and "Contact support" buttons ✅
      
      FRONTEND TASK: "Repoint install_guide_url to our own guide page" - ✅ WORKING
      
      Database migration verified:
      - 30 products have install_guide_url LIKE '/install-guide.php%' ✅
      - 0 products have install_guide_url LIKE '%manuals.winandoffice.com%' ✅
      - 7 antivirus products have NULL install_guide_url/installer_url/activation_url ✅
      
      Product page verified (microsoft-office-2024-professional-plus-windows):
      - Installation guide button (data-testid="install-guide-btn") → /install-guide.php?slug=microsoft-office-2024-professional-plus-windows ✅
      - Download installer button → https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_64Bits.exe (external) ✅
      - Activate button → https://setup.office.com (external) ✅
      
      Order-success page verified (MVT-DEMO-002 with microsoft-office-home-business-2024-pc):
      - Installation guide button (data-testid="guide-installguide-btn") → /install-guide.php?slug=microsoft-office-home-business-2024-pc ✅
      - Download installer button → https://download.winandoffice.com/Volume/office/2024/EN/Office_2024_EN_standard_64Bits.exe (external) ✅
      - Install & sign in button → https://setup.office.com (external) ✅
      
      All requirements from the review request validated successfully. No issues found.

#====================================================================================================
    -agent: "main"
    -message: |
      COMPREHENSIVE UI TESTING REQUEST — Test the installation guide feature end-to-end via browser automation.
      Focus ONLY on the new installation guide feature. Test at https://mobile-speed-fix.preview.emergentagent.com
      
      PART 1-4: Test native guide pages, product page block, order history page, and admin filter.

    -agent: "testing"
    -message: |
      ✅ COMPREHENSIVE UI TESTING COMPLETE - All 4 parts validated via browser automation.
      
      PART 1: Native Installation Guide Pages - ✅ ALL PASSED
      Tested 5 guide pages with full visual verification:
      - Office 2024 PP (Windows): ✅ All elements present, 5 screenshots loaded, Download+Activate buttons
      - Office 2021 H&B (Windows): ✅ All elements present, 5 screenshots loaded
      - Office H&B 2024 (Mac): ✅ All elements present, 7 screenshots loaded, NO Download button (correct)
      - Windows 11 Pro: ✅ All elements present, 5 screenshots loaded, Download+Activate buttons
      - Bitdefender (Fallback): ✅ Graceful fallback page, NO install-guide testid, Browse/Contact buttons
      
      PART 2: Product Page Install Block - ✅ PASSED
      - All 3 buttons present with correct hrefs
      - Installation guide button points to /install-guide.php?slug=... (our own page)
      
      PART 3: Order History Page - ✅ PASSED
      - Lookup form works correctly
      - Install card appears with all 3 buttons
      - Installation guide button href contains /install-guide.php?slug=
      
      PART 4: Admin Filter - ✅ VERIFIED VIA DATABASE
      - 7 antivirus products have NULL install_guide_url (Bitdefender x6 + McAfee x1)
      - 30 Microsoft products have non-NULL install_guide_url
      - Filter logic confirmed correct via database queries
      
      All screenshots captured. No broken images or console errors. Feature is production-ready.


    -agent: "main"
    -message: |
      ENHANCEMENTS ADDED (round 2) + FULL BROWSER UI TESTING DONE (auto_frontend_testing_agent):
      1) order-history.php (customer "Track Order & Receipts") now shows a "Download & install" card
         (data-testid="oh-install-card") per purchased item with oh-installer-btn / oh-installguide-btn
         (→ /install-guide.php?slug=...) / oh-activate-btn. Verified with MVT-DEMO-002 (john.demo@example.com).
      2) admin.php Products tab: new "Install guide" filter (data-testid="filter-guide") with options
         "Missing guide URL" / "Has guide URL". Verified: missing = the 7 antivirus products only.
      3) Expanded the Mac (office_mac) guide step captions with richer, more accurate detail.
      4) Closest-match mappings reviewed and kept as-is (sensible: Office Home/Word/Excel → suite/Standard guide).
      Browser UI testing: ALL PASSED — guide pages render flowchart + numbered steps + screenshots load
      (naturalWidth>0), Mac hides Download button, antivirus shows graceful fallback, product page + order
      history link to our own guide page, admin filter logic verified. test_credentials.md populated.


    -agent: "main"
    -message: |
      USER REQUEST: order-success product card should show Download now + Installation guide + Sign in to activate.
      FIXED + verified:
      1) Added a code-level fallback (mv_product_install_meta / mv_resolve_install_links in includes/install-guides.php)
         so installer/guide/activation links resolve from a central mapping even when the products table isn't seeded
         or on the demo-preview path. Applied in order-success.php (both paths), order-history.php, product.php, includes/email.php.
      2) BUG: the green "Download" buttons used Bootstrap class btn-success, but the theme CSS rule
         .btn-success{...;background-image:none !important;} killed the inline gradient AND the shorthand reset
         background-color to transparent -> white-on-white invisible button. Removed btn-success from the 5 download
         buttons (order-success x2, order-history, product.php, install-guide.php) and applied the green gradient +
         color:#fff with !important inline. Verified via getComputedStyle: color rgb(255,255,255), bgImage green gradient.
      3) Renamed the order-success product-card installer button label to "Download now" per request.
      Screenshot confirms all three buttons render on order-success (Sign in to activate / View installation guide / Download now).
      NOTE: the live deployed site needs this code deployed (Save to GitHub -> deploy); the fallback means no DB seed is required for buttons to appear.

    -agent: "main"
    -message: |
      MAC OFFICE GUIDE rebuilt to be accurate (user: "make it according to Mac installation, get real stuff online").
      - Discovered the old Mac screenshots (i1-i13) were actually a Windows-style web-portal flow (mentioned Start Menu),
        which is why the Mac guide was wrong. Removed them.
      - Sourced the OFFICIAL macOS Office installer screenshots from Microsoft's support CDN and re-hosted them at
        /uploads/guides/mac/ (mac-portal-install, mac-dock-pkg, mac-continue, mac-password, mac-success, mac-word-icon,
        mac-getstarted). All serve HTTP 200, naturalWidth>0.
      - Rewrote office_mac template per the real Microsoft flow: Redeem key at setup.office.com -> download .pkg from
        account.microsoft.com -> open installer (.pkg) -> Continue/Agree/Install -> enter Mac password -> success ->
        open Word from Launchpad -> Get Started / Sign in to activate. 8 steps, proper Mac flowchart, uninstall-old-Office tip.
      - Verified: Mac guide renders 8 steps with 7 real macOS screenshots; hero shows Activate/Sign in + Back, NO one-click
        Download button (Mac downloads after sign-in). Screenshot confirms authentic macOS installer password + success screens.

    -agent: "testing"
    -message: |
      ✅ COUNTRY-SCOPED LICENSE KEY INVENTORY - COMPREHENSIVE VALIDATION COMPLETE
      
      Validated the NEW country-scoped license-key inventory feature per the review request. All 4 test items PASSED:
      
      1) ✅ REGION CONFIG: All 5 regions (US, UK, CA, AU, EU) exist and active=1 in database
      2) ✅ ADMIN KEY MANAGEMENT: Admin UI correctly shows all 5 country-pool pills, allows adding keys to specific country pools (tested AU and UK), US pool remains unchanged
      3) ✅ COUNTRY-SCOPED ASSIGNMENT (CORE REQUIREMENT): 
         - AU order (country='AU') received AU key (QATEST-AU-0001), NO US key assigned ✅
         - CA order (country='CA') with NO CA keys available: NO key assigned, delivery_status='pending' (backorder), did NOT fallback to US/AU/UK/EU keys ✅
         - STRICT SEPARATION CONFIRMED: Orders only consume keys from their own country pool
      4) ✅ CLEANUP: All test data removed, database restored to original state
      
      Feature is working correctly as specified. No code modifications made (testing only).

#====================================================================================================
# NEW WORK (continuation) — Checkout country-code box + Receipt/Invoice redesign
#====================================================================================================

  - task: "Checkout: shrink country-code box into a compact +dial-code prefix + modern checkout polish"
    implemented: true
    working: true
    file: "php-version/checkout.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "User: the +1 country-code box on checkout was too big. Removed the fixed 90px width; the flag + dial-code now form ONE snug pill (min 62px / max 74px, caret-only right padding) via .phone-group CSS, so it fits just the code (e.g. flag +1). Also modernised the merged Details+Payment card: gradient step badge, rounded .7rem inputs with cyan focus ring, uppercase micro-labels, selectable payment tiles (.pay-tile active state), larger gutters. Verified via screenshot (rendered checkout with a seeded cart): phone prefix is compact, layout clean/modern."
        -working: true
        -agent: "testing"
        -comment: "✅ COMPREHENSIVE UI TESTING COMPLETE - All checkout changes verified via browser automation at http://localhost:3000. PHONE COUNTRY-CODE CONTROL: Measured width 62px (within required 56-80px range) ✅, CSS confirms min-width:56px max-width:66px ✅, displays just the dial code (e.g. '+1', '+44') ✅, NO separate flag box found (data-testid='phone-flag' does not exist) ✅, phone input sits directly next to selector in same input-group (class='input-group phone-group') ✅, dial code change from +1 to +44 works without JS errors ✅. FORM FUNCTIONALITY: All fields accept input correctly (email, phone, first/last name, address, city, state select, ZIP) ✅, layout is clean and modern with merged Details+Payment card ✅. ACCESSIBILITY FIX: Mobile cart link [data-testid='cart-button-mobile'] has correct aria-label='View cart' ✅. NO CONSOLE ERRORS: Homepage, product page, and checkout all load without JavaScript console errors ✅. Screenshots captured showing compact phone control and filled form. All requirements from review request validated successfully."
        -working: true
        -agent: "testing"
        -comment: "✅ BUG FIX VERIFICATION COMPLETE (2026-07-02) - Verified the NEW implementation where flag is rendered as BACKGROUND IMAGE inside the dial-code select box (not a separate bordered cell). COMPREHENSIVE TESTING RESULTS: (1) ✅ Dial-code control [data-testid='phone-code-select'] exists and displays '+1'. (2) ✅ NO separate flag elements found (confirmed id='phone-flag' and id='phone-flag-img' do NOT exist in DOM). (3) ✅ Select has inline style with '--phone-flag:url(https://flagcdn.com/w40/us.png)' CSS variable. (4) ✅ Computed background-image contains TWO images: flagcdn URL (us.png) + caret SVG, positioned left and right respectively. (5) ✅ Width measured at 74px (within required 66-80px compact range, CSS: min-width:66px max-width:74px). (6) ✅ Zoomed screenshots captured showing flag rendered INSIDE the select box as background (seamless compact pill, no separate divider). (7) ✅ Changed dial-code from +1 to +44: background-image correctly updated from us.png to gb.png, data-iso attribute changed from 'us' to 'gb', NO JavaScript console errors. (8) ✅ Phone-number input [data-testid='phone-number-input'] sits directly beside code box in same .input-group.phone-group container with flex:1 1 auto (fills remaining width), flex-wrap:nowrap (no wrapping/overflow). Visual confirmation: US flag (🇺🇸) shows left of '+1', GB flag (🇬🇧) shows left of '+44', both with caret on right - one seamless box per the bug fix specification. All 8 verification points from review request PASSED."

  - task: "Receipt vs Invoice PDFs — 3 distinct numbers + completely different formats"
    implemented: true
    working: true
    file: "php-version/includes/pdf.php, php-version/includes/functions.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "User: receipt & invoice PDFs looked identical (same template, just a renamed title) and the invoice number equalled the order number. Added mv_invoice_number() (INV-YYYY-000123, sequential) + mv_receipt_number() (RCP-YYMMDD-XXXXXXXX) in functions.php so Order#, Invoice#, Receipt# are all DIFFERENT & stable. Rewrote generate_receipt_pdf() as a GREEN payment-confirmation doc (paid-in-full badge, big amount hero, payment-details card showing all 3 refs + method, light purchase summary, subtotal/discount/total) and generate_invoice_pdf() as an INDIGO formal TAX INVOICE (bordered meta box with Invoice#/Order#/dates/PAID status, From/Bill-To, dark-header ledger, totals with discount row, PAID/DUE watermark stamp, terms). Kept _pdf_shell intact (subscription certificate still uses it) and the $extraBodyHtml receipt param (subscriptions). Verified: both PDFs generate for demo order MVT-DEMO-002 (Order MVT-DEMO-002 / Invoice INV-2026-000002 / Receipt RCP-260612-...), render cleanly (AI structure analysis: green receipt vs indigo tax invoice, no overlap, watermark subtle). PDF filenames unchanged (Receipt-/Invoice-<order_number>.pdf) so admin/order-history/subscription download links still work."

agent_communication:
    -agent: "main"
    -message: |
      Continuation tasks 1 & 3 DONE + self-verified (env had no PHP/MySQL on this fresh pod — installed PHP 8.2 + MariaDB, seeded DB, store live on :3000):
      1) Checkout country-code box shrunk to a compact flag + dial-code prefix; checkout card modernised. Verified via screenshot.
      3) Receipt & Invoice PDFs now completely different designs with 3 distinct numbers (Order#, INV-…, RCP-…). Verified by generating both for MVT-DEMO-002 and AI-analysing the rendered PDFs.
      PENDING task 2 (PageSpeed mobile fixes from the user's PDF) — awaiting go-ahead. Frontend testing not yet run (awaiting user permission).
    -agent: "testing"
    -message: |
      ✅ CHECKOUT PAGE TESTING COMPLETE - All requirements from review request validated successfully.
      
      Tested the checkout page changes and accessibility fixes via comprehensive browser automation at http://localhost:3000.
      
      PHONE COUNTRY-CODE CONTROL (PRIMARY FOCUS):
      ✅ Compact size confirmed: Measured width 62px (within required 56-80px range)
      ✅ CSS constraints verified: min-width:56px, max-width:66px
      ✅ Display format correct: Shows just the dial code (e.g. "+1", "+44")
      ✅ No separate flag box: data-testid="phone-flag" element does not exist (removed as required)
      ✅ Proper layout: Phone input sits directly next to selector in same input-group (class="input-group phone-group")
      ✅ Functionality: Dial code change from +1 to +44 works without JavaScript errors
      ✅ No wrapping/overflow: Phone number input fills remaining width with flex:1 1 auto
      
      FORM FUNCTIONALITY:
      ✅ All fields accept input correctly: email, phone, first/last name, address, city, state (select), ZIP
      ✅ Layout is clean and modern with merged "Your Details" + "Payment" card
      
      ACCESSIBILITY FIX:
      ✅ Mobile cart link [data-testid="cart-button-mobile"] has correct aria-label="View cart"
      
      NO REGRESSIONS:
      ✅ Homepage renders correctly (products, footer)
      ✅ Product page works (add to cart successful)
      ✅ Checkout page accessible without redirect
      ✅ No JavaScript console errors on homepage, product page, or checkout
      
      Screenshots captured showing compact phone control (62px width) and filled checkout form with clean layout.
      All test steps from review request completed successfully. No issues found.

  - task: "PageSpeed mobile: safe accessibility/perf fixes (gtag 404, aria-label, touch targets, composited phone-pulse)"
    implemented: true
    working: true
    file: "php-version/includes/header.php, php-version/assets/css/style.css"
    stuck_count: 0
    priority: "medium"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "From the user's PageSpeed mobile PDF. SAFE in-our-control fixes applied: (1) gtag/GA4/Ads/UET/Clarity IDs now strip ALL internal whitespace (preg_replace /\\s+/) — a mistyped id like 'G 9824E82NN1' was producing a 404 gtag.js URL (console error). (2) Mobile cart link (data-testid=cart-button-mobile) given aria-label='View cart' + icon aria-hidden — fixes 'Links do not have a discernible name' + agentic 'links must have discernible text'. (3) tel:/mailto: links in topbar/footer given min-height:24px + inline-flex — fixes 'Touch targets do not have sufficient size/spacing'. (4) phone-pulse animation converted from box-shadow (non-composited paint) to a ::after ring animated with transform+opacity only — fixes 'Avoid non-composited animations' for phone-pulse. GTM noscript iframe already had a title. Frontend test PASSED (checkout regression + aria-label + no console errors). NOTE: remaining PageSpeed items are deployed-site/third-party and NOT fixable from code here: the actual gtag 404 needs the live-site admin to correct the malformed Google Tag ID (missing hyphen); #merchantwidgetiframe (Google Customer Reviews badge) title is set by Google's own injected script; ~520KiB unused JS is Google Tag Manager/gtag; render-blocking core bootstrap/style.css left as-is to avoid FOUC regression; brand-shimmer/logo-sheen are text-gradient (background-position) effects that can't be GPU-composited without removing the effect."

agent_communication:
    -agent: "main"
    -message: |
      Follow-up done: (1) Phone country-code box made EVEN smaller — removed the flag box entirely (it rendered as "us" text on some devices) leaving a compact 62px "+1" selector. Frontend test confirms 62px width, no flag box, form works, no console errors. (2) Task 2 PageSpeed safe fixes applied (gtag whitespace sanitise → fixes 404 root cause, cart-link aria-label, tel/mailto touch targets, composited phone-pulse). Remaining PageSpeed items are deployed-site/third-party (documented) and require live-site admin action, not code.
    -agent: "testing"
    -message: |
      MOBILE RESPONSIVENESS VERIFICATION COMPLETE (2026-07-02). Tested at 390x844 viewport per review request.
      
      ✅ PASSED (4/5 major checks):
      1. Homepage mobile rendering - All elements visible, no horizontal overflow
      2. Hero abstract animation - Correctly disabled on mobile
      3. Cart link accessibility - aria-label='View cart' present
      4. Checkout mobile regression - Phone control working correctly (flag as background, 66.48px width, no overflow)
      
      ❌ CRITICAL ISSUE (1/5):
      Logo shimmer animation NOT disabled on mobile - animationName='brand-shimmer-dark' instead of 'none'
      
      ROOT CAUSE: CSS specificity conflict in /app/php-version/assets/css/style.css
      - Line 1515: @media (max-width: 768px) sets animation: none !important for .logo-3d .brand-grad
      - Line ~3212: Dark mode rule sets animation: brand-shimmer-dark 8s linear infinite !important
      - Dark mode rule comes AFTER mobile media query, so it wins despite both having !important
      
      FIX NEEDED: Move the mobile media query AFTER all dark mode rules (after line 3220), OR increase specificity by adding [data-bs-theme="dark"] to the mobile media query selector.
      
      ⚠️ MINOR ISSUE (tel: links):
      4 out of 6 tel: links have 0px height - these are in the trustbar (hidden on mobile with d-none d-md-block). The 2 VISIBLE tel: links (mobile contact strip + footer) DO meet the 24px requirement. This is not a functional issue since hidden elements don't need tap targets.
      
      NO JavaScript console errors detected (only expected third-party tracking blocks).

  - task: "Tracking IDs: validate + auto-heal (GTM/GA4/gtag/Ads) so a malformed admin value can't emit a broken gtag.js 404"
    implemented: true
    working: true
    file: "php-version/includes/functions.php, php-version/includes/header.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "User supplied the correct tracking IDs (GTM-N6Q7FKS2, Google tag GT-TQV4X72G, GA4 G-9824E82NN1, Ads AW-18263028048, GA4 stream 15158795356 = informational, not used in tag code). These already match config.php compile-time defaults. Root cause of the live 404 (/gtag/js?id=G 9824E82NN1) = a malformed value with a stray space stored in the admin settings DB that overrode the correct default. Added mv_tracking_id($key,$default,$pattern) in functions.php: strips all whitespace, validates against the id's expected pattern, and FALLS BACK to the known-good compile-time default when the stored value is empty/malformed (valid custom values still honoured). Applied to gtmId + ga4 + gtag + ads in header.php. VERIFIED in preview: homepage renders gtag.js?id=GT-TQV4X72G, GTM-N6Q7FKS2, config G-9824E82NN1 + AW-18263028048 (no stray space). Injected the exact bad value 'G 9824E82NN1' into settings → output auto-healed to 'G-9824E82NN1' with 0 occurrences of the broken value → the gtag 404 is fixed by code alone once deployed."

  - task: "Checkout phone country-code: flag now INSIDE the box (bg image) + smaller pill (bug fix)"
    implemented: true
    working: true
    file: "php-version/checkout.php, php-version/assets/js/main.js"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "User reported the flag looked cramped/cut-off in its own bordered cell beside +1. FIX: removed the separate flag <span>/<img>; flag now renders as a LEFT background-image inside the dial-code <select> (two-layer background: flag left + caret right), passed via --phone-flag CSS var (inline style, updated by syncPhoneFlag() on change). Higher-specificity selector (.co-banner .phone-group .phone-code) beats generic .form-select. Box shrunk (min 66 / max 74px). No separate cell/divider."
        -working: true
        -agent: "testing"
        -comment: "auto_frontend_testing_agent VERIFIED all 8 points PASS: phone-code select present showing +1; NO phone-flag/phone-flag-img elements; inline --phone-flag var + computed background-image includes flagcdn flag + caret; width 74px (compact); changing to +44 updates bg us.png->gb.png with data-iso us->gb and NO console errors; phone input sits beside, flex:1, nowrap, no overflow. Screenshots confirm flag sits inside box left of +1, no divider/cut-off."

  - task: "Mobile responsiveness verification after PageSpeed/accessibility tweaks"
    implemented: true
    working: true
    file: "php-version/assets/css/style.css, php-version/includes/header.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: false
        -agent: "testing"
        -comment: "MOBILE RESPONSIVENESS TESTING (390x844 viewport) - MIXED RESULTS. ✅ PASSED: (1) Homepage renders correctly on mobile - navbar, hero, 12 product cards, footer all present and visible. NO horizontal overflow (docWidth=390px, winWidth=390px). (2) Hero abstract animation correctly DISABLED on mobile (animationName='none'). (3) Cart link accessibility CORRECT - [data-testid='cart-button-mobile'] has aria-label='View cart'. (4) Checkout regression test PASSED - phone country-code control shows '+1', width 66.48px (within 66-80px range), flag rendered as background image (flagcdn URL present), inline --phone-flag CSS variable present, NO horizontal overflow on checkout. ❌ FAILED: (1) Logo shimmer animation NOT disabled on mobile - .logo-3d .brand-grad has animationName='brand-shimmer-dark' (expected 'none'). ROOT CAUSE: Dark mode CSS rule at line ~3212 in style.css has 'animation: brand-shimmer-dark 8s linear infinite !important;' which overrides the mobile media query at line 1515. The mobile @media (max-width: 768px) rule needs to come AFTER dark mode rules or needs higher specificity to override the !important. (2) Tel: links tap target issue - 4 out of 6 tel: links have 0px actualHeight (expected >= 24px). These are in the trustbar which is hidden on mobile (d-none d-md-block class), so they're not visible/tappable. The 2 visible tel: links (mobile contact strip + footer) DO meet the 24px requirement (30.50px and 24.00px). Mailto: links both PASS (32.50px and 24.00px). ℹ️ NO JavaScript console errors (only expected third-party tracking script blocks). Screenshots captured: mobile-homepage-top.png, mobile-homepage-footer.png, mobile-checkout.png."
        -working: true

    -agent: "testing"
    -message: |
      ✅ CSS FIX VERIFICATION COMPLETE (2026-07-02) - Logo shimmer animation bug FIXED.
      
      RE-VERIFIED the mobile logo shimmer animation fix at 390x844 viewport per review request.
      
      INITIAL FINDING: The dark mode fix was working (animationName='none' in dark mode), but there was a NEW bug in LIGHT mode where the animation was showing 'brand-shimmer-dark' instead of 'none'.
      
      ROOT CAUSE: CSS rule at line 3162-3180 (.logo-3d .brand-grad with animation: brand-shimmer-dark !important) was overriding the mobile media query in LIGHT mode due to equal specificity and later source order. The mobile media query had [data-bs-theme="dark"] .logo-3d .brand-grad which had higher specificity for dark mode (so dark mode worked), but only .logo-3d .brand-grad for light mode (same specificity as line 3164, so line 3164 won due to source order).
      
      FIX APPLIED: Added 'body .logo-3d .brand-grad' to the mobile media query (line 1517) to increase specificity and ensure it overrides the general rule at line 3163 'body .brand-grad' in both light and dark modes.
      
      FINAL VERIFICATION RESULTS - ALL PASSED:
      ✅ LIGHT MODE: .logo-3d .brand-grad animationName='none'
      ✅ DARK MODE: .logo-3d .brand-grad animationName='none'
      ✅ Hero abstract: animationName='none' in both modes
      ✅ Homepage renders correctly in dark mode on mobile (navbar/hero/footer visible)
      ✅ No horizontal overflow (390px)
      ✅ No JavaScript console errors
      
      Screenshot captured: mobile-dark-final-verification.png
      
      The CSS fix is now complete and working correctly in both light and dark modes on mobile.

        -agent: "testing"
        -comment: "✅ CSS FIX VERIFIED (2026-07-02) - Logo shimmer animation now correctly disabled on mobile in BOTH light and dark modes. COMPREHENSIVE RE-TESTING at 390x844 viewport: (1) ✅ LIGHT MODE: .logo-3d .brand-grad animationName='none' (previously was 'brand-shimmer-dark'). (2) ✅ DARK MODE: .logo-3d .brand-grad animationName='none' (the original bug fix). (3) ✅ Hero abstract: animationName='none' in both light and dark modes. (4) ✅ Homepage renders correctly in dark mode on mobile - navbar, hero, footer all visible, NO horizontal overflow (390px). (5) ✅ NO JavaScript console errors. ROOT CAUSE ANALYSIS: The CSS rule at line 3162-3180 (.logo-3d .brand-grad with animation: brand-shimmer-dark) was overriding the mobile media query in LIGHT mode due to equal specificity and later source order. FIX APPLIED: Added 'body .logo-3d .brand-grad' to the mobile media query (line 1517) to increase specificity and ensure it overrides the general rule in both light and dark modes. Screenshot captured: mobile-dark-final-verification.png. All requirements from review request now PASSED."

  - task: "PageSpeed mobile round 2: merchant iframe title, touch targets, disable heavy decorative animations on mobile"
    implemented: true
    working: true
    file: "php-version/includes/footer.php, php-version/assets/css/style.css"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: true
        -agent: "main"
        -comment: "From 2nd PageSpeed mobile PDF (Perf 86 / A11y 89 / BP 96). Fixes: (1) Google Customer Reviews badge iframe (#merchantwidgetiframe, injected by Google, no title) — set title='Google Customer Reviews' via polling after merchantwidget.start(). (2) Broadened tel:/mailto: touch-target CSS to ALL such anchors (inline-flex,min-height:24px) + footer link spacing. (3) @media(max-width:768px) disables the continuously-repainting DECORATIVE animations (brand-shimmer/brand-shimmer-dark logo sweep, hero-abstract sway, hero-ring orbit, floating cards/tiles/cubes, watermark) — cuts mobile main-thread Style&Layout + clears non-composited-animation / 'unsupported CSS property background-position-x/box-shadow' best-practice warnings. Kept core bootstrap/style.css synchronous (prior agent found deferring them caused large CLS). Left GTM/GA4/Clarity as-is (already deferred to first-interaction/idle)."
        -working: true
        -agent: "testing"
        -comment: "auto_frontend_testing_agent verified at 390x844 mobile: homepage/hero/footer render, NO horizontal overflow, no console errors; .logo-3d .brand-grad animationName='none' in BOTH light AND dark mode (needed body/.dark specificity bumps to beat later !important shimmer rules); .hero-abstract animationName='none'; cart-button-mobile aria-label='View cart'; visible tel/mailto links >=24px; checkout phone control OK (66px, flag bg). All PASS."

  - task: "TEST MODE checkout simulation + Google review popup removal (issues 2 & 3)"
    implemented: true
    working: true
    file: "php-version/checkout.php, php-version/order-success.php"
    stuck_count: 0
    priority: "high"
    needs_retesting: false
    status_history:
        -working: "NA"
        -agent: "testing"
        -comment: "NEW BUG FIX VALIDATION (2026-07-03). Tested two specific bug fixes per review request: (1) ISSUE 2 - TEST MODE CHECKOUT SIMULATION: Verified that when gw_mode='test' (no real payment gateway configured), the checkout flow successfully simulates a paid order. Added product to cart via fetch (microsoft-office-2024-professional-plus-windows), filled all required checkout fields (email: tester@example.com, phone, name, address, city: San Francisco, state: CA, ZIP: 94107, country: US, payment method: Card, card details with future expiry 12/28). Confirmed TEST MODE banner visible on checkout page. Submitted order and successfully landed on order-success.php?order=MV26070341A74 with order confirmation message 'Thanks for purchasing with us, John!' and order number displayed. ✅ PASS - Test mode correctly simulates successful purchase without real payment processing. (2) ISSUE 3 - GOOGLE REVIEW POPUP REMOVAL: Verified that the Google Customer Reviews opt-in survey popup is NO LONGER auto-loaded on order-success page. Checked page source: 'surveyoptin' NOT present ✅, 'apis.google.com/js/platform.js?onload=renderOptIn' NOT present ✅. Confirmed inline review card IS present with data-testid='success-review-card' ✅, all 5 star buttons present (success-review-star-1 through star-5) ✅. Verified Google share button (#srGoogleShareWrap with data-testid='success-review-google-share') is HIDDEN initially (display:none, is_visible()=false) ✅ - only shown AFTER customer submits a 4-5 star review. No JavaScript console errors detected ✅. ROOT CAUSE: The Google opt-in popup was naggy (appeared on EVERY paid order). Fix: Added setting 'gcr_optin_popup' defaulting to '0' (OFF) in order-success.php line 433, so the opt-in survey is disabled by default. The on-page review card (lines 609-821) now offers a clean 'Post my review on Google' button that only appears AFTER the customer chooses to leave a review (lines 782-794). Both bug fixes working correctly as specified."
