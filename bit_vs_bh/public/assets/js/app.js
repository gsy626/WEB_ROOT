/* 京工双雄：校区混战
   仅负责：结算倒计时、节点行动表单的战力预估。
   没有 WebSocket、没有轮询请求，页面仍然是表单 + 刷新。 */
(function () {
    'use strict';

    var pad = function (n) {
        return String(n).padStart(2, '0');
    };

    function setupCountdowns() {
        var nodes = document.querySelectorAll('[data-countdown]');
        if (!nodes.length) {
            return;
        }

        var tick = function () {
            var now = Date.now();
            nodes.forEach(function (el) {
                var until = Date.parse(el.getAttribute('data-until') || '');
                if (!until || Number.isNaN(until)) {
                    el.textContent = '--:--';
                    return;
                }
                var diff = Math.max(0, until - now);
                var total = Math.floor(diff / 1000);
                var hours = Math.floor(total / 3600);
                var minutes = Math.floor((total % 3600) / 60);
                var seconds = total % 60;

                if (diff <= 0) {
                    el.textContent = '结算中';
                    return;
                }
                el.textContent = hours > 0
                    ? hours + ':' + pad(minutes) + ':' + pad(seconds)
                    : pad(minutes) + ':' + pad(seconds);
            });
        };

        tick();
        window.setInterval(tick, 1000);
    }

    function setupOrderForm() {
        var form = document.querySelector('.order-form');
        if (!form) {
            return;
        }

        var powers = {};
        var actions = {};
        try {
            powers = JSON.parse(form.getAttribute('data-powers') || '{}');
            actions = JSON.parse(form.getAttribute('data-actions') || '{}');
        } catch (error) {
            return;
        }

        var costOutput = form.querySelector('[data-cost-output]');
        var powerOutput = form.querySelector('[data-power-output]');
        var hintOutput = form.querySelector('[data-hint-output]');
        var submitButton = form.querySelector('[data-submit-label]');
        var actionInputs = Array.prototype.slice.call(form.querySelectorAll('input[name="action"]'));
        var unitInputs = Array.prototype.slice.call(form.querySelectorAll('input[name="unit_code"]'));

        var checked = function (inputs) {
            for (var i = 0; i < inputs.length; i++) {
                if (inputs[i].checked) {
                    return inputs[i];
                }
            }
            return null;
        };

        var setCheckedClasses = function () {
            actionInputs.forEach(function (input) {
                var label = input.closest('.action-option');
                if (label) {
                    label.classList.toggle('is-checked', input.checked);
                }
            });
            unitInputs.forEach(function (input) {
                var label = input.closest('.unit-option');
                if (label) {
                    label.classList.toggle('is-checked', input.checked);
                }
            });
        };

        var update = function () {
            var actionInput = checked(actionInputs);
            var unitInput = checked(unitInputs);
            if (!actionInput || !unitInput) {
                return;
            }

            var action = actionInput.value;
            var unitCode = unitInput.value;
            var meta = actions[action] || {};
            var cost = Number(meta.ap || 0);

            if (costOutput) {
                costOutput.innerHTML = cost + ' <small>AP</small>';
            }

            if (action === 'scout') {
                if (powerOutput) {
                    powerOutput.textContent = '情报';
                }
                if (hintOutput) {
                    hintOutput.textContent = '立即获取敌军待结算战力，情报对本阵营共享。';
                }
                if (submitButton) {
                    submitButton.textContent = '执行侦察';
                }
            } else if (action === 'repair') {
                if (powerOutput) {
                    powerOutput.textContent = '+16 驻军';
                }
                if (hintOutput) {
                    hintOutput.textContent = '工程兵立即修复节点：驻军 +16，防御加成 +2%。';
                }
                if (submitButton) {
                    submitButton.textContent = '执行修复';
                }
            } else {
                var table = powers[unitCode] || {};
                var power = Number(table[action] || 0);
                if (powerOutput) {
                    powerOutput.textContent = '≈' + power;
                }
                if (hintOutput) {
                    var desc = {
                        attack: '与同阵营玩家合力攻坚，结算时叠加兵种克制与人数补正。',
                        defend: '守住后部分驻防战力会转化为常驻守军。',
                        support: '本方节点计入防御，其他节点计入进攻；战力按 50% 计算。'
                    };
                    hintOutput.textContent = desc[action] || '';
                }
                if (submitButton) {
                    submitButton.textContent = '下达命令';
                }
            }

            setCheckedClasses();
        };

        actionInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                if (input.checked && input.value === 'repair') {
                    var engineer = unitInputs.filter(function (u) {
                        return u.value === 'engineer';
                    })[0];
                    if (engineer) {
                        engineer.checked = true;
                    }
                }
                update();
            });
        });

        unitInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                var actionInput = checked(actionInputs);
                if (actionInput && actionInput.value === 'repair' && input.value !== 'engineer') {
                    var fallback = actionInputs.filter(function (a) {
                        return a.value === 'defend' && !a.disabled;
                    })[0] || actionInputs.filter(function (a) {
                        return a.value === 'support' && !a.disabled;
                    })[0];
                    if (fallback) {
                        fallback.checked = true;
                    }
                }
                update();
            });
        });

        update();
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupCountdowns();
        setupOrderForm();
    });
})();
