<?php
session_start();
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Login</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>

  <style>
    /* ── Login toast — reference style: light bg, colored border + icon, dismiss btn ── */
    .login-toast {
      position: fixed !important;
      top: 1rem !important;
      right: 1rem !important;
      left: auto !important;
      bottom: auto !important;
      transform: translateX(110%) !important;
      width: 300px !important;
      background: #fff !important;
      border-radius: 8px !important;
      border-left: 4px solid #ccc !important;
      box-shadow: 0 4px 20px rgba(0,0,0,.12) !important;
      padding: .75rem 1rem !important;
      display: flex !important;
      align-items: flex-start !important;
      gap: .65rem !important;
      opacity: 0 !important;
      transition: transform .3s ease, opacity .3s ease !important;
      z-index: 99999 !important;
      font-family: Inter, sans-serif !important;
      pointer-events: all !important;
    }
    .login-toast.show {
      transform: translateX(0) !important;
      opacity: 1 !important;
    }
    /* success */
    .login-toast.success { border-left-color: #16a34a !important; background: #f0fdf4 !important; }
    .login-toast.success .lt-icon { color: #16a34a !important; }
    /* error */
    .login-toast.error   { border-left-color: #dc2626 !important; background: #fef2f2 !important; }
    .login-toast.error   .lt-icon { color: #dc2626 !important; }

    .lt-icon { font-size: 1.05rem !important; margin-top: 1px !important; flex-shrink: 0 !important; }
    .lt-body { flex: 1 !important; }
    .lt-title {
      font-size: .82rem !important;
      font-weight: 700 !important;
      color: #111 !important;
      line-height: 1.2 !important;
      margin-bottom: .18rem !important;
    }
    .lt-msg {
      font-size: .75rem !important;
      font-weight: 400 !important;
      color: #444 !important;
      line-height: 1.4 !important;
    }
    .lt-close {
      background: none !important;
      border: none !important;
      cursor: pointer !important;
      color: #888 !important;
      font-size: .85rem !important;
      padding: 0 !important;
      line-height: 1 !important;
      flex-shrink: 0 !important;
      margin-top: 1px !important;
    }
    .lt-close:hover { color: #333 !important; }
  </style>
</head>
<body>

  <!-- LOGIN SECTION -->
  <section class="auth-section">
    <div class="auth-card login-card">

      <a href="index.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>

      <h2 class="auth-title">Welcome back</h2>
      <p class="auth-subtitle">Sign in to your student account to continue.</p>

      <form id="loginForm" class="auth-form" novalidate>

        <div class="form-group">
          <label for="loginId">ID Number</label>
          <input
            type="text"
            id="loginId"
            name="loginId"
            placeholder="e.g. 123456789"
            required
            autocomplete="username"
          />
          <span class="form-error" id="loginIdError"></span>
        </div>

        <div class="form-group">
          <label for="loginPassword">Password</label>
          <div class="input-icon-wrap">
            <input
              type="password"
              id="loginPassword"
              name="loginPassword"
              placeholder="Your password"
              required
              autocomplete="current-password"
            />
            <button type="button" class="toggle-pw" data-target="loginPassword" tabindex="-1">
              <i class="fa-regular fa-eye"></i>
            </button>
          </div>
          <span class="form-error" id="loginPasswordError"></span>
        </div>

        <a href="#" class="forgot-link">Forgot password?</a>

        <button type="submit" id="loginBtn" class="btn btn-primary btn-full" style="margin-top:.2rem">
          <i class="fa-solid fa-right-to-bracket"></i> Sign In
        </button>

        <div class="auth-divider">or</div>

        <p class="auth-switch">
          Don't have an account? <a href="register.php">Register here</a>
        </p>

      </form>

    </div>
  </section>

  <script>
  (function () {
    /* ── password toggle ── */
    document.querySelectorAll('.toggle-pw').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.target);
        if (!input) return;
        var hiding = input.type === 'password';
        input.type = hiding ? 'text' : 'password';
        var icon = btn.querySelector('i');
        if (icon) {
          icon.classList.toggle('fa-eye',       !hiding);
          icon.classList.toggle('fa-eye-slash',  hiding);
        }
      });
    });

    /* ── toast — reference style with title, message, dismiss ── */
    function toast(msg, type) {
      document.querySelector('.login-toast')?.remove();

      var titles = { success: 'Success', error: 'Error', info: 'Info' };
      var icons  = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' };
      var t = type || 'success';

      var el = document.createElement('div');
      el.className = 'login-toast ' + t;
      el.innerHTML =
        '<i class="fa-solid ' + (icons[t] || icons.info) + ' lt-icon"></i>' +
        '<div class="lt-body">' +
          '<div class="lt-title">' + (titles[t] || 'Notice') + '</div>' +
          '<div class="lt-msg">' + msg + '</div>' +
        '</div>' +
        '<button class="lt-close" aria-label="Dismiss"><i class="fa-solid fa-xmark"></i></button>';

      document.body.appendChild(el);

      el.querySelector('.lt-close').addEventListener('click', function () {
        el.classList.remove('show');
        setTimeout(function () { el.remove(); }, 300);
      });

      requestAnimationFrame(function () {
        requestAnimationFrame(function () { el.classList.add('show'); });
      });

      setTimeout(function () {
        el.classList.remove('show');
        setTimeout(function () { el.remove(); }, 300);
      }, 3500);
    }

    /* ── submit ── */
    document.getElementById('loginForm').addEventListener('submit', async function (e) {
      e.preventDefault();

      var idVal = (document.getElementById('loginId').value || '').trim();
      var pwVal =  document.getElementById('loginPassword').value || '';
      var idErr = document.getElementById('loginIdError');
      var pwErr = document.getElementById('loginPasswordError');

      idErr.textContent = '';
      pwErr.textContent = '';

      var ok = true;
      if (!idVal) { idErr.textContent = 'This field is required.'; ok = false; }
      if (!pwVal) { pwErr.textContent = 'This field is required.'; ok = false; }
      if (!ok) return;

      var btn = document.getElementById('loginBtn');
      btn.disabled  = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Signing in\u2026';

      try {
        var fd = new FormData();
        fd.append('loginId',       idVal);
        fd.append('loginPassword', pwVal);
        fd.append('loginRole',     idVal.startsWith('ADM-') ? 'admin' : 'student');

        var res  = await fetch('login_process.php', { method: 'POST', body: fd });
        var text = await res.text();
        var data;

        try { data = JSON.parse(text); }
        catch (_) {
          toast('Unexpected server response. Check PHP logs.', 'error');
          console.error('login_process.php returned:', text);
          btn.disabled  = false;
          btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Sign In';
          return;
        }

        if (data.success) {
          toast('Login successful! Redirecting\u2026', 'success');
          setTimeout(function () {
            window.location.href = data.role === 'admin' ? 'admin.php' : 'dashboard.php';
          }, 1200);
        } else {
          toast(data.message || 'Invalid credentials.', 'error');
          btn.disabled  = false;
          btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Sign In';
          document.getElementById('loginPassword').value = '';
          document.getElementById('loginPassword').focus();
        }

      } catch (err) {
        toast('Server error. Make sure XAMPP is running.', 'error');
        console.error('Login error:', err);
        btn.disabled  = false;
        btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Sign In';
      }
    });
  })();
  </script>

</body>
</html>