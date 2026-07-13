/* ============================================================
   善因官网系统 — 主脚本 (script.js)
   ShanYin Tech & Culture — Main JavaScript
   Version: 2.0.0
   Description: 善因(杭州)科技文化有限责任公司官方网站交互脚本
   ============================================================ */

/**
 * ============================================================
 * 0. 全局配置与常量
 * ============================================================
 */
const SITE_CONFIG = {
  name: '善因科技文化',
  nameEn: 'ShanYin Tech & Culture',
  founded: 2019,
  navHeight: 72,
  scrollThreshold: 60,
  revealThreshold: 0.15,
  animationDuration: 700,
  apiBase: '',
  locale: 'zh-CN',
  supportedLocales: ['zh-CN', 'en-US'],
};

/**
 * ============================================================
 * 1. 工具函数 (Utilities)
 * ============================================================
 */

/**
 * 节流函数 — 限制函数执行频率
 * @param {Function} fn - 目标函数
 * @param {number} delay - 节流延迟(ms)
 * @returns {Function} 节流后的函数
 */
function throttle(fn, delay) {
  let lastCall = 0;
  let timeoutId = null;
  return function (...args) {
    const now = Date.now();
    const remaining = delay - (now - lastCall);
    if (remaining <= 0) {
      if (timeoutId) {
        clearTimeout(timeoutId);
        timeoutId = null;
      }
      lastCall = now;
      fn.apply(this, args);
    } else if (!timeoutId) {
      timeoutId = setTimeout(() => {
        lastCall = Date.now();
        timeoutId = null;
        fn.apply(this, args);
      }, remaining);
    }
  };
}

/**
 * 防抖函数 — 延迟执行直到停止调用
 * @param {Function} fn - 目标函数
 * @param {number} delay - 防抖延迟(ms)
 * @returns {Function} 防抖后的函数
 */
function debounce(fn, delay) {
  let timer = null;
  return function (...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), delay);
  };
}

/**
 * 安全查询 DOM 元素
 * @param {string} selector - CSS 选择器
 * @param {Element} parent - 父元素，默认 document
 * @returns {Element|null}
 */
function $(selector, parent) {
  return (parent || document).querySelector(selector);
}

/**
 * 安全查询 DOM 元素列表
 * @param {string} selector - CSS 选择器
 * @param {Element} parent - 父元素，默认 document
 * @returns {Element[]}
 */
function $$(selector, parent) {
  return Array.from((parent || document).querySelectorAll(selector));
}

/**
 * 判断元素是否在视口内
 * @param {Element} el - 目标元素
 * @param {number} offset - 提前量(px)
 * @returns {boolean}
 */
function isInViewport(el, offset) {
  offset = offset || 0;
  var rect = el.getBoundingClientRect();
  return (
    rect.top <= window.innerHeight + offset &&
    rect.bottom >= -offset
  );
}

/**
 * 平滑滚动到指定位置
 * @param {number} targetY - 目标 Y 坐标
 * @param {number} duration - 动画时长(ms)
 */
function smoothScrollTo(targetY, duration) {
  duration = duration || 800;
  var startY = window.pageYOffset;
  var diff = targetY - startY;
  var startTime = null;

  function easeOutCubic(t) {
    return 1 - Math.pow(1 - t, 3);
  }

  function step(timestamp) {
    if (!startTime) startTime = timestamp;
    var progress = Math.min((timestamp - startTime) / duration, 1);
    var easedProgress = easeOutCubic(progress);
    window.scrollTo(0, startY + diff * easedProgress);
    if (progress < 1) {
      requestAnimationFrame(step);
    }
  }

  requestAnimationFrame(step);
}

/**
 * 格式化日期
 * @param {Date} date
 * @param {string} locale
 * @returns {string}
 */
function formatDate(date, locale) {
  locale = locale || SITE_CONFIG.locale;
  var year = date.getFullYear();
  var month = String(date.getMonth() + 1).padStart(2, '0');
  var day = String(date.getDate()).padStart(2, '0');
  if (locale === 'en-US') {
    return month + '/' + day + '/' + year;
  }
  return year + '年' + month + '月' + day + '日';
}

/**
 * 生成唯一 ID
 * @returns {string}
 */
function generateId() {
  return 'id_' + Date.now().toString(36) + '_' + Math.random().toString(36).substr(2, 6);
}

/**
 * ============================================================
 * 2. 加载屏幕 (Loading Screen)
 * ============================================================
 */
function initLoadingScreen() {
  var loadingScreen = $('#loading-screen');
  if (!loadingScreen) return;

  // 页面加载完成后隐藏
  function hideLoading() {
    loadingScreen.classList.add('hidden');
    setTimeout(function () {
      if (loadingScreen.parentNode) {
        loadingScreen.parentNode.removeChild(loadingScreen);
      }
    }, 600);
  }

  if (document.readyState === 'complete') {
    setTimeout(hideLoading, 400);
  } else {
    window.addEventListener('load', function () {
      setTimeout(hideLoading, 400);
    });
  }

  // 安全超时：最多 3 秒后强制隐藏
  setTimeout(hideLoading, 3000);
}

/**
 * ============================================================
 * 3. 导航栏 (Navigation)
 * ============================================================
 */
function initNavigation() {
  var nav = $('#nav');
  var navToggle = $('#nav-toggle');
  var navLinks = $('#nav-links');

  if (!nav) return;

  // 滚动效果
  function handleNavScroll() {
    var scrollY = window.pageYOffset;
    if (scrollY > SITE_CONFIG.scrollThreshold) {
      nav.classList.add('scrolled');
    } else {
      nav.classList.remove('scrolled');
    }
  }

  window.addEventListener('scroll', throttle(handleNavScroll, 100));
  handleNavScroll(); // 初始检查

  // 移动端菜单切换
  if (navToggle && navLinks) {
    navToggle.addEventListener('click', function () {
      navToggle.classList.toggle('active');
      navLinks.classList.toggle('open');
      document.body.classList.toggle('no-scroll');
    });

    // 点击导航链接后关闭菜单
    $$('a', navLinks).forEach(function (link) {
      link.addEventListener('click', function () {
        navToggle.classList.remove('active');
        navLinks.classList.remove('open');
        document.body.classList.remove('no-scroll');
      });
    });
  }

  // 平滑滚动（锚点链接）
  $$('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var href = link.getAttribute('href');
      if (!href || href === '#') return;
      var target = $(href);
      if (target) {
        e.preventDefault();
        var offset = nav ? nav.offsetHeight : SITE_CONFIG.navHeight;
        var targetY = target.getBoundingClientRect().top + window.pageYOffset - offset;
        smoothScrollTo(targetY, 800);
      }
    });
  });
}

/**
 * ============================================================
 * 4. 滚动揭示动画 (Reveal on Scroll)
 * ============================================================
 */
function initRevealAnimations() {
  var revealSelectors = [
    '.reveal',
    '.reveal-left',
    '.reveal-right',
    '.reveal-scale',
  ];

  var allRevealEls = [];
  revealSelectors.forEach(function (selector) {
    allRevealEls = allRevealEls.concat($$(selector));
  });

  if (allRevealEls.length === 0) return;

  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          // 可选：动画完成后停止观察以节省性能
          observer.unobserve(entry.target);
        }
      });
    },
    {
      threshold: SITE_CONFIG.revealThreshold,
      rootMargin: '0px 0px -50px 0px',
    }
  );

  allRevealEls.forEach(function (el) {
    observer.observe(el);
  });
}

/**
 * ============================================================
 * 5. 返回顶部按钮 (Back to Top)
 * ============================================================
 */
function initBackToTop() {
  var btn = $('#back-to-top');
  if (!btn) return;

  function handleScroll() {
    if (window.pageYOffset > 400) {
      btn.classList.add('visible');
    } else {
      btn.classList.remove('visible');
    }
  }

  window.addEventListener('scroll', throttle(handleScroll, 100));
  handleScroll();

  btn.addEventListener('click', function () {
    smoothScrollTo(0, 600);
  });
}

/**
 * ============================================================
 * 6. 数字递增动画 (Count Up Animation)
 * ============================================================
 */
function animateCountUp(el, target, duration) {
  duration = duration || 2000;
  var start = 0;
  var startTime = null;
  var isDecimal = target % 1 !== 0;
  var decimalPlaces = isDecimal ? target.toString().split('.')[1].length : 0;

  function step(timestamp) {
    if (!startTime) startTime = timestamp;
    var progress = Math.min((timestamp - startTime) / duration, 1);
    // easeOutExpo
    var eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);
    var current = eased * target;

    if (isDecimal) {
      el.textContent = current.toFixed(decimalPlaces);
    } else {
      el.textContent = Math.floor(current).toLocaleString(SITE_CONFIG.locale);
    }

    if (progress < 1) {
      requestAnimationFrame(step);
    } else {
      // 最终值
      if (isDecimal) {
        el.textContent = target.toFixed(decimalPlaces);
      } else {
        el.textContent = target.toLocaleString(SITE_CONFIG.locale);
      }
    }
  }

  requestAnimationFrame(step);
}

function initCountUpAnimations() {
  var countEls = $$('[data-count]');
  if (countEls.length === 0) return;

  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          var el = entry.target;
          var target = parseFloat(el.getAttribute('data-count'));
          if (!isNaN(target) && !el.classList.contains('counted')) {
            el.classList.add('counted');
            animateCountUp(el, target, 2000);
          }
          observer.unobserve(el);
        }
      });
    },
    { threshold: 0.5 }
  );

  countEls.forEach(function (el) {
    observer.observe(el);
  });
}

/**
 * ============================================================
 * 7. 项目筛选 (Project Filter)
 * ============================================================
 */
function initProjectFilter() {
  var filterBtns = $$('.filter-btn');
  var projectCards = $$('.project-card');

  if (filterBtns.length === 0 || projectCards.length === 0) return;

  filterBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var filter = btn.getAttribute('data-filter');

      // 更新按钮状态
      filterBtns.forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');

      // 筛选项目卡片
      projectCards.forEach(function (card) {
        var category = card.getAttribute('data-category');
        if (filter === 'all' || category === filter) {
          card.style.display = '';
          card.style.opacity = '0';
          card.style.transform = 'translateY(20px)';
          requestAnimationFrame(function () {
            card.style.transition = 'opacity 400ms, transform 400ms';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
          });
        } else {
          card.style.display = 'none';
        }
      });
    });
  });
}

/**
 * ============================================================
 * 8. 表单验证 (Form Validation)
 * ============================================================
 */
function initForms() {
  var forms = $$('form[data-validate]');

  forms.forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var isValid = validateForm(form);
      if (isValid) {
        handleFormSubmit(form);
      }
    });

    // 实时验证
    $$('input, textarea, select', form).forEach(function (field) {
      field.addEventListener('blur', function () {
        validateField(field);
      });
    });
  });
}

function validateForm(form) {
  var fields = $$('[required]', form);
  var isValid = true;

  fields.forEach(function (field) {
    if (!validateField(field)) {
      isValid = false;
    }
  });

  return isValid;
}

function validateField(field) {
  var value = field.value.trim();
  var type = field.getAttribute('type');
  var isRequired = field.hasAttribute('required');
  var errorEl = document.getElementById(field.getAttribute('data-error-id'));

  // 清除之前的错误状态
  field.classList.remove('field-error');
  if (errorEl) errorEl.textContent = '';

  if (isRequired && !value) {
    field.classList.add('field-error');
    if (errorEl) errorEl.textContent = '此字段为必填项';
    return false;
  }

  if (type === 'email' && value) {
    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(value)) {
      field.classList.add('field-error');
      if (errorEl) errorEl.textContent = '请输入有效的邮箱地址';
      return false;
    }
  }

  if (type === 'tel' && value) {
    var telRegex = /^[\d\s\-\+\(\)]{7,}$/;
    if (!telRegex.test(value)) {
      field.classList.add('field-error');
      if (errorEl) errorEl.textContent = '请输入有效的电话号码';
      return false;
    }
  }

  return true;
}

function handleFormSubmit(form) {
  var submitBtn = $('[type="submit"]', form);
  if (submitBtn) {
    var originalText = submitBtn.textContent;
    submitBtn.textContent = '提交中...';
    submitBtn.disabled = true;

    // 模拟提交（实际项目中替换为真实 API 调用）
    setTimeout(function () {
      showNotification('提交成功！我们会尽快与您联系。', 'success');
      form.reset();
      submitBtn.textContent = originalText;
      submitBtn.disabled = false;
    }, 1500);
  }
}

/**
 * ============================================================
 * 9. 通知系统 (Notification System)
 * ============================================================
 */
function showNotification(message, type) {
  type = type || 'info';
  var container = $('#notification-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'notification-container';
    container.style.cssText = [
      'position: fixed',
      'top: 20px',
      'right: 20px',
      'z-index: 10000',
      'display: flex',
      'flex-direction: column',
      'gap: 10px',
    ].join(';');
    document.body.appendChild(container);
  }

  var notification = document.createElement('div');
  notification.className = 'notification notification-' + type;
  notification.style.cssText = [
    'padding: 14px 24px',
    'background: ' + (type === 'success' ? '#2ecc71' : type === 'error' ? '#e74c3c' : '#3498db'),
    'color: white',
    'font-size: 14px',
    'box-shadow: 0 4px 12px rgba(0,0,0,0.15)',
    'transform: translateX(120%)',
    'transition: transform 400ms cubic-bezier(0.16, 1, 0.3, 1)',
    'max-width: 360px',
  ].join(';');
  notification.textContent = message;

  container.appendChild(notification);

  // 触发动画
  requestAnimationFrame(function () {
    notification.style.transform = 'translateX(0)';
  });

  // 自动消失
  setTimeout(function () {
    notification.style.transform = 'translateX(120%)';
    setTimeout(function () {
      if (notification.parentNode) {
        notification.parentNode.removeChild(notification);
      }
    }, 400);
  }, 4000);
}

/**
 * ============================================================
 * 10. 语言切换 (Language Switcher)
 * ============================================================
 */
function initLanguageSwitcher() {
  var langBtns = $$('.lang-btn');
  if (langBtns.length === 0) return;

  langBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var locale = btn.getAttribute('data-locale');
      if (locale) {
        setLocale(locale);
        langBtns.forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
      }
    });
  });
}

function setLocale(locale) {
  SITE_CONFIG.locale = locale;
  document.documentElement.lang = locale === 'en-US' ? 'en' : 'zh-CN';

  // 切换带有 data-i18n 属性的元素文本
  $$('[data-i18n]').forEach(function (el) {
    var key = el.getAttribute('data-i18n');
    var text = getI18nText(key, locale);
    if (text) {
      el.textContent = text;
    }
  });

  // 切换带有 data-i18n-placeholder 属性的输入框占位符
  $$('[data-i18n-placeholder]').forEach(function (el) {
    var key = el.getAttribute('data-i18n-placeholder');
    var text = getI18nText(key, locale);
    if (text) {
      el.placeholder = text;
    }
  });

  localStorage.setItem('shanyin-locale', locale);
}

function getI18nText(key, locale) {
  var dict = I18N_DICT[locale];
  if (dict && dict[key]) {
    return dict[key];
  }
  return null;
}

// 国际化字典
var I18N_DICT = {
  'zh-CN': {
    'nav.about': '关于',
    'nav.business': '业务',
    'nav.tech': '技术',
    'nav.honors': '资质',
    'nav.contact': '联系',
    'hero.tagline': '科技赋能文化\n文化滋养科技',
    'hero.sub': '善因科技文化成立于2019年，是浙江省高新技术企业。我们以视频处理与数字影像技术为核心，深度融合文化艺术交流策划，为客户提供从技术研发到文化传播的全链路服务。',
    'form.name': '姓名',
    'form.email': '邮箱',
    'form.message': '留言',
    'form.submit': '提交',
  },
  'en-US': {
    'nav.about': 'About',
    'nav.business': 'Services',
    'nav.tech': 'Tech',
    'nav.honors': 'Credentials',
    'nav.contact': 'Contact',
    'hero.tagline': 'Tech Empowers Culture\nCulture Nourishes Tech',
    'hero.sub': 'Founded in 2019, ShanYin is a high-tech enterprise in Zhejiang. We integrate video processing technology with cultural exchange planning to deliver end-to-end solutions.',
    'form.name': 'Name',
    'form.email': 'Email',
    'form.message': 'Message',
    'form.submit': 'Submit',
  },
};

/**
 * ============================================================
 * 11. Hero 粒子背景 (Hero Particles)
 * ============================================================
 */
function initHeroParticles() {
  var container = $('.hero-particles');
  if (!container) return;

  var particleCount = 15;
  if (window.innerWidth < 768) {
    particleCount = 8;
  }

  for (var i = 0; i < particleCount; i++) {
    var particle = document.createElement('div');
    particle.className = 'particle';
    var size = Math.random() * 200 + 50;
    particle.style.width = size + 'px';
    particle.style.height = size + 'px';
    particle.style.left = Math.random() * 100 + '%';
    particle.style.top = Math.random() * 100 + '%';
    particle.style.animationDelay = (Math.random() * 20) + 's';
    particle.style.animationDuration = (Math.random() * 20 + 15) + 's';
    container.appendChild(particle);
  }
}

/**
 * ============================================================
 * 12. 图片懒加载 (Lazy Loading)
 * ============================================================
 */
function initLazyLoad() {
  var lazyEls = $$('[data-lazy]');
  if (lazyEls.length === 0) return;

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            var el = entry.target;
            var src = el.getAttribute('data-lazy');
            if (src) {
              if (el.tagName === 'IMG') {
                el.src = src;
              } else {
                el.style.backgroundImage = 'url(' + src + ')';
              }
              el.removeAttribute('data-lazy');
            }
            observer.unobserve(el);
          }
        });
      },
      { rootMargin: '100px' }
    );

    lazyEls.forEach(function (el) { observer.observe(el); });
  } else {
    // Fallback: 直接加载
    lazyEls.forEach(function (el) {
      var src = el.getAttribute('data-lazy');
      if (src) {
        if (el.tagName === 'IMG') {
          el.src = src;
        } else {
          el.style.backgroundImage = 'url(' + src + ')';
        }
      }
    });
  }
}

/**
 * ============================================================
 * 13. 打字机效果 (Typewriter Effect)
 * ============================================================
 */
function initTypewriter() {
  var els = $$('[data-typewriter]');
  els.forEach(function (el) {
    var texts = JSON.parse(el.getAttribute('data-typewriter') || '[]');
    var speed = parseInt(el.getAttribute('data-typewriter-speed')) || 100;
    if (texts.length === 0) return;
    typewriterLoop(el, texts, speed);
  });
}

function typewriterLoop(el, texts, speed) {
  var textIndex = 0;
  var charIndex = 0;
  var isDeleting = false;
  var currentText = '';

  function tick() {
    var fullText = texts[textIndex];

    if (!isDeleting) {
      currentText = fullText.substring(0, charIndex + 1);
      charIndex++;
      el.textContent = currentText;
      if (charIndex === fullText.length) {
        isDeleting = true;
        setTimeout(tick, 1500); // 停留时间
        return;
      }
      setTimeout(tick, speed);
    } else {
      currentText = fullText.substring(0, charIndex - 1);
      charIndex--;
      el.textContent = currentText;
      if (charIndex === 0) {
        isDeleting = false;
        textIndex = (textIndex + 1) % texts.length;
        setTimeout(tick, 500); // 切换间隔
        return;
      }
      setTimeout(tick, speed / 2);
    }
  }

  tick();
}

/**
 * ============================================================
 * 14. 滚动进度条 (Scroll Progress Bar)
 * ============================================================
 */
function initScrollProgressBar() {
  var bar = $('#scroll-progress-bar');
  if (!bar) {
    bar = document.createElement('div');
    bar.id = 'scroll-progress-bar';
    bar.style.cssText = [
      'position: fixed',
      'top: 0',
      'left: 0',
      'height: 2px',
      'background: var(--accent, #c27a30)',
      'z-index: 10001',
      'width: 0%',
      'transition: width 100ms linear',
    ].join(';');
    document.body.appendChild(bar);
  }

  function updateProgress() {
    var scrollTop = window.pageYOffset;
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    var progress = docHeight > 0 ? (scrollTop / docHeight) * 100 : 0;
    bar.style.width = progress + '%';
  }

  window.addEventListener('scroll', throttle(updateProgress, 50));
  updateProgress();
}

/**
 * ============================================================
 * 15. 合作伙伴 Logo 轮播 (Partners Carousel)
 * ============================================================
 */
function initPartnersCarousel() {
  var track = $('#partners-track');
  if (!track) return;

  var items = $$('.partner-logo', track);
  if (items.length === 0) return;

  // 自动滚动（可选功能，在宽屏下启用）
  if (window.innerWidth > 1024) {
    var scrollSpeed = 1; // px per frame
    var isPaused = false;

    track.addEventListener('mouseenter', function () { isPaused = true; });
    track.addEventListener('mouseleave', function () { isPaused = false; });

    function autoScroll() {
      if (!isPaused) {
        track.scrollLeft += scrollSpeed;
        if (track.scrollLeft >= track.scrollWidth / 2) {
          track.scrollLeft = 0;
        }
      }
      requestAnimationFrame(autoScroll);
    }

    // 克隆一半的 logo 实现无缝滚动
    items.forEach(function (item) {
      var clone = item.cloneNode(true);
      track.appendChild(clone);
    });

    requestAnimationFrame(autoScroll);
  }
}

/**
 * ============================================================
 * 16. 键盘无障碍 (Keyboard Accessibility)
 * ============================================================
 */
function initKeyboardAccessibility() {
  // ESC 关闭移动菜单
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      var navLinks = $('#nav-links');
      var navToggle = $('#nav-toggle');
      if (navLinks && navLinks.classList.contains('open')) {
        navLinks.classList.remove('open');
        if (navToggle) navToggle.classList.remove('active');
        document.body.classList.remove('no-scroll');
      }
    }
  });

  // Tab 焦点陷阱（移动菜单打开时）
  var navLinks = $('#nav-links');
  if (navLinks) {
    var focusableEls = $$('a, button, input, textarea, select', navLinks);
    if (focusableEls.length > 0) {
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Tab' && navLinks.classList.contains('open')) {
          var first = focusableEls[0];
          var last = focusableEls[focusableEls.length - 1];
          if (e.shiftKey) {
            if (document.activeElement === first) {
              e.preventDefault();
              last.focus();
            }
          } else {
            if (document.activeElement === last) {
              e.preventDefault();
              first.focus();
            }
          }
        }
      });
    }
  }
}

/**
 * ============================================================
 * 17. 统计面板 (Impact Stats Interaction)
 * ============================================================
 */
function initImpactStats() {
  var statEls = $$('[data-count]');
  if (statEls.length === 0) return;

  // 已通过 initCountUpAnimations 处理
  // 此处可扩展：点击统计项展示详情等
  statEls.forEach(function (el) {
    el.style.cursor = 'default';
  });
}

/**
 * ============================================================
 * 18. 媒体列表展开/折叠 (Media List Expand)
 * ============================================================
 */
function initMediaList() {
  var mediaItems = $$('.media-item');
  mediaItems.forEach(function (item) {
    var content = $('.media-content p', item);
    if (content && content.textContent.length > 120) {
      var fullText = content.textContent;
      var shortText = fullText.substring(0, 120) + '...';
      content.textContent = shortText;

      var expandBtn = document.createElement('button');
      expandBtn.className = 'media-expand-btn';
      expandBtn.textContent = '展开全文';
      expandBtn.style.cssText = 'font-size:12px;color:var(--accent);background:none;border:none;cursor:pointer;padding:0;margin-top:4px;';
      expandBtn.addEventListener('click', function () {
        if (content.textContent === shortText) {
          content.textContent = fullText;
          expandBtn.textContent = '收起';
        } else {
          content.textContent = shortText;
          expandBtn.textContent = '展开全文';
        }
      });
      item.appendChild(expandBtn);
    }
  });
}

/**
 * ============================================================
 * 19. 本地存储恢复 (LocalStorage Recovery)
 * ============================================================
 */
function initLocalStorageRecovery() {
  // 恢复表单输入
  var forms = $$('form[data-persist]');
  forms.forEach(function (form) {
    var formId = form.getAttribute('data-persist') || form.id || generateId();
    var storageKey = 'shanyin-form-' + formId;

    // 恢复
    try {
      var saved = localStorage.getItem(storageKey);
      if (saved) {
        var data = JSON.parse(saved);
        Object.keys(data).forEach(function (name) {
          var field = form.querySelector('[name="' + name + '"]');
          if (field) field.value = data[name];
        });
      }
    } catch (e) { /* 忽略解析错误 */ }

    // 监听输入并保存
    form.addEventListener('input', debounce(function () {
      var data = {};
      $$('input, textarea, select', form).forEach(function (field) {
        if (field.name && field.type !== 'password') {
          data[field.name] = field.value;
        }
      });
      try {
        localStorage.setItem(storageKey, JSON.stringify(data));
      } catch (e) { /* 忽略存储错误 */ }
    }, 500));

    // 提交后清除
    form.addEventListener('submit', function () {
      localStorage.removeItem(storageKey);
    });
  });

  // 恢复语言设置
  try {
    var savedLocale = localStorage.getItem('shanyin-locale');
    if (savedLocale && SITE_CONFIG.supportedLocales.indexOf(savedLocale) !== -1) {
      setLocale(savedLocale);
      var activeBtn = $('.lang-btn[data-locale="' + savedLocale + '"]');
      if (activeBtn) {
        $$('.lang-btn').forEach(function (b) { b.classList.remove('active'); });
        activeBtn.classList.add('active');
      }
    }
  } catch (e) { /* 忽略 */ }
}

/**
 * ============================================================
 * 20. 错误监控 (Error Monitoring - 基础版)
 * ============================================================
 */
function initErrorMonitoring() {
  window.addEventListener('error', function (e) {
    var errorInfo = {
      message: e.message || 'Unknown error',
      filename: e.filename || '',
      lineno: e.lineno || 0,
      colno: e.colno || 0,
      timestamp: new Date().toISOString(),
    };
    // 在生产环境中，此处可将 errorInfo 发送到日志服务
    console.warn('[ShanYin] Error captured:', errorInfo);
  });

  window.addEventListener('unhandledrejection', function (e) {
    console.warn('[ShanYin] Unhandled Promise rejection:', e.reason);
  });
}

/**
 * ============================================================
 * 21. 性能监控 (Performance Monitoring)
 * ============================================================
 */
function logPerformance() {
  if (!('performance' in window)) return;

  window.addEventListener('load', function () {
    setTimeout(function () {
      var perf = performance.getEntriesByType('navigation')[0];
      if (perf) {
        var loadTime = Math.round(perf.loadEventEnd - perf.navigationStart);
        var domReady = Math.round(perf.domContentLoadedEventEnd - perf.navigationStart);
        console.log(
          '[ShanYin] Performance: load=' + loadTime + 'ms, DOMReady=' + domReady + 'ms'
        );
      }
    }, 0);
  });
}

/**
 * ============================================================
 * 22. SEO 辅助 (Dynamic Meta Tags)
 * ============================================================
 */
function updateMetaTags(options) {
  options = options || {};
  var title = options.title;
  var description = options.description;
  var image = options.image;
  var url = options.url;

  if (title) document.title = title;

  function setMeta(property, content, isProperty) {
    var selector = isProperty
      ? 'meta[property="' + property + '"]'
      : 'meta[name="' + property + '"]';
    var meta = $(selector);
    if (meta) {
      meta.setAttribute('content', content);
    } else {
      meta = document.createElement('meta');
      if (isProperty) {
        meta.setAttribute('property', property);
      } else {
        meta.setAttribute('name', property);
      }
      meta.setAttribute('content', content);
      document.head.appendChild(meta);
    }
  }

  if (description) {
    setMeta('description', description, false);
    setMeta('og:description', description, true);
  }

  if (title) {
    setMeta('og:title', title, true);
    setMeta('twitter:title', title, true);
  }

  if (image) {
    setMeta('og:image', image, true);
    setMeta('twitter:image', image, true);
  }

  if (url) {
    setMeta('og:url', url, true);
  }
}

/**
 * ============================================================
 * 23. 初始化入口 (Initialization Entry Point)
 * ============================================================
 */
function init() {
  // 按依赖顺序初始化各模块
  var modules = [
    { name: 'LoadingScreen', fn: initLoadingScreen },
    { name: 'Navigation', fn: initNavigation },
    { name: 'RevealAnimations', fn: initRevealAnimations },
    { name: 'BackToTop', fn: initBackToTop },
    { name: 'CountUp', fn: initCountUpAnimations },
    { name: 'ProjectFilter', fn: initProjectFilter },
    { name: 'Forms', fn: initForms },
    { name: 'LanguageSwitcher', fn: initLanguageSwitcher },
    { name: 'HeroParticles', fn: initHeroParticles },
    { name: 'LazyLoad', fn: initLazyLoad },
    { name: 'Typewriter', fn: initTypewriter },
    { name: 'ScrollProgressBar', fn: initScrollProgressBar },
    { name: 'PartnersCarousel', fn: initPartnersCarousel },
    { name: 'KeyboardA11y', fn: initKeyboardAccessibility },
    { name: 'ImpactStats', fn: initImpactStats },
    { name: 'MediaList', fn: initMediaList },
    { name: 'LocalStorage', fn: initLocalStorageRecovery },
    { name: 'ErrorMonitoring', fn: initErrorMonitoring },
  ];

  modules.forEach(function (module) {
    try {
      module.fn();
    } catch (e) {
      console.warn('[ShanYin] Module ' + module.name + ' failed:', e);
    }
  });

  // 性能日志（开发模式）
  if (
    location.hostname === 'localhost' ||
    location.hostname === '127.0.0.1'
  ) {
    logPerformance();
  }

  console.log('%c善因科技文化 %c官网系统 v2.0',
    'font-family: serif; font-size: 14px; color: #c27a30; font-weight: bold;',
    'font-size: 10px; color: #999;'
  );
}

/**
 * ============================================================
 * 24. DOM Ready 监听
 * ============================================================
 */
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

/**
 * ============================================================
 * END OF script.js
 * ============================================================
 */
