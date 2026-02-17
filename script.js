/* ============================================
   CCS Sit-in Monitoring System — script.js
   ============================================ */

document.addEventListener('DOMContentLoaded', () => {

  /* ─── HAMBURGER MENU ─── */
  const hamburger = document.getElementById('hamburger');
  const navLinks  = document.getElementById('navLinks');

  if (hamburger && navLinks) {
    hamburger.addEventListener('click', () => {
      navLinks.classList.toggle('open');
      const isOpen = navLinks.classList.contains('open');
      hamburger.setAttribute('aria-expanded', isOpen);
    });

    // Close menu when a link is clicked
    navLinks.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => navLinks.classList.remove('open'));
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

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
      if (!communityToggle.contains(e.target) && !communityMenu.contains(e.target)) {
        communityMenu.classList.remove('show');
      }
    });
  }


  /* ─── COUNTER ANIMATION (Hero Stats) ─── */
  const counters = document.querySelectorAll('.stat-number[data-target]');

  if (counters.length) {
    const animateCounter = (el) => {
      const target   = parseInt(el.dataset.target, 10);
      const duration = 1400;
      const step     = 16;
      const increment = target / (duration / step);
      let current = 0;

      const timer = setInterval(() => {
        current += increment;
        if (current >= target) {
          el.textContent = target;
          clearInterval(timer);
        } else {
          el.textContent = Math.floor(current);
        }
      }, step);
    };

    // Use IntersectionObserver so counters animate when visible
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


  /* ─── SCROLL REVEAL (Feature Cards) ─── */
  const revealEls = document.querySelectorAll('.feature-card');

  if (revealEls.length) {
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry, i) => {
        if (entry.isIntersecting) {
          setTimeout(() => {
            entry.target.style.opacity    = '1';
            entry.target.style.transform  = 'translateY(0)';
          }, i * 80);
          revealObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });

    revealEls.forEach(el => {
      el.style.opacity   = '0';
      el.style.transform = 'translateY(20px)';
      el.style.transition = 'opacity .5s ease, transform .5s ease';
      revealObserver.observe(el);
    });
  }


  /* ─── PASSWORD TOGGLE ─── */
  document.querySelectorAll('.toggle-pw').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.dataset.target;
      const input    = document.getElementById(targetId);
      if (!input) return;

      const isHidden = input.type === 'password';
      input.type     = isHidden ? 'text' : 'password';

      const icon = btn.querySelector('i');
      if (icon) {
        icon.classList.toggle('fa-eye',        !isHidden);
        icon.classList.toggle('fa-eye-slash',   isHidden);
      }
    });
  });


  /* ─── REGISTER FORM VALIDATION ─── */
  const registerForm = document.getElementById('registerForm');

  if (registerForm) {
    registerForm.addEventListener('submit', (e) => {
      e.preventDefault();
      if (validateRegisterForm()) {
        showToast('Registration successful! Welcome to CCS Monitoring.', 'success');
        // Simulate redirect after success
        setTimeout(() => { window.location.href = 'index.html'; }, 2200);
      }
    });

    // Real-time validation on blur
    registerForm.querySelectorAll('input, select').forEach(input => {
      input.addEventListener('blur', () => validateField(input));
      input.addEventListener('input', () => {
        if (input.classList.contains('error')) validateField(input);
      });
    });
  }

  /**
   * Validate a single field.
   * @param {HTMLInputElement|HTMLSelectElement} field
   * @returns {boolean}
   */
  function validateField(field) {
    const { id, value, required } = field;
    const trimmed = value.trim();
    let errorMsg  = '';

    if (required && !trimmed) {
      errorMsg = 'This field is required.';
    } else {
      switch (id) {
        case 'idNumber':
          if (trimmed && !/^\d{4}-\d{5}$/.test(trimmed))
            errorMsg = 'Format: 12345678';
          break;
        case 'courseLevel':
          if (trimmed) {
            const n = parseInt(trimmed, 10);
            if (isNaN(n) || n < 1 || n > 4)
              errorMsg = 'Course level must be 1–4.';
          }
          break;
        case 'email':
          if (trimmed && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed))
            errorMsg = 'Enter a valid email address.';
          break;
        case 'password':
          if (trimmed && trimmed.length < 8)
            errorMsg = 'Password must be at least 8 characters.';
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

    if (errorMsg) {
      field.classList.add('error');
    } else {
      field.classList.remove('error');
    }

    return !errorMsg;
  }

  /**
   * Run validation on all required register fields.
   * @returns {boolean}
   */
  function validateRegisterForm() {
    const fields = ['idNumber','lastName','firstName','courseLevel','email','password','repeatPassword'];
    let allValid = true;

    fields.forEach(id => {
      const field = document.getElementById(id);
      if (field && !validateField(field)) allValid = false;
    });

    return allValid;
  }


  /* ─── TOAST NOTIFICATION ─── */
  /**
   * Show a toast message.
   * @param {string} message
   * @param {'success'|'error'} type
   */
  function showToast(message, type = 'success') {
    // Remove existing toast
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


  /* ─── SMOOTH ACTIVE NAV LINK ─── */
  const currentPath = window.location.pathname.split('/').pop() || 'index.html';

  document.querySelectorAll('.navbar-links .nav-link').forEach(link => {
    const href = link.getAttribute('href');
    if (href && href.includes(currentPath)) {
      link.classList.add('active');
    } else {
      link.classList.remove('active');
    }
  });

});