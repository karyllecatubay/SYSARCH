<?php
session_start();
require 'db.php';

/* ══════════════════════════════════════════════════
   AJAX: SEARCH STUDENT
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'search_student') {
    header('Content-Type: application/json');
    if (empty($_SESSION['admin_logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    $id = trim($_POST['id_number'] ?? '');
    if (!$id) { echo json_encode(['success' => false, 'message' => 'Please enter an ID number.']); exit; }
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id_number = ?");
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) { echo json_encode(['success' => false, 'message' => 'No student found with ID: ' . htmlspecialchars($id)]); exit; }
    $yr = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
    echo json_encode([
        'success'    => true,
        'id_number'  => $s['id_number'],
        'name'       => $s['last_name'] . ', ' . $s['first_name'] . (!empty($s['middle_name']) ? ' ' . $s['middle_name'] : ''),
        'first_name' => $s['first_name'],
        'course'     => $s['course'],
        'year'       => $yr[$s['year_level']] ?? ($s['year_level'] . ' Year'),
        'email'      => $s['email'],
        'address'    => $s['address'] ?? '—',
        'photo'      => !empty($s['profile_photo']) ? $s['profile_photo'] : null,
        'sessions'   => 30,
        'used'       => 0,
        'lastSitin'  => '—',
    ]);
    exit;
}

/* ══════════════════════════════════════════════════
   AJAX: ADMIN LOGIN
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'admin_login') {
    header('Content-Type: application/json');
    $user = trim($_POST['adminUser'] ?? '');
    $pass =      $_POST['adminPass'] ?? '';
    if ($user === 'admin' && $pass === 'admin123') {
        session_regenerate_id(true);
        unset($_SESSION['student_id']);
        $_SESSION['role']            = 'admin';
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_name']      = 'Administrator';
        echo json_encode(['success' => true, 'message' => 'Welcome, Admin!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid admin credentials.']);
    }
    exit;
}

/* ══════════════════════════════════════════════════
   AJAX: LOGOUT
   ══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'logout') {
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

/* ══════════════════════════════════════════════════
   GUARD — not admin → show login page
   ══════════════════════════════════════════════════ */
if (empty($_SESSION['admin_logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') { ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Admin Login</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
  <style>
    :root {
      --purple-dark:#4c1d95;--purple-mid:#6c3fcf;--purple-light:#a259f7;
      --text:#1a1a2e;--muted:#6b7280;--white:#ffffff;
      --ff:'Poppins',sans-serif;--fb:'DM Sans',sans-serif;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:var(--fb);background:#f4f2fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem}
    .auth-section{width:100%;display:flex;align-items:center;justify-content:center}
    .auth-card{background:var(--white);border-radius:22px;padding:2.4rem 2rem;width:100%;max-width:400px;border:1px solid #ece9f8;box-shadow:0 16px 56px rgba(108,63,207,.13);position:relative}
    .btn-back{display:inline-flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:600;color:var(--muted);text-decoration:none;margin-bottom:1.5rem;padding:.3rem .6rem;border-radius:8px;transition:all .15s}
    .btn-back:hover{background:#f3f0ff;color:var(--purple-mid)}
    .admin-icon{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#dc2626,var(--purple-mid));color:#fff;font-size:1.3rem;display:flex;align-items:center;justify-content:center;margin:0 auto 1.1rem;box-shadow:0 6px 20px rgba(108,63,207,.35)}
    .auth-title{font-family:var(--ff);font-size:1.4rem;font-weight:800;color:var(--text);text-align:center;margin-bottom:.3rem}
    .auth-subtitle{font-size:.82rem;color:var(--muted);text-align:center;margin-bottom:1.5rem}
    .admin-badge{display:flex;align-items:center;justify-content:center;gap:.4rem;background:#fef3c7;color:#92400e;font-size:.72rem;font-weight:700;padding:.3rem .85rem;border-radius:20px;width:fit-content;margin:0 auto 1.5rem}
    .form-group{margin-bottom:1rem}
    .form-group label{display:block;font-size:.8rem;font-weight:600;color:#374151;margin-bottom:.38rem}
    .form-group input{width:100%;padding:.7rem 1rem;border:1.5px solid #e5e7eb;border-radius:11px;font-size:.9rem;font-family:var(--fb);outline:none;transition:border-color .18s,box-shadow .18s;color:var(--text);background:#fafafa}
    .form-group input:focus{border-color:var(--purple-mid);background:#fff;box-shadow:0 0 0 3px rgba(108,63,207,.1)}
    .input-icon-wrap{position:relative}
    .input-icon-wrap input{padding-right:2.8rem}
    .toggle-pw{position:absolute;right:.85rem;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:.85rem;padding:0;transition:color .15s}
    .toggle-pw:hover{color:var(--purple-mid)}
    .form-error{font-size:.73rem;color:#ef4444;margin-top:.28rem;display:block;min-height:1rem}
    .err-box{background:#fef2f2;color:#ef4444;border:1px solid #fecaca;border-radius:10px;padding:.65rem .9rem;font-size:.82rem;margin-bottom:1rem;display:none}
    .err-box.show{display:block}
    .btn-login{width:100%;padding:.78rem;border-radius:11px;border:none;cursor:pointer;font-family:var(--ff);font-size:.9rem;font-weight:700;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;box-shadow:0 4px 16px rgba(108,63,207,.35);margin-top:.4rem;display:flex;align-items:center;justify-content:center;gap:.5rem;transition:all .2s}
    .btn-login:hover{transform:translateY(-2px);box-shadow:0 7px 24px rgba(108,63,207,.45)}
    .btn-login:disabled{opacity:.65;cursor:not-allowed;transform:none}
    .auth-switch{text-align:center;font-size:.82rem;color:var(--muted);margin-top:1.2rem}
    .auth-switch a{color:var(--purple-mid);font-weight:600;text-decoration:none}
    .auth-switch a:hover{text-decoration:underline}
    .toast{position:fixed;bottom:1.5rem;left:50%;tranform:translateX(-50%) translateY(20px);background:#1a1a2e;color:#fff;padding:.7rem 1.3rem;border-radius:10px;font-size:.84rem;font-weight:600;font-family:var(--fb);display:flex;align-items:center;gap:.5rem;opacity:0;transition:all .3s;z-index:9999;white-space:nowrap}
    .toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
  </style>
</head>
<body>
  <section class="auth-section">
    <div class="auth-card">
      <a href="index.php" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back</a>
      
      <h1 class="auth-title">Admin Login</h1>
      <p class="auth-subtitle">CCS Sit-in Monitoring System</p>
      <div class="err-box" id="errBox"></div>
      <div class="form-group">
        <label for="adminUser"><i class="fa-solid fa-user" style="color:var(--purple-mid)"></i> Username</label>
        <input type="text" id="adminUser" placeholder="Enter admin username" autocomplete="username"/>
        <span class="form-error" id="adminUserErr"></span>
      </div>
      <div class="form-group">
        <label for="adminPass"><i class="fa-solid fa-lock" style="color:var(--purple-mid)"></i> Password</label>
        <div class="input-icon-wrap">
          <input type="password" id="adminPass" placeholder="Enter admin password" autocomplete="current-password"/>
          <button type="button" class="toggle-pw" data-target="adminPass" tabindex="-1"><i class="fa-regular fa-eye"></i></button>
        </div>
        <span class="form-error" id="adminPassErr"></span>
      </div>
      <button class="btn-login" id="loginBtn"><i class="fa-solid fa-right-to-bracket"></i> Sign In</button>
      <p class="auth-switch">Not an admin? <a href="login.php">Student login</a></p>
    </div>
  </section>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toggle-pw').forEach(btn => {
      btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        if (!input) return;
        const hide = input.type === 'password';
        input.type = hide ? 'text' : 'password';
        const icon = btn.querySelector('i');
        if (icon) { icon.classList.toggle('fa-eye', !hide); icon.classList.toggle('fa-eye-slash', hide); }
      });
    });
    const btn    = document.getElementById('loginBtn');
    const errBox = document.getElementById('errBox');
    async function doLogin() {
      const u    = document.getElementById('adminUser').value.trim();
      const p    = document.getElementById('adminPass').value;
      const uErr = document.getElementById('adminUserErr');
      const pErr = document.getElementById('adminPassErr');
      uErr.textContent = ''; pErr.textContent = ''; errBox.classList.remove('show');
      let ok = true;
      if (!u) { uErr.textContent = 'Username is required.'; ok = false; }
      if (!p) { pErr.textContent = 'Password is required.'; ok = false; }
      if (!ok) return;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Signing in…';
      const fd = new FormData();
      fd.append('_action','admin_login'); fd.append('adminUser',u); fd.append('adminPass',p);
      try {
        const res  = await fetch('admin.php', { method:'POST', body:fd });
        const data = await res.json();
        if (data.success) { showToast('Welcome, Admin!'); setTimeout(() => location.reload(), 1400); }
        else { errBox.textContent = data.message; errBox.classList.add('show'); }
      } catch (e) { errBox.textContent = 'Server error. Make sure XAMPP is running.'; errBox.classList.add('show'); }
      finally { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> Sign In as Admin'; }
    }
    btn.addEventListener('click', doLogin);
    document.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
    function showToast(m) {
      const t = document.createElement('div');
      t.className = 'toast';
      t.innerHTML = '<i class="fa-solid fa-circle-check" style="color:#4ade80"></i> ' + m;
      document.body.appendChild(t);
      requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 2500);
    }
  });
  </script>
</body>
</html>
<?php
    exit;
}

/* ══════════════════════════════════════════════════
   ADMIN IS LOGGED IN — fetch stats
   ══════════════════════════════════════════════════ */
$total      = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$bsit       = $pdo->query("SELECT COUNT(*) FROM students WHERE course='BSIT'")->fetchColumn();
$bscs       = $pdo->query("SELECT COUNT(*) FROM students WHERE course='BSCS'")->fetchColumn();
$admin_name = $_SESSION['admin_name'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>CCS | Admin Dashboard</title>
  <link rel="stylesheet" href="style.css"/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
  <style>
/* =====================================================
   Admin Dashboard — mirrors dashboard.php sidebar UI
   ===================================================== */
:root {
  --purple-dark:  #4c1d95;
  --purple-mid:   #6c3fcf;
  --purple-light: #a259f7;
  --yellow:       #f5c518;
  --red:          #ef4444;
  --green:        #22c55e;
  --text:         #1a1a2e;
  --muted:        #6b7280;
  --border:       #e5e7eb;
  --white:        #ffffff;
  --ff: 'Poppins', sans-serif;
  --fb: 'DM Sans', sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

/* ── LAYOUT ── */
.dash-body{display:flex;min-height:100vh;background:#f4f2fb;overflow-x:hidden}

/* ── SIDEBAR ── */
.sidebar{width:240px;min-width:240px;background:var(--white);border-right:1px solid #ece9f8;display:flex;flex-direction:column;height:100vh;position:sticky;top:0;z-index:300;box-shadow:2px 0 20px rgba(108,63,207,.06);transition:transform .28s cubic-bezier(.4,0,.2,1)}

.sb-brand{display:flex;align-items:center;gap:.7rem;padding:1.4rem 1.2rem 1rem;border-bottom:1px solid #ece9f8}
.sb-logo{width:38px;height:38px;object-fit:contain;flex-shrink:0}
.sb-brand-text{display:flex;flex-direction:column;gap:.05rem}
.sb-title{font-family:var(--ff);font-size:.9rem;font-weight:800;color:var(--text);line-height:1.2}
.sb-admin-badge{display:inline-flex;align-items:center;gap:.25rem;background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;font-size:.6rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:.15rem .45rem;border-radius:5px;margin-top:.1rem;width:fit-content}

.sb-nav{padding:.8rem .7rem 0;flex:1;overflow-y:auto}
.sb-nav ul{list-style:none;display:flex;flex-direction:column;gap:.15rem}

.sb-section-label{font-size:.65rem;font-weight:800;color:#9ca3af;letter-spacing:.1em;text-transform:uppercase;padding:.65rem .9rem .3rem}

.sb-link{display:flex;align-items:center;gap:.8rem;padding:.62rem .9rem;border-radius:10px;text-decoration:none;color:#4b5563;font-size:.875rem;font-weight:500;transition:all .2s ease;position:relative;cursor:pointer}
.sb-link:hover{background:#f3f0ff;color:var(--purple-mid)}
.sb-link.active{background:linear-gradient(135deg,#ede9fe,#f5f3ff);color:var(--purple-mid);font-weight:700;box-shadow:0 2px 8px rgba(108,63,207,.1)}
.sb-link.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--purple-mid);border-radius:0 3px 3px 0}

.sb-icon{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:7px;font-size:.85rem;flex-shrink:0;background:transparent;transition:background .2s}
.sb-link.active .sb-icon{background:rgba(108,63,207,.12)}
.sb-link:hover .sb-icon{background:rgba(108,63,207,.08)}
.sb-label{font-size:.875rem}

.sb-spacer{flex:1}

.sb-user-section{padding:.7rem .7rem 1rem;border-top:1px solid #ece9f8;position:relative}
.sb-user-btn{display:flex;align-items:center;gap:.7rem;padding:.55rem .65rem;border-radius:10px;cursor:pointer;transition:background .2s}
.sb-user-btn:hover{background:#fff4f4}

.sb-avatar{width:36px;height:36px;border-radius:10px;color:#fff;font-family:var(--ff);font-size:.95rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sb-avatar.admin-av{background:linear-gradient(135deg,#dc2626,#f97316);box-shadow:0 2px 8px rgba(220,38,38,.4)}

.sb-user-info{flex:1;min-width:0;display:flex;flex-direction:column}
.sb-user-name{font-size:.82rem;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-user-id{font-size:.7rem;color:var(--muted)}

.sb-chevron{font-size:.65rem;color:var(--muted);transition:transform .2s;flex-shrink:0}
.sb-chevron.open{transform:rotate(180deg)}

.sb-user-menu{position:absolute;bottom:calc(100% + .3rem);left:.7rem;right:.7rem;background:var(--white);border:1px solid #ece9f8;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.12);overflow:hidden;display:none;z-index:400;animation:fadeUp .18s ease}
.sb-user-menu.open{display:block}

@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

.sb-menu-item{display:flex;align-items:center;gap:.6rem;padding:.7rem 1rem;font-size:.85rem;font-weight:500;color:#374151;text-decoration:none;transition:background .15s;cursor:pointer}
.sb-menu-item i{font-size:.85rem;width:16px;text-align:center;color:var(--purple-mid)}
.sb-menu-item:hover{background:#f9fafb}
.sb-menu-item.danger{color:#ef4444}
.sb-menu-item.danger i{color:#ef4444}
.sb-menu-item.danger:hover{background:#fef2f2}
.sb-menu-divider{height:1px;background:#f0ecff}

/* ── MOBILE TOPBAR ── */
.dash-topbar{display:none;align-items:center;justify-content:space-between;height:56px;padding:0 1rem;background:var(--white);border-bottom:1px solid #ece9f8;position:fixed;top:0;left:0;right:0;z-index:250;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.dash-topbar-brand{display:flex;align-items:center;gap:.5rem;font-family:var(--ff);font-size:.9rem;font-weight:800;color:var(--text)}
.sb-toggle{background:none;border:none;font-size:1.1rem;color:var(--text);cursor:pointer;padding:.4rem;border-radius:8px;transition:background .15s}
.sb-toggle:hover{background:#f3f0ff}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:290}

/* ── MAIN ── */
.dash-main{flex:1;min-width:0;padding:1.8rem 2rem;overflow-y:auto}
.dash-page{display:none}
.dash-page.active{display:block;animation:fadeUp .3s ease both}

/* ── PAGE HEADER ── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
.page-title{font-family:var(--ff);font-size:1.55rem;font-weight:800;color:var(--text);letter-spacing:-.025em;line-height:1.2}
.page-sub{color:var(--muted);font-size:.875rem;margin-top:.2rem}

/* ── WELCOME BANNER ── */
.welcome-banner{background:linear-gradient(135deg,#1e0a3c 0%,var(--purple-dark) 45%,var(--purple-mid) 100%);border-radius:18px;padding:1.8rem 2rem;display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;position:relative;overflow:hidden;box-shadow:0 8px 32px rgba(108,63,207,.35)}
.welcome-banner::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;background:rgba(255,255,255,.05);border-radius:50%;pointer-events:none}
.welcome-eyebrow{font-size:.82rem;color:rgba(255,255,255,.7);font-weight:600;margin-bottom:.3rem}
.welcome-title{font-family:var(--ff);font-size:1.55rem;font-weight:800;color:#fff;letter-spacing:-.025em;line-height:1.2}
.welcome-sub{font-size:.86rem;color:rgba(255,255,255,.65);margin-top:.4rem}
.welcome-badge{display:flex;flex-direction:column;align-items:flex-end;gap:.35rem;z-index:1}
.badge-admin{background:linear-gradient(135deg,#fef3c7,#fde68a);color:#92400e;font-family:var(--ff);font-size:.8rem;font-weight:800;padding:.3rem .85rem;border-radius:8px;letter-spacing:.04em}

/* ── STAT CARDS ── */
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1.5rem}
.stat-card{background:var(--white);border-radius:16px;padding:1.2rem 1.4rem;border:1px solid #ece9f8;display:flex;align-items:center;gap:.9rem;box-shadow:0 2px 12px rgba(108,63,207,.05);transition:transform .18s,box-shadow .18s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 24px rgba(108,63,207,.1)}
.stat-card.p{border-left:4px solid var(--purple-mid)}.stat-card.y{border-left:4px solid var(--yellow)}.stat-card.g{border-left:4px solid var(--green)}.stat-card.r{border-left:4px solid var(--red)}
.sc-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.stat-card.p .sc-icon{background:#ede9fe;color:var(--purple-mid)}.stat-card.y .sc-icon{background:#fef9c3;color:#a16207}.stat-card.g .sc-icon{background:#dcfce7;color:#16a34a}.stat-card.r .sc-icon{background:#fef2f2;color:#dc2626}
.sc-val{font-family:var(--ff);font-size:1.5rem;font-weight:800;line-height:1;color:var(--text)}.sc-lbl{font-size:.76rem;color:var(--muted);margin-top:.2rem}

/* ── CONTENT CARD ── */
.content-card{background:var(--white);border-radius:16px;border:1px solid #ece9f8;box-shadow:0 2px 12px rgba(108,63,207,.05);overflow:hidden;margin-bottom:1.4rem}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.4rem;border-bottom:1px solid #f0ecff}
.card-header h2{font-family:var(--ff);font-size:.92rem;font-weight:700;display:flex;align-items:center;gap:.45rem}
.card-header h2 i{color:var(--purple-mid)}

/* ── SEARCH SECTION ── */
.search-hero{text-align:center;padding:2.2rem 1rem 1.6rem}
.search-hero-icon{width:68px;height:68px;border-radius:20px;background:linear-gradient(135deg,#ede9fe,#f5f3ff);color:var(--purple-mid);font-size:1.8rem;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;box-shadow:0 6px 24px rgba(108,63,207,.18)}
.search-hero h2{font-family:var(--ff);font-size:1.25rem;font-weight:800;color:var(--text)}
.search-hero p{font-size:.84rem;color:var(--muted);margin-top:.35rem}

.search-bar-wrap{max-width:500px;margin:0 auto;display:flex;align-items:center;gap:.55rem;background:#f9f8ff;border:1.5px solid #ddd6fe;border-radius:14px;padding:.6rem .9rem;transition:all .2s}
.search-bar-wrap:focus-within{border-color:var(--purple-mid);box-shadow:0 0 0 3px rgba(108,63,207,.12)}
.search-bar-wrap i{color:#9ca3af;flex-shrink:0}
.search-bar-wrap input{flex:1;border:none;outline:none;font-size:.9rem;font-family:var(--fb);color:var(--text);background:transparent;font-weight:500;min-width:0}
.search-bar-wrap input::placeholder{color:#9ca3af;font-weight:400}
.btn-search{padding:.48rem 1.2rem;border-radius:10px;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;border:none;font-size:.83rem;font-weight:700;font-family:var(--fb);cursor:pointer;white-space:nowrap;transition:all .18s;box-shadow:0 3px 10px rgba(108,63,207,.3)}
.btn-search:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(108,63,207,.4)}
.btn-search:disabled{opacity:.6;cursor:not-allowed;transform:none}
.search-hint{text-align:center;font-size:.77rem;color:var(--muted);margin-top:.55rem}

.recent-searches{max-width:500px;margin:1.4rem auto 0}
.recent-title{font-size:.78rem;font-weight:700;color:var(--muted);margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem}
.recent-chips{display:flex;flex-wrap:wrap;gap:.45rem}
.recent-chip{background:var(--white);border:1.5px solid #ede9fe;border-radius:8px;padding:.3rem .75rem;font-size:.77rem;font-weight:600;color:var(--purple-mid);cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:.4rem}
.recent-chip:hover{background:#f3f0ff;border-color:var(--purple-mid)}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(15,10,40,.55);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:500;opacity:0;pointer-events:none;transition:opacity .22s;padding:1rem}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-card{background:var(--white);border-radius:20px;width:100%;max-width:500px;box-shadow:0 24px 64px rgba(0,0,0,.22);transform:translateY(14px);transition:transform .24s;overflow:hidden}
.modal-overlay.open .modal-card{transform:translateY(0)}

.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.4rem;border-bottom:1px solid #f0ecff}
.modal-header h3{font-family:var(--ff);font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:.45rem}
.modal-header h3 i{color:var(--purple-mid)}
.modal-close{width:30px;height:30px;border-radius:8px;border:none;background:#f3f0ff;color:var(--purple-mid);font-size:.85rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.modal-close:hover{background:#fef2f2;color:var(--red)}

.result-profile{display:flex;align-items:center;gap:1rem;padding:1.1rem 1.4rem;background:linear-gradient(135deg,#faf8ff,#f5f3ff);border-bottom:1px solid #ece9f8}
.rp-avatar{width:58px;height:58px;border-radius:13px;background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;font-family:var(--ff);font-size:1.3rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;box-shadow:0 4px 14px rgba(108,63,207,.3)}
.rp-avatar img{width:100%;height:100%;object-fit:cover}
.rp-info{flex:1;min-width:0}
.rp-name{font-family:var(--ff);font-size:.92rem;font-weight:800;color:var(--text)}
.rp-course{font-size:.79rem;color:var(--purple-mid);font-weight:600;margin-top:.1rem}
.rp-id{font-size:.74rem;color:var(--muted);margin-top:.1rem}
.rp-sessions{text-align:center;flex-shrink:0}
.rps-val{font-family:var(--ff);font-size:1.45rem;font-weight:800;color:var(--purple-mid);line-height:1}
.rps-lbl{font-size:.69rem;color:var(--muted);margin-top:.2rem;line-height:1.3}

.result-grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#f0ecff;border-bottom:1px solid #f0ecff}
.rg-item{background:var(--white);padding:.82rem 1.1rem}
.rg-item.full{grid-column:1/-1}
.rg-label{font-size:.69rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:.3rem;margin-bottom:.2rem}
.rg-label i{color:var(--purple-mid);font-size:.69rem}
.rg-value{font-size:.84rem;font-weight:600;color:var(--text)}

.modal-actions{display:flex;justify-content:flex-end;gap:.55rem;padding:.9rem 1.4rem}
.btn-sm{padding:.38rem .85rem;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:all .18s;display:inline-flex;align-items:center;gap:.35rem;font-family:var(--fb)}
.btn-sm.outline{background:var(--white);border:1.5px solid var(--border);color:#4b5563}
.btn-sm.outline:hover{border-color:var(--purple-mid);color:var(--purple-mid);background:#f3f0ff}
.btn-sm.primary{background:linear-gradient(135deg,var(--purple-mid),var(--purple-light));color:#fff;box-shadow:0 3px 10px rgba(108,63,207,.3)}
.btn-sm.primary:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(108,63,207,.4)}

/* ── TOAST ── */
.toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%) translateY(20px);background:#1a1a2e;color:#fff;padding:.7rem 1.3rem;border-radius:10px;font-size:.84rem;font-weight:600;font-family:var(--fb);display:flex;align-items:center;gap:.5rem;opacity:0;transition:all .3s;z-index:9999;white-space:nowrap}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.error{background:#ef4444}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .sidebar{position:fixed;left:0;top:0;bottom:0;transform:translateX(-100%)}
  .sidebar.open{transform:translateX(0)}
  .dash-topbar{display:flex}
  .dash-main{padding:4.5rem 1.2rem 1.2rem}
  .stats-row{grid-template-columns:1fr 1fr}
}
@media(max-width:540px){
  .stats-row{grid-template-columns:1fr}
  .result-grid{grid-template-columns:1fr}
  .rg-item.full{grid-column:auto}
}
  </style>
</head>
<body class="dash-body">

<!-- ════════ SIDEBAR ════════ -->
<aside class="sidebar" id="sidebar">

  <div class="sb-brand">
    <img src="images/ccslogo.png" alt="CCS Logo" class="sb-logo"/>
    <div class="sb-brand-text">
      <span class="sb-title">CCS Sit-in System</span>
      <span class="sb-admin-badge">Admin</span>
    </div>
  </div>

  <nav class="sb-nav">
    <ul>
      <li>
        <a href="#" class="sb-link active" data-page="home">
          <span class="sb-icon"><i class="fa-solid fa-house"></i></span>
          <span class="sb-label">Home</span>
        </a>
      </li>
      <li>
        <a href="#" class="sb-link" data-page="search">
          <span class="sb-icon"><i class="fa-solid fa-magnifying-glass"></i></span>
          <span class="sb-label">Search Student</span>
        </a>
      </li>
    </ul>
  </nav>

  <div class="sb-spacer"></div>

  <!-- Bottom user block — same pattern as dashboard.php -->
  <div class="sb-user-section">
    <div class="sb-user-btn" id="userMenuBtn">
      <div class="sb-avatar admin-av">A</div>
      <div class="sb-user-info">
        <span class="sb-user-name"><?= htmlspecialchars($admin_name) ?></span>
        
      </div>
      <i class="fa-solid fa-chevron-up sb-chevron" id="userChevron"></i>
    </div>
    <div class="sb-user-menu" id="userMenu">
      <div class="sb-menu-divider"></div>
      <a class="sb-menu-item danger" id="logoutBtn">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
      </a>
    </div>
  </div>

</aside>

<!-- ════════ MOBILE TOPBAR ════════ -->
<header class="dash-topbar" id="dashTopbar">
  <button class="sb-toggle" id="sbToggle" aria-label="Toggle sidebar">
    <i class="fa-solid fa-bars"></i>
  </button>
  <div class="dash-topbar-brand">
    <img src="images/ccslogo.png" alt="Logo" style="width:30px;height:30px;object-fit:contain"/>
    <span>CCS Admin</span>
  </div>
  <div class="sb-avatar admin-av" style="width:32px;height:32px;border-radius:8px;font-size:.82rem;flex-shrink:0">A</div>
</header>

<!-- ════════ MAIN CONTENT ════════ -->
<main class="dash-main">

  <!-- ═══ PAGE: HOME ═══ -->
  <div class="dash-page active" id="page-home">


    <!-- Welcome banner -->
    <div class="welcome-banner">
      <div>
        <div class="welcome-eyebrow">Admin Panel — CCS Sit-in Monitoring System</div>
        <div class="welcome-title">Welcome back, <?= htmlspecialchars($admin_name) ?> !</div>
        <div class="welcome-sub">You have full access to the admin dashboard.</div>
      </div>
      <div class="welcome-badge">
        <span class="badge-admin">Administrator</span>
      </div>
    </div>

  </div><!-- /page-home -->

  <!-- ═══ PAGE: SEARCH STUDENT ═══ -->
  <div class="dash-page" id="page-search">

    <div class="page-header">
      <div>
        <div class="page-title">Search Student</div>
        <div class="page-sub">Find any registered student by their ID number</div>
      </div>
    </div>

    <div class="content-card">
      <div class="card-header">
        <h2><i class="fa-solid fa-magnifying-glass"></i> Student Lookup</h2>
      </div>

      <div style="padding:1.4rem 1.4rem 1.8rem">
        <div class="search-hero">
          <div class="search-hero-icon"><i class="fa-solid fa-id-card"></i></div>
          <h2>Find a Student</h2>
          <p>Enter the student's 8-digit ID number to pull up their full profile.</p>
        </div>

        <div class="search-bar-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input
            type="text"
            id="searchInput"
            placeholder="e.g. 12345678"
            maxlength="8"
            inputmode="numeric"
            autocomplete="off"
          />
          <button class="btn-search" id="doSearchBtn">
            <i class="fa-solid fa-magnifying-glass"></i> Search
          </button>
        </div>


        <!-- Recent search chips — populated by JS -->
        <div class="recent-searches" id="recentSearchesWrap" style="display:none">
          <div class="recent-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Searches</div>
          <div class="recent-chips" id="recentChips"></div>
        </div>
      </div>
    </div>

  </div><!-- /page-search -->

</main><!-- /dash-main -->

<!-- ════════ STUDENT RESULT MODAL ════════ -->
<div class="modal-overlay" id="resultModal">
  <div class="modal-card">

    <div class="modal-header">
      <h3><i class="fa-solid fa-id-card"></i> Student Profile</h3>
      <button class="modal-close" id="closeResultModal"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <div class="result-profile">
      <div class="rp-avatar" id="rpAvatar">?</div>
      <div class="rp-info">
        <div class="rp-name"   id="rpName">—</div>
        <div class="rp-course" id="rpCourse">—</div>
        <div class="rp-id"     id="rpId">ID: —</div>
      </div>
      <div class="rp-sessions">
        <div class="rps-val" id="rpSessions">—</div>
        <div class="rps-lbl">Sessions<br>Remaining</div>
      </div>
    </div>

    <div class="result-grid">
      <div class="rg-item">
        <div class="rg-label"><i class="fa-solid fa-graduation-cap"></i> Course</div>
        <div class="rg-value" id="rgCourse">—</div>
      </div>
      <div class="rg-item">
        <div class="rg-label"><i class="fa-solid fa-layer-group"></i> Year Level</div>
        <div class="rg-value" id="rgYear">—</div>
      </div>
      <div class="rg-item full">
        <div class="rg-label"><i class="fa-solid fa-envelope"></i> Email</div>
        <div class="rg-value" id="rgEmail">—</div>
      </div>
      <div class="rg-item">
        <div class="rg-label"><i class="fa-solid fa-clock-rotate-left"></i> Sessions Used</div>
        <div class="rg-value" id="rgUsed">—</div>
      </div>
      <div class="rg-item">
        <div class="rg-label"><i class="fa-solid fa-calendar-check"></i> Last Sit-in</div>
        <div class="rg-value" id="rgLastSitin">—</div>
      </div>
      <div class="rg-item full">
        <div class="rg-label"><i class="fa-solid fa-location-dot"></i> Address</div>
        <div class="rg-value" id="rgAddress">—</div>
      </div>
    </div>

    <div class="modal-actions">
      <button class="btn-sm outline" id="searchAnotherBtn">
        <i class="fa-solid fa-arrow-left"></i> Search Another
      </button>
     

  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
document.addEventListener('DOMContentLoaded', () => {

  /* ── PAGE SWITCHING ── */
  const sbLinks = document.querySelectorAll('.sb-link[data-page]');
  const pages   = document.querySelectorAll('.dash-page');

  function switchPage(id) {
    pages.forEach(p => p.classList.remove('active'));
    sbLinks.forEach(l => l.classList.remove('active'));
    document.getElementById('page-' + id)?.classList.add('active');
    sbLinks.forEach(l => { if (l.dataset.page === id) l.classList.add('active'); });
    closeSidebar();
  }

  sbLinks.forEach(l => l.addEventListener('click', e => { e.preventDefault(); switchPage(l.dataset.page); }));
  /* ── USER MENU (same logic as dashboard.php) ── */
  const userMenuBtn = document.getElementById('userMenuBtn');
  const userMenu    = document.getElementById('userMenu');
  const userChevron = document.getElementById('userChevron');

  userMenuBtn?.addEventListener('click', e => {
    e.stopPropagation();
    const open = userMenu.classList.toggle('open');
    userChevron?.classList.toggle('open', open);
  });

  document.addEventListener('click', e => {
    if (!userMenuBtn?.contains(e.target) && !userMenu?.contains(e.target)) {
      userMenu?.classList.remove('open');
      userChevron?.classList.remove('open');
    }
  });

  /* ── LOGOUT ── */
  document.getElementById('logoutBtn')?.addEventListener('click', async () => {
    const fd = new FormData();
    fd.append('_action', 'logout');
    try { await fetch('admin.php', { method: 'POST', body: fd }); } catch {}
    window.location.href = 'index.php';
  });

  /* ── MOBILE SIDEBAR ── */
  const sidebar        = document.getElementById('sidebar');
  const sbToggle       = document.getElementById('sbToggle');
  const sidebarOverlay = document.getElementById('sidebarOverlay');

  function closeSidebar() {
    sidebar?.classList.remove('open');
    sidebarOverlay?.classList.remove('open');
  }

  sbToggle?.addEventListener('click', () => {
    const open = sidebar.classList.toggle('open');
    sidebarOverlay.classList.toggle('open', open);
  });

  sidebarOverlay?.addEventListener('click', closeSidebar);

  /* ── RESULT MODAL ── */
  const resultModal = document.getElementById('resultModal');

  function openResult(d) {
    const av = document.getElementById('rpAvatar');
    if (d.photo) {
      av.innerHTML        = `<img src="${d.photo}" alt="avatar"/>`;
      av.style.background = 'none';
    } else {
      av.textContent   = d.first_name.charAt(0).toUpperCase();
      av.style.cssText = '';
    }
    document.getElementById('rpName').textContent      = d.name;
    document.getElementById('rpCourse').textContent    = d.course + ' — ' + d.year;
    document.getElementById('rpId').textContent        = 'ID: ' + d.id_number;
    document.getElementById('rpSessions').textContent  = d.sessions;
    document.getElementById('rgCourse').textContent    = d.course;
    document.getElementById('rgYear').textContent      = d.year;
    document.getElementById('rgEmail').textContent     = d.email;
    document.getElementById('rgUsed').textContent      = d.used + ' sessions';
    document.getElementById('rgLastSitin').textContent = d.lastSitin;
    document.getElementById('rgAddress').textContent   = d.address || '—';
    resultModal.classList.add('open');
  }

  function closeResult() { resultModal?.classList.remove('open'); }

  document.getElementById('closeResultModal')?.addEventListener('click', closeResult);
  document.getElementById('closeResultBtn')?.addEventListener('click',   closeResult);
  resultModal?.addEventListener('click', e => { if (e.target === resultModal) closeResult(); });

  document.getElementById('searchAnotherBtn')?.addEventListener('click', () => {
    closeResult();
    document.getElementById('searchInput')?.focus();
  });

  /* ── SEARCH ── */
  const searchInput = document.getElementById('searchInput');
  const doSearchBtn = document.getElementById('doSearchBtn');

  async function runSearch() {
    const id = searchInput?.value.trim();
    if (!id) { showToast('Please enter an ID number.', 'error'); return; }
    if (!/^\d{1,8}$/.test(id)) { showToast('ID number must be up to 8 digits.', 'error'); return; }

    doSearchBtn.disabled  = true;
    doSearchBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Searching…';

    const fd = new FormData();
    fd.append('_action', 'search_student');
    fd.append('id_number', id);

    try {
      const res  = await fetch('admin.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.success) { showToast(data.message, 'error'); return; }
      addRecentChip(id);
      openResult(data);
    } catch (e) {
      showToast('Server error. Make sure XAMPP is running.', 'error');
    } finally {
      doSearchBtn.disabled  = false;
      doSearchBtn.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i> Search';
    }
  }

  doSearchBtn?.addEventListener('click', runSearch);
  searchInput?.addEventListener('keydown', e => { if (e.key === 'Enter') runSearch(); });

  /* ── RECENT CHIPS ── */
  function addRecentChip(id) {
    const wrap      = document.getElementById('recentChips');
    const container = document.getElementById('recentSearchesWrap');
    if (!wrap) return;
    if ([...wrap.querySelectorAll('.recent-chip')].some(c => c.dataset.id === id)) return;
    const chip = document.createElement('span');
    chip.className  = 'recent-chip';
    chip.dataset.id = id;
    chip.innerHTML  = `<i class="fa-solid fa-user"></i> ${id}`;
    chip.addEventListener('click', () => { searchInput.value = id; runSearch(); });
    wrap.prepend(chip);
    if (container) container.style.display = 'block';
  }

  /* ── TOAST ── */
  function showToast(msg, type = 'success') {
    document.querySelector('.toast')?.remove();
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark'}"></i> ${msg}`;
    document.body.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 400); }, 3200);
  }

});
</script>
</body>
</html>