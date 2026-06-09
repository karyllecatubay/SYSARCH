<?php session_start(); ?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Sit-in Monitoring System</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
</head>
<body>

  <!-- NAVBAR -->
  <nav class="navbar">
    <div class="navbar-brand">
      <img src="images/ccslogo.png" alt="Logo" class="nav-logo">
      <span>Sit-in Monitoring System <br/><span style="font-size:.75rem;font-weight:600;opacity:.55">University of Cebu — College of Computer Studies</span></span>
    </div>

    <ul class="navbar-center" id="navCenter">
      <li><a href="index.php" class="nav-link active">Home</a></li>
     <li class="dropdown">
          <a href="#" class="nav-link dropdown-toggle" id="communityToggle">
            Community <i class="fa-solid fa-caret-down" style="font-size:.72rem"></i>
          </a>
          <ul class="dropdown-menu" id="communityMenu">
            <li><a href="#">Announcements</a></li>
            <li><a href="#">Forum</a></li>
            <li><a href="#">Resources</a></li>
          </ul>
        </li>
      <li><a href="#about" class="nav-link">About</a></li>
      <li><a href="#leaderboard" class="nav-link">Leaderboard</a></li>
      <li><a href="login.php" class="nav-link">Login</a></li>
    </ul>

    <div class="navbar-right">
      <a href="register.php" class="btn-nav-cta">Register</a>
    </div>

    <button class="hamburger" id="hamburger" aria-label="Toggle menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </nav>

  <!-- PAGE BODY -->
  <div class="page-body">

    <!-- HERO BANNER -->
    <div class="hero-banner">

      <!-- LEFT: title, description, CTA, socials -->
      <div class="hero-left">
        <div class="hero-top">
          <h1 class="hero-title">
            Sit-in Monitoring System
          </h1>
          <p class="hero-desc">
            College of Computer Studies
          </p>
          <p class="hero-desc-small">
            Log sessions, track computer lab availability, manage sit-ins, and monitor remaining sessions — all in one platform built for the College of Computer Studies.
          </p>
        </div>

        <div class="hero-bottom">
          <a href="register.php" class="btn-hero-outline">
            Get Started
              <i class="fa-solid fa-arrow-right" style="margin-left:2px;font-size:.6rem"></i>
          </a>
          <div class="hero-social">
            <a href="#" class="social-icon"><i class="fa-brands fa-facebook-f"></i></a>
            <a href="#" class="social-icon"><i class="fa-brands fa-twitter"></i></a>
            <a href="#" class="social-icon"><i class="fa-brands fa-instagram"></i></a>
          </div>
        </div>
      </div>

      <!-- RIGHT: tilted dashboard screens -->
      <div class="hero-right">
        <div class="screens-wrap">

          <!-- Back screen (tilted right) -->
          <div class="screen-back">
            <div class="back-item">
              <div class="back-icon"><i class="fa-solid fa-users"></i></div>
              <div>
                <div class="back-info-title">Students Active</div>
                <div class="back-info-sub">32 currently in labs</div>
              </div>
            </div>
            <div class="back-item">
              <div class="back-icon"><i class="fa-solid fa-desktop"></i></div>
              <div>
                <div class="back-info-title">Computers Free</div>
                <div class="back-info-sub">16 available now</div>
              </div>
            </div>
            <div class="back-item">
              <div class="back-icon"><i class="fa-solid fa-bell"></i></div>
              <div>
                <div class="back-info-title">New Announcement</div>
                <div class="back-info-sub">Lab 3 closing at 5pm</div>
              </div>
            </div>
          </div>

          

          <!-- Front screen (tilted left) -->
          <div class="screen-front">
            <div class="screen-topbar">
              <div class="sdots">
                <span class="sdot r"></span>
                <span class="sdot y"></span>
                <span class="sdot g"></span>
              </div>
              <span class="screen-title-sm">
                <img src="images/ccslogo.png"  alt="Logo" class="nav-logo">
                <img src="images/uclogo(2).png"  alt="Logo" class="nav-logo">
                </span>
              <span class="screen-title-sm">Dashboard</span>
              <span class="screen-live">LIVE</span>
            </div>
                <span class="screen-title-sm"><h2>Welcome Student!</h2></span>
                <br>
            <div class="s-stat-row">
              <div class="s-stat">
                <div class="s-stat-n">32</div>
                <div class="s-stat-l">Active</div>
              </div>
              <div class="s-stat">
                <div class="s-stat-n">16</div>
                <div class="s-stat-l">Free</div>
              </div>
            </div>
            <div class="s-bars">
              <div class="s-bar-row">
                <span class="s-bar-lbl">BSIT</span>
                <div class="s-bar-track">
                  <div class="s-bar-fill" style="width:78%;background:linear-gradient(90deg,#6c3fcf,#f5c518)"></div>
                </div>
                <span class="s-bar-val">78%</span>
              </div>
              <div class="s-bar-row">
                <span class="s-bar-lbl">BSCS</span>
                <div class="s-bar-track">
                  <div class="s-bar-fill" style="width:55%;background:linear-gradient(90deg,#a259f7,#c084fc)"></div>
                </div>
                <span class="s-bar-val">55%</span>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
    <!-- /hero-banner -->

    <!-- WHITE CONTENT SECTION -->
    <div class="section-white" id="about">
      <div class="section-top-row">
        <div>
          <div class="section-eyebrow">✦ Platform Features</div>
          <h2 class="section-title">Everything You<br/><span>Need</span></h2>
          <p class="section-sub">A complete solution for students and administrators of the College of Computer Studies.</p>
        </div>
        <a href="register.php" class="btn-section-cta">
          <i class="fa-solid fa-rocket"></i> Get Started
        </a>
      </div>

      <div class="features-grid">
        <div class="feature-card">
          <div class="feature-icon"><i class="fa-solid fa-calendar-check" style="color:#6c3fcf"></i></div>
          <h3>Session Tracking</h3>
          <p>Monitor your sit-in sessions in real time and track remaining session counts.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><i class="fa-solid fa-bell" style="color:#a259f7"></i></div>
          <h3>Announcements</h3>
          <p>Stay updated with lab schedules, closures, and important CCS notices instantly.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><i class="fa-solid fa-chart-bar" style="color:#f5c518"></i></div>
          <h3>Usage Reports</h3>
          <p>Admins access detailed lab usage stats, peak hours, and per-course breakdowns.</p>
        </div>
        <div class="feature-card">
          <div class="feature-icon"><i class="fa-solid fa-shield-halved" style="color:#6c3fcf"></i></div>
          <h3>Secure Access</h3>
          <p>Protected student accounts with authenticated logins. Your data stays private.</p>
        </div>
      </div>

      <!-- Stats -->
      <div class="stats-strip">
        <div class="stat-item">
          <div class="stat-number" data-target="1200">0</div>
          <div class="stat-label">Students Enrolled</div>
        </div>
        <div class="stat-item">
          <div class="stat-number" data-target="48">0</div>
          <div class="stat-label">Lab Computers</div>
        </div>
        <div class="stat-item">
          <div class="stat-number" data-target="320">0</div>
          <div class="stat-label">Sessions This Month</div>
        </div>
        <div class="stat-item">
          <div class="stat-number" data-target="99">0<span class="stat-suffix">%</span></div>
          <div class="stat-label">Uptime</div>
        </div>
      </div>
    </div>

    <!-- LEADERBOARD SECTION -->
    <div class="section-leaderboard" id="leaderboard">
      <div class="lb-header-row">
        <div>
          <div class="section-eyebrow">✦ Top Performers</div>
          <h2 class="section-title" style="color:#fff">Student<br/><span style="color:#f5c518">Leaderboard</span></h2>
          <p class="section-sub" style="color:rgba(255,255,255,.65)">Ranked by sit-in activity, hours logged, and tasks completed.</p>
        </div>
        <div class="lb-formula-card">
          <div class="lb-formula-title"><i class="fa-solid fa-calculator"></i> Scoring Formula</div>
          <div class="lb-formula-row"><span class="lb-formula-label">Every 3 Sit-ins</span><span class="lb-formula-val">= 1 pt × 50%</span></div>
          <div class="lb-formula-row"><span class="lb-formula-label">Total Hours</span><span class="lb-formula-val">× 30%</span></div>
          <div class="lb-formula-row"><span class="lb-formula-label">Tasks Done</span><span class="lb-formula-val">× 20%</span></div>
        </div>
      </div>

      <div id="leaderboardContainer" class="lb-grid">
        <div class="lb-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading leaderboard…</div>
      </div>
    </div>

    <!-- FOOTER -->
    <footer class="footer">
      <div class="footer-brand">
        <img src="images/uclogo.png" alt="Logo" class="nav-logo">
        CCS Sit-in Monitoring System
      </div>
      <div class="footer-copy">© 2026 University of Cebu — College of Computer Studies</div>
      <div class="footer-links">
        <a href="#">Privacy</a>
        <a href="#">Terms</a>
        <a href="#">Contact

            </a>
      </div>
    </footer>

  </div><!-- /page-body -->

  <style>html{scroll-behavior:smooth}</style>
  <script src="script.js"></script>
  <style>
  /* ── LEADERBOARD ── */
  .section-leaderboard{background:linear-gradient(135deg,#2d1060 0%,#4c1d95 45%,#6c3fcf 100%);padding:4rem 2rem 3.5rem;position:relative;overflow:hidden}
  .section-leaderboard::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");pointer-events:none}
  .lb-header-row{display:flex;align-items:flex-start;justify-content:space-between;gap:2rem;max-width:1100px;margin:0 auto 2.5rem;flex-wrap:wrap}
  .lb-formula-card{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:14px;padding:1.1rem 1.4rem;backdrop-filter:blur(8px);min-width:220px}
  .lb-formula-title{font-size:.78rem;font-weight:700;color:#f5c518;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.65rem;display:flex;align-items:center;gap:.4rem}
  .lb-formula-row{display:flex;justify-content:space-between;gap:1rem;font-size:.8rem;padding:.25rem 0;border-bottom:1px solid rgba(255,255,255,.07)}
  .lb-formula-row:last-child{border-bottom:none}
  .lb-formula-label{color:rgba(255,255,255,.7)}
  .lb-formula-val{color:#f5c518;font-weight:700}
  .lb-grid{max-width:1100px;margin:0 auto;display:flex;flex-direction:column;gap:.65rem}
  .lb-loading{text-align:center;color:rgba(255,255,255,.6);padding:3rem;font-size:.9rem}
  .lb-empty{text-align:center;color:rgba(255,255,255,.5);padding:3rem;font-size:.88rem}
  .lb-row{display:grid;grid-template-columns:52px 1fr auto auto auto;align-items:center;gap:1rem;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:.85rem 1.2rem;transition:background .2s,transform .18s;backdrop-filter:blur(4px)}
  .lb-row:hover{background:rgba(255,255,255,.12);transform:translateX(4px)}
  .lb-row.top-1{background:linear-gradient(135deg,rgba(245,197,24,.18),rgba(245,197,24,.08));border-color:rgba(245,197,24,.35)}
  .lb-row.top-2{background:linear-gradient(135deg,rgba(229,231,235,.12),rgba(229,231,235,.05));border-color:rgba(229,231,235,.25)}
  .lb-row.top-3{background:linear-gradient(135deg,rgba(180,83,9,.15),rgba(180,83,9,.06));border-color:rgba(180,83,9,.25)}
  .lb-rank{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-weight:800;font-size:.95rem;flex-shrink:0}
  .lb-rank-1{background:linear-gradient(135deg,#f5c518,#fbbf24);color:#1a1a00;box-shadow:0 4px 12px rgba(245,197,24,.4)}
  .lb-rank-2{background:linear-gradient(135deg,#9ca3af,#d1d5db);color:#1f2937}
  .lb-rank-3{background:linear-gradient(135deg,#b45309,#d97706);color:#fff}
  .lb-rank-other{background:rgba(255,255,255,.1);color:rgba(255,255,255,.6);font-size:.85rem}
  .lb-name{font-weight:700;font-size:.92rem;color:#fff;line-height:1.2}
  .lb-course{font-size:.72rem;color:rgba(255,255,255,.55);margin-top:.1rem}
  .lb-stat{display:flex;flex-direction:column;align-items:center;gap:.08rem;min-width:54px}
  .lb-stat-val{font-family:'Poppins',sans-serif;font-weight:800;font-size:1rem;color:#fff;line-height:1}
  .lb-stat-lbl{font-size:.62rem;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
  .lb-score{min-width:80px;text-align:right}
  .lb-score-val{font-family:'Poppins',sans-serif;font-size:1.25rem;font-weight:800;color:#f5c518;line-height:1}
  .lb-score-lbl{font-size:.62rem;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.05em}
  @media(max-width:640px){.lb-row{grid-template-columns:44px 1fr auto}.lb-stat:nth-child(4){display:none}.lb-header-row{flex-direction:column}}
  </style>
  <script>
  (async function loadLeaderboard() {
    const container = document.getElementById('leaderboardContainer');
    if (!container) return;
    try {
      const fd = new FormData(); fd.append('_action','get_leaderboard');
      const res  = await fetch('admin.php',{method:'POST',body:fd});
      const data = await res.json();
      if (!data.success || !data.leaderboard || data.leaderboard.length === 0) {
        container.innerHTML = '<div class="lb-empty"><i class="fa-solid fa-trophy" style="font-size:2.5rem;color:rgba(245,197,24,.3);display:block;margin-bottom:.75rem"></i>No leaderboard data yet. Complete sit-in sessions to appear here.</div>';
        return;
      }
      const rankCls = (r) => r===1?'top-1':r===2?'top-2':r===3?'top-3':'';
      const rankBadgeCls = (r) => r===1?'lb-rank-1':r===2?'lb-rank-2':r===3?'lb-rank-3':'lb-rank-other';
      const medalIcon = (r) => r===1?'🥇':r===2?'🥈':r===3?'🥉':r;
      container.innerHTML = data.leaderboard.map(s => `
        <div class="lb-row ${rankCls(s.rank)}">
          <div class="lb-rank ${rankBadgeCls(s.rank)}">${medalIcon(s.rank)}</div>
          <div>
            <div class="lb-name">${escH(s.display_name)}</div>
            <div class="lb-course">${escH(s.course)}</div>
          </div>
          <div class="lb-stat"><div class="lb-stat-val">${s.total_sitins}</div><div class="lb-stat-lbl">Sit-ins</div></div>
          <div class="lb-stat"><div class="lb-stat-val">${s.total_hours}h</div><div class="lb-stat-lbl">Hours</div></div>
          <div class="lb-score"><div class="lb-score-val">${s.final_score}</div><div class="lb-score-lbl">Score</div></div>
        </div>
      `).join('');
    } catch(e) {
      container.innerHTML = '<div class="lb-empty">Could not load leaderboard.</div>';
    }
    function escH(s){ return String(s||'').replace(/[&<>]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])); }
  })();
  </script>
</body>
</html>