<?php
require_once __DIR__ . '/auth/check_auth.php';
require_once __DIR__ . '/config/db.php';

// Enforce Read permission for Reports
requirePermission('reports', 'read');

$pageTitle = 'Reports & Analytics';
$pdo = getDBConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports & Analytics - WIP Management Portal</title>

  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <!-- Core Dashboard CSS with cache-busting -->
  <link rel="stylesheet" href="css/dashboard.css?v=<?php echo time(); ?>">

  <!-- Reports Page Specialized & Print Styles -->
  <style>
    :root {
      --rep-primary: #2563eb;
      --rep-primary-hover: #1d4ed8;
      --rep-dark: #0f172a;
      --rep-border: #e2e8f0;
      --rep-muted: #64748b;
      --rep-bg: #f8fafc;
    }

    body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
      background-color: var(--rep-bg);
      color: #1e293b;
      margin: 0;
      padding: 0;
    }

    .content-body {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      min-width: 0;
    }

    /* Filter & Controls Card */
    .filter-card {
      background: #ffffff;
      border: 1px solid var(--rep-border);
      border-radius: 12px;
      padding: 18px 20px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      display: flex;
      flex-direction: column;
      gap: 14px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      min-width: 0;
    }

    .filter-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      border-bottom: 1px solid #f1f5f9;
      padding-bottom: 12px;
    }

    .filter-title {
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--rep-dark);
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 0;
    }

    .filter-title svg {
      width: 17px;
      height: 17px;
      color: var(--rep-primary);
    }

    .quick-dates {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }

    .btn-quick-date {
      padding: 4px 9px;
      background: #f1f5f9;
      color: #475569;
      border: 1px solid #e2e8f0;
      border-radius: 6px;
      font-size: 0.74rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-quick-date:hover, .btn-quick-date.active {
      background: #eff6ff;
      color: #2563eb;
      border-color: #bfdbfe;
    }

    .filter-form-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: flex-end;
      width: 100%;
    }

    .filter-field {
      flex: 1 1 180px;
      min-width: 140px;
      display: flex;
      flex-direction: column;
      gap: 5px;
    }

    .filter-label {
      font-size: 0.76rem;
      font-weight: 700;
      color: #334155;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .filter-input {
      height: 38px;
      padding: 0 12px;
      border: 1px solid var(--rep-border);
      border-radius: 8px;
      font-family: inherit;
      font-size: 0.85rem;
      color: #0f172a;
      background: #ffffff;
      outline: none;
      transition: border-color 0.15s ease;
      width: 100%;
      box-sizing: border-box;
    }

    .filter-input:focus {
      border-color: var(--rep-primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .filter-actions {
      flex: 0 0 auto;
      display: flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
    }

    .btn-filter-apply {
      height: 38px;
      padding: 0 16px;
      background: var(--rep-primary);
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background 0.15s ease;
    }

    .btn-filter-apply:hover {
      background: var(--rep-primary-hover);
    }

    .btn-filter-reset {
      height: 38px;
      padding: 0 14px;
      background: #ffffff;
      color: #64748b;
      border: 1px solid var(--rep-border);
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-filter-reset:hover {
      background: #f8fafc;
      color: #0f172a;
    }

    /* Summary KPI Strip */
    .summary-strip {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 12px;
      width: 100%;
      box-sizing: border-box;
      min-width: 0;
    }

    .summary-pill-box {
      background: #ffffff;
      border: 1px solid var(--rep-border);
      border-radius: 10px;
      padding: 12px 16px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
      display: flex;
      flex-direction: column;
      gap: 3px;
      min-width: 0;
    }

    .summary-pill-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--rep-muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .summary-pill-val {
      font-size: 1.35rem;
      font-weight: 800;
      color: var(--rep-dark);
      letter-spacing: -0.02em;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .summary-pill-val.green { color: #059669; }
    .summary-pill-val.red { color: #dc2626; }
    .summary-pill-val.amber { color: #d97706; }
    .summary-pill-val.blue { color: #2563eb; }

    /* Report Data Table Card */
    .report-table-card {
      background: #ffffff;
      border: 1px solid var(--rep-border);
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
      min-width: 0;
    }

    .report-table-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }

    .report-meta-info {
      font-size: 0.86rem;
      color: var(--rep-muted);
    }

    .report-meta-info strong {
      color: var(--rep-dark);
    }

    .export-btn-group {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn-export {
      height: 36px;
      padding: 0 14px;
      border-radius: 8px;
      font-size: 0.82rem;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.15s ease;
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .btn-export-excel {
      background: #ecfdf5;
      color: #047857;
      border: 1px solid #a7f3d0;
    }

    .btn-export-excel:hover {
      background: #d1fae5;
      color: #065f46;
      border-color: #6ee7b7;
      transform: translateY(-1px);
    }

    .btn-export-print {
      background: #ffffff;
      color: #334155;
      border: 1px solid #cbd5e1;
    }

    .btn-export-print:hover {
      background: #f8fafc;
      color: #0f172a;
      border-color: #94a3b8;
      transform: translateY(-1px);
    }


    .btn-export svg {
      width: 16px;
      height: 16px;
    }

    /* Table Container */
    .report-table-wrap {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      border: 1px solid var(--rep-border);
      border-radius: 8px;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    .rep-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.84rem;
      text-align: left;
    }

    .rep-table th {
      background: #f8fafc;
      padding: 10px 14px;
      font-weight: 700;
      color: #475569;
      border-bottom: 2px solid var(--rep-border);
      white-space: nowrap;
    }

    .rep-table td {
      padding: 11px 14px;
      border-bottom: 1px solid #f1f5f9;
      color: #1e293b;
      vertical-align: middle;
      white-space: nowrap;
    }

    .rep-table tr:hover td {
      background: #fafbfc;
    }

    /* Print-Only Elements (Hidden on Web Screen) */
    .print-header,
    .print-footer {
      display: none;
    }

    /* =========================================================
       PRINT STYLES: Professional A4 Landscape Report
       ========================================================= */
    @media print {
      @page {
        size: A4 landscape;
        margin: 8mm 8mm 10mm 8mm;
      }

      *, *::before, *::after {
        box-shadow: none !important;
        text-shadow: none !important;
      }

      html, body {
        background: #ffffff !important;
        color: #0f172a !important;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif !important;
        font-size: 8.5pt !important;
        line-height: 1.3 !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        height: auto !important;
      }

      /* Hide All Web Navigation & Interactive Controls */
      .sidebar,
      .topbar,
      .filter-card,
      .export-btn-group,
      .mobile-close-btn,
      #sidebarBackdrop,
      .quick-dates,
      .report-table-bar,
      button,
      .btn-export {
        display: none !important;
      }

      /* Remove overflow & margins from layout containers so full pages print cleanly */
      .layout-container {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow: visible !important;
        min-height: auto !important;
      }

      .main-content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow: visible !important;
        min-height: auto !important;
      }

      .content-body {
        padding: 0 !important;
        margin: 0 !important;
        gap: 10px !important;
        width: 100% !important;
        max-width: 100% !important;
        overflow: visible !important;
      }

      /* Professional Official Print Letterhead */
      .print-header {
        display: block !important;
        margin-bottom: 12px !important;
        padding-bottom: 8px !important;
        border-bottom: 2pt solid #0f172a !important;
      }

      .print-header-top {
        display: flex !important;
        justify-content: space-between !important;
        align-items: flex-start !important;
        margin-bottom: 8px !important;
      }

      .print-company-name {
        font-size: 9pt !important;
        font-weight: 800 !important;
        letter-spacing: 0.08em !important;
        color: #2563eb !important;
        text-transform: uppercase !important;
      }

      .print-title {
        font-size: 15pt !important;
        font-weight: 800 !important;
        color: #0f172a !important;
        margin: 2px 0 0 0 !important;
      }

      .print-badge-box {
        text-align: right !important;
        border: 1pt solid #cbd5e1 !important;
        padding: 4px 10px !important;
        border-radius: 4px !important;
        background: #f8fafc !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .print-badge-title {
        font-size: 8pt !important;
        font-weight: 800 !important;
        color: #0f172a !important;
      }

      .print-badge-sub {
        font-size: 7pt !important;
        color: #64748b !important;
      }

      .print-meta-grid {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 6px 14px !important;
        background: #f8fafc !important;
        border: 1pt solid #e2e8f0 !important;
        border-radius: 4px !important;
        padding: 8px 12px !important;
        font-size: 8pt !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .print-meta-grid strong {
        color: #334155 !important;
      }

      /* KPI Summary Strip in Print */
      .summary-strip {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 8px !important;
        margin-bottom: 12px !important;
        page-break-inside: avoid !important;
      }

      .summary-pill-box {
        background: #ffffff !important;
        border: 1pt solid #cbd5e1 !important;
        padding: 6px 12px !important;
        border-radius: 4px !important;
        flex: 1 1 auto !important;
        min-width: 120px !important;
        box-shadow: none !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .summary-pill-label {
        font-size: 7pt !important;
        color: #475569 !important;
        text-transform: uppercase !important;
        font-weight: 700 !important;
        margin-bottom: 2px !important;
      }

      .summary-pill-val {
        font-size: 11pt !important;
        font-weight: 800 !important;
        color: #0f172a !important;
      }

      .summary-pill-val.green { color: #047857 !important; }
      .summary-pill-val.red { color: #b91c1c !important; }
      .summary-pill-val.blue { color: #1d4ed8 !important; }

      /* Table Card & Data Table */
      .report-table-card {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 !important;
        background: transparent !important;
      }

      .report-table-wrap {
        border: none !important;
        overflow: visible !important;
        width: 100% !important;
        max-width: 100% !important;
      }

      .rep-table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 8pt !important;
        table-layout: auto !important;
      }

      .rep-table thead {
        display: table-header-group !important;
      }

      .rep-table tr {
        page-break-inside: avoid !important;
      }

      .rep-table th {
        background: #f1f5f9 !important;
        color: #0f172a !important;
        border: 1pt solid #94a3b8 !important;
        padding: 6px 7px !important;
        font-size: 7.8pt !important;
        font-weight: 800 !important;
        text-align: left !important;
        white-space: nowrap !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .rep-table td {
        border: 0.75pt solid #cbd5e1 !important;
        padding: 5px 7px !important;
        color: #1e293b !important;
        font-size: 7.8pt !important;
        vertical-align: middle !important;
        white-space: nowrap !important;
      }

      .rep-table tr:nth-child(even) td {
        background: #f8fafc !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      /* Printable Footer with Sign-off */
      .print-footer {
        display: block !important;
        margin-top: 24px !important;
        page-break-inside: avoid !important;
      }

      .print-footer-grid {
        display: flex !important;
        justify-content: space-between !important;
        align-items: flex-end !important;
        padding-top: 14px !important;
        border-top: 1pt solid #94a3b8 !important;
      }

      .print-footer-note {
        font-size: 7pt !important;
        color: #64748b !important;
        line-height: 1.4 !important;
      }

      .print-signature-box {
        text-align: center !important;
        width: 220px !important;
      }

      .print-signature-line {
        border-bottom: 1pt solid #0f172a !important;
        margin-bottom: 5px !important;
        height: 24px !important;
      }

      .print-signature-label {
        font-size: 7.5pt !important;
        font-weight: 700 !important;
        color: #334155 !important;
      }
    }
  </style>
</head>
<body>

  <!-- Mobile Sidebar Backdrop -->
  <div id="sidebarBackdrop" class="sidebar-backdrop"></div>

  <div class="layout-container">
    
    <!-- Reusable Sidebar Component -->
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
      
      <!-- Reusable Top Bar Header Component -->
      <?php include __DIR__ . '/includes/header.php'; ?>

      <!-- Page Content Body -->
      <div class="content-body">
        
        <!-- Official Printable Letterhead (Shown in Print Only) -->
        <div class="print-header">
          <div class="print-header-top">
            <div>
              <div class="print-company-name">WIP MANAGEMENT PORTAL</div>
              <h1 class="print-title" id="printReportTitle">DAILY PRODUCTION REPORT (DPR)</h1>
            </div>
            <div class="print-badge-box">
              <div class="print-badge-title">OFFICIAL REPORT</div>
              <div class="print-badge-sub" id="printReportId">REF: <?php echo 'RPT-' . date('Ymd-His'); ?></div>
            </div>
          </div>
          <div class="print-meta-grid">
            <div><strong>Report Type:</strong> <span id="printReportTypeLabel">Daily Production Report (DPR)</span></div>
            <div><strong>Filter Period:</strong> <span id="printDateRangeLabel">All Dates</span></div>
            <div><strong>Total Records:</strong> <span id="printTotalRecordsLabel">Loading...</span></div>
            <div><strong>Printed On:</strong> <span id="printGeneratedOnLabel"><?php echo date('d-m-Y H:i:s'); ?></span></div>
            <div><strong>Printed By:</strong> <span><?php echo htmlspecialchars($currentUser['name'] ?? 'Administrator'); ?> (<?php echo htmlspecialchars($currentUser['role'] ?? 'Admin'); ?>)</span></div>
            <div><strong>System Status:</strong> <span style="color: #047857; font-weight: 700;">Verified Active</span></div>
          </div>
        </div>

        <!-- Filter & Search Controls Card -->
        <div class="filter-card">
          <div class="filter-card-top">
            <h2 class="filter-title">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
              </svg>
              Report Filters & Date Range
            </h2>

            <div class="quick-dates">
              <span style="font-size: 0.76rem; font-weight: 700; color: var(--rep-muted); margin-right: 4px;">QUICK RANGE:</span>
              <button type="button" class="btn-quick-date" data-range="today">Today</button>
              <button type="button" class="btn-quick-date" data-range="week">Last 7 Days</button>
              <button type="button" class="btn-quick-date active" data-range="month">This Month</button>
              <button type="button" class="btn-quick-date" data-range="all">All Time</button>
            </div>
          </div>

          <form id="reportFilterForm" class="filter-form-grid" onsubmit="event.preventDefault(); loadReportData();">
            
            <!-- Report Type Dropdown -->
            <div class="filter-field">
              <label class="filter-label" for="reportType">Report Type</label>
              <select id="reportType" class="filter-input" onchange="loadReportData();">
                <option value="production" selected>Daily Production Report (DPR)</option>
                <option value="mip">Material Issue for Production (MIP)</option>
                <!-- <option value="rm_inward">Raw Material (RM) Inward</option> (Hidden) -->
                <option value="child_inward">RM & Child Part Inward</option>
                <option value="fg_dispatch">Finished Goods (FG) Dispatch</option>
                <option value="inventory">Warehouse Inventory Stocks</option>
              </select>
            </div>

            <!-- From Date Picker -->
            <div class="filter-field">
              <label class="filter-label" for="fromDate">From Date</label>
              <input type="date" id="fromDate" class="filter-input">
            </div>

            <!-- To Date Picker -->
            <div class="filter-field">
              <label class="filter-label" for="toDate">To Date</label>
              <input type="date" id="toDate" class="filter-input">
            </div>

            <!-- Search Keyword -->
            <div class="filter-field">
              <label class="filter-label" for="searchKeyword">Search Keyword</label>
              <input type="text" id="searchKeyword" class="filter-input" placeholder="Search part, WO, vendor, shift...">
            </div>

            <!-- Submit / Reset Actions -->
            <div class="filter-actions">
              <button type="submit" class="btn-filter-apply">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 15px; height: 15px;">
                  <circle cx="11" cy="11" r="8"></circle>
                  <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                Generate Report
              </button>
              <button type="button" class="btn-filter-reset" id="resetFiltersBtn">Reset</button>
            </div>

          </form>
        </div>

        <!-- Summary Strip (Dynamic Metrics calculated per filter) -->
        <div class="summary-strip" id="summaryStrip">
          <!-- Populated by JavaScript -->
        </div>

        <!-- Report Data Table & Export Actions Card -->
        <div class="report-table-card">
          <div class="report-table-bar">
            <div>
              <div class="report-meta-info" id="reportResultInfo">
                Showing report data for <strong id="currentReportName">Daily Production Report (DPR)</strong>
              </div>
            </div>

            <!-- Export Buttons: Excel, PDF, Print -->
            <div class="export-btn-group">
              
              <!-- Download Excel / CSV -->
              <a href="#" class="btn-export btn-export-excel" id="downloadExcelBtn" title="Download Excel Spreadsheet (.CSV)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="8" y1="13" x2="16" y2="13"></line>
                  <line x1="8" y1="17" x2="16" y2="17"></line>
                  <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                <span>Export Excel (.csv)</span>
              </a>

              <!-- Direct Print -->
              <button type="button" class="btn-export btn-export-print" id="printReportBtn" title="Print this Report">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <polyline points="6 9 6 2 18 2 18 9"></polyline>
                  <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                  <rect x="6" y="14" width="12" height="8"></rect>
                </svg>
                <span>Print</span>
              </button>

            </div>
          </div>

          <!-- Table Wrapper -->
          <div class="report-table-wrap">
            <table class="rep-table" id="reportDataTable">
              <thead id="reportTableHead">
                <!-- Injected via JS -->
              </thead>
              <tbody id="reportTableBody">
                <tr>
                  <td colspan="12" style="text-align: center; padding: 28px; color: var(--rep-muted);">
                    Loading report data...
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

        </div>

        <!-- Printable Footer (Shown in Print Only) -->
        <div class="print-footer">
          <div class="print-footer-grid">
            <div class="print-footer-note">
              <strong>Official Verification & Compliance Note:</strong><br>
              This is a system-generated production and inventory report from the WIP Management Portal.<br>
              All figures reflect real-time production and warehouse database records.
            </div>
            <div class="print-signature-box">
              <div class="print-signature-line"></div>
              <div class="print-signature-label">Authorized Plant Supervisor / Manager</div>
            </div>
          </div>
        </div>

      </div>

    </div>

  </div>

  <!-- JavaScript for Reports Engine -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const reportTypeSelect = document.getElementById('reportType');
      const fromDateInput = document.getElementById('fromDate');
      const toDateInput = document.getElementById('toDate');
      const searchInput = document.getElementById('searchKeyword');
      const resetBtn = document.getElementById('resetFiltersBtn');
      const downloadExcelBtn = document.getElementById('downloadExcelBtn');
      const printReportBtn = document.getElementById('printReportBtn');
      const quickDateBtns = document.querySelectorAll('.btn-quick-date');

      // Initialize default date range: "This Month"
      setQuickDate('month');

      // Quick Date Range Preset Handler
      function setQuickDate(range) {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, '0');
        const dd = String(today.getDate()).padStart(2, '0');
        const todayStr = `${yyyy}-${mm}-${dd}`;

        if (range === 'today') {
          fromDateInput.value = todayStr;
          toDateInput.value = todayStr;
        } else if (range === 'week') {
          const past7 = new Date();
          past7.setDate(today.getDate() - 7);
          const pyyyy = past7.getFullYear();
          const pmm = String(past7.getMonth() + 1).padStart(2, '0');
          const pdd = String(past7.getDate()).padStart(2, '0');
          fromDateInput.value = `${pyyyy}-${pmm}-${pdd}`;
          toDateInput.value = todayStr;
        } else if (range === 'month') {
          fromDateInput.value = `${yyyy}-${mm}-01`;
          toDateInput.value = todayStr;
        } else if (range === 'all') {
          fromDateInput.value = '';
          toDateInput.value = '';
        }
      }

      quickDateBtns.forEach(btn => {
        btn.addEventListener('click', () => {
          quickDateBtns.forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          setQuickDate(btn.dataset.range);
          loadReportData();
        });
      });

      // Reset Button
      resetBtn.addEventListener('click', () => {
        searchInput.value = '';
        setQuickDate('all');
        quickDateBtns.forEach(b => b.classList.remove('active'));
        document.querySelector('[data-range="all"]').classList.add('active');
        loadReportData();
      });

      // Print and Save as PDF Triggers
      const triggerPrint = () => {
        const selText = reportTypeSelect.options[reportTypeSelect.selectedIndex] ? reportTypeSelect.options[reportTypeSelect.selectedIndex].text : 'Report';
        
        // Update printable letterhead labels
        const pTitle = document.getElementById('printReportTitle');
        if (pTitle) pTitle.textContent = selText.toUpperCase();

        const pType = document.getElementById('printReportTypeLabel');
        if (pType) pType.textContent = selText;

        const f = fromDateInput.value;
        const t = toDateInput.value;
        const pRange = document.getElementById('printDateRangeLabel');
        if (pRange) pRange.textContent = (f || t) ? `From: ${f || 'Start'} To: ${t || 'Present'}` : 'All Dates (Full History)';

        const pGen = document.getElementById('printGeneratedOnLabel');
        if (pGen) {
          const now = new Date();
          pGen.textContent = now.toLocaleDateString('en-GB') + ' ' + now.toLocaleTimeString('en-GB');
        }

        // Get total count
        const rows = document.querySelectorAll('#reportTableBody tr');
        let countText = '0 Records';
        if (rows.length === 1 && rows[0].innerText.includes('Loading')) {
          countText = 'Loading...';
        } else if (rows.length === 1 && rows[0].innerText.includes('No Matching')) {
          countText = '0 Records';
        } else {
          countText = rows.length + ' Records';
        }
        const pTot = document.getElementById('printTotalRecordsLabel');
        if (pTot) pTot.textContent = countText;
        
        window.print();
      };

      if (printReportBtn) printReportBtn.addEventListener('click', triggerPrint);

      // Load Report Data Function
      window.loadReportData = async function() {
        const type = reportTypeSelect.value;
        const from = fromDateInput.value;
        const to = toDateInput.value;
        const q = searchInput.value.trim();

        // Update Excel Download Link
        const exportUrl = `api/reports.php?report_type=${encodeURIComponent(type)}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&search=${encodeURIComponent(q)}&export=excel`;
        downloadExcelBtn.setAttribute('href', exportUrl);

        // Update title badge safely
        const selText = reportTypeSelect.options[reportTypeSelect.selectedIndex] ? reportTypeSelect.options[reportTypeSelect.selectedIndex].text : type;
        const currentReportNameEl = document.getElementById('currentReportName');
        if (currentReportNameEl) {
          currentReportNameEl.textContent = selText;
        }

        const tbody = document.getElementById('reportTableBody');
        tbody.innerHTML = `<tr><td colspan="12" style="text-align: center; padding: 28px; color: var(--rep-muted);">Loading report data...</td></tr>`;

        try {
          const fetchUrl = `api/reports.php?report_type=${encodeURIComponent(type)}&from_date=${encodeURIComponent(from)}&to_date=${encodeURIComponent(to)}&search=${encodeURIComponent(q)}`;
          const res = await fetch(fetchUrl);
          const data = await res.json();

          if (!data.success) {
            tbody.innerHTML = `<tr><td colspan="12" style="text-align: center; padding: 28px; color: #dc2626;">Error: ${escapeHtml(data.message)}</td></tr>`;
            return;
          }

          // 1. Render Summary Strip
          renderSummaryStrip(data.summary);

          // 2. Render Table Headers
          renderTableHeaders(data.columns);

          // 3. Render Table Rows
          renderTableRows(data.rows, data.columns);

          // 4. Update Result Counter safely preserving currentReportName element
          const resultInfoEl = document.getElementById('reportResultInfo');
          if (resultInfoEl) {
            resultInfoEl.innerHTML = `Showing report data for <strong id="currentReportName">${escapeHtml(selText)}</strong> (Found <strong>${data.count}</strong> records${from || to ? ` | From: ${from || 'Start'} To: ${to || 'Present'}` : ''})`;
          }

        } catch (err) {
          console.error(err);
          tbody.innerHTML = `<tr><td colspan="12" style="text-align: center; padding: 28px; color: #dc2626;">Failed to load report data from server.</td></tr>`;
        }
      };

      // Summary Strip Renderer
      function renderSummaryStrip(summary) {
        const strip = document.getElementById('summaryStrip');
        if (!summary || summary.length === 0) {
          strip.innerHTML = '';
          return;
        }

        strip.innerHTML = summary.map(item => {
          let hlClass = '';
          if (item.highlight === 'green') hlClass = 'green';
          else if (item.highlight === 'red') hlClass = 'red';
          else if (item.highlight === 'amber') hlClass = 'amber';
          else if (item.highlight === 'blue') hlClass = 'blue';

          return `
            <div class="summary-pill-box">
              <span class="summary-pill-label">${escapeHtml(item.label)}</span>
              <span class="summary-pill-val ${hlClass}">${escapeHtml(item.value)}</span>
            </div>
          `;
        }).join('');
      }

      // Table Header Renderer
      function renderTableHeaders(cols) {
        const thead = document.getElementById('reportTableHead');
        const colKeys = Object.keys(cols || {});
        thead.innerHTML = `
          <tr>
            <th style="width: 40px; text-align: center;">#</th>
            ${colKeys.map(k => `<th>${escapeHtml(cols[k])}</th>`).join('')}
          </tr>
        `;
      }

      // Table Rows Renderer
      function renderTableRows(rows, cols) {
        const tbody = document.getElementById('reportTableBody');
        const colKeys = Object.keys(cols || {});

        if (!rows || rows.length === 0) {
          tbody.innerHTML = `
            <tr>
              <td colspan="${colKeys.length + 1}" style="text-align: center; padding: 36px; color: var(--rep-muted);">
                <div style="font-size: 1.1rem; font-weight: 700; color: #334155; margin-bottom: 4px;">No Matching Records Found</div>
                <div>Try adjusting your From/To date filter or search query.</div>
              </td>
            </tr>
          `;
          return;
        }

        tbody.innerHTML = rows.map((r, idx) => {
          return `
            <tr>
              <td style="text-align: center; color: var(--rep-muted); font-size: 0.78rem;">${idx + 1}</td>
              ${colKeys.map(k => {
                let val = r[k] ?? '-';
                if (k.includes('qty') || k.includes('stock')) {
                  const num = parseFloat(val);
                  if (!isNaN(num)) val = num.toLocaleString();
                }

                // Status chip styling
                if (k === 'status') {
                  const s = String(val).toLowerCase();
                  if (s.includes('completed') || s.includes('active') || s.includes('passed')) {
                    return `<td><span style="display:inline-block; padding: 2px 7px; border-radius: 999px; background: #ecfdf5; color: #059669; font-size: 0.74rem; font-weight: 700;">${escapeHtml(val)}</span></td>`;
                  } else if (s.includes('progress') || s.includes('issued')) {
                    return `<td><span style="display:inline-block; padding: 2px 7px; border-radius: 999px; background: #eff6ff; color: #2563eb; font-size: 0.74rem; font-weight: 700;">${escapeHtml(val)}</span></td>`;
                  }
                }

                if (k === 'ok_qty') {
                  return `<td><strong style="color: #059669;">${escapeHtml(val)}</strong></td>`;
                }
                if (k === 'rejected_qty') {
                  const num = parseFloat(val);
                  return `<td><span style="${num > 0 ? 'color: #dc2626; font-weight: 700;' : 'color: var(--rep-muted);'}">${escapeHtml(val)}</span></td>`;
                }

                return `<td>${escapeHtml(val)}</td>`;
              }).join('')}
            </tr>
          `;
        }).join('');
      }

      function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      // Initial Load
      loadReportData();
    });
  </script>
</body>
</html>
