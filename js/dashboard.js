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

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
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
      const monthSelect = document.getElementById('dashHeaderMonthSelect');
      const mVal = monthSelect ? monthSelect.value : 'all';
      const res = await fetch('api/dashboard.php?month=' + encodeURIComponent(mVal) + '&t=' + Date.now());
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

        const qualityPill = document.getElementById('valQualityPill');
        if (qualityPill) {
          qualityPill.textContent = (k.rejection_rate > 10 ? 'High Rework' : 'Optimal Quality');
          qualityPill.className = 'kpi-chip ' + (k.rejection_rate > 10 ? 'amber' : 'green');
        }

        setVal('valRmStock', Number(k.rm_stock || 0).toLocaleString() + ' KG');
        setVal('valRmCount', (k.rm_count || 0) + ' Types');

        const rmAlertPill = document.getElementById('valRmAlertPill');
        if (rmAlertPill) {
          rmAlertPill.textContent = (k.rm_low_stock > 0 ? k.rm_low_stock + ' Low Stock' : 'Stock Healthy');
          rmAlertPill.className = 'kpi-chip ' + (k.rm_low_stock > 0 ? 'amber' : 'green');
        }

        setVal('valFgStock', Number(k.fg_stock || 0).toLocaleString() + ' Units');
        setVal('valDispatchedQty', Number(k.dispatch_qty || 0).toLocaleString() + ' Units');

        // Update & Animate Live Charts on Refresh
        const barTarget = document.getElementById('perfBarTarget');
        const barOk = document.getElementById('perfBarOk');
        const barRej = document.getElementById('perfBarRej');
        const donutOk = document.getElementById('donutArcOk');
        const donutRej = document.getElementById('donutArcRej');
        const donutYield = document.getElementById('donutYieldNum');

        if (barTarget && barOk && barRej) {
          const valTarget = Number(k.prod_target || 0);
          const valOk = Number(k.prod_ok || 0);
          const valRej = Number(k.prod_rejected || 0);
          const maxVal = Math.max(10, Math.ceil(Math.max(valTarget, valOk, valRej) * 1.25));
          const chartH = 155;

          barTarget.setAttribute('data-target-h', ((valTarget / maxVal) * chartH).toFixed(1));
          barTarget.setAttribute('data-val', valTarget);

          barOk.setAttribute('data-target-h', ((valOk / maxVal) * chartH).toFixed(1));
          barOk.setAttribute('data-val', valOk);

          barRej.setAttribute('data-target-h', ((valRej / maxVal) * chartH).toFixed(1));
          barRej.setAttribute('data-val', valRej);

          const circ = 408.407;
          const totP = valOk + valRej;
          const okPct = totP > 0 ? (valOk / totP) : 1;
          const rejPct = totP > 0 ? (valRej / totP) : 0;

          if (donutOk) donutOk.setAttribute('data-target-dash', (okPct * circ).toFixed(1));
          if (donutRej) donutRej.setAttribute('data-target-dash', (rejPct * circ).toFixed(1));
          if (donutYield) donutYield.setAttribute('data-target-yield', k.yield_rate || 0);

          setVal('donutLegendOk', Number(valOk).toLocaleString());
          setVal('donutLegendOkPct', (k.yield_rate || 0) + '%');
          setVal('donutLegendRej', Number(valRej).toLocaleString());
          setVal('donutLegendRejPct', (k.rejection_rate || 0) + '%');

          if (typeof animateDashboardCharts === 'function') {
            animateDashboardCharts();
          }
        }

        // Update Shop Floor Production Parts (Running & Completed)
        if (Array.isArray(data.running_parts)) {
          const runningContainer = document.getElementById('runningPartsContainer');
          const runningCountText = document.getElementById('runningCountText');
          let inProgCount = 0;
          let compCount = 0;
          data.running_parts.forEach(rp => {
            const targetQ = Number(rp.target_qty || 0);
            const okQ = Number(rp.ok_qty || 0);
            const rejQ = Number(rp.rejected_qty || 0);
            const isDone = (rp.effective_status === 'Completed') || (targetQ > 0 && (okQ + rejQ) >= targetQ);
            if (isDone) compCount++; else inProgCount++;
          });

          if (runningCountText) runningCountText.textContent = inProgCount + ' In Progress';

          if (runningContainer) {
            if (data.running_parts.length === 0) {
              runningContainer.innerHTML = `
                <div class="running-empty-state">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 36px; height: 36px; color: #10b981; margin-bottom: 6px;">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                  </svg>
                  <div style="font-size: 0.90rem; font-weight: 700; color: var(--dash-dark);">No Production Parts Found</div>
                  <div style="font-size: 0.78rem; color: var(--dash-muted); margin-top: 2px;">There are no active or completed shop floor production runs.</div>
                </div>
              `;
            } else {
              let html = '<div class="running-parts-grid">';
              data.running_parts.forEach(rp => {
                const targetQ = Number(rp.target_qty || 0);
                const okQ = Number(rp.ok_qty || 0);
                const rejQ = Number(rp.rejected_qty || 0);
                const uom = escapeHtml(rp.uom || 'NOS');
                const partTitle = escapeHtml(rp.part_name || rp.part_code || '');
                const partCode = escapeHtml(rp.part_code || '');
                const mipNo = escapeHtml(rp.mip_no || '');
                const custName = escapeHtml(rp.customer_name || '-');
                const workOrder = escapeHtml(rp.work_order || '');
                const soNumber = escapeHtml(rp.so_number || '');
                const isDone = (rp.effective_status === 'Completed') || (targetQ > 0 && (okQ + rejQ) >= targetQ);
                const isRework = Boolean(rp.is_rework && (rp.is_rework == 1 || rp.is_rework === '1' || rp.is_rework === true));
                const statusClass = isDone ? 'completed' : 'in-progress';
                const effectiveStatus = isDone ? 'Completed' : 'In Progress';
                const searchKeywords = escapeHtml(`${partTitle} ${partCode} ${mipNo} ${isRework ? 'r rework ' : ''}${custName} ${workOrder} ${soNumber}`.toLowerCase());

                const reworkTag = isRework ? '<span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>' : '';
                const mipBadge = (mipNo && mipNo !== '-') ? `<span class="running-part-mip"><strong>${mipNo}</strong>${reworkTag}</span>` : '';
                const codeBadge = partCode ? `<span class="running-part-code" title="Part Code">${partCode}</span>` : '';
                const statusPill = isDone
                  ? `<span class="running-status-pill comp"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg> Completed</span>`
                  : `<span class="running-status-pill in-prog"><span class="pulse-dot-sm"></span> In Progress</span>`;
                const qtyText = (isDone ? 'Done: ' : 'OK: ') + okQ + ' / ' + targetQ + ' ' + uom;
                const qtyClass = isDone ? 'comp' : 'in-prog';

                html += `
                  <div class="running-card-item ${isDone ? 'is-completed' : 'is-running'}"
                       data-status="${statusClass}"
                       data-search="${searchKeywords}">
                    <div class="running-card-info">
                      <div class="running-part-head">
                        <div class="running-part-title" title="${partTitle}">${partTitle}</div>
                        ${statusPill}
                      </div>
                      <div class="running-part-meta">
                        ${mipBadge}
                        ${codeBadge}
                        <div class="running-part-cust" title="Customer">
                          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                          <span>${custName}</span>
                        </div>
                        <div class="running-part-qty-pill ${qtyClass}" title="Production Quantity">
                          <span>${qtyText}</span>
                        </div>
                      </div>
                    </div>
                    <div class="running-card-actions">
                      <button type="button" class="btn-view"
                              data-id="${encodeURIComponent(rp.id)}"
                              data-mip_no="${encodeURIComponent(mipNo)}"
                              data-is_rework="${isRework ? '1' : '0'}"
                              data-part_name="${escapeHtml(partTitle)}"
                              data-part_code="${escapeHtml(partCode)}"
                              data-customer_name="${escapeHtml(custName)}"
                              data-target_qty="${targetQ}"
                              data-ok_qty="${okQ}"
                              data-rejected_qty="${rejQ}"
                              data-uom="${uom}"
                              data-status="${escapeHtml(effectiveStatus)}"
                              data-created_at="${escapeHtml(rp.entry_date || rp.created_at || '')}"
                              title="View Process Breakdown">
                        View
                      </button>
                    </div>
                  </div>
                `;
              });
              html += '</div>';
              html += `
                <div id="runningNoResults" class="running-empty-state" style="display: none;">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 36px; height: 36px; color: #94a3b8; margin-bottom: 6px;">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                  </svg>
                  <div style="font-size: 0.90rem; font-weight: 700; color: var(--dash-dark);">No matching parts found</div>
                  <div style="font-size: 0.78rem; color: var(--dash-muted); margin-top: 2px;">Try searching with another keyword or MIP number.</div>
                  <button type="button" class="btn-clear-search-link" id="btnResetRunningSearch">Clear Search</button>
                </div>
              `;
              runningContainer.innerHTML = html;
              if (typeof filterRunningCards === 'function') {
                filterRunningCards();
              }
            }
          }
        }

        // Update Recent Shop Floor Production Entries
        if (Array.isArray(data.recent_production)) {
          const tbody = document.getElementById('prodTableBody');
          if (tbody) {
            if (data.recent_production.length === 0) {
              tbody.innerHTML = `<tr><td colspan="10" style="text-align: center; padding: 24px; color: var(--dash-muted);">No production entries found. Start logging with "+ Production Log".</td></tr>`;
            } else {
              tbody.innerHTML = data.recent_production.map(r => {
                const target = Number(r.target_qty || 0);
                const ok = Number(r.ok_qty || 0);
                const rej = Number(r.rejected_qty || 0);
                const rw = Number(r.rework_qty || 0);
                const st = (r.status || '').toLowerCase();
                const isCompleted = (st === 'completed' || st === 'complete')
                  || (target > 0 && (ok + rej) >= target)
                  || (target > 0 && (ok + rej + rw) >= target);
                const statusClass = isCompleted ? 'completed' : 'in-progress';
                const statusText = isCompleted ? 'Completed' : 'In Progress';

                return `
                  <tr>
                    <td>
                      <strong>${escapeHtml(r.mip_no || 'N/A')}</strong>
                      ${r.work_order ? `<div style="font-size: 0.74rem; color: var(--dash-muted);">WO: ${escapeHtml(r.work_order)}</div>` : ''}
                    </td>
                    <td>
                      <div style="font-weight: 700; color: var(--dash-dark);">${escapeHtml(r.part_name || 'Assembly')}</div>
                      <div style="font-size: 0.74rem; color: var(--dash-muted); font-family: monospace;">${escapeHtml(r.part_code || '')}</div>
                    </td>
                    <td>${escapeHtml(r.process_name || 'General')}</td>
                    <td><strong>${escapeHtml(r.shift || 'Shift')}</strong></td>
                    <td>${target.toLocaleString()} ${escapeHtml(r.uom || 'PCS')}</td>
                    <td><strong style="color: #059669;">${ok.toLocaleString()}</strong></td>
                    <td><span style="${rej > 0 ? 'color: #dc2626; font-weight: 700;' : 'color: var(--dash-muted);'}">${rej.toLocaleString()}</span></td>
                    <td>${escapeHtml(r.operator_name || '-')}</td>
                    <td>
                      <span class="badge-status ${statusClass}">${statusText}</span>
                    </td>
                    <td style="font-size: 0.76rem; color: var(--dash-muted);">${escapeHtml(r.created_at || '-')}</td>
                  </tr>
                `;
              }).join('');
            }
          }
        }
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

  // =========================================================
  // Process Workflow Drawer / Slider Controller
  // =========================================================
  const btnCloseSlider = document.getElementById('btnCloseSlider');
  const btnCloseSliderFooter = document.getElementById('btnCloseSliderFooter');
  const sliderOverlay = document.getElementById('processTimelineSliderOverlay');

  function closeProcessSlider() {
    const overlay = document.getElementById('processTimelineSliderOverlay');
    const drawer = document.getElementById('processTimelineSlider');
    if (overlay) overlay.classList.remove('active');
    if (drawer) drawer.classList.remove('active');
    document.body.style.overflow = '';
  }

  if (btnCloseSlider) btnCloseSlider.addEventListener('click', closeProcessSlider);
  if (btnCloseSliderFooter) btnCloseSliderFooter.addEventListener('click', closeProcessSlider);
  if (sliderOverlay) sliderOverlay.addEventListener('click', closeProcessSlider);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      const drawer = document.getElementById('processTimelineSlider');
      if (drawer && drawer.classList.contains('active')) {
        closeProcessSlider();
      }
    }
  });

  function formatDateDMY(dStr) {
    if (!dStr) return '';
    const clean = dStr.toString().trim().split('T')[0].split(' ')[0];
    const p = clean.split('-');
    if (p.length === 3 && p[0].length === 4) {
      return `${p[2]}-${p[1]}-${p[0]}`;
    }
    return dStr;
  }

  async function openProcessSlider(data) {
    const mip = data.mipNo || '';
    const id = data.id || '';
    const targetQty = data.targetQty || 0;
    const uom = data.uom || 'NOS';

    // Update Slider Top KPI Elements (Target, FG Passed, Rejections)
    const elStatTarget = document.getElementById('sldStatTarget');
    const elStatPassed = document.getElementById('sldStatPassed');
    const elStatRejected = document.getElementById('sldStatRejected');

    if (elStatTarget) elStatTarget.textContent = `${targetQty} ${uom}`;
    if (elStatPassed) elStatPassed.textContent = `-`;
    if (elStatRejected) elStatRejected.textContent = `-`;

    // Update Slider Sub-Header Meta
    const elMetaMip = document.getElementById('sldMetaMip');
    const elMetaPart = document.getElementById('sldMetaPart');
    const elMetaDate = document.getElementById('sldMetaDate');
    const isRw = Boolean(data.isRework || (data.is_rework == 1 || data.is_rework === '1' || data.is_rework === true));
    if (elMetaMip) {
      elMetaMip.innerHTML = (escapeHtml(mip || '-')) + (isRw ? ' <span style="margin-left: 4px; font-size: 0.70rem; padding: 1px 6px; border-radius: 9999px; background: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-weight: 700; white-space: nowrap;" title="Rework">R</span>' : '');
    }
    if (elMetaPart) elMetaPart.textContent = `${data.partCode || ''} ${data.partName ? '- ' + data.partName : ''}`.trim() || data.partName || '-';
    if (elMetaDate) elMetaDate.textContent = data.createdAt ? formatDateDMY(data.createdAt) : '-';

    // Direct link to process.php
    const openWfBtn = document.getElementById('btnSliderOpenWorkflow');
    if (openWfBtn) {
      openWfBtn.href = `process.php?id=${encodeURIComponent(id)}&mip=${encodeURIComponent(mip)}`;
    }

    // Update Slider Footer Context
    const elFooterMeta = document.getElementById('sldFooterMeta');
    if (elFooterMeta) {
      const partLbl = escapeHtml(data.partName || data.partCode || '');
      elFooterMeta.innerHTML = mip ? `MIP: <strong style="color: #0f172a;">${escapeHtml(mip)}</strong> &bull; ${partLbl}` : `Shop Floor Live Stage Progress`;
    }

    // Show Skeleton / Loading State
    const container = document.getElementById('sldTimelineStepsContainer');
    if (container) {
      container.innerHTML = `
        <div style="padding: 40px 20px; text-align: center; color: #64748b;">
          <div style="display: inline-block; width: 34px; height: 34px; border: 3px solid #e2e8f0; border-top-color: #2563eb; border-radius: 50%; animation: spin-loop 0.8s linear infinite;"></div>
          <div style="margin-top: 12px; font-weight: 700; font-size: 0.90rem; color: #1e293b;">Loading Workflow Progress &amp; Breakdown...</div>
          <div style="font-size: 0.78rem; color: #94a3b8; margin-top: 4px;">Calculating live stage-by-stage pass &amp; rework percentages</div>
        </div>
      `;
    }

    // Slide Drawer in from Right
    const overlay = document.getElementById('processTimelineSliderOverlay');
    const drawer = document.getElementById('processTimelineSlider');
    if (overlay) overlay.classList.add('active');
    if (drawer) drawer.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Fetch Detailed Breakdown from API
    try {
      const res = await fetch(`api/process_stage.php?entry_id=${encodeURIComponent(id)}&mip_no=${encodeURIComponent(mip)}`);
      const resJson = await res.json();

      if (resJson.entry && resJson.entry.created_at_formatted && elMetaDate) {
        elMetaDate.textContent = resJson.entry.created_at_formatted;
      }

      if (resJson.success && Array.isArray(resJson.stages) && resJson.stages.length > 0) {
        renderProcessTimelineSteps(resJson.stages, resJson.overall, uom);
      } else {
        if (container) {
          container.innerHTML = `
            <div style="padding: 30px 20px; text-align: center; color: #64748b; background: #ffffff; border: 1px dashed #cbd5e1; border-radius: 8px;">
              <div style="font-weight: 700; color: #0f172a; margin-bottom: 4px;">No process stages logged yet</div>
              <div style="font-size: 0.84rem;">Click "Open Full Process Workspace" below to begin recording process progressions.</div>
            </div>
          `;
        }
      }
    } catch (err) {
      if (container) {
        container.innerHTML = `
          <div style="padding: 24px; text-align: center; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; font-size: 0.85rem;">
            Failed to load process progress breakdown. Please try again or open the Process Workspace.
          </div>
        `;
      }
    }
  }

  function renderProcessTimelineSteps(stages, overall, uom) {
    const container = document.getElementById('sldTimelineStepsContainer');
    if (!container) return;
    container.innerHTML = '';

    if (overall) {
      const finalFg = overall.final_fg_qty !== undefined ? overall.final_fg_qty : 0;
      const totalRej = overall.total_rejected !== undefined ? overall.total_rejected : 0;
      const elPassed = document.getElementById('sldStatPassed');
      const elRejected = document.getElementById('sldStatRejected');
      if (elPassed) elPassed.textContent = `${finalFg} ${uom}`;
      if (elRejected) elRejected.textContent = `${totalRej} ${uom}`;
    }

    stages.forEach((st) => {
      const passPct = parseFloat(st.pass_percent || 0);
      const rejPct = parseFloat(st.rej_percent || 0);
      const remPct = parseFloat(st.rem_percent || 0);

      let nodeClass = '';
      let nodeIcon = `${st.order}`;
      let statusBadgeClass = 'step-status-waiting';

      if (st.status === 'Completed') {
        nodeClass = 'completed';
        nodeIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        statusBadgeClass = 'step-status-completed';
      } else if (st.status === 'In Progress') {
        nodeClass = 'in-progress';
        statusBadgeClass = 'step-status-in-progress';
      } else if (st.status === 'Ready') {
        nodeClass = 'ready';
        statusBadgeClass = 'step-status-ready';
      }

      const stepDiv = document.createElement('div');
      stepDiv.className = 'timeline-step';

      stepDiv.innerHTML = `
        <div class="step-node ${nodeClass}">
          ${nodeIcon}
        </div>
        <div class="step-card ${st.status === 'In Progress' ? 'active-stage' : ''}">
          <div class="step-card-header">
            <div class="step-proc-name">
              <span class="step-proc-order">#${st.order}</span>
              <span>${escapeHtml(st.name)}</span>
              ${st.is_final ? '<span style="font-size: 0.68rem; font-weight: 700; color: #16a34a; background: #dcfce7; padding: 1px 6px; border-radius: 4px; border: 1px solid #bbf7d0;">FINAL STAGE</span>' : ''}
            </div>
            <span class="step-status-tag ${statusBadgeClass}">${st.status}</span>
          </div>

          <!-- Simple Clean Breakdown (Non-congested) -->
          <div class="simple-breakdown-row">
            <span class="simple-stat-pill simple-stat-pass">
              <span class="simple-stat-dot" style="background: #2563eb;"></span>
              <span class="simple-stat-label">Passed:</span>
              <strong>${st.pass_qty} ${uom}</strong>
              <span class="simple-stat-pct">${passPct}%</span>
            </span>

            <span class="simple-stat-pill simple-stat-rej">
              <span class="simple-stat-dot" style="background: #8b5cf6;"></span>
              <span class="simple-stat-label">Rejected:</span>
              <strong>${st.rejected_qty} ${uom}</strong>
              <span class="simple-stat-pct">${rejPct}%</span>
            </span>

            ${st.remaining_qty > 0 ? `
              <span class="simple-stat-pill simple-stat-pending">
                <span class="simple-stat-dot" style="background: #94a3b8;"></span>
                <span class="simple-stat-label">Pending:</span>
                <strong>${st.remaining_qty} ${uom}</strong>
                <span class="simple-stat-pct">${remPct}%</span>
              </span>
            ` : ''}
          </div>

          <!-- Sleek Multi-segment Progress Bar -->
          <div class="simple-progress-bar" title="Passed: ${passPct}% | Rejected: ${rejPct}% | Pending: ${remPct}%">
            <div class="simple-bar-seg pass" style="width: ${passPct}%;"></div>
            <div class="simple-bar-seg rej" style="width: ${rejPct}%;"></div>
            <div class="simple-bar-seg rem" style="width: ${remPct}%;"></div>
          </div>

          ${(st.operator || st.date_formatted || st.date) ? `
            <div class="step-operator-meta">
              ${st.operator ? `
                <div class="step-meta-item">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                  <span>Operator: <strong>${escapeHtml(st.operator)}</strong></span>
                </div>
              ` : '<div></div>'}
              ${(st.date_formatted || st.date) ? `
                <div class="step-meta-item step-date-item" title="${escapeHtml(st.date || '')}">
                  <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                  <span>Date: <strong>${escapeHtml(st.date_formatted || formatDateDMY(st.date))}</strong>${st.time_formatted ? ` <span style="color: #94a3b8; font-weight: normal; font-size: 0.68rem;">(${escapeHtml(st.time_formatted)})</span>` : ''}</span>
                </div>
              ` : ''}
            </div>
          ` : ''}
        </div>
      `;

      container.appendChild(stepDiv);
    });
  }

  // =========================================================
  // Shop Floor Parts Search & Filter Controller
  // =========================================================
  function filterRunningCards() {
    const searchInput = document.getElementById('runningPartsSearch');
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const clearBtn = document.getElementById('runningSearchClear');
    if (clearBtn) {
      clearBtn.style.display = query ? 'block' : 'none';
    }

    const cards = document.querySelectorAll('#runningPartsContainer .running-card-item');
    const noResultsEl = document.getElementById('runningNoResults');
    let visibleCount = 0;

    cards.forEach(card => {
      const cardStatus = (card.getAttribute('data-status') || '').toLowerCase();
      const cardSearch = (card.getAttribute('data-search') || '').toLowerCase();

      let isVisible = false;

      if (query.length > 0) {
        // When searching: search across both current running and completed parts
        isVisible = cardSearch.includes(query);
      } else {
        // By default (no search query): ONLY show current running parts
        isVisible = (cardStatus === 'in-progress');
      }

      if (isVisible) {
        card.style.display = '';
        visibleCount++;
      } else {
        card.style.display = 'none';
      }
    });

    if (noResultsEl) {
      if (cards.length > 0 && visibleCount === 0) {
        noResultsEl.style.display = 'flex';
      } else {
        noResultsEl.style.display = 'none';
      }
    }
  }

  // Setup search input listeners
  const runningSearchInput = document.getElementById('runningPartsSearch');
  if (runningSearchInput) {
    runningSearchInput.addEventListener('input', filterRunningCards);
    runningSearchInput.addEventListener('keyup', (e) => {
      if (e.key === 'Escape') {
        runningSearchInput.value = '';
        filterRunningCards();
      }
    });
  }

  // Clear search button
  const runningSearchClear = document.getElementById('runningSearchClear');
  if (runningSearchClear) {
    runningSearchClear.addEventListener('click', () => {
      if (runningSearchInput) {
        runningSearchInput.value = '';
        runningSearchInput.focus();
      }
      filterRunningCards();
    });
  }

  // Document delegation for "Clear Search" reset button inside no results state
  document.addEventListener('click', (e) => {
    if (e.target.closest('#btnResetRunningSearch')) {
      if (runningSearchInput) {
        runningSearchInput.value = '';
        runningSearchInput.focus();
      }
      filterRunningCards();
    }
  });

  // Initial filter run on page load
  filterRunningCards();
  window.filterRunningCards = filterRunningCards;

  // Document-level delegation for .btn-view clicks
  document.addEventListener('click', (e) => {
    const viewBtn = e.target.closest('.btn-view');
    if (!viewBtn) return;

    e.preventDefault();
    e.stopPropagation();

    const data = {
      id: viewBtn.getAttribute('data-id') || '',
      mipNo: viewBtn.getAttribute('data-mip_no') || '',
      isRework: viewBtn.getAttribute('data-is_rework') === '1',
      partName: viewBtn.getAttribute('data-part_name') || '',
      partCode: viewBtn.getAttribute('data-part_code') || '',
      customerName: viewBtn.getAttribute('data-customer_name') || '',
      targetQty: parseFloat(viewBtn.getAttribute('data-target_qty') || 0),
      okQty: parseFloat(viewBtn.getAttribute('data-ok_qty') || 0),
      rejectedQty: parseFloat(viewBtn.getAttribute('data-rejected_qty') || 0),
      uom: viewBtn.getAttribute('data-uom') || 'NOS',
      status: viewBtn.getAttribute('data-status') || 'In Progress'
    };

    openProcessSlider(data);
  });

  window.openProcessSlider = openProcessSlider;
});
