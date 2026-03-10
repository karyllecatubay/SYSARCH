/* ============================================
   CCS Sit-in Monitoring System — script.js
   ============================================ */

document.addEventListener('DOMContentLoaded', () => {

  /* ─── HAMBURGER MENU ─── */
  const hamburger = document.getElementById('hamburger');
  const navCenter = document.getElementById('navCenter');

  if (hamburger && navCenter) {
    hamburger.addEventListener('click', () => {
      navCenter.classList.toggle('open');
      hamburger.setAttribute('aria-expanded', navCenter.classList.contains('open'));
    });

    navCenter.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => navCenter.classList.remove('open'));
    });
  }

  /* ─── COMMUNITY DROPDOWN ─── */
  const communityToggle = document.getElementById('communityToggle');
  const communityMenu   = document.getElementById('communityMenu');

  if (communityToggle && communityMenu) {
    communityToggle.addEventListener('click', (e) => {
      e.preventDefault();
      communityMenu.classList.toggle('show');
    });

    document.addEventListener('click', (e) => {
      if (!communityToggle.contains(e.target) && !communityMenu.contains(e.target)) {
        communityMenu.classList.remove('show');
      }
    });
  }

  /* ─── COUNTER ANIMATION ─── */
  const counters = document.querySelectorAll('.stat-number[data-target]');

  if (counters.length) {
    const animateCounter = (el) => {
      const target    = parseInt(el.dataset.target, 10);
      const duration  = 1400;
      const step      = 16;
      const increment = target / (duration / step);
      let current = 0;

      const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
          el.firstChild.textContent = target;
          clearInterval(timer);
        } else {
          el.firstChild.textContent = Math.floor(current);
        }
      }, step);
    };

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          animateCounter(entry.target);
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.5 });

    counters.forEach(counter => observer.observe(counter));
  }

  /* ─── FEATURE CARD SCROLL REVEAL ─── */
  const featureCards = document.querySelectorAll('.feature-card');

  if (featureCards.length) {
    featureCards.forEach((card, i) => {
      card.style.opacity    = '0';
      card.style.transform  = 'translateY(18px)';
      card.style.transition = `opacity .5s ease ${i * 80}ms, transform .5s ease ${i * 80}ms`;
    });

    const revealHandler = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.opacity   = '1';
          entry.target.style.transform = 'translateY(0)';
          revealHandler.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1 });

    featureCards.forEach(card => revealHandler.observe(card));
  }

  /* ─── PASSWORD TOGGLE ─── */
  document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;

      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';

      const icon = btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('fa-eye',       !isHidden);
        icon.classList.toggle('fa-eye-slash',  isHidden);
      }
    });
  });

  /* ─── REGISTER FORM → PHP ─── */
  const registerForm = document.getElementById('registerForm');

  if (registerForm) {
    registerForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (!validateRegisterForm()) return;

      const formData = new FormData(registerForm);
      try {
        const res  = await fetch('register_process.php', { method: 'POST', body: formData });
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) {
          setTimeout(() => { window.location.href = 'dashboard.php'; }, 2200);
        }
      } catch (err) {
        showToast('Server error. Make sure XAMPP is running.', 'error');
      }
    });

    registerForm.querySelectorAll('input, select').forEach(input => {
      input.addEventListener('blur',  () => validateField(input));
      input.addEventListener('input', () => {
        if (input.classList.contains('error')) validateField(input);
      });
    });
  }

  /* ─── LOGIN FORM → PHP ─── */
  const loginForm = document.getElementById('loginForm');

  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (!validateLoginForm()) return;

      const formData = new FormData(loginForm);
      try {
        const res  = await fetch('login_process.php', { method: 'POST', body: formData });
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) {
          setTimeout(() => { window.location.href = 'dashboard.php'; }, 2200);
        }
      } catch (err) {
        showToast('Server error. Make sure XAMPP is running.', 'error');
      }
    });

    loginForm.querySelectorAll('input').forEach(input => {
      input.addEventListener('blur',  () => validateField(input));
      input.addEventListener('input', () => {
        if (input.classList.contains('error')) validateField(input);
      });
    });
  }

  /* ─── FIELD VALIDATION ─── */
  function validateField(field) {
    const { id, value, required } = field;
    const trimmed = value.trim();
    let errorMsg  = '';

    if (required && !trimmed) {
      errorMsg = 'This field is required.';
    } else {
      switch (id) {
        case 'idNumber':
          if (trimmed && !/^\d{8}$/.test(trimmed))
            errorMsg = 'Format: 12345678';
          break;
        case 'courseLevel': {
          const n = parseInt(trimmed, 10);
          if (trimmed && (isNaN(n) || n < 1 || n > 4))
            errorMsg = 'Must be 1–4.';
          break;
        }
        case 'email':
          if (trimmed && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed))
            errorMsg = 'Enter a valid email address.';
          break;
        case 'password':
          if (trimmed && trimmed.length < 8)
            errorMsg = 'Minimum 8 characters.';
          break;
        case 'repeatPassword': {
          const pw = document.getElementById('password');
          if (pw && trimmed !== pw.value.trim())
            errorMsg = 'Passwords do not match.';
          break;
        }
      }
    }

    const errorEl = document.getElementById(`${id}Error`);
    if (errorEl) errorEl.textContent = errorMsg;
    field.classList.toggle('error', !!errorMsg);

    return !errorMsg;
  }

  function validateRegisterForm() {
    const fields = ['idNumber', 'lastName', 'firstName', 'courseLevel', 'email', 'password', 'repeatPassword'];
    return fields.reduce((allValid, id) => {
      const field = document.getElementById(id);
      return field ? (validateField(field) && allValid) : allValid;
    }, true);
  }

  function validateLoginForm() {
    const fields = ['loginId', 'loginPassword'];
    return fields.reduce((allValid, id) => {
      const field = document.getElementById(id);
      return field ? (validateField(field) && allValid) : allValid;
    }, true);
  }

  /* ─── TOAST NOTIFICATION ─── */
  function showToast(message, type = 'success') {
    const existing = document.querySelector('.toast');
    if (existing) existing.remove();

    const icon  = type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark';
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<i class="fa-solid ${icon}"></i> ${message}`;
    document.body.appendChild(toast);

    requestAnimationFrame(() => {
      requestAnimationFrame(() => toast.classList.add('show'));
    });

    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 400);
    }, 3000);
  }

  /* ─── ACTIVE NAV LINK ─── */
  const currentPage = window.location.pathname.split('/').pop() || 'index.php';

  document.querySelectorAll('.nav-link').forEach(link => {
    const href = link.getAttribute('href') || '';
    if (href && href.includes(currentPage)) {
      link.classList.add('active');
    }
  });

});