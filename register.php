<?php session_start(); ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Register</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
</head>
<body>

  <!-- REGISTER SECTION -->
  <section class="auth-section">
    <div class="auth-card register-card">

      <a href="index.php" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back
      </a>

      <div class="auth-layout">
        <div class="auth-form-side">

          <h2 class="auth-title">Create Account</h2>
          <p class="auth-subtitle">Join the CCS Sit-in Monitoring System to get started.</p>

          <form id="registerForm" class="auth-form" novalidate onsubmit="return false;">

            <!-- Row 1: ID Number -->
            <div class="form-group">
              <label for="idNumber">ID Number</label>
              <input type="text" id="idNumber" name="idNumber" placeholder="e.g. 12345678" required/>
              <span class="form-error" id="idNumberError"></span>
            </div>

            <!-- Row 2: Last / First / Middle -->
            <div class="form-row three-col">
              <div class="form-group">
                <label for="lastName">Last Name</label>
                <input type="text" id="lastName" name="lastName" placeholder="Last name" required/>
                <span class="form-error" id="lastNameError"></span>
              </div>
              <div class="form-group">
                <label for="firstName">First Name</label>
                <input type="text" id="firstName" name="firstName" placeholder="First name" required/>
                <span class="form-error" id="firstNameError"></span>
              </div>
              <div class="form-group">
                <label for="middleName">Middle Name</label>
                <input type="text" id="middleName" name="middleName" placeholder="Middle name"/>
              </div>
            </div>

            <!-- Row 3: Course + Year Level -->
            <div class="form-row two-col">
              <div class="form-group">
                <label for="course">Course</label>
                <select id="course" name="course" required>
                  <option value="BSIT">Bachelor of Science in Information Technology (BSIT)</option>
                  <option value="BSCS">Bachelor of Science in Computer Science (BSCS)</option>
                  <option value="BSCE">Bachelor of Science in Civil Engineering (BSCE)</option>
                  <option value="BSME">Bachelor of Science in Mechanical Engineering (BSME)</option>
                  <option value="BSEE">Bachelor of Science in Electrical Engineering (BSEE)</option>
                  <option value="BSECE">Bachelor of Science in Electronics Engineering (BSECE)</option>
                  <option value="BSIE">Bachelor of Science in Industrial Engineering (BSIE)</option>
                  <option value="BEEd">Bachelor of Elementary Education (BEEd)</option>
                  <option value="BSEd">Bachelor of Secondary Education (BSEd)</option>
                  <option value="BSCrim">Bachelor of Science in Criminology (BSCrim)</option>
                  <option value="BSA">Bachelor of Science in Accountancy (BSA)</option>
                  <option value="BSBA">Bachelor of Science in Business Administration (BSBA)</option>
                  <option value="BSHRM">Bachelor of Science in Hotel and Restaurant Management (BSHRM)</option>
                  <option value="BSCA">Bachelor of Science in Customs Administration (BSCA)</option>
                  <option value="BSOA">Bachelor of Science in Office Administration (BSOA)</option>
                  <option value="BSSW">Bachelor of Science in Social Work (BSSW)</option>
                  <option value="AB Political Science">Bachelor of Arts in Political Science (AB Political Science)</option>
                </select>
                <span class="form-error" id="courseError"></span>
              </div>
              <div class="form-group">
                <label for="courseLevel">Year Level</label>
                <input type="number" id="courseLevel" name="courseLevel" placeholder="1–4" min="1" max="4" value="1" required/>
                <span class="form-error" id="courseLevelError"></span>
              </div>
            </div>

            <!-- Row 4: Email -->
            <div class="form-group">
              <label for="email">Email</label>
              <input type="email" id="email" name="email" placeholder="you@email.com" required/>
              <span class="form-error" id="emailError"></span>
            </div>

            <!-- Row 5: Address -->
            <div class="form-group">
              <label for="address">Address</label>
              <input type="text" id="address" name="address" placeholder="Street, Barangay, City"/>
            </div>

            <!-- Row 6: Password + Repeat Password -->
            <div class="form-row two-col">
              <div class="form-group">
                <label for="password">Password</label>
                <div class="input-icon-wrap">
                  <input type="password" id="password" name="password" placeholder="Min. 8 characters" required/>
                  <button type="button" class="toggle-pw" data-target="password" tabindex="-1">
                    <i class="fa-regular fa-eye"></i>
                  </button>
                </div>
                <span class="form-error" id="passwordError"></span>
              </div>
              <div class="form-group">
                <label for="repeatPassword">Repeat Password</label>
                <div class="input-icon-wrap">
                  <input type="password" id="repeatPassword" name="repeatPassword" placeholder="Repeat password" required/>
                  <button type="button" class="toggle-pw" data-target="repeatPassword" tabindex="-1">
                    <i class="fa-regular fa-eye"></i>
                  </button>
                </div>
                <span class="form-error" id="repeatPasswordError"></span>
              </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full" style="margin-top:.3rem">
              <i class="fa-solid fa-user-plus"></i> Create Account
            </button>

            <p class="auth-switch">
              Already have an account? <a href="login.php">Sign in here</a>
            </p>

          </form>
        </div>
      </div>

    </div>
  </section>

  <!-- SUCCESS OVERLAY -->
  <div class="reg-success-overlay" id="regSuccessOverlay">
    <div class="reg-success-card" id="regSuccessCard">
      <div class="reg-success-icon">
        <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
          <circle cx="30" cy="30" r="30" fill="url(#sg)"/>
          <path class="check-path" d="M17 30.5 L25.5 39 L43 21" stroke="white" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
          <defs>
            <linearGradient id="sg" x1="0" y1="0" x2="60" y2="60" gradientUnits="userSpaceOnUse">
              <stop stop-color="#6c3fcf"/>
              <stop offset="1" stop-color="#a259f7"/>
            </linearGradient>
          </defs>
        </svg>
      </div>
      <h2 class="reg-success-title">You're all set!</h2>
      <p class="reg-success-name" id="regSuccessName"></p>
      <p class="reg-success-msg">Your account has been created successfully. Please sign in with your credentials to continue.</p>
      <div class="reg-success-id-wrap">
        <span class="reg-success-id-label">Your ID Number</span>
        <span class="reg-success-id" id="regSuccessId"></span>
      </div>
      <div class="reg-success-progress">
        <div class="reg-success-bar" id="regSuccessBar"></div>
      </div>
      <a href="login.php" class="btn btn-primary reg-success-btn">
        <i class="fa-solid fa-right-to-bracket"></i> Go to Login
      </a>
    </div>
  </div>

  <script src="script.js"></script>
</body>
</html>