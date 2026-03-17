<?php session_start(); ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Login</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
</head>
<body>

  <!-- LOGIN SECTION -->
  <section class="auth-section">
    <div class="auth-card login-card">

      <a href="index.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>

      <!-- Brand mark on login card -->

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

        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.2rem">
          <i class="fa-solid fa-right-to-bracket"></i> Sign In
        </button>

        <div class="auth-divider">or</div>

        <p class="auth-switch">
          Don't have an account? <a href="register.php">Register here</a>
        </p>

      </form>

    </div>
  </section>

  <script src="script.js"></script>
</body>
</html>