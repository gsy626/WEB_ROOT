/* =========================================================
   五校逐鹿 · 前端控制器
   ========================================================= */
(() => {
    'use strict';

    const CONFIG = window.__BOOTSTRAP__;
    const INITIAL_GAME = window.__INITIAL_GAME__ || '';
    const INITIAL_STATE = window.__INITIAL_STATE__ || null;
    const STORAGE_LAST = 'wjzl_last_game_v1';
    const STORAGE_CUSTOMS = 'wjzl_customs_v1';
    const SLOT_CHARS = ['壹', '贰', '叁', '肆', '伍'];

    function reportClientError(message) {
        try {
            const node = document.createElement('pre');
            node.id = 'clientErrorBox';
            node.style.cssText = 'position:fixed;left:10px;bottom:10px;z-index:9999;max-width:80vw;padding:10px;background:#2a211c;color:#ffd6cc;font:11px/1.5 monospace;white-space:pre-wrap;border:1px solid #a4211d';
            node.textContent = `脚本异常：\n${message}`;
            document.body.appendChild(node);
        } catch (error) { /* ignore */ }
    }

    window.addEventListener('error', (event) => {
        reportClientError(`${event.message}\n${event.filename}:${event.lineno}:${event.colno}`);
    });
    window.addEventListener('unhandledrejection', (event) => {
        reportClientError(String(event.reason && event.reason.stack || event.reason || 'unknown rejection'));
    });

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[ch]));

    const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
    const pct = (n, max = 100) => clamp(Math.round((Number(n) / max) * 100), 0, 100);
    const signed = (n) => `${Number(n) > 0 ? '+' : ''}${Math.round(Number(n) * 10) / 10}`;
    const truncate = (text, max) => {
        const str = String(text ?? '');
        return str.length > max ? `${str.slice(0, max - 1)}…` : str;
    };

    const state = {
        data: null,
        gameId: '',
        selectedId: null,
        tab: 'action',
        lineup: [],
        customs: [],
        busy: false,
        pendingEnd: false,
        endOverlayShown: null,
        customTarget: 0,
    };

    const $startScreen = $('#startScreen');
    const $gameScreen = $('#gameScreen');
    const $mapStage = $('#mapStage');
    const $sideBody = $('#sideBody');
    const $modalRoot = $('#modalRoot');
    const $toastRoot = $('#toastRoot');
    const $loading = $('#loadingOverlay');
    const $rosterList = $('#rosterList');
    const $resumeBtn = $('#resumeBtn');

    const presetMap = new Map(CONFIG.presets.map((p) => [p.id, p]));

    function init() {
        loadCustoms();
        const last = safeStorageGet(STORAGE_LAST);
        if (last && /^[a-f0-9]{16}$/.test(last)) {
            $resumeBtn.hidden = false;
        }

        state.lineup = CONFIG.presets.slice(0, 5).map((p) => `preset:${p.id}`);
        renderStartRules();
        renderRoster();
        bindEvents();

        if (INITIAL_GAME && INITIAL_STATE && INITIAL_STATE.game && INITIAL_STATE.game.id === INITIAL_GAME) {
            setGameData(INITIAL_STATE, { newGame: false });
            showScreen('game');
            if (INITIAL_STATE.game.status !== 'playing') {
                window.setTimeout(() => showEndOverlay(), 450);
            }
        } else if (INITIAL_GAME) {
            loadGame(INITIAL_GAME, true);
        } else {
            showScreen('start');
        }
    }

    /* ---------------- 基础工具 ---------------- */

    function safeStorageGet(key) {
        try {
            return localStorage.getItem(key) || '';
        } catch (error) {
            return '';
        }
    }

    function safeStorageSet(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (error) {
            /* 隐私模式或存储已满时忽略。 */
        }
    }

    function loadCustoms() {
        try {
            const parsed = JSON.parse(safeStorageGet(STORAGE_CUSTOMS) || '[]');
            if (Array.isArray(parsed)) {
                state.customs = parsed.filter((item) => item && item.customId && item.name && item.nickname);
            }
        } catch (error) {
            state.customs = [];
        }
    }

    function saveCustoms() {
        safeStorageSet(STORAGE_CUSTOMS, JSON.stringify(state.customs));
    }

    function customMap() {
        return new Map(state.customs.map((item) => [item.customId, item]));
    }

    function keyLabel(key) {
        if (key.startsWith('preset:')) {
            const preset = presetMap.get(key.slice(7));
            return preset ? `${preset.name}（${preset.short}）` : '未知高校';
        }
        const custom = customMap().get(key.slice(7));
        return custom ? `${custom.name}（${custom.short || custom.nickname}）` : '未知高校';
    }

    function itemByKey(key) {
        if (key.startsWith('preset:')) {
            return presetMap.get(key.slice(7)) || null;
        }
        return customMap().get(key.slice(7)) || null;
    }

    function itemPreview(key) {
        if (key.startsWith('preset:')) {
            return presetMap.get(key.slice(7)) || null;
        }
        return customMap().get(key.slice(7)) || null;
    }

    function schoolById(id) {
        if (!state.data) return null;
        return state.data.schools.find((school) => school.id === Number(id)) || null;
    }

    function districtById(id) {
        if (!state.data) return null;
        return state.data.districts.find((district) => district.id === Number(id)) || null;
    }

    function playerSchool() {
        if (!state.data) return null;
        return schoolById(state.data.game.player_school_id);
    }

    function colorForSchool(id) {
        const school = schoolById(id);
        return school ? school.color : '#9d211c';
    }

    function contrastText(hex) {
        const clean = String(hex || '').replace('#', '');
        if (clean.length !== 6) return '#fffaf0';
        const r = parseInt(clean.slice(0, 2), 16);
        const g = parseInt(clean.slice(2, 4), 16);
        const b = parseInt(clean.slice(4, 6), 16);
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        return luminance > 0.62 ? '#2c2a22' : '#fffaf0';
    }

    function readableInfluenceColor(hex) {
        const clean = String(hex || '').replace('#', '');
        if (clean.length !== 6) return '#f0d18e';
        const r = parseInt(clean.slice(0, 2), 16);
        const g = parseInt(clean.slice(2, 4), 16);
        const b = parseInt(clean.slice(4, 6), 16);
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        return luminance < 0.38 ? '#efd398' : hex;
    }

    function typeLabel(kind) {
        return {
            ace: '王牌院系',
            lab: '交叉学科',
            life: '学生生活区',
            admin: '行政中枢',
            library: '公共设施',
            metro: '公共交通',
            sports: '公共设施',
            food: '公共设施',
        }[kind] || '校区区块';
    }

    function kindIcon(kind) {
        return {
            ace: '峰',
            lab: '研',
            life: '舍',
            admin: '政',
            library: '书',
            metro: '铁',
            sports: '体',
            food: '食',
        }[kind] || '院';
    }

    function actionIcon(type) {
        return {
            infiltrate: '渗',
            battle: '战',
            campaign: '舆',
            fortify: '固',
            stabilize: '稳',
            ace: '牌',
            tech_unlock: '策',
            tech_ult: '杀',
        }[type] || '行';
    }

    function showToast(message, tone = 'info') {
        const node = document.createElement('div');
        node.className = `toast ${tone}`;
        node.innerHTML = `<span class="toast-mark">${tone === 'error' ? '驳' : '批'}</span><span>${esc(message)}</span>`;
        $toastRoot.appendChild(node);
        window.setTimeout(() => {
            node.style.opacity = '0';
            node.style.transform = 'translateY(6px)';
            node.style.transition = 'all .25s ease';
            window.setTimeout(() => node.remove(), 260);
        }, 3200);
    }

    function setLoading(show, title = '正在盖章', sub = '教务处正在核对你提交的材料……') {
        if (!show) {
            $loading.hidden = true;
            return;
        }
        $('.loading-title').textContent = title;
        $('.loading-sub').textContent = sub;
        $loading.hidden = false;
    }

    function showScreen(screen) {
        $startScreen.hidden = screen !== 'start';
        $gameScreen.hidden = screen !== 'game';
        window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
    }

    async function apiGet(params) {
        const query = new URLSearchParams(params).toString();
        const response = await fetch(`api.php?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return parseApiResponse(response);
    }

    async function apiPost(payload) {
        const response = await fetch('api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        });
        return parseApiResponse(response);
    }

    async function parseApiResponse(response) {
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error('服务器返回了无法解析的内容。');
        }
        if (!response.ok || !data.ok) {
            throw new Error(data.message || '操作失败。');
        }
        return data;
    }

    /* ---------------- 开始页 ---------------- */

    function renderStartRules() {
        const root = $('#startRules');
        root.innerHTML = CONFIG.rules.map((rule) => `
            <article class="rule-card">
                <div class="rule-icon">${esc(rule.icon)}</div>
                <h3>${esc(rule.title)}</h3>
                <p>${esc(rule.text)}</p>
            </article>
        `).join('');
    }

    function renderRoster() {
        const used = new Map();
        state.lineup.forEach((key, index) => used.set(key, index));

        $rosterList.innerHTML = state.lineup.map((key, index) => {
            const item = itemPreview(key);
            const isPlayer = index === 0;
            const color = item ? item.color : '#9d211c';
            const nickname = item ? item.nickname : '未选择';
            const ace = item ? (item.ace || '交叉学科') : '';
            const perk = item ? (CONFIG.perks[item.perk] || {}) : {};
            const options = buildRosterOptions(index);
            return `
                <div class="roster-row ${isPlayer ? 'is-player' : ''}">
                    ${isPlayer ? '<span class="player-flag">我方主控</span>' : ''}
                    <div class="slot-no"><b>${SLOT_CHARS[index]}</b><span>${isPlayer ? 'PLAYER' : 'RIVAL'}</span></div>
                    <div class="roster-main">
                        <div class="roster-select-wrap">
                            <select class="roster-select" data-slot="${index}" aria-label="${isPlayer ? '我方高校' : `第 ${index + 1} 所对手高校`}">
                                ${options}
                            </select>
                        </div>
                        <div class="roster-meta">
                            <span class="color-pip" style="background:${esc(color)}"></span>
                            <span>${esc(nickname)}</span>
                            <span class="sep">·</span>
                            <span>${esc(ace)}</span>
                            <span class="sep">·</span>
                            <span class="perk">${esc(perk.name || '自定义配置')}</span>
                        </div>
                    </div>
                    <div class="roster-edit">
                        <button class="modify-btn" type="button" data-custom-slot="${index}" title="为该校位新建自定义高校">＋</button>
                    </div>
                </div>
            `;
        }).join('');
    }

    function buildRosterOptions(slotIndex) {
        const current = state.lineup[slotIndex];
        const used = new Map();
        state.lineup.forEach((key, index) => {
            if (index !== slotIndex) used.set(key, index);
        });

        const presetOptions = CONFIG.presets.map((preset) => {
            const key = `preset:${preset.id}`;
            const disabled = used.has(key) ? 'disabled' : '';
            const selected = current === key ? 'selected' : '';
            return `<option value="${esc(key)}" ${disabled} ${selected}>${esc(preset.name)} · ${esc(preset.short)}</option>`;
        }).join('');

        let customOptions = '';
        if (state.customs.length) {
            customOptions = `<optgroup label="我的自定义高校">${state.customs.map((custom) => {
                const key = `custom:${custom.customId}`;
                const disabled = used.has(key) ? 'disabled' : '';
                const selected = current === key ? 'selected' : '';
                return `<option value="${esc(key)}" ${disabled} ${selected}>${esc(custom.name)} · ${esc(custom.short || custom.nickname)}</option>`;
            }).join('')}</optgroup>`;
        }

        return `<optgroup label="经典高校">${presetOptions}</optgroup>${customOptions}`;
    }

    function onRosterChange(event) {
        const select = event.target.closest('.roster-select');
        if (!select) return;
        const slot = Number(select.dataset.slot);
        const value = select.value;
        if (!value) return;
        const other = state.lineup.indexOf(value);
        if (other !== -1 && other !== slot) {
            // 已被占用的学校直接与目标槽交换，避免重复。
            state.lineup[other] = state.lineup[slot];
        }
        state.lineup[slot] = value;
        renderRoster();
    }

    function randomLineup() {
        const all = [...CONFIG.presets].sort(() => Math.random() - 0.5);
        state.lineup = all.slice(0, 5).map((preset) => `preset:${preset.id}`);
        renderRoster();
        showToast('已随机抽取五所高校。', 'success');
    }

    function resetLineup() {
        state.lineup = CONFIG.presets.slice(0, 5).map((preset) => `preset:${preset.id}`);
        renderRoster();
    }

    function startGame() {
        if (state.busy) return;
        const keys = state.lineup;
        if (new Set(keys).size !== 5 || keys.some((key) => !itemPreview(key))) {
            showToast('请先选满五所不重复的高校。', 'error');
            return;
        }

        const lineup = keys.map((key) => {
            if (key.startsWith('preset:')) {
                return { kind: 'preset', preset: key.slice(7) };
            }
            const custom = customMap().get(key.slice(7));
            return {
                kind: 'custom',
                name: custom.name,
                nickname: custom.nickname,
                short: custom.short,
                color: custom.color,
                ace: custom.ace,
                motto: custom.motto,
                perk: custom.perk,
                flaw: custom.flaw,
            };
        });

        state.busy = true;
        setLoading(true, '正在签发开赛文件', '大学城管委会正在核对五校建制……');
        apiPost({ action: 'new_game', lineup })
            .then((res) => {
                setLoading(false);
                setGameData(res.state, { newGame: true });
                safeStorageSet(STORAGE_LAST, res.state.game.id);
                const url = new URL(window.location.href);
                url.searchParams.set('game', res.state.game.id);
                window.history.replaceState(null, '', url);
                showScreen('game');
                showScreenWelcome();
            })
            .catch((error) => {
                setLoading(false);
                showToast(error.message, 'error');
            })
            .finally(() => {
                state.busy = false;
            });
    }

    async function loadGame(gameId, initial = false) {
        if (state.busy) return;
        state.busy = true;
        if (initial) setLoading(true, '正在调取对局档案', '请稍候，教务处正在翻找你的材料……');
        try {
            const res = await apiGet({ action: 'state', game_id: gameId });
            setLoading(false);
            setGameData(res.state, { newGame: false });
            safeStorageSet(STORAGE_LAST, gameId);
            const url = new URL(window.location.href);
            url.searchParams.set('game', gameId);
            window.history.replaceState(null, '', url);
            showScreen('game');
            if (res.state.game.status !== 'playing') {
                window.setTimeout(() => showEndOverlay(), 450);
            }
        } catch (error) {
            setLoading(false);
            showScreen('start');
            const saved = safeStorageGet(STORAGE_LAST);
            if (saved === gameId) {
                try { localStorage.removeItem(STORAGE_LAST); } catch (e) { /* ignore */ }
            }
            $resumeBtn.hidden = true;
            showToast(error.message, 'error');
        } finally {
            state.busy = false;
        }
    }

    function setGameData(data, options = {}) {
        state.data = data;
        state.gameId = data.game.id;
        state.selectedId = null;
        state.tab = 'action';
        state.pendingEnd = false;
        state.endOverlayShown = null;
        renderGame();
        if (options.newGame) {
            safeStorageSet(STORAGE_LAST, data.game.id);
        }
    }

    /* ---------------- 自定义高校 ---------------- */

    function openCustomBuilder(targetSlot = 0) {
        state.customTarget = clamp(Number(targetSlot) || 0, 0, 4);
        const colorIndex = Math.floor(Math.random() * CONFIG.colors.length);
        const defaultColor = CONFIG.colors[colorIndex];
        const perks = Object.entries(CONFIG.perks);
        const flaws = Object.entries(CONFIG.flaws);

        const perkHtml = perks.map(([key, perk], index) => `
            <label class="option-card ${index === 0 ? 'selected' : ''}">
                <input type="radio" name="custom-perk" value="${esc(key)}" ${index === 0 ? 'checked' : ''}>
                <span class="option-icon">${esc(perk.icon)}</span>
                <span class="option-text"><b>${esc(perk.name)}</b><span>${esc(perk.desc)}</span></span>
            </label>
        `).join('');

        const flawHtml = flaws.map(([key, flaw], index) => `
            <label class="option-card ${index === 0 ? 'selected' : ''}">
                <input type="radio" name="custom-flaw" value="${esc(key)}" ${index === 0 ? 'checked' : ''}>
                <span class="option-icon">${esc((CONFIG.flaws[key] && CONFIG.flaws[key].name || '短').slice(0, 1))}</span>
                <span class="option-text"><b>${esc(flaw.name)}</b><span>${esc(flaw.desc)}</span></span>
            </label>
        `).join('');

        const colorHtml = CONFIG.colors.map((color, index) => `
            <button type="button" class="color-swatch ${index === colorIndex ? 'active' : ''}" data-color="${esc(color)}" style="background:${esc(color)}" title="${esc(color)}"></button>
        `).join('');

        $modalRoot.hidden = false;
        $modalRoot.innerHTML = `
            <div class="modal-scrim" data-close-modal="1"></div>
            <section class="form-modal" role="dialog" aria-modal="true" aria-labelledby="customTitle">
                <div class="doc-topline"></div>
                <div class="form-head">
                    <div>
                        <div class="form-kicker">教高赛〔2026〕增补院校申请表</div>
                        <h2 id="customTitle">自建一所高校</h2>
                    </div>
                    <button type="button" class="form-close" data-close-modal="1" aria-label="关闭">×</button>
                </div>
                <form id="customForm" novalidate>
                    <div class="form-body">
                        <div class="form-grid">
                            <div class="field">
                                <label for="customName">正式校名<em>*</em></label>
                                <input id="customName" name="name" type="text" maxlength="20" placeholder="例如：云城大学" autocomplete="off">
                                <span class="field-hint">2–20 个字，不能与已参战高校重名。</span>
                            </div>
                            <div class="field">
                                <label for="customNickname">民间绰号<em>*</em></label>
                                <input id="customNickname" name="nickname" type="text" maxlength="24" placeholder="例如：云城职业技术学院" autocomplete="off">
                                <span class="field-hint">越损越好，这是舆论战的素材库。</span>
                            </div>
                            <div class="field">
                                <label for="customShort">地图简称</label>
                                <input id="customShort" name="short" type="text" maxlength="8" placeholder="例如：云城" autocomplete="off">
                            </div>
                            <div class="field">
                                <label for="customAce">王牌院系 / 学科<em>*</em></label>
                                <input id="customAce" name="ace" type="text" maxlength="12" placeholder="例如：量子玄学学院" value="交叉学科中心" autocomplete="off">
                                <span class="field-hint">会作为你在大学城的第一个渗透据点。</span>
                            </div>
                            <div class="field full">
                                <label for="customMotto">校训</label>
                                <input id="customMotto" name="motto" type="text" maxlength="24" placeholder="例如：自强不息，适度内卷" value="自强不息" autocomplete="off">
                            </div>
                            <div class="field full">
                                <label>校徽主色<em>*</em></label>
                                <div class="color-row" id="colorRow">
                                    ${colorHtml}
                                    <input type="color" class="color-input" id="customColor" value="${esc(defaultColor)}" title="自定义颜色">
                                </div>
                            </div>
                        </div>

                        <div class="option-block">
                            <div class="option-block-title"><b>选择特长</b><span>只能选一个，决定你的核心打法</span></div>
                            <div class="option-grid" id="perkOptions">${perkHtml}</div>
                        </div>

                        <div class="option-block">
                            <div class="option-block-title"><b>选择短板</b><span>天下没有六边形战士，除非你充钱</span></div>
                            <div class="option-grid" id="flawOptions">${flawHtml}</div>
                        </div>
                    </div>
                    <div class="form-foot">
                        <div class="form-note">新建后将自动填入第 ${SLOT_CHARS[state.customTarget]} 顺位。你可以继续创建更多自定义高校。</div>
                        <div class="form-actions">
                            <button type="button" class="btn btn-paper" data-close-modal="1">取消</button>
                            <button type="submit" class="btn btn-red">造好，送它上场</button>
                        </div>
                    </div>
                </form>
            </section>
        `;
        bindCustomForm(defaultColor);
        window.setTimeout(() => $('#customName')?.focus(), 80);
    }

    function bindCustomForm(defaultColor) {
        const form = $('#customForm');
        const colorRow = $('#colorRow');
        const colorInput = $('#customColor');
        let selectedColor = defaultColor;

        colorRow.addEventListener('click', (event) => {
            const swatch = event.target.closest('.color-swatch');
            if (!swatch) return;
            selectedColor = swatch.dataset.color;
            colorInput.value = selectedColor;
            $$('.color-swatch', colorRow).forEach((node) => node.classList.toggle('active', node === swatch));
        });

        colorInput.addEventListener('input', () => {
            selectedColor = colorInput.value;
            $$('.color-swatch', colorRow).forEach((node) => node.classList.remove('active'));
        });

        form.addEventListener('change', (event) => {
            const input = event.target;
            if (input.type !== 'radio') return;
            const group = input.name === 'custom-perk' ? $('#perkOptions') : $('#flawOptions');
            $$('.option-card', group).forEach((label) => {
                const radio = $('input', label);
                label.classList.toggle('selected', radio.checked);
            });
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const name = form.querySelector('[name="name"]').value.trim();
            const nickname = form.querySelector('[name="nickname"]').value.trim();
            const short = form.querySelector('[name="short"]').value.trim() || nickname.slice(0, 4);
            const ace = form.querySelector('[name="ace"]').value.trim() || '交叉学科中心';
            const motto = form.querySelector('[name="motto"]').value.trim() || '自强不息';
            const perk = form.querySelector('input[name="custom-perk"]:checked')?.value;
            const flaw = form.querySelector('input[name="custom-flaw"]:checked')?.value;

            if (name.length < 2 || nickname.length < 2) {
                showToast('校名和绰号都至少需要 2 个字。', 'error');
                return;
            }
            const allNames = new Set([
                ...CONFIG.presets.map((preset) => preset.name),
                ...state.customs.map((custom) => custom.name),
            ]);
            if (allNames.has(name)) {
                showToast('这个校名已经在名单里了，换一个吧。', 'error');
                return;
            }

            const customId = `c${Date.now().toString(36)}${Math.floor(Math.random() * 999).toString(36)}`;
            const custom = {
                customId,
                name,
                nickname,
                short,
                color: selectedColor,
                ace,
                motto,
                perk,
                flaw,
            };
            state.customs.push(custom);
            saveCustoms();
            state.lineup[state.customTarget] = `custom:${customId}`;
            renderRoster();
            closeModal();
            showToast(`「${name}」已获参赛资格。`, 'success');
        });
    }

    /* ---------------- 游戏主渲染 ---------------- */

    function renderGame() {
        if (!state.data) return;
        const data = state.data;
        const game = data.game;
        const player = data.player;
        const mySchool = playerSchool();

        document.title = `${player.name} · 五校逐鹿`;

        $('#gameSeal').textContent = player.emblem || player.name.slice(0, 1);
        $('#gameSeal').style.color = player.color;
        $('#gameSeal').style.borderColor = player.color;
        $('#playerSchoolName').textContent = player.name;
        $('#playerNickname').textContent = player.nickname;
        $('#weekNumber').textContent = game.week;
        $('#maxWeek').textContent = game.max_week;
        $('#campaignClock').textContent = `第 ${game.week} 周`;

        renderHeaderStats();
        renderEventChip();
        renderObjective();
        renderMap();
        renderLegend();
        renderTicker();
        renderSidebar();

        const ended = game.status !== 'playing';
        $('#endTurnBtn').disabled = ended || state.busy;
        if (ended) {
            $('#endTurnBtn').firstChild.textContent = '本局已结案 ';
            $('#endTurnBtn').querySelector('.end-turn-sub').textContent = game.status === 'won' ? '吞并成功' : '淘汰出局';
        } else {
            $('#endTurnBtn').firstChild.textContent = '结束本周 ';
            $('#endTurnBtn').querySelector('.end-turn-sub').textContent = '盖章推进';
        }

        // 玩家可能通过弹窗关闭才看到终局，这里不主动打断当前文档。
        if (mySchool && mySchool.eliminated) {
            $('#endTurnBtn').disabled = true;
        }
    }

    function renderHeaderStats() {
        const data = state.data;
        const game = data.game;
        const player = data.player;
        const grievanceHigh = player.grievance >= 70;
        const apLow = game.ap <= 0;
        const fundsLow = player.funds < 30;
        const chips = [
            { icon: '政', label: '行政点', value: `${game.ap}<small> / ${game.max_ap}</small>`, cls: apLow ? 'low' : '' },
            { icon: '费', label: '科研经费', value: `${player.funds}<small> 万</small>`, cls: fundsLow ? 'low' : '' },
            { icon: '策', label: '校策点', value: `${player.tech_points}<small> 点</small>`, cls: '' },
            { icon: '持', label: '支持率', value: `${player.support}<small>%</small>`, cls: '' },
            { icon: '怨', label: '学生怨气', value: `${player.grievance}<small>%</small>`, cls: grievanceHigh ? 'high' : '' },
            { icon: '收', label: '周收入', value: `+${game.income}<small> 万</small>`, cls: '' },
        ];
        $('#statStrip').innerHTML = chips.map((chip) => `
            <div class="resource-chip ${chip.cls}" title="${esc(chip.label)}">
                <span class="res-icon">${esc(chip.icon)}</span>
                <span class="res-text"><span class="res-label">${esc(chip.label)}</span><span class="res-value">${chip.value}</span></span>
            </div>
        `).join('');
    }

    function renderEventChip() {
        const event = state.data.game.active_event;
        const node = $('#eventChip');
        if (!event) {
            node.hidden = true;
            return;
        }
        node.hidden = false;
        node.innerHTML = `<b>${esc(event.icon || '事')}</b><span>${esc(event.name)} · 至第 ${event.until} 周</span>`;
        node.title = event.desc || '';
    }

    function renderObjective() {
        const game = state.data.game;
        $('#controlledCount').textContent = game.controlled;
        $('#thresholdCount').textContent = game.threshold;
        $('#objectiveBar').style.width = `${clamp((game.controlled / game.threshold) * 100, 0, 100)}%`;
    }

    /* ---------------- 地图 ---------------- */

    function renderMap() {
        const data = state.data;
        const game = data.game;
        const schools = data.schools;
        const districts = data.districts;
        const week = game.week;

        const schoolSlots = new Map(schools.map((school) => [school.id, school]));
        const roads = [
            'M 72 384 C 228 278 385 262 548 342 C 721 428 902 395 1052 300',
            'M 556 34 C 508 154 620 246 560 360 C 506 472 590 584 556 708',
            'M 107 160 C 268 168 380 245 476 294',
            'M 1013 166 C 856 178 748 244 657 296',
            'M 112 600 C 282 598 400 514 474 422',
            'M 1017 602 C 850 594 738 512 657 422',
            'M 300 342 C 360 308 420 304 485 318',
            'M 840 342 C 772 306 700 304 641 318',
        ];

        const decorations = [];
        for (let i = 0; i < 18; i++) {
            const x = 75 + ((i * 173) % 970);
            const y = 55 + ((i * 97) % 610);
            if (x > 360 && x < 780 && y > 220 && y < 460) continue;
            decorations.push(`<circle class="map-tree" cx="${x}" cy="${y}" r="${3 + (i % 3)}"></circle>`);
        }

        const outlines = schools.map((school) => {
            const own = districts.filter((district) => district.slot_index >= school.slot * 4 && district.slot_index < school.slot * 4 + 4);
            if (!own.length) return '';
            const minX = Math.min(...own.map((d) => d.x - d.w / 2)) - 18;
            const minY = Math.min(...own.map((d) => d.y - d.h / 2)) - 18;
            const maxX = Math.max(...own.map((d) => d.x + d.w / 2)) + 18;
            const maxY = Math.max(...own.map((d) => d.y + d.h / 2)) + 18;
            return `<rect x="${minX}" y="${minY}" width="${maxX - minX}" height="${maxY - minY}" rx="38" fill="${esc(school.color)}" opacity="0.045" stroke="${esc(school.color)}" stroke-opacity="0.17" stroke-dasharray="3 7"></rect>`;
        }).join('');

        const tiles = districts.map((district) => renderTile(district, week, data)).join('');
        const campusTags = schools.map((school) => renderCampusTag(school, districts, data)).join('');

        const previousMapScroll = $mapStage.scrollLeft;
        $mapStage.innerHTML = `
            <svg class="university-map" viewBox="0 0 1120 720" role="group" aria-label="大学城势力地图">
                <defs>
                    <linearGradient id="mapSheen" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#ffffff" stop-opacity="0.16"></stop>
                        <stop offset="0.55" stop-color="#ffffff" stop-opacity="0.02"></stop>
                        <stop offset="1" stop-color="#000000" stop-opacity="0.08"></stop>
                    </linearGradient>
                    <pattern id="neutralHatch" width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(30)">
                        <line x1="0" y1="0" x2="0" y2="6" stroke="#a08d70" stroke-width="1" opacity="0.17"></line>
                    </pattern>
                    <filter id="mapShadow" x="-20%" y="-20%" width="140%" height="140%">
                        <feDropShadow dx="0" dy="3" stdDeviation="3" flood-color="#40331f" flood-opacity="0.18"></feDropShadow>
                    </filter>
                </defs>
                <rect class="map-bg" x="0" y="0" width="1120" height="720" rx="6"></rect>
                <rect class="map-frame" x="12" y="12" width="1096" height="696" rx="4"></rect>
                <path class="map-canal" d="M 16 690 C 180 628 300 714 435 668 C 560 625 655 690 780 658 C 900 628 1020 670 1104 640"></path>
                <ellipse class="map-green" cx="560" cy="352" rx="198" ry="165"></ellipse>
                <ellipse class="map-green" cx="254" cy="178" rx="178" ry="120"></ellipse>
                <ellipse class="map-green" cx="874" cy="180" rx="178" ry="120"></ellipse>
                <ellipse class="map-green" cx="244" cy="548" rx="176" ry="122"></ellipse>
                <ellipse class="map-green" cx="878" cy="548" rx="176" ry="122"></ellipse>
                ${decorations.join('')}
                ${roads.map((d) => `<path class="map-road" d="${d}"></path>`).join('')}
                ${roads.map((d) => `<path class="map-road-dash" d="${d}"></path>`).join('')}
                ${outlines}
                <text class="map-label" x="30" y="35">大学城 · 规划示意图</text>
                <text class="map-label" x="30" y="50">SCALE 1 : 985</text>
                <g class="map-compass" transform="translate(1070 650)">
                    <path d="M0,-25 L7,8 L0,3 L-7,8 Z" fill="currentColor" opacity=".55"></path>
                    <text x="0" y="22" text-anchor="middle">N</text>
                    <text x="0" y="34" text-anchor="middle" style="font-size:6px;letter-spacing:.12em">大学城</text>
                </g>
                <g opacity=".55">
                    <text class="map-compass" x="1000" y="688" text-anchor="end">教高赛制图 · 第 ${game.week} 周</text>
                </g>
                ${campusTags}
                ${tiles}
            </svg>
        `;
        if (window.innerWidth <= 820) {
            window.requestAnimationFrame(() => {
                const maxScroll = $mapStage.scrollWidth - $mapStage.clientWidth;
                $mapStage.scrollLeft = previousMapScroll > 8 ? previousMapScroll : Math.max(0, maxScroll / 2);
            });
        }
    }

    function renderCampusTag(school, districts, data) {
        const own = districts.filter((district) => district.slot_index >= school.slot * 4 && district.slot_index < school.slot * 4 + 4);
        if (!own.length) return '';
        const xs = own.map((d) => d.x);
        const ys = own.map((d) => d.y);
        const centerX = xs.reduce((a, b) => a + b, 0) / xs.length;
        let tagY;
        if (school.slot === 4) {
            tagY = Math.min(...ys) - 55;
        } else if (school.slot < 2) {
            tagY = Math.min(...ys) - 76;
        } else {
            tagY = Math.max(...ys) + 52;
        }
        const label = truncate(`${school.nickname}`, 9);
        const width = clamp(54 + label.length * 9, 128, 166);
        const isPlayer = school.is_player;
        const stroke = isPlayer ? '#9d211c' : '#cdbfa7';
        return `
            <g class="campus-tag" transform="translate(${centerX - width / 2} ${tagY - 10})">
                <rect x="0" y="0" width="${width}" height="20" rx="2" fill="${isPlayer ? 'rgba(157,33,28,.055)' : 'rgba(250,245,234,.86)'}" stroke="${stroke}" stroke-width="${isPlayer ? 1 : 0.8}"></rect>
                <rect x="0" y="0" width="3" height="20" fill="${esc(school.color)}"></rect>
                <text x="9" y="13" fill="${isPlayer ? '#9d211c' : '#3d3a31'}">${esc(label)}</text>
                <text class="tag-sub" x="${width - 7}" y="13" text-anchor="end">${isPlayer ? '我方' : '对手'}</text>
            </g>
        `;
    }

    function renderTile(district, week, data) {
        const owner = district.owner_id ? schoolById(district.owner_id) : null;
        const selected = state.selectedId === district.id;
        const disabled = Number(district.disabled_until) >= Number(week);
        const isPlayer = !!owner && owner.is_player;
        const fill = owner ? owner.color : '#ded2bf';
        const textColor = owner ? contrastText(fill) : '#3d382e';
        const ownerText = owner ? owner.short_name : '中立';
        const icon = kindIcon(district.kind);
        const x = district.x - district.w / 2;
        const y = district.y - district.h / 2;
        const displayName = !owner && district.kind === 'sports' ? '体育中心' : (!owner && district.kind === 'food' ? '美食广场' : district.name);
        const titleLines = splitTileLabel(displayName);
        const influenceEntries = Object.entries(district.influence)
            .map(([sid, value]) => ({ sid: Number(sid), value: Number(value) }))
            .filter((entry) => entry.value > 0 && entry.sid !== district.owner_id)
            .sort((a, b) => b.value - a.value);
        const topInfluence = influenceEntries[0] || null;
        const topSchool = topInfluence ? schoolById(topInfluence.sid) : null;
        const infColor = topSchool ? readableInfluenceColor(topSchool.color) : '#f0d18e';
        const classes = [
            'tile',
            isPlayer ? 'is-player' : '',
            selected ? 'is-selected' : '',
            !owner ? 'is-neutral' : '',
            disabled ? 'is-disabled' : '',
        ].filter(Boolean).join(' ');
        const labelY = titleLines.length > 1 ? 37 : 43;
        const secondY = labelY + 13;
        const ownerY = titleLines.length > 1 ? 64 : 60;
        const barY = 71;
        const playerLabel = isPlayer ? ' · 我方' : '';

        return `
            <g class="${classes}" data-id="${district.id}" tabindex="0" role="button" aria-label="${esc(district.name)}，${esc(owner ? owner.name : '中立区域')}">
                <title>${esc(district.name)} · ${esc(owner ? owner.name + ' · ' + owner.nickname : '中立区域')}${playerLabel}</title>
                <g class="tile-inner">
                    <rect x="${x}" y="${y}" width="${district.w}" height="${district.h}" rx="10" fill="${esc(fill)}" class="tile-bg"></rect>
                    ${owner ? '' : `<rect x="${x}" y="${y}" width="${district.w}" height="${district.h}" rx="10" fill="url(#neutralHatch)"></rect>`}
                    <rect x="${x}" y="${y}" width="${district.w}" height="${district.h}" rx="10" fill="url(#mapSheen)"></rect>
                    <circle class="tile-icon-bg" cx="${x + 17}" cy="${y + 16}" r="8.5"></circle>
                    <text class="tile-icon" x="${x + 17}" y="${y + 18.5}" text-anchor="middle" style="fill:${textColor};opacity:.9">${esc(icon)}</text>
                    ${titleLines.map((line, index) => `
                        <text class="tile-name-shadow" x="${x + district.w / 2 + 0.5}" y="${y + (index === 0 ? labelY : secondY) + 0.7}" text-anchor="middle" fill="${owner ? 'rgba(0,0,0,.16)' : 'rgba(255,255,255,.35)'}">${esc(line)}</text>
                        <text class="tile-label" x="${x + district.w / 2}" y="${y + (index === 0 ? labelY : secondY)}" text-anchor="middle" style="fill:${textColor}">${esc(line)}</text>
                    `).join('')}
                    <text class="tile-owner" x="${x + district.w / 2}" y="${y + ownerY}" text-anchor="middle" style="fill:${owner ? textColor : '#8e816a'};opacity:${owner ? .78 : 1}">${esc(ownerText)}${owner && owner.is_player ? ' · 我方' : ''}</text>
                    ${topInfluence ? `
                        <rect class="tile-inf-bar" x="${x + 9}" y="${y + barY}" width="${district.w - 18}" height="2.6" rx="1.3"></rect>
                        <rect class="tile-inf-fill" x="${x + 9}" y="${y + barY}" width="${(district.w - 18) * topInfluence.value / 100}" height="2.6" rx="1.3" style="fill:${infColor}"></rect>
                    ` : `<text class="tile-inf-text" x="${x + district.w / 2}" y="${y + barY + 3.4}" text-anchor="middle">${owner ? '' : '待认领'}</text>`}
                    ${topInfluence ? `<text class="tile-inf-text" x="${x + district.w / 2}" y="${y + barY + 4.4}" text-anchor="middle" style="fill:${owner ? textColor : '#6f6450'};opacity:${owner ? .78 : 1}">${esc(truncate(topSchool ? topSchool.short_name : '', 4))} 渗透 ${Math.round(topInfluence.value)}%</text>` : ''}
                    ${district.fortify > 0 ? `<text class="tile-fort" x="${x + district.w - 8}" y="${y + 16}" text-anchor="end" style="fill:${textColor}">城 ${Math.round(district.fortify)}</text>` : ''}
                    ${disabled ? `
                        <circle class="tile-disabled-badge" cx="${x + district.w - 15}" cy="${y + 29}" r="8"></circle>
                        <text class="tile-disabled-text" x="${x + district.w - 15}" y="${y + 31.5}" text-anchor="middle">瘫</text>
                    ` : ''}
                    ${isPlayer ? `<rect x="${x + 1}" y="${y + 1}" width="${district.w - 2}" height="${district.h - 2}" rx="9" fill="none" stroke="rgba(247,222,164,.7)" stroke-width="1" stroke-dasharray="2 4"></rect>` : ''}
                </g>
            </g>
        `;
    }

    function splitTileLabel(name) {
        const clean = String(name || '');
        if (clean.length <= 6) return [clean];
        if (clean.length <= 12) return [clean.slice(0, 6), clean.slice(6)];
        return [clean.slice(0, 6), `${clean.slice(6, 11)}…`];
    }

    function renderLegend() {
        const schools = state.data.schools;
        const items = schools.map((school) => `
            <span class="legend-item">
                <span class="legend-swatch ${school.is_player ? 'player' : ''}" style="background:${esc(school.color)}"></span>
                ${esc(school.short_name)}${school.is_player ? '（我方）' : ''}
            </span>
        `);
        items.push('<span class="legend-item"><span class="legend-swatch neutral"></span>公共区域</span>');
        $('#mapLegend').innerHTML = items.join('');
    }

    function renderTicker() {
        const news = (state.data.news || []).slice(-10);
        const items = news.length ? news : [{ text: '大学城风平浪静，各校正在悄悄修改培养方案。' }];
        const content = items.map((item) => `<span>${esc(item.text)}</span><b>◆</b>`).join('');
        const track = $('#newsTicker');
        track.innerHTML = content + content;
        const duration = clamp(items.reduce((sum, item) => sum + item.text.length, 0) * 0.75, 38, 90);
        track.style.animationDuration = `${duration}s`;
    }

    /* ---------------- 侧栏 ---------------- */

    function renderSidebar() {
        if (!state.data) return;
        $$('.side-tab').forEach((tab) => {
            tab.classList.toggle('active', tab.dataset.tab === state.tab);
        });
        if (state.tab === 'tech') {
            renderTechTab();
        } else if (state.tab === 'intel') {
            renderIntelTab();
        } else {
            renderActionTab();
        }
    }

    function renderActionTab() {
        const target = state.selectedId ? districtById(state.selectedId) : null;
        let html = '';
        if (state.data.game.status !== 'playing') {
            html += renderEndedCard();
        }

        if (target) {
            html += renderTargetCard(target);
        } else {
            html += `
                <div class="side-card">
                    <div class="side-card-head"><div class="side-card-title">选择战略目标</div><span class="side-card-badge">行动</span></div>
                    <div class="side-card-body">
                        <div class="target-empty">
                            <div class="empty-mark">图</div>
                            <h4>点击地图上的一个区块</h4>
                            <p>相邻区块可以投放学术交流；敌校区块还能发动学科评估战或舆论战。公共区域不直接产出论文，但能打通扩张路线。</p>
                        </div>
                    </div>
                </div>
            `;
        }

        html += renderPlayerCard();
        html += renderObjectiveCard();
        $sideBody.innerHTML = html;
        $sideBody.scrollTop = 0;
    }

    function renderEndedCard() {
        const end = state.data.game.end;
        const won = state.data.game.status === 'won';
        return `
            <div class="side-card">
                <div class="side-card-head"><div class="side-card-title">终局裁定</div><span class="side-card-badge">${won ? '胜' : '汰'}</span></div>
                <div class="side-card-body">
                    <div class="victory-card">
                        <div class="victory-mark">${won ? '胜' : '汰'}</div>
                        <h4>${won ? '吞并成功' : '淘汰出局'}</h4>
                        <p>${esc(end ? end.reason : '本局已经结束。')}</p>
                    </div>
                    <button class="quick-btn wide" type="button" data-act="show-end"><b>查看终局文件</b><span>重新打开终局裁定</span></button>
                </div>
            </div>
        `;
    }

    function renderTargetCard(district) {
        const owner = district.owner_id ? schoolById(district.owner_id) : null;
        const player = playerSchool();
        const game = state.data.game;
        const isOwn = owner && owner.is_player;
        const week = game.week;
        const disabled = Number(district.disabled_until) >= week;
        const ownerShielded = !!owner && Number(owner.ult_white_until || 0) >= week;
        const ap = game.ap;
        const funds = player.funds;
        const ended = game.status !== 'playing';

        const influenceRows = [];
        if (owner) {
            influenceRows.push({ school: owner, value: 100, owner: true });
        }
        Object.entries(district.influence)
            .map(([sid, value]) => ({ school: schoolById(Number(sid)), value: Number(value) }))
            .filter((entry) => entry.school && (!owner || entry.school.id !== owner.id))
            .sort((a, b) => b.value - a.value)
            .slice(0, 4)
            .forEach((entry) => influenceRows.push({ ...entry, owner: false }));

        const influenceHtml = influenceRows.length ? influenceRows.map((entry) => `
            <div class="influence-row">
                <span class="inf-name" style="color:${entry.owner ? 'var(--ink-soft)' : 'var(--muted)'}">${esc(entry.school.short_name)}</span>
                <span class="influence-track"><span style="width:${clamp(entry.value, 0, 100)}%;background:${esc(entry.school.color)}"></span></span>
                <b>${Math.round(entry.value)}%</b>
            </div>
        `).join('') : '<div class="report-empty">尚无势力渗透，公共区域待认领。</div>';

        const actions = [];
        if (!ended) {
            if (isOwn) {
                const cost = player.perk_info && player.perk_info.name === '基建狂魔' ? 11 : 15;
                actions.push(actionButton('fortify', '校区加固', `1 行政点 · ${cost} 万`, `城防 +${12 + (player.tech?.white || 0) * 2}，上限 60`, ap >= 1 && funds >= cost, '行政点或经费不足'));
            } else {
                const infEnabled = district.can_infiltrate && ap >= 1 && funds >= 10;
                actions.push(actionButton('infiltrate', '投放学术交流', '1 行政点 · 10 万', `渗透进度 +基础 21%（受城防与科技影响）`, infEnabled, actionBlockReason(district, ap, funds, 'infiltrate')));
                if (owner) {
                    const battleEnabled = district.can_battle && ap >= 2 && funds >= 45;
                    actions.push(actionButton('battle', '发动学科评估战', '2 行政点 · 45 万', '胜则瘫痪目标 2 周并大幅渗透', battleEnabled, actionBlockReason(district, ap, funds, 'battle')));
                    const campaignEnabled = district.can_campaign && ap >= 2 && funds >= 25;
                    actions.push(actionButton('campaign', '投放舆论战', '2 行政点 · 25 万', '直击对方校长支持率，无视距离', campaignEnabled, actionBlockReason(district, ap, funds, 'campaign')));
                }
            }
        }

        let note = '';
        if (ended) {
            note = '本局已经结束，无法继续行动。';
        } else if (!isOwn && !district.can_reach) {
            note = '该区块不与本校控制区相邻。先接管中间公共区域，或换一个目标。';
        } else if (game.active_event && game.active_event.id === 'lockdown' && !isOwn) {
            note = '疫情封控期间，跨校渗透与学科评估战暂停。可先进行校内维稳。';
        } else if (ownerShielded) {
            note = `「${owner.nickname}」已启动封校结界，本周无法渗透该校区。`;
        } else if (district.fortify > 0) {
            note = `目标城防 ${Math.round(district.fortify)}，会抵消部分渗透进度；学科评估战可先打瘫它。`;
        } else if (isOwn) {
            note = '加固校区可降低被渗透速度。城防越高，对方越难在评估战里打穿你。';
        } else {
            note = '渗透至 100% 即可接管。接管敌方区块还会削减对方支持率。';
        }

        return `
            <div class="side-card">
                <div class="side-card-head">
                    <div class="side-card-title">目标档案</div>
                    <span class="side-card-badge">${disabled ? '瘫痪中' : typeLabel(district.kind)}</span>
                </div>
                <div class="side-card-body">
                    <div class="target-head">
                        <div class="target-title">
                            <div class="target-owner">
                                <span class="owner-dot" style="background:${esc(owner ? owner.color : '#b9a98e')}"></span>
                                ${esc(owner ? `${owner.name} · ${owner.nickname}` : '中立区域 · 大学城管委会')}
                            </div>
                            <h3>${esc(district.name)}</h3>
                            <div class="target-type">${typeLabel(district.kind)} · 区块 #${district.slot_index + 1}</div>
                        </div>
                    </div>
                    <div class="target-stat-row">
                        <div class="target-stat"><span>周产出</span><b>${district.income}</b></div>
                        <div class="target-stat"><span>城防</span><b>${Math.round(district.fortify)}</b></div>
                        <div class="target-stat"><span>状态</span><b>${disabled ? '瘫痪' : '正常'}</b></div>
                    </div>
                    <div class="influence-list">${influenceHtml}</div>
                    <div class="action-list">${actions.join('')}</div>
                    ${note ? `<div class="action-note">${esc(note)}</div>` : ''}
                </div>
            </div>
        `;
    }

    function actionBlockReason(district, ap, funds, type) {
        if (state.data.game.status !== 'playing') return '本局已经结束';
        if (type === 'infiltrate') {
            if (!district.can_reach) return '不在相邻范围';
            if (state.data.game.active_event && state.data.game.active_event.id === 'lockdown') return '封控期间暂停跨校渗透';
            if (ap < 1) return '行政点不足';
            if (funds < 10) return '经费不足';
            if (!district.can_infiltrate) return '当前不可渗透';
        }
        if (type === 'battle') {
            if (!district.can_reach) return '不在相邻范围';
            if (state.data.game.active_event && state.data.game.active_event.id === 'lockdown') return '封控期间暂停学科战';
            if (ap < 2) return '行政点不足';
            if (funds < 45) return '经费不足';
            if (!district.can_battle) return '当前不可发动';
        }
        if (type === 'campaign') {
            if (ap < 2) return '行政点不足';
            if (funds < 25) return '经费不足';
            if (!district.can_campaign) return '当前不可发动';
        }
        return '暂不可用';
    }

    function actionButton(type, name, cost, desc, enabled, reason = '') {
        return `
            <button class="action-btn" type="button" data-act="${type}" ${enabled ? '' : 'disabled'} title="${esc(enabled ? desc : reason)}">
                <span class="action-icon">${actionIcon(type)}</span>
                <span class="action-text"><span class="action-name">${esc(name)}</span><span class="action-cost">${esc(cost)} · ${esc(desc)}</span></span>
                <span class="action-arrow">→</span>
            </button>
        `;
    }

    function renderPlayerCard() {
        const player = playerSchool();
        const game = state.data.game;
        const stabilizeCost = player.perk === 'medic' ? 20 : 25;
        const canStabilize = game.status === 'playing' && game.ap >= 1 && player.funds >= stabilizeCost;
        const canAce = game.status === 'playing' && !player.ace_used;
        return `
            <div class="side-card">
                <div class="side-card-head">
                    <div class="side-card-title">本校态势</div>
                    <span class="side-card-badge">${player.is_player ? '我方' : ''}</span>
                </div>
                <div class="side-card-body">
                    <div class="player-status-grid">
                        <div class="mini-meter">
                            <div class="mini-meter-top"><span>校长支持率</span><b>${player.support}%</b></div>
                            <div class="meter-track support"><span style="width:${clamp(player.support, 0, 100)}%"></span></div>
                        </div>
                        <div class="mini-meter">
                            <div class="mini-meter-top"><span>学生怨气</span><b>${player.grievance}%</b></div>
                            <div class="meter-track grievance"><span style="width:${clamp(player.grievance, 0, 100)}%"></span></div>
                        </div>
                    </div>
                    <div class="school-perks">
                        <div class="perk-box">
                            <div class="perk-label"><i>长</i>${esc(player.perk_info.name || '特长')}</div>
                            <p>${esc(player.perk_info.desc || '')}</p>
                        </div>
                        <div class="perk-box flaw">
                            <div class="perk-label"><i>短</i>${esc(player.flaw_info.name || '短板')}</div>
                            <p>${esc(player.flaw_info.desc || '')}</p>
                        </div>
                    </div>
                    <div class="quick-actions">
                        <button class="quick-btn" type="button" data-act="stabilize" ${canStabilize ? '' : 'disabled'} title="${canStabilize ? '食堂半价，取消早操' : '行政点或经费不足'}">
                            <b>校园维稳</b><span>1 行政点 · ${stabilizeCost} 万</span>
                        </button>
                        <button class="quick-btn" type="button" data-act="ace" ${canAce ? '' : 'disabled'} title="${player.ace_used ? '王牌已经用过' : '一次性王牌'}">
                            <b>${esc(player.ace_info.name)}</b><span>${player.ace_used ? '已使用' : '一次性 · 王牌'}</span>
                        </button>
                        <button class="quick-btn wide" type="button" data-act="switch-tab" data-tabtarget="tech">
                            <b>打开校策树 →</b><span>当前 ${player.tech_points} 校策点 · 红${player.tech?.red || 0} 白${player.tech?.white || 0} 黑${player.tech?.black || 0}</span>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }

    function renderObjectiveCard() {
        const game = state.data.game;
        const player = playerSchool();
        const progress = clamp((game.controlled / game.threshold) * 100, 0, 100);
        return `
            <div class="side-card">
                <div class="side-card-head"><div class="side-card-title">胜利条件</div><span class="side-card-badge">第 ${game.week} / ${game.max_week} 周</span></div>
                <div class="side-card-body">
                    <div class="mini-meter">
                        <div class="mini-meter-top"><span>吞并进度</span><b>${game.controlled} / ${game.threshold}</b></div>
                        <div class="meter-track progress"><span style="width:${progress}%"></span></div>
                    </div>
                    <div class="action-note" style="margin-top:9px">
                        控制 ${game.threshold} 个区块，或让所有对手支持率归零。第 ${game.max_week} 周仍未分出胜负时，科研经费最高者胜。
                        当前支持率 ${player.support}%，怨气 ${player.grievance}%。
                    </div>
                </div>
            </div>
        `;
    }

    /* ---------------- 校策 ---------------- */

    function renderTechTab() {
        const player = playerSchool();
        const game = state.data.game;
        const techs = state.data.techs;
        const branchOrder = ['red', 'white', 'black'];
        const branchHtml = branchOrder.map((branchKey) => {
            const branch = techs[branchKey];
            const nodes = branch.nodes.map((node) => {
                const classes = ['tech-node'];
                if (node.unlocked) classes.push('unlocked');
                else if (node.next) classes.push('next');
                else classes.push('locked');
                const clickable = node.next && node.can_unlock && game.status === 'playing';
                const attrs = clickable ? `data-act="tech_unlock" data-branch="${branchKey}"` : 'disabled';
                return `
                    <button class="${classes.join(' ')}" type="button" ${attrs} title="${esc(node.flavor)}">
                        <span class="node-level">0${node.level}</span>
                        <span class="node-main">
                            <span class="node-name">${esc(node.name)} ${node.next ? `<span class="node-cost">${node.cost} 点</span>` : ''}</span>
                            <span class="node-desc">${esc(node.desc)}</span>
                        </span>
                        <span class="node-check">${node.unlocked ? '已' : (node.next ? (node.can_unlock ? '可' : '缺') : '')}</span>
                    </button>
                `;
            }).join('');

            return `
                <div class="tech-branch">
                    <div class="branch-head">
                        <span class="branch-icon" style="background:${esc(branch.color)}">${esc(branch.icon)}</span>
                        <span class="branch-title"><b>${esc(branch.name)}</b><span>${esc(branch.subtitle)}</span></span>
                        <span class="branch-level">Lv.${branch.level} / 4</span>
                    </div>
                    <div class="tech-nodes">${nodes}</div>
                </div>
            `;
        }).join('');

        const aceHtml = `
            <div class="side-card">
                <div class="side-card-head"><div class="side-card-title">本校王牌</div><span class="side-card-badge">${player.ace_used ? '已使用' : '一次性'}</span></div>
                <div class="side-card-body">
                    <div style="display:flex;align-items:flex-start;gap:9px">
                        <span class="tech-point-seal" style="width:38px;height:38px;font-size:15px">牌</span>
                        <div style="flex:1;min-width:0">
                            <div style="font-family:var(--serif);font-weight:700;font-size:13px;margin-bottom:3px">${esc(player.ace_info.name)}</div>
                            <div style="color:var(--muted);font-size:8.5px;line-height:1.6">${esc(player.ace_info.desc)}</div>
                        </div>
                    </div>
                    <div class="quick-actions">
                        <button class="quick-btn wide" type="button" data-act="ace" ${!player.ace_used && game.status === 'playing' ? '' : 'disabled'}>
                            <b>${player.ace_used ? '王牌已用过' : '现在启用王牌'}</b><span>${player.ace_used ? '没有第二次机会' : '不消耗行政点'}</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        const branchSchool = state.data.schools.find((school) => school.id === state.data.game.player_school_id);
        const ultButtons = [];
        for (const key of branchOrder) {
            const level = player.tech?.[key] || 0;
            if (level < 4) continue;
            if (key === 'red') {
                const cd = Math.max(0, (branchSchool.ult_red_cd || 0) - game.week);
                const inspection = game.active_event && game.active_event.id === 'inspection';
                const disabled = cd > 0 || inspection || game.status !== 'playing';
                ultButtons.push(`<button class="ult-btn" type="button" data-act="tech_ult" data-branch="red" ${disabled ? 'disabled' : ''}><b>QS 排名注水术</b><span>${inspection ? '突击检查中，禁用' : (cd > 0 ? `冷却 ${cd} 周` : '全体对手支持率 -7')}</span></button>`);
            } else if (key === 'white') {
                const shielded = player.shielded;
                const cd = (branchSchool.ult_white_until || 0) > 0 ? Math.max(0, (branchSchool.ult_white_until || 0) + 4 - game.week) : 0;
                const disabled = shielded || cd > 0 || game.status !== 'playing';
                ultButtons.push(`<button class="ult-btn" type="button" data-act="tech_ult" data-branch="white" ${disabled ? 'disabled' : ''}><b>封校结界</b><span>${shielded ? '结界生效中' : (cd > 0 ? `冷却 ${cd} 周` : '2 周免疫敌方渗透')}</span></button>`);
            } else {
                const cd = Math.max(0, (branchSchool.ult_black_cd || 0) - game.week);
                const disabled = cd > 0 || game.status !== 'playing';
                ultButtons.push(`<button class="ult-btn" type="button" data-act="tech_ult" data-branch="black" ${disabled ? 'disabled' : ''}><b>跨校绰号刷屏</b><span>${cd > 0 ? `冷却 ${cd} 周` : '全体对手怨气 +18'}</span></button>`);
            }
        }

        $sideBody.innerHTML = `
            <div class="tech-summary">
                <div class="tech-point-seal">${player.tech_points}</div>
                <div class="tech-summary-text">
                    <b>可用校策点</b>
                    <span>每周自动增加 2 点。三条路线不能全点，选一条走到底，或在中途改换赛道。</span>
                </div>
            </div>
            ${branchHtml}
            ${aceHtml}
            <div class="side-card">
                <div class="side-card-head"><div class="side-card-title">终极杀招</div><span class="side-card-badge">路线 Lv.4 解锁</span></div>
                <div class="side-card-body">
                    ${ultButtons.length ? `<div class="ult-row">${ultButtons.join('')}</div>` : '<div class="report-empty">尚未解锁任何终极杀招。先把某条路线点到 Lv.4。</div>'}
                </div>
            </div>
        `;
        $sideBody.scrollTop = 0;
    }

    /* ---------------- 情报 ---------------- */

    function renderIntelTab() {
        const data = state.data;
        const game = data.game;
        const player = playerSchool();
        const event = game.active_event;
        const schools = [...data.schools].sort((a, b) => {
            if (a.is_player !== b.is_player) return a.is_player ? -1 : 1;
            if (a.eliminated !== b.eliminated) return a.eliminated ? 1 : -1;
            return b.territories - a.territories;
        });

        const eventHtml = event ? `
            <div class="event-card">
                <div class="event-card-top">
                    <span class="event-icon">${esc(event.icon || '事')}</span>
                    <h4>${esc(event.name)}</h4>
                </div>
                <p>${esc(event.desc)}</p>
                <span class="event-until">影响持续至第 ${event.until} 周</span>
            </div>
        ` : '<div class="report-empty" style="margin-bottom:12px">本周暂无全城事件。平静得有些可疑。</div>';

        const schoolHtml = schools.map((school) => `
            <div class="intel-school ${school.is_player ? 'is-player' : ''} ${school.eliminated ? 'is-dead' : ''}" title="${esc(school.name)} · ${esc(school.motto || '')}">
                <div class="intel-head">
                    <span class="intel-emblem" style="background:${esc(school.color)}">${esc(school.emblem)}</span>
                    <span class="intel-name">
                        <b>${esc(school.name)}${school.is_player ? ' · 我方' : ''}${school.eliminated ? ' · 已出局' : ''}</b>
                        <span>${esc(school.nickname)} · ${esc(school.perk_info.name || '')} / ${esc(school.flaw_info.name || '')}</span>
                    </span>
                    <span class="intel-count"><b>${school.territories}</b><span>区块</span></span>
                </div>
                <div class="intel-meters">
                    <div class="intel-meter-line"><span>支持 ${school.support}</span><span class="meter-track support"><span style="width:${clamp(school.support, 0, 100)}%"></span></span></div>
                    <div class="intel-meter-line"><span>怨气 ${school.grievance}</span><span class="meter-track grievance"><span style="width:${clamp(school.grievance, 0, 100)}%"></span></span></div>
                </div>
            </div>
        `).join('');

        const logs = (data.logs || []).slice(-18).reverse().map((log) => `
            <div class="log-item"><span class="log-week">第${log.week}周</span><span class="log-text">${esc(log.text)}</span></div>
        `).join('');

        $sideBody.innerHTML = `
            <div class="side-card">
                <div class="side-card-head"><div class="side-card-title">全城事件</div><span class="side-card-badge">${event ? '进行中' : '风平浪静'}</span></div>
                <div class="side-card-body">${eventHtml}</div>
            </div>
            <div class="side-card">
                <div class="side-card-head"><div class="side-card-title">高校档案</div><span class="side-card-badge">${schools.length} 所</span></div>
                <div class="side-card-body">${schoolHtml}</div>
            </div>
            <div class="side-card">
                <div class="side-card-head"><div class="side-card-title">大学城日志</div><span class="side-card-badge">最近 18 条</span></div>
                <div class="side-card-body"><div class="log-list">${logs || '<div class="report-empty">暂无记录。</div>'}</div></div>
            </div>
        `;
        $sideBody.scrollTop = 0;
    }

    /* ---------------- 行动与结算 ---------------- */

    async function doGameAction(type, extra = {}) {
        if (!state.data || state.busy) return;
        if (state.data.game.status !== 'playing') {
            showToast('本局已经结束。', 'error');
            return;
        }
        const payload = { ...extra };
        if (['infiltrate', 'battle', 'campaign', 'fortify'].includes(type)) {
            if (!state.selectedId) {
                showToast('请先选择一个区块。', 'error');
                return;
            }
            payload.district_id = state.selectedId;
        }
        if (type === 'tech_unlock' || type === 'tech_ult') {
            payload.branch = extra.branch;
        }

        state.busy = true;
        try {
            const res = await apiPost({
                action: 'action',
                game_id: state.gameId,
                type,
                payload,
            });
            state.data = res.state;
            if (state.selectedId && !districtById(state.selectedId)) {
                state.selectedId = null;
            }
            renderGame();
            showResultDoc(res.result);
            if (res.state.game.status !== 'playing') {
                state.pendingEnd = true;
            }
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            state.busy = false;
            if (state.data) {
                $('#endTurnBtn').disabled = state.data.game.status !== 'playing';
            }
        }
    }

    function confirmDialog({ kicker = '教高赛 · 内部请示', title, body, confirmText = '同意', cancelText = '再想想', danger = false }, onConfirm) {
        openModalRaw(`
            <div class="modal-scrim" data-close-modal="1"></div>
            <section class="doc-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
                <div class="doc-topline"></div>
                <div class="doc-head">
                    <div class="doc-org">大学城动态淘汰赛组委会</div>
                    <div class="doc-code">${esc(kicker)}</div>
                </div>
                <div class="doc-body">
                    <h2 class="doc-title" id="confirmTitle">${esc(title)}</h2>
                    <div class="doc-lines"><div class="doc-line">${esc(body)}</div></div>
                    <div class="doc-stamp">请<br>批示</div>
                </div>
                <div class="doc-foot">
                    <div class="doc-foot-left">经办：战略指挥部办公室<br>本文件不进入个人档案</div>
                    <div class="doc-actions">
                        <button class="btn btn-paper" type="button" data-close-modal="1">${esc(cancelText)}</button>
                        <button class="btn ${danger ? 'btn-red' : 'btn-ink'}" type="button" id="confirmYes">${esc(confirmText)}</button>
                    </div>
                </div>
            </section>
        `);
        $('#confirmYes').addEventListener('click', () => {
            closeModal();
            onConfirm();
        });
    }

    function endTurn() {
        if (!state.data || state.busy) return;
        if (state.data.game.status !== 'playing') return;
        const game = state.data.game;
        const body = game.ap > 0
            ? `第 ${game.week} 周结束前，你还有 ${game.ap} 点行政点未使用。未使用的行政点不会累积。确定盖章推进吗？`
            : `本周行政点已用完。确认结束第 ${game.week} 周，进入各校结算与 AI 行动阶段？`;
        confirmDialog({
            kicker: `教高赛〔2026〕第 ${game.week} 周结算`,
            title: `结束第 ${game.week} 周？`,
            body,
            confirmText: '盖章推进',
            cancelText: '继续行动',
        }, doEndTurn);
    }

    async function doEndTurn() {
        state.busy = true;
        setLoading(true, '正在召开周例会', '各校校长正在互相读空气……');
        try {
            const res = await apiPost({ action: 'end_turn', game_id: state.gameId });
            state.data = res.state;
            state.selectedId = null;
            setLoading(false);
            renderGame();
            showWeeklyReport(res.summary);
            if (res.state.game.status !== 'playing') {
                state.pendingEnd = true;
            }
        } catch (error) {
            setLoading(false);
            showToast(error.message, 'error');
        } finally {
            state.busy = false;
            if (state.data) {
                $('#endTurnBtn').disabled = state.data.game.status !== 'playing';
            }
        }
    }

    /* ---------------- 弹窗 ---------------- */

    function openModalRaw(html) {
        $modalRoot.hidden = false;
        $modalRoot.innerHTML = html;
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        $modalRoot.hidden = true;
        $modalRoot.innerHTML = '';
        document.body.style.overflow = '';
        if (state.pendingEnd) {
            state.pendingEnd = false;
            window.setTimeout(() => showEndOverlay(), 180);
        }
    }

    function showResultDoc(result) {
        const lines = (result.lines || []).map((line) => `<div class="doc-line">${esc(line)}</div>`).join('');
        const battle = result.battle ? `
            <div class="battle-versus">
                <div class="battle-side"><span>${esc(result.battle.attacker)}</span><b>${result.battle.attack}</b></div>
                <div class="battle-vs">VS</div>
                <div class="battle-side"><span>${esc(result.battle.defender)}</span><b>${result.battle.defense}</b></div>
            </div>
        ` : '';
        const ended = state.data && state.data.game.status !== 'playing';
        openModalRaw(`
            <div class="modal-scrim" data-close-modal="1"></div>
            <section class="doc-modal" role="dialog" aria-modal="true" aria-labelledby="docTitle">
                <div class="doc-topline"></div>
                <div class="doc-head">
                    <div class="doc-org">大学城动态淘汰赛组委会</div>
                    <div class="doc-code">教高赛〔2026〕第 ${state.data?.game.week || 1} 号 · 自动备案</div>
                </div>
                <div class="doc-body">
                    <h2 class="doc-title" id="docTitle">${esc(result.title)}</h2>
                    ${battle}
                    <div class="doc-lines">${lines}</div>
                    <div class="doc-stamp">${esc(result.stamp || '已备案').split('').join('<br>')}</div>
                </div>
                <div class="doc-foot">
                    <div class="doc-foot-left">经办：大学城战略指挥部<br>本文件由系统自动生成，盖章有效</div>
                    <div class="doc-actions">
                        <button class="btn btn-red" type="button" data-close-modal="1">${ended ? '查看终局' : '收到，继续'}</button>
                    </div>
                </div>
            </section>
        `);
    }

    function showScreenWelcome() {
        if (!state.data) return;
        openModalRaw(`
            <div class="modal-scrim" data-close-modal="1"></div>
            <section class="doc-modal" role="dialog" aria-modal="true" aria-labelledby="welcomeTitle">
                <div class="doc-topline"></div>
                <div class="doc-head">
                    <div class="doc-org">大学城动态淘汰赛组委会</div>
                    <div class="doc-code">教高赛〔2026〕1 号 · 开赛批复</div>
                </div>
                <div class="doc-body">
                    <h2 class="doc-title" id="welcomeTitle">关于同意五所高校进入大学城开展动态淘汰赛的批复</h2>
                    <div class="doc-lines">
                        <div class="doc-line">经研究，同意你们以「${esc(state.data.player.name)}」名义参战。你的校区位于大学城中央，四方高校环伺。</div>
                        <div class="doc-line">点击地图上的相邻区块，投放学术交流。渗透度达到 100% 即可接管。公共区域是扩张跳板，敌方校区则可以发动学科评估战与舆论战。</div>
                        <div class="doc-line">控制 ${state.data.game.threshold} 个区块，或让所有对手校长支持率归零。第 60 周仍未分出胜负时，科研经费最高者胜。</div>
                        <div class="doc-line">提醒：不要把所有校策点都点在“买热搜”上。真的会破产。</div>
                    </div>
                    <div class="doc-stamp">准<br>予<br>开<br>赛</div>
                </div>
                <div class="doc-foot">
                    <div class="doc-foot-left">抄送：五校校长办公室、大学城食堂联合会<br>附件：无</div>
                    <div class="doc-actions"><button class="btn btn-red" type="button" data-close-modal="1">开始吞并</button></div>
                </div>
            </section>
        `);
    }

    function aggregateActions(items) {
        const map = new Map();
        (items || []).forEach((item) => {
            const key = `${item.school_id || item.school}|${item.text}`;
            if (map.has(key)) {
                map.get(key).count += 1;
            } else {
                map.set(key, { ...item, count: 1 });
            }
        });
        return [...map.values()];
    }

    function showWeeklyReport(summary) {
        if (!summary) return;
        const event = summary.event;
        const supportDiff = Number(summary.support_after) - Number(summary.support_before);
        const grievanceDiff = Number(summary.grievance_after) - Number(summary.grievance_before);
        const aiActions = aggregateActions(summary.ai_actions).map((action) => `
            <div class="report-news"><span class="dot" style="background:${esc(action.color || '#9d211c')}"></span><span><b>${esc(action.school)}</b>：${esc(action.text)}${action.count > 1 ? ` <em>×${action.count}</em>` : ''}</span></div>
        `).join('');
        const battles = aggregateActions([...(summary.battles || []), ...(summary.captures || [])]).map((item) => `
            <div class="report-news"><span class="dot" style="background:${esc(item.color || '#9d211c')}"></span><span><b>${esc(item.school)}</b>：${esc(item.text)}${item.count > 1 ? ` <em>×${item.count}</em>` : ''}</span></div>
        `).join('');
        const eventHtml = event ? `
            <div class="event-banner"><b>${esc(event.icon || '事')} · ${esc(event.name)}</b><p>${esc(event.desc)}</p></div>
        ` : '';
        const protestHtml = summary.protest ? `<div class="event-banner"><b>校内游行</b><p>${esc(summary.protest)}</p></div>` : '';
        const statusEnded = summary.status && summary.status !== 'playing';

        openModalRaw(`
            <div class="modal-scrim" data-close-modal="1"></div>
            <section class="report-modal" role="dialog" aria-modal="true" aria-labelledby="reportTitle">
                <div class="report-head">
                    <div>
                        <div class="report-kicker">大学城动态淘汰赛 · 周度简报</div>
                        <h2 id="reportTitle">${esc(summary.headline || '大学城周报')}</h2>
                    </div>
                    <div class="report-week">第 <b>${summary.week}</b> 周结算</div>
                </div>
                <div class="report-body">
                    ${eventHtml}
                    ${protestHtml}
                    <div class="report-summary-grid">
                        <div class="report-stat"><span>本周科研经费</span><b class="up">+${summary.income} 万</b></div>
                        <div class="report-stat"><span>校长支持率</span><b class="${supportDiff < 0 ? 'down' : 'up'}">${summary.support_before} → ${summary.support_after}</b></div>
                        <div class="report-stat"><span>学生怨气</span><b class="${grievanceDiff > 0 ? 'down' : 'up'}">${summary.grievance_before} → ${summary.grievance_after}</b></div>
                    </div>
                    <div class="report-section">
                        <h3>别校动向</h3>
                        <div class="report-news-list">${aiActions || '<div class="report-empty">本周各校都在补作业，没有特别动向。</div>'}</div>
                    </div>
                    ${battles ? `<div class="report-section"><h3>冲突与版图</h3><div class="report-news-list">${battles}</div></div>` : ''}
                    <div class="report-section">
                        <h3>教务处提示</h3>
                        <div class="report-news">${summary.status === 'won' ? '吞并计划完成。请准备接受无限经费与无穷无尽的评估材料。' : summary.status === 'lost' ? '很遗憾，本校独立建制已经撤销。请把校牌擦干净，准备换新名字。' : '行政点已重置。未使用的点数不会累积，建议本周就把讲座排满。'}</div>
                    </div>
                </div>
                <div class="report-foot">
                    <div class="hint">${statusEnded ? '终局已产生，点击查看结果。' : `下一周：第 ${summary.week + 1} 周`}</div>
                    <div class="doc-actions"><button class="btn btn-red" type="button" data-close-modal="1">${statusEnded ? '查看终局' : '盖章，进入下一周'}</button></div>
                </div>
            </section>
        `);
    }

    function showEndOverlay(force = false) {
        if (!state.data) return;
        const game = state.data.game;
        if (!game.end) return;
        if (force) state.endOverlayShown = null;
        if (state.endOverlayShown === `${game.id}:${game.status}`) return;
        state.endOverlayShown = `${game.id}:${game.status}`;
        const player = state.data.player;
        const won = game.status === 'won';
        const overlay = document.createElement('div');
        overlay.className = 'end-overlay';
        overlay.innerHTML = `
            <div class="end-card">
                <div class="end-kicker">大学城动态淘汰赛 · 终局裁定</div>
                <div class="end-seal">${won ? '胜' : '汰'}</div>
                <h2 class="end-title">${won ? '吞并成功' : '淘汰出局'}</h2>
                <p class="end-reason">${esc(game.end.reason || '')}</p>
                <div class="end-stats">
                    <div class="end-stat"><span>最终周数</span><b>${game.week}</b></div>
                    <div class="end-stat"><span>控制区块</span><b>${game.controlled}</b></div>
                    <div class="end-stat"><span>科研经费</span><b>${player.funds}</b></div>
                </div>
                <div class="end-actions">
                    <button class="btn btn-paper" type="button" data-end-close="1">再看看地图</button>
                    <button class="btn btn-red" type="button" data-end-new="1">回到指挥部</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-end-close]')) {
                overlay.remove();
                document.body.style.overflow = '';
                return;
            }
            if (event.target.closest('[data-end-new]')) {
                overlay.remove();
                document.body.style.overflow = '';
                returnToStart();
            }
        });
    }

    function returnToStart() {
        closeModal();
        state.data = null;
        state.gameId = '';
        state.selectedId = null;
        const url = new URL(window.location.href);
        url.searchParams.delete('game');
        window.history.replaceState(null, '', url);
        showScreen('start');
        $resumeBtn.hidden = !safeStorageGet(STORAGE_LAST);
        renderRoster();
        window.scrollTo({ top: 0, behavior: 'auto' });
    }

    function showHelp() {
        openModalRaw(`
            <div class="modal-scrim" data-close-modal="1"></div>
            <section class="doc-modal" role="dialog" aria-modal="true" aria-labelledby="helpTitle">
                <div class="doc-topline"></div>
                <div class="doc-head">
                    <div class="doc-org">大学城战略指挥部 · 内部手册</div>
                    <div class="doc-code">阅读后请勿转交其他高校</div>
                </div>
                <div class="doc-body">
                    <h2 class="doc-title" id="helpTitle">大学城生存手册（极简版）</h2>
                    <div class="doc-lines">
                        <div class="doc-line"><b>一、渗透：</b>只能从本校相邻区块出发。每次 1 行政点 + 10 万经费，进度到 100% 即接管。公共区域能打通更多路线。</div>
                        <div class="doc-line"><b>二、战斗：</b>学科评估战只能打相邻高校。胜则目标瘫痪 2 周、大幅渗透并扣对方支持率；败则本校支持率下降。</div>
                        <div class="doc-line"><b>三、舆论：</b>无视距离，直接削减对方校长支持率。支持率归零，学校提前出局，名下区块暂时无主。</div>
                        <div class="doc-line"><b>四、校策：</b>每周 +2 校策点。红专偏进攻，白专偏防御与后勤，黑线偏舆论。三条路线无法全点，终极节点解锁杀招。</div>
                        <div class="doc-line"><b>五、怨气：</b>每周自然增长。超过 80% 可能爆发校内游行，扣经费、扣支持率、少 1 点行政点。食堂半价是最便宜的维稳。</div>
                        <div class="doc-line"><b>六、胜利：</b>控制 ${state.data ? state.data.game.threshold : 15} 个区块，或让所有对手出局。第 60 周按科研经费结算。</div>
                        <div class="doc-line"><b>七、操作：</b>点击地图区块 → 右侧“行动”页 → 选择行动。结束本周后自动存档。刷新页面可继续。</div>
                    </div>
                    <div class="doc-stamp">阅<br>毕</div>
                </div>
                <div class="doc-foot">
                    <div class="doc-foot-left">版本 ${esc(CONFIG.version)} · 适用于单机人机局<br>解释权归大学城管委会所有</div>
                    <div class="doc-actions"><button class="btn btn-red" type="button" data-close-modal="1">知道了</button></div>
                </div>
            </section>
        `);
    }

    /* ---------------- 事件绑定 ---------------- */

    function bindEvents() {
        $('#startGameBtn').addEventListener('click', startGame);
        $('#quickStartBtn').addEventListener('click', startGame);
        $('#randomLineupBtn').addEventListener('click', randomLineup);
        $('#resetLineupBtn').addEventListener('click', resetLineup);
        $('#addCustomBtn').addEventListener('click', () => openCustomBuilder(0));
        $rosterList.addEventListener('change', onRosterChange);
        $rosterList.addEventListener('click', (event) => {
            const button = event.target.closest('[data-custom-slot]');
            if (button) openCustomBuilder(Number(button.dataset.customSlot));
        });

        $resumeBtn.addEventListener('click', () => {
            const gameId = safeStorageGet(STORAGE_LAST);
            if (gameId && /^[a-f0-9]{16}$/.test(gameId)) {
                loadGame(gameId, true);
            } else {
                $resumeBtn.hidden = true;
                showToast('没有找到可继续的对局。', 'error');
            }
        });

        $$('.side-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                state.tab = tab.dataset.tab;
                renderSidebar();
            });
        });

        $sideBody.addEventListener('click', (event) => {
            const target = event.target.closest('[data-act]');
            if (!target || target.disabled) return;
            const act = target.dataset.act;
            if (act === 'show-end') {
                showEndOverlay(true);
                return;
            }
            if (act === 'switch-tab') {
                state.tab = target.dataset.tabtarget || 'action';
                renderSidebar();
                return;
            }
            if (act === 'tech_unlock' || act === 'tech_ult') {
                doGameAction(act, { branch: target.dataset.branch });
                return;
            }
            if (act === 'ace') {
                confirmDialog({
                    kicker: '教高赛 · 王牌启用申请',
                    title: `启用王牌「${state.data.player.ace_info.name}」？`,
                    body: `王牌每局只能使用一次。效果：${state.data.player.ace_info.desc}`,
                    confirmText: '盖章启用',
                }, () => doGameAction('ace'));
                return;
            }
            doGameAction(act);
        });

        $mapStage.addEventListener('click', (event) => {
            const tile = event.target.closest('.tile');
            if (!tile) return;
            selectDistrict(Number(tile.dataset.id));
        });

        $mapStage.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const tile = event.target.closest('.tile');
            if (!tile) return;
            event.preventDefault();
            selectDistrict(Number(tile.dataset.id));
        });

        $('#endTurnBtn').addEventListener('click', endTurn);
        $('#helpBtn').addEventListener('click', showHelp);
        $('#newGameBtn').addEventListener('click', () => {
            confirmDialog({
                kicker: '教高赛 · 中止当前推演',
                title: '返回指挥部，重开一局？',
                body: '当前对局会保存在数据库中，稍后仍可从开始页“继续上局”读取。确定要离开当前战场吗？',
                confirmText: '返回指挥部',
                cancelText: '继续战斗',
                danger: true,
            }, returnToStart);
        });

        $modalRoot.addEventListener('click', (event) => {
            if (event.target.closest('[data-close-modal]')) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !$modalRoot.hidden) {
                closeModal();
            }
        });
    }

    function selectDistrict(id) {
        if (!state.data) return;
        state.selectedId = id;
        state.tab = 'action';
        renderMap();
        renderSidebar();
        if (window.innerWidth <= 1080) {
            const sidebar = $('.sidebar');
            if (sidebar) {
                window.setTimeout(() => sidebar.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
            }
        }
    }

    /* ---------------- 启动 ---------------- */

    init();
})();
