(function () {
    'use strict';

    var WHITELIST = {
        ml: ['ml_automacao_ativa', 'ml_tag_afiliado', 'ml_csrf_token', 'ml_cookie', 'ml_openai_model', 'ml_openai_prompt', 'ml_link_grupo_whatsapp', 'ml_evolution_conta_id', 'ml_grupos_ids', 'ml_telegram_chat_ids', 'ml_produtos_por_execucao', 'ml_delay_entre_envios', 'ml_site_publicar', 'ml_site_categoria_id', 'ml_whatsapp_exigir_foto', 'whatsapp_status_ativo', 'telegram_envio_ativo', 'telegram_story_ativo', 'cron_painel_presente', 'cron_individual_ativo', 'cron_token', 'cron_intervalo_minutos', 'cron_hora_inicio', 'cron_hora_fim', 'cron_dias_remocao', 'cron_job_id'],
        shopee: ['shopee_automacao_ativa', 'shopee_app_id', 'shopee_secret', 'shopee_openai_model', 'shopee_openai_prompt', 'shopee_telegram_chat_ids', 'shopee_site_publicar', 'shopee_site_categoria_id', 'whatsapp_status_ativo', 'telegram_envio_ativo', 'telegram_story_ativo', 'cron_painel_presente', 'cron_individual_ativo', 'cron_token', 'cron_intervalo_minutos', 'cron_hora_inicio', 'cron_hora_fim', 'cron_dias_remocao', 'cron_job_id'],
        magalu: ['magalu_automacao_ativa', 'magalu_loja_url', 'magalu_loja_url_alternativa', 'magalu_scraper_api_key', 'magalu_openai_model', 'magalu_openai_prompt', 'magalu_evolution_conta_id', 'magalu_grupos_ids', 'magalu_telegram_chat_ids', 'magalu_produtos_por_execucao', 'magalu_delay_entre_envios', 'magalu_dias_evitar_repetir', 'magalu_site_publicar', 'magalu_site_categoria_id', 'whatsapp_status_ativo', 'telegram_envio_ativo', 'telegram_story_ativo', 'cron_painel_presente', 'cron_individual_ativo', 'cron_token', 'cron_intervalo_minutos', 'cron_hora_inicio', 'cron_hora_fim', 'cron_dias_remocao', 'cron_job_id'],
        amazon: ['amazon_automacao_ativa', 'amazon_access_key', 'amazon_secret_key', 'amazon_associate_tag', 'amazon_region', 'amazon_search_keywords', 'amazon_openai_model', 'amazon_openai_prompt', 'amazon_telegram_chat_ids', 'amazon_site_publicar', 'amazon_site_categoria_id', 'telegram_envio_ativo', 'telegram_story_ativo', 'cron_painel_presente', 'cron_individual_ativo', 'cron_token', 'cron_intervalo_minutos', 'cron_hora_inicio', 'cron_hora_fim', 'cron_dias_remocao', 'cron_job_id'],
        shein: ['shein_automacao_ativa', 'shein_api_key', 'shein_api_secret', 'shein_openai_model', 'shein_openai_prompt', 'shein_evolution_conta_id', 'shein_grupos_ids', 'shein_telegram_chat_ids', 'shein_produtos_por_execucao', 'shein_delay_entre_envios', 'shein_site_publicar', 'shein_site_categoria_id', 'whatsapp_status_ativo', 'telegram_envio_ativo', 'telegram_story_ativo', 'cron_painel_presente', 'cron_individual_ativo', 'cron_token', 'cron_intervalo_minutos', 'cron_hora_inicio', 'cron_hora_fim', 'cron_dias_remocao', 'cron_job_id'],
        aliexpress: ['aliexpress_automacao_ativa', 'aliexpress_app_key', 'aliexpress_app_secret', 'aliexpress_openai_model', 'aliexpress_openai_prompt', 'aliexpress_evolution_conta_id', 'aliexpress_grupos_ids', 'aliexpress_telegram_chat_ids', 'aliexpress_site_publicar', 'aliexpress_site_categoria_id', 'whatsapp_status_ativo', 'telegram_envio_ativo', 'telegram_story_ativo', 'cron_painel_presente', 'cron_individual_ativo', 'cron_token', 'cron_intervalo_minutos', 'cron_hora_inicio', 'cron_hora_fim', 'cron_dias_remocao', 'cron_job_id'],
        ml_cupons: ['ml_cupons_cookie', 'ml_cupons_csrf_token', 'ml_api_client_id', 'ml_api_client_secret', 'ml_api_redirect_uri', 'ml_api_access_token', 'ml_api_refresh_token', 'ml_api_user_id', 'ml_cupons_automacao_ativa', 'ml_cupons_evolution_conta_id', 'ml_cupons_grupos', 'ml_cupons_telegram_chat_ids', 'ml_cupons_link_ativacao', 'ml_cupons_delay_entre_envios', 'whatsapp_status_ativo', 'telegram_envio_ativo', 'telegram_story_ativo', 'cron_painel_presente', 'cron_individual_ativo', 'cron_token', 'cron_intervalo_minutos', 'cron_hora_inicio', 'cron_hora_fim', 'cron_dias_remocao', 'cron_job_id']
    };

    function getToken() {
        var b = document.body;
        return (b && b.getAttribute('data-admin-autosave-token')) || '';
    }

    function lojaEvolutionPatchTouchesEvolution(patch) {
        if (!patch || typeof patch !== 'object') {
            return false;
        }
        var k;
        for (k in patch) {
            if (Object.prototype.hasOwnProperty.call(patch, k) && String(k).indexOf('evolution_conta_id') !== -1) {
                return true;
            }
        }
        return false;
    }

    window.lojaEvolutionStatusBindPrefix = function (prefix) {
        var p = String(prefix || '');
        if (!p) {
            return;
        }
        var btn = document.getElementById('btnEvoTeste_' + p);
        var modal = document.getElementById('evoTesteModal_' + p);
        var msg = document.getElementById('evoTesteMsg_' + p);
        if (!btn || !modal || !msg) {
            return;
        }
        btn.addEventListener('click', function () {
            modal.classList.remove('hidden');
            msg.textContent = '';
        });
        modal.querySelectorAll('.evo-teste-fechar').forEach(function (b) {
            if (b.getAttribute('data-prefix') === p) {
                b.addEventListener('click', function () {
                    modal.classList.add('hidden');
                });
            }
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                modal.classList.add('hidden');
            }
        });
        var env = modal.querySelector('.evo-teste-enviar');
        if (env && env.getAttribute('data-prefix') === p) {
            env.addEventListener('click', function () {
                var cid = document.getElementById('evoTesteContaId_' + p);
                var num = document.getElementById('evoTesteNumero_' + p);
                if (!cid || !num) {
                    return;
                }
                msg.textContent = 'Enviando…';
                var fd = new FormData();
                fd.append('evolution_action', 'test');
                fd.append('evolution_id', cid.value);
                fd.append('evolution_test_number', num.value);
                fetch('configuracoes.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (d) {
                        msg.textContent = d.message || (d.success ? 'OK' : 'Erro');
                        msg.className = 'text-xs min-w-0 ' + (d.success ? 'text-emerald-700' : 'text-red-700');
                        if (d.success) {
                            modal.classList.add('hidden');
                        }
                    })
                    .catch(function () {
                        msg.textContent = 'Falha na requisição.';
                        msg.className = 'text-xs min-w-0 text-red-700';
                    });
            });
        }
    };

    function lojaRefreshEvolutionAfterSave(loja) {
        var token = getToken();
        if (!token || !loja) {
            return;
        }
        fetch('api/loja-evolution-status-fragment.php?loja=' + encodeURIComponent(loja), {
            credentials: 'same-origin',
            headers: { 'X-Autosave-Token': token }
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (d) {
                if (!d || !d.ok || !d.html) {
                    return;
                }
                var el = document.querySelector('.loja-evolution-status-mount[data-loja-key="' + loja + '"]');
                if (!el) {
                    return;
                }
                el.outerHTML = d.html;
                var m2 = document.querySelector('.loja-evolution-status-mount[data-loja-key="' + loja + '"]');
                var p2 = m2 && m2.getAttribute('data-loja-evolution-prefix');
                if (p2) {
                    window.lojaEvolutionStatusBindPrefix(p2);
                }
            })
            .catch(function () {});
    }

    function escSel(s) {
        if (typeof CSS !== 'undefined' && CSS.escape) {
            return CSS.escape(s);
        }
        return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function collectGrupos(form, baseKey) {
        var name = baseKey === 'ml_cupons_grupos' ? 'ml_cupons_grupos[]' : baseKey + '[]';
        var arr = [];
        form.querySelectorAll('input[type="checkbox"][name="' + escSel(name) + '"]').forEach(function (el) {
            if (el.checked) {
                var n = parseInt(el.value, 10);
                arr.push(isNaN(n) ? 0 : n);
            }
        });
        return arr;
    }

    function collectField(form, key) {
        if (key === 'cron_individual_ativo') {
            var lk = form.getAttribute('data-loja-autosave');
            if (lk) {
                var pfx = String(lk).replace(/[^a-z0-9_]/g, '_');
                var modoIndiv = document.getElementById('cron_modo_indiv_' + pfx);
                var modoGlobal = document.getElementById('cron_modo_global_' + pfx);
                var hid = document.getElementById('cron_individual_ativo_' + pfx);
                if (modoIndiv && modoGlobal && hid) {
                    var on = !!modoIndiv.checked;
                    hid.value = on ? '1' : '0';
                    return on ? '1' : '0';
                }
                var modoCb = document.getElementById('cron_modo_' + pfx);
                if (modoCb && hid) {
                    var on2 = !!modoCb.checked;
                    hid.value = on2 ? '1' : '0';
                    return on2 ? '1' : '0';
                }
            }
        }
        if (key === 'telegram_envio_ativo') {
            var tcb = form.querySelector('input[type="checkbox"][name="telegram_envio_ativo"]');
            return tcb && tcb.checked ? '1' : '0';
        }
        if (key === 'ml_cupons_grupos' || (key.length > 11 && key.slice(-11) === '_grupos_ids')) {
            var gname = key === 'ml_cupons_grupos' ? 'ml_cupons_grupos[]' : key + '[]';
            var gnodes = form.querySelectorAll('input[type="checkbox"][name="' + escSel(gname) + '"]');
            if (gnodes.length === 0) {
                return undefined;
            }
            return collectGrupos(form, key);
        }
        var cb = form.querySelector('input[type="checkbox"][name="' + escSel(key) + '"]');
        if (cb) {
            if (cb.disabled) {
                return undefined;
            }
            return cb.checked ? '1' : '0';
        }
        var el = form.elements.namedItem(key);
        if (!el) {
            return undefined;
        }
        if (typeof RadioNodeList !== 'undefined' && el instanceof RadioNodeList) {
            var i;
            for (i = 0; i < el.length; i++) {
                if (el[i].type === 'checkbox' && el[i].checked) {
                    return '1';
                }
            }
            return '0';
        }
        if (el.disabled) {
            return undefined;
        }
        return el.value;
    }

    function buildPatch(form, loja) {
        var keys = WHITELIST[loja];
        if (!keys) {
            return null;
        }
        var patch = {};
        var k;
        for (var i = 0; i < keys.length; i++) {
            k = keys[i];
            var v = collectField(form, k);
            if (v !== undefined) {
                patch[k] = v;
            }
        }
        return patch;
    }

    function showFeedback(el, text, ok) {
        if (!el) {
            return;
        }
        el.textContent = text;
        el.classList.remove('hidden', 'text-green-700', 'text-red-600', 'text-gray-500');
        el.classList.add(ok ? 'text-green-700' : 'text-red-600');
        if (ok) {
            clearTimeout(el._lojaAsT);
            el._lojaAsT = setTimeout(function () {
                el.classList.add('hidden');
            }, 2500);
        }
    }

    function initForm(form) {
        var loja = form.getAttribute('data-loja-autosave');
        if (!loja || !WHITELIST[loja]) {
            return;
        }
        var token = getToken();
        if (!token) {
            return;
        }
        var debounceMs = 800;
        var t = null;
        var saving = false;
        var pending = false;

        var feedbackId = form.getAttribute('data-loja-autosave-feedback');
        var feedbackEl = feedbackId ? document.getElementById(feedbackId) : null;
        if (!feedbackEl) {
            feedbackEl = document.getElementById('lojaAutosaveFeedback');
        }

        function scheduleSave() {
            if (t) {
                clearTimeout(t);
            }
            t = setTimeout(runSave, debounceMs);
        }

        function runSave() {
            t = null;
            if (saving) {
                pending = true;
                return;
            }
            var patch = buildPatch(form, loja);
            if (!patch || Object.keys(patch).length === 0) {
                return;
            }
            saving = true;
            fetch('api/loja-patch.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Autosave-Token': token
                },
                body: JSON.stringify({ loja: loja, patch: patch })
            })
                .then(function (r) {
                    return r.json().then(function (j) {
                        return { ok: r.ok, json: j };
                    });
                })
                .then(function (res) {
                    saving = false;
                    if (res.ok && res.json && res.json.ok) {
                        showFeedback(feedbackEl, 'Salvo', true);
                        lojaAplicarCronModoGlobalForcadoResposta(loja, res.json);
                        if (lojaEvolutionPatchTouchesEvolution(patch)) {
                            lojaRefreshEvolutionAfterSave(loja);
                        }
                    } else {
                        var err = (res.json && res.json.error) || 'Erro ao salvar';
                        showFeedback(feedbackEl, err, false);
                    }
                    if (pending) {
                        pending = false;
                        scheduleSave();
                    }
                })
                .catch(function () {
                    saving = false;
                    showFeedback(feedbackEl, 'Erro ao salvar', false);
                    if (pending) {
                        pending = false;
                        scheduleSave();
                    }
                });
        }

        form._lojaAutosaveForcar = function () {
            return new Promise(function (resolve, reject) {
                function flushWhenIdle() {
                    if (saving) {
                        setTimeout(flushWhenIdle, 40);
                        return;
                    }
                    if (t) {
                        clearTimeout(t);
                        t = null;
                    }
                    var patch = buildPatch(form, loja);
                    if (!patch || Object.keys(patch).length === 0) {
                        resolve({ ok: true, empty: true });
                        return;
                    }
                    saving = true;
                    fetch('api/loja-patch.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Autosave-Token': token
                        },
                        body: JSON.stringify({ loja: loja, patch: patch })
                    })
                        .then(function (r) {
                            return r.json().then(function (j) {
                                return { ok: r.ok, json: j };
                            });
                        })
                        .then(function (res) {
                            saving = false;
                            if (res.ok && res.json && res.json.ok) {
                                showFeedback(feedbackEl, 'Salvo', true);
                                lojaAplicarCronModoGlobalForcadoResposta(loja, res.json);
                                if (lojaEvolutionPatchTouchesEvolution(patch)) {
                                    lojaRefreshEvolutionAfterSave(loja);
                                }
                                resolve({ ok: true });
                            } else {
                                var err = (res.json && res.json.error) || 'Erro ao salvar';
                                showFeedback(feedbackEl, err, false);
                                reject(new Error(err));
                            }
                            if (pending) {
                                pending = false;
                                scheduleSave();
                            }
                        })
                        .catch(function (err) {
                            saving = false;
                            showFeedback(feedbackEl, 'Erro ao salvar', false);
                            reject(err || new Error('Erro ao salvar'));
                            if (pending) {
                                pending = false;
                                scheduleSave();
                            }
                        });
                }
                flushWhenIdle();
            });
        };

        form.addEventListener(
            'input',
            function (e) {
                var target = e.target;
                if (!target || !target.name) {
                    return;
                }
                if (target.type === 'file' || target.type === 'submit' || target.type === 'button') {
                    return;
                }
                scheduleSave();
            },
            true
        );
        form.addEventListener(
            'change',
            function (e) {
                var target = e.target;
                if (!target || !target.name) {
                    return;
                }
                if (target.type === 'file' || target.type === 'submit' || target.type === 'button') {
                    return;
                }
                scheduleSave();
            },
            true
        );
        form.addEventListener('submit', function (e) {
            e.preventDefault();
        });
    }

    function boot() {
        document.querySelectorAll('form[data-loja-autosave]').forEach(initForm);
        document.querySelectorAll('.loja-evolution-status-mount[data-loja-evolution-prefix]').forEach(function (m) {
            var pr = m.getAttribute('data-loja-evolution-prefix');
            if (pr) {
                window.lojaEvolutionStatusBindPrefix(pr);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.forcarAutosaveAgora = function (lojaKey) {
        var form = document.querySelector('form[data-loja-autosave="' + escSel(lojaKey) + '"]');
        if (!form || typeof form._lojaAutosaveForcar !== 'function') {
            return Promise.resolve({ ok: true, skipped: true });
        }
        return form._lojaAutosaveForcar();
    };

    document.addEventListener('click', function (e) {
        var aplicar = e.target && e.target.closest && e.target.closest('.loja-autosave-aplicar-btn');
        if (!aplicar) {
            return;
        }
        var lk = aplicar.getAttribute('data-loja') || '';
        if (!lk) {
            return;
        }
        var bar = aplicar.closest('.loja-autosave-cta-bar');
        var fb = bar ? bar.querySelector('.loja-autosave-cta-feedback') : null;
        aplicar.disabled = true;
        if (fb) {
            fb.classList.remove('hidden', 'text-green-700', 'text-red-600');
            fb.classList.add('text-gray-600');
            fb.textContent = 'A gravar…';
        }
        window
            .forcarAutosaveAgora(lk)
            .then(function (res) {
                if (fb) {
                    fb.classList.remove('text-gray-600', 'text-red-600');
                    fb.classList.add('text-green-700');
                    fb.textContent =
                        res && res.empty ? 'Nada por gravar (já atualizado).' : 'AutoSave aplicado.';
                    setTimeout(function () {
                        fb.classList.add('hidden');
                    }, 3200);
                }
            })
            .catch(function () {
                if (fb) {
                    fb.classList.remove('text-gray-600', 'text-green-700');
                    fb.classList.add('text-red-600');
                    fb.textContent = 'Falha ao gravar. Corrija os campos e tente de novo.';
                }
            })
            .then(function () {
                aplicar.disabled = false;
            });
    });

    var _lojaExecutarFluxoBusy = false;

    function lojaEscapeAttr(s) {
        if (s == null) {
            return '';
        }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function lojaFormatarFasesHtml(phases) {
        if (!phases || typeof phases !== 'object') {
            return '';
        }
        var keys = ['validacao', 'automacao', 'cron_loja', 'sync_org'];
        var labels = {
            validacao: 'Validação',
            automacao: 'Automação',
            cron_loja: 'Cron da loja',
            sync_org: 'Sincronização cron-job.org'
        };
        var html = '<ul class="mt-2 list-disc list-inside space-y-1 text-sm">';
        var i;
        for (i = 0; i < keys.length; i++) {
            var k = keys[i];
            var p = phases[k];
            if (!p || typeof p !== 'object') {
                continue;
            }
            var ok = p.ok === true;
            var icon = ok ? '✅' : '❌';
            var msg = p.message != null ? String(p.message) : '';
            var skip = p.skipped != null && p.skipped !== false;
            if (skip && p.skipped !== true) {
                msg = msg || String(p.skipped);
            }
            var extraLink = '';
            if (k === 'sync_org' && p.crons_setup_url && typeof p.crons_setup_url === 'string') {
                var href = String(p.crons_setup_url).trim();
                if (href.indexOf('javascript:') !== 0 && href.indexOf('data:') !== 0) {
                    extraLink =
                        ' <a class="font-semibold underline text-orange-700 hover:text-orange-900" href="' +
                        lojaEscapeAttr(href) +
                        '">Abrir Configurações → Crons</a>';
                }
            }
            html +=
                '<li><span class="font-medium">' +
                lojaEscapeHtml(labels[k] || k) +
                ':</span> ' +
                icon +
                ' ' +
                lojaEscapeHtml(msg) +
                extraLink +
                '</li>';
        }
        html += '</ul>';
        return html;
    }

    window.lojaExecutarFluxoCompleto = function (lojaKey, opts, rootWrap) {
        opts = opts || {};
        var timeoutMs = opts.timeoutMs != null ? opts.timeoutMs : 120000;
        var wrap =
            rootWrap && rootWrap.querySelector
                ? rootWrap
                : document.querySelector('.loja-executar-agora-wrap[data-loja="' + escSel(lojaKey) + '"]');
        if (!wrap) {
            var btnGuess = document.querySelector('.loja-btn-executar-fluxo[data-loja="' + escSel(lojaKey) + '"]');
            if (btnGuess && btnGuess.closest) {
                wrap = btnGuess.closest('.loja-executar-agora-wrap');
            }
        }
        var btn = wrap ? wrap.querySelector('.loja-btn-executar-fluxo') : null;
        var txt = wrap ? wrap.querySelector('.loja-btn-executar-texto') : null;
        var spi = wrap ? wrap.querySelector('.loja-btn-executar-spinner') : null;
        var box = wrap ? wrap.querySelector('.loja-executar-fluxo-resultado') : null;
        if (!btn || !txt || !spi || !box) {
            btn = document.getElementById('btnLojaExecutarFluxo');
            txt = document.getElementById('btnLojaExecutarFluxoTexto');
            spi = document.getElementById('btnLojaExecutarFluxoSpinner');
            box = document.getElementById('lojaExecutarFluxoResultado');
        }
        var token = getToken();
        if (!btn || !txt || !spi || !box) {
            if (typeof console !== 'undefined' && console.error) {
                console.error(
                    '[loja-autosave] Executar (cron API): não encontrou botão/área de resultado (loja=' +
                        lojaKey +
                        '). Recarregue com Ctrl+F5.'
                );
            }
            try {
                window.alert(
                    'Não foi possível iniciar «Executar» (interface incompleta). Recarregue a página com Ctrl+F5.'
                );
            } catch (e2) {}
            return;
        }
        if (_lojaExecutarFluxoBusy) {
            return;
        }
        if (!token) {
            box.classList.remove('hidden');
            box.className = 'mt-4 rounded-lg p-4 text-sm bg-red-100 text-red-800';
            box.innerHTML =
                '<p class="font-bold">Erro</p><p class="mt-1">Sem token de sessão. Recarregue a página.</p>';
            return;
        }
        _lojaExecutarFluxoBusy = true;
        btn.disabled = true;
        spi.classList.remove('hidden');
        box.classList.add('hidden');
        box.innerHTML = '';

        function setLbl(s) {
            txt.textContent = s;
        }

        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = controller ? setTimeout(function () { controller.abort(); }, timeoutMs) : null;

        var execUrl;
        try {
            execUrl = new URL('api/sincronizar-cron-loja.php', window.location.href).href;
        } catch (eUrl) {
            execUrl = 'api/sincronizar-cron-loja.php';
        }

        setLbl('Gravando…');
        window
            .forcarAutosaveAgora(lojaKey)
            .catch(function () {
                return null;
            })
            .then(function () {
                setLbl('Sincronizando na cron-job.org…');
                return fetch(execUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller ? controller.signal : undefined,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Autosave-Token': token
                    },
                    body: JSON.stringify({ loja: lojaKey, token: token })
                });
            })
            .then(function (r) {
                return r.text().then(function (text) {
                    return { okHttp: r.ok, status: r.status, text: text };
                });
            })
            .then(function (pack) {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                var raw = pack.text || '';
                var data = null;
                var parseErr = null;
                var ttrim = raw.trim();
                if (ttrim.length > 0 && (ttrim.charAt(0) === '{' || ttrim.charAt(0) === '[')) {
                    try {
                        data = JSON.parse(raw);
                    } catch (e) {
                        parseErr = e;
                    }
                } else if (ttrim.length > 0) {
                    parseErr = new Error('not_json');
                }
                return { pack: pack, data: data, parseErr: parseErr };
            })
            .then(function (ctx) {
                _lojaExecutarFluxoBusy = false;
                btn.disabled = false;
                setLbl('Executar');
                spi.classList.add('hidden');
                box.classList.remove('hidden');

                if (ctx.parseErr && !ctx.data) {
                    box.className = 'mt-4 rounded-lg p-4 text-sm bg-red-100 text-red-800';
                    box.innerHTML =
                        '<p class="font-bold">Resposta inválida</p><p class="mt-1">HTTP ' +
                        ctx.pack.status +
                        '.</p><pre class="mt-2 text-xs overflow-x-auto max-h-48 whitespace-pre-wrap">' +
                        lojaEscapeHtml((ctx.pack.text || '').slice(0, 8000)) +
                        '</pre>';
                    return;
                }

                var d = ctx.data;
                if (!d || typeof d !== 'object') {
                    box.className = 'mt-4 rounded-lg p-4 text-sm bg-red-100 text-red-800';
                    box.innerHTML = '<p class="font-bold">Erro</p><p class="mt-1">Resposta vazia.</p>';
                    return;
                }

                var ok = d.success === true || d.ok === true;
                var msg =
                    d.message != null
                        ? String(d.message)
                        : d.error != null
                          ? String(d.error)
                          : '';
                var syncPh = d.phases && d.phases.sync_org;
                var cronPrecisaAtencao = ok && syncPh && syncPh.ok === false;

                box.className =
                    'mt-4 rounded-lg p-4 text-sm ' +
                    (ok
                        ? cronPrecisaAtencao
                            ? 'bg-amber-50 text-amber-950 border border-amber-200'
                            : 'bg-green-100 text-green-800'
                        : 'bg-red-100 text-red-800');
                box.innerHTML =
                    '<p class="font-bold">' +
                    (ok ? (cronPrecisaAtencao ? 'Sincronização parcial' : 'Sincronização concluída') : 'Erro') +
                    '</p><p class="mt-1">' +
                    lojaEscapeHtml(msg) +
                    '</p>';

                if (
                    ok &&
                    d.phases &&
                    d.phases.sync_org &&
                    d.phases.sync_org.ok === true &&
                    d.phases.sync_org.job_id
                ) {
                    var reg = window.lojaCronRegistrarAtualizacaoJobId;
                    if (reg && typeof reg[lojaKey] === 'function') {
                        reg[lojaKey](String(d.phases.sync_org.job_id));
                    }
                }
                if (d.phases) {
                    box.innerHTML += lojaFormatarFasesHtml(d.phases);
                }
                if (Array.isArray(d.errors) && d.errors.length > 0) {
                    box.innerHTML += '<p class="mt-2 font-medium">Detalhes:</p><ul class="list-disc list-inside mt-1">';
                    d.errors.forEach(function (errItem) {
                        box.innerHTML += '<li>' + lojaEscapeHtml(String(errItem)) + '</li>';
                    });
                    box.innerHTML += '</ul>';
                }
            })
            .catch(function (e) {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                _lojaExecutarFluxoBusy = false;
                btn.disabled = false;
                setLbl('Executar');
                spi.classList.add('hidden');
                box.classList.remove('hidden');
                box.className = 'mt-4 rounded-lg p-4 text-sm bg-red-100 text-red-800';
                var msg =
                    e && e.name === 'AbortError'
                        ? 'Tempo esgotado ao contactar a API da cron-job.org.'
                        : e && e.message
                          ? e.message
                          : 'Falha na requisição.';
                box.innerHTML = '<p class="font-bold">Erro</p><p class="mt-1">' + lojaEscapeHtml(String(msg)) + '</p>';
            });
    };

    document.addEventListener('click', function (e) {
        var ex = e.target && e.target.closest && e.target.closest('.loja-btn-executar-fluxo');
        if (!ex) {
            return;
        }
        var wrap = ex.closest('.loja-executar-agora-wrap');
        var lk = (wrap && wrap.getAttribute('data-loja')) || ex.getAttribute('data-loja') || '';
        if (!lk) {
            return;
        }
        window.lojaExecutarFluxoCompleto(lk, { timeoutMs: 120000 }, wrap || null);
    });

    document.addEventListener('click', function (e) {
        var salvar = e.target && e.target.closest && e.target.closest('.loja-btn-salvar-horarios-cron');
        if (!salvar) {
            return;
        }
        var lk = salvar.getAttribute('data-loja') || '';
        if (!lk) {
            return;
        }
        var par = salvar.parentElement;
        var fb = par ? par.querySelector('.loja-salvar-horarios-feedback') : null;
        var token = getToken();
        if (!token) {
            if (fb) {
                fb.classList.remove('hidden', 'text-green-700', 'text-red-600');
                fb.classList.add('text-red-600');
                fb.textContent = 'Sem sessão. Recarregue a página.';
            }
            return;
        }
        salvar.disabled = true;
        if (fb) {
            fb.classList.remove('hidden', 'text-green-700', 'text-red-600');
            fb.classList.add('text-gray-600');
            fb.textContent = 'A gravar…';
        }
        window
            .forcarAutosaveAgora(lk)
            .then(function (res) {
                salvar.disabled = false;
                if (!fb) {
                    return;
                }
                fb.classList.remove('text-gray-600');
                if (res && res.skipped) {
                    fb.classList.add('text-amber-700');
                    fb.textContent = 'Formulário não encontrado. Recarregue a página.';
                } else if (res && res.empty) {
                    fb.classList.add('text-green-700');
                    fb.textContent = 'Já estava gravado.';
                } else {
                    fb.classList.add('text-green-700');
                    fb.textContent = 'Configuração gravada.';
                }
                clearTimeout(fb._lojaSalvarT);
                fb._lojaSalvarT = setTimeout(function () {
                    fb.classList.add('hidden');
                }, 3500);
            })
            .catch(function () {
                salvar.disabled = false;
                if (fb) {
                    fb.classList.remove('text-gray-600', 'text-green-700');
                    fb.classList.add('text-red-600');
                    fb.textContent = 'Falha ao gravar. Corrija os campos e tente de novo.';
                }
            });
    });

    window.lojaAutosaveRequest = function (payload) {
        var token = getToken();
        if (!token) {
            return Promise.reject(new Error('Sem token de sessão'));
        }
        return fetch('api/loja-patch.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Autosave-Token': token
            },
            body: JSON.stringify(payload || {})
        }).then(function (r) {
            return r.json().then(function (j) {
                if (!r.ok || !j.ok) {
                    throw new Error((j && j.error) || 'Falha na requisição');
                }
                return j;
            });
        });
    };

    function lojaEscapeHtml(s) {
        if (s == null) {
            return '';
        }
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /**
     * Executa endpoint de automação (POST JSON) e mostra resultado no painel.
     * cfg: { url, btnId?, txtId?, spiId?, boxId?, timeoutMs? }
     */
    window.lojaRunExecutar = function (cfg) {
        if (!cfg || !cfg.url) {
            return;
        }
        var btn = document.getElementById(cfg.btnId || 'btnExecutarAgora');
        var txt = document.getElementById(cfg.txtId || 'btnExecutarTexto');
        var spi = document.getElementById(cfg.spiId || 'btnExecutarSpinner');
        var box = document.getElementById(cfg.boxId || 'executarResultado');
        var timeoutMs = cfg.timeoutMs != null ? cfg.timeoutMs : 300000;
        if (!btn || !txt || !spi || !box) {
            return;
        }

        btn.disabled = true;
        txt.textContent = 'Executando...';
        spi.classList.remove('hidden');
        box.classList.add('hidden');
        box.innerHTML = '';

        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var timeoutId = controller ? setTimeout(function () { controller.abort(); }, timeoutMs) : null;
        var fetchOpts = { method: 'POST', credentials: 'same-origin' };
        if (controller) {
            fetchOpts.signal = controller.signal;
        }

        fetch(cfg.url, fetchOpts)
            .then(function (r) {
                return r.text().then(function (text) {
                    if (timeoutId) {
                        clearTimeout(timeoutId);
                    }
                    var data = null;
                    var parseErr = null;
                    var raw = text || '';
                    var t = raw.trim();
                    if (t.length > 0 && (t.charAt(0) === '{' || t.charAt(0) === '[')) {
                        try {
                            data = JSON.parse(t);
                        } catch (e) {
                            parseErr = e;
                        }
                    } else if (t.length > 0) {
                        parseErr = new Error('not_json');
                    }
                    return { okHttp: r.ok, status: r.status, text: text, data: data, parseErr: parseErr };
                });
            })
            .then(function (pack) {
                btn.disabled = false;
                txt.textContent = 'Executar agora';
                spi.classList.add('hidden');
                box.classList.remove('hidden');

                if (pack.parseErr && !pack.data) {
                    box.className = 'mt-6 p-4 rounded bg-red-100 text-red-800';
                    box.innerHTML =
                        '<p class="font-bold">Resposta inválida</p><p class="mt-1">HTTP ' +
                        pack.status +
                        '. O servidor não devolveu JSON utilizável.</p>';
                    if (pack.text) {
                        box.innerHTML +=
                            '<pre class="mt-2 text-xs overflow-x-auto max-h-48 whitespace-pre-wrap">' +
                            lojaEscapeHtml(pack.text.slice(0, 8000)) +
                            '</pre>';
                    }
                    return;
                }

                var d = pack.data;
                if (d == null || typeof d !== 'object') {
                    box.className = 'mt-6 p-4 rounded bg-red-100 text-red-800';
                    box.innerHTML =
                        '<p class="font-bold">Erro</p><p class="mt-1">Resposta vazia ou inválida (HTTP ' +
                        pack.status +
                        ').</p>';
                    return;
                }

                var isOk = d.success === true || d.ok === true;
                if (!pack.okHttp && d.success === undefined && d.ok === undefined) {
                    isOk = false;
                }
                var msg =
                    d.message != null
                        ? d.message
                        : d.error != null
                          ? d.error
                          : d.msg != null
                            ? d.msg
                            : '';
                if (!isOk && String(msg) === '' && !pack.okHttp) {
                    msg = 'HTTP ' + pack.status;
                }

                box.className =
                    'mt-6 p-4 rounded ' + (isOk ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800');
                box.innerHTML =
                    '<p class="font-bold">' +
                    (isOk ? 'Sucesso' : 'Erro') +
                    '</p><p class="mt-1">' +
                    lojaEscapeHtml(String(msg)) +
                    '</p>';

                if (d.details != null && typeof d.details === 'object' && Object.keys(d.details).length > 0) {
                    box.innerHTML +=
                        '<pre class="mt-2 text-sm opacity-90 overflow-x-auto max-h-64">' +
                        lojaEscapeHtml(JSON.stringify(d.details, null, 2)) +
                        '</pre>';
                }
                if (Array.isArray(d.errors) && d.errors.length > 0) {
                    box.innerHTML += '<p class="mt-2 font-medium">Detalhes:</p><ul class="list-disc list-inside mt-1 text-sm">';
                    d.errors.forEach(function (errItem) {
                        box.innerHTML += '<li>' + lojaEscapeHtml(String(errItem)) + '</li>';
                    });
                    box.innerHTML += '</ul>';
                }
            })
            .catch(function (e) {
                if (timeoutId) {
                    clearTimeout(timeoutId);
                }
                btn.disabled = false;
                txt.textContent = 'Executar agora';
                spi.classList.add('hidden');
                box.classList.remove('hidden');
                box.className = 'mt-6 p-4 rounded bg-red-100 text-red-800';
                var msg =
                    e && e.name === 'AbortError'
                        ? 'Demorou muito. Reduza «produtos por execução» ou aumente o tempo limite do servidor.'
                        : e && e.message
                          ? e.message
                          : 'Falha na requisição.';
                box.innerHTML = '<p class="font-bold">Erro</p><p>' + lojaEscapeHtml(String(msg)) + '</p>';
            });
    };
})();
