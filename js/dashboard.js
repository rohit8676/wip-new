/**
 * WIP Management Portal - Executive Dashboard Controller
 * Offline-first, Zero-dependency live updates and interactive filtering.
 */

document.addEventListener('DOMContentLoaded', () => {
  // Mobile Sidebar Elements
  const menuToggleBtn = document.getElementById('menuToggleBtn');
  const sidebar = document.getElementById('sidebar');
  const sidebarCloseBtn = document.getElementById('sidebarCloseBtn');
  const sidebarBackdrop = document.getElementById('sidebarBackdrop');
  const logoutLink = document.getElementById('logoutLink');
  const refreshDashBtn = document.getElementById('refreshDashBtn');
  const refreshIcon = document.getElementById('refreshIcon');
  const prodSearch = document.getElementById('prodSearch');

  // Mobile Sidebar Navigation
  const openSidebar = () => {
    if (sidebar) sidebar.classList.add('open');
    if (sidebarBackdrop) sidebarBackdrop.classList.add('active');
  };

  const closeSidebar = () => {
    if (sidebar) sidebar.classList.remove('open');
    if (sidebarBackdrop) sidebarBackdrop.classList.remove('active');
  };

  if (menuToggleBtn) menuToggleBtn.addEventListener('click', openSidebar);
  if (sidebarCloseBtn) sidebarCloseBtn.addEventListener('click', closeSidebar);
  if (sidebarBackdrop) sidebarBackdrop.addEventListener('click', closeSidebar);

  // Logout confirmation
  if (logoutLink) {
    logoutLink.addEventListener('click', (e) => {
      if (!confirm('Are you sure you want to log out of the WIP Portal?')) {
        e.preventDefault();
      }
    });
  }

  // -------------------------------------------------------------
  // Real-time Table Search Filtering
  // -------------------------------------------------------------
  if (prodSearch) {
    prodSearch.addEventListener('input', () => {
      const q = prodSearch.value.toLowerCase().trim();
      const rows = document.querySelectorAll('#prodTableBody tr');
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(q) ? '' : 'none';
      });
    });
  }

  // -------------------------------------------------------------
  // Live Dashboard Refresh
  // -------------------------------------------------------------
  async function refreshDashboard() {
    if (refreshIcon) refreshIcon.classList.add('spin-icon-anim');

    try {
      const res = await fetch('api/dashboard.php?t=' + Date.now());
      if (!res.ok) throw new Error('API fetch failed');
      const data = await res.json();

      if (data.success && data.kpi) {
        // Update KPI values smoothly
        const k = data.kpi;
        const setVal = (id, val) => {
          const el = document.getElementById(id);
          if (el) el.textContent = val;
        };

        setVal('valActiveWip', k.wip_active);
        setVal('valTotalIssuedQty', Number(k.wip_issued_qty || 0).toLocaleString() + ' Units');
        setVal('valWipTotalCount', (k.wip_total || 0) + ' Orders');

        setVal('valProdOk', Number(k.prod_ok || 0).toLocaleString());
        setVal('valProdTarget', Number(k.prod_target || 0).toLocaleString());
        setVal('valYieldPill', 'Yield: ' + (k.yield_rate || 0) + '%');

        setVal('valProdRej', Number(k.prod_rejected || 0).toLocaleString());
        setVal('valRejRate', (k.rejection_rate || 0) + '%');

        setVal('valRmStock', Number(k.rm_stock || 0).toLocaleString() + ' KG');
        setVal('valRmCount', (k.rm_count || 0) + ' Types');

        setVal('valFgStock', Number(k.fg_stock || 0).toLocaleString() + ' Units');
        setVal('valDispatchedQty', Number(k.dispatch_qty || 0).toLocaleString() + ' Units');
      }
    } catch (err) {
      console.warn('Silent refresh fallback:', err);
    } finally {
      if (refreshIcon) {
        setTimeout(() => refreshIcon.classList.remove('spin-icon-anim'), 400);
      }
    }
  }

  if (refreshDashBtn) {
    refreshDashBtn.addEventListener('click', () => {
      refreshDashboard();
    });
  }
});
