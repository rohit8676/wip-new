<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WIP Management Portal - Login</title>
  
  <!-- Google Fonts: Plus Jakarta Sans -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <!-- CSS Stylesheet -->
  <link rel="stylesheet" href="css/style.css">
</head>
<body>

  <!-- Toast Notification Container -->
  <div id="toastContainer" class="toast-container" aria-live="polite"></div>

  <main class="login-container">
    <div class="login-card">
      <div class="card-header">
        <h1 class="portal-title">WIP Management Portal</h1>
        <h2 class="login-subtitle">Login</h2>
        <p>Sign in to your account</p>
      </div>

      <!-- Login Form (POST via AJAX to api/login.php) -->
      <form id="loginForm" method="POST" action="api/login.php" novalidate autocomplete="on">
        
        <!-- Email Input -->
        <div class="form-group">
          <label for="email" class="form-label">Email</label>
          <div class="input-wrapper">
            <input 
              type="email" 
              id="email" 
              name="email" 
              class="form-input" 
              placeholder="admin@wip.com" 
              autocomplete="email" 
              required
            >
            <div class="input-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                <polyline points="22,6 12,13 2,6"></polyline>
              </svg>
            </div>
          </div>
          <div class="input-feedback" id="emailFeedback"></div>
        </div>

        <!-- Password Input -->
        <div class="form-group">
          <label for="password" class="form-label">Password</label>
          <div class="input-wrapper">
            <input 
              type="password" 
              id="password" 
              name="password" 
              class="form-input" 
              placeholder="••••••••••••" 
              autocomplete="current-password" 
              required
            >
            <div class="input-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              </svg>
            </div>
            <button type="button" class="password-toggle-btn" id="passwordToggle" aria-label="Show password">
              <svg id="passwordEyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                <circle cx="12" cy="12" r="3"></circle>
              </svg>
            </button>
          </div>
          <div class="input-feedback" id="passwordFeedback"></div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn-submit" id="submitBtn">
          <span class="btn-text">Login</span>
          <span class="spinner" aria-hidden="true"></span>
        </button>

      </form>

      <!-- Demo Accounts Helper
      <div style="margin-top: 18px; padding-top: 14px; border-top: 1px dashed #e2e8f0; font-size: 0.76rem; color: #64748b; text-align: center; line-height: 1.5;">
        <span>Demo MSSQL Accounts:</span><br>
        <code>admin@wip.com</code> / <code>admin123</code><br>
        <code>user@wip.com</code> / <code>user123</code>
      </div> -->

    </div>
  </main>

  <!-- Login JS Logic -->
  <script src="js/app.js"></script>
</body>
</html>
