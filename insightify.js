// ═══════════════════════════════════════════════════════════
//  insightify.js — Auth + Landing Page Logic
// ═══════════════════════════════════════════════════════════

// ── MODAL ────────────────────────────────────────────────────
function showAuth(tab = 'login') {
    switchTab(tab);
    document.getElementById('auth-overlay').classList.add('active');
}

function closeAuth() {
    document.getElementById('auth-overlay').classList.remove('active');
    clearErrors();
}

function closeAuthOnBg(e) {
    if (e.target === document.getElementById('auth-overlay')) closeAuth();
}

function switchTab(tab) {
    document.getElementById('form-login').classList.toggle('active',  tab === 'login');
    document.getElementById('form-signup').classList.toggle('active', tab === 'signup');
    document.getElementById('tab-login').classList.toggle('active',   tab === 'login');
    document.getElementById('tab-signup').classList.toggle('active',  tab === 'signup');
    clearErrors();
}

function togglePw(id, btn) {
    const input = document.getElementById(id);
    input.type      = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
}

// ── FORM HELPERS ─────────────────────────────────────────────
function clearErrors() {
    document.querySelectorAll('.field-error').forEach(el => el.textContent = '');
    document.querySelectorAll('.form-group input').forEach(el => el.classList.remove('error'));
    document.querySelectorAll('.auth-msg').forEach(el => { el.className = 'auth-msg'; el.textContent = ''; });
}

function setError(fieldId, msg) {
    const el    = document.getElementById('err-' + fieldId);
    const input = document.getElementById(fieldId);
    if (el)    el.textContent = msg;
    if (input) input.classList.add('error');
}

function showMsg(id, msg, type) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.className   = 'auth-msg ' + type;
}

// ── LOGIN ─────────────────────────────────────────────────────
async function submitLogin() {
    clearErrors();
    const email    = document.getElementById('login-email').value.trim();
    const password = document.getElementById('login-password').value;
    let valid = true;

    if (!email)                            { setError('login-email',    'Email is required');    valid = false; }
    else if (!/\S+@\S+\.\S+/.test(email)) { setError('login-email',    'Enter a valid email');  valid = false; }
    if (!password)                         { setError('login-password', 'Password is required'); valid = false; }
    if (!valid) return;

    const btn = document.getElementById('btn-login');
    btn.disabled    = true;
    btn.textContent = 'Logging in…';

    try {
        const res  = await fetch('/backend/auth.php', {
            method:      'POST',
            credentials: 'include',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ action: 'login', email, password })
        });
        const data = await res.json();

        if (data.success) {
            showMsg('login-msg', '✓ Welcome back, ' + (data.user.firstname || data.user.name) + '!', 'success');
            // Store full user info in localStorage for dashboard greeting
            localStorage.setItem('user_name',      data.user.name);
            localStorage.setItem('user_firstname',  data.user.firstname || '');
            localStorage.setItem('user_lastname',   data.user.lastname  || '');
            setTimeout(() => {
                closeAuth();
                setLoggedIn(data.user);
                window.location.href = 'dashboard.html';
            }, 900);
            return;
        } else {
            showMsg('login-msg', data.message || 'Invalid email or password.', 'error');
        }
    } catch (err) {
        showMsg('login-msg', 'Connection error. Please try again.', 'error');
    }

    btn.disabled    = false;
    btn.textContent = 'Login';
}

// ── SIGNUP ────────────────────────────────────────────────────
async function submitSignup() {
    clearErrors();
    const firstname = document.getElementById('signup-firstname').value.trim();
    const lastname  = document.getElementById('signup-lastname').value.trim();
    const email     = document.getElementById('signup-email').value.trim();
    const password  = document.getElementById('signup-password').value;
    const confirm   = document.getElementById('signup-confirm').value;
    let valid = true;

    if (!firstname)                         { setError('signup-firstname', 'First name required');    valid = false; }
    if (!lastname)                          { setError('signup-lastname',  'Last name required');     valid = false; }
    if (!email)                             { setError('signup-email',     'Email is required');      valid = false; }
    else if (!/\S+@\S+\.\S+/.test(email))  { setError('signup-email',     'Enter a valid email');    valid = false; }
    if (!password)                          { setError('signup-password',  'Password is required');   valid = false; }
    else if (password.length < 6)           { setError('signup-password',  'Minimum 6 characters');   valid = false; }
    if (password !== confirm)               { setError('signup-confirm',   'Passwords do not match'); valid = false; }
    if (!valid) return;

    const btn = document.getElementById('btn-signup');
    btn.disabled    = true;
    btn.textContent = 'Creating account…';

    try {
        const res  = await fetch('/backend/auth.php', {
            method:      'POST',
            credentials: 'include',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ action: 'signup', firstname, lastname, email, password })
        });
        const data = await res.json();

        if (data.success) {
            showMsg('signup-msg', '✓ Account created! Redirecting…', 'success');
            // Store full user info in localStorage for dashboard greeting
            localStorage.setItem('user_name',      data.user.name);
            localStorage.setItem('user_firstname',  data.user.firstname || '');
            localStorage.setItem('user_lastname',   data.user.lastname  || '');
            setTimeout(() => {
                closeAuth();
                setLoggedIn(data.user);
                window.location.href = 'dashboard.html';
            }, 1000);
            return;
        } else {
            showMsg('signup-msg', data.message || 'Signup failed. Try again.', 'error');
        }
    } catch (err) {
        showMsg('signup-msg', 'Connection error. Please try again.', 'error');
    }

    btn.disabled    = false;
    btn.textContent = 'Create Account';
}

// ── SESSION UI ────────────────────────────────────────────────
function setLoggedIn(user) {
    const navAuth = document.getElementById('nav-auth');
    const navUser = document.getElementById('nav-user');
    if (navAuth) navAuth.classList.add('hidden');
    if (navUser) { navUser.classList.remove('hidden'); navUser.style.display = 'flex'; }

    const navUsername = document.getElementById('nav-username');
    if (navUsername) navUsername.textContent = user.firstname || user.name || '';

    const initials  = ((user.firstname?.[0] || '') + (user.lastname?.[0] || '')).toUpperCase() || '?';
    const navAvatar = document.getElementById('nav-avatar');
    if (navAvatar) navAvatar.textContent = initials;
}

// ── LOGOUT ────────────────────────────────────────────────────
async function logout() {
    try {
        await fetch('/backend/auth.php', {
            method:      'POST',
            credentials: 'include',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ action: 'logout' })
        });
    } catch (_) {}

    // Clear localStorage on logout
    localStorage.removeItem('user_name');
    localStorage.removeItem('user_firstname');
    localStorage.removeItem('user_lastname');

    const navUser = document.getElementById('nav-user');
    const navAuth = document.getElementById('nav-auth');
    if (navUser) { navUser.classList.add('hidden'); navUser.style.display = 'none'; }
    if (navAuth) navAuth.classList.remove('hidden');

    if (window.location.href.includes('dashboard')) {
        window.location.href = 'index.html';
    }
}

const doLogout = logout;

// ── CHECK SESSION ON LOAD ─────────────────────────────────────
// FIX: Now properly reads session without destroying it.
// The bug was that auth.php merged 'check' and 'logout' into one block,
// so every checkSession() call wiped the session — causing all N/A data.
async function checkSession() {
    try {
        const res  = await fetch('/backend/auth.php', {
            method:      'POST',
            credentials: 'include',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ action: 'check' })
        });
        const data = await res.json();
        if (data.logged_in) {
            const user = data.user || {};
            // Keep localStorage in sync with server session
            localStorage.setItem('user_name',     user.name      || '');
            localStorage.setItem('user_firstname', user.firstname || '');
            localStorage.setItem('user_lastname',  user.lastname  || '');
            setLoggedIn(user);
        }
    } catch (_) {}
}

// ── HELPERS ───────────────────────────────────────────────────
function isEmpty(val) {
    return val === null || val === undefined || Number(val) === 0 || val === 'N/A';
}

function emptyText(el, val, formatted) {
    if (!el) return;
    el.textContent   = isEmpty(val) ? 'No data yet' : formatted;
    el.style.opacity = isEmpty(val) ? '0.35' : '1';
}

// ── INSIGHTS FETCH ────────────────────────────────────────────
async function fetchInsights() {
    try {
        const res  = await fetch('/backend/insights.php', { credentials: 'include' });
        const data = await res.json();

        const rev   = data.kpi?.revenue || {};
        const stats = data.stats        || {};
        const trend = data.sales_trend  || [];
        const prods = data.top_products || [];

        const dir    = rev.direction || 'up';
        const noData = parseFloat(rev.total || 0) === 0;

        emptyText(document.getElementById('kpi-revenue'),   parseFloat(rev.total || 0), '₱' + (rev.total || 0));
        emptyText(document.getElementById('trend-revenue'), parseFloat(rev.total || 0), '₱' + (rev.total || 0));

        const kpiChange = document.getElementById('kpi-change');
        if (kpiChange) {
            kpiChange.textContent = noData
                ? 'No transactions yet'
                : (dir === 'up' ? '▲ ' : '▼ ') + Math.abs(rev.change_pct || 0) + '% vs last month';
            kpiChange.className = 'hc-change' + (noData ? '' : ' ' + dir);
        }

        const badge = document.getElementById('trend-badge');
        if (badge) {
            badge.textContent = noData
                ? '— %'
                : (dir === 'up' ? '▲ ' : '▼ ') + Math.abs(rev.change_pct || 0) + '%';
            badge.className = 'trend-badge' + (dir === 'down' ? ' down' : '');
        }

        emptyText(document.getElementById('m-users'),  stats.active_users,              Number(stats.active_users || 0).toLocaleString());
        emptyText(document.getElementById('m-txns'),   stats.txns_today,                String(stats.txns_today || 0));
        emptyText(document.getElementById('m-avg'),    parseFloat(stats.avg_order || 0), '₱' + Number(stats.avg_order || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        emptyText(document.getElementById('m-return'), stats.return_rate,               (stats.return_rate || 0) + '%');

        const mPeak = document.getElementById('m-peak');
        if (mPeak) mPeak.textContent = isEmpty(stats.txns_today) ? 'Peak: —' : 'Peak: ' + (stats.peak_hour || '—');

        emptyText(document.getElementById('kpi-peak'), stats.txns_today, stats.peak_hour || '—');

        const statTxns = document.getElementById('stat-txns');
        if (statTxns) statTxns.textContent = stats.txns_today || '0';

        const sparkEl  = document.getElementById('sparkline');
        const labelsEl = document.getElementById('spark-labels');
        if (sparkEl && labelsEl) {
            sparkEl.innerHTML = labelsEl.innerHTML = '';
            if (!trend.length) {
                sparkEl.innerHTML = '<div style="width:100%;text-align:center;color:rgba(116,198,157,0.35);font-size:11px;font-family:monospace;padding:14px 0;">No sales data yet</div>';
            } else {
                const maxVal = Math.max(...trend.map(m => m.actual || 0)) || 1;
                trend.forEach((m, i) => {
                    const bar = document.createElement('div');
                    bar.className    = 'spark-bar' + (i === trend.length - 1 ? ' highlight' : '');
                    bar.style.height = Math.max(5, Math.round(((m.actual || 0) / maxVal) * 100)) + '%';
                    sparkEl.appendChild(bar);
                });
                [0, Math.floor(trend.length / 4), Math.floor(trend.length / 2), Math.floor(trend.length * 3 / 4), trend.length - 1]
                    .filter((v, i, a) => a.indexOf(v) === i && trend[v])
                    .forEach(idx => {
                        const lbl = document.createElement('span');
                        lbl.className   = 'spark-lbl';
                        lbl.textContent = trend[idx].label;
                        labelsEl.appendChild(lbl);
                    });
            }
        }

        const prodEl = document.getElementById('top-products');
        if (prodEl) {
            if (!prods.length) {
                prodEl.innerHTML = '<div style="text-align:center;color:rgba(116,198,157,0.35);font-size:11px;font-family:monospace;padding:14px 0;">No product sales yet</div>';
                emptyText(document.getElementById('kpi-top-product'), null, '');
                const kpiTopUnits = document.getElementById('kpi-top-units');
                if (kpiTopUnits) { kpiTopUnits.textContent = '— units sold'; kpiTopUnits.style.opacity = '0.35'; }
            } else {
                prodEl.innerHTML = prods.map((p, i) => `
                    <div class="product-row">
                        <span class="product-rank">#${i + 1}</span>
                        <div class="product-info">
                            <div class="product-name">${p.name}</div>
                            <div class="product-bar-wrap">
                                <div class="product-bar-fill" style="width:${p.pct}%"></div>
                            </div>
                        </div>
                        <span class="product-units">${p.units_sold} units</span>
                    </div>`).join('');
                emptyText(document.getElementById('kpi-top-product'), 1, prods[0].name);
                const kpiTopUnits = document.getElementById('kpi-top-units');
                if (kpiTopUnits) { kpiTopUnits.textContent = '▲ ' + prods[0].units_sold + ' units sold'; kpiTopUnits.style.opacity = '1'; }
            }
        }

        const barsEl = document.getElementById('kpi-bars');
        if (barsEl) {
            barsEl.innerHTML = '';
            if (!trend.length) {
                barsEl.innerHTML = '<div style="width:100%;text-align:center;color:rgba(45,106,79,0.4);font-size:10px;font-family:monospace;align-self:center;">No data yet</div>';
            } else {
                const maxVal = Math.max(...trend.map(m => m.actual || 0)) || 1;
                trend.slice(-7).forEach(m => {
                    const bar = document.createElement('div');
                    bar.className    = 'hc-bar';
                    bar.style.height = Math.max(5, Math.round(((m.actual || 0) / maxVal) * 100)) + '%';
                    barsEl.appendChild(bar);
                });
            }
        }

        const lastUpdated = document.getElementById('last-updated');
        if (lastUpdated) lastUpdated.textContent = 'updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    } catch (err) {
        console.error('fetchInsights error:', err);
        const lastUpdated = document.getElementById('last-updated');
        if (lastUpdated) lastUpdated.textContent = 'connection error — retrying…';
    }
}

// ── INIT ──────────────────────────────────────────────────────
checkSession();

if (!document.querySelector('.sidebar')) {
    fetchInsights();
    setInterval(fetchInsights, 30000);
}

// ═══════════════════════════════════════════════════
//  REVIEWS
// ═══════════════════════════════════════════════════

let _reviewRating = 0;
const _ratingLabels = ['', 'Poor', 'Fair', 'Good', 'Great', 'Excellent!'];

function setReviewRating(val) {
    _reviewRating = val;
    document.querySelectorAll('.review-star-btn').forEach(function(btn) {
        if (parseInt(btn.getAttribute('data-v')) <= val) {
            btn.classList.add('lit');
        } else {
            btn.classList.remove('lit');
        }
    });
    var hint = document.getElementById('rv-star-hint');
    if (hint) {
        hint.textContent = _ratingLabels[val] || 'Click to rate';
        if (val === 5)      hint.style.color = 'rgba(82,183,136,0.75)';
        else if (val >= 3)  hint.style.color = 'rgba(245,197,24,0.65)';
        else                hint.style.color = 'rgba(242,139,130,0.65)';
    }
}

function _buildReviewCard(r, delay) {
    var words    = r.full_name.trim().split(/\s+/);
    var initials = words.map(function(w){ return w[0]; }).join('').toUpperCase().slice(0, 2);
    var stars    = '★'.repeat(r.rating);
    var card     = document.createElement('div');
    card.className = 'proof-card';
    card.style.animationDelay = (delay || 0) + 'ms';
    card.innerHTML =
        '<div class="proof-stars">' + stars + '</div>' +
        '<p class="proof-quote">&ldquo;' + r.review_text + '&rdquo;</p>' +
        '<div class="proof-author">' +
            '<div class="proof-avatar">' + initials + '</div>' +
            '<div>' +
                '<div class="proof-name">' + r.full_name + '</div>' +
                '<div class="proof-role">' + r.position + ' · ' + r.location + '</div>' +
            '</div>' +
        '</div>';
    return card;
}

async function loadReviews() {
    var grid = document.getElementById('proof-grid');
    if (!grid) return;
    try {
        var res  = await fetch('/backend/reviews.php', { credentials: 'include' });
        var data = await res.json();
        grid.innerHTML = '';
        if (!data.success || !data.reviews || !data.reviews.length) {
            grid.innerHTML = '<div class="proof-empty">No reviews yet — be the first!</div>';
            return;
        }
        data.reviews.forEach(function(r, i) {
            grid.appendChild(_buildReviewCard(r, i * 80));
        });
    } catch(e) {
        var g = document.getElementById('proof-grid');
        if (g) g.innerHTML = '<div class="proof-empty">Could not load reviews. Please refresh.</div>';
    }
}

async function submitReview() {
    ['rv-name','rv-position','rv-location','rv-text'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.borderColor = '';
    });
    ['rve-name','rve-position','rve-location','rve-text'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.textContent = '';
    });
    var msgEl = document.getElementById('review-msg');
    if (msgEl) { msgEl.textContent = ''; msgEl.className = 'review-msg'; }

    var full_name   = (document.getElementById('rv-name')     ? document.getElementById('rv-name').value     : '').trim();
    var position    = (document.getElementById('rv-position') ? document.getElementById('rv-position').value : '').trim();
    var location    = (document.getElementById('rv-location') ? document.getElementById('rv-location').value : '').trim();
    var review_text = (document.getElementById('rv-text')     ? document.getElementById('rv-text').value     : '').trim();

    var valid = true;

    if (_reviewRating === 0) {
        var hint = document.getElementById('rv-star-hint');
        if (hint) { hint.textContent = '⚠ Please select a rating'; hint.style.color = '#f28b82'; }
        valid = false;
    }

    function markErr(id, errId, msg) {
        var el = document.getElementById(id);
        if (el) el.style.borderColor = '#f28b82';
        var e = document.getElementById(errId);
        if (e) e.textContent = msg;
        valid = false;
    }

    if (!full_name)                   markErr('rv-name',     'rve-name',     'Full name is required');
    if (!position)                    markErr('rv-position', 'rve-position', 'Position is required');
    if (!location)                    markErr('rv-location', 'rve-location', 'Location is required');
    if (!review_text)                 markErr('rv-text',     'rve-text',     'Review is required');
    else if (review_text.length < 10) markErr('rv-text',     'rve-text',     'Review is too short (min 10 chars)');

    if (!valid) return;

    var btn = document.getElementById('rv-submit-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Submitting…'; }

    try {
        var res  = await fetch('/backend/reviews.php', {
            method:      'POST',
            credentials: 'include',
            headers:     { 'Content-Type': 'application/json' },
            body:        JSON.stringify({ full_name: full_name, position: position, location: location, review_text: review_text, rating: _reviewRating })
        });
        var data = await res.json();

        if (data.success) {
            if (msgEl) { msgEl.textContent = '✓ ' + data.message; msgEl.className = 'review-msg success'; }

            if (_reviewRating === 5 && data.review) {
                var grid   = document.getElementById('proof-grid');
                var empty  = grid ? grid.querySelector('.proof-empty') : null;
                if (empty) empty.remove();
                if (grid)  grid.insertBefore(_buildReviewCard(data.review, 0), grid.firstChild);
            }

            ['rv-name','rv-position','rv-location','rv-text'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.value = '';
            });
            _reviewRating = 0;
            document.querySelectorAll('.review-star-btn').forEach(function(b) { b.classList.remove('lit'); });
            var hint2 = document.getElementById('rv-star-hint');
            if (hint2) { hint2.textContent = 'Click to rate'; hint2.style.color = ''; }

        } else {
            if (msgEl) { msgEl.textContent = data.message || 'Something went wrong.'; msgEl.className = 'review-msg error'; }
        }
    } catch(e) {
        if (msgEl) { msgEl.textContent = 'Connection error. Please try again.'; msgEl.className = 'review-msg error'; }
    }

    if (btn) { btn.disabled = false; btn.textContent = '✦ Submit Review'; }
}

// ── Init ──────────────────────────────────────────────
if (!document.querySelector('.sidebar')) {
    loadReviews();
}