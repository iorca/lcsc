// mobile/Assets/js/pages.js — 移动端页面级交互（阶段 1：修改密码 / 我的操作记录）
(function () {
    'use strict';
    if (!window.MUI) return;

    function el(id) { return document.getElementById(id); }
    function hide(node, h) { if (node) node.classList.toggle('is-hidden', !!h); }

    function initPassword() {
        var form = el('mPwForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var oldPw     = el('mPwOld').value;
            var newPw     = el('mPwNew').value;
            var confirmPw = el('mPwConfirm').value;

            if (!oldPw || !newPw || !confirmPw) { MUI.toast('请填写完整', 'warning'); return; }
            if (newPw !== confirmPw) { MUI.toast('两次新密码不一致', 'warning'); return; }

            var btn = form.querySelector('button[type=submit]');
            if (btn) btn.disabled = true;

            MUI.ajax('account.change_password', {
                old_password: oldPw,
                new_password: newPw,
                confirm: confirmPw
            }).then(function (res) {
                if (btn) btn.disabled = false;
                if (res && res.code === 0) {
                    MUI.toast(res.msg || '密码已更新', 'success');
                    form.reset();
                } else {
                    MUI.toast((res && res.msg) || '修改失败', 'error');
                }
            }).catch(function () {
                if (btn) btn.disabled = false;
                MUI.toast('网络错误，请重试', 'error');
            });
        });
    }

    function initMyLogs() {
        var wrap = el('mLogList');
        if (!wrap) return;
        var pager = el('mLogPager');
        var PER_PAGE = 20;
        // 操作详情显示上限：批量编辑物料会把大量 ID 拼进 detail，整串渲染会让
        // 单条卡片极高且拖慢滚动。超长只截前 120 字（完整内容仍留在服务端日志里）。
        var DETAIL_MAX = 120;
        var logState = { page: 1, totalPages: 1, loading: false };

        function rowHtml(log) {
            var detail = String(log.detail || '');
            var main   = detail !== '' ? detail : String(log.action_label || log.action || '—');
            if (main.length > DETAIL_MAX) main = main.slice(0, DETAIL_MAX) + '…';
            var tags   = '';
            if (log.action_label)      tags += '<span class="m-log-tag">' + MUI.esc(log.action_label) + '</span>';
            if (log.target_type_label) tags += '<span class="m-log-tag">' + MUI.esc(log.target_type_label) + '</span>';
            if (log.ip)                tags += '<span class="m-log-tag">' + MUI.esc(log.ip) + '</span>';
            return '<div class="m-card m-log-item">'
                 + '<div class="m-log-time">' + MUI.esc(String(log.created_at || '')) + '</div>'
                 + '<div class="m-log-detail">' + MUI.esc(main) + '</div>'
                 + (tags !== '' ? '<div class="m-log-tags">' + tags + '</div>' : '')
                 + '</div>';
        }
        function emptyHtml(text, icon) {
            return '<div class="m-card m-empty"><div class="m-empty-ico">'
                 + '<span class="m-ico m-ico--' + (icon || 'inbox') + '" aria-hidden="true"></span></div>'
                 + '<div class="m-empty-text">' + MUI.esc(text) + '</div></div>';
        }

        function renderPager() {
            if (!pager) return;
            if (logState.totalPages <= 1) { pager.innerHTML = ''; pager.hidden = true; return; }
            pager.hidden = false;
            pager.innerHTML = '<button type="button" class="m-btn m-btn-sm m-btn--inline" data-log-page="prev"'
                + (logState.page <= 1 ? ' disabled' : '') + '>上一页</button>'
                + '<span class="m-log-page-info">第 ' + logState.page + ' / ' + logState.totalPages + ' 页</span>'
                + '<button type="button" class="m-btn m-btn-sm m-btn--inline" data-log-page="next"'
                + (logState.page >= logState.totalPages ? ' disabled' : '') + '>下一页</button>';
        }

        function load(page) {
            if (logState.loading) return;
            logState.loading = true;
            logState.page = parseInt(page, 10) || 1;

            var act = el('mLogAction'), tgt = el('mLogTarget');
            var params = { mine: 1, page: logState.page, per_page: PER_PAGE };
            if (act && act.value) params.trace_action = act.value;
            if (tgt && tgt.value) params.trace_target_type = tgt.value;

            wrap.innerHTML = emptyHtml('加载中…', 'clock');
            MUI.get('log.trace_list', params).then(function (res) {
                logState.loading = false;
                if (!res || res.code !== 0) {
                    MUI.toast((res && res.msg) || '加载失败', 'error');
                    wrap.innerHTML = emptyHtml('加载失败，请稍后重试', 'alert');
                    return;
                }
                var data = res.data || {};
                var logs = data.logs || [];
                logState.totalPages = parseInt(data.total_pages, 10) || 1;
                if (!logs.length) { wrap.innerHTML = emptyHtml('没有符合条件的记录', 'inbox'); renderPager(); return; }
                var html = '';
                for (var i = 0; i < logs.length; i++) html += rowHtml(logs[i]);
                wrap.innerHTML = html;
                renderPager();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }).catch(function () {
                logState.loading = false;
                MUI.toast('网络错误，请重试', 'error');
                wrap.innerHTML = emptyHtml('加载失败，请稍后重试', 'alert');
            });
        }

        var btn = el('mLogFilterBtn');
        if (btn) btn.addEventListener('click', function () { load(1); });
        if (pager) pager.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-log-page]') : null;
            if (!b || b.disabled) return;
            if (b.getAttribute('data-log-page') === 'prev') load(logState.page - 1);
            else load(logState.page + 1);
        });
        load(1);
    }

    function initStale() {
        var list = el('staleList');
        if (!list) return;

        var canCost = (el('mStaleRoot') || {}).getAttribute
            ? el('mStaleRoot').getAttribute('data-can-view-cost') === '1'
            : false;

        var PER_PAGE = 20;
        var state = { q: '', days: 0, page: 1, totalPage: 1, loading: false, done: false };

        function emptyHtml(text, icon) {
            return '<div class="m-card m-empty"><div class="m-empty-ico">'
                 + '<span class="m-ico m-ico--' + (icon || 'inbox') + '" aria-hidden="true"></span></div>'
                 + '<div class="m-empty-text">' + MUI.esc(text) + '</div></div>';
        }

        /* 金额格式化（成本不可见时服务端返回 null，此处不渲染金额列） */
        function money(v) {
            if (v === null || v === undefined) return '';
            var n = parseFloat(v) || 0;
            if (n >= 10000) return '¥' + (Math.round(n / 1000) / 10) + '万';
            return '¥' + n.toFixed(0);
        }

        function rowHtml(p) {
            var name = String(p.model || p.platform_part_no || ('#' + (p.id || 0)));
            var meta = [];
            if (p.brand)    meta.push(String(p.brand));
            if (p.package)  meta.push(String(p.package));
            if (p.location) meta.push(String(p.location));
            var value = '';
            if (canCost && p.asset_value !== null && p.asset_value !== undefined) {
                value = '<span class="m-stale-value">' + MUI.esc(money(p.asset_value)) + '</span>';
            }
            return '<div class="m-card m-part-row">'
                 + '<div class="m-part-head">'
                 +   '<div class="m-part-main">'
                 +     '<div class="m-part-model">' + MUI.esc(name) + '</div>'
                 +     (p.product_name ? '<div class="m-part-name">' + MUI.esc(String(p.product_name)) + '</div>' : '')
                 +     (meta.length ? '<div class="m-part-meta">' + MUI.esc(meta.join(' · ')) + '</div>' : '')
                 +   '</div>'
                 +   '<div class="m-part-side">'
                 +     '<span class="m-part-stock">' + MUI.esc(String(p.stock || 0)) + '</span>'
                 +     '<span class="m-stale-days">' + MUI.esc(String(p.stale_days || 0)) + ' 天未动销</span>'
                 +     value
                 +   '</div>'
                 + '</div>'
                 + '</div>';
        }

        /* 积压分档（服务端已算好，前端不自行判定档位） */
        function renderBuckets(buckets) {
            var box = el('staleBuckets');
            if (!box) return;
            if (!buckets || !buckets.length) { hide(el('staleBucketsCard'), true); return; }
            hide(el('staleBucketsCard'), false);
            var html = '';
            for (var i = 0; i < buckets.length; i++) {
                var b = buckets[i];
                var sub = '';
                if (canCost && b.value !== null && b.value !== undefined) sub = money(b.value);
                html += '<div class="m-stat">'
                     + '<div class="m-stat-value">' + MUI.esc(String(b.count || 0)) + '</div>'
                     + '<div class="m-stat-label">' + MUI.esc(String(b.label || '')) + '</div>'
                     + (sub !== '' ? '<div class="m-stat-sub">' + MUI.esc(sub) + '</div>' : '')
                     + '</div>';
            }
            box.innerHTML = html;
        }

        /* 行级骨架屏（首屏占位；宽度用预定义类，不写行内 style） */
        var SKEL_W = ['w-a', 'w-b', 'w-c'];
        var SKEL_W_SUB = ['w-d', 'w-e', 'w-f'];
        function renderSkeleton(rows) {
            var box = el('staleList');
            if (!box) return;
            var html = '';
            for (var i = 0; i < rows; i++) {
                html += '<div class="m-skel-row">'
                     + '<div class="m-skel-main">'
                     +   '<div class="m-skel-line ' + SKEL_W[i % SKEL_W.length] + '"></div>'
                     +   '<div class="m-skel-line is-sub ' + SKEL_W_SUB[i % SKEL_W_SUB.length] + '"></div>'
                     + '</div>'
                     + '<div class="m-skel-badge"></div>'
                     + '</div>';
            }
            box.innerHTML = html;
        }

        function load(reset) {
            if (state.loading) return;
            state.loading = true;
            if (reset) { state.page = 1; state.done = false; }
            if (reset) renderSkeleton(4);
            setFootState(reset ? '' : '加载中…');

            var params = { page: state.page, per_page: PER_PAGE };
            if (state.q)     params.q = state.q;
            if (state.days)  params.days = state.days;

            MUI.ajax('stock.stale_list', params).then(function (res) {
                state.loading = false;
                if (!res || res.code !== 0) {
                    setFootState('');
                    MUI.toast((res && res.msg) || '加载失败', 'error', {
                        label: '重试',
                        onClick: function () { load(reset); }
                    });
                    if (reset) list.innerHTML = emptyHtml('加载失败，请稍后重试', 'alert');
                    return;
                }
                var data = res.data || {};
                var items = data.items || [];
                state.totalPage = data.total_page || 1;
                state.done = state.page >= state.totalPage;

                renderBuckets(data.buckets);

                if (reset) {
                    list.innerHTML = '';
                }
                if (state.page === 1 && items.length === 0) {
                    hide(el('staleCount'), true);
                    list.innerHTML = emptyHtml(state.q ? '没有匹配的呆滞物料' : '暂无呆滞物料', 'inbox');
                    setFootState('');
                    return;
                }
                var cnt = el('staleCount');
                if (cnt) {
                    cnt.textContent = '共 ' + (data.total || 0) + ' 种呆滞物料';
                    hide(cnt, false);
                }
                var html = '';
                for (var i = 0; i < items.length; i++) html += rowHtml(items[i]);
                var frag = document.createElement('div');
                frag.innerHTML = html;
                while (frag.firstChild) list.appendChild(frag.firstChild);

                setFootState(state.done ? '已全部加载' : '');
            }).catch(function () {
                state.loading = false;
                setFootState('');
                MUI.toast('网络错误，请重试', 'error', {
                    label: '重试',
                    onClick: function () { load(reset); }
                });
                if (reset) list.innerHTML = emptyHtml('加载失败，请稍后重试', 'alert');
            });
        }

        /* 底部状态条：加载中 / 已到底 */
        function setFootState(text) {
            var t = el('staleMoreText');
            if (!t) return;
            t.textContent = text || '';
            hide(t, !text);
        }

        /* 无限滚动：滚动到接近底部时自动加载下一页（与查询页同一范式） */
        function bindInfiniteScroll() {
            var ticking = false;
            window.addEventListener('scroll', function () {
                if (ticking) return;
                ticking = true;
                window.requestAnimationFrame(function () {
                    ticking = false;
                    if (state.loading || state.done) return;
                    var doc = document.documentElement;
                    var scrolled = window.pageYOffset || doc.scrollTop || 0;
                    var height = doc.scrollHeight || 0;
                    var viewport = window.innerHeight || 0;
                    if (scrolled + viewport >= height - 300) {
                        state.page += 1;
                        load(false);
                    }
                });
            }, { passive: true });
        }

        // 搜索（防抖 300ms）
        var input = el('staleSearch');
        if (input) {
            var timer = null;
            var run = function () { state.q = input.value.trim(); load(true); };
            input.addEventListener('input', function () {
                var wrap = el('staleSearchWrap');
                if (wrap) wrap.classList.toggle('has-value', input.value !== '');
                clearTimeout(timer);
                timer = setTimeout(run, 300);
            });
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); run(); }
            });
        }
        var clear = el('staleClear');
        if (clear) clear.addEventListener('click', function () {
            if (input) { input.value = ''; input.focus(); }
            state.q = '';
            var wrap = el('staleSearchWrap');
            if (wrap) wrap.classList.remove('has-value');
            load(true);
        });

        // 天数档筛选
        var daysBar = el('staleDaysBar');
        if (daysBar) daysBar.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.m-chip') : null;
            if (!b) return;
            state.days = parseInt(b.getAttribute('data-days'), 10) || 0;
            Array.prototype.forEach.call(daysBar.querySelectorAll('.m-chip'), function (x) {
                x.classList.toggle('is-on', x === b);
            });
            load(true);
        });

        var more = el('staleMore');
        if (more) more.addEventListener('click', function () {
            // 底部状态条本身可点（键盘可达 / 手动续页兜底）
            if (!state.loading && !state.done && state.page < state.totalPage) {
                state.page += 1;
                load(false);
            }
        });

        bindInfiniteScroll();
        load(true);
    }

    function initProfile() {
        var slogan = el('mSlogan');
        var weatherBtn = el('mWeather');
        if (!slogan && !weatherBtn) return;

        var wData = null;   // 有真实天气数据（live）才允许点开弹窗

        /* 指标网格（key→value 对象）；空对象返回空串 */
        function gridHtml(obj) {
            var keys = Object.keys(obj || {});
            if (!keys.length) return '';
            var h = '<div class="m-wm-grid">';
            keys.forEach(function (k) {
                h += '<div class="m-wm-cell"><span class="k">' + MUI.esc(k) + '</span>'
                   + '<span class="v">' + MUI.esc(obj[k]) + '</span></div>';
            });
            return h + '</div>';
        }

        /* 天气文案去掉后端拼在句首的 emoji 图标 */
        function weatherText(w) {
            var t = String(w.text || '');
            var ic = String(w.icon || '');
            if (ic !== '' && t.indexOf(ic) === 0) t = t.slice(ic.length).trim();
            return t;
        }

        /* 详情弹窗内容：城市 + 当前天气 + 指标网格 + 生活指数 + 来源 */
        function modalBody(w) {
            var h = '';
            if (w.city) h += '<div class="m-wm-city">' + MUI.esc(w.city) + '</div>';
            h += '<div class="m-wm-now">'
               + '<span class="m-wm-now-ico"><span class="m-ico m-ico--weather" aria-hidden="true"></span></span>'
               + '<span class="m-wm-now-temp">' + MUI.esc(w.temp || '') + '</span>'
               + '<span class="m-wm-now-text">' + MUI.esc(weatherText(w)) + '</span>'
               + '</div>';
            h += gridHtml(w.detail || w.summary || {});
            var adv = w.advice || {};
            var advKeys = Object.keys(adv);
            if (advKeys.length) {
                h += '<div class="m-wm-adv">' + MUI.esc(advKeys.map(function (k) {
                    return k + ' ' + adv[k];
                }).join('　')) + '</div>';
            }
            var names = { 'uapis': 'uapis.cn', 'wttr': 'wttr.in', 'open-meteo': 'Open-Meteo' };
            var src = names[w.source] || w.source || '';
            var at = String(w.at || '').replace('T', ' ').slice(0, 16);
            if (src || at) {
                h += '<div class="m-wm-src">' + MUI.esc(src) + (at ? ' · ' + MUI.esc(at) : '') + '</div>';
            }
            return h;
        }

        MUI.get('external.wb').then(function (res) {
            var data = (res && res.data) || {};
            if (slogan) {
                slogan.classList.remove('is-loading');
                slogan.textContent = data.slogan || '';
            }
            var w = data.weather || {};
            // 内网 / 本机地址访问：后端返回 display=false，不显示天气行
            if (w.display === false) {
                if (weatherBtn) weatherBtn.style.display = 'none';
                return;
            }
            if (el('mWeatherText')) el('mWeatherText').textContent = weatherText(w);
            if (el('mWeatherCity')) el('mWeatherCity').textContent = w.city || '';
            if (el('mWeatherIcon')) el('mWeatherIcon').innerHTML = '<span class="m-ico m-ico--weather" aria-hidden="true"></span>';
            if (el('mWeatherTemp')) el('mWeatherTemp').textContent = w.temp || '';
            if (weatherBtn && w.live) { weatherBtn.disabled = false; wData = w; }
        }).catch(function () {
            if (slogan) { slogan.classList.remove('is-loading'); slogan.textContent = ''; }
            if (el('mWeatherText')) el('mWeatherText').textContent = '天气暂不可用';
        });

        // 弹窗开关（信息类模态框）
        var modal = el('mWeatherModal');
        function closeModal() { if (modal) modal.classList.add('is-hidden'); }
        if (weatherBtn) weatherBtn.addEventListener('click', function () {
            if (weatherBtn.disabled || !wData || !modal) return;
            var body = el('mWeatherModalBody');
            if (body) body.innerHTML = modalBody(wData);
            modal.classList.remove('is-hidden');
            // 焦点移入弹窗（关闭按钮），关闭后归还给触发它的天气按钮
            var closeBtn = el('mWeatherModalClose');
            if (closeBtn) { try { closeBtn.focus({ preventScroll: true }); } catch (e) { closeBtn.focus(); } }
        });
        if (modal) {
            var closeBtn = el('mWeatherModalClose');
            var mask     = el('mWeatherModalMask');
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (mask) mask.addEventListener('click', closeModal);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !modal.classList.contains('is-hidden')) closeModal();
            });
        }
    }

    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }
    ready(function () {
        initPassword();
        initMyLogs();
        initStale();
        initProfile();
    });
})();
