(() => {
  'use strict';

  const NAV = {
    admin: [
      ['dashboard', 'Overview'], ['schedules', 'Schedule'], ['data', 'Academic setup'], ['room-requests', 'Room requests'], ['reports', 'Reports'], ['audit-logs', 'Security & Audit Logs'], ['profile', 'Profile'], ['settings', 'Settings'], ['help', 'Help']
    ],
    scheduler: [
      ['dashboard', 'Overview'], ['schedules', 'Schedule'], ['data', 'Academic setup'], ['reports', 'Reports'], ['profile', 'Profile'], ['settings', 'Settings'], ['help', 'Help']
    ],
    instructor: [
      ['dashboard', 'Overview'], ['schedules', 'My schedule'], ['room-requests', 'Room requests'], ['profile', 'Profile'], ['settings', 'Settings'], ['help', 'Help']
    ],
    student: [
      ['dashboard', 'Overview'], ['schedules', 'My section'], ['profile', 'Profile'], ['settings', 'Settings'], ['help', 'Help']
    ]
  };
  const PAGE_META = {
    dashboard: ['Overview', 'Command center'],
    schedules: ['Published timetable', 'Timetable'],
    'room-requests': ['Instructor tools', 'Room requests'],
    data: ['Master data', 'Academic records'],
    reports: ['Evidence and review', 'Validation reports'],
    profile: ['Account', 'Profile'],
    settings: ['System controls', 'Account controls'],
    help: ['Quick orientation', 'Getting started'],
    'audit-logs': ['Security monitoring', 'Audit logs']
  };
  const BASE_PATH = document.querySelector('meta[name="app-base-path"]')?.content || '';
  const ROUTES = {
    home: '',
    login: 'login',
    register: 'register',
    'forgot-password': 'forgot-password',
    dashboard: 'dashboard',
    schedules: 'schedules',
    'room-requests': 'room-requests',
    data: 'data',
    reports: 'reports',
    profile: 'profile',
    settings: 'settings',
    help: 'help'
    , 'audit-logs': 'audit-logs'
  };
  // Inline SVG only: the content security policy forbids external assets, and
  // font glyphs render inconsistently across the machines used for review.
  const svgIcon = (body, size = 17) => `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${body}</svg>`;
  const PATH = {
    dashboard: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="4.6" rx="1.6"/><rect x="13.5" y="11.1" width="7" height="9.4" rx="1.6"/><rect x="3.5" y="13.1" width="7" height="7.4" rx="1.6"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="15.5" rx="2.4"/><path d="M3.5 10h17M8 3.2v3.4M16 3.2v3.4"/>',
    calendarCheck: '<rect x="3.5" y="5" width="17" height="15.5" rx="2.4"/><path d="M3.5 10h17M8 3.2v3.4M16 3.2v3.4M9.4 15.1l1.9 1.9 3.7-4"/>',
    database: '<ellipse cx="12" cy="6" rx="7.4" ry="2.7"/><path d="M4.6 6v6c0 1.5 3.3 2.7 7.4 2.7s7.4-1.2 7.4-2.7V6"/><path d="M4.6 12v6c0 1.5 3.3 2.7 7.4 2.7s7.4-1.2 7.4-2.7v-6"/>',
    chart: '<path d="M3.5 20.5h17"/><rect x="5" y="11" width="3.6" height="6.6" rx="1.2"/><rect x="10.2" y="6.4" width="3.6" height="11.2" rx="1.2"/><rect x="15.4" y="9" width="3.6" height="8.6" rx="1.2"/>',
    sliders: '<path d="M6 3.5v6.2M6 14.3v6.2M18 3.5v8.2M18 16.3v4.2"/><circle cx="6" cy="12" r="2.3"/><circle cx="18" cy="14" r="2.3"/>',
    book: '<path d="M12 6.6C10.6 5.2 8.7 4.5 6.2 4.5H4.5v13h1.7c2.5 0 4.4.7 5.8 2.1"/><path d="M12 6.6c1.4-1.4 3.3-2.1 5.8-2.1h1.7v13h-1.7c-2.5 0-4.4.7-5.8 2.1"/><path d="M12 6.6v13"/>',
    users: '<circle cx="9.2" cy="8.4" r="3.2"/><path d="M3.6 19.8a5.6 5.6 0 0111.2 0"/><path d="M16.4 5.6a3.2 3.2 0 010 5.8M17.7 19.8a5.7 5.7 0 00-1.8-4.1"/>',
    building: '<path d="M4.6 20.5V5.2a1.7 1.7 0 011.7-1.7h7.5a1.7 1.7 0 011.7 1.7v15.3"/><path d="M15.5 9.6h2.7a1.7 1.7 0 011.7 1.7v9.2"/><path d="M2.8 20.5h18.4"/><path d="M8.1 7.6h3.6M8.1 11.1h3.6M8.1 14.6h3.6"/>',
    success: '<circle cx="12" cy="12" r="8.6"/><path d="M8.4 12.4l2.4 2.4 4.8-5.2"/>',
    error: '<circle cx="12" cy="12" r="8.6"/><path d="M9.2 9.2l5.6 5.6M14.8 9.2l-5.6 5.6"/>',
    warn: '<path d="M12 4.4l8.4 14.6H3.6z"/><path d="M12 9.9v4M12 16.5h.01"/>',
    shield: '<path d="M12 3l7.5 3v5.6c0 4.4-3 8-7.5 9.4-4.5-1.4-7.5-5-7.5-9.4V6z"/><path d="M9 12.2l2.1 2.1L15.2 10"/>',
    close: '<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>',
    empty: '<path d="M3.5 13.6h4.2l1.6 2.6h5.4l1.6-2.6h4.2"/><path d="M3.5 13.6L6.1 5.4h11.8l2.6 8.2v4.3a1.8 1.8 0 01-1.8 1.8H5.3a1.8 1.8 0 01-1.8-1.8z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7M18.5 5.5l-1.7 1.7M7.2 16.8l-1.7 1.7"/>',
    moon: '<path d="M20.5 14.6A8.6 8.6 0 019.4 3.5a8.6 8.6 0 1011.1 11.1z"/>'
  };
  const ICONS = {
    dashboard: svgIcon(PATH.dashboard),
    schedules: svgIcon(PATH.calendar),
    'room-requests': svgIcon(PATH.calendarCheck),
    data: svgIcon(PATH.database),
    reports: svgIcon(PATH.chart),
    profile: svgIcon(PATH.users),
    settings: svgIcon(PATH.sliders),
    help: svgIcon(PATH.book),
    'audit-logs': svgIcon(PATH.shield)
  };
  const METRIC_ICONS = {
    'Active class assignments': PATH.book,
    'Published classes': PATH.calendarCheck,
    Sections: PATH.users,
    Rooms: PATH.building,
    'Faculty used': PATH.users,
    'Rooms used': PATH.building,
    'Sections covered': PATH.dashboard
  };
  const emptyState = (title, detail) => `<div class="empty-state">${svgIcon(PATH.empty, 26)}<strong>${esc(title)}</strong>${esc(detail)}</div>`;
  const state = { snapshot: null, page: 'dashboard', dataTab: 'subjects', scheduleView: 'all', scheduleFilter: 'all', calendarView: 'week', calendarDay: '', query: '', subjectQuery: '', audit: { rows: [], page: 1, pages: 1, total: 0, filters: {} } };
  let registrationSections = [];
  let selectedScheduleTermId = null;
  let selectedScheduleRows = null;
  let selectedScheduleHasRun = null;
  let selectedScheduleValidation = null;
  let selectedScheduleRun = null;
  let selectedScheduleIsSample = false;
  let scheduleHistoryRows = [];
  let scheduleHistoryLoaded = false;
  let scheduleHistoryLoading = false;
  let scheduleTermRequestId = 0;
  let scheduleTermLoading = false;
  let auditRefreshTimer = 0;
  let auditLoadInProgress = false;
  const CALENDAR_LAYOUT_KEY = 'easysched-calendar-layout-v6';
  const LEGACY_CALENDAR_LAYOUT_KEY = 'easysched-calendar-layout';
  const calendarLayout = { timeWidth: null, dayWidths: [], rowHeights: [], columnsAdjusted: false };
  const calendarDetailGroups = new Map();
  let activeCalendarDetailGroup = null;
  let calendarResize = null;
  let calendarResizeFrame = 0;
  let roomAvailabilityCheckId = 0;
  let roomAvailabilityTimer = 0;
  let availableRoomsReady = false;
  let roomRequestAllowed = false;
  let brandPopupReturnFocus = null;
  const CALENDAR_RESIZE_SENSITIVITY = 0.2;
  const HELP_SLIDES = {
    admin: [
      ['Administrator orientation', 'You manage the complete EasySched workspace: users, academic data, schedule generation, reports, and security settings.', ['Start with Academic setup and review the active term before making changes.']],
      ['Maintain the academic setup', 'Create and verify programs, sections, subjects, instructors, rooms, and class assignments. These records are the inputs used by the scheduler.', ['Keep enrollment, room capacity, room type, and required features accurate.']],
      ['Generate and publish', 'Use Auto generate for the full active-term timetable, or Manual generate to replace only offerings matching selected filters while preserving the rest.', ['A failed generation preserves the last published schedule.']],
      ['Review evidence', 'Use Reports to inspect constraint results, room utilization, and the generation record before presenting the timetable.', ['Export or print the reviewed schedule for approval.']],
      ['Manage accounts and security', 'Use Settings to change your password and administer the deployment boundary. Rotate seed credentials and use HTTPS before production use.', ['Review audit records and restrict database access.']]
    ],
    scheduler: [
      ['Scheduler orientation', 'You prepare academic records, generate schedules, and maintain the published timetable without managing user accounts.', ['Begin by checking the active term and master data.']],
      ['Prepare scheduling inputs', 'Review programs, subjects, faculty, rooms, sections, and Class assignments before generating. Each program has Years 1 through 4 with Sections A and B.', ['Incomplete or conflicting inputs can make generation impossible.']],
      ['Generate a schedule', 'Auto generate builds the full timetable. Manual generate lets you filter by program, section, subject, and instructor; matching offerings are replaced while the rest are preserved.', ['Both options validate room, time, availability, section, and instructor constraints before publication.']],
      ['Review and edit safely', 'Use the weekly schedule filters to inspect by section, instructor, or room. Add a missing required meeting or edit an existing entry; both actions are checked against the same hard constraints.', ['Regenerate when the academic inputs have materially changed.']],
      ['Reports and handoff', 'Use Reports and CSV/print export to document room utilization, validation results, and the final timetable.', ['Keep the published run as the approved reference.']]
    ],
    instructor: [
      ['Instructor orientation', 'Your workspace shows assigned classes, the published timetable, and a dedicated Room requests tab.', ['Use My schedule for confirmed meetings and Room requests for proposed changes.']],
      ['Read the weekly schedule', 'My schedule shows subjects, sections, rooms, exact dates, weekdays, and hourly time slots.', ['Lecture, laboratory, and other room types use different calendar colors.']],
      ['Request a room or schedule change', 'The Room requests page lets you request a room for a specific date or propose a different weekday, time, or room for one of your published classes.', ['Schedule changes keep the current class duration and must use a configured start time.']],
      ['Track approval', 'Your separate request histories show Pending, Approved, or Rejected. Administrators review both request types, and approved changes update the published timetable.', ['Every proposed schedule is checked against room, instructor, section, and time conflicts.']],
      ['Account settings', 'Use Settings to change your password and keep your account secure.', ['Never share your password or use another person’s account.']]
    ],
    student: [
      ['Student orientation', 'Your workspace shows the published timetable for your assigned section.', ['Use My section to find your classes quickly.']],
      ['Read your timetable', 'The weekly schedule shows each subject, instructor, room, exact date, weekday, and hourly time slot for your section.', ['Lecture, laboratory, and other room types use different calendar colors.']],
      ['Understand your section', 'Each program has two sections per year level, such as Section A and Section B, for Years 1 through 4.', ['Your published classes are filtered to your assigned section.']],
      ['Trust the published schedule', 'Schedules are checked for room, instructor, section, capacity, and time conflicts before publication.', ['Ask your program office about corrections or changes.']],
      ['Account settings', 'Use Settings to change your password and keep your account secure.', ['Contact an administrator if your section or account details are wrong.']]
    ]
  };
  const helpSlides = [
    ['Welcome to EasySched', 'Use this workspace to prepare academic data, generate conflict-aware schedules, and publish a timetable your campus can trust.', ['Start with Academic setup, then generate and review the schedule.']],
    ['Know your role', 'Administrators and schedulers maintain data and generate schedules. Instructors view assigned classes. Students view their section schedule.', ['Your account controls which pages and records are available.']],
    ['Prepare the academic setup', 'Maintain the active term, four programs, 16 subjects, faculty, seven room types, sections, and Class assignments before generating.', ['Each program uses Years 1 through 4 with two sections per year.']],
    ['Create Class assignments', 'Connect a subject to a section, instructor, academic term, enrollment count, and meetings per week. These are the actual classes the scheduler places.', ['Subjects define the curriculum; Class assignments define what runs this term.']],
    ['Generate a schedule', 'Use Auto generate for the full timetable or Manual generate to select programs, sections, subjects, and instructors. Manual generation replaces matching offerings and preserves all others.', ['A failed generation never replaces the last published schedule.']],
    ['Review the weekly timetable', 'Use the view and filter controls to inspect schedules by section, instructor, or room. Calendar blocks show their exact start and end times and span the full class duration.', ['Conflicts are rejected before publication.']],
    ['Publish and manage changes', 'Add missing required meetings or edit existing entries; manual placements are validated against the same conflict rules before saving.', ['Keep the published run stable while testing alternatives.']],
    ['Reports and exports', 'Reports show constraint checks and room utilization. Export the visible schedule as CSV or print a clean timetable for review.', ['Apply filters before exporting when you need a focused report.']],
    ['Account security and support', 'Change your password in Settings. Use HTTPS in deployment, keep database secrets server-side, and contact an administrator when access or data needs review.', ['Return to this Help center any time from the sidebar.']]
  ];
  let helpIndex = 0;
  let modalMode = null;
  let modalRecord = null;
  let modalReturnFocus = null;
  let registrationOpen = false;
  let requestCalendarMonth = null;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const text = (value) => String(value ?? '').trim();
  const role = () => state.snapshot?.user?.role || '';
  const canManage = () => ['admin', 'scheduler'].includes(role());
  const canSeeGeneration = () => canManage();
  const canAdmin = () => role() === 'admin';
  const days = () => Object.entries(state.snapshot?.days || {}).sort((a, b) => Number(a[0]) - Number(b[0]));
  const slots = () => state.snapshot?.time_slots || [];
  const schedules = () => state.snapshot?.schedules || [];
  const scheduleViewRows = () => selectedScheduleRows || schedules();
  const subjectById = (id) => (state.snapshot?.subjects || []).find((item) => Number(item.id) === Number(id));

  async function request(action, options = {}) {
    const method = options.method || 'GET';
    const headers = { Accept: 'application/json' };
    let url = `api.php?action=${encodeURIComponent(action)}`;
    if (options.query) url += `&${new URLSearchParams(options.query).toString()}`;
    const init = { method, headers, credentials: 'same-origin' };
    if (method !== 'GET') {
      headers['Content-Type'] = 'application/json';
      const payload = { ...(options.body || {}), csrf: state.snapshot?.csrf || '' };
      init.body = JSON.stringify(payload);
    }

    const response = await fetch(url, init);
    const contentType = response.headers.get('content-type') || '';
    if (action === 'export' && !contentType.includes('application/json')) return response;
    let payload;
    try { payload = await response.json(); } catch { throw new Error('The server returned an invalid response.'); }
    if (response.status === 401) {
      state.snapshot = null;
      if (!registrationOpen && action !== 'registration_options' && action !== 'bootstrap') showLogin();
    }
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || 'The request could not be completed.');
      error.details = payload.details || {};
      error.status = response.status;
      throw error;
    }
    return payload.data;
  }

  async function changeScheduleTerm() {
    const select = $('#scheduleTermFilter');
    const nextTermId = Number(select.value);
    if (!Number.isInteger(nextTermId) || nextTermId < 1 || (nextTermId === Number(selectedScheduleTermId) && !selectedScheduleRun && !selectedScheduleIsSample)) return;

    const requestId = ++scheduleTermRequestId;
    scheduleTermLoading = true;
    select.value = String(selectedScheduleTermId);
    renderSchedules();
    try {
      const result = await request('schedule_term', { query: { term_id: String(nextTermId) } });
      if (requestId !== scheduleTermRequestId) return;
      selectedScheduleTermId = Number(result.term_id);
      selectedScheduleRows = result.schedules || [];
      selectedScheduleHasRun = Boolean(result.has_published_run);
      selectedScheduleValidation = result.validation || null;
      selectedScheduleRun = null;
      selectedScheduleIsSample = Boolean(result.is_sample);
      state.scheduleFilter = 'all';
    } catch (error) {
      if (requestId !== scheduleTermRequestId) return;
      showToast('Could not load academic term', error.message, 'error');
    } finally {
      if (requestId === scheduleTermRequestId) {
        scheduleTermLoading = false;
        renderSchedules();
      }
    }
  }

  async function loadScheduleHistory() {
    if (scheduleHistoryLoaded || scheduleHistoryLoading) return;
    scheduleHistoryLoading = true;
    renderScheduleHistory();
    try {
      scheduleHistoryRows = await request('schedule_history') || [];
      scheduleHistoryRows.sort((left, right) =>
        String(right.academic_year).localeCompare(String(left.academic_year))
        || String(right.semester).localeCompare(String(left.semester))
        || String(right.created_at || '').localeCompare(String(left.created_at || ''))
      );
      scheduleHistoryLoaded = true;
    } catch (error) {
      showToast('Could not load schedule history', error.message, 'error');
    } finally {
      scheduleHistoryLoading = false;
      renderScheduleHistory();
    }
  }

  function renderScheduleHistory() {
    const body = $('#scheduleHistoryBody');
    if (!body) return;
    if (scheduleHistoryLoading) {
      body.innerHTML = '<tr><td colspan="5">Loading schedule history…</td></tr>';
      return;
    }
    if (!scheduleHistoryLoaded) {
      body.innerHTML = '<tr><td colspan="5">Schedule history has not been loaded.</td></tr>';
      return;
    }
    if (!scheduleHistoryRows.length) {
      body.innerHTML = '<tr><td colspan="5">No saved schedule history is available yet.</td></tr>';
      return;
    }
    body.innerHTML = scheduleHistoryRows.map((run) => {
      const term = `${run.academic_year} · ${run.semester}`;
      const savedAt = run.is_sample ? 'Illustrative example' : new Date(String(run.created_at).replace(' ', 'T')).toLocaleString();
      const status = run.is_sample ? 'Sample only' : run.status === 'PUBLISHED' ? 'Current' : 'Archived';
      const selected = run.is_sample
        ? selectedScheduleIsSample && Number(selectedScheduleTermId) === Number(run.term_id)
        : Number(selectedScheduleRun?.id) === Number(run.run_id);
      const action = run.is_sample
        ? `<button class="button button-ghost button-small" type="button" data-schedule-history-sample="${Number(run.term_id)}" ${selected ? 'disabled' : ''}>${selected ? 'Viewing sample' : 'View sample'}</button>`
        : `<button class="button button-ghost button-small" type="button" data-schedule-history-run="${Number(run.run_id)}" ${selected ? 'disabled' : ''}>${selected ? 'Viewing' : 'View'}</button>`;
      return `<tr><td>${esc(term)}</td><td>${esc(savedAt)}</td><td>${esc(run.class_count)}</td><td><span class="badge ${run.is_sample ? 'badge-warning' : run.status === 'PUBLISHED' ? 'badge-neutral' : 'badge-warning'}">${status}</span></td><td>${action}</td></tr>`;
    }).join('');
  }

  async function viewSampleSchedule(termId) {
    const select = $('#scheduleTermFilter');
    if (!select) return;
    select.value = String(termId);
    await changeScheduleTerm();
    $('#calendarGrid').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  async function viewScheduleHistory(runId) {
    try {
      const result = await request('schedule_run', { query: { run_id: String(runId) } });
      selectedScheduleTermId = Number(result.term_id);
      selectedScheduleRows = result.schedules || [];
      selectedScheduleHasRun = Boolean(result.has_published_run);
      selectedScheduleValidation = result.validation || null;
      selectedScheduleRun = { id: Number(result.run_id), status: result.status, created_at: result.created_at };
      selectedScheduleIsSample = false;
      state.scheduleFilter = 'all';
      renderSchedules();
    } catch (error) {
      showToast('Could not open saved schedule', error.message, 'error');
    }
  }

  function auditFilterValues() {
    return {
      from: $('#auditFrom').value, to: $('#auditTo').value, q: $('#auditQuery').value.trim(),
      severity: $('#auditSeverity').value, outcome: $('#auditOutcome').value, target_type: $('#auditTarget').value,
      page: state.audit.page, limit: 25
    };
  }

  async function auditRequest(action, params = {}) {
    const query = new URLSearchParams({ action, ...params });
    const response = await fetch(`api.php?${query.toString()}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok || payload.ok === false) {
      const error = new Error(payload.error || 'The audit request could not be completed.');
      error.status = response.status;
      throw error;
    }
    return payload.data;
  }

  function renderAuditRows(rows) {
    const body = $('#auditBody');
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="9" class="empty-state">No audit events match the selected filters.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((row) => `<tr>
      <td>${esc(row.occurred_at_utc)}</td>
      <td>${esc(row.display_name || row.username || 'System')}</td>
      <td>${esc(row.username || '—')}</td>
      <td><strong>${esc(row.action)}</strong></td>
      <td>${esc(row.entity_type || '—')}${row.entity_id ? ` #${esc(row.entity_id)}` : ''}</td>
      <td><span class="audit-badge audit-${esc(String(row.severity).toLowerCase())}">${esc(row.severity)}</span></td>
      <td>${esc(row.outcome)}</td><td>${esc(row.source_ip || 'Unavailable')}</td>
      <td><button class="button button-ghost button-small" type="button" data-audit-detail="${esc(row.id)}">Details</button></td>
    </tr>`).join('');
  }

  async function loadAuditLogs(silent = false) {
    if (!canAdmin() || auditLoadInProgress) return;
    auditLoadInProgress = true;
    const filters = silent && state.audit.filters
      ? { ...state.audit.filters, page: state.audit.page, limit: 25 }
      : auditFilterValues();
    if (!silent) $('#auditStatus').textContent = 'Loading audit events…';
    try {
      const [summary, result] = await Promise.all([auditRequest('audit_summary', filters), auditRequest('audit_logs', filters)]);
      state.audit = { ...result, filters };
      $('#auditTotal').textContent = summary.total ?? 0; $('#auditFailedLogins').textContent = summary.failed_logins ?? 0;
      $('#auditDenied').textContent = summary.denied ?? 0; $('#auditWarnings').textContent = summary.warnings ?? 0; $('#auditCritical').textContent = summary.critical ?? 0;
      renderAuditRows(result.rows || []);
      $('#auditPageLabel').textContent = `Page ${result.page} of ${result.pages}`;
      $('#auditPreviousButton').disabled = result.page <= 1; $('#auditNextButton').disabled = result.page >= result.pages;
      $('#auditStatus').textContent = `${result.total} event${result.total === 1 ? '' : 's'} found.`;
    } catch (error) {
      $('#auditStatus').textContent = error.message;
      if (!silent) $('#auditBody').innerHTML = '<tr><td colspan="9" class="empty-state">Audit events could not be loaded.</td></tr>';
    } finally {
      auditLoadInProgress = false;
    }
  }

  async function showAuditDetail(id) {
    try {
      const row = await auditRequest('audit_detail', { id });
      const details = Object.entries(row.details || {}).map(([key, value]) => `<dt>${esc(key)}</dt><dd>${esc(typeof value === 'object' ? JSON.stringify(value) : value)}</dd>`).join('');
      $('#auditDetailTitle').textContent = row.action;
      $('#auditDetailContent').innerHTML = `<dl class="audit-detail-list">
        <dt>Timestamp (PHT)</dt><dd>${esc(row.occurred_at_utc)}</dd><dt>Actor</dt><dd>${esc(row.display_name || row.username || 'System')} (${esc(row.role || 'Unavailable')})</dd><dt>Username</dt><dd>${esc(row.username || 'Unavailable')}</dd>
        <dt>Target</dt><dd>${esc(row.entity_type || 'Unavailable')}${row.entity_id ? ` #${esc(row.entity_id)}` : ''}</dd>
        <dt>Severity</dt><dd>${esc(row.severity)}</dd><dt>Outcome</dt><dd>${esc(row.outcome)}</dd><dt>Source IP</dt><dd>${esc(row.source_ip || 'Unavailable')}</dd>
        <dt>Request</dt><dd>${esc(row.http_method || '')} ${esc(row.request_path || 'Unavailable')}</dd><dt>Correlation ID</dt><dd>${esc(row.correlation_id || 'Unavailable')}</dd>${details}</dl>`;
      $('#auditDetailDialog').showModal();
    } catch (error) { showToast('Cannot load audit event', error.message, 'error'); }
  }

  function showToast(title, message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.innerHTML = svgIcon(PATH[type] || PATH.success, 18);

    const body = document.createElement('div');
    body.innerHTML = `<strong>${esc(title)}</strong><p>${esc(message)}</p>`;

    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = svgIcon(PATH.close, 14);

    let removeTimer = 0;
    const dismiss = () => {
      window.clearTimeout(removeTimer);
      toast.classList.add('leaving');
      window.setTimeout(() => toast.remove(), 200);
    };
    close.addEventListener('click', dismiss);
    toast.addEventListener('pointerenter', () => window.clearTimeout(removeTimer));
    toast.addEventListener('pointerleave', () => { removeTimer = window.setTimeout(dismiss, 2500); });

    toast.append(icon, body, close);
    $('#toastStack').append(toast);
    removeTimer = window.setTimeout(dismiss, type === 'error' ? 8000 : 5000);
  }

  /* ---------------------------------------------------------------------- *
   * Theme. The stylesheet reads `data-theme` on <html>; the choice is
   * remembered per browser and falls back to the operating system setting.
   * ---------------------------------------------------------------------- */
  const THEME_KEY = 'easysched-theme';

  function readStoredTheme() {
    try { return window.localStorage.getItem(THEME_KEY); } catch { return null; }
  }

  function applyTheme(theme) {
    const dark = theme === 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    const toggle = $('#themeToggle');
    if (!toggle) return;
    toggle.innerHTML = svgIcon(dark ? PATH.sun : PATH.moon, 18);
    const next = dark ? 'light' : 'dark';
    toggle.setAttribute('aria-label', `Switch to ${next} theme`);
    toggle.setAttribute('title', `Switch to ${next} theme`);
  }

  function initTheme() {
    const stored = readStoredTheme();
    if (stored === 'dark' || stored === 'light') return applyTheme(stored);
    const query = window.matchMedia('(prefers-color-scheme: dark)');
    applyTheme(query.matches ? 'dark' : 'light');
    // Keep following the operating system until a theme is chosen explicitly.
    query.addEventListener('change', (event) => { if (!readStoredTheme()) applyTheme(event.matches ? 'dark' : 'light'); });
  }

  function toggleTheme() {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    try { window.localStorage.setItem(THEME_KEY, next); } catch { /* private browsing */ }
    announce(`${next === 'dark' ? 'Dark' : 'Light'} theme enabled.`);
  }

  // Applied at parse end because this file is deferred. The content security
  // policy forbids an inline bootstrap script, so this is the earliest point the
  // stored theme can be restored without showing the wrong palette first.
  initTheme();

  function announce(message) {
    const region = $('#liveRegion');
    if (region) region.textContent = message;
  }

  function animateView(element) {
    element.classList.remove('view-enter');
    requestAnimationFrame(() => element.classList.add('view-enter'));
  }

  function scrollToTop() {
    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    window.scrollTo({ top: 0, left: 0, behavior });
    $('#mainContent').scrollTo({ top: 0, left: 0, behavior });
  }

  function routeUrl(route) {
    const segment = ROUTES[route];
    return `${BASE_PATH}/${segment || ''}` || '/';
  }

  function setRoute(route, replace = false) {
    const url = routeUrl(route);
    if (window.location.pathname === url) return;
    window.history[replace ? 'replaceState' : 'pushState']({}, '', url);
  }

  function readRoute() {
    let path = window.location.pathname.replace(/\/+$/, '');
    if (BASE_PATH && (path === BASE_PATH || path.startsWith(`${BASE_PATH}/`))) {
      path = path.slice(BASE_PATH.length);
    }
    const segment = path.replace(/^\/+/, '');
    return Object.keys(ROUTES).find((route) => ROUTES[route] === segment) || 'home';
  }

  function canonicalizeHomePath() {
    const currentPath = window.location.pathname.replace(/\/+$/, '') || '/';
    const homePath = routeUrl('home').replace(/\/+$/, '') || '/';
    if (currentPath !== homePath) setRoute('home', true);
  }

  function handleRouteChange() {
    const route = readRoute();
    if (state.snapshot) {
      if ((NAV[role()] || []).some(([id]) => id === route)) {
        navigate(route, false);
      } else {
        state.page = 'dashboard';
        setRoute('dashboard', true);
        showApp();
      }
      return;
    }

    if (route === 'home') {
      canonicalizeHomePath();
      showPublicHome(false);
    } else if (route === 'login') {
      showLogin('', false);
    } else if (route === 'register') {
      showRegistration(false);
    } else if (route === 'forgot-password') {
      showForgotPassword(false);
    } else {
      showLogin('', false);
      setRoute('login', true);
    }
  }

  function showLogin(message = '', updateRoute = true, replaceRoute = false) {
    if (updateRoute) setRoute('login', replaceRoute);
    document.title = 'Login | EasySched';
    registrationOpen = false;
    $('#publicHome').hidden = true;
    $('#loginView').hidden = false; $('#appView').hidden = true;
    animateView($('#loginView'));
    $('#loginView').classList.remove('registration-mode');
    $('#registrationView').hidden = true; $('#loginForm').closest('.login-panel').hidden = false;
    $('#forgotPasswordView').hidden = true;
    $('#loginError').textContent = message;
    $('#loginPassword').value = '';
    $('#loginCaptcha').value = '';
    $('#loginUsername').focus();
  }

  function showSignIn() {
    $('#publicHome').hidden = true;
    showLogin();
  }

  function showPublicHome(updateRoute = true) {
    if (updateRoute) setRoute('home');
    document.title = 'EasySched | New Sinai School and Colleges Sta. Rosa, Inc.';
    registrationOpen = false;
    $('#publicHome').hidden = false;
    animateView($('#publicHome'));
    $('#loginView').hidden = true;
    $('#loginView').classList.remove('registration-mode');
    $('#appView').hidden = true;
    scrollToTop();
  }

  async function showRegistration(updateRoute = true) {
    if (updateRoute) setRoute('register');
    document.title = 'Student registration | EasySched';
    registrationOpen = true;
    $('#loginView').classList.add('registration-mode'); $('#loginForm').closest('.login-panel').hidden = true; $('#registrationView').hidden = false; $('#registrationError').textContent = '';
    animateView($('#registrationView'));
    scrollToTop();
    try {
      const options = await request('registration_options');
      $('#registrationProgram').innerHTML = '<option value="">Select program</option>' + options.programs.map((item) => `<option value="${esc(item.id)}">${esc(item.code)} - ${esc(item.name)}</option>`).join('');
      registrationSections = options.sections;
      renderRegistrationSections();
    } catch (error) { $('#registrationError').textContent = error.message; }
    $('#registrationFirstName').focus();
  }

  function renderRegistrationSections() {
    const programId = $('#registrationProgram').value;
    const yearLevel = $('#registrationYear').value;
    const sectionSelect = $('#registrationSection');
    const sections = registrationSections.filter((section) =>
      String(section.program_id) === programId && (!yearLevel || String(section.year_level) === yearLevel)
    );
    sectionSelect.value = '';
    sectionSelect.disabled = !programId;
    sectionSelect.innerHTML = `<option value="">${programId ? 'Select section' : 'Select a program first'}</option>`
      + sections.map((section) => `<option value="${esc(section.id)}">${esc(section.code)} - Year ${esc(section.year_level)}</option>`).join('');
  }

  async function sendRegistrationOtp() { try { const result = await request('request_registration_otp', { method: 'POST', body: { email: $('#registrationEmail').value } }); showToast('Code sent', result.message); } catch (error) { $('#registrationError').textContent = error.message; } }
  function showForgotPassword(updateRoute = true) { if (updateRoute) setRoute('forgot-password'); document.title = 'Password recovery | EasySched'; registrationOpen = true; $('#loginView').classList.add('registration-mode'); $('#loginForm').closest('.login-panel').hidden = true; $('#registrationView').hidden = true; $('#forgotPasswordView').hidden = false; animateView($('#forgotPasswordView')); $('#resetError').textContent = ''; $('#resetAccount').focus(); }
  async function sendResetOtp() { try { const result = await request('request_password_reset', { method: 'POST', body: { account: $('#resetAccount').value } }); showToast('Check your email', result.message); } catch (error) { $('#resetError').textContent = error.message; } }
  async function resetPassword(event) { event.preventDefault(); try { const result = await request('reset_password', { method: 'POST', body: { otp: $('#resetOtp').value, password: $('#resetPassword').value, confirm_password: $('#resetPasswordConfirm').value } }); showLogin('', true, true); showToast('Password reset', result.message); } catch (error) { $('#resetError').textContent = error.message; } }

  async function registerStudent(event) {
    event.preventDefault();
    $('#registrationError').textContent = '';
    const password = $('#registrationPassword').value;
    const confirmPassword = $('#registrationPasswordConfirm').value;
    if (password.length < 10 || !/[A-Z]/.test(password) || !/\d/.test(password) || !/[^A-Za-z0-9]/.test(password)) {
      $('#registrationError').textContent = 'Use at least 10 characters, including an uppercase letter, a number, and a special character.';
      $('#registrationPassword').focus();
      return;
    }
    if (password !== confirmPassword) {
      $('#registrationError').textContent = 'Passwords do not match.';
      $('#registrationPasswordConfirm').focus();
      return;
    }
    const first = text($('#registrationFirstName').value);
    const middle = text($('#registrationMiddleName').value);
    const last = text($('#registrationLastName').value);
    const suffix = text($('#registrationSuffix').value);
    const body = {
      display_name: [first, middle, last, suffix].filter(Boolean).join(' '),
      username: $('#registrationUsername').value,
      email: $('#registrationEmail').value,
      otp: $('#registrationOtp').value,
      program_id: $('#registrationProgram').value,
      year_level: $('#registrationYear').value,
      section_id: $('#registrationSection').value,
      password,
      confirm_password: confirmPassword
    };
    try {
      const result = await request('register', { method: 'POST', body });
      showLogin('', true, true);
      showToast('Registration submitted', result.message, 'success');
    } catch (error) {
      $('#registrationError').textContent = error.message;
    }
  }

  function togglePasswordVisibility(button) {
    const input = document.getElementById(button.dataset.passwordToggle);
    if (!(input instanceof HTMLInputElement)) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', `${visible ? 'Hide' : 'Show'} ${input.id === 'registrationPassword' ? 'password' : 'confirm password'}`);
  }

  async function reviewRegistration(id, decision) { const label = decision === 'APPROVE' ? 'approve this student registration' : 'reject this student registration'; if (!window.confirm(`Are you sure you want to ${label}?`)) return; const button = $(`.review-registration[data-registration-id="${id}"][data-decision="${decision}"]`); if (button) { button.disabled = true; button.textContent = decision === 'APPROVE' ? 'Approving...' : 'Rejecting...'; } try { const result = await request('review_registration', { method: 'POST', body: { registration_id: id, decision } }); if (result.snapshot) result.snapshot.pending_registrations = (result.snapshot.pending_registrations || []).filter((row) => Number(row.id) !== Number(id)); applySnapshot(result.snapshot); showToast('Registration reviewed', result.message); } catch (error) { if (button) { button.disabled = false; button.textContent = decision === 'APPROVE' ? 'Approve' : 'Reject'; } showToast('Could not review registration', error.message, 'error'); } }

  function updateLoginChallenge(details = {}) {
    const required = Boolean(details.captcha_required);
    $('#loginCaptchaWrap').hidden = !required;
    $('#loginCaptcha').required = required;
    $('#loginCaptcha').value = '';
    if (required) $('#loginCaptchaImage').src = `captcha.php?v=${Date.now()}`;
    else $('#loginCaptchaImage').removeAttribute('src');
  }

  function refreshLoginCaptcha() {
    if ($('#loginCaptchaWrap').hidden) return;
    $('#loginCaptcha').value = '';
    $('#loginCaptchaImage').src = `captcha.php?refresh=1&v=${Date.now()}`;
    $('#loginCaptcha').focus();
  }

  function showApp() {
    $('#publicHome').hidden = true;
    $('#loginView').hidden = true; $('#appView').hidden = false;
    animateView($('#appView'));
    scrollToTop();
    $('#userName').textContent = state.snapshot.user.display_name;
    $('#userRole').textContent = state.snapshot.user.role;
    $('#userAvatar').textContent = state.snapshot.user.display_name.slice(0, 2).toUpperCase();
    buildNavigation();
    renderAll();
  }

  function buildNavigation() {
    const nav = $('#navList');
    nav.replaceChildren();
    (NAV[role()] || []).forEach(([id, label]) => {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button'; button.dataset.navigate = id; button.className = id === state.page ? 'active' : '';
      button.setAttribute('aria-current', id === state.page ? 'page' : 'false');
      button.innerHTML = `<span class="nav-icon" aria-hidden="true">${ICONS[id]}</span><span>${esc(label)}</span>`;
      item.append(button); nav.append(item);
    });
  }

  function navigate(page, updateRoute = true) {
    if (!(NAV[role()] || []).some(([id]) => id === page)) return;
    if (updateRoute) setRoute(page);
    if (state.page !== page) {
      state.query = '';
      $('#globalSearch').value = '';
    }
    state.page = page;
    $('#appView').classList.toggle('dashboard-active', page === 'dashboard');
    $$('.page').forEach((section) => { section.hidden = section.dataset.page !== page; });
    const activePage = $(`#page-${page}`);
    activePage?.classList.remove('page-enter');
    if (activePage) requestAnimationFrame(() => activePage.classList.add('page-enter'));
    $$('#navList button').forEach((button) => { const active = button.dataset.navigate === page; button.classList.toggle('active', active); button.setAttribute('aria-current', active ? 'page' : 'false'); });
    const [eyebrow, title] = PAGE_META[page]; $('#pageEyebrow').textContent = eyebrow; $('#pageTitle').textContent = title;
    document.title = `${title} | EasySched`;
    announce(`${title} page loaded.`);
    const searchField = $('#globalSearch').closest('.search-field');
    const searchablePage = ['dashboard', 'schedules', 'data'].includes(page);
    searchField.hidden = !searchablePage;
    searchField.setAttribute('aria-hidden', String(!searchablePage));
    $('#globalSearch').setAttribute('placeholder', page === 'data' ? 'Search records' : page === 'dashboard' ? 'Search classes, rooms, or instructors' : 'Search classes');
    closeSidebar();
    if (page === 'audit-logs') {
      if (!auditRefreshTimer) auditRefreshTimer = window.setInterval(() => loadAuditLogs(true), 5000);
    } else if (auditRefreshTimer) {
      window.clearInterval(auditRefreshTimer);
      auditRefreshTimer = 0;
    }
    if (page === 'dashboard') renderDashboard();
    if (page === 'schedules') {
      renderSchedules();
      if (role() !== 'student') loadScheduleHistory();
    }
    if (page === 'room-requests') renderRoomRequests();
    if (page === 'data') renderData();
    if (page === 'reports') renderReports();
    if (page === 'profile') renderProfile();
    if (page === 'settings') renderSettings();
    if (page === 'help') renderHelp();
    if (page === 'audit-logs') loadAuditLogs();
  }

  function renderHelp() {
    const slides = HELP_SLIDES[role()] || helpSlides;
    if (helpIndex >= slides.length) helpIndex = 0;
    const slide = slides[helpIndex];
    $('#helpSlideCount').textContent = `${helpIndex + 1} / ${slides.length}`;
    $('#helpSlideContent').innerHTML = `<p class="eyebrow">EASYSCHED ORIENTATION</p><h3>${esc(slide[0])}</h3><p class="help-lead">${esc(slide[1])}</p><ul>${slide[2].map((item) => `<li>${esc(item)}</li>`).join('')}</ul>`;
    $('#helpBackButton').disabled = helpIndex === 0;
    $('#helpNextButton').textContent = helpIndex === slides.length - 1 ? 'Finish' : 'Next';
    $('#helpDots').innerHTML = slides.map((_, i) => `<button type="button" class="help-dot${i === helpIndex ? ' active' : ''}" data-help-index="${i}" aria-label="Step ${i + 1}" aria-current="${i === helpIndex ? 'step' : 'false'}"></button>`).join('');
  }

  function applySnapshot(snapshot) {
    state.snapshot = snapshot;
    if (!snapshot) return showLogin();
    selectedScheduleTermId = Number(snapshot.active_term_id) || null;
    selectedScheduleRows = null;
    selectedScheduleHasRun = null;
    selectedScheduleValidation = null;
    selectedScheduleRun = null;
    selectedScheduleIsSample = false;
    scheduleHistoryRows = [];
    scheduleHistoryLoaded = false;
    scheduleHistoryLoading = false;
    scheduleTermRequestId += 1;
    scheduleTermLoading = false;
    if (!(NAV[snapshot.user.role] || []).some(([id]) => id === state.page)) state.page = 'dashboard';
    setRoute(state.page, true);
    showApp();
  }

  function metric(label, value, note) {
    const icon = svgIcon(METRIC_ICONS[label] || PATH.dashboard, 16);
    const tone = label === 'Published classes' || label === "Today's classes" ? 'blue' : label === 'Sections' ? 'violet' : label === 'Rooms' || label === 'Rooms used' ? 'orange' : 'green';
    return `<div class="metric metric-${tone}"><div class="metric-top"><span class="metric-icon">${icon}</span><div class="metric-label">${esc(label)}</div></div><div class="metric-value">${esc(value)}</div><div class="metric-footer"><div class="metric-note">${esc(note)}</div></div></div>`;
  }

  function renderDashboard() {
    const snapshot = state.snapshot; const list = schedules(); const run = snapshot.active_run;
    const activeTerm = (snapshot.terms || []).find((term) => Number(term.id) === Number(snapshot.active_term_id));
    const termLabel = activeTerm ? `${activeTerm.academic_year} · ${activeTerm.semester}` : 'No active academic term';
    const dashboardQuickPanel = $('.dashboard-quick-panel');
    const dashboardSidePanels = $('.dashboard-side-panels');
    const dashboardLowerGrid = $('.dashboard-lower-grid');
    if (dashboardQuickPanel) dashboardQuickPanel.hidden = !canManage();
    if (dashboardSidePanels) dashboardSidePanels.hidden = !canManage();
    if (dashboardLowerGrid) dashboardLowerGrid.classList.toggle('dashboard-without-quick-actions', !canManage());
    const termElement = $('#activeTermLabel'); if (termElement) termElement.textContent = termLabel;
    const metricGrid = $('#metricGrid'); if (metricGrid) metricGrid.hidden = false;
    const now = new Date();
    const today = now.getDay();
    const todayDay = today >= 1 && today <= 5 ? today : 1;
    const currentMinutes = now.getHours() * 60 + now.getMinutes();
    const todayCount = list.filter((row) => Number(row.day_of_week) === todayDay).length;
    const remainingClasses = list.filter((row) => {
      const day = Number(row.day_of_week);
      if (day < todayDay) return false;
      if (day > todayDay || today < 1 || today > 5) return true;
      const startMinutes = scheduleClockMinutes(row.start_time);
      return startMinutes !== null && startMinutes > currentMinutes;
    });
    const nextClass = remainingClasses[0];
    const metrics = canSeeGeneration()
      ? [metric('Active class assignments', snapshot.offerings.length, 'Course-section assignments'), metric('Published classes', list.length, run ? `Run #${run.id}` : 'No published run'), metric('Sections', snapshot.sections.length, 'Current academic term'), metric('Rooms', snapshot.rooms.length, 'Available resources')]
      : [metric('My classes', list.length, role() === 'student' ? 'Classes in your section' : 'Classes assigned to you'), metric("Today's classes", todayCount, 'Scheduled for today'), metric('Rooms', new Set(list.map((row) => row.room_id)).size, 'Rooms in your schedule'), metric('Next class', nextClass?.time_label || 'None', nextClass ? `${nextClass.subject_code} · ${nextClass.room_code}` : 'No upcoming class')];
    $('#metricGrid').innerHTML = metrics.join('');
    const query = state.query.trim().toLowerCase();
    const upcoming = remainingClasses.filter((row) => !query || `${row.subject_code} ${row.subject_name} ${row.section_code} ${row.program_code} ${row.room_code} ${row.instructor_name}`.toLowerCase().includes(query)).slice(0, 6);
    const emptyTitle = query ? 'No matching classes' : list.length ? 'No upcoming classes' : 'No published schedule';
    const emptyMessage = query ? 'Try another subject, section, room, or instructor.' : list.length ? 'There are no remaining classes for the rest of this week.' : 'Generate a conflict-free timetable to populate this list.';
    $('#upcomingBody').innerHTML = upcoming.length ? upcoming.map((row) => `<tr><td><strong>${esc(row.day_name)}</strong><span class="subline">${esc(row.time_label || row.slot_label)}</span></td><td><strong>${esc(row.subject_code)}</strong><span class="subline">${esc(row.subject_name)}</span></td><td>${esc(row.section_code)}</td><td>${esc(row.room_code)}</td><td>${esc(row.instructor_name)}</td></tr>`).join('') : `<tr><td colspan="5">${emptyState(emptyTitle, emptyMessage)}</td></tr>`;
    const quickActions = canManage()
      ? [
        { page: 'schedules', addClass: true, icon: PATH.calendar, label: 'Add class' },
        { page: 'data', tab: 'rooms', icon: PATH.building, label: 'Manage rooms' },
        { page: 'reports', icon: PATH.chart, label: 'View reports' }
      ]
      : role() === 'instructor'
        ? [
          { page: 'schedules', icon: PATH.calendar, label: 'View my schedule' },
          { page: 'room-requests', icon: PATH.calendarCheck, label: 'Request a room' }
        ]
        : [{ page: 'schedules', icon: PATH.calendar, label: 'View my section' }];
    if (canManage()) quickActions.unshift(
      { page: 'manual-generate', icon: PATH.sliders, label: 'Manual generate' },
      { page: 'generate', icon: PATH.calendarCheck, label: 'Auto generate' }
    );
    $('#dashboardQuickActions').innerHTML = quickActions.map((action) => `<button class="dashboard-quick-action" type="button" data-navigate="${['generate', 'manual-generate'].includes(action.page) ? '' : action.page}"${action.tab ? ` data-dashboard-tab="${action.tab}"` : ''}${action.page === 'generate' ? ' data-dashboard-generate' : ''}${action.page === 'manual-generate' ? ' data-dashboard-manual-generate' : ''}${action.addClass ? ' data-dashboard-add' : ''}><span class="dashboard-quick-icon">${svgIcon(action.icon, 18)}</span><span>${esc(action.label)}</span>${svgIcon('<path d="M5 12h14M13 6l6 6-6 6"/>', 14)}</button>`).join('');
    $$('.manage-only').forEach((element) => { element.hidden = !canSeeGeneration(); });
  }

  function scheduleTypeClass(roomType) {
    const normalized = String(roomType || '').trim().toUpperCase();
    if (normalized === 'LAB' || normalized === 'LABORATORY') return 'lab';
    if (normalized === 'SPECIAL' || normalized === 'OTHER') return 'other';
    return 'lecture';
  }

  function scheduleTypeLabel(roomType) {
    const type = scheduleTypeClass(roomType);
    return type === 'lab' ? 'Laboratory' : type === 'other' ? 'Special' : 'Lecture';
  }

  function roomTypeBadge(roomType) {
    const type = scheduleTypeClass(roomType);
    return `<span class="room-type-badge ${type}">${scheduleTypeLabel(roomType)}</span>`;
  }

  function scheduleRow(row, includeAction = false, editable = canManage()) {
    const action = includeAction && canManage() ? `<td class="manage-column">${editable ? `<div class="row-actions"><button class="button button-ghost button-small edit-entry" data-entry-id="${Number(row.id)}" type="button">Edit</button><button class="button button-danger button-small cancel-entry" data-entry-id="${Number(row.id)}" type="button">Cancel</button></div>` : '—'}</td>` : '';
    const roomType = row.room_type || 'LECTURE';
    const colorClass = scheduleTypeClass(roomType);
    return `<tr class="schedule-color-${colorClass}"><td>${esc(row.day_name)}</td><td><strong>${esc(row.time_label || row.slot_label)}</strong><span class="subline">${esc(scheduleDurationLabel(row))}</span></td><td><strong>${esc(row.subject_code)}</strong><span class="subline">${esc(row.subject_name)}${roomTypeBadge(roomType)}</span></td><td>${esc(row.section_code)}<span class="subline">${esc(row.program_code)}</span></td><td>${esc(row.instructor_name)}</td><td><strong>${esc(row.room_code)}</strong><span class="subline">${Number(row.room_capacity)} seats</span></td>${action}</tr>`;
  }

  function scheduleDurationLabel(row) {
    const minutes = scheduleDurationMinutes(row);
    if (minutes <= 0) return 'Duration unavailable';
    const hours = minutes / 60;
    const value = Number.isInteger(hours) ? String(hours) : String(Number(hours.toFixed(1)));
    return `${value} ${hours === 1 ? 'hour' : 'hours'}`;
  }

  function scheduleDurationMinutes(row) {
    const start = scheduleClockMinutes(row.start_time);
    const end = scheduleClockMinutes(row.end_time);
    return start === null || end === null ? Number(row.duration_slots || 0) * 60 : end - start;
  }

  function scheduleClockMinutes(value) {
    const match = /^(\d{1,2}):(\d{2})/.exec(String(value || ''));
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
  }

  function scheduleClockLabel(totalMinutes) {
    const normalized = ((totalMinutes % 1440) + 1440) % 1440;
    const hour = Math.floor(normalized / 60);
    const minute = normalized % 60;
    const period = hour < 12 ? 'AM' : 'PM';
    const displayHour = hour % 12 || 12;
    return `${displayHour}:${String(minute).padStart(2, '0')} ${period}`;
  }

  function scheduleCompactClockLabel(totalMinutes) {
    const normalized = ((totalMinutes % 1440) + 1440) % 1440;
    const hour = Math.floor(normalized / 60);
    const minute = normalized % 60;
    const period = hour < 12 ? 'AM' : 'PM';
    const displayHour = hour % 12 || 12;
    return `${displayHour}${minute ? `:${String(minute).padStart(2, '0')}` : ''} ${period}`;
  }

  function scheduleCompactTimeRange(startMinutes, endMinutes) {
    const start = scheduleCompactClockLabel(startMinutes);
    const end = scheduleCompactClockLabel(endMinutes);
    const startPeriod = start.slice(-2);
    const endPeriod = end.slice(-2);
    return `${startPeriod === endPeriod ? start.slice(0, -3) : start}–${end}`;
  }

  function scheduleTimeRangeLabel(row) {
    if (row.time_label) return row.time_label;
    const start = scheduleClockMinutes(row.start_time);
    const end = scheduleClockMinutes(row.end_time);
    return start === null || end === null
      ? row.time_label || row.slot_label || 'Time unavailable'
      : `${scheduleClockLabel(start)} – ${scheduleClockLabel(end)}`;
  }

  function visibleScheduleRows() {
    const query = state.query.toLowerCase();
    return scheduleViewRows().filter((row) => {
      const selected = state.scheduleView === 'all' || state.scheduleFilter === 'all' || (state.scheduleView === 'section' && String(row.section_id) === state.scheduleFilter) || (state.scheduleView === 'instructor' && String(row.instructor_id) === state.scheduleFilter) || (state.scheduleView === 'room' && String(row.room_id) === state.scheduleFilter) || (state.scheduleView === 'room_type' && scheduleTypeClass(row.room_type) === state.scheduleFilter);
      const searchable = `${row.subject_code} ${row.subject_name} ${row.section_code} ${row.instructor_name} ${row.room_code} ${row.room_type} ${row.day_name} ${row.slot_label}`.toLowerCase();
      return selected && (!query || searchable.includes(query));
    });
  }

  function openStudentLinks() {
    if (role() !== 'student') return;
    const term = (state.snapshot.terms || []).find((item) => Number(item.id) === Number(selectedScheduleTermId ?? state.snapshot.active_term_id));
    $('#studentLinksTerm').textContent = term
      ? `AY ${term.academic_year} · ${term.semester}`
      : 'No active academic term';
    const instructorSubjects = new Map();
    scheduleViewRows().forEach((row) => {
      const name = String(row.instructor_name || '').trim();
      if (!name) return;
      const key = name.toLocaleLowerCase();
      if (!instructorSubjects.has(key)) instructorSubjects.set(key, { name, subjects: new Map() });
      const subject = [row.subject_code, row.subject_name].map((value) => String(value || '').trim()).filter(Boolean).join(' · ');
      if (subject) instructorSubjects.get(key).subjects.set(subject.toLocaleLowerCase(), subject);
    });
    const instructors = [...instructorSubjects.values()].sort((a, b) => a.name.localeCompare(b.name));
    $('#studentLinksBody').innerHTML = instructors.length
      ? instructors.map(({ name, subjects }, index) => {
        const exampleLink = `meet.google.com/demo-class-${String(index + 1).padStart(3, '0')}`;
        const subjectList = [...subjects.values()].sort((a, b) => a.localeCompare(b));
        return `<tr><td>${index + 1}</td><td>${esc(name)}</td><td>${subjectList.length ? subjectList.map(esc).join('<br>') : '—'}</td><td><code class="student-example-link">${exampleLink}</code></td></tr>`;
      }).join('')
      : '<tr><td colspan="4">No instructors are listed in your published schedule.</td></tr>';
    $('#studentLinksDialog').showModal();
  }

  function renderSchedules() {
    const terms = state.snapshot.terms || [];
    const activeTermId = Number(state.snapshot.active_term_id);
    if (selectedScheduleTermId === null) {
      selectedScheduleTermId = activeTermId;
      selectedScheduleRows = null;
    }
    const termFilter = $('#scheduleTermFilter');
    if (termFilter) {
      const orderedTerms = [...terms].sort((left, right) => {
        const activeOrder = Number(Number(right.id) === activeTermId) - Number(Number(left.id) === activeTermId);
        return activeOrder || String(right.academic_year).localeCompare(String(left.academic_year)) || Number(right.id) - Number(left.id);
      });
      termFilter.innerHTML = orderedTerms.map((term) => {
        const isActive = Number(term.id) === activeTermId;
        const label = `${term.academic_year} · ${term.semester}${isActive ? ' (Active)' : ' (Inactive)'}`;
        return `<option value="${Number(term.id)}">${esc(label)}</option>`;
      }).join('');
      termFilter.value = String(selectedScheduleTermId);
    }
    const selectedTerm = terms.find((term) => Number(term.id) === Number(selectedScheduleTermId));
    const termLabel = selectedTerm ? `${selectedTerm.academic_year} · ${selectedTerm.semester}` : 'No academic term';
    const isActiveTerm = Number(selectedScheduleTermId) === activeTermId;
    const canEditSelectedSchedule = canManage() && isActiveTerm && !selectedScheduleRun;
    $('#addScheduleButton').hidden = !canEditSelectedSchedule;
    $('#manualGenerateButton').hidden = !canEditSelectedSchedule;
    $('#generateButton').hidden = !canEditSelectedSchedule;
    $('.schedule-table-panel').hidden = role() === 'student';
    const scheduleTerm = $('#scheduleTermFilter'); if (scheduleTerm) scheduleTerm.disabled = terms.length < 2 || scheduleTermLoading;
    const savedRunLabel = selectedScheduleIsSample
      ? ' · SAMPLE ONLY — illustrative data, not a real historical schedule'
      : selectedScheduleRun ? ` · ${selectedScheduleRun.status === 'ARCHIVED' ? 'Archived version' : 'Saved version'} · ${new Date(String(selectedScheduleRun.created_at).replace(' ', 'T')).toLocaleString()}` : '';
    const printMeta = $('#printMeta'); if (printMeta) printMeta.textContent = `Weekly class schedule · ${termLabel}${savedRunLabel}`;
    const exportButton = $('#exportButton');
    if (exportButton) {
      exportButton.disabled = selectedScheduleIsSample;
      exportButton.title = selectedScheduleIsSample ? 'Sample schedules are illustrative and cannot be exported as real records.' : '';
    }
    renderFilterValues();
    const rows = visibleScheduleRows(); $('#scheduleCountLabel').textContent = `${rows.length} ${rows.length === 1 ? 'class' : 'classes'}`;
    const laboratoryView = (state.scheduleView === 'room_type' && state.scheduleFilter === 'lab')
      || (rows.length > 0 && rows.every((row) => scheduleTypeClass(row.room_type) === 'lab'));
    $('.schedule-table-panel').classList.toggle('schedule-table-panel-lab', laboratoryView);
    const columnCount = canManage() ? 7 : 6;
    const emptyStateClass = state.scheduleView === 'room_type' && state.scheduleFilter !== 'all'
      ? `schedule-color-${scheduleTypeClass(state.scheduleFilter)}`
      : '';
    const emptyScheduleMessage = isActiveTerm
      ? ['No classes match this view', 'Change the filter above, or generate a schedule.']
      : ['No published classes for this term', 'This inactive academic term does not have a published schedule yet.'];
    $('#scheduleTableBody').innerHTML = rows.length ? rows.map((row) => scheduleRow(row, true, canEditSelectedSchedule)).join('') : `<tr class="${emptyStateClass}"><td colspan="${columnCount}">${emptyState(emptyScheduleMessage[0], emptyScheduleMessage[1])}</td></tr>`;
    const hasRun = selectedScheduleRun ? Boolean(selectedScheduleHasRun) : isActiveTerm ? Boolean(state.snapshot.active_run) : Boolean(selectedScheduleHasRun);
    const valid = isActiveTerm && !selectedScheduleRun ? Boolean(state.snapshot.validation?.valid) : Boolean(selectedScheduleValidation?.valid);
    $('#conflictBadge').textContent = selectedScheduleIsSample ? 'Sample only' : selectedScheduleRun?.status === 'ARCHIVED' ? 'Archived version' : hasRun ? (valid ? 'Validated' : 'Conflict found') : 'No published schedule';
    $('#conflictBadge').className = `badge ${selectedScheduleIsSample ? 'badge-warning' : selectedScheduleRun?.status === 'ARCHIVED' || !hasRun ? 'badge-neutral' : (valid ? 'badge-neutral' : 'badge-warning')}`;
    renderScheduleHistory();
    renderCalendar(rows);
    updatePrintMeta(rows.length);
    $$('.manage-column').forEach((cell) => { cell.hidden = !canManage(); });
  }

  function renderScheduleRequests() {
    const requests = (state.snapshot.schedule_requests || []).map((item) => ({ ...item, slot_label: requestTimeLabel(item) }));
    const instructorPanel = $('#scheduleRequestPanel');
    const changePanel = $('#scheduleChangePanel');
    const reviewPanel = $('#scheduleRequestReviewPanel');
    if (instructorPanel) instructorPanel.hidden = role() !== 'instructor';
    if (changePanel) changePanel.hidden = role() !== 'instructor';
    if (reviewPanel) reviewPanel.hidden = !canAdmin();
    if (role() === 'instructor') {
      const offerings = state.snapshot.offerings || [];
      $('#requestOffering').innerHTML = `${offerings.map((item) => `<option value="${Number(item.id)}">${esc(item.subject_name)} - ${esc(item.section_code)}</option>`).join('')}<option value="other">Others</option>`;
      updateRoomRequestType();
      configureRequestDateBounds();
      renderRequestRooms();
      const roomRequests = requests.filter((item) => ['ROOM', 'OTHER'].includes(item.request_type || 'ROOM'));
      const changeRequests = requests.filter((item) => item.request_type === 'CHANGE');
      $('#instructorRequestBody').innerHTML = roomRequests.length ? roomRequests.map((item) => `<tr><td>${item.request_type === 'OTHER' ? 'Others' : `${esc(item.subject_name)} - ${esc(item.section_code)}`}${item.request_type === 'OTHER' ? `<span class="subline">${esc(item.note)}</span>` : ''}</td><td>${esc(item.room_code)}</td><td>${esc(requestDateLabel(item))}</td><td>${esc(['','Monday','Tuesday','Wednesday','Thursday','Friday'][Number(item.day_of_week)] || item.day_of_week)}</td><td>${esc(item.slot_label)}</td><td>${esc(item.status)}</td></tr>`).join('') : `<tr><td colspan="6">${emptyState('No room requests', 'Your submitted room requests will appear here.')}</td></tr>`;
      $('#instructorChangeRequestBody').innerHTML = changeRequests.length ? changeRequests.map((item) => `<tr><td>${esc(item.subject_name)} - ${esc(item.section_code)}</td><td>${esc(item.room_code)}</td><td>${esc(['','Monday','Tuesday','Wednesday','Thursday','Friday'][Number(item.day_of_week)] || item.day_of_week)}</td><td>${esc(item.slot_label)}</td><td>${esc(item.status)}</td></tr>`).join('') : `<tr><td colspan="5">${emptyState('No schedule change requests', 'Your submitted change requests will appear here.')}</td></tr>`;
      const scheduledClasses = schedules();
      $('#changeEntry').innerHTML = scheduledClasses.map((row) => `<option value="${Number(row.id)}">${esc(row.subject_code)} - ${esc(row.section_code)} · ${esc(row.day_name)} · ${esc(row.time_label)} · ${esc(row.room_code)}</option>`).join('') || '<option value="">No published classes to change</option>';
      $('#changeRoom').innerHTML = (state.snapshot.rooms || []).map((room) => `<option value="${Number(room.id)}">${esc(room.code)} - ${esc(room.name)}</option>`).join('') || '<option value="">No rooms available</option>';
      $('#submitScheduleChangeButton').disabled = scheduledClasses.length === 0 || !(state.snapshot.rooms || []).length;
      setChangeFormFromSelectedEntry();
    }
    if (canAdmin()) {
      $('#scheduleRequestCount').textContent = `${requests.length} pending`;
      $('#scheduleRequestReviewBody').innerHTML = requests.length ? requests.map((item) => {
        const isChange = item.request_type === 'CHANGE';
        const isOther = item.request_type === 'OTHER';
        const weekday = ['','Monday','Tuesday','Wednesday','Thursday','Friday'][Number(item.day_of_week)] || item.day_of_week;
        const current = isChange
          ? `${['','Monday','Tuesday','Wednesday','Thursday','Friday'][Number(item.previous_day_of_week)] || 'Unavailable'} · ${requestTimeLabel({ request_start_time: item.previous_start_time, requested_end_time: item.previous_end_time })} · ${item.previous_room_code || 'Unavailable'}`
          : '—';
        const dateOrDay = isChange ? weekday : requestDateLabel(item);
        const note = (item.note || '').replace(/^\[REQUEST_DATE:\d{4}-\d{2}-\d{2}\]\s*/, '') || '—';
        return `<tr><td>${isChange ? 'Schedule change' : isOther ? 'Other room request' : 'Room request'}</td><td>${esc(item.instructor_name)}</td><td>${isOther ? 'Others' : `${esc(item.subject_name)} - ${esc(item.section_code)}`}</td><td>${esc(current)}</td><td>${esc(item.room_code)}</td><td>${esc(dateOrDay)}</td><td>${esc(item.slot_label)}</td><td>${esc(note)}</td><td><div class="row-actions"><button class="button button-primary button-small review-schedule-request" data-request-id="${Number(item.id)}" data-decision="APPROVE" type="button">Approve</button><button class="button button-danger button-small review-schedule-request" data-request-id="${Number(item.id)}" data-decision="REJECT" type="button">Reject</button></div></td></tr>`;
      }).join('') : `<tr><td colspan="9">${emptyState('No pending instructor requests', 'Room and schedule change requests will appear here.')}</td></tr>`;
    }
  }

  function setChangeFormFromSelectedEntry() {
    const selectedId = Number($('#changeEntry').value);
    const row = schedules().find((item) => Number(item.id) === selectedId);
    if (!row) return;
    $('#changeDay').value = String(row.day_of_week);
    $('#changeRoom').value = String(row.room_id);
    const setClock = (value, timeId, periodId) => {
      const match = /^(\d{1,2}):(\d{2})/.exec(String(value || ''));
      if (!match) return;
      const hour = Number(match[1]);
      const period = hour >= 12 ? 'PM' : 'AM';
      document.querySelector(timeId).value = `${String(hour % 12 || 12).padStart(2, '0')}:${match[2]}`;
      document.querySelector(periodId).value = period;
    };
    setClock(row.start_time, '#changeStartTime', '#changeStartPeriod');
    setClock(row.end_time, '#changeEndTime', '#changeEndPeriod');
  }

  function renderRequestRooms() {
    const roomSelect = $('#requestRoom');
    if (roomSelect && !roomSelect.dataset.initialized) {
      roomSelect.innerHTML = '<option value="">Choose a date and time first</option>';
      roomSelect.dataset.initialized = 'true';
    }
  }

  function updateRoomRequestType() {
    const isOther = $('#requestOffering').value === 'other';
    $('#requestOtherPurposeField').hidden = !isOther;
    $('#requestPurpose').required = isOther;
    $('#requestOffering').setAttribute('aria-expanded', String(isOther));
    $('#requestNoteField').hidden = isOther;
    if (isOther) $('#requestNote').value = '';
    if (!isOther) $('#requestPurpose').value = '';
  }

  function renderRequestCalendar() {
    configureRequestDateBounds();
    const input = $('#requestDate');
    const monthSelect = $('#requestCalendarMonth');
    const daysGrid = $('#requestCalendarDays');
    if (!input || !monthSelect || !daysGrid) return;
    const today = new Date();
    const year = today.getFullYear();
    const firstMonth = today.getMonth();
    if (!Number.isInteger(requestCalendarMonth) || requestCalendarMonth < firstMonth || requestCalendarMonth > 11) {
      requestCalendarMonth = firstMonth;
    }
    monthSelect.innerHTML = Array.from({ length: 12 - firstMonth }, (_, offset) => {
      const month = firstMonth + offset;
      return `<option value="${month}"${month === requestCalendarMonth ? ' selected' : ''}>${esc(new Date(year, month, 1).toLocaleString(undefined, { month: 'long' }))}</option>`;
    }).join('');
    $('#requestCalendarYear').textContent = String(year);

    const firstWeekday = (new Date(year, requestCalendarMonth, 1).getDay() + 6) % 7;
    const daysInMonth = new Date(year, requestCalendarMonth + 1, 0).getDate();
    const minDate = input.min;
    const maxDate = input.max;
    const cells = Array.from({ length: firstWeekday }, () => '<span class="request-date-empty" aria-hidden="true"></span>');
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(year, requestCalendarMonth, day);
      const value = localDateInputValue(date);
      const weekday = date.getDay();
      const available = value >= minDate && value <= maxDate && weekday >= 1 && weekday <= 5;
      const selected = value === input.value;
      cells.push(`<button type="button" class="request-date-day${selected ? ' is-selected' : ''}" data-request-date="${value}"${available ? '' : ' disabled'}${selected ? ' aria-pressed="true"' : ''}>${day}</button>`);
    }
    daysGrid.innerHTML = cells.join('');
  }

  function closeRequestCalendar() {
    $('#requestDateCalendar').hidden = true;
    $('#requestDateButton').setAttribute('aria-expanded', 'false');
  }

  function chooseRequestDate(value) {
    const input = $('#requestDate');
    input.value = value;
    const selected = new Date(`${value}T00:00:00`);
    $('#requestDateButton').textContent = selected.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    updateRequestDate();
    closeRequestCalendar();
    invalidateRoomAvailability();
  }

  function formatTimeInput(input) { const digits = input.value.replace(/\D/g, '').slice(0, 4); input.value = digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits; }
  function validTimeInput(value) { return /^(0[1-9]|1[0-2]):[0-5][0-9]$/.test(value); }
  function to24Hour(value, period) {
    const match = /^([0-9]{1,2}):([0-9]{2})$/.exec(value || '');
    if (!match) return null;
    let hour = Number(match[1]);
    const minute = Number(match[2]);
    if (period === 'AM' && hour === 12) hour = 0;
    if (period === 'PM' && hour !== 12) hour += 12;
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
  }

  function localDateInputValue(date) { return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`; }
  function configureRequestDateBounds() { const input = $('#requestDate'); if (!input) return; const today = new Date(); const tomorrow = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1); input.min = localDateInputValue(tomorrow); input.max = `${today.getFullYear()}-12-31`; }
  function updateRequestDate() { configureRequestDateBounds(); const input = $('#requestDate'); const date = input.value; if (!date) return; if (date < input.min || date > input.max) { showToast('Invalid date', 'Choose a date at least one day ahead within the current year.', 'error'); input.value = ''; $('#requestDateButton').textContent = 'Choose a date'; return; } const day = new Date(`${date}T00:00:00`).getDay(); if (day === 0 || day === 6) { showToast('Invalid date', 'Choose a Monday through Friday date.', 'error'); input.value = ''; $('#requestDateButton').textContent = 'Choose a date'; } }
  function requestDateLabel(item) { return item.request_date || (String(item.note || '').match(/^\[REQUEST_DATE:(\d{4}-\d{2}-\d{2})\]/)?.[1] || '-'); }
  function requestTimeLabel(item) { const format = (value) => { const [hours, minutes] = String(value || '').slice(0, 5).split(':').map(Number); if (!Number.isInteger(hours) || !Number.isInteger(minutes)) return ''; const period = hours >= 12 ? 'PM' : 'AM'; return `${hours % 12 || 12}:${String(minutes).padStart(2, '0')} ${period}`; }; const start = format(item.request_start_time); const end = format(item.requested_end_time); return start && end ? `${start} - ${end}` : item.slot_label || '-'; }
  function roomRequestPayload() { const date = $('#requestDate').value; const start = $('#requestStartTime').value; const end = $('#requestEndTime').value; const isOther = $('#requestOffering').value === 'other'; if (!$('#requestOffering').value || !date || !validTimeInput(start) || !validTimeInput(end)) throw new Error('Choose a class, exact date, and valid start and end times.'); if (isOther && !$('#requestPurpose').value.trim()) throw new Error('Enter the purpose for this room request.'); const start24 = to24Hour(start, $('#requestStartPeriod').value); const day = new Date(`${date}T00:00:00`).getDay(); const slotId = Number((slots().find((slot) => slot.start_time === start24) || {}).id || 0); if (day < 1 || day > 5) throw new Error('Choose a Monday through Friday date.'); if (!slotId) throw new Error('Start time must match a configured timetable hour.'); return { request_type: isOther ? 'OTHER' : 'ROOM', offering_id: isOther ? null : Number($('#requestOffering').value), purpose: isOther ? $('#requestPurpose').value.trim() : '', room_id: Number($('#requestRoom').value || 0), request_date: date, day_of_week: day, slot_id: slotId, start_time: start, start_period: $('#requestStartPeriod').value, end_time: end, end_period: $('#requestEndPeriod').value }; }
  function setRoomAvailabilityStatus(message, tone = 'muted') { const status = $('#roomAvailabilityStatus'); if (!status) return; status.textContent = message; status.className = `availability-status ${tone}`; $('#submitRoomRequestButton').disabled = tone !== 'health-ok' || !$('#requestRoom').value; }
  function invalidateRoomAvailability() { roomAvailabilityCheckId++; window.clearTimeout(roomAvailabilityTimer); availableRoomsReady = false; roomRequestAllowed = false; $('#requestRoom').innerHTML = '<option value="">Finding available rooms...</option>'; $('#submitRoomRequestButton').disabled = true; try { roomRequestPayload(); roomAvailabilityTimer = window.setTimeout(refreshAvailableRooms, 250); setRoomAvailabilityStatus('Finding available rooms...'); } catch (error) { $('#requestRoom').innerHTML = '<option value="">Choose a date and time first</option>'; setRoomAvailabilityStatus(error.message); } }
  async function refreshAvailableRooms() { const checkId = ++roomAvailabilityCheckId; try { const result = await request('available_rooms', { method: 'POST', body: roomRequestPayload() }); if (checkId !== roomAvailabilityCheckId) return; const rooms = result.rooms || []; const blockers = result.request_blockers || []; const select = $('#requestRoom'); const selectedRoom = select.value; select.innerHTML = rooms.length ? rooms.map((room) => `<option value="${Number(room.id)}">${esc(room.code)} - ${esc(room.name)} (${esc(room.room_type)})</option>`).join('') : '<option value="">No rooms available</option>'; if (rooms.some((room) => Number(room.id) === Number(selectedRoom))) select.value = selectedRoom; availableRoomsReady = rooms.length > 0; roomRequestAllowed = availableRoomsReady && blockers.length === 0; const conflictText = blockers.map((item) => `${item.description} (${requestTimeLabel({ request_start_time: item.start_time, requested_end_time: item.end_time })})`).join('; '); const message = blockers.length ? `${rooms.length ? `${rooms.length} room${rooms.length === 1 ? '' : 's'} are free, but this request cannot be submitted` : 'No rooms available for the selected time'}. ${conflictText}` : rooms.length ? `${rooms.length} room${rooms.length === 1 ? '' : 's'} available for the selected time.` : 'No rooms available for the selected time.'; setRoomAvailabilityStatus(message, roomRequestAllowed ? 'health-ok' : 'health-warn'); } catch (error) { if (checkId === roomAvailabilityCheckId) { availableRoomsReady = false; roomRequestAllowed = false; $('#requestRoom').innerHTML = '<option value="">Availability could not be loaded</option>'; setRoomAvailabilityStatus(error.message, 'health-error'); } } }
  function renderRoomRequests() { renderScheduleRequests(); }

  async function requestSchedule(event) { event.preventDefault(); let payload; try { payload = roomRequestPayload(); } catch (error) { setRoomAvailabilityStatus(error.message, 'health-error'); return; } if (!availableRoomsReady || !payload.room_id) { setRoomAvailabilityStatus('Choose an available room before submitting.', 'health-error'); return; } if (!roomRequestAllowed) { setRoomAvailabilityStatus('Resolve the instructor or class schedule conflict before submitting.', 'health-error'); return; } try { const result = await request('request_schedule', { method: 'POST', body: { ...payload, note: $('#requestNote').value } }); applySnapshot(result.snapshot); showToast('Request submitted', 'Your room request is waiting for administrator approval.'); invalidateRoomAvailability(); } catch (error) { const conflict = error.details?.conflicts?.[0]; const message = conflict ? `Conflict: this room is occupied from ${requestTimeLabel({ request_start_time: conflict.start_time, requested_end_time: conflict.end_time })} (${conflict.description}).` : error.message; availableRoomsReady = false; roomRequestAllowed = false; setRoomAvailabilityStatus(message, 'health-error'); showToast('Could not submit request', message, 'error'); } }
  async function requestScheduleChange(event) {
    event.preventDefault();
    if (!$('#changeEntry').value || !$('#changeRoom').value || !validTimeInput($('#changeStartTime').value) || !validTimeInput($('#changeEndTime').value)) {
      showToast('Check the request', 'Choose a published class, room, weekday, and valid start and end times.', 'error');
      return;
    }
    try {
      const result = await request('request_schedule_change', {
        method: 'POST',
        body: {
          entry_id: Number($('#changeEntry').value),
          room_id: Number($('#changeRoom').value),
          day_of_week: Number($('#changeDay').value),
          start_time: $('#changeStartTime').value,
          start_period: $('#changeStartPeriod').value,
          end_time: $('#changeEndTime').value,
          end_period: $('#changeEndPeriod').value,
          note: $('#changeNote').value
        }
      });
      applySnapshot(result.snapshot);
      showToast('Request submitted', 'Your schedule change is waiting for administrator approval.');
    } catch (error) {
      showToast('Could not submit schedule change', error.message, 'error');
    }
  }
  async function reviewScheduleRequest(id, decision) { const item = (state.snapshot.schedule_requests || []).find((requestItem) => Number(requestItem.id) === Number(id)); const isChange = item?.request_type === 'CHANGE'; const isOther = item?.request_type === 'OTHER'; const requestLabel = isChange ? 'schedule change request' : isOther ? 'other room request' : 'room request'; if (!window.confirm(`${decision === 'APPROVE' ? 'Approve' : 'Reject'} this ${requestLabel}?`)) return; try { const result = await request('review_schedule_request', { method: 'POST', body: { request_id: id, decision } }); applySnapshot(result.snapshot); showToast('Request reviewed', decision === 'APPROVE' ? (isChange ? 'The schedule change was approved and the published class was updated.' : isOther ? 'The room reservation was approved.' : 'The room request was approved.') : `The ${requestLabel} was rejected.`); } catch (error) { showToast('Could not review request', error.message, 'error'); } }

  /* Printed timetables are handed out during review, so the sheet carries its
   * own term, filter and print date rather than relying on browser headers. */
  function updatePrintMeta(count) {
    const target = $('#printMeta');
    if (!target) return;
    const terms = state.snapshot?.terms || [];
    const active = terms.find((term) => Number(term.id) === Number(selectedScheduleTermId ?? state.snapshot?.active_term_id)) || terms[0];
    const period = active ? `${active.academic_year} · ${active.semester}` : 'No active term';
    const scope = state.scheduleView === 'all' || state.scheduleFilter === 'all'
      ? 'All classes'
      : text($('#scheduleFilterValue')?.selectedOptions?.[0]?.textContent) || 'Filtered';
    const printed = new Date().toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    const sampleLabel = selectedScheduleIsSample ? ' · SAMPLE ONLY — illustrative data, not a real historical schedule' : '';
    target.textContent = `Weekly class schedule · ${period}${sampleLabel} · ${scope} · ${count} ${count === 1 ? 'class' : 'classes'} · Printed ${printed}`;
  }

  function renderFilterValues() {
    const select = $('#scheduleFilterValue'); const previous = state.scheduleFilter; let values = [];
    const rows = scheduleViewRows();
    const unique = (key, label) => [...new Map(rows.map((row) => [String(row[key]), [row[key], label(row)]])).values()];
    if (state.scheduleView === 'section') values = unique('section_id', (item) => `${item.section_code} - ${item.program_code}`);
    if (state.scheduleView === 'instructor') values = unique('instructor_id', (item) => item.instructor_name);
    if (state.scheduleView === 'room') values = unique('room_id', (item) => item.room_code);
    if (state.scheduleView === 'room_type') values = [['lecture', 'Lecture'], ['lab', 'Laboratory'], ['other', 'Special']];
    $('#scheduleFilterValueWrap').hidden = state.scheduleView === 'all';
    select.innerHTML = `<option value="all">All</option>${values.map(([id, label]) => `<option value="${esc(id)}">${esc(label)}</option>`).join('')}`;
    select.value = values.some(([id]) => String(id) === previous) ? previous : 'all'; state.scheduleFilter = select.value;
  }

  function renderCalendar(rows) {
    calendarDetailGroups.clear();
    const allColumns = days();
    const dayFilter = $('#calendarDayFilter');
    if (dayFilter) {
      const selectedDay = String(state.calendarDay || allColumns[0]?.[0] || '');
      dayFilter.innerHTML = allColumns.map(([day, name]) => `<option value="${esc(day)}">${esc(name)}</option>`).join('');
      state.calendarDay = allColumns.some(([day]) => String(day) === selectedDay) ? selectedDay : String(allColumns[0]?.[0] || '');
      dayFilter.value = state.calendarDay;
    }
    const columns = state.calendarView === 'day'
      ? allColumns.filter(([day]) => String(day) === state.calendarDay)
      : allColumns;
    $('#calendarDayFilterWrap').hidden = state.calendarView !== 'day';
    $('#calendarWeekView').classList.toggle('is-active', state.calendarView === 'week');
    $('#calendarDayView').classList.toggle('is-active', state.calendarView === 'day');
    $('#calendarWeekView').setAttribute('aria-pressed', String(state.calendarView === 'week'));
    $('#calendarDayView').setAttribute('aria-pressed', String(state.calendarView === 'day'));
    const timeSlots = slots();
    const dayColumns = new Map(columns.map(([day], index) => [String(day), index + 2]));
    const slotRows = new Map(timeSlots.map((slot, index) => [String(slot.id), index]));
    const byDay = new Map(columns.map(([day]) => [String(day), []]));
    rows.forEach((row) => {
      const startRow = slotRows.get(String(row.slot_id));
      if (startRow === undefined) return;
      const startTime = String(row.start_time || '').slice(0, 5);
      const endTime = String(row.end_time || '').slice(0, 5);
      const calculatedSpan = timeSlots.filter((slot) =>
        String(slot.start_time).slice(0, 5) < endTime && String(slot.end_time).slice(0, 5) > startTime
      ).length || Number(row.duration_slots) || 1;
      const rowSpan = Math.min(Math.max(1, calculatedSpan), timeSlots.length - startRow);
      const dayRows = byDay.get(String(row.day_of_week));
      if (dayRows) dayRows.push({ row, startRow, rowSpan });
    });

    const renderCalendarEvent = ({ row, rowSpan }) => {
      const subject = subjectById(row.subject_id);
      const roomType = row.room_type || subject?.room_type || 'LECTURE';
      const eventClass = scheduleTypeClass(roomType);
      const timeLabel = scheduleTimeRangeLabel(row);
      const duration = scheduleDurationLabel(row);
      const title = `${row.subject_code} | ${row.section_code} | ${row.room_code} | ${row.instructor_name} | ${timeLabel} | ${duration}`;
      return `<article class="calendar-event ${eventClass}${rowSpan > 1 ? ' calendar-event-long' : ''}" aria-label="${esc(title)}" title="${esc(title)}"><span class="calendar-event-time">${esc(timeLabel)}</span><strong>${esc(row.subject_code)}</strong><span>${esc(row.section_code)} · ${esc(row.room_code)}</span><span class="calendar-event-instructor">${esc(row.instructor_name)}</span></article>`;
    };

    let html = '<div class="calendar-cell calendar-head calendar-row-1 calendar-column-1">Time</div>';
    columns.forEach(([day, name], index) => {
      const originalDayIndex = allColumns.findIndex(([allDay]) => String(allDay) === String(day));
      html += `<div class="calendar-cell calendar-head calendar-row-1 calendar-column-${index + 2}">${esc(name)}<span class="calendar-resize calendar-resize-column" role="separator" tabindex="0" aria-orientation="vertical" aria-valuemin="150" aria-valuemax="720" aria-label="Resize ${esc(name)} column" title="Drag to resize ${esc(name)}" data-resize-axis="column" data-resize-index="${originalDayIndex + 1}"></span></div>`;
    });
    timeSlots.forEach((slot, index) => {
      const gridRow = index + 2;
      const slotStart = scheduleClockMinutes(slot.start_time);
      const slotEnd = scheduleClockMinutes(slot.end_time);
      const slotLabel = slotStart !== null && slotEnd !== null
        ? `<span class="calendar-time-start">${esc(scheduleClockLabel(slotStart))}</span><span class="calendar-time-divider">–</span><span class="calendar-time-end">${esc(scheduleClockLabel(slotEnd))}</span>`
        : esc(slot.label);
      html += `<div class="calendar-cell calendar-time calendar-row-${gridRow} calendar-column-1">${slotLabel}<span class="calendar-resize calendar-resize-row" role="separator" tabindex="0" aria-orientation="horizontal" aria-valuemin="56" aria-valuemax="240" aria-label="Resize row after ${esc(slot.label)}" title="Drag to resize this time slot" data-resize-axis="row" data-resize-index="${index}"></span></div>`;
      columns.forEach((_, dayIndex) => {
        html += `<div class="calendar-cell calendar-row-${gridRow} calendar-column-${dayIndex + 2}"></div>`;
      });
    });

    byDay.forEach((dayRows, day) => {
      if (!dayRows.length) return;
      dayRows.sort((left, right) => left.startRow - right.startRow
        || String(left.row.subject_code || '').localeCompare(String(right.row.subject_code || ''))
        || String(left.row.section_code || '').localeCompare(String(right.row.section_code || '')));
      const gridColumn = dayColumns.get(day);
      if (!gridColumn) return;
      const groups = state.calendarView === 'day'
        ? dayRows.map((entry) => ({
          startRow: entry.startRow,
          entries: [entry],
          rowSpan: entry.rowSpan
        }))
        : (() => {
        const overlapGroups = [];
        dayRows.forEach((entry) => {
          const previous = overlapGroups[overlapGroups.length - 1];
          const entryEnd = entry.startRow + entry.rowSpan;
          if (!previous || entry.startRow >= previous.endRow) {
            overlapGroups.push({ startRow: entry.startRow, endRow: entryEnd, entries: [entry] });
            return;
          }
          previous.entries.push(entry);
          previous.endRow = Math.max(previous.endRow, entryEnd);
        });
        return overlapGroups.map((group) => ({
          startRow: group.startRow,
          entries: group.entries,
          rowSpan: group.endRow - group.startRow
        }));
      })();
      const laneEnds = [];
      const dayEvents = groups.map(({ entries, startRow, rowSpan }) => {
        let lane = laneEnds.findIndex((laneEnd) => laneEnd <= startRow);
        if (lane === -1) {
          lane = laneEnds.length;
          laneEnds.push(startRow + rowSpan);
        } else {
          laneEnds[lane] = startRow + rowSpan;
        }
        if (entries.length === 1) {
          const event = renderCalendarEvent(entries[0]);
          return event.replace('class="calendar-event ', `class="calendar-event calendar-event-start-${startRow + 1} calendar-event-span-${rowSpan} calendar-event-lane-${lane + 1} `);
        }
        const startLabel = timeSlots[startRow]?.label || entries[0].row.time_label || entries[0].row.slot_label;
        const startMinutes = entries.map(({ row }) => scheduleClockMinutes(row.start_time)).filter((value) => value !== null);
        const endMinutes = entries.map(({ row }) => scheduleClockMinutes(row.end_time)).filter((value) => value !== null);
        const timeRange = startMinutes.length && endMinutes.length
          ? `${scheduleClockLabel(Math.min(...startMinutes))} - ${scheduleClockLabel(Math.max(...endMinutes))}`
          : startLabel;
        const label = `${entries.length} classes scheduled ${timeRange}`;
        const detailId = String(calendarDetailGroups.size);
        calendarDetailGroups.set(detailId, { entries, day: allColumns.find(([dayId]) => String(dayId) === String(day))?.[1] || '', timeRange });
        const groupsByTime = new Map();
        entries.forEach((entry) => {
          const range = scheduleTimeRangeLabel(entry.row);
          const timeGroup = groupsByTime.get(range) || [];
          timeGroup.push(entry.row);
          groupsByTime.set(range, timeGroup);
        });
        const durationSummary = [...groupsByTime.entries()]
          .map(([range, timeRows]) => {
            const noun = timeRows.length === 1 ? 'class' : 'classes';
            return `<span class="calendar-overlap-duration"><strong>${timeRows.length}</strong> ${noun} · ${esc(range)}</span>`;
          }).join('');
        return `<button class="calendar-event calendar-overlap calendar-event-start-${startRow + 1} calendar-event-span-${rowSpan} calendar-event-lane-${lane + 1}" type="button" data-calendar-detail-id="${detailId}" aria-label="${esc(label)}" title="Show all ${entries.length} classes scheduled ${esc(timeRange)}"><strong>${entries.length} ${entries.length === 1 ? 'class' : 'classes'}</strong><span class="calendar-overlap-breakdown">${durationSummary}</span><span class="calendar-overlap-action">Select to view all details</span></button>`;
      }).join('');
      html += `<div class="calendar-day-events calendar-column-${gridColumn} calendar-day-events-lanes-${Math.min(laneEnds.length, 12)}">${dayEvents}</div>`;
    });

    const calendar = $('#calendarGrid');
    calendar.classList.toggle('calendar-grid-day', state.calendarView === 'day');
    calendar.innerHTML = html;
    initializeCalendarLayout(allColumns.length, timeSlots.length);
    applyCalendarLayout(calendar);
    resizeDefaultCalendarLayout();
  }

  function initializeCalendarLayout(dayCount, rowCount) {
    if (calendarLayout.timeWidth === null) {
      const compact = window.matchMedia('(max-width: 620px)').matches;
      const timeWidth = compact ? 92 : 144;
      const calendarWidth = $('#calendarGrid').clientWidth;
      const dayWidth = Math.max(150, Math.min(480, Math.floor((calendarWidth - timeWidth) / dayCount)));
      let saved = null;
      let legacy = null;
      try {
        saved = JSON.parse(window.localStorage.getItem(CALENDAR_LAYOUT_KEY) || 'null');
        legacy = JSON.parse(window.localStorage.getItem(LEGACY_CALENDAR_LAYOUT_KEY) || 'null');
      } catch { saved = null; legacy = null; }
      const validSize = (value, min, max) => Number.isFinite(Number(value)) && Number(value) >= min && Number(value) <= max;
      calendarLayout.timeWidth = validSize(saved?.timeWidth, 80, 300) ? Number(saved.timeWidth) : timeWidth;
      calendarLayout.columnsAdjusted = saved?.columnsAdjusted === true;
      calendarLayout.dayWidths = Array.from({ length: dayCount }, (_, index) =>
        validSize(saved?.dayWidths?.[index], 100, 720) ? Number(saved.dayWidths[index]) : dayWidth);
      calendarLayout.rowHeights = Array.from({ length: rowCount }, (_, index) =>
        validSize(saved?.rowHeights?.[index] ?? legacy?.rowHeights?.[index], 56, 240)
          ? Number(saved?.rowHeights?.[index] ?? legacy.rowHeights[index]) : 82);
      return;
    }
    calendarLayout.dayWidths = Array.from({ length: dayCount }, (_, index) => Math.max(100, Math.min(720, calendarLayout.dayWidths[index] || 100)));
    calendarLayout.rowHeights = Array.from({ length: rowCount }, (_, index) => calendarLayout.rowHeights[index] || 82);
  }

  function applyCalendarLayout(calendar = $('#calendarGrid')) {
    if (!calendar || calendarLayout.timeWidth === null) return;
    const allColumns = days();
    const visibleIndexes = state.calendarView === 'day'
      ? [allColumns.findIndex(([day]) => String(day) === state.calendarDay)].filter((index) => index >= 0)
      : allColumns.map((_, index) => index);
    const timeWidth = window.matchMedia('(max-width: 620px)').matches ? 92 : calendarLayout.timeWidth;
    const availableDayWidth = Math.max(0, calendar.parentElement.clientWidth - timeWidth);
    const savedDayWidth = visibleIndexes.reduce((sum, index) => sum + calendarLayout.dayWidths[index], 0);
    const scale = savedDayWidth > availableDayWidth && availableDayWidth > 0
      ? availableDayWidth / savedDayWidth
      : 1;
    const fittedWidths = visibleIndexes.map((index) => Math.max(0, calendarLayout.dayWidths[index] * scale));
    calendar.style.width = '100%';
    calendar.style.gridTemplateColumns = state.calendarView === 'day'
      ? `${timeWidth}px minmax(0, 1fr)`
      : calendarLayout.columnsAdjusted
      ? `${timeWidth}px ${fittedWidths.map((width) => `${width}px`).join(' ')}`
      : `${timeWidth}px repeat(${visibleIndexes.length}, minmax(0, 1fr))`;
    calendar.style.gridTemplateRows = `40px ${calendarLayout.rowHeights.map((height) => `minmax(${height}px, auto)`).join(' ')}`;
    $$('.calendar-resize-column', calendar).forEach((handle) => {
      const width = calendarLayout.dayWidths[Number(handle.dataset.resizeIndex) - 1];
      handle.setAttribute('aria-valuenow', String(width));
    });
    $$('.calendar-resize-row', calendar).forEach((handle) => {
      const height = calendarLayout.rowHeights[Number(handle.dataset.resizeIndex)];
      handle.setAttribute('aria-valuenow', String(height));
    });
  }

  function resizeDefaultCalendarLayout() {
    if (calendarLayout.timeWidth === null) return;
    const calendar = $('#calendarGrid');
    if (!calendar) return;
    if (calendarLayout.columnsAdjusted) return;
    const visibleIndexes = state.calendarView === 'day'
      ? [days().findIndex(([day]) => String(day) === state.calendarDay)].filter((index) => index >= 0)
      : days().map((_, index) => index);
    const availableWidth = calendar.parentElement.clientWidth - calendarLayout.timeWidth - 8;
    const width = Math.max(100, Math.floor(availableWidth / visibleIndexes.length));
    visibleIndexes.forEach((index) => { calendarLayout.dayWidths[index] = width; });
    applyCalendarLayout(calendar);
  }

  function saveCalendarLayout() {
    try {
      window.localStorage.setItem(CALENDAR_LAYOUT_KEY, JSON.stringify(calendarLayout));
    } catch {
      announce('The timetable size was adjusted but could not be saved in this browser.');
    }
  }

  function resizeCalendarHandle(handle, delta) {
    const index = Number(handle.dataset.resizeIndex);
    if (handle.dataset.resizeAxis === 'column') {
      resizeCalendarColumns(index - 1, delta);
      calendarLayout.columnsAdjusted = true;
    } else {
      const height = calendarLayout.rowHeights[index];
      calendarLayout.rowHeights[index] = Math.max(56, Math.min(240, height + delta));
    }

    applyCalendarLayout();
  }

  function resizeCalendarColumns(index, delta) {
    const neighbor = index + 1 < calendarLayout.dayWidths.length ? index + 1 : index - 1;
    if (index < 0 || neighbor < 0 || neighbor >= calendarLayout.dayWidths.length) return;
    const direction = neighbor > index ? 1 : -1;
    const current = calendarLayout.dayWidths[index];
    const adjacent = calendarLayout.dayWidths[neighbor];
    const nextCurrent = Math.max(100, Math.min(720, current + delta * direction));
    const applied = nextCurrent - current;
    calendarLayout.dayWidths[index] = nextCurrent;
    calendarLayout.dayWidths[neighbor] = Math.max(100, Math.min(720, adjacent - applied));
  }

  function beginCalendarResize(event) {
    const handle = event.target.closest('.calendar-resize');
    if (!handle) return;
    event.preventDefault();
    handle.setPointerCapture(event.pointerId);
    calendarResize = {
      handle,
      pointerId: event.pointerId,
      axis: handle.dataset.resizeAxis,
      start: handle.dataset.resizeAxis === 'column' ? event.clientX : event.clientY,
      latestCoordinate: handle.dataset.resizeAxis === 'column' ? event.clientX : event.clientY,
      appliedCoordinate: handle.dataset.resizeAxis === 'column' ? event.clientX : event.clientY,
      initialDayWidths: calendarLayout.dayWidths.slice(),
      initialRowHeights: calendarLayout.rowHeights.slice(),
      initial: handle.dataset.resizeAxis === 'column'
        ? calendarLayout.dayWidths[Number(handle.dataset.resizeIndex) - 1]
        : calendarLayout.rowHeights[Number(handle.dataset.resizeIndex)]
    };
  }

  function moveCalendarResize(event) {
    if (!calendarResize || event.pointerId !== calendarResize.pointerId) return;
    const coordinate = calendarResize.axis === 'column' ? event.clientX : event.clientY;
    calendarResize.latestCoordinate = coordinate;
    if (calendarResizeFrame) return;
    calendarResizeFrame = requestAnimationFrame(() => {
      calendarResizeFrame = 0;
      if (!calendarResize) return;
      const delta = calendarResize.latestCoordinate - calendarResize.start;
      const index = Number(calendarResize.handle.dataset.resizeIndex);
      if (calendarResize.axis === 'column') {
        calendarLayout.dayWidths = calendarResize.initialDayWidths.slice();
        resizeCalendarColumns(index - 1, delta * CALENDAR_RESIZE_SENSITIVITY);
        calendarLayout.columnsAdjusted = true;
      } else {
        calendarLayout.rowHeights = calendarResize.initialRowHeights.slice();
        calendarLayout.rowHeights[index] = Math.max(56, Math.min(240, calendarResize.initial + (delta * CALENDAR_RESIZE_SENSITIVITY)));
      }
      applyCalendarLayout();
      calendarResize.appliedCoordinate = calendarResize.latestCoordinate;
    });
  }

  function endCalendarResize(event) {
    if (!calendarResize || event.pointerId !== calendarResize.pointerId) return;
    if (calendarResizeFrame) {
      cancelAnimationFrame(calendarResizeFrame);
      calendarResizeFrame = 0;
    }
    if (calendarResize.latestCoordinate !== calendarResize.appliedCoordinate) {
      const delta = calendarResize.latestCoordinate - calendarResize.start;
      const index = Number(calendarResize.handle.dataset.resizeIndex);
      if (calendarResize.axis === 'column') {
        calendarLayout.dayWidths = calendarResize.initialDayWidths.slice();
        resizeCalendarColumns(index - 1, delta * CALENDAR_RESIZE_SENSITIVITY);
        calendarLayout.columnsAdjusted = true;
      } else {
        calendarLayout.rowHeights = calendarResize.initialRowHeights.slice();
        calendarLayout.rowHeights[index] = Math.max(56, Math.min(240, calendarResize.initial + (delta * CALENDAR_RESIZE_SENSITIVITY)));
      }
      applyCalendarLayout();
    }
    calendarResize = null;
    saveCalendarLayout();
  }

  function keyboardResizeCalendar(event) {
    const handle = event.target.closest('.calendar-resize');
    if (!handle) return;
    const keys = handle.dataset.resizeAxis === 'column' ? ['ArrowLeft', 'ArrowRight'] : ['ArrowUp', 'ArrowDown'];
    if (!keys.includes(event.key)) return;
    event.preventDefault();
    const delta = event.key === keys[0] ? -8 : 8;
    resizeCalendarHandle(handle, delta);
    saveCalendarLayout();
  }

  const DATA_META = {
    rooms: { title: 'Rooms', singular: 'Room', headers: ['Code', 'Name', 'Capacity', 'Type', 'Features'], row: (item) => [item.code, item.name, `${item.capacity} seats`, item.room_type, (item.features || []).join(', ')], fields: roomFields },
    instructors: { title: 'Faculty', singular: 'Faculty member', headers: ['Employee no.', 'Name', 'Email', 'Max hours/day'], row: (item) => [item.employee_no, item.name, item.email || '-', `${item.max_hours_day} hours`], fields: instructorFields },
    subjects: { title: 'Subject & Course Code', singular: 'Subject', headers: ['Course Code', 'Subject Name'], row: (item) => [item.code, item.name], fields: subjectFields },
    programs: { title: 'Programs', singular: 'Program', headers: ['Code', 'Program name'], row: (item) => [item.code, item.name], fields: programFields },
    sections: { title: 'Sections', singular: 'Section', headers: ['Code', 'Program', 'Year', 'Students'], row: (item) => [item.code, item.program_code, `Year ${item.year_level}`, item.student_count], fields: sectionFields },
    offerings: { title: 'Class assignments', singular: 'Class assignment', headers: ['Subject', 'Section', 'Instructor', 'Enrollment', 'Meetings'], row: (item) => [`${item.subject_code} · ${item.subject_name}`, item.section_code, item.instructor_name, item.enrollment, item.required_meetings], fields: offeringFields },
    users: { title: 'Users', singular: 'User', headers: ['Username', 'Display name', 'Email', 'Role', 'Assignment'], row: (item) => [item.username, item.display_name, item.email || '-', item.role, item.instructor_name || item.section_code || '-'], fields: userFields }
  };

  function renderData() {
    if (state.dataTab === 'users' && !canAdmin()) state.dataTab = 'rooms';
    const isSubjectCatalog = state.dataTab === 'subjects';
    const meta = DATA_META[state.dataTab];
    $('#dataTabTitle').textContent = meta.title;
    $('#dataTabEyebrow').textContent = isSubjectCatalog ? 'Subject & course management' : 'Current term records';
    $('#subjectManagementTools').hidden = !isSubjectCatalog;
    $('#offeringImportPanel').hidden = state.dataTab !== 'offerings' || !canAdmin();
    $('#addRecordButton').textContent = isSubjectCatalog ? '+ Add Subject' : 'Add record';
    if (state.page === 'data') {
      const globalSearch = $('#globalSearch').closest('.search-field');
      globalSearch.hidden = isSubjectCatalog;
      globalSearch.setAttribute('aria-hidden', String(isSubjectCatalog));
    }
    $('#dataTableHead').innerHTML = `<tr>${meta.headers.map((header) => `<th>${esc(header)}</th>`).join('')}<th class="manage-column">${isSubjectCatalog ? 'Actions' : 'Action'}</th></tr>`;
    $$('.tab-button').forEach((button) => { const active = button.dataset.dataTab === state.dataTab; button.classList.toggle('active', active); button.setAttribute('aria-selected', String(active)); });
    const query = (isSubjectCatalog ? state.subjectQuery : state.query).trim().toLowerCase();
    const source = (state.snapshot[state.dataTab] || [])
      .filter((item) => !query || (isSubjectCatalog
        ? `${item.code} ${item.name}`.toLowerCase().includes(query)
        : Object.values(item).some((value) => String(value ?? '').toLowerCase().includes(query))))
      .sort(isSubjectCatalog ? (left, right) => left.code.localeCompare(right.code) || left.name.localeCompare(right.name) : () => 0);
    if (isSubjectCatalog) $('#subjectResultCount').textContent = `${source.length} subject${source.length === 1 ? '' : 's'}`;
    const emptyTitle = query ? 'No matching records' : `No ${meta.title.toLowerCase()} yet`;
    const emptyMessage = query ? 'Try a different search term.' : isSubjectCatalog ? 'Add a course code and subject name to build the catalog.' : 'Use “Add record” to create the first one.';
    $('#dataTableBody').innerHTML = source.length ? source.map((item) => {
      const cells = isSubjectCatalog
        ? `<td>${esc(item.code)}</td><td>${esc(item.name)}${roomTypeBadge(item.room_type)}</td>`
        : meta.row(item).map((cell) => `<td>${esc(cell)}</td>`).join('');
      return `<tr>${cells}<td class="manage-column"><div class="row-actions"><button class="button button-ghost button-small edit-record" data-entity="${state.dataTab}" data-record-id="${Number(item.id || 0)}" type="button">Edit</button><button class="button button-danger button-small delete-record" data-entity="${state.dataTab}" data-record-id="${Number(item.id || 0)}" type="button">Delete</button></div></td></tr>`;
    }).join('') : `<tr><td colspan="${meta.headers.length + 1}">${emptyState(emptyTitle, emptyMessage)}</td></tr>`;
    $$('.manage-column').forEach((cell) => { cell.hidden = !canManage(); });
    renderPendingRegistrations();
  }

  function renderPendingRegistrations() { const panel = $('#registrationReviewPanel'); if (!panel) return; panel.hidden = !canAdmin(); if (!canAdmin()) return; const rows = state.snapshot.pending_registrations || []; $('#pendingRegistrationCount').textContent = `${rows.length} pending`; $('#pendingRegistrationCount').className = `badge ${rows.length ? 'badge-warning' : 'badge-neutral'}`; $('#pendingRegistrationBody').innerHTML = rows.length ? rows.map((row) => `<tr><td><strong>${esc(row.display_name)}</strong></td><td>${esc(row.username)}</td><td>${esc(row.program_code)}</td><td>${esc(row.year_level)}</td><td>${esc(row.section_code || 'Unassigned')}</td><td><div class="row-actions"><button class="button button-primary button-small review-registration" data-registration-id="${Number(row.id)}" data-decision="APPROVE" type="button">Approve</button><button class="button button-danger button-small review-registration" data-registration-id="${Number(row.id)}" data-decision="REJECT" type="button">Reject</button></div></td></tr>`).join('') : `<tr><td colspan="6">${emptyState('No pending registrations', 'New student requests will appear here for review.')}</td></tr>`; }

  function renderReports() {
    const rows = schedules(); const roomCounts = new Map(); rows.forEach((row) => roomCounts.set(row.room_code, (roomCounts.get(row.room_code) || 0) + 1)); const max = Math.max(1, ...roomCounts.values());
    const validation = state.snapshot.validation; const run = state.snapshot.last_generation; const diagnostics = run?.diagnostics || {}; const metrics = [metric('Published classes', rows.length, 'Current published run'), metric('Faculty used', new Set(rows.map((row) => row.instructor_id)).size, `of ${state.snapshot.instructors.length}`), metric('Rooms used', new Set(rows.map((row) => row.room_id)).size, `of ${state.snapshot.rooms.length}`), metric('Sections covered', new Set(rows.map((row) => row.section_id)).size, `of ${state.snapshot.sections.length}`)];
    $('#reportMetricGrid').innerHTML = metrics.join('');
    const generationStatus = $('#lastGenerationStatus');
    if (!run) {
      generationStatus.textContent = 'No run'; generationStatus.className = 'badge badge-neutral';
      $('#lastGenerationReport').innerHTML = emptyState('No generation recorded', 'Generate a schedule to record its result here.');
    } else {
      const failures = Object.entries(diagnostics.failures || {});
      const warnings = [...new Set(diagnostics.warnings || [])];
      const successful = diagnostics.hard_constraints || [];
      const explanations = [...new Set([...(diagnostics.preflight_issues || []), ...(diagnostics.explanations || [])])];
      const issueGroups = (items) => {
        const groups = new Map();
        items.forEach((item) => {
          const value = String(item);
          let title = value;
          let fix = 'Review the affected scheduling records.';
          if (value.includes('scheduled hours but the subject requires')) {
            title = 'Scheduled hours do not match subject requirements';
            fix = 'Align meetings per week, duration, and subject hours.';
          } else if (value.includes('no room matching')) {
            title = 'No suitable room is available';
            fix = 'Add a matching room or reduce the class enrollment.';
          } else if (value.includes('no individually valid time')) {
            title = 'No valid time is available';
            fix = 'Review time slots, availability, and daily teaching limits.';
          } else if (value.includes('combined room, instructor, and section constraints')) {
            title = 'Resources are over-constrained together';
            fix = 'Review room, instructor, and section conflicts.';
          } else if (value.startsWith('no_candidate:')) {
            title = 'Some classes have no schedulable candidate';
            fix = 'Review the class requirements and available resources.';
          }
          if (!groups.has(title)) groups.set(title, { count: 0, fix, examples: [] });
          const group = groups.get(title); group.count += 1;
          if (group.examples.length < 3) group.examples.push(value.replace(/^no_candidate:/, ''));
        });
        return [...groups.entries()].sort((left, right) => right[1].count - left[1].count).map(([title, group]) => ({ title, ...group }));
      };
      const issueList = (items, kind) => {
        const groups = issueGroups(items);
        if (!groups.length) return `<div class="generation-clear health-ok">No ${kind === 'warning' ? 'warnings' : 'problems'} recorded.</div>`;
        return `<div class="generation-issues ${kind}"><div class="generation-issues-heading"><strong>${kind === 'warning' ? 'Warnings' : 'Problems to fix'}</strong><span>${items.length} affected</span></div><div class="generation-group-list">${groups.map((group) => `<article class="generation-group"><div class="generation-group-title"><strong>${esc(group.title)}</strong><span>${group.count}</span></div><div class="generation-fix">Fix: ${esc(group.fix)}</div><ul>${group.examples.map((example) => `<li>${esc(example)}</li>`).join('')}</ul></article>`).join('')}</div></div>`;
      };
      generationStatus.textContent = run.status === 'PUBLISHED' ? 'Published' : run.status; generationStatus.className = `badge ${run.status === 'PUBLISHED' ? '' : 'badge-warning'}`;
      $('#lastGenerationReport').innerHTML = [
        `<div class="generation-meta"><span>Run #${Number(run.id)}</span><time>${esc(run.created_at || 'Date unavailable')}</time></div>`,
        `<div class="generation-result"><strong>${Number(run.assigned_tasks)} / ${Number(run.total_tasks)}</strong><span>classes assigned</span></div>`,
        `<div class="generation-stats"><div><strong class="health-ok">${successful.length}</strong><span>checks tracked</span></div><div><strong class="${warnings.length ? 'health-warn' : 'health-ok'}">${warnings.length}</strong><span>warnings</span></div><div><strong class="${failures.length || explanations.length ? 'health-error' : 'health-ok'}">${failures.length + explanations.length}</strong><span>problems</span></div></div>`,
        `<div class="generation-search"><span>Search effort</span><strong>${Number(diagnostics.search_nodes || 0).toLocaleString()} nodes</strong></div>`,
        issueList(warnings, 'warning'),
        failures.length || explanations.length ? issueList([...failures.map(([name, count]) => `${name} (${Number(count)})`), ...explanations], 'problem') : issueList([], 'problem')
      ].join('');
    }
    $('#constraintReport').innerHTML = Object.entries(validation?.checks || {}).map(([item, passed]) => `<div class="constraint-row"><span class="constraint-status ${passed ? '' : 'health-error'}">${passed ? 'PASS' : 'FAIL'}</span><span>${esc(item.replaceAll('_', ' '))}</span></div>`).join('') || emptyState('No published run', 'Constraint evidence appears after a successful generation.');
    $('#roomReport').innerHTML = state.snapshot.rooms.length
      ? state.snapshot.rooms.map((room) => { const count = roomCounts.get(room.code) || 0; return `<div class="bar-row"><span class="bar-label" title="${esc(room.name)}">${esc(room.code)}</span><div class="bar-track"><div class="bar-fill" data-fill="${Math.round((count / max) * 100)}"></div></div><strong>${count}</strong></div>`; }).join('')
      : emptyState('No rooms configured', 'Add rooms under Academic setup to measure utilization.');
    // Widths are applied through the CSSOM: the content security policy has no
    // 'unsafe-inline', so a style="" attribute in this markup would be dropped.
    $$('#roomReport .bar-fill').forEach((bar) => { bar.style.width = `${bar.dataset.fill}%`; });
  }

  function profileSectionMarkup(section, fields, profile) {
    const values = fields.map(([key, label, type, required]) => `<div class="profile-value"><dt>${esc(label)}${required ? ' <span class="required-mark">*</span>' : ''}</dt><dd>${text(profile[key]) ? esc(profile[key]) : '<span class="profile-missing">Not provided</span>'}</dd></div>`).join('');
    const controls = fields.map(([key, label, type, required, maxLength, options = []]) => {
      const id = `profile-${key}`;
      const requiredAttribute = required ? 'required' : '';
      const control = type === 'select'
        ? `<select id="${id}" name="${key}" ${requiredAttribute}><option value="">Choose</option>${options.map((option) => `<option value="${esc(option)}" ${profile[key] === option ? 'selected' : ''}>${esc(option)}</option>`).join('')}</select>`
        : `<input id="${id}" name="${key}" type="${type}" value="${esc(profile[key] || '')}" maxlength="${maxLength}" ${requiredAttribute}>`;
      return `<div class="field"><label for="${id}">${esc(label)}${required ? ' <span class="required-mark">*</span>' : ''}</label>${control}</div>`;
    }).join('');
    return `<dl class="profile-values">${values}</dl><form class="profile-editor" data-profile-form="${section}" method="post" hidden><div class="profile-form-grid">${controls}</div><div class="profile-actions"><button class="button button-primary button-small" type="submit">Save changes</button><button class="button button-ghost button-small" type="button" data-profile-cancel="${section}">Cancel</button></div></form>`;
  }

  function renderProfile() {
    const profile = state.snapshot.student_profile || {};
    $('#profileBasicsContent').innerHTML = profileSectionMarkup('basics', [
      ['sex', 'Sex', 'select', true, 20, ['Male', 'Female', 'Other', 'Prefer not to say']],
      ['birthdate', 'Birthdate', 'date', true, 10],
      ['mobile_number', 'Mobile number', 'tel', true, 24],
      ['guardian_first_name', 'Primary guardian first name', 'text', true, 80],
      ['guardian_middle_name', 'Primary guardian middle name', 'text', false, 80],
      ['guardian_last_name', 'Primary guardian last name', 'text', true, 80],
      ['guardian_email', 'Primary guardian email address', 'email', true, 254],
      ['guardian_contact_number', 'Primary guardian contact number', 'tel', true, 24],
      ['guardian_relation', 'Relation to student', 'text', true, 60]
    ], profile);
    $('#profileAddressContent').innerHTML = profileSectionMarkup('address', [
      ['block_lot', 'Block/Lot/No/Village/Subdivision', 'text', true, 120],
      ['street_name', 'Street name', 'text', false, 120],
      ['barangay', 'Barangay', 'text', true, 120],
      ['city_municipality', 'City / Municipality', 'text', true, 120],
      ['province', 'Province', 'text', true, 120],
      ['zip_code', 'ZIP code', 'text', false, 12]
    ], profile);
    $('#profileParentsContent').innerHTML = profileSectionMarkup('parents', [
      ['father_last_name', 'Father last name', 'text', true, 80],
      ['father_first_name', 'Father first name', 'text', true, 80],
      ['father_middle_name', 'Father middle name', 'text', false, 80],
      ['mother_last_name', 'Mother last name (maiden name)', 'text', true, 80],
      ['mother_first_name', 'Mother first name', 'text', true, 80],
      ['mother_middle_name', 'Mother middle name', 'text', false, 80]
    ], profile);
    $('#profileVerified').checked = Boolean(profile.verified_at);
    $('#profileVerificationStatus').textContent = profile.verified_at ? 'Information verified' : '';
    ['basics', 'address', 'parents'].forEach((section) => setProfileSectionEditing(section, false));
  }

  function setProfileSectionEditing(section, editing) {
    const panel = $(`[data-profile-section="${section}"]`);
    if (!panel) return;
    panel.querySelector('.profile-values').hidden = editing;
    panel.querySelector('[data-profile-form]').hidden = !editing;
    panel.querySelector('[data-profile-edit]').hidden = editing;
    if (editing) panel.querySelector('input, select')?.focus();
  }

  function renderSettings() {
    const terms = state.snapshot.terms; const active = terms.find((term) => Number(term.id) === Number(state.snapshot.active_term_id)) || terms[0]; if (active) { $('#academicYear').value = active.academic_year; $('#semester').value = active.semester; } $('#settingsForm').closest('.panel').hidden = !canAdmin(); $('#accountEmailValue').textContent = state.snapshot.user.email || 'Not provided';
  }

  function renderAll() { buildNavigation(); $$('.manage-only').forEach((element) => { element.hidden = !canManage(); }); $$('.admin-only').forEach((element) => { element.hidden = !canAdmin(); }); $$('.student-only').forEach((element) => { element.hidden = role() !== 'student'; }); $$('.student-hidden').forEach((element) => { element.hidden = role() === 'student'; }); navigate(state.page); }

  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function resetModalControls() {
    $('#modalCloseButton').disabled = false;
    $('#modalCancelButton').disabled = false;
    const submit = $('.modal-footer button[type="submit"]');
    submit.disabled = false;
    submit.textContent = 'Save';
  }

  function openModal(title, fields, mode, record = {}) {
    modalMode = mode; modalRecord = record;
    modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    resetModalControls();
    $('#modal').classList.remove('modal-calendar-details', 'modal-generation');
    $('#modalTitle').textContent = title;
    $('#modalEyebrow').textContent = mode === 'manual-generate' ? 'Filtered generation' : mode.startsWith('edit') ? 'Update record' : 'New record';
    if (mode === 'manual-generate') {
      $('#modal').classList.add('modal-generation');
      $('.modal-footer button[type="submit"]').textContent = 'Generate selected';
    } else {
      $('.modal-footer button[type="submit"]').textContent = 'Save';
    }
    $('#modalForm').hidden = false;
    $('.modal-footer').hidden = false;
    $('#modalBody').innerHTML = `<div class="form-grid">${fields(record).join('')}</div>`;
    updateUserAssignmentFields();
    $('#modalBackdrop').hidden = false;
    document.body.classList.add('modal-open');
    // Land on the first field rather than the close button: reviewers fill these
    // dialogs in one pass and should be able to start typing immediately.
    const first = $(FOCUSABLE, $('#modalBody')) || $('#modalCloseButton');
    first.focus();
    if (first instanceof HTMLInputElement && first.type !== 'password') first.select();
    announce(`${title} dialog opened.`);
  }

  function openCalendarDetails(detailId) {
    const group = calendarDetailGroups.get(detailId);
    if (!group) return;
    activeCalendarDetailGroup = group;
    modalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    modalMode = 'calendar-details';
    modalRecord = null;
    $('#modal').classList.add('modal-calendar-details');
    $('#modalTitle').textContent = `${group.day} · ${group.timeRange}`;
    $('#modalEyebrow').textContent = `${group.entries.length} scheduled classes`;
    $('#modalForm').hidden = false;
    $('.modal-footer').hidden = true;
    const filterOptions = (key, label) => {
      const values = [...new Set(group.entries.map(({ row }) => String(row[key] || '').trim()).filter(Boolean))]
        .sort((left, right) => left.localeCompare(right));
      return `<label class="schedule-detail-filter">${esc(label)}<select data-schedule-detail-filter="${esc(key)}"><option value="">All ${esc(label.toLowerCase())}</option>${values.map((value) => `<option value="${esc(value)}">${esc(value)}</option>`).join('')}</select></label>`;
    };
    $('#modalBody').innerHTML = `<div class="schedule-detail-filters"><label class="schedule-detail-search">Search this time slot<input type="search" data-schedule-detail-search placeholder="Subject, section, instructor, or room" autocomplete="off"></label>${filterOptions('section_code', 'Section')}${filterOptions('instructor_name', 'Instructor')}${filterOptions('room_code', 'Room')}</div><p class="schedule-detail-result-count" data-schedule-detail-count aria-live="polite"></p><div class="schedule-detail-list" data-schedule-detail-list></div>`;
    renderCalendarDetailResults();
    $('#modalBackdrop').hidden = false;
    document.body.classList.add('modal-open');
    $('#modalCloseButton').focus();
    announce(`${group.entries.length} scheduled class details opened.`);
  }

  function renderCalendarDetailResults() {
    const group = activeCalendarDetailGroup;
    const list = $('[data-schedule-detail-list]', $('#modalBody'));
    if (!group || !list) return;
    const query = text($('[data-schedule-detail-search]', $('#modalBody'))?.value).toLowerCase();
    const selectedSection = text($('[data-schedule-detail-filter="section_code"]', $('#modalBody'))?.value);
    const selectedInstructor = text($('[data-schedule-detail-filter="instructor_name"]', $('#modalBody'))?.value);
    const selectedRoom = text($('[data-schedule-detail-filter="room_code"]', $('#modalBody'))?.value);
    const matches = group.entries.filter(({ row }) => {
      const subject = subjectById(row.subject_id);
      const searchable = `${row.subject_code} ${row.subject_name || subject?.name || ''} ${row.section_code} ${row.program_code || ''} ${row.instructor_name} ${row.room_code}`.toLowerCase();
      return (!query || searchable.includes(query))
        && (!selectedSection || String(row.section_code) === selectedSection)
        && (!selectedInstructor || String(row.instructor_name) === selectedInstructor)
        && (!selectedRoom || String(row.room_code) === selectedRoom);
    });
    const count = $('[data-schedule-detail-count]', $('#modalBody'));
    if (count) count.textContent = `Showing ${matches.length} of ${group.entries.length} classes`;
    list.innerHTML = matches.length ? matches.map(({ row }) => {
      const subject = subjectById(row.subject_id);
      const roomType = row.room_type || subject?.room_type || 'LECTURE';
      const type = scheduleTypeClass(roomType);
      return `<article class="schedule-detail-card schedule-color-${type}"><div class="schedule-detail-heading"><strong>${esc(row.subject_code)}</strong><span>${esc(row.time_label || row.slot_label)} · ${esc(scheduleDurationLabel(row))}</span></div><p>${esc(row.subject_name || subject?.name || '')}${roomTypeBadge(roomType)}</p><dl><div><dt>Section</dt><dd>${esc(row.section_code)}${row.program_code ? ` · ${esc(row.program_code)}` : ''}</dd></div><div><dt>Instructor</dt><dd>${esc(row.instructor_name)}</dd></div><div><dt>Room</dt><dd>${esc(row.room_code)}${row.room_capacity ? ` · ${Number(row.room_capacity)} seats` : ''}</dd></div></dl></article>`;
    }).join('') : '<p class="schedule-detail-empty">No classes match these filters. Try changing or clearing a filter.</p>';
  }

  /* Tab must not escape an open dialog, otherwise focus walks into the page
   * behind the backdrop where nothing is clickable. */
  function trapModalFocus(event, container = $('#modalBackdrop')) {
    const stops = $$(FOCUSABLE, container).filter((element) => element.offsetParent !== null);
    if (!stops.length) return;
    const first = stops[0]; const last = stops[stops.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && (active === first || !container.contains(active))) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && active === last) { event.preventDefault(); first.focus(); }
  }

  function openBrandPopup() {
    brandPopupReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    $('#brandPopup').hidden = false;
    document.body.classList.add('modal-open');
    $('#brandPopupClose').focus();
    announce('EasySched information dialog opened.');
  }

  function closeBrandPopup() {
    $('#brandPopup').hidden = true;
    document.body.classList.remove('modal-open');
    if (brandPopupReturnFocus?.isConnected) brandPopupReturnFocus.focus();
    brandPopupReturnFocus = null;
  }

  function inputField(id, label, value, type = 'text', required = true, extra = '') { const full = /(?:^|\s)class="[^"]*\bfull\b/.test(extra); return `<div class="field${full ? ' full' : ''}"><label for="modal-${id}">${esc(label)}</label><input id="modal-${id}" name="${esc(id)}" type="${type}" value="${esc(value)}" ${required ? 'required' : ''} ${extra}></div>`; }
  function selectField(id, label, value, options, required = true) { return `<div class="field"><label for="modal-${id}">${esc(label)}</label><select id="modal-${id}" name="${esc(id)}" ${required ? 'required' : ''}>${options.map(([optionValue, optionLabel]) => `<option value="${esc(optionValue)}" ${String(optionValue) === String(value) ? 'selected' : ''}>${esc(optionLabel)}</option>`).join('')}</select></div>`; }
  function userAssignmentField(id, label, value, options) { return `<div class="field" data-user-assignment="${esc(id)}">${selectField(id, label, value, options, false).replace(/^<div class="field">|<\/div>$/g, '')}</div>`; }
  function updateUserAssignmentFields() {
    const role = $('#modal-role')?.value;
    const instructorField = $('[data-user-assignment="instructor_id"]', $('#modalBody'));
    const sectionField = $('[data-user-assignment="section_id"]', $('#modalBody'));
    if (!instructorField || !sectionField) return;
    instructorField.hidden = role !== 'instructor';
    sectionField.hidden = role !== 'student';
  }
  function roomFields(row) { return [inputField('code','Room code',row.code || ''), inputField('name','Room name',row.name || ''), inputField('capacity','Capacity',row.capacity || '', 'number', true, 'min="1" max="5000"'), selectField('room_type','Room type',row.room_type || 'LECTURE', [['LECTURE','Lecture'],['LAB','Laboratory'],['SPECIAL','Special']]), inputField('features','Features (comma separated)',(row.features || []).join(', '), 'text', false, 'class="full"')]; }
  function instructorFields(row) { const prefixes = ['', 'Dr.', 'Prof.', 'Engr.', 'Mr.', 'Ms.', 'Mrs.']; const suffixes = ['', 'Jr.', 'Sr.', 'II', 'III', 'IV']; const nameParts = text(row.name).trim().split(/\s+/).filter(Boolean); const prefix = prefixes.includes(nameParts[0]) ? nameParts.shift() : ''; const suffix = suffixes.includes(nameParts[nameParts.length - 1]) ? nameParts.pop() : ''; const firstName = nameParts.shift() || ''; const lastName = nameParts.length ? nameParts.pop() : ''; const middleName = nameParts.join(' '); return [selectField('prefix','Prefix',prefix,[['','None'], ...prefixes.slice(1).map((item) => [item,item])], false), inputField('first_name','First name',firstName), inputField('middle_name','Middle name',middleName, 'text', false), inputField('last_name','Last name',lastName), selectField('suffix','Suffix',suffix,[['','None'], ...suffixes.slice(1).map((item) => [item,item])], false), inputField('employee_no','Employee number',row.employee_no || ''), inputField('email','Email',row.email || '', 'email', false), inputField('max_hours_day','Maximum hours per day',row.max_hours_day || 6, 'number', true, 'min="1" max="16"')]; }
  function subjectFields(row) { return [inputField('code','Course Code',row.code || '', 'text', true, 'maxlength="40" autocomplete="off"'), inputField('name','Subject Name',row.name || '', 'text', true, 'maxlength="180" class="full"'), selectField('room_type','Room type',row.room_type || 'LECTURE',[['LECTURE','Lecture'],['LAB','Laboratory'],['SPECIAL','Special']])]; }
  function programFields(row) { return [inputField('code','Program code',row.code || ''), inputField('name','Program name',row.name || '', 'text', true, 'class="full"')]; }
  function sectionFields(row) { return [selectField('program_id','Program',row.program_id || state.snapshot.programs[0]?.id || '', state.snapshot.programs.map((item) => [item.id, `${item.code} - ${item.name}`])), selectField('term_id','Term',row.term_id || state.snapshot.active_term_id, state.snapshot.terms.map((item) => [item.id, `${item.academic_year} - ${item.semester}`])), inputField('code','Section code',row.code || ''), inputField('year_level','Year level',row.year_level || 1, 'number', true, 'min="1" max="4"'), inputField('student_count','Student count',row.student_count || 30, 'number', true, 'min="1" max="5000"')]; }
  function offeringFields(row) { return [selectField('term_id','Term',row.term_id || state.snapshot.active_term_id, state.snapshot.terms.map((item) => [item.id, `${item.academic_year} - ${item.semester}`])), selectField('subject_id','Subject',row.subject_id || state.snapshot.subjects[0]?.id || '', state.snapshot.subjects.map((item) => [item.id, item.name])), selectField('section_id','Section',row.section_id || state.snapshot.sections[0]?.id || '', state.snapshot.sections.map((item) => [item.id, item.code])), selectField('instructor_id','Instructor',row.instructor_id || state.snapshot.instructors[0]?.id || '', state.snapshot.instructors.map((item) => [item.id, item.name])), inputField('enrollment','Enrollment',row.enrollment || 30, 'number', true, 'min="1" max="5000"'), inputField('required_meetings','Meetings per week',row.required_meetings || 1, 'number', true, 'min="1" max="20"')]; }
  function userFields(row) { return [inputField('username','Username',row.username || ''), inputField('display_name','Display name',row.display_name || ''), `<div class="field"><label for="modal-email">Email</label><input id="modal-email" name="email" type="email" value="${esc(row.email || '')}" maxlength="180"><span class="field-hint">Administrators can update this directly if the user can no longer access their email.</span></div>`, selectField('role','Role',row.role || 'student',[['admin','Administrator'],['scheduler','Scheduler'],['instructor','Instructor'],['student','Student']]), userAssignmentField('instructor_id','Faculty link',row.instructor_id || '', [['','Not linked'], ...state.snapshot.instructors.map((item) => [item.id,item.name])]), userAssignmentField('section_id','Section link',row.section_id || '', [['','Not linked'], ...state.snapshot.sections.map((item) => [item.id,item.code])]), inputField('password',row.id ? 'New password (leave blank to keep current)' : 'Temporary password','', 'password', !row.id, 'minlength="10" class="full"')]; }

  function openDataRecord(entity, id = 0) { const record = (state.snapshot[entity] || []).find((item) => Number(item.id) === Number(id)) || {}; const mode = id ? `edit-${entity}` : `create-${entity}`; const label = DATA_META[entity].singular; openModal(id ? `Edit ${label}` : `Add ${label}`, DATA_META[entity].fields, mode, record); }

  function downloadOfferingsTemplate() {
    const csv = 'academic_year,semester,subject_code,subject_name,section_code,instructor_employee_no,instructor_name,enrollment,required_meetings\r\n';
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'class-assignments-template.csv';
    link.click();
    URL.revokeObjectURL(url);
  }

  function exportOfferings() {
    window.location.href = 'api.php?action=export_offerings';
  }

  async function importOfferings(event) {
    event.preventDefault();
    const file = $('#offeringImportFile').files?.[0];
    const status = $('#offeringImportStatus');
    const submit = $('#offeringImportSubmit');
    if (!file) return;
    if (file.size > 2000000) {
      status.textContent = 'Choose a CSV file that is 2 MB or smaller.';
      return;
    }
    if (!window.confirm(`Add missing assignments from "${file.name}"? Existing Railway assignments will not be changed.`)) return;
    submit.disabled = true;
    status.textContent = 'Validating and importing assignments...';
    try {
      const csv = await file.text();
      const result = await request('import_offerings', { method: 'POST', body: { csv } });
      applySnapshot(result.snapshot);
      $('#offeringImportFile').value = '';
      const summary = `Added ${result.created} assignments. ${result.skipped} already existed and were left unchanged.`;
      status.textContent = summary;
      showToast('Assignments imported', summary);
    } catch (error) {
      const rowErrors = error.details?.rows;
      if (Array.isArray(rowErrors) && rowErrors.length) {
        const grouped = new Map();
        rowErrors.forEach((item) => {
          const rows = grouped.get(item.error) || [];
          rows.push(item.row);
          grouped.set(item.error, rows);
        });
        const summaries = Array.from(grouped, ([message, rows]) => {
          const examples = rows.slice(0, 5).join(', ');
          const remaining = rows.length > 5 ? `, and ${rows.length - 5} more` : '';
          return `${message} (${rows.length} row${rows.length === 1 ? '' : 's'}: ${examples}${remaining})`;
        });
        const missingNames = rowErrors.some((item) => item.error.includes('Export a fresh CSV to include instructor names'));
        const nextStep = missingNames
          ? '\nDownload a new assignments CSV from the updated localhost app, then upload that new file here. The older CSV has no instructor names to match across databases.'
          : '';
        status.textContent = `${error.message}\n${summaries.join('\n')}${nextStep}`;
      } else {
        status.textContent = error.message;
      }
      showToast('Could not import assignments', error.message, 'error');
    } finally {
      submit.disabled = false;
    }
  }

  function closeModal() { const returnFocus = modalReturnFocus; $('#modalBackdrop').hidden = true; document.body.classList.remove('modal-open'); $('#modal').classList.remove('modal-calendar-details', 'modal-generation'); resetModalControls(); $('#modalBody').replaceChildren(); activeCalendarDetailGroup = null; modalMode = null; modalRecord = null; modalReturnFocus = null; if (returnFocus?.isConnected) returnFocus.focus(); }

  async function submitModal(event) { event.preventDefault(); if (!modalMode) return; if (modalMode === 'manual-generate') return submitManualGeneration(event); const entity = modalMode.replace(/^(create|edit)-/, ''); const formData = new FormData(event.currentTarget); const data = Object.fromEntries(formData.entries()); if (entity === 'instructors') { data.name = [data.prefix, data.first_name, data.middle_name, data.last_name, data.suffix].map((value) => text(value).trim()).filter(Boolean).join(' '); delete data.prefix; delete data.first_name; delete data.middle_name; delete data.last_name; delete data.suffix; } if (['rooms', 'subjects'].includes(entity)) data.features = text(data.features).split(',').map((item) => item.trim()).filter(Boolean); if (entity === 'subjects') data.required_features = text(formData.get('required_features')).split(',').map((item) => item.trim()).filter(Boolean); if (modalRecord?.id) data.id = Number(modalRecord.id); try { const result = await request('save_master', { method: 'POST', body: { entity, data } }); applySnapshot(result.snapshot); closeModal(); showToast('Saved', `${DATA_META[entity].singular} saved successfully.`); } catch (error) { showToast('Could not save', error.message, 'error'); } }

  function manualTimeField(name, label, value) {
    const [hourValue, minuteValue] = String(value || '07:00').slice(0, 5).split(':').map(Number);
    const period = hourValue >= 12 ? 'PM' : 'AM';
    const hour = hourValue % 12 || 12;
    return `<div class="field"><label for="modal-${name}">${label}</label><div class="time-input-group"><input id="modal-${name}" name="${name}" value="${String(hour).padStart(2, '0')}:${String(minuteValue).padStart(2, '0')}" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" required><select id="modal-${name}-period" name="${name}_period" aria-label="${label} period"><option value="AM" ${period === 'AM' ? 'selected' : ''}>AM</option><option value="PM" ${period === 'PM' ? 'selected' : ''}>PM</option></select></div></div>`;
  }

  function openScheduleEditor(entryId) {
    const row = schedules().find((item) => Number(item.id) === Number(entryId));
    if (!row) return;
    const roomOptions = state.snapshot.rooms.map((item) => [item.id, `${item.code} - ${item.name}`]);
    const dayOptions = days();
    openModal(`Edit ${row.subject_code}`, () => [
      selectField('room_id', 'Room', row.room_id, roomOptions),
      selectField('day_of_week', 'Day', row.day_of_week, dayOptions),
      manualTimeField('start_time', 'Start time', row.start_time),
      manualTimeField('end_time', 'End time', row.end_time)
    ], 'edit-schedule', row);
  }

  function openAddScheduleModal() {
    if (!state.snapshot.active_run) {
      showToast('No published schedule', 'Generate a schedule before adding a class.', 'warn');
      return;
    }
    const scheduledCounts = new Map();
    schedules().forEach((row) => scheduledCounts.set(Number(row.offering_id), (scheduledCounts.get(Number(row.offering_id)) || 0) + 1));
    const offerings = state.snapshot.offerings || [];
    if (!offerings.length) {
      showToast('No class assignments', 'Add a class assignment in Academic setup before scheduling a meeting.', 'warn');
      return;
    }
    const offeringOptions = offerings.map((item) => {
      const scheduled = scheduledCounts.get(Number(item.id)) || 0;
      const remaining = Number(item.required_meetings) - scheduled;
      const meetingStatus = remaining > 0 ? `${remaining} required remaining` : `extra meeting (${scheduled} already scheduled)`;
      return [item.id, `${item.subject_code} · ${item.section_code} (${meetingStatus})`];
    });
    const roomOptions = state.snapshot.rooms.map((item) => [item.id, `${item.code} · ${item.name}`]);
    const dayOptions = days();
    const availableSlots = slots().filter((item) => Number(item.slot_order) !== 6);
    if (!roomOptions.length || !dayOptions.length || !availableSlots.length) {
      showToast('Cannot add class', 'Active rooms, weekdays, and timetable hours must be configured first.', 'error');
      return;
    }
    const timeField = (name, label, value, period) => `<div class="field"><label for="modal-${name}">${label}</label><div class="time-input-group"><input id="modal-${name}" name="${name}" value="${value}" inputmode="numeric" maxlength="5" pattern="[0-9]{2}:[0-9]{2}" placeholder="HH:MM" required><select id="modal-${period}" name="${period}" aria-label="${label} period"><option selected>AM</option><option>PM</option></select></div></div>`;
    openModal('Add class', () => [
      selectField('offering_id', 'Course and section', offeringOptions[0][0], offeringOptions),
      selectField('room_id', 'Room', roomOptions[0]?.[0] || '', roomOptions),
      selectField('day_of_week', 'Day', dayOptions[0]?.[0] || '', dayOptions),
      timeField('start_time', 'Start time', '07:00', 'start_period'),
      timeField('end_time', 'End time', '09:00', 'end_period'),
      '<p class="field-hint full">Enter any class duration that fits configured timetable hours. Manual additions can use a room that does not meet the class capacity, type, or feature requirements. Availability, schedule conflicts, lunch breaks, and instructor daily-hour limits are still checked.</p>',
      '<p class="field-hint full">Adds one meeting, including extra meetings when all required meetings are scheduled.</p>'
    ], 'add-schedule');
  }

  async function submitScheduleEditor(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    try {
      const result = await request('save_schedule', {
        method: 'POST',
        body: {
          entry_id: Number(modalRecord.id),
          room_id: Number(form.get('room_id')),
          day_of_week: Number(form.get('day_of_week')),
          start_time: form.get('start_time'),
          start_period: form.get('start_time_period'),
          end_time: form.get('end_time'),
          end_period: form.get('end_time_period')
        }
      });
      applySnapshot(result.snapshot);
      closeModal();
      showToast('Schedule updated', 'The entry passed hard-constraint validation.');
    } catch (error) {
      showToast('Cannot update schedule', error.message, 'error');
    }
  }

  async function submitAddSchedule(event) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    try {
      const result = await request('add_schedule', {
        method: 'POST',
        body: {
          offering_id: Number(form.get('offering_id')),
          room_id: Number(form.get('room_id')),
          day_of_week: Number(form.get('day_of_week')),
          start_time: form.get('start_time'),
          start_period: form.get('start_period'),
          end_time: form.get('end_time'),
          end_period: form.get('end_period')
        }
      });
      applySnapshot(result.snapshot);
      closeModal();
      showToast('Class added', 'The class was added after schedule validation.');
    } catch (error) {
      showToast('Cannot add class', error.message, 'error');
    }
  }

  async function cancelScheduleEntry(entryId) { if (!canManage() || !window.confirm('Cancel this class meeting? The published run will be marked incomplete until regenerated.')) return; try { const result = await request('delete_schedule', { method: 'POST', body: { entry_id: Number(entryId) } }); applySnapshot(result.snapshot); showToast('Class cancelled', 'The meeting was removed while the schedule history was preserved.', 'warn'); } catch (error) { showToast('Cannot cancel class', error.message, 'error'); } }

  function manualGenerationFields() {
    const snapshot = state.snapshot;
    const offerings = snapshot.offerings || [];
    const sectionById = new Map((snapshot.sections || []).map((section) => [Number(section.id), section]));
    const uniqueOptions = (key, source, labelFor) => {
      const ids = new Set(offerings.map((offering) => Number(offering[key])));
      return source.filter((row) => ids.has(Number(row.id))).map((row) => ({ id: Number(row.id), label: labelFor(row) }));
    };
    const offeredInstructorIds = new Set(offerings.map((offering) => Number(offering.instructor_id)));
    const offeredProgramIds = new Set(offerings.map((offering) => Number(sectionById.get(Number(offering.section_id))?.program_id)).filter(Boolean));
    const groups = [
      { key: 'program', name: 'program_ids', label: 'Programs', options: (snapshot.programs || []).filter((row) => offeredProgramIds.has(Number(row.id))).map((row) => ({ id: Number(row.id), label: `${row.code} · ${row.name}` })) },
      { key: 'section', name: 'section_ids', label: 'Sections', options: uniqueOptions('section_id', snapshot.sections || [], (row) => `${row.code} · ${row.program_code || sectionById.get(Number(row.id))?.program_code || 'Program'}`) },
      { key: 'subject', name: 'subject_ids', label: 'Subjects', options: uniqueOptions('subject_id', snapshot.subjects || [], (row) => `${row.code} · ${row.name}`) },
      { key: 'instructor', name: 'instructor_ids', label: 'Instructors', options: (snapshot.instructors || []).map((row) => {
        const hasOfferings = offeredInstructorIds.has(Number(row.id));
        return { id: Number(row.id), label: hasOfferings ? row.name : `${row.name} · No active class assignments`, disabled: !hasOfferings };
      }) }
    ];
    const groupMarkup = groups.map((group) => `<fieldset class="manual-generation-group" data-generation-group="${group.key}"><legend class="manual-generation-legend">${esc(group.label)}</legend><div class="manual-generation-group-actions"><button class="button button-ghost" type="button" data-generation-filter-action="all">All</button><button class="button button-ghost" type="button" data-generation-filter-action="clear">Clear</button></div><div class="manual-generation-options">${group.options.length ? group.options.map((option) => `<label class="manual-generation-option"><input type="checkbox" name="${group.name}" value="${option.id}"${option.disabled ? ' disabled' : ''}><span>${esc(option.label)}</span></label>`).join('') : '<span class="manual-generation-empty">No active options</span>'}</div></fieldset>`).join('');
    return [`<p class="manual-generation-note">Choose at least one filter. When multiple filter groups are used, an offering must match every selected group. Matching offerings are regenerated; all other published classes are preserved.</p><div class="manual-generation-grid">${groupMarkup}</div><p class="manual-generation-preview" data-generation-preview aria-live="polite"></p>`];
  }

  function updateManualGenerationPreview() {
    if (modalMode !== 'manual-generate') return;
    const form = $('#modalForm');
    const selected = {
      program_ids: new Set(Array.from(form.querySelectorAll('[name="program_ids"]:checked'), (input) => Number(input.value))),
      section_ids: new Set(Array.from(form.querySelectorAll('[name="section_ids"]:checked'), (input) => Number(input.value))),
      subject_ids: new Set(Array.from(form.querySelectorAll('[name="subject_ids"]:checked'), (input) => Number(input.value))),
      instructor_ids: new Set(Array.from(form.querySelectorAll('[name="instructor_ids"]:checked'), (input) => Number(input.value)))
    };
    const hasSelection = Object.values(selected).some((ids) => ids.size > 0);
    const sectionById = new Map((state.snapshot.sections || []).map((section) => [Number(section.id), section]));
    const matches = (state.snapshot.offerings || []).filter((offering) => {
      const section = sectionById.get(Number(offering.section_id));
      return (!selected.program_ids.size || selected.program_ids.has(Number(section?.program_id)))
        && (!selected.section_ids.size || selected.section_ids.has(Number(offering.section_id)))
        && (!selected.subject_ids.size || selected.subject_ids.has(Number(offering.subject_id)))
        && (!selected.instructor_ids.size || selected.instructor_ids.has(Number(offering.instructor_id)));
    });
    const meetings = matches.reduce((total, offering) => total + Math.max(1, Number(offering.required_meetings) || 1), 0);
    const preview = $('[data-generation-preview]', $('#modalBody'));
    const submit = $('.modal-footer button[type="submit"]');
    if (!hasSelection) {
      preview.textContent = 'Select one or more filters to preview the offerings that will be regenerated.';
      submit.disabled = true;
    } else if (!matches.length) {
      preview.textContent = 'No active offerings match these filter choices. Adjust your selections to continue.';
      submit.disabled = true;
    } else {
      preview.textContent = `${matches.length} offering${matches.length === 1 ? '' : 's'} · ${meetings} meeting${meetings === 1 ? '' : 's'} will be regenerated.`;
      submit.disabled = false;
    }
  }

  function openManualGenerationModal() {
    if (!canManage()) return;
    openModal('Manual schedule generation', manualGenerationFields, 'manual-generate');
    updateManualGenerationPreview();
  }

  async function submitManualGeneration(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const submit = $('.modal-footer button[type="submit"]');
    const cancel = $('#modalCancelButton');
    const close = $('#modalCloseButton');
    const selectedIds = (name) => Array.from(new FormData(form).getAll(name), Number);
    const body = {
      term_id: Number(state.snapshot.active_term_id),
      program_ids: selectedIds('program_ids'),
      section_ids: selectedIds('section_ids'),
      subject_ids: selectedIds('subject_ids'),
      instructor_ids: selectedIds('instructor_ids')
    };
    submit.disabled = true;
    cancel.disabled = true;
    close.disabled = true;
    submit.textContent = 'Generating...';
    try {
      const result = await request('generate_selected', { method: 'POST', body });
      applySnapshot(result.snapshot);
      closeModal();
      showToast('Manual schedule published', `${result.diagnostics.generated_tasks} selected meetings generated; ${result.diagnostics.preserved_entries} existing classes preserved.`);
    } catch (error) {
      const details = error.details || {};
      const issue = details.preflight_issues?.[0] || details.explanations?.[0] || Object.keys(details.failures || {})[0];
      showToast('Manual generation failed', issue ? `${error.message} ${issue.replace(/^no_candidate:/, '')}` : error.message, 'error');
    } finally {
      if (modalMode === 'manual-generate') {
        cancel.disabled = false;
        close.disabled = false;
        submit.textContent = 'Generate selected';
        updateManualGenerationPreview();
      }
    }
  }

  async function generate() { const buttons = [$('#generateButton'), $('#dashboardGenerateButton')].filter(Boolean); const originalContent = buttons.map((button) => button.innerHTML); buttons.forEach((button) => { button.disabled = true; button.textContent = 'Generating...'; }); try { const result = await request('generate', { method: 'POST', body: { term_id: state.snapshot.active_term_id } }); applySnapshot(result.snapshot); showToast('Schedule published', `${result.diagnostics.assigned_tasks} classes passed validation and were published.`); } catch (error) { const details = error.details || {}; const issue = details.preflight_issues?.[0] || details.explanations?.[0] || Object.keys(details.failures || {})[0]; const message = issue ? `${error.message} ${issue.replace(/^no_candidate:/, '')}` : error.message; showToast('Generation failed', message, 'error'); try { applySnapshot(await request('bootstrap')); } catch { /* Keep the current snapshot if the refresh is unavailable. */ } } finally { buttons.forEach((button, index) => { button.disabled = false; button.innerHTML = originalContent[index]; }); } }

  function exportSchedule() {
    if (selectedScheduleIsSample) {
      showToast('Sample schedule only', 'This illustrative schedule is not a real historical record and cannot be exported as one.', 'warn');
      return;
    }
    if (selectedScheduleRun) {
      window.location.href = `api.php?action=export&run_id=${encodeURIComponent(selectedScheduleRun.id)}`;
      return;
    }
    const termId = Number(selectedScheduleTermId ?? state.snapshot?.active_term_id);
    const query = Number.isInteger(termId) && termId > 0 ? `&term_id=${encodeURIComponent(termId)}` : '';
    window.location.href = `api.php?action=export${query}`;
  }
  function exportReport() {
    if ($('#reportExportFormat').value === 'csv') return exportSchedule();
    navigate('schedules');
    window.setTimeout(() => window.print(), 120);
  }

  async function deleteRecord(entity, id) { if (!canManage() || !window.confirm('Delete this record from the active system? Existing history will be preserved.')) return; try { const result = await request('save_master', { method: 'POST', body: { entity, id: Number(id), delete: true } }); applySnapshot(result.snapshot); showToast('Deleted', 'The record was removed from the active system. Existing history was preserved.'); } catch (error) { showToast('Cannot delete record', error.message, 'error'); } }

  async function login(event) { event.preventDefault(); const username = text($('#loginUsername').value).toLowerCase(); const password = $('#loginPassword').value; const captcha = $('#loginCaptcha').value; $('#loginError').textContent = ''; try { const result = await request('login', { method: 'POST', body: { username, password, captcha } }); updateLoginChallenge(); applySnapshot(result); showToast('Welcome', `Signed in as ${result.user.display_name}.`); if (result.security_alert?.failed_attempts) showToast('Security notice', `${result.security_alert.failed_attempts} failed login attempt${result.security_alert.failed_attempts === 1 ? '' : 's'} were recorded for this account in the last 24 hours. Change your password if this was not you.`, 'warn'); } catch (error) { $('#loginError').textContent = error.message; updateLoginChallenge(error.details || {}); (error.details?.captcha_required ? $('#loginCaptcha') : $('#loginPassword')).focus(); } }
  async function logout() { try { await request('logout', { method: 'POST', body: {} }); } catch (error) { /* session may already be gone */ } state.snapshot = null; showPublicHome(); }

  async function saveSettings(event) { event.preventDefault(); try { const result = await request('save_settings', { method: 'POST', body: { academic_year: $('#academicYear').value, semester: $('#semester').value } }); applySnapshot(result.snapshot); showToast('Term saved', 'The active academic term was updated.'); } catch (error) { showToast('Cannot save term', error.message, 'error'); } }
  async function sendEmailChangeOtp() {
    if (!$('#newEmail').reportValidity()) return;
    const button = $('#requestEmailOtpButton');
    const status = $('#emailChangeStatus');
    button.disabled = true;
    status.textContent = 'Sending verification code…';
    try {
      const result = await request('request_email_change_otp', { method: 'POST', body: { new_email: $('#newEmail').value } });
      status.textContent = result.message;
    } catch (error) {
      status.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  }
  async function confirmEmailChange(event) {
    event.preventDefault();
    const status = $('#emailChangeStatus');
    try {
      const result = await request('confirm_email_change', { method: 'POST', body: { otp: $('#emailChangeOtp').value } });
      $('#emailChangeForm').reset();
      status.textContent = result.message;
      applySnapshot(result.snapshot);
      showToast('Email updated', result.message);
    } catch (error) {
      status.textContent = error.message;
    }
  }
  async function saveStudentProfile(event) {
    event.preventDefault();
    const form = event.target;
    const body = Object.fromEntries(new FormData(form).entries());
    body.section = form.dataset.profileForm;
    try {
      const result = await request('save_student_profile', { method: 'POST', body });
      applySnapshot(result.snapshot);
      showToast('Profile saved', 'Your information has been updated. Please verify it again if everything is correct.');
    } catch (error) {
      showToast('Cannot save profile', error.message, 'error');
    }
  }
  async function updateProfileVerification(event) {
    const checkbox = event.target;
    const verified = checkbox.checked;
    try {
      const result = await request('save_student_profile', { method: 'POST', body: { section: 'verification', verified } });
      applySnapshot(result.snapshot);
      showToast(verified ? 'Information verified' : 'Verification removed', verified ? 'Your profile is marked as verified.' : 'Your profile is no longer marked as verified.');
    } catch (error) {
      checkbox.checked = !verified;
      showToast('Cannot verify profile', error.message, 'error');
    }
  }
  async function changePassword(event) { event.preventDefault(); try { const result = await request('change_password', { method: 'POST', body: { current_password: $('#currentPassword').value, new_password: $('#newPassword').value, confirm_password: $('#confirmPassword').value } }); $('#passwordForm').reset(); showToast('Password changed', result.message); } catch (error) { showToast('Cannot change password', error.message, 'error'); } }

  function closeSidebar() { $('#sidebar').classList.remove('open'); $('#sidebarBackdrop').classList.remove('active'); $('#menuButton').setAttribute('aria-expanded', 'false'); }
  function toggleSidebar() { const open = $('#sidebar').classList.toggle('open'); $('#sidebarBackdrop').classList.toggle('active', open); $('#menuButton').setAttribute('aria-expanded', String(open)); }
  function isTypingIn(target) { return target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'SELECT', 'TEXTAREA'].includes(target.tagName)); }

  function initHomeScrollReveals() {
    const home = $('#publicHome');
    if (!home || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const groups = $$('.home-section-heading, .home-feature-grid, .home-step-grid, .home-benefit-grid, .home-about-copy, .home-contact-wrap', home)
      .map((group) => Array.from(group.children));
    if (!groups.some((group) => group.length)) return;

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

    home.classList.add('has-scroll-reveals');
    groups.forEach((group) => {
      group.forEach((element, index) => {
        element.classList.add('home-reveal');
        element.style.setProperty('--home-reveal-delay', `${Math.min(index, 5) * 85}ms`);
        observer.observe(element);
      });
    });
  }

  function bindEvents() {
    window.addEventListener('popstate', handleRouteChange);
    const calendar = $('#calendarGrid');
    window.addEventListener('resize', resizeDefaultCalendarLayout);
    calendar.addEventListener('pointerdown', beginCalendarResize);
    calendar.addEventListener('pointermove', moveCalendarResize);
    calendar.addEventListener('pointerup', endCalendarResize);
    calendar.addEventListener('pointercancel', endCalendarResize);
    calendar.addEventListener('keydown', keyboardResizeCalendar);
    const homeContactForm = $('#homeContactForm');
    homeContactForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const status = $('#homeContactStatus');
      const submit = homeContactForm.querySelector('[type="submit"]');
      submit.disabled = true;
      status.textContent = 'Sending your message…';
      status.removeAttribute('data-error');
      try {
        const response = await fetch(homeContactForm.action, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
          body: JSON.stringify(Object.fromEntries(new FormData(homeContactForm).entries()))
        });
        const payload = await response.json();
        if (!response.ok || payload.ok !== true) {
          throw new Error(payload.error || 'Your message could not be sent. Please try again later.');
        }
        status.textContent = payload.data?.message || 'Your message has been sent.';
        homeContactForm.reset();
      } catch (error) {
        status.textContent = error.message || 'Your message could not be sent. Please try again later.';
        status.setAttribute('data-error', 'true');
      } finally {
        submit.disabled = false;
      }
    });
    $('#brandPopupTrigger').addEventListener('click', openBrandPopup);
    $('#auditFilters').addEventListener('submit', (event) => { event.preventDefault(); state.audit.page = 1; loadAuditLogs(); });
    $('#auditClearButton').addEventListener('click', () => { $('#auditFilters').reset(); state.audit.page = 1; loadAuditLogs(); });
    $('#auditPreviousButton').addEventListener('click', () => { if (state.audit.page > 1) { state.audit.page -= 1; loadAuditLogs(); } });
    $('#auditNextButton').addEventListener('click', () => { if (state.audit.page < state.audit.pages) { state.audit.page += 1; loadAuditLogs(); } });
    $('#auditExportButton').addEventListener('click', () => { const query = new URLSearchParams({ action: 'audit_export', ...auditFilterValues() }); window.location.href = `api.php?${query.toString()}`; });
    $('#auditBody').addEventListener('click', (event) => { const button = event.target.closest('[data-audit-detail]'); if (button) showAuditDetail(button.dataset.auditDetail); });
    $('#auditDetailClose').addEventListener('click', () => $('#auditDetailDialog').close());
    $('#brandPopupClose').addEventListener('click', closeBrandPopup);
    $('#brandPopup').addEventListener('mousedown', (event) => { if (event.target === $('#brandPopup')) closeBrandPopup(); });
    document.addEventListener('submit', (event) => { if (event.target.matches('[data-profile-form]')) saveStudentProfile(event); });
    document.addEventListener('change', (event) => { if (event.target.matches('#profileVerified')) updateProfileVerification(event); });
    document.addEventListener('click', (event) => {
      const edit = event.target.closest('[data-profile-edit]');
      if (edit) setProfileSectionEditing(edit.dataset.profileEdit, true);
      const cancel = event.target.closest('[data-profile-cancel]');
      if (cancel) renderProfile();
    });
    $$('[data-show-login]').forEach((button) => button.addEventListener('click', showSignIn));
    $('#backToHomeButton').addEventListener('click', showPublicHome);
    $('#themeToggle').addEventListener('click', toggleTheme);
    $('#refreshLoginCaptcha').addEventListener('click', refreshLoginCaptcha);
    $('#loginForm').addEventListener('submit', login); $('#registrationForm').addEventListener('submit', registerStudent); $('#registrationProgram').addEventListener('change', renderRegistrationSections); $('#registrationYear').addEventListener('change', renderRegistrationSections); $('#scheduleTermFilter').addEventListener('change', changeScheduleTerm); $('#scheduleHistoryBody').addEventListener('click', (event) => { const sample = event.target.closest('[data-schedule-history-sample]'); if (sample) { viewSampleSchedule(Number(sample.dataset.scheduleHistorySample)); return; } const button = event.target.closest('[data-schedule-history-run]'); if (button) viewScheduleHistory(Number(button.dataset.scheduleHistoryRun)); }); $$('[data-password-toggle]').forEach((button) => button.addEventListener('click', () => togglePasswordVisibility(button))); $('#showRegistrationButton').addEventListener('click', showRegistration); $('#forgotPasswordButton').addEventListener('click', showForgotPassword); $('#backToLoginButton').addEventListener('click', () => showLogin('', true, true)); $('#backFromResetButton').addEventListener('click', () => showLogin('', true, true)); $('#sendRegistrationOtpButton').addEventListener('click', sendRegistrationOtp); $('#sendResetOtpButton').addEventListener('click', sendResetOtp); $('#forgotPasswordForm').addEventListener('submit', resetPassword); $('#logoutButton').addEventListener('click', logout); $('#menuButton').addEventListener('click', toggleSidebar); $('#sidebarBackdrop').addEventListener('click', closeSidebar);     $('#modalCloseButton').addEventListener('click', closeModal); $('#modalCancelButton').addEventListener('click', closeModal); $('#modalForm').addEventListener('submit', (event) => modalMode === 'edit-schedule' ? submitScheduleEditor(event) : modalMode === 'add-schedule' ? submitAddSchedule(event) : submitModal(event)); $('#generateButton').addEventListener('click', generate); $('#manualGenerateButton').addEventListener('click', openManualGenerationModal); $('#addScheduleButton').addEventListener('click', openAddScheduleModal); $('#dashboardGenerateButton').addEventListener('click', generate); $('#exportButton').addEventListener('click', exportSchedule); $('#reportExportButton').addEventListener('click', exportReport); $('#printButton').addEventListener('click', () => window.print()); $('#settingsForm').addEventListener('submit', saveSettings); $('#passwordForm').addEventListener('submit', changePassword); $('#requestEmailOtpButton').addEventListener('click', sendEmailChangeOtp); $('#emailChangeForm').addEventListener('submit', confirmEmailChange);
    $('#studentLinksButton').addEventListener('click', openStudentLinks); $('#studentLinksClose').addEventListener('click', () => $('#studentLinksDialog').close()); $('#studentLinksDialog').addEventListener('click', (event) => { if (event.target === $('#studentLinksDialog')) $('#studentLinksDialog').close(); });
    $('#globalSearch').addEventListener('input', (event) => { state.query = event.target.value; if (state.page === 'dashboard') renderDashboard(); if (state.page === 'schedules') renderSchedules(); if (state.page === 'data') renderData(); }); $('#scheduleViewFilter').addEventListener('change', (event) => { state.scheduleView = event.target.value; state.scheduleFilter = 'all'; renderSchedules(); }); $('#scheduleFilterValue').addEventListener('change', (event) => { state.scheduleFilter = event.target.value; renderSchedules(); }); $('#addRecordButton').addEventListener('click', () => openDataRecord(state.dataTab));
    $('#calendarWeekView').addEventListener('click', () => { state.calendarView = 'week'; renderSchedules(); });
    $('#calendarDayView').addEventListener('click', () => { state.calendarView = 'day'; renderSchedules(); });
    $('#calendarDayFilter').addEventListener('change', (event) => { state.calendarDay = event.target.value; renderSchedules(); });
    $('#calendarGrid').addEventListener('click', (event) => {
      const button = event.target.closest('[data-calendar-detail-id]');
      if (button) openCalendarDetails(button.dataset.calendarDetailId);
    });
    $('#modalBody').addEventListener('input', (event) => {
      if (event.target.matches('[data-schedule-detail-search]')) renderCalendarDetailResults();
    });
    $('#modalBody').addEventListener('change', (event) => {
      if (event.target.matches('[data-schedule-detail-filter]')) renderCalendarDetailResults();
      if (event.target.matches('#modal-role')) updateUserAssignmentFields();
      if (event.target.matches('.manual-generation-options input')) updateManualGenerationPreview();
    });
    $('#modalBody').addEventListener('click', (event) => {
      const action = event.target.closest('[data-generation-filter-action]');
      if (!action) return;
      const group = action.closest('[data-generation-group]');
      if (!group) return;
      group.querySelectorAll('.manual-generation-options input').forEach((input) => { input.checked = action.dataset.generationFilterAction === 'all' && !input.disabled; });
      updateManualGenerationPreview();
    });
    $('#subjectSearch').addEventListener('input', (event) => { state.subjectQuery = event.target.value; if (state.page === 'data' && state.dataTab === 'subjects') renderData(); });
    $('#offeringImportForm').addEventListener('submit', importOfferings);
    $('#offeringImportTemplate').addEventListener('click', downloadOfferingsTemplate);
    $('#offeringExportButton').addEventListener('click', exportOfferings);
    $('#helpBackButton').addEventListener('click', () => { if (helpIndex > 0) { helpIndex -= 1; renderHelp(); } }); $('#helpNextButton').addEventListener('click', () => { const slides = HELP_SLIDES[role()] || helpSlides; if (helpIndex < slides.length - 1) { helpIndex += 1; renderHelp(); } else navigate('dashboard'); });
    $('#helpDots').addEventListener('click', (event) => {
      const stepButton = event.target.closest('[data-help-index]');
      if (!stepButton) return;
      const step = Number(stepButton.dataset.helpIndex);
      const slides = HELP_SLIDES[role()] || helpSlides;
      if (!Number.isInteger(step) || step < 0 || step >= slides.length) return;
      helpIndex = step;
      renderHelp();
    });
    $('#requestOffering').addEventListener('change', () => { updateRoomRequestType(); invalidateRoomAvailability(); }); $('#requestPurpose').addEventListener('input', invalidateRoomAvailability);
    $('#requestDateButton').addEventListener('click', () => {
      const calendar = $('#requestDateCalendar');
      if (!calendar.hidden) return closeRequestCalendar();
      configureRequestDateBounds();
      const selectedDate = $('#requestDate').value;
      const selectedMonth = selectedDate ? new Date(`${selectedDate}T00:00:00`).getMonth() : new Date().getMonth();
      requestCalendarMonth = selectedMonth;
      renderRequestCalendar();
      calendar.hidden = false;
      $('#requestDateButton').setAttribute('aria-expanded', 'true');
    });
    $('#requestCalendarMonth').addEventListener('change', (event) => { requestCalendarMonth = Number(event.target.value); renderRequestCalendar(); });
    $('#requestCalendarDays').addEventListener('click', (event) => {
      const day = event.target.closest('[data-request-date]');
      if (day && !day.disabled) chooseRequestDate(day.dataset.requestDate);
    });
    document.addEventListener('click', (event) => {
      if (!event.target.closest('.request-date-picker')) closeRequestCalendar();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !$('#requestDateCalendar').hidden) {
        closeRequestCalendar();
        $('#requestDateButton').focus();
      }
    });
    ['#requestOffering', '#requestStartTime', '#requestStartPeriod', '#requestEndTime', '#requestEndPeriod'].forEach((selector) => { const field = $(selector); field.addEventListener('input', invalidateRoomAvailability); field.addEventListener('change', invalidateRoomAvailability); });
    $('#requestRoom').addEventListener('change', () => { $('#submitRoomRequestButton').disabled = !availableRoomsReady || !roomRequestAllowed || !$('#requestRoom').value; });
    $('#scheduleRequestForm').addEventListener('submit', requestSchedule); ['#requestStartTime', '#requestEndTime'].forEach((selector) => $(selector).addEventListener('input', (event) => formatTimeInput(event.target)));             document.addEventListener('click', (event) => { const dashboardManualGenerate = event.target.closest('[data-dashboard-manual-generate]'); if (dashboardManualGenerate) { openManualGenerationModal(); return; } const dashboardGenerate = event.target.closest('[data-dashboard-generate]'); if (dashboardGenerate) { generate(); return; } const dashboardAdd = event.target.closest('[data-dashboard-add]'); if (dashboardAdd) { navigate('schedules'); openAddScheduleModal(); return; } const navigateButton = event.target.closest('[data-navigate]'); if (navigateButton) { if (!navigateButton.dataset.navigate) return; if (navigateButton.dataset.dashboardTab) state.dataTab = navigateButton.dataset.dashboardTab; navigate(navigateButton.dataset.navigate); } const dataTab = event.target.closest('[data-data-tab]'); if (dataTab) { state.dataTab = dataTab.dataset.dataTab; $$('.tab-button').forEach((button) => { const active = button.dataset.dataTab === state.dataTab; button.classList.toggle('active', active); button.setAttribute('aria-selected', String(active)); }); renderData(); } const review = event.target.closest('.review-registration'); if (review) reviewRegistration(Number(review.dataset.registrationId), review.dataset.decision); const scheduleReview = event.target.closest('.review-schedule-request'); if (scheduleReview) reviewScheduleRequest(Number(scheduleReview.dataset.requestId), scheduleReview.dataset.decision); const edit = event.target.closest('.edit-record'); if (edit) openDataRecord(edit.dataset.entity, Number(edit.dataset.recordId)); const del = event.target.closest('.delete-record'); if (del) deleteRecord(del.dataset.entity, Number(del.dataset.recordId)); const editEntry = event.target.closest('.edit-entry'); if (editEntry) openScheduleEditor(Number(editEntry.dataset.entryId)); const cancelEntry = event.target.closest('.cancel-entry'); if (cancelEntry) cancelScheduleEntry(Number(cancelEntry.dataset.entryId)); });
    $('#scheduleChangeForm').addEventListener('submit', requestScheduleChange);
    $('#changeEntry').addEventListener('change', setChangeFormFromSelectedEntry);
    ['#changeStartTime', '#changeEndTime'].forEach((selector) => $(selector).addEventListener('input', (event) => formatTimeInput(event.target)));
    document.addEventListener('input', (event) => {
      if (event.target.matches('#modal-start_time, #modal-end_time')) formatTimeInput(event.target);
    });
    document.addEventListener('keydown', (event) => {
      const modalOpen = !$('#modalBackdrop').hidden;
      const brandPopupOpen = !$('#brandPopup').hidden;
      if (event.key === 'Escape') { if (modalOpen) closeModal(); if (brandPopupOpen) closeBrandPopup(); closeSidebar(); return; }
      if (modalOpen || brandPopupOpen) { if (event.key === 'Tab') trapModalFocus(event, brandPopupOpen ? $('#brandPopup') : $('#modalBackdrop')); return; }
      // "/" jumps to the view filter, matching the hint rendered beside the field.
      if (event.key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey && !isTypingIn(event.target)) {
        const search = $('#globalSearch'); const field = search?.closest('.search-field');
        if (search && field && !field.hidden) { event.preventDefault(); search.focus(); search.select(); }
      }
    });
    $('#modalBackdrop').addEventListener('mousedown', (event) => { if (event.target === $('#modalBackdrop')) closeModal(); });
    initHomeScrollReveals();
    const homePreview = $('#homeDashboardPreview');
    if (homePreview && window.matchMedia('(prefers-reduced-motion: no-preference)').matches) {
      const updateHomeParallax = () => {
        const bounds = homePreview.getBoundingClientRect();
        const distanceFromCenter = (bounds.top + bounds.height / 2 - window.innerHeight / 2) / window.innerHeight;
        const offset = Math.max(-10, Math.min(10, distanceFromCenter * -24));
        homePreview.style.setProperty('--home-parallax-y', `${offset}px`);
      };
      window.addEventListener('scroll', updateHomeParallax, { passive: true });
      window.addEventListener('resize', updateHomeParallax);
      updateHomeParallax();
    }
    const parallax = $('#loginParallax');
    if (parallax && window.matchMedia('(prefers-reduced-motion: no-preference)').matches) {
      parallax.addEventListener('pointermove', (event) => {
        const rect = parallax.getBoundingClientRect();
        const x = ((event.clientX - rect.left) / rect.width - .5) * 16;
        const y = ((event.clientY - rect.top) / rect.height - .5) * 12;
        parallax.style.setProperty('--parallax-x', `${x}px`);
        parallax.style.setProperty('--parallax-y', `${y}px`);
      });
      parallax.addEventListener('pointerleave', () => { parallax.style.setProperty('--parallax-x', '0px'); parallax.style.setProperty('--parallax-y', '0px'); });
    }
  }

  async function start() {
    bindEvents();
    window.setInterval(() => {
      configureRequestDateBounds();
      if (state.snapshot && state.page === 'dashboard') renderDashboard();
    }, 30000);
    const initialRoute = readRoute();
    if ((NAV.admin || []).some(([id]) => id === initialRoute)) state.page = initialRoute;
    if (initialRoute === 'login') showLogin('', false);
    if (initialRoute === 'register') await showRegistration(false);
    if (initialRoute === 'forgot-password') showForgotPassword(false);
    if (initialRoute === 'home') canonicalizeHomePath();
    try {
      const result = await request('bootstrap');
      if (initialRoute === 'home' || initialRoute === 'login' || initialRoute === 'register' || initialRoute === 'forgot-password') state.page = 'dashboard';
      applySnapshot(result);
    } catch (error) {
      if (error.status === 401) {
        if ((NAV.admin || []).some(([id]) => id === initialRoute)) {
          showLogin('', false);
          setRoute('login', true);
        }
        return;
      }
      showLogin(error.message);
    }
  }
  document.addEventListener('DOMContentLoaded', start);
})();
