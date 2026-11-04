/**
 * GLOBAL RECIPROCAL COLLEGES - Professor Grading System
 * File: assets/js/app.js
 * Frontend interactivity: modal management, live search/filtering, real-time grade calculations
 */

document.addEventListener('DOMContentLoaded', () => {
  initSidebarToggle();
  initAutoDismissAlerts();
  initTableSearch();
  initGradeModalCalculations();
  initScoreInputValidators();
});

/**
 * Mobile Sidebar Toggle
 */
function initSidebarToggle() {
  const toggleBtn = document.getElementById('menuToggleBtn');
  const sidebar = document.getElementById('appSidebar');

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      sidebar.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
      if (window.innerWidth <= 992 && sidebar.classList.contains('show')) {
        if (!sidebar.contains(e.target) && e.target !== toggleBtn) {
          sidebar.classList.remove('show');
        }
      }
    });
  }
}

/**
 * Auto-dismiss flash alert messages after 5 seconds
 */
function initAutoDismissAlerts() {
  const alerts = document.querySelectorAll('.alert');
  alerts.forEach(alert => {
    setTimeout(() => {
      alert.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
      alert.style.opacity = '0';
      alert.style.transform = 'translateY(-10px)';
      setTimeout(() => alert.remove(), 400);
    }, 5000);
  });
}

/**
 * Generic Modal Functions
 */
function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.remove('open');
    document.body.style.overflow = '';
  }
}

// Close modal on escape or background click
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-backdrop.open').forEach(modal => {
      modal.classList.remove('open');
    });
    document.body.style.overflow = '';
  }
});

document.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal-backdrop')) {
    e.target.classList.remove('open');
    document.body.style.overflow = '';
  }
});

/**
 * Client-side Real-time Search and Filter on Tables
 */
function initTableSearch() {
  const searchInputs = document.querySelectorAll('[data-table-search]');
  searchInputs.forEach(input => {
    const tableId = input.getAttribute('data-table-search');
    const table = document.getElementById(tableId);
    if (!table) return;

    input.addEventListener('input', () => {
      const query = input.value.toLowerCase().trim();
      const rows = table.querySelectorAll('tbody tr');

      rows.forEach(row => {
        // Skip informational rows like 'No records found'
        if (row.classList.contains('no-records-row')) return;

        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
      });
    });
  });
}

/**
 * Score Input Restrictions: 0 to 100, decimals allowed, empty allowed
 */
function initScoreInputValidators() {
  document.addEventListener('input', (e) => {
    if (e.target.classList.contains('score-input') || e.target.getAttribute('type') === 'number' && e.target.hasAttribute('data-score')) {
      const val = e.target.value.trim();
      if (val === '') return;

      const num = parseFloat(val);
      if (isNaN(num)) {
        e.target.value = '';
      } else if (num < 0) {
        e.target.value = '0';
      } else if (num > 100) {
        e.target.value = '100';
      }
    }
  });
}

/**
 * Real-time Grade Calculation in Grade Entry Modal
 */
function initGradeModalCalculations() {
  const gradeForm = document.getElementById('gradeEntryForm');
  if (!gradeForm) return;

  const inputs = gradeForm.querySelectorAll('input[data-component]');
  inputs.forEach(input => {
    input.addEventListener('input', recalculateModalGrades);
  });
}

function parseScore(val) {
  if (val === null || val === undefined) return null;
  val = String(val).trim();
  if (val === '') return null;
  const num = parseFloat(val);
  return (isNaN(num) || num < 0 || num > 100) ? null : num;
}

function recalculateModalGrades() {
  const form = document.getElementById('gradeEntryForm');
  if (!form) return;

  const getVal = (name) => {
    const el = form.querySelector(`[name="${name}"]`);
    return el ? parseScore(el.value) : null;
  };

  // 1. Midterm Lecture Component
  const midLecScores = [
    { val: getVal('mid_lec_attendance'),   w: 0.15 },
    { val: getVal('mid_lec_recitation'),   w: 0.15 },
    { val: getVal('mid_lec_assignment'),   w: 0.05 },
    { val: getVal('mid_lec_quizzes'),      w: 0.30 },
    { val: getVal('mid_lec_prelim_exam'),  w: 0.10 },
    { val: getVal('mid_lec_midterm_exam'), w: 0.25 },
  ];
  const midLecComplete = midLecScores.every(s => s.val !== null);
  const midLecGrade = midLecComplete ? midLecScores.reduce((sum, s) => sum + (s.val * s.w), 0) : null;

  // 2. Midterm Lab Component
  const midLabScores = [
    { val: getVal('mid_lab_attendance'),   w: 0.15 },
    { val: getVal('mid_lab_recitation'),   w: 0.15 },
    { val: getVal('mid_lab_assignment'),   w: 0.05 },
    { val: getVal('mid_lab_quizzes'),      w: 0.30 },
    { val: getVal('mid_lab_prelim_exam'),  w: 0.10 },
    { val: getVal('mid_lab_midterm_exam'), w: 0.25 },
  ];
  const midLabComplete = midLabScores.every(s => s.val !== null);
  const midLabGrade = midLabComplete ? midLabScores.reduce((sum, s) => sum + (s.val * s.w), 0) : null;

  // Midterm Period Total = 65% Lec + 35% Lab
  const midPeriodGrade = (midLecGrade !== null && midLabGrade !== null) 
    ? (midLecGrade * 0.65) + (midLabGrade * 0.35) 
    : null;

  // 3. Final Lecture Component
  const fnlLecScores = [
    { val: getVal('fnl_lec_attendance'),  w: 0.15 },
    { val: getVal('fnl_lec_recitation'),  w: 0.15 },
    { val: getVal('fnl_lec_assignment'),  w: 0.05 },
    { val: getVal('fnl_lec_quizzes'),     w: 0.30 },
    { val: getVal('fnl_lec_final_exam'),  w: 0.35 },
  ];
  const fnlLecComplete = fnlLecScores.every(s => s.val !== null);
  const fnlLecGrade = fnlLecComplete ? fnlLecScores.reduce((sum, s) => sum + (s.val * s.w), 0) : null;

  // 4. Final Lab Component
  const fnlLabScores = [
    { val: getVal('fnl_lab_attendance'),  w: 0.15 },
    { val: getVal('fnl_lab_recitation'),  w: 0.15 },
    { val: getVal('fnl_lab_assignment'),  w: 0.05 },
    { val: getVal('fnl_lab_quizzes'),     w: 0.30 },
    { val: getVal('fnl_lab_final_exam'),  w: 0.35 },
  ];
  const fnlLabComplete = fnlLabScores.every(s => s.val !== null);
  const fnlLabGrade = fnlLabComplete ? fnlLabScores.reduce((sum, s) => sum + (s.val * s.w), 0) : null;

  // Final Period Total = 65% Lec + 35% Lab
  const fnlPeriodGrade = (fnlLecGrade !== null && fnlLabGrade !== null) 
    ? (fnlLecGrade * 0.65) + (fnlLabGrade * 0.35) 
    : null;

  // Course Grade = (Midterm + Final) / 2
  const courseGrade = (midPeriodGrade !== null && fnlPeriodGrade !== null) 
    ? (midPeriodGrade + fnlPeriodGrade) / 2 
    : null;

  // Update UI Elements
  updatePreviewBadge('previewMidLec', midLecGrade);
  updatePreviewBadge('previewMidLab', midLabGrade);
  updatePreviewBadge('previewMidterm', midPeriodGrade);

  updatePreviewBadge('previewFnlLec', fnlLecGrade);
  updatePreviewBadge('previewFnlLab', fnlLabGrade);
  updatePreviewBadge('previewFinal', fnlPeriodGrade);

  updatePreviewBadge('previewCourse', courseGrade);
}

function updatePreviewBadge(elementId, gradeVal) {
  const el = document.getElementById(elementId);
  if (!el) return;

  if (gradeVal === null) {
    el.innerHTML = '<span class="badge badge-incomplete">Incomplete</span>';
  } else {
    el.innerHTML = `<span class="badge badge-complete">${gradeVal.toFixed(2)}%</span>`;
  }
}

/**
 * Tab switching in Modal Grade Form (Midterm Lec / Midterm Lab / Final Lec / Final Lab)
 */
function switchGradeTab(evt, tabId) {
  const form = document.getElementById('gradeEntryForm');
  if (!form) return;

  const tabBtns = form.querySelectorAll('.grade-tab-btn');
  tabBtns.forEach(btn => btn.classList.remove('active'));

  const tabContents = form.querySelectorAll('.grade-tab-content');
  tabContents.forEach(content => content.style.display = 'none');

  evt.currentTarget.classList.add('active');
  const target = document.getElementById(tabId);
  if (target) {
    target.style.display = 'block';
  }
}

/**
 * Switch Authentication Tabs (Sign In / Register Setup)
 */
function switchAuthTab(tabName) {
  const loginForm = document.getElementById('loginFormContainer');
  const registerForm = document.getElementById('registerFormContainer');
  const loginTab = document.getElementById('tabLoginBtn');
  const registerTab = document.getElementById('tabRegisterBtn');

  if (tabName === 'login') {
    if (loginForm) loginForm.style.display = 'block';
    if (registerForm) registerForm.style.display = 'none';
    if (loginTab) loginTab.classList.add('active');
    if (registerTab) registerTab.classList.remove('active');
  } else {
    if (loginForm) loginForm.style.display = 'none';
    if (registerForm) registerForm.style.display = 'block';
    if (loginTab) loginTab.classList.remove('active');
    if (registerTab) registerTab.classList.add('active');
  }
}

/**
 * Confirmation dialog before critical delete actions
 */
function confirmAction(message, formElement) {
  if (confirm(message)) {
    if (formElement) formElement.submit();
    return true;
  }
  return false;
}

