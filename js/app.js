/**
 * WIP Management Portal - Simple Light Mode Login Logic with MSSQL API
 */

document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('loginForm');
  const emailInput = document.getElementById('email');
  const passwordInput = document.getElementById('password');
  const passwordToggle = document.getElementById('passwordToggle');
  const passwordEyeIcon = document.getElementById('passwordEyeIcon');
  const submitBtn = document.getElementById('submitBtn');
  const toastContainer = document.getElementById('toastContainer');

  // ==========================================
  // Password Visibility Toggle
  // ==========================================
  if (passwordToggle && passwordInput) {
    passwordToggle.addEventListener('click', () => {
      const isPassword = passwordInput.getAttribute('type') === 'password';
      passwordInput.setAttribute('type', isPassword ? 'text' : 'password');

      if (isPassword) {
        // Eye slashed / Hide
        passwordEyeIcon.innerHTML = `
          <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
          <line x1="1" y1="1" x2="23" y2="23"></line>
        `;
        passwordToggle.setAttribute('aria-label', 'Hide password');
      } else {
        // Normal eye
        passwordEyeIcon.innerHTML = `
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
          <circle cx="12" cy="12" r="3"></circle>
        `;
        passwordToggle.setAttribute('aria-label', 'Show password');
      }
    });
  }

  // ==========================================
  // Validation Helpers
  // ==========================================
  const showError = (inputEl, message) => {
    const group = inputEl.closest('.form-group');
    if (group) {
      group.classList.add('has-error');
      const feedback = group.querySelector('.input-feedback');
      if (feedback) feedback.textContent = message;
    }
  };

  const clearError = (inputEl) => {
    const group = inputEl.closest('.form-group');
    if (group) {
      group.classList.remove('has-error');
    }
  };

  [emailInput, passwordInput].forEach((input) => {
    if (input) {
      input.addEventListener('input', () => clearError(input));
    }
  });

  const isValidEmail = (email) => {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  };

  // ==========================================
  // Toast Notification
  // ==========================================
  const showToast = (message, type = 'info') => {
    if (!toastContainer) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    let iconSvg = '';
    if (type === 'success') {
      iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;
    } else {
      iconSvg = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>`;
    }

    toast.innerHTML = `
      <span class="toast-icon">${iconSvg}</span>
      <span class="toast-message">${message}</span>
    `;

    toastContainer.appendChild(toast);

    requestAnimationFrame(() => {
      toast.classList.add('show');
    });

    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => {
        if (toast.parentElement) toast.remove();
      }, 250);
    }, 4000);
  };

  // ==========================================
  // Form Submission via MSSQL API
  // ==========================================
  if (loginForm) {
    loginForm.addEventListener('submit', (e) => {
      e.preventDefault();

      let hasError = false;
      const emailVal = emailInput.value.trim();
      const passwordVal = passwordInput.value;

      if (!emailVal) {
        showError(emailInput, 'Please enter your email');
        hasError = true;
      } else if (!isValidEmail(emailVal)) {
        showError(emailInput, 'Please enter a valid email address');
        hasError = true;
      }

      if (!passwordVal) {
        showError(passwordInput, 'Please enter your password');
        hasError = true;
      }

      if (hasError) return;

      // Submit via Fetch API to PHP backend
      submitBtn.classList.add('loading');
      submitBtn.disabled = true;

      fetch('api/login.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          email: emailVal,
          password: passwordVal
        })
      })
      .then(async (response) => {
        let data;
        try {
          data = await response.json();
        } catch (err) {
          data = { success: false, message: 'Server response error.' };
        }

        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;

        if (response.ok && data.success) {
          showToast(data.message || 'Login successful!', 'success');
          setTimeout(() => {
            window.location.href = data.redirect || 'dashboard.php';
          }, 800);
        } else {
          showToast(data.message || 'Invalid email or password.', 'error');
        }
      })
      .catch(() => {
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
        showToast('Unable to connect to login server.', 'error');
      });
    });
  }
});
