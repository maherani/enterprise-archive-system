/**
 * Enterprise Archive System - First Run Wizard (Custom Two-Slide Implementation)
 * Replaces generic Nextcloud Hub 26 promotional screens with Enterprise Archive Onboarding
 */

let modalMounted = false;
let currentSlide = 0;
let overlayEl = null;
let sliderEl = null;
let indicatorsEl = null;

const heroSvg = `<svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="eaGradOrange" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" stop-color="#fb923c"/>
      <stop offset="100%" stop-color="#ea580c"/>
    </linearGradient>
    <linearGradient id="eaGradDark" x1="0%" y1="0%" x2="0%" y2="100%">
      <stop offset="0%" stop-color="#1e2533"/>
      <stop offset="100%" stop-color="#0e1219"/>
    </linearGradient>
    <filter id="eaGlow" x="-20%" y="-20%" width="140%" height="140%">
      <feGaussianBlur stdDeviation="4" result="blur"/>
      <feComposite in="SourceGraphic" in2="blur" operator="over"/>
    </filter>
  </defs>
  <!-- Outer Decorative Ring -->
  <circle cx="60" cy="60" r="54" stroke="rgba(249,115,22,0.25)" stroke-width="1.5" stroke-dasharray="4 4"/>
  <circle cx="60" cy="60" r="48" stroke="rgba(249,115,22,0.4)" stroke-width="1"/>
  <!-- Outer Shield -->
  <path d="M60 16L92 28V62C92 81.5 78.5 96.5 60 102C41.5 96.5 28 81.5 28 62V28L60 16Z" fill="url(#eaGradDark)" stroke="url(#eaGradOrange)" stroke-width="2.5" filter="url(#eaGlow)"/>
  <!-- Archival Document Stack in Shield -->
  <rect x="44" y="38" width="32" height="40" rx="4" fill="#1e293b" stroke="rgba(249,115,22,0.6)" stroke-width="1.5"/>
  <line x1="50" y1="48" x2="70" y2="48" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"/>
  <line x1="50" y1="55" x2="66" y2="55" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"/>
  <line x1="50" y1="62" x2="60" y2="62" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"/>
  <!-- Central Glowing Vault Lock -->
  <circle cx="60" cy="74" r="10" fill="#090b0e" stroke="#f97316" stroke-width="2"/>
  <path d="M57 74C57 72.3 58.3 71 60 71C61.7 71 63 72.3 63 74V75H57V74Z" fill="#f97316"/>
  <rect x="55" y="75" width="10" height="7" rx="1" fill="#f97316"/>
  <circle cx="60" cy="78" r="1" fill="#090b0e"/>
  <!-- Accent Nodes -->
  <circle cx="28" cy="62" r="3" fill="#f97316"/>
  <circle cx="92" cy="62" r="3" fill="#f97316"/>
  <circle cx="60" cy="16" r="3.5" fill="#f97316" filter="url(#eaGlow)"/>
</svg>`;

const iconSecurity = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><rect x="9" y="11" width="6" height="5" rx="1"/><path d="M10 11V9a2 2 0 0 1 4 0v2"/></svg>`;
const iconTags = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/><path d="M16 3v4"/><path d="M21 8h-4"/></svg>`;
const iconFilter = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/><line x1="16" y1="18" x2="22" y2="18"/><line x1="19" y1="15" x2="19" y2="21"/></svg>`;
const iconGov = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 15 2 2 4-4"/></svg>`;

function buildModalDOM() {
  if (document.getElementById('ea-wizard-overlay')) {
    overlayEl = document.getElementById('ea-wizard-overlay');
    sliderEl = overlayEl.querySelector('.ea-wizard-slider');
    indicatorsEl = overlayEl.querySelectorAll('.ea-indicator');
    return;
  }

  const overlay = document.createElement('div');
  overlay.id = 'ea-wizard-overlay';
  overlay.className = 'ea-wizard-overlay';

  overlay.innerHTML = `
    <div class="ea-wizard-modal" role="dialog" aria-modal="true">
      <button class="ea-wizard-close" aria-label="بستن" title="بستن و خروج">✕</button>
      
      <div class="ea-wizard-slider">
        <!-- SLIDE 1: INTRO / WELCOME HERO -->
        <div class="ea-wizard-slide ea-slide-1">
          <div class="ea-hero-wrapper">
            <div class="ea-hero-badge">
              <span>🔒</span> سامانه متمرکز و ایزوله سازمانی
            </div>
            
            <div class="ea-hero-icon-box">
              ${heroSvg}
            </div>

            <h1 class="ea-hero-title">سامانه جامع بایگانی اسناد سازمانی</h1>
            <div class="ea-hero-en-title">Enterprise Document Archiving & Governance</div>

            <p class="ea-hero-desc">
              بستر یکپارچه، امن و ایزوله درون‌سازمانی جهت نگهداری متمرکز، رده‌بندی هوشمند متادیتا،
              و بازیابی سریع پرونده‌ها و اسناد اداری در بالاترین سطح انطباق و محرمانگی.
            </p>

            <div class="ea-hero-pills">
              <div class="ea-pill"><span class="dot"></span>ایزوله On-Premise (Air-Gapped)</div>
              <div class="ea-pill"><span class="dot"></span>برچسب‌گذاری سلسله‌مراتبی</div>
              <div class="ea-pill"><span class="dot"></span>جستجوی ترکیبی چندتگی</div>
              <div class="ea-pill"><span class="dot"></span>حاکمیت داده و ممیزی</div>
            </div>
          </div>

          <div class="ea-wizard-footer">
            <div class="ea-indicators">
              <div class="ea-indicator active" data-slide="0" title="صفحه اول: معرفی"></div>
              <div class="ea-indicator" data-slide="1" title="صفحه دوم: قابلیت‌ها"></div>
            </div>
            <div class="ea-actions">
              <button class="ea-btn ea-btn-secondary ea-btn-direct">ورود مستقیم</button>
              <button class="ea-btn ea-btn-primary ea-btn-next">
                مشاهده قابلیت‌ها ←
              </button>
            </div>
          </div>
        </div>

        <!-- SLIDE 2: CORE CAPABILITIES -->
        <div class="ea-wizard-slide ea-slide-2">
          <div class="ea-caps-header">
            <h2>ارکان و قابلیت‌های کلیدی سامانه بایگانی</h2>
            <p>معماری و خط‌مشی‌های امنیتی حاکم بر مدیریت پرونده‌ها و اسناد اداری</p>
          </div>

          <div class="ea-grid-cards">
            <!-- Card 1 -->
            <div class="ea-card">
              <div class="ea-card-icon">${iconSecurity}</div>
              <div class="ea-card-body">
                <h3 class="ea-card-title">ایزولاسیون و محرمانگی داده‌ها</h3>
                <p class="ea-card-desc">تفکیک کامل مخازن اسناد، ایزولاسیون دسترسی کاربران و شعب در محیط کاملاً بسته On-Premise بدون اتصال به اینترنت.</p>
              </div>
            </div>

            <!-- Card 2 -->
            <div class="ea-card">
              <div class="ea-card-icon">${iconTags}</div>
              <div class="ea-card-body">
                <h3 class="ea-card-title">برچسب‌گذاری سلسله‌مراتبی</h3>
                <p class="ea-card-desc">شناسایی و الصاق خودکار و هوشمند تگ‌های متادیتا بر پایه ساختار درختی پوشه‌ها به محض بارگذاری اسناد.</p>
              </div>
            </div>

            <!-- Card 3 -->
            <div class="ea-card">
              <div class="ea-card-icon">${iconFilter}</div>
              <div class="ea-card-body">
                <h3 class="ea-card-title">فیلتر و جستجوی چندتگی</h3>
                <p class="ea-card-desc">بازیابی سریع و دقیق پرونده‌ها با منطق اشتراک برچسب‌ها (AND Filter)، تفکیک تگ‌های شخصی و سازمانی.</p>
              </div>
            </div>

            <!-- Card 4 -->
            <div class="ea-card">
              <div class="ea-card-icon">${iconGov}</div>
              <div class="ea-card-body">
                <h3 class="ea-card-title">حاکمیت داده و انضباط سازمانی</h3>
                <p class="ea-card-desc">محدودیت سهمیه دپارتمان‌ها، سیاست پوشه‌سازی انحصاری مدیران، و انطباق کامل با گزارش‌های ممیزی و نظارت.</p>
              </div>
            </div>
          </div>

          <div class="ea-version-banner">
            <span>سامانه بایگانی اسناد سازمانی</span>
            <span>•</span>
            <span class="badge">ویرایش سازمانی On-Premise</span>
            <span>•</span>
            <span>نسخه ۳۴</span>
          </div>

          <div class="ea-wizard-footer">
            <div class="ea-indicators">
              <div class="ea-indicator" data-slide="0" title="صفحه اول: معرفی"></div>
              <div class="ea-indicator active" data-slide="1" title="صفحه دوم: قابلیت‌ها"></div>
            </div>
            <div class="ea-actions">
              <button class="ea-btn ea-btn-secondary ea-btn-prev">← بازگشت</button>
              <button class="ea-btn ea-btn-primary ea-btn-finish">
                ورود به پرتال بایگانی اسناد ✓
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;

  document.body.appendChild(overlay);
  overlayEl = overlay;
  sliderEl = overlay.querySelector('.ea-wizard-slider');
  indicatorsEl = overlay.querySelectorAll('.ea-indicator');

  // Bind events
  overlay.querySelector('.ea-wizard-close').addEventListener('click', () => close());
  overlay.querySelector('.ea-btn-direct').addEventListener('click', () => close());
  overlay.querySelector('.ea-btn-next').addEventListener('click', () => goToSlide(1));
  overlay.querySelector('.ea-btn-prev').addEventListener('click', () => goToSlide(0));
  overlay.querySelector('.ea-btn-finish').addEventListener('click', () => {
    close();
    // Navigate to archive portal if not already there
    if (!window.location.href.includes('/apps/archive_autotag')) {
      const portalUrl = window.OC && window.OC.generateUrl ? window.OC.generateUrl('/apps/archive_autotag/') : '/index.php/apps/archive_autotag/';
      window.location.href = portalUrl;
    }
  });

  // Indicator clicks
  indicatorsEl.forEach(ind => {
    ind.addEventListener('click', (e) => {
      const idx = parseInt(e.currentTarget.getAttribute('data-slide'), 10);
      goToSlide(idx);
    });
  });

  // Close on backdrop click outside modal
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) {
      close();
    }
  });

  // Close on Esc key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && overlay.classList.contains('ea-active')) {
      close();
    }
  });

  modalMounted = true;
}

function goToSlide(index) {
  currentSlide = index;
  if (!sliderEl) return;
  // In RTL, sliding to slide 1 moves slider left: translateX(50%)
  sliderEl.style.transform = index === 1 ? 'translateX(50%)' : 'translateX(0%)';
  
  if (overlayEl) {
    const allIndicators = overlayEl.querySelectorAll('.ea-indicator');
    allIndicators.forEach(ind => {
      const slideNum = parseInt(ind.getAttribute('data-slide'), 10);
      if (slideNum === index) {
        ind.classList.add('active');
      } else {
        ind.classList.remove('active');
      }
    });
  }
}

function close() {
  if (overlayEl) {
    overlayEl.classList.remove('ea-active');
  }

  // Record dismissal in Nextcloud so it will not automatically pop up again
  try {
    const url = window.OC && window.OC.generateUrl 
      ? window.OC.generateUrl('/apps/firstrunwizard/wizard') 
      : '/index.php/apps/firstrunwizard/wizard';
    const token = window.OC && window.OC.requestToken ? window.OC.requestToken : '';
    fetch(url, {
      method: 'DELETE',
      headers: {
        'requesttoken': token,
        'OCS-APIRequest': 'true'
      }
    }).catch(() => {});
  } catch (err) {}
}

function open(focusReturn) {
  buildModalDOM();
  goToSlide(0);
  if (overlayEl) {
    requestAnimationFrame(() => {
      overlayEl.classList.add('ea-active');
    });
  }
}

export { open };
