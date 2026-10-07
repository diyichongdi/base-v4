/* ============================================
   基地 v4 · 全局脚本
   卡片风格切换 / 暗色模式 / 数字动画 / 弹窗 / Toast
   ============================================ */
(function () {
    'use strict';

    /* ============================================================
       卡片设计体系控制器
       frost 雾面玻璃 / aurora 极光流光 / particles 星域粒子 /
       blueprint 工程蓝图；html[data-fx-active] + html.fx-dark 驱动
       ============================================================ */
    var FXS = ['frost', 'aurora', 'particles', 'blueprint'];
    var FX_NAME = { frost: '雾面玻璃', aurora: '极光流光', particles: '星域粒子', blueprint: '工程蓝图' };
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var fx = 'frost';
    try { fx = localStorage.getItem('bm-cardfx') || 'frost'; } catch (e) {}
    if (FXS.indexOf(fx) === -1) fx = 'frost';

    var darkOn = false;
    try { darkOn = localStorage.getItem('bm-fxdark') === '1'; } catch (e) {}

    function applyFx() {
        var h = document.documentElement;
        h.setAttribute('data-fx-active', fx);
        h.classList.toggle('fx-dark', darkOn);
        document.querySelectorAll('[data-fx-opt]').forEach(function (el) {
            var active = el.getAttribute('data-fx-opt') === fx;
            el.classList.toggle('active', active);
            var check = el.querySelector('.theme-check');
            if (check) check.style.display = active ? '' : 'none';
        });
        document.querySelectorAll('[data-dark-switch]').forEach(function (sw) {
            sw.classList.toggle('on', darkOn);
        });
        if (fx === 'particles') { pcBuild(); pcEngines.forEach(pcSize); pcStart(); } else { pcPause(); }
        if (fx === 'blueprint') bpEnsureDims();
    }

    function setFx(v) {
        if (FXS.indexOf(v) === -1) return;
        fx = v;
        try { localStorage.setItem('bm-cardfx', v); } catch (e) {}
        applyFx();
        toast('卡片风格已切换为「' + FX_NAME[v] + '」', 'success');
    }

    function setDark(on) {
        darkOn = !!on;
        try { localStorage.setItem('bm-fxdark', darkOn ? '1' : '0'); } catch (e) {}
        applyFx();
    }

    document.addEventListener('click', function (e) {
        var opt = e.target.closest('[data-fx-opt]');
        if (opt) setFx(opt.getAttribute('data-fx-opt'));
        var sw = e.target.closest('[data-dark-switch]');
        if (sw) setDark(!darkOn);
    });

    /* ---------- SVG 滤镜注入（雾面玻璃边缘折射用） ---------- */
    function injectSvgFilters() {
        if (document.getElementById('lg-distort-low')) return;
        var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', '0');
        svg.setAttribute('height', '0');
        svg.style.position = 'absolute';
        svg.setAttribute('aria-hidden', 'true');
        svg.innerHTML =
            '<filter id="lg-distort-low" x="-20%" y="-20%" width="140%" height="140%">' +
            '<feTurbulence type="fractalNoise" baseFrequency="0.012 0.02" numOctaves="2" seed="7" result="n">' +
            '<animate attributeName="baseFrequency" dur="18s" values="0.012 0.02;0.014 0.015;0.012 0.02" repeatCount="indefinite"/>' +
            '</feTurbulence>' +
            '<feDisplacementMap in="SourceGraphic" in2="n" scale="2"/>' +
            '</filter>';
        document.body.appendChild(svg);
    }

    /* ============================================================
       星域粒子：卡片内粒子网络引擎
       每卡独立小画布；单一 rAF 循环、30fps 上限、DPR≤1.25、
       离屏卡片跳过、标签页隐藏暂停
       ============================================================ */
    var pcEngines = [];
    var pcRaf = 0, pcRunning = false, pcLast = 0;
    var PC_PAL_DARK = ['255,255,255', '255,255,255', '100,210,255', '100,210,255', '160,120,255'];
    var PC_PAL_LIGHT = ['96,112,214', '70,88,190', '140,96,224', '52,72,168', '176,128,255'];
    var PC_SPRITES = { dark: null, light: null };

    function pcIsDark() { return document.documentElement.classList.contains('fx-dark'); }
    function pcPal() { return pcIsDark() ? PC_PAL_DARK : PC_PAL_LIGHT; }

    function pcSprites() {
        var mode = pcIsDark() ? 'dark' : 'light';
        if (PC_SPRITES[mode]) return PC_SPRITES[mode];
        PC_SPRITES[mode] = pcPal().map(function (rgb) {
            var s = document.createElement('canvas');
            s.width = s.height = 32;
            var c = s.getContext('2d');
            var g = c.createRadialGradient(16, 16, 0, 16, 16, 16);
            g.addColorStop(0, 'rgba(' + rgb + ',1)');
            g.addColorStop(0.3, 'rgba(' + rgb + ',.5)');
            g.addColorStop(1, 'rgba(' + rgb + ',0)');
            c.fillStyle = g;
            c.fillRect(0, 0, 32, 32);
            return s;
        });
        return PC_SPRITES[mode];
    }

    function pcSize(eng) {
        var r = eng.card.getBoundingClientRect();
        if (r.width < 10 || r.height < 10) return;
        var dpr = Math.min(window.devicePixelRatio || 1, 1.25);
        eng.w = r.width; eng.h = r.height;
        eng.cv.width = Math.round(r.width * dpr);
        eng.cv.height = Math.round(r.height * dpr);
        eng.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        var n = Math.max(6, Math.min(14, Math.round(r.width * r.height / 9000)));
        eng.nodes = [];
        for (var i = 0; i < n; i++) {
            eng.nodes.push({
                x: Math.random() * r.width,
                y: Math.random() * r.height,
                vx: (Math.random() - .5) * .22,
                vy: (Math.random() - .5) * .22,
                r: .9 + Math.random() * 1.2,
                si: (Math.random() * 5) | 0
            });
        }
    }

    function pcBuild() {
        if (reduced) return;
        document.querySelectorAll('.lg-card').forEach(function (card) {
            if (card.querySelector('.pc-net')) return;
            var cv = document.createElement('canvas');
            cv.className = 'pc-net';
            cv.setAttribute('aria-hidden', 'true');
            card.appendChild(cv);
            var eng = { card: card, cv: cv, ctx: cv.getContext('2d'), nodes: [], w: 0, h: 0, mx: -9999, my: -9999, vis: true, rect: null };
            card.addEventListener('pointerenter', function () { eng.rect = card.getBoundingClientRect(); });
            card.addEventListener('pointermove', function (e) {
                var r = eng.rect || card.getBoundingClientRect();
                eng.mx = e.clientX - r.left; eng.my = e.clientY - r.top;
            });
            card.addEventListener('pointerleave', function () { eng.mx = -9999; eng.my = -9999; });
            pcEngines.push(eng);
            pcSize(eng);
            if ('IntersectionObserver' in window) {
                new IntersectionObserver(function (en) { eng.vis = en[0].isIntersecting; }, { rootMargin: '40px' }).observe(card);
            }
        });
    }

    function pcDraw(eng) {
        var ctx = eng.ctx, w = eng.w, h = eng.h;
        if (!w || !h) return;
        var nodes = eng.nodes, i, j, a, b, dx, dy, d2, d;
        ctx.clearRect(0, 0, w, h);
        var LINK = Math.min(90, Math.max(52, Math.min(w, h) * .42));
        for (i = 0; i < nodes.length; i++) {
            a = nodes[i];
            a.x += a.vx; a.y += a.vy;
            if (a.x < -8) a.x = w + 8; else if (a.x > w + 8) a.x = -8;
            if (a.y < -8) a.y = h + 8; else if (a.y > h + 8) a.y = -8;
            dx = eng.mx - a.x; dy = eng.my - a.y;
            d2 = dx * dx + dy * dy;
            if (d2 < 22500 && d2 > 0.001) {
                d = Math.sqrt(d2);
                a.vx += dx / d * .01; a.vy += dy / d * .01;
                var sp2 = a.vx * a.vx + a.vy * a.vy;
                if (sp2 > .09) { var k = .3 / Math.sqrt(sp2); a.vx *= k; a.vy *= k; }
            }
        }
        ctx.lineWidth = 1;
        for (i = 0; i < nodes.length; i++) {
            a = nodes[i];
            for (j = i + 1; j < nodes.length; j++) {
                b = nodes[j];
                dx = a.x - b.x; dy = a.y - b.y;
                if (dx > LINK || dx < -LINK || dy > LINK || dy < -LINK) continue;
                d2 = dx * dx + dy * dy;
                if (d2 < LINK * LINK) {
                    var lc = pcIsDark() ? '126,166,255' : '96,116,210';
                    ctx.strokeStyle = 'rgba(' + lc + ',' + ((1 - Math.sqrt(d2) / LINK) * .28).toFixed(3) + ')';
                    ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
                }
            }
        }
        var spr = pcSprites();
        if (pcIsDark()) ctx.globalCompositeOperation = 'lighter';
        for (i = 0; i < nodes.length; i++) {
            a = nodes[i];
            var sz = a.r * 6;
            ctx.drawImage(spr[a.si], a.x - sz / 2, a.y - sz / 2, sz, sz);
        }
        ctx.globalCompositeOperation = 'source-over';
    }

    function pcLoop(ts) {
        if (!pcRunning) return;
        pcRaf = requestAnimationFrame(pcLoop);
        if (ts - pcLast < 33) return;
        pcLast = ts;
        for (var i = 0; i < pcEngines.length; i++) {
            if (pcEngines[i].vis) pcDraw(pcEngines[i]);
        }
    }
    function pcStart() {
        if (reduced || !pcRunning) { if (!reduced) { pcRunning = true; pcRaf = requestAnimationFrame(pcLoop); } }
    }
    function pcPause() {
        pcRunning = false;
        if (pcRaf) cancelAnimationFrame(pcRaf);
    }
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) pcPause();
        else if (fx === 'particles') pcStart();
    });
    window.addEventListener('resize', function () {
        pcEngines.forEach(function (eng) { pcSize(eng); eng.rect = null; });
    }, { passive: true });

    /* ============================================================
       工程蓝图：尺寸标注线 + 点击测量脉冲
       ============================================================ */
    function bpEnsureDims() {
        document.querySelectorAll('.lg-card').forEach(function (card) {
            if (card.querySelector('.bp-dim')) return;
            var dim = document.createElement('span');
            dim.className = 'bp-dim';
            dim.innerHTML = '<i></i>';
            card.appendChild(dim);
        });
        bpMeasure();
    }
    function bpMeasure() {
        document.querySelectorAll('.bp-dim').forEach(function (dim) {
            var w = Math.round(dim.parentElement.getBoundingClientRect().width);
            dim.querySelector('i').textContent = 'W=' + w + 'PX';
        });
    }
    document.addEventListener('click', function (e) {
        if (fx !== 'blueprint' || reduced) return;
        var card = e.target.closest('.lg-card');
        if (!card) return;
        var r = card.getBoundingClientRect();
        var mark = document.createElement('span');
        mark.className = 'bp-mark';
        mark.style.left = (e.clientX - r.left) + 'px';
        mark.style.top = (e.clientY - r.top) + 'px';
        card.appendChild(mark);
        mark.addEventListener('animationend', function () { mark.remove(); });
    });

    /* ============================================================
       指针交互：高光光源 + 轻倾斜（frost / aurora）
       ============================================================ */
    function bindCardPointer() {
        document.querySelectorAll('.lg-card').forEach(function (card) {
            if (card._lgbound) return;
            card._lgbound = true;
            card.addEventListener('pointermove', function (e) {
                var r = card.getBoundingClientRect();
                var px = (e.clientX - r.left) / r.width;
                var py = (e.clientY - r.top) / r.height;
                card.style.setProperty('--mx', (px * 100).toFixed(2) + '%');
                card.style.setProperty('--my', (py * 100).toFixed(2) + '%');
                var max = parseFloat(getComputedStyle(card).getPropertyValue('--fx-tilt')) || 0;
                if (max > 0 && !reduced) {
                    card.style.setProperty('--rx', ((py - .5) * -2 * max).toFixed(2) + 'deg');
                    card.style.setProperty('--ry', ((px - .5) * 2 * max).toFixed(2) + 'deg');
                }
            });
            card.addEventListener('pointerleave', function () {
                card.style.setProperty('--rx', '0deg');
                card.style.setProperty('--ry', '0deg');
            });
        });
    }

    /* reveal 动画结束后清除，避免 fill:both 长期压制 transform */
    document.addEventListener('animationend', function (e) {
        if (e.animationName === 'fadeUp' && e.target && e.target.style) {
            e.target.style.animation = 'none';
        }
    }, true);

    /* ---------- 数字滚动动画 ---------- */
    function animateCount(el) {
        var target = parseFloat(el.getAttribute('data-count') || el.textContent || '0');
        var duration = parseInt(el.getAttribute('data-duration') || '800', 10);
        var decimals = (el.getAttribute('data-decimals') || '0');
        var hasDot = String(target).indexOf('.') !== -1;
        if (decimals === 'auto') decimals = hasDot ? 2 : 0;
        var start = null;
        function step(ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / duration, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = (target * eased).toFixed(decimals);
            if (p < 1) requestAnimationFrame(step);
            else el.textContent = String(target);
        }
        requestAnimationFrame(step);
    }

    /* ---------- Toast ---------- */
    function toast(msg, type) {
        type = type || 'info';
        var wrap = document.querySelector('.toast-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'toast-wrap';
            document.body.appendChild(wrap);
        }
        var t = document.createElement('div');
        t.className = 'toast ' + type;
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; }, 2600);
        setTimeout(function () { t.remove(); }, 3000);
    }

    /* ---------- 弹窗 ---------- */
    function openModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.add('show');
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('show');
    }
    document.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('modal-mask')) {
            e.target.classList.remove('show');
        }
        var x = e.target.closest('[data-modal-x]');
        if (x) closeModal(x.getAttribute('data-modal-x'));
        var opener = e.target.closest('[data-modal]');
        if (opener) openModal(opener.getAttribute('data-modal'));
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-mask.show').forEach(function (m) { m.classList.remove('show'); });
        }
    });

    /* ---------- 表单提交 loading ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var btn = form.querySelector('[type="submit"]');
        if (btn && !btn.disabled) {
            setTimeout(function () { btn.disabled = true; btn.style.opacity = '0.6'; }, 50);
        }
    });

    /* ---------- 密码强度条 ---------- */
    function bindStrength(inputId, barId) {
        var input = document.getElementById(inputId);
        var bar = document.getElementById(barId);
        if (!input || !bar) return;
        input.addEventListener('input', function () {
            var p = input.value;
            var s = 0;
            if (p.length >= 8) s++;
            if (/[A-Z]/.test(p)) s++;
            if (/[a-z]/.test(p)) s++;
            if (/[0-9]/.test(p)) s++;
            if (/[^A-Za-z0-9]/.test(p)) s++;
            bar.className = 'strength-fill';
            if (s <= 2) bar.classList.add('weak');
            else if (s <= 4) bar.classList.add('mid');
            else bar.classList.add('strong');
        });
    }

    /* ---------- 滚动显现 ---------- */
    var revealEls = document.querySelectorAll('.card-plain, .module-card, .stat-card, .list-card');
    if (revealEls.length && 'IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) {
                    var idx = 0;
                    if (en.target.parentNode) {
                        Array.prototype.forEach.call(en.target.parentNode.children, function (c, i) {
                            if (c === en.target) { idx = i; }
                        });
                    }
                    en.target.style.animation = 'fadeUp .5s ease both';
                    en.target.style.animationDelay = Math.min(idx * 0.05, 0.6).toFixed(2) + 's';
                    io.unobserve(en.target);
                }
            });
        }, { threshold: 0.08 });
        revealEls.forEach(function (el) { io.observe(el); });
    }

    /* ---------- 聊天：移动端显示会话列表 ---------- */
    var chatBack = document.querySelector('[data-chat-back]');
    if (chatBack) {
        chatBack.addEventListener('click', function () {
            document.querySelectorAll('.chat-list').forEach(function (l) { l.classList.toggle('open'); });
        });
    }

    /* ---------- 初始化 ---------- */
    injectSvgFilters();
    bindCardPointer();

    var resizeT;
    window.addEventListener('resize', function () {
        clearTimeout(resizeT);
        resizeT = setTimeout(function () {
            pcEngines.forEach(pcSize);
            if (fx === 'blueprint') bpMeasure();
        }, 200);
    });

    document.querySelectorAll('[data-count]').forEach(animateCount);

    applyFx();

    window.bmTheme = { toast: toast, openModal: openModal, closeModal: closeModal, animateCount: animateCount, bindStrength: bindStrength };
    window.bmFx = { set: setFx, setDark: setDark };
})();

/* ---------- 下拉菜单触屏/键盘支持（hover 之外的打开方式） ---------- */
(function () {
    var SEL = '.navbar .nav-item, .navbar .user-menu-wrap, .navbar .theme-menu-wrap';
    function roots() {
        return Array.prototype.slice.call(document.querySelectorAll(SEL));
    }
    function closeAll(except) {
        roots().forEach(function (r) { if (r !== except) r.classList.remove('is-open'); });
    }
    document.addEventListener('click', function (e) {
        var t = e.target;
        var wrap = t.closest ? t.closest(SEL) : null;
        if (!wrap) { closeAll(); return; }
        var drop = wrap.querySelector(':scope > .dropdown');
        var caret = wrap.querySelector(':scope > .nav-caret');
        /* 独立箭头：点击箭头只做展开/收起，不干扰链接 */
        if (caret && (caret === t || caret.contains(t))) {
            var caretWasOpen = wrap.classList.contains('is-open');
            closeAll(wrap);
            if (!caretWasOpen) {
                if (e && typeof e.preventDefault === 'function') e.preventDefault();
                wrap.classList.add('is-open');
            }
            return;
        }
        var inTrigger = wrap.querySelector(':scope > .nav-link, :scope > .icon-btn, :scope > .user-chip');
        if (inTrigger && (inTrigger === t || inTrigger.contains(t))) {
            /* 有独立箭头的项：链接点击直达（已开则收起并阻止），箭头负责展开 */
            if (caret) {
                if (wrap.classList.contains('is-open')) { closeAll(); e.preventDefault(); }
                return;
            }
            /* 无下拉的导航项（如 即时通讯/暗网导航）：直接放行链接跳转 */
            if (!drop) return;
            var wasOpen = wrap.classList.contains('is-open');
            closeAll(wrap);
            if (!wasOpen) {
                if (e && typeof e.preventDefault === 'function') e.preventDefault();
                wrap.classList.add('is-open');
            }
            return;
        }
        if (!drop || !drop.contains(t)) closeAll();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll();
    });
})();

/* ---------- 移动端汉堡菜单（E.1） ---------- */
(function () {
    var toggle = document.querySelector('[data-nav-toggle]');
    var drawer = document.getElementById('navDrawer');
    if (!toggle || !drawer) return;

    function open() {
        toggle.setAttribute('aria-expanded', 'true');
        drawer.classList.add('open');
    }
    function close() {
        toggle.setAttribute('aria-expanded', 'false');
        drawer.classList.remove('open');
    }

    toggle.addEventListener('click', function () {
        if (drawer.classList.contains('open')) close();
        else open();
    });
    drawer.addEventListener('click', function (e) {
        if (e.target.closest('a')) close();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') close();
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) close();
    });
})();

/* ============================================================
   语言切换：localStorage 持久化 + 首次访问回填（F.6）
   ============================================================ */
(function () {
    var KEY = 'site-lang';
    var SUPPORTED = ['zh', 'en'];
    function currentLang() {
        return (document.documentElement.lang || '').split('-')[0];
    }
    function applyPersist() {
        var saved = '', cur = currentLang();
        if (cur !== 'zh' && cur !== 'en') cur = 'zh';
        try { saved = localStorage.getItem(KEY); } catch (err) {}
        if (saved === cur) return;
        if (SUPPORTED.indexOf(saved) === -1) return;
        try { localStorage.removeItem(KEY); } catch (err) {}
        var u = new URL(window.location.href);
        u.searchParams.set('lang', saved);
        window.location.replace(u.toString());
    }
    function wireDynamicLinks() {
        if (document.addEventListener) {
            document.addEventListener('click', function (e) {
                var a = e.target && e.target.closest ? e.target.closest('a.lang-switch') : null;
                if (!a) return;
                var m = /[?&]lang=(zh|en)/.exec(a.getAttribute('href') || '');
                if (!m) return;
                try { localStorage.setItem(KEY, m[1]); } catch (err) {}
            });
        }
    }
    wireDynamicLinks();
    applyPersist();
})();
