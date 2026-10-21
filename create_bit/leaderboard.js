(function () {
  "use strict";

  if (window.__BIT_LEADERBOARD_LOADED__) {
    return;
  }
  window.__BIT_LEADERBOARD_LOADED__ = true;

  var HOST_CONFIG = window.BIT_LEADERBOARD_CONFIG || {};
  var GAME_CONFIG = window.MERGE_GAME_CONFIG || {};
  var LEVELS = Array.isArray(GAME_CONFIG.levels) ? GAME_CONFIG.levels : [];
  var LEVEL_NAMES = Array.isArray(GAME_CONFIG.levelNames) ? GAME_CONFIG.levelNames : [];
  var DEFAULT_API_CANDIDATES = ["api.php"];
  var API_CANDIDATES = Array.isArray(HOST_CONFIG.apiCandidates) && HOST_CONFIG.apiCandidates.length
    ? HOST_CONFIG.apiCandidates.slice()
    : DEFAULT_API_CANDIDATES;
  var FORCED_API_BASE = typeof HOST_CONFIG.apiBase === "string" ? HOST_CONFIG.apiBase.trim() : "";
  var SESSION_KEY = "bit-merge:api-base:v1";
  var REQUEST_TIMEOUT_MS = 9000;
  var PROBE_TIMEOUT_MS = 4500;
  var TOAST_MS = 2600;
  var MAX_LEVEL = LEVELS.length > 0 ? LEVELS.length - 1 : 8;
  var TOKEN_FAILURE_CODES = {
    RUN_NOT_FOUND: true,
    RUN_USED: true,
    RUN_EXPIRED: true,
    RUN_FORBIDDEN: true
  };
  var NON_RETRY_CODES = {
    SCORE_REJECTED: true,
    INVALID_INPUT: true,
    RUN_NOT_FOUND: true,
    RUN_USED: true,
    RUN_EXPIRED: true,
    RUN_FORBIDDEN: true,
    USERNAME_TAKEN: true,
    INVALID_CREDENTIALS: true,
    RATE_LIMITED: true
  };

  var state = {
    player: null,
    apiBase: null,
    apiPromise: null,
    apiUnavailable: false,
    lastProbeAt: 0,
    runId: null,
    runStartedAt: 0,
    runPromise: null,
    runSeq: 0,
    finishSeq: 0,
    pending: null,
    submitting: false,
    leaderboard: null,
    leaderboardLoading: false,
    authMode: "login",
    authReturnToLeaderboard: false,
    pausedByUs: false,
    activeModal: null,
    toastTimer: null
  };

  var dom = {};

  function byId(id) {
    return document.getElementById(id);
  }

  function initDom() {
    [
      "leaderboardButton", "accountButton",
      "gameOverOverlay", "rankPanel", "rankHint", "rankResult",
      "rankLoginButton", "rankRetryButton", "rankViewButton",
      "leaderboardOverlay", "leaderboardCloseButton", "leaderboardSub",
      "leaderboardList", "leaderboardMe", "leaderboardRefreshButton",
      "leaderboardLoginButton", "leaderboardLogoutButton",
      "authOverlay", "authCloseButton", "authTitle", "authSub",
      "authLoginTab", "authRegisterTab", "authForm", "authUsername",
      "authPassword", "authPasswordConfirm", "authPasswordConfirmField",
      "authHint", "authError", "authSubmit", "toast"
    ].forEach(function (id) {
      dom[id] = byId(id);
    });
    return Boolean(dom.leaderboardOverlay && dom.authOverlay && dom.gameOverOverlay);
  }

  function show(element) {
    if (element) {
      element.hidden = false;
    }
  }

  function hide(element) {
    if (element) {
      element.hidden = true;
    }
  }

  function text(element, value) {
    if (element) {
      element.textContent = value;
    }
  }

  function formatScore(value) {
    var number = Number(value);
    if (!Number.isFinite(number)) {
      return "0";
    }
    return Math.max(0, Math.round(number)).toLocaleString("zh-CN");
  }

  function pad2(value) {
    return value < 10 ? "0" + value : String(value);
  }

  function formatDate(seconds) {
    var value = Number(seconds);
    if (!Number.isFinite(value) || value <= 0) {
      return "";
    }
    var date = new Date(value * 1000);
    var now = new Date();
    var stamp = pad2(date.getMonth() + 1) + "-" + pad2(date.getDate());
    if (date.getFullYear() !== now.getFullYear()) {
      stamp = date.getFullYear() + "-" + stamp;
    }
    return stamp;
  }

  function levelLabel(index) {
    var value = Number(index);
    if (!Number.isFinite(value) || value < 0) {
      return "Lv.1";
    }
    var name = LEVEL_NAMES[value] || "";
    return "Lv." + (value + 1) + (name ? " · " + name : "");
  }

  function toast(message) {
    if (!dom.toast) {
      return;
    }
    if (state.toastTimer) {
      window.clearTimeout(state.toastTimer);
    }
    text(dom.toast, message);
    show(dom.toast);
    state.toastTimer = window.setTimeout(function () {
      state.toastTimer = null;
      hide(dom.toast);
    }, TOAST_MS);
  }

  function ApiError(code, message) {
    this.name = "ApiError";
    this.code = code || "REQUEST_FAILED";
    this.message = message || "请求失败，请稍后再试。";
  }
  ApiError.prototype = Object.create(Error.prototype);
  ApiError.prototype.constructor = ApiError;

  function errorMessage(error, fallback) {
    if (error && typeof error.message === "string" && error.message) {
      return error.message;
    }
    return fallback || "网络不太顺畅，请稍后再试。";
  }

  function withAction(base, action, query) {
    var params = [];
    if (query && typeof query === "object") {
      Object.keys(query).forEach(function (key) {
        if (query[key] !== undefined && query[key] !== null) {
          params.push(encodeURIComponent(key) + "=" + encodeURIComponent(String(query[key])));
        }
      });
    }
    params.push("action=" + encodeURIComponent(action));
    return base + (base.indexOf("?") >= 0 ? "&" : "?") + params.join("&");
  }

  function readStoredBase() {
    try {
      var value = window.sessionStorage.getItem(SESSION_KEY);
      return typeof value === "string" && value.trim() ? value.trim() : "";
    } catch (error) {
      return "";
    }
  }

  function storeBase(base) {
    try {
      window.sessionStorage.setItem(SESSION_KEY, base);
    } catch (error) {
      // sessionStorage may be unavailable in private mode.
    }
  }

  function clearStoredBase() {
    try {
      window.sessionStorage.removeItem(SESSION_KEY);
    } catch (error) {
      // Ignore.
    }
  }

  function timeoutSignal(milliseconds) {
    if (typeof window.AbortController !== "function") {
      return { signal: undefined, cancel: function () {} };
    }
    var controller = new window.AbortController();
    var timer = window.setTimeout(function () {
      controller.abort();
    }, milliseconds);
    return {
      signal: controller.signal,
      cancel: function () {
        window.clearTimeout(timer);
      }
    };
  }

  function probeApi(base) {
    var limit = timeoutSignal(PROBE_TIMEOUT_MS);
    return window.fetch(withAction(base, "ping"), {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { "Accept": "application/json" },
      signal: limit.signal
    }).then(function (response) {
      var type = (response.headers.get("content-type") || "").toLowerCase();
      if (type.indexOf("application/json") < 0) {
        return false;
      }
      return response.json().then(function (data) {
        return Boolean(data && data.ok === true && data.data && data.data.pong === true);
      });
    }).catch(function () {
      return false;
    }).then(function (result) {
      limit.cancel();
      return result;
    });
  }

  function resolveApi(force) {
    if (FORCED_API_BASE) {
      state.apiBase = FORCED_API_BASE;
      return Promise.resolve(FORCED_API_BASE);
    }
    if (!force && state.apiBase) {
      return Promise.resolve(state.apiBase);
    }
    if (!force && state.apiPromise) {
      return state.apiPromise;
    }
    if (!force && state.apiUnavailable && Date.now() - state.lastProbeAt < 20000) {
      return Promise.resolve(null);
    }

    state.lastProbeAt = Date.now();
    var candidates = [];
    var stored = readStoredBase();
    if (stored) {
      candidates.push(stored);
    }
    API_CANDIDATES.forEach(function (candidate) {
      if (candidate && candidates.indexOf(candidate) < 0) {
        candidates.push(candidate);
      }
    });

    state.apiPromise = (function () {
      var index = 0;
      function next() {
        if (index >= candidates.length) {
          state.apiUnavailable = true;
          state.apiPromise = null;
          return null;
        }
        var candidate = candidates[index++];
        return probeApi(candidate).then(function (ok) {
          if (!ok) {
            return next();
          }
          state.apiBase = candidate;
          state.apiUnavailable = false;
          state.apiPromise = null;
          storeBase(candidate);
          return candidate;
        });
      }
      return next();
    }());

    return state.apiPromise;
  }

  function apiRequest(action, payload, query) {
    return resolveApi(false).then(function (base) {
      if (!base) {
        state.apiUnavailable = true;
        throw new ApiError("API_UNAVAILABLE", "排行榜服务未启用，游戏与本地最高分不受影响。");
      }

      var isPost = payload !== undefined && payload !== null;
      var limit = timeoutSignal(REQUEST_TIMEOUT_MS);
      var options = {
        method: isPost ? "POST" : "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" },
        signal: limit.signal
      };
      if (isPost) {
        options.headers["Content-Type"] = "application/json";
        options.body = JSON.stringify(payload);
      }

      return window.fetch(withAction(base, action, query), options).then(function (response) {
        var type = (response.headers.get("content-type") || "").toLowerCase();
        if (type.indexOf("application/json") < 0) {
          throw new ApiError("BAD_RESPONSE", "排行榜接口没有返回有效数据。");
        }
        return response.json().then(function (data) {
          if (!data || data.ok !== true) {
            var error = data && data.error ? data.error : {};
            throw new ApiError(error.code || "REQUEST_FAILED", error.message || "请求失败，请稍后再试。");
          }
          return data.data;
        });
      }).catch(function (error) {
        if (error && error.name === "AbortError") {
          throw new ApiError("TIMEOUT", "请求超时，请检查网络后重试。");
        }
        if (error instanceof ApiError) {
          throw error;
        }
        state.apiBase = null;
        clearStoredBase();
        throw new ApiError("NETWORK", "连接排行榜失败，请检查网络后重试。");
      }).then(function (data) {
        limit.cancel();
        return data;
      }, function (error) {
        limit.cancel();
        throw error;
      });
    });
  }

  // ---------------------------------------------------------------- 账号状态

  function playerRankLabel(player) {
    if (!player) {
      return "";
    }
    if (player.rank) {
      return "第 " + player.rank + " 名";
    }
    return "尚未上榜";
  }

  function updateAccountUI() {
    var player = state.player;
    text(dom.accountButton, player ? "我的账号" : "登录 / 注册");
    if (dom.accountButton) {
      dom.accountButton.title = player ? player.username : "登录或注册后参与排行榜";
      dom.accountButton.classList.toggle("is-logged-in", Boolean(player));
    }

    if (dom.leaderboardLoginButton) {
      dom.leaderboardLoginButton.hidden = Boolean(player);
    }
    if (dom.leaderboardLogoutButton) {
      dom.leaderboardLogoutButton.hidden = !player;
    }
    if (dom.leaderboardMe) {
      if (player) {
        var parts = [
          "我的最高分 " + formatScore(player.bestScore || 0),
          playerRankLabel(player),
          "已玩 " + (player.plays || 0) + " 局"
        ];
        dom.leaderboardMe.textContent = player.username + " · " + parts.join(" · ");
        show(dom.leaderboardMe);
      } else {
        hide(dom.leaderboardMe);
      }
    }

    if (dom.rankLoginButton) {
      dom.rankLoginButton.hidden = Boolean(player);
    }
    updateRankPanel();
  }

  function applyPlayer(player) {
    state.player = player || null;
    updateAccountUI();
    if (state.player && state.pending && !state.pending.submitted) {
      window.setTimeout(resumePendingSubmission, 0);
    }
  }

  function loadMe() {
    return apiRequest("me").then(function (data) {
      applyPlayer(data && data.player ? data.player : null);
      return state.player;
    }).catch(function () {
      return null;
    });
  }

  function logout() {
    if (!state.player) {
      return;
    }
    apiRequest("logout", {}).then(function () {
      state.pending = null;
      applyPlayer(null);
      toast("已退出登录");
      if (!dom.leaderboardOverlay.hidden) {
        refreshLeaderboard();
      }
    }).catch(function (error) {
      toast(errorMessage(error, "退出登录失败"));
    });
  }

  // ---------------------------------------------------------------- 暂停

  function pauseForModal() {
    if (window.MERGE_GAME_PAUSE && typeof window.MERGE_GAME_PAUSE.pause === "function") {
      if (window.MERGE_GAME_PAUSE.pause()) {
        state.pausedByUs = true;
      }
    }
  }

  function resumeAfterModal() {
    if (state.pausedByUs && window.MERGE_GAME_PAUSE && typeof window.MERGE_GAME_PAUSE.resume === "function") {
      state.pausedByUs = false;
      window.MERGE_GAME_PAUSE.resume();
    }
    state.pausedByUs = false;
  }

  function hideAuthOnly() {
    hide(dom.authOverlay);
  }

  function hideLeaderboardOnly() {
    hide(dom.leaderboardOverlay);
  }

  // ---------------------------------------------------------------- 登录 / 注册

  function setAuthMode(mode) {
    state.authMode = mode === "register" ? "register" : "login";
    var isRegister = state.authMode === "register";

    text(dom.authTitle, isRegister ? "注册" : "登录");
    text(dom.authSub, isRegister
      ? "起一个昵称，注册后自动登录，最高分会留在排行榜上"
      : "登录后，你的最高分会出现在排行榜上");
    text(dom.authSubmit, isRegister ? "注册并登录" : "登录");
    text(dom.authHint, isRegister
      ? "用户名 2–20 个字符，支持中文、字母、数字、下划线；密码至少 6 位。"
      : "还没有账号？切换到「注册」即可创建。");

    if (dom.authPassword) {
      dom.authPassword.setAttribute("autocomplete", isRegister ? "new-password" : "current-password");
    }
    if (dom.authPasswordConfirm) {
      dom.authPasswordConfirm.setAttribute("autocomplete", "new-password");
      dom.authPasswordConfirm.required = isRegister;
      if (!isRegister) {
        dom.authPasswordConfirm.value = "";
      }
    }
    if (dom.authPasswordConfirmField) {
      dom.authPasswordConfirmField.hidden = !isRegister;
    }
    if (dom.authLoginTab && dom.authRegisterTab) {
      dom.authLoginTab.classList.toggle("is-active", !isRegister);
      dom.authRegisterTab.classList.toggle("is-active", isRegister);
      dom.authLoginTab.setAttribute("aria-selected", String(!isRegister));
      dom.authRegisterTab.setAttribute("aria-selected", String(isRegister));
    }
    hide(dom.authError);
  }

  function openAuth(mode, returnToLeaderboard) {
    state.authReturnToLeaderboard = Boolean(returnToLeaderboard);
    if (returnToLeaderboard) {
      hideLeaderboardOnly();
    }
    setAuthMode(mode || "login");
    state.activeModal = "auth";
    pauseForModal();
    show(dom.authOverlay);
    if (dom.authError) {
      hide(dom.authError);
    }
    if (dom.authForm) {
      dom.authForm.reset();
    }
    window.setTimeout(function () {
      if (dom.authUsername) {
        dom.authUsername.focus();
      }
    }, 30);
  }

  function closeAuth(openLeaderboardAfter) {
    hideAuthOnly();
    state.activeModal = null;
    if (state.authReturnToLeaderboard || openLeaderboardAfter) {
      state.authReturnToLeaderboard = false;
      openLeaderboard();
      return;
    }
    resumeAfterModal();
  }

  function validateCredentials(username, password) {
    if (!username) {
      return "请输入用户名。";
    }
    var length = Array.from(username).length;
    if (length < 2 || length > 20) {
      return "用户名需要 2–20 个字符。";
    }
    if (!/^[A-Za-z0-9_\-\u00B7\u3400-\u9FFF\uF900-\uFAFF]+$/.test(username)) {
      return "用户名只能包含中文、字母、数字、下划线、短横线或中点。";
    }
    if (!password || password.length < 6) {
      return "密码至少需要 6 位。";
    }
    var bytes;
    if (typeof window.TextEncoder === "function") {
      bytes = new window.TextEncoder().encode(password).length;
    } else {
      bytes = unescape(encodeURIComponent(password)).length;
    }
    if (bytes > 72) {
      return "密码过长，请控制在 72 个字节以内。";
    }
    return "";
  }

  function submitAuth(event) {
    if (event) {
      event.preventDefault();
    }
    if (!dom.authForm || dom.authSubmit.disabled) {
      return;
    }

    var username = dom.authUsername ? dom.authUsername.value.trim() : "";
    var password = dom.authPassword ? dom.authPassword.value : "";
    var problem = validateCredentials(username, password);
    if (problem) {
      text(dom.authError, problem);
      show(dom.authError);
      return;
    }
    if (state.authMode === "register") {
      var confirmPassword = dom.authPasswordConfirm ? dom.authPasswordConfirm.value : "";
      if (confirmPassword !== password) {
        text(dom.authError, "两次输入的密码不一致。");
        show(dom.authError);
        return;
      }
    }

    hide(dom.authError);
    dom.authSubmit.disabled = true;
    var original = dom.authSubmit.textContent;
    dom.authSubmit.textContent = state.authMode === "register" ? "注册中…" : "登录中…";

    var action = state.authMode === "register" ? "register" : "login";
    apiRequest(action, { username: username, password: password }).then(function (data) {
      applyPlayer(data && data.player ? data.player : null);
      toast("欢迎，" + username);
      dom.authSubmit.disabled = false;
      dom.authSubmit.textContent = original;

      var returnToBoard = state.authReturnToLeaderboard;
      hideAuthOnly();
      state.activeModal = null;
      state.authReturnToLeaderboard = false;

      if (returnToBoard) {
        openLeaderboard();
      } else {
        resumeAfterModal();
      }
      resumePendingSubmission();
    }).catch(function (error) {
      dom.authSubmit.disabled = false;
      dom.authSubmit.textContent = original;
      text(dom.authError, errorMessage(error, "登录失败，请稍后再试。"));
      show(dom.authError);
    });
  }

  // ---------------------------------------------------------------- 成绩提交

  function updateRankPanel() {
    var pending = state.pending;
    if (!pending) {
      return;
    }

    if (state.submitting) {
      text(dom.rankHint, "正在提交成绩…");
    } else if (!state.player) {
      text(dom.rankHint, "登录后可将本局成绩计入排行榜");
    } else {
      text(dom.rankHint, "已登录：" + state.player.username);
    }

    if (pending.submitted) {
      if (pending.isBest) {
        text(dom.rankResult, "已上榜 · 第 " + (pending.rank || "-") + " 名 · 最高分 " + formatScore(pending.score));
      } else {
        text(dom.rankResult, "本局 " + formatScore(pending.score) + " 分，未超过你的最高分 " + formatScore(state.player ? state.player.bestScore : 0));
      }
      show(dom.rankResult);
    } else if (pending.error) {
      text(dom.rankResult, pending.error);
      show(dom.rankResult);
    } else {
      hide(dom.rankResult);
    }

    if (dom.rankLoginButton) {
      dom.rankLoginButton.hidden = Boolean(state.player);
    }
    if (dom.rankRetryButton) {
      dom.rankRetryButton.hidden = !(pending.error && pending.canRetry && !pending.submitted);
    }
  }

  function submitPending() {
    if (state.submitting) {
      return;
    }
    if (!state.player) {
      openAuth("login", false);
      return;
    }
    if (!state.pending || state.pending.submitted) {
      return;
    }
    if (!state.runId) {
      state.pending.error = "本局没有建立排行会话，请重新开始一局后再提交。";
      state.pending.canRetry = false;
      updateRankPanel();
      return;
    }

    state.submitting = true;
    state.pending.error = "";
    state.pending.canRetry = false;
    updateRankPanel();

    var pending = state.pending;
    var runId = state.runId;
    var finishSeq = state.finishSeq;

    apiRequest("submit", {
      runId: runId,
      score: Math.max(0, Math.round(Number(pending.score) || 0)),
      maxLevel: Math.max(0, Math.round(Number(pending.maxLevel) || 0)),
      durationMs: Math.max(0, Math.round(Number(pending.durationMs) || 0)),
      highestMerged: Boolean(pending.hasMergedHighestLevel)
    }).then(function (data) {
      if (finishSeq !== state.finishSeq) {
        return;
      }
      state.submitting = false;
      pending.submitted = true;
      pending.isBest = Boolean(data && data.isBest);
      pending.rank = data && data.rank ? data.rank : null;
      if (data && data.player) {
        applyPlayer(data.player);
      }
      updateRankPanel();
      toast(pending.isBest ? "成绩已上榜" : "成绩已记录");
      if (!dom.leaderboardOverlay.hidden) {
        refreshLeaderboard();
      }
    }).catch(function (error) {
      if (finishSeq !== state.finishSeq) {
        return;
      }
      state.submitting = false;
      var code = error && error.code ? error.code : "REQUEST_FAILED";
      if (code === "AUTH_REQUIRED") {
        applyPlayer(null);
        pending.error = "登录已过期，请重新登录后提交。";
        pending.canRetry = false;
      } else if (TOKEN_FAILURE_CODES[code]) {
        pending.error = errorMessage(error, "本局排行会话已失效，请重新开始一局。");
        pending.canRetry = false;
      } else if (code === "API_UNAVAILABLE") {
        pending.error = "排行榜服务未启用，成绩只保存在本地。";
        pending.canRetry = false;
      } else {
        pending.error = errorMessage(error, "提交失败，请稍后重试。");
        pending.canRetry = !NON_RETRY_CODES[code];
      }
      updateRankPanel();
    });
  }

  function waitForRun() {
    if (state.runId) {
      return Promise.resolve(state.runId);
    }
    if (!state.runPromise) {
      return Promise.resolve(null);
    }
    return Promise.race([
      state.runPromise,
      new Promise(function (resolve) {
        window.setTimeout(function () {
          resolve(null);
        }, 9000);
      })
    ]).then(function () {
      return state.runId;
    });
  }

  function resumePendingSubmission() {
    if (!state.player || !state.pending || state.pending.submitted || state.submitting) {
      return;
    }
    if (state.pending.error && !state.pending.canRetry) {
      return;
    }
    if (state.runId) {
      submitPending();
      return;
    }
    if (state.runPromise) {
      var finishSeq = state.finishSeq;
      waitForRun().then(function (runId) {
        if (finishSeq !== state.finishSeq) {
          return;
        }
        if (runId) {
          submitPending();
          return;
        }
        if (state.pending && !state.pending.submitted) {
          state.pending.error = "本局没有建立排行会话，请重新开始一局后再提交。";
          state.pending.canRetry = false;
          updateRankPanel();
        }
      });
      return;
    }
    state.pending.error = "本局没有建立排行会话，请重新开始一局后再提交。";
    state.pending.canRetry = false;
    updateRankPanel();
  }

  function onGameStart(detail) {
    state.finishSeq += 1;
    state.pending = null;
    state.submitting = false;
    state.runId = null;
    state.runStartedAt = detail && detail.startedAt ? detail.startedAt : Date.now();
    state.runPromise = null;
    if (state.activeModal) {
      hideAuthOnly();
      hideLeaderboardOnly();
      state.activeModal = null;
      state.authReturnToLeaderboard = false;
      state.pausedByUs = false;
    }

    var seq = ++state.runSeq;
    state.runPromise = apiRequest("start", {}).then(function (data) {
      if (seq !== state.runSeq) {
        return null;
      }
      state.runId = data && data.runId ? data.runId : null;
      state.runStartedAt = data && data.startedAt ? data.startedAt : state.runStartedAt;
      return state.runId;
    }).catch(function () {
      if (seq === state.runSeq) {
        state.runId = null;
      }
      return null;
    });
  }

  function onGameFinish(detail) {
    state.finishSeq += 1;

    state.pending = {
      score: Math.max(0, Math.round(Number(detail && detail.score) || 0)),
      maxLevel: Math.max(0, Math.round(Number(detail && detail.maxLevel) || 0)),
      highestLevel: detail && Number.isFinite(detail.highestLevel) ? detail.highestLevel : MAX_LEVEL,
      hasMergedHighestLevel: Boolean(detail && detail.hasMergedHighestLevel),
      durationMs: Math.max(0, Math.round(Number(detail && detail.durationMs) || 0)),
      at: Date.now(),
      submitted: false,
      isBest: false,
      rank: null,
      error: "",
      canRetry: false
    };

    updateRankPanel();
    if (!state.player) {
      return;
    }
    resumePendingSubmission();
  }

  // ---------------------------------------------------------------- 排行榜

  function openLeaderboard() {
    if (state.activeModal === "leaderboard" && !dom.leaderboardOverlay.hidden) {
      refreshLeaderboard();
      return;
    }
    if (state.activeModal === "auth") {
      hideAuthOnly();
    }
    state.activeModal = "leaderboard";
    state.authReturnToLeaderboard = false;
    pauseForModal();
    show(dom.leaderboardOverlay);
    refreshLeaderboard();
  }

  function closeLeaderboard() {
    hideLeaderboardOnly();
    if (state.activeModal === "leaderboard") {
      state.activeModal = null;
    }
    resumeAfterModal();
  }

  function makeLeaderboardRow(entry) {
    var row = document.createElement("div");
    row.className = "lb-row" + (entry.isMe ? " is-me" : "");

    var rank = document.createElement("div");
    rank.className = "lb-rank";
    rank.textContent = String(entry.rank);

    var player = document.createElement("div");
    player.className = "lb-player";
    var name = document.createElement("div");
    name.className = "lb-name";
    name.textContent = entry.username;
    var meta = document.createElement("div");
    meta.className = "lb-meta";
    var metaParts = [levelLabel(entry.level)];
    var date = formatDate(entry.at);
    if (date) {
      metaParts.push(date);
    }
    if (entry.plays) {
      metaParts.push(entry.plays + " 局");
    }
    meta.textContent = metaParts.join(" · ");
    player.appendChild(name);
    player.appendChild(meta);

    var score = document.createElement("div");
    score.className = "lb-score";
    var scoreValue = document.createElement("strong");
    scoreValue.textContent = formatScore(entry.score);
    var scoreLabel = document.createElement("span");
    scoreLabel.textContent = "最高分";
    score.appendChild(scoreValue);
    score.appendChild(scoreLabel);

    row.appendChild(rank);
    row.appendChild(player);
    row.appendChild(score);
    return row;
  }

  function renderLeaderboard(data) {
    if (!dom.leaderboardList) {
      return;
    }
    state.leaderboard = data || null;
    dom.leaderboardList.textContent = "";

    if (!data) {
      var loading = document.createElement("p");
      loading.className = "leaderboard-empty";
      loading.textContent = "加载中…";
      dom.leaderboardList.appendChild(loading);
      return;
    }

    var entries = Array.isArray(data.entries) ? data.entries : [];
    if (entries.length === 0) {
      var empty = document.createElement("p");
      empty.className = "leaderboard-empty";
      empty.textContent = "还没有人上榜。登录后完成一局，抢下第一名！";
      dom.leaderboardList.appendChild(empty);
    } else {
      var fragment = document.createDocumentFragment();
      entries.forEach(function (entry) {
        fragment.appendChild(makeLeaderboardRow(entry));
      });
      dom.leaderboardList.appendChild(fragment);
    }

    var total = Number(data.total) || 0;
    text(dom.leaderboardSub, "共 " + total + " 位玩家 · 按最高分排名。");

    if (data.me) {
      applyPlayer(data.me);
    } else if (!state.player) {
      hide(dom.leaderboardMe);
    }
  }

  function refreshLeaderboard() {
    if (state.leaderboardLoading) {
      return;
    }
    state.leaderboardLoading = true;
    text(dom.leaderboardSub, "正在加载排行榜…");
    if (dom.leaderboardList) {
      dom.leaderboardList.textContent = "";
      var loading = document.createElement("p");
      loading.className = "leaderboard-empty";
      loading.textContent = "加载中…";
      dom.leaderboardList.appendChild(loading);
    }

    apiRequest("leaderboard", null, { limit: 50 }).then(function (data) {
      state.leaderboardLoading = false;
      renderLeaderboard(data);
    }).catch(function (error) {
      state.leaderboardLoading = false;
      if (dom.leaderboardList) {
        dom.leaderboardList.textContent = "";
        var box = document.createElement("p");
        box.className = "leaderboard-empty";
        box.textContent = errorMessage(error, "排行榜加载失败，请稍后重试。");
        dom.leaderboardList.appendChild(box);
      }
      text(dom.leaderboardSub, "暂时无法加载排行榜");
    });
  }

  // ---------------------------------------------------------------- 事件

  function bindEvents() {
    if (dom.leaderboardButton) {
      dom.leaderboardButton.addEventListener("click", openLeaderboard);
    }
    if (dom.accountButton) {
      dom.accountButton.addEventListener("click", function () {
        if (state.player) {
          openLeaderboard();
        } else {
          openAuth("login", false);
        }
      });
    }
    if (dom.leaderboardCloseButton) {
      dom.leaderboardCloseButton.addEventListener("click", closeLeaderboard);
    }
    if (dom.leaderboardRefreshButton) {
      dom.leaderboardRefreshButton.addEventListener("click", refreshLeaderboard);
    }
    if (dom.leaderboardLoginButton) {
      dom.leaderboardLoginButton.addEventListener("click", function () {
        openAuth("login", true);
      });
    }
    if (dom.leaderboardLogoutButton) {
      dom.leaderboardLogoutButton.addEventListener("click", logout);
    }
    if (dom.authCloseButton) {
      dom.authCloseButton.addEventListener("click", function () {
        closeAuth(false);
      });
    }
    if (dom.authLoginTab) {
      dom.authLoginTab.addEventListener("click", function () {
        setAuthMode("login");
      });
    }
    if (dom.authRegisterTab) {
      dom.authRegisterTab.addEventListener("click", function () {
        setAuthMode("register");
      });
    }
    if (dom.authForm) {
      dom.authForm.addEventListener("submit", submitAuth);
    }
    if (dom.rankLoginButton) {
      dom.rankLoginButton.addEventListener("click", function () {
        openAuth("login", false);
      });
    }
    if (dom.rankRetryButton) {
      dom.rankRetryButton.addEventListener("click", submitPending);
    }
    if (dom.rankViewButton) {
      dom.rankViewButton.addEventListener("click", openLeaderboard);
    }
    if (dom.leaderboardOverlay) {
      dom.leaderboardOverlay.addEventListener("click", function (event) {
        if (event.target === dom.leaderboardOverlay) {
          closeLeaderboard();
        }
      });
    }
    if (dom.authOverlay) {
      dom.authOverlay.addEventListener("click", function (event) {
        if (event.target === dom.authOverlay) {
          closeAuth(false);
        }
      });
    }
    document.addEventListener("keydown", function (event) {
      if (event.key !== "Escape" && event.key !== "Esc") {
        return;
      }
      if (dom.authOverlay && !dom.authOverlay.hidden) {
        closeAuth(false);
      } else if (dom.leaderboardOverlay && !dom.leaderboardOverlay.hidden) {
        closeLeaderboard();
      }
    });
  }

  function init() {
    if (!initDom()) {
      return;
    }
    bindEvents();
    applyPlayer(null);
    window.BIT_GAME_HOOKS = window.BIT_GAME_HOOKS || {};
    window.BIT_GAME_HOOKS.start = onGameStart;
    window.BIT_GAME_HOOKS.finish = onGameFinish;
    window.BIT_LEADERBOARD = Object.freeze({
      open: openLeaderboard,
      auth: openAuth,
      refresh: refreshLeaderboard,
      submit: submitPending,
      state: function () {
        return state;
      }
    });
    window.setTimeout(loadMe, 80);
    window.setTimeout(function () {
      var hash = String(window.location.hash || "").toLowerCase();
      if (hash === "#leaderboard") {
        openLeaderboard();
      } else if (hash === "#login") {
        openAuth("login", false);
      } else if (hash === "#register") {
        openAuth("register", false);
      }
    }, 160);
  }

  init();
}());
