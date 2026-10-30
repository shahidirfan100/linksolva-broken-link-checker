(function (window, document) {
	'use strict';

	var config = window.LINKSOLVA_BLC_DATA || {};
	var nonceRefresh = null;
	var root = document.getElementById('sblc-app');
	if (!root) {
		return;
	}

	var state = {
		screen: root.getAttribute('data-screen') || config.page || 'findings',
		status: 'all',
		type: 'all',
		scope: 'all',
		scopeInitialized: false,
		search: '',
		page: 1,
		perPage: 20,
		monitoring: false,
		monitorTimer: null,
		reconcileTimer: null,
		unchangedSteps: 0,
		lastStepProgressKey: '',
		refreshId: 0,
		historyRunId: 0,
		historyPage: 1,
		lastProgress: null,
		drawerOpener: null,
		bodyOverflow: ''
	};

	function t(value) {
		return config.strings && config.strings[value] ? config.strings[value] : value;
	}

	function escapeHtml(value) {
		return String(value === null || typeof value === 'undefined' ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function displayStatus(status) {
		var labels = {
			unverified: 'Unverified', pending: 'Queued', broken: 'Broken', redirect: 'Redirects', blocked: 'Blocked',
			rate_limited: 'Rate Limited', temporary_error: 'Temporary Error', ssl_error: 'SSL Error',
			dns_error: 'DNS Error', timeout: 'Timeout', healthy: 'Healthy', ignored: 'Ignored'
		};
		return t(labels[status] || String(status || 'unverified').replace(/_/g, ' ').replace(/\b\w/g, function (letter) { return letter.toUpperCase(); }));
	}

	function translateMarkup(html) {
		var template = document.createElement('template');
		template.innerHTML = html;
		var walker = document.createTreeWalker(template.content, window.NodeFilter.SHOW_TEXT);
		var node;
		while ((node = walker.nextNode())) {
			var text = node.nodeValue.trim();
			if (text && config.strings && config.strings[text]) {
				node.nodeValue = node.nodeValue.replace(text, config.strings[text]);
			}
		}
		template.content.querySelectorAll('[aria-label], [placeholder]').forEach(function (element) {
			['aria-label', 'placeholder'].forEach(function (attribute) {
				var value = element.getAttribute(attribute);
				if (value && config.strings && config.strings[value]) {
					element.setAttribute(attribute, config.strings[value]);
				}
			});
		});
		return template.innerHTML;
	}

	function renewNonce() {
		if (!nonceRefresh) {
			nonceRefresh = fetch(config.ajaxUrl + '?action=sblc_refresh_rest_nonce', { credentials: 'same-origin', cache: 'no-store' }).then(function (response) {
				return response.json().then(function (body) {
					if (!response.ok || !body.success || !body.data || !body.data.nonce) { throw new Error(t('Your session has expired or you do not have permission. Sign in again.')); }
					config.nonce = body.data.nonce;
				});
			}).finally(function () { nonceRefresh = null; });
		}
		return nonceRefresh;
	}

	function api(path, options, retried) {
		var endpoint = config.restUrl + path;
		var queryAt = path.indexOf('?');
		if (queryAt !== -1 && config.restUrl.indexOf('?rest_route=') !== -1) {
			endpoint = config.restUrl + path.slice(0, queryAt) + '&' + path.slice(queryAt + 1);
		}
		options = options || {};
		options.headers = options.headers || {};
		options.headers['X-WP-Nonce'] = config.nonce;
		options.headers['Content-Type'] = 'application/json';
		options.cache = 'no-store';
		var request;
		if ( '/scan/step' === path || '/dashboard' === path || path.indexOf('/resources?') === 0 ) {
			var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
			if ( controller ) { options.signal = controller.signal; }
			request = new Promise(function (resolve, reject) {
				var timeoutId = window.setTimeout(function () {
					if ( controller ) { controller.abort(); }
					reject(new Error(t('The request timed out. Try again.')));
				}, '/scan/step' === path ? 30000 : 15000);
				fetch(endpoint, options).then(function (response) {
					window.clearTimeout(timeoutId);
					resolve(response);
				}, function (error) {
					window.clearTimeout(timeoutId);
					reject(error);
				});
			});
		} else {
			request = fetch(endpoint, options);
		}
		return request.then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (body) {
				if (!response.ok) {
					if (!retried && response.status === 403 && body.code === 'rest_cookie_invalid_nonce' && config.ajaxUrl) {
						return renewNonce().then(function () { delete options.signal; return api(path, options, true); });
					}
					if (response.status === 401) { throw new Error(t('Your session has expired. Sign in again and retry.')); }
					throw new Error(body.message || t('The request could not be completed.'));
				}
				return body;
			});
		});
	}

	function post(path, body) {
		return api(path, { method: 'POST', body: JSON.stringify(body || {}) });
	}

	function notice(message, type) {
		var existing = root.querySelector('.sblc-notice-live');
		if (existing) {
			existing.remove();
		}
		var element = document.createElement('div');
		element.className = 'sblc-notice sblc-notice-live ' + (type ? 'sblc-notice-' + type : '');
		element.setAttribute('role', type === 'error' ? 'alert' : 'status');
		element.textContent = message;
		var main = root.querySelector('.sblc-main');
		if (main) {
			main.insertBefore(element, main.firstChild);
		}
	}

	function button(label, action, extraClass, extra) {
		return '<button type="button" class="sblc-button ' + (extraClass || 'sblc-button-secondary') + '" data-action="' + escapeHtml(action) + '"' + (extra || '') + '>' + escapeHtml(label) + '</button>';
	}

	function patchFindings(screen, markup) {
		var staging = document.createElement('div');
		staging.innerHTML = markup;

		function patchInner(selector) {
			var current = screen.querySelector(selector);
			var next = staging.querySelector(selector);
			if (current && next && current.innerHTML !== next.innerHTML) {
				current.innerHTML = next.innerHTML;
			}
		}

		patchInner('.sblc-toolbar-actions');
		var currentBody = screen.querySelector('.sblc-table tbody');
		var nextBody = staging.querySelector('.sblc-table tbody');
		if (currentBody && nextBody) {
			var emptyRow = currentBody.querySelector('.sblc-empty');
			if (emptyRow) {
				emptyRow.closest('tr').remove();
			}
			var currentRows = {};
			Array.prototype.forEach.call(currentBody.querySelectorAll('[data-resource-row]'), function (row) {
				currentRows[row.getAttribute('data-resource-row')] = row;
			});
			Array.prototype.forEach.call(nextBody.children, function (nextRow) {
				var key = nextRow.getAttribute('data-resource-row');
				var currentRow = key ? currentRows[key] : null;
				if (currentRow) {
					if (currentRow.innerHTML !== nextRow.innerHTML) {
						currentRow.innerHTML = nextRow.innerHTML;
					}
					currentBody.appendChild(currentRow);
					delete currentRows[key];
				} else {
					currentBody.appendChild(nextRow.cloneNode(true));
				}
			});
			Object.keys(currentRows).forEach(function (key) { currentRows[key].remove(); });
			if (!nextBody.querySelector('[data-resource-row]') && currentBody.innerHTML !== nextBody.innerHTML) {
				currentBody.innerHTML = nextBody.innerHTML;
			}
		}
		patchInner('.sblc-pagination');

		var currentStatus = screen.querySelector('.sblc-scan-status');
		var nextStatus = staging.querySelector('.sblc-scan-status');
		if (currentStatus && nextStatus) {
			if (currentStatus.innerHTML !== nextStatus.innerHTML) {
				currentStatus.innerHTML = nextStatus.innerHTML;
			}
		} else if (currentStatus) {
			currentStatus.remove();
		} else if (nextStatus) {
			screen.querySelector('.sblc-toolbar').insertAdjacentElement('afterend', nextStatus);
		}

		var currentMetrics = screen.querySelectorAll('.sblc-metric-value');
		var nextMetrics = staging.querySelectorAll('.sblc-metric-value');
		Array.prototype.forEach.call(currentMetrics, function (metric, index) {
			if (nextMetrics[index] && metric.textContent !== nextMetrics[index].textContent) {
				metric.textContent = nextMetrics[index].textContent;
			}
		});

		Array.prototype.forEach.call(screen.querySelectorAll('[data-action="filter-status"]'), function (filter) {
			var status = filter.getAttribute('data-status');
			var nextFilter = staging.querySelector('[data-action="filter-status"][data-status="' + status + '"]');
			if (!nextFilter) { return; }
			filter.className = nextFilter.className;
			var count = filter.querySelector('.sblc-filter-count');
			var nextCount = nextFilter.querySelector('.sblc-filter-count');
			if (count && nextCount && count.textContent !== nextCount.textContent) {
				count.textContent = nextCount.textContent;
			}
		});
	}

	function isActiveScan(progress) {
		return !!progress && ['discovering', 'checking', 'cancelling'].indexOf(progress.state) !== -1;
	}

	function progressKey(progress) {
		var stats = progress && progress.stats ? progress.stats : {};
		return [
			progress && progress.scan_id || 0,
			progress && progress.state || '',
			progress && progress.phase || '',
			progress && progress.provider || '',
			progress && progress.page || 0,
			progress && progress.resource_cursor || 0,
			stats.checked_resources || 0,
			stats.total_resources || 0,
			progress && progress.processed_sources || 0,
			progress && progress.discovered_occurrences || 0
		].join(':');
	}

	function elapsedLabel(seconds) {
		seconds = Math.max(0, Math.floor(Number(seconds) || 0));
		return t('Elapsed') + ': ' + Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
	}

	function updateElapsed() {
		var timer = root.querySelector('[data-scan-elapsed]');
		if (!timer) { return; }
		var elapsed = Number(timer.getAttribute('data-elapsed-base')) || 0;
		var at = Number(timer.getAttribute('data-elapsed-at')) || Date.now();
		timer.textContent = elapsedLabel(elapsed + Math.floor(Math.max(0, Date.now() - at) / 1000));
	}

	function scanStatusMarkup(progress) {
		return translateMarkup('<div class="sblc-scan-status" role="status"><strong>' + escapeHtml(progress.phase === 'discovery' ? t('Discovering sources') : t('Verifying resources')) + '</strong><span>' + escapeHtml(progress.message || '') + '</span><div class="sblc-progress" aria-label="Scan progress"><span style="width:' + escapeHtml(progress.percent || 0) + '%"></span></div><span>' + escapeHtml(progress.percent || 0) + '%</span><span data-scan-elapsed data-elapsed-base="' + escapeHtml(progress.elapsed_seconds || 0) + '" data-elapsed-at="' + Date.now() + '" aria-live="off">' + escapeHtml(elapsedLabel(progress.elapsed_seconds)) + '</span>' + button(t('Stop scan'), 'cancel-scan', 'sblc-button-danger', ' data-scan-id="' + escapeHtml(progress.scan_id) + '"') + '</div>');
	}

	function showActiveScan(progress) {
		if (!isActiveScan(progress)) { return; }
		var screen = root.querySelector('[data-screen-content]');
		if (!screen) { return; }
		var wrapper = document.createElement('div');
		wrapper.innerHTML = scanStatusMarkup(progress);
		var nextStatus = wrapper.firstElementChild;
		var currentStatus = screen.querySelector('.sblc-scan-status');
		if (currentStatus) {
			currentStatus.parentNode.replaceChild(nextStatus, currentStatus);
			return;
		}
		var toolbar = screen.querySelector('.sblc-toolbar');
		if (toolbar) {
			screen.insertBefore(nextStatus, toolbar.nextSibling);
		} else {
			screen.insertBefore(nextStatus, screen.firstChild);
		}
	}

	function resumeActiveScan(progress) {
		if (isActiveScan(progress) && !state.monitoring && !state.monitorTimer) {
			monitor(progress.scan_id);
		}
	}

	function renderFindings(data) {
		var progress = data.progress || {};
		var counts = data.counts || progress.counts || {};
		var active = isActiveScan(progress);
		var scanStatus = active
			? scanStatusMarkup(progress)
			: progress.state === 'completed' && progress.stats && Number(progress.stats.skipped_resources) > 0
				? '<div class="sblc-notice" role="status">' + escapeHtml(progress.message || '') + '</div>'
				: progress.state === 'failed' && progress.message
					? '<div class="sblc-notice" role="alert">' + escapeHtml(progress.message) + '</div>'
				: '';
		var list = data.list || { items: [], total: 0 };
		var filterCounts = list.filter_counts || counts;
		var statuses = [
			['all', t('All'), filterCounts.all], ['broken', t('Broken'), filterCounts.broken], ['redirect', t('Redirects'), filterCounts.redirect], ['needs_review', t('Needs review'), filterCounts.needs_review], ['blocked', t('Blocked'), filterCounts.blocked], ['healthy', t('Healthy'), filterCounts.healthy], ['ignored', t('Ignored'), filterCounts.ignored]
		];
		var filters = statuses.map(function (item) {
			return '<button type="button" class="sblc-filter ' + (state.status === item[0] ? 'is-active' : '') + '" data-action="filter-status" data-status="' + item[0] + '">' + escapeHtml(item[1]) + '<span class="sblc-filter-count">' + escapeHtml(item[2] || 0) + '</span></button>';
		}).join('');
		var rows = list.items || [];
		var table = rows.length ? rows.map(renderRow).join('') : '<tr><td colspan="7"><div class="sblc-empty">' + escapeHtml(t('No findings match these filters.')) + '</div></td></tr>';
		var totalPages = Math.max(1, Math.ceil((list.total || 0) / state.perPage));
		var screen = root.querySelector('[data-screen-content]');
		var markup = translateMarkup('<section class="sblc-toolbar"><div><h2>Findings</h2><p>Summary totals include all links; status filters follow scope, type and search. During a scan, the selected scope is checked first, then other links.</p></div><div class="sblc-toolbar-actions">' + (active ? '' : button(t('Run new scan'), 'start-scan', 'sblc-button-primary')) + button(t('Export CSV'), 'export-findings', 'sblc-button-secondary') + '</div></section>' + scanStatus + '<section class="sblc-metrics" aria-label="Finding summary"><div class="sblc-metric sblc-metric-danger"><span class="sblc-metric-label">Broken</span><strong class="sblc-metric-value">' + escapeHtml(counts.broken || 0) + '</strong></div><div class="sblc-metric sblc-metric-warn"><span class="sblc-metric-label">Needs review</span><strong class="sblc-metric-value">' + escapeHtml(counts.needs_review || 0) + '</strong></div><div class="sblc-metric"><span class="sblc-metric-label">Redirects</span><strong class="sblc-metric-value">' + escapeHtml(counts.redirect || 0) + '</strong></div><div class="sblc-metric sblc-metric-good"><span class="sblc-metric-label">Healthy</span><strong class="sblc-metric-value">' + escapeHtml(counts.healthy || 0) + '</strong></div><div class="sblc-metric"><span class="sblc-metric-label">Unique URLs</span><strong class="sblc-metric-value">' + escapeHtml(counts.all || 0) + '</strong></div><div class="sblc-metric"><span class="sblc-metric-label">Occurrences</span><strong class="sblc-metric-value">' + escapeHtml(progress.discovered_occurrences || 0) + '</strong></div></section><section class="sblc-panel"><div class="sblc-filters"><div>' + filters + '</div><div class="sblc-filter-controls"><label class="screen-reader-text" for="sblc-search">Search URL</label><input id="sblc-search" class="sblc-input" type="search" placeholder="Search URL" value="' + escapeHtml(state.search) + '" data-search-field /><label class="screen-reader-text" for="sblc-type">Resource type</label><select id="sblc-type" class="sblc-select" data-type-field><option value="all">All types</option><option value="link"' + (state.type === 'link' ? ' selected' : '') + '>Links</option><option value="image"' + (state.type === 'image' ? ' selected' : '') + '>Images</option></select>' + button(t('Clear filters'), 'clear-filters', 'sblc-button-secondary') + '</div></div><div class="sblc-filters"><label class="screen-reader-text" for="sblc-bulk">Bulk action</label><select id="sblc-bulk" class="sblc-select" data-bulk-select><option value="">Bulk actions</option><option value="recheck">Queue recheck</option><option value="ignore">Ignore</option><option value="restore">Restore</option></select>' + button(t('Apply'), 'bulk-action', 'sblc-button-secondary') + '<span class="sblc-subline">Bulk URL replacement is intentionally unavailable.</span></div><div class="sblc-table-wrap"><table class="sblc-table"><thead><tr><th class="sblc-check-col"><label class="screen-reader-text" for="sblc-select-all">Select all</label><input id="sblc-select-all" type="checkbox" data-select-all /></th><th>Status</th><th>URL</th><th>HTTP</th><th>Type</th><th>Sources</th><th>Action</th></tr></thead><tbody>' + table + '</tbody></table></div><div class="sblc-pagination"><p>' + escapeHtml(t('Showing')) + ' ' + escapeHtml(rows.length ? ((state.page - 1) * state.perPage + 1) : 0) + '–' + escapeHtml(Math.min(state.page * state.perPage, list.total || 0)) + ' ' + escapeHtml(t('of')) + ' ' + escapeHtml(list.total || 0) + '</p><div class="sblc-actions">' + button(t('Previous'), 'page-prev', 'sblc-button-secondary', totalPages > 1 && state.page > 1 ? '' : ' disabled') + button(t('Next'), 'page-next', 'sblc-button-secondary', totalPages > 1 && state.page < totalPages ? '' : ' disabled') + '</div></div></section>');
		if (screen.querySelector('.sblc-toolbar')) {
			patchFindings(screen, markup);
		} else {
			screen.innerHTML = markup;
		}
		var typeSelect = screen.querySelector('[data-type-field]');
		if (typeSelect && !screen.querySelector('[data-scope-field]')) {
			var scopeSelect = document.createElement('select');
			scopeSelect.id = 'sblc-scope';
			scopeSelect.className = 'sblc-select';
			scopeSelect.setAttribute('data-scope-field', '');
			scopeSelect.setAttribute('aria-label', t('Link scope'));
			scopeSelect.innerHTML = '<option value="all">' + escapeHtml(t('All links')) + '</option><option value="internal">' + escapeHtml(t('Internal links')) + '</option><option value="external">' + escapeHtml(t('External links')) + '</option>';
			scopeSelect.value = state.scope;
			typeSelect.parentNode.insertBefore(scopeSelect, typeSelect);
		}
	}

	function renderRow(item) {
		var status = item.status || 'unverified';
		var sourceText = item.occurrence_count + ' ' + t(Number(item.occurrence_count) === 1 ? 'source' : 'sources');
		return translateMarkup('<tr data-resource-row="' + escapeHtml(item.id) + '"><td class="sblc-check-col"><input type="checkbox" value="' + escapeHtml(item.id) + '" data-resource-check /></td><td><span class="sblc-status sblc-status-' + escapeHtml(status) + '">' + escapeHtml(displayStatus(status)) + '</span><span class="sblc-subline">' + escapeHtml(item.confidence || 'unverified') + '</span></td><td><a class="sblc-url" href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer" title="' + escapeHtml(item.url) + '">' + escapeHtml(item.url) + '</a><span class="sblc-subline">' + escapeHtml(item.explanation || '') + '</span></td><td>' + escapeHtml(item.http_code || t('No response')) + '</td><td>' + escapeHtml(item.resource_type || 'link') + '<span class="sblc-subline">' + escapeHtml(displayStatus(item.link_scope || 'external')) + '</span></td><td>' + escapeHtml(sourceText) + '</td><td><div class="sblc-actions">' + '<button type="button" class="sblc-icon-button" data-action="open-resource" data-resource-id="' + escapeHtml(item.id) + '">Evidence</button>' + (status === 'ignored' ? '<button type="button" class="sblc-icon-button" data-action="resource-action" data-resource-id="' + escapeHtml(item.id) + '" data-resource-action="restore">Restore</button>' : '<button type="button" class="sblc-icon-button" data-action="resource-action" data-resource-id="' + escapeHtml(item.id) + '" data-resource-action="ignore">Ignore</button>') + '</div></td></tr>');
	}

	function renderSettings(data) {
		var settings = data.settings || {};
		var postTypes = data.post_types || [];
		var typeFields = postTypes.map(function (type) {
			var name = type.name || type.slug || '';
			var label = type.label || name;
			return '<label class="sblc-check"><input type="checkbox" name="post_types[]" value="' + escapeHtml(name) + '"' + ((settings.post_types || []).indexOf(name) !== -1 ? ' checked' : '') + ' /> <span>' + escapeHtml(label) + ' <code>' + escapeHtml(name) + '</code></span></label>';
		}).join('');
		root.querySelector('[data-screen-content]').innerHTML = translateMarkup('<section class="sblc-panel sblc-settings"><h2>Settings</h2><p class="sblc-settings-intro">Requests stay on this WordPress server. The plugin does not use an account, cloud scanner, tracking, or link credits.</p><form data-settings-form><div class="sblc-settings-section"><h3>Verification safety</h3><div class="sblc-field-grid"><div class="sblc-field"><label for="sblc-batch">Resources per worker request</label><input class="sblc-input" id="sblc-batch" name="batch_size" type="number" min="1" max="10" value="' + escapeHtml(settings.batch_size) + '" /><p class="sblc-help">The worker caps this at ten and lowers it when PHP memory is tight.</p></div><div class="sblc-field"><label for="sblc-parallel">Parallel URL checks</label><input class="sblc-input" id="sblc-parallel" name="parallel_requests" type="number" min="1" max="4" value="' + escapeHtml(settings.parallel_requests || 3) + '" /><p class="sblc-help">The worker caps this at four and lowers it when PHP memory is tight.</p></div><div class="sblc-field"><label for="sblc-timeout">Request timeout, seconds</label><input class="sblc-input" id="sblc-timeout" name="timeout" type="number" min="3" max="60" value="' + escapeHtml(settings.timeout) + '" /></div><div class="sblc-field"><label for="sblc-redirects">Maximum redirect hops</label><input class="sblc-input" id="sblc-redirects" name="max_redirects" type="number" min="0" max="15" value="' + escapeHtml(settings.max_redirects) + '" /></div><div class="sblc-field"><label for="sblc-retries">Timeout retries</label><input class="sblc-input" id="sblc-retries" name="max_retries" type="number" min="0" max="4" value="' + escapeHtml(settings.max_retries) + '" /></div></div></div><div class="sblc-settings-section"><h3>Content sources</h3><div class="sblc-field-grid"><div class="sblc-field sblc-field-full"><label>Public post types</label><div class="sblc-field-grid">' + typeFields + '</div><p class="sblc-help">Leave all unchecked to include every public post type except attachments and menu items.</p></div><label class="sblc-check"><input type="checkbox" name="include_comments"' + (settings.include_comments ? ' checked' : '') + ' /> <span>Scan approved comments</span></label><label class="sblc-check"><input type="checkbox" name="include_menus"' + (settings.include_menus ? ' checked' : '') + ' /> <span>Scan navigation menu URLs</span></label><label class="sblc-check"><input type="checkbox" name="include_featured_images"' + (settings.include_featured_images ? ' checked' : '') + ' /> <span>Check featured images</span></label><label class="sblc-check"><input type="checkbox" name="include_custom_fields"' + (settings.include_custom_fields ? ' checked' : '') + ' /> <span>Read scalar custom-field and builder data</span></label><label class="sblc-check"><input type="checkbox" name="check_internal_links"' + (settings.check_internal_links ? ' checked' : '') + ' /> <span>Check internal links</span></label><label class="sblc-check"><input type="checkbox" name="check_external_links"' + (settings.check_external_links ? ' checked' : '') + ' /> <span>Check external links</span></label><div class="sblc-field sblc-field-full"><label for="sblc-meta-keys">Limit custom-field keys (one per line, optional)</label><textarea class="sblc-input" id="sblc-meta-keys" name="custom_meta_keys" rows="3">' + escapeHtml((settings.custom_meta_keys || []).join('\n')) + '</textarea><p class="sblc-help">Builder metadata is read-only in this release; it is never rewritten blindly.</p></div></div></div><div class="sblc-settings-section"><h3>Scan history</h3><div class="sblc-field-grid"><div class="sblc-field"><label for="sblc-history-limit">Scans to keep</label><select class="sblc-select" id="sblc-history-limit" name="scan_history_limit"><option value="0"' + (Number(settings.scan_history_limit) === 0 ? ' selected' : '') + '>Off</option><option value="5"' + (Number(settings.scan_history_limit) === 5 ? ' selected' : '') + '>5 scans</option><option value="10"' + (Number(settings.scan_history_limit) === 10 ? ' selected' : '') + '>10 scans</option><option value="25"' + (Number(settings.scan_history_limit) === 25 ? ' selected' : '') + '>25 scans</option></select><p class="sblc-help">Save completed and stopped scans, including URL results and source locations. Lowering the limit removes the oldest saved runs.</p></div></div></div><div class="sblc-settings-section"><h3>Exclusions and lifecycle</h3><div class="sblc-field-grid"><div class="sblc-field"><label for="sblc-excluded-domains">Excluded domains (one per line)</label><textarea class="sblc-input" id="sblc-excluded-domains" name="excluded_domains" rows="3">' + escapeHtml((settings.excluded_domains || []).join('\n')) + '</textarea></div><div class="sblc-field"><label for="sblc-excluded-fragments">Excluded URL fragments (one per line)</label><textarea class="sblc-input" id="sblc-excluded-fragments" name="excluded_url_fragments" rows="3">' + escapeHtml((settings.excluded_url_fragments || []).join('\n')) + '</textarea></div><div class="sblc-field"><label for="sblc-schedule">Scheduled scans</label><select class="sblc-select" id="sblc-schedule" name="schedule"><option value="manual"' + ('manual' === settings.schedule ? ' selected' : '') + '>Manual only</option><option value="daily"' + ('daily' === settings.schedule ? ' selected' : '') + '>Daily</option><option value="weekly"' + ('weekly' === settings.schedule ? ' selected' : '') + '>Weekly</option></select><p class="sblc-help">Scheduled work starts a bounded scan from WP-Cron. Manual scanning remains available if cron is delayed.</p></div><label class="sblc-check"><input type="checkbox" name="email_notifications"' + (settings.email_notifications ? ' checked' : '') + ' /> <span>Email a local summary when a scan completes</span></label><div class="sblc-field"><label for="sblc-email">Notification email</label><input class="sblc-input" id="sblc-email" name="notification_email" type="email" value="' + escapeHtml(settings.notification_email || '') + '" /></div><label class="sblc-check"><input type="checkbox" name="delete_data_on_uninstall"' + (settings.delete_data_on_uninstall ? ' checked' : '') + ' /> <span>Delete findings when the plugin is uninstalled</span></label></div></div><div class="sblc-actions">' + button(t('Save settings'), 'save-settings', 'sblc-button-primary') + '</div></form></section>');
	}

	function renderHistoryList(data) {
		var items = data.items || [];
		var rows = items.map(function (run) {
			var counts = run.counts || {};
			return '<tr><td>#' + escapeHtml(run.id) + '</td><td>' + escapeHtml(run.state === 'completed' ? t('Complete') : t('Incomplete')) + '</td><td>' + escapeHtml(run.started_at) + '</td><td>' + escapeHtml(run.finished_at || '') + '</td><td>' + escapeHtml(run.checked_resources || 0) + '</td><td>' + escapeHtml(run.discovered_occurrences || 0) + '</td><td>' + escapeHtml(counts.broken || 0) + ' / ' + escapeHtml(counts.needs_review || 0) + ' / ' + escapeHtml(counts.redirect || 0) + ' / ' + escapeHtml(counts.healthy || 0) + '</td><td><button type="button" class="sblc-button sblc-button-secondary" data-action="view-history-run" data-scan-id="' + escapeHtml(run.id) + '">' + escapeHtml(t('View results')) + '</button></td></tr>';
		}).join('');
		var emptyMessage = Number(data.limit) === 0 ? t('Scan history is off. Enable it in Settings to save future runs.') : t('No scans have been saved yet.');
		var markup = '<section class="sblc-toolbar"><div><h2>Scan history</h2><p>Review results saved from completed and stopped scans.</p></div></section><section class="sblc-panel">' + (rows ? '<div class="sblc-table-wrap"><table class="sblc-table"><thead><tr><th>Run</th><th>Status</th><th>Started (UTC)</th><th>Finished (UTC)</th><th>URLs checked</th><th>Sources found</th><th>Broken / review / healthy</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<p class="sblc-subline">' + escapeHtml(emptyMessage) + '</p>') + '</section>';
		root.querySelector('[data-screen-content]').innerHTML = translateMarkup(markup);
	}

	function renderHistoryResults(data, scanId) {
		var items = data.items || [];
		var rows = items.map(function (item) {
			return '<tr><td><span class="sblc-status sblc-status-' + escapeHtml(item.status || 'unverified') + '">' + escapeHtml(displayStatus(item.status)) + '</span><span class="sblc-subline">' + escapeHtml(item.confidence || '') + '</span></td><td><a class="sblc-url" href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(item.url) + '</a><span class="sblc-subline">' + escapeHtml(item.explanation || '') + '</span></td><td>' + escapeHtml(item.http_code || t('No response')) + '</td><td>' + escapeHtml(item.resource_type || 'link') + '</td><td>' + escapeHtml(item.occurrence_count || 0) + '</td><td><button type="button" class="sblc-icon-button" data-action="open-history-evidence" data-scan-id="' + escapeHtml(scanId) + '" data-resource-id="' + escapeHtml(item.resource_id) + '">' + escapeHtml(t('View evidence')) + '</button></td></tr>';
		}).join('');
		var totalPages = Math.max(1, Math.ceil((data.total || 0) / state.perPage));
		var markup = '<section class="sblc-toolbar"><div><h2>' + escapeHtml(t('Run')) + ' #' + escapeHtml(scanId) + '</h2><p>Saved URL results from this run.</p></div><div class="sblc-toolbar-actions"><button type="button" class="sblc-button sblc-button-secondary" data-action="history-back">' + escapeHtml(t('Back to scan history')) + '</button></div></section><section class="sblc-panel"><div class="sblc-table-wrap"><table class="sblc-table"><thead><tr><th>Status</th><th>URL</th><th>HTTP</th><th>Type</th><th>Sources</th><th></th></tr></thead><tbody>' + (rows || '<tr><td colspan="6">' + escapeHtml(t('No URL results were saved for this run.')) + '</td></tr>') + '</tbody></table></div><div class="sblc-pagination"><p>' + escapeHtml(t('Showing')) + ' ' + escapeHtml(items.length ? ((state.historyPage - 1) * state.perPage + 1) : 0) + '–' + escapeHtml(Math.min(state.historyPage * state.perPage, data.total || 0)) + ' ' + escapeHtml(t('of')) + ' ' + escapeHtml(data.total || 0) + '</p><div class="sblc-actions"><button type="button" class="sblc-button sblc-button-secondary" data-action="history-page-prev"' + (state.historyPage <= 1 ? ' disabled' : '') + '>' + escapeHtml(t('Previous')) + '</button><button type="button" class="sblc-button sblc-button-secondary" data-action="history-page-next"' + (state.historyPage >= totalPages ? ' disabled' : '') + '>' + escapeHtml(t('Next')) + '</button></div></div></section>';
		root.querySelector('[data-screen-content]').innerHTML = translateMarkup(markup);
	}

	function loadHistoryResults(scanId) {
		var content = root.querySelector('[data-screen-content]');
		content.innerHTML = '<div class="sblc-loading">' + escapeHtml(t('Loading history results…')) + '</div>';
		var query = '?page=' + state.historyPage + '&per_page=' + state.perPage;
		return api('/history/' + encodeURIComponent(scanId) + '/resources' + query).then(function (data) {
			renderHistoryResults(data, scanId);
		}).catch(function (error) { notice(error.message, 'error'); });
	}

	function renderHistoryEvidence(data) {
		var occurrences = (data.occurrences || []).map(function (item) {
			return '<article class="sblc-occurrence"><strong>' + escapeHtml(item.source_title || t('Untitled source')) + '</strong><p>' + escapeHtml(item.source_type) + ' · ' + escapeHtml(item.source_field) + ' · ' + escapeHtml(item.location) + '</p><p><a href="' + escapeHtml(item.source_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(t('Open source')) + '</a></p><p>' + escapeHtml(item.context || item.anchor_text || '') + '</p></article>';
		}).join('');
		var history = (data.request_history || []).map(function (item) { return '<li><code>' + escapeHtml(item.method) + '</code><span>' + escapeHtml(item.status || item.message || t('No response')) + '</span><span>' + escapeHtml(item.duration || 0) + 's</span></li>'; }).join('');
		var redirects = (data.redirect_chain || []).map(function (item) { return '<li><span>' + escapeHtml(item.url || item) + '</span><span>' + escapeHtml(item.status || '') + '</span></li>'; }).join('');
		var drawer = document.createElement('div');
		drawer.className = 'sblc-drawer-backdrop';
		drawer.setAttribute('role', 'presentation');
		drawer.innerHTML = translateMarkup('<aside class="sblc-drawer" role="dialog" aria-modal="true" aria-labelledby="sblc-history-drawer-title"><div class="sblc-drawer-header"><h2 id="sblc-history-drawer-title">' + escapeHtml(data.url) + '</h2><button type="button" class="sblc-close" data-action="close-drawer" aria-label="Close evidence">×</button></div><div class="sblc-drawer-body"><dl class="sblc-detail-grid"><dt>Classification</dt><dd><span class="sblc-status sblc-status-' + escapeHtml(data.status) + '">' + escapeHtml(displayStatus(data.status)) + '</span></dd><dt>Confidence</dt><dd>' + escapeHtml(data.confidence || '') + '</dd><dt>HTTP result</dt><dd>' + escapeHtml(data.http_code || t('No response')) + ' ' + escapeHtml(data.status_text || '') + '</dd><dt>Final URL</dt><dd>' + escapeHtml(data.final_url || data.url) + '</dd><dt>Response time</dt><dd>' + escapeHtml(data.response_time || 0) + 's</dd><dt>Last checked</dt><dd>' + escapeHtml(data.last_checked || t('Not checked')) + '</dd><dt>Explanation</dt><dd>' + escapeHtml(data.explanation || '') + '</dd></dl><section class="sblc-evidence-section"><h3>Request history</h3><ul class="sblc-history">' + (history || '<li>No request history.</li>') + '</ul></section>' + (redirects ? '<section class="sblc-evidence-section"><h3>Redirect chain</h3><ul class="sblc-history">' + redirects + '</ul></section>' : '') + '<section class="sblc-evidence-section"><h3>' + escapeHtml(t('Source locations')) + ' (' + escapeHtml((data.occurrences || []).length) + ')</h3>' + (occurrences || '<p>' + escapeHtml(t('No source locations were saved for this URL.')) + '</p>') + '</section></div></aside>');
		state.drawerOpener = document.activeElement;
		root.appendChild(drawer);
		setDrawerBackgroundHidden(true);
		state.bodyOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		var close = drawer.querySelector('[data-action="close-drawer"]');
		if (close) { close.focus(); }
	}

	function setDrawerBackgroundHidden( hidden ) {
		Array.prototype.forEach.call( root.children, function ( element ) {
			if ( element.classList.contains( 'sblc-drawer-backdrop' ) ) {
				return;
			}
			element.inert = hidden;
			if ( hidden ) {
				element.setAttribute( 'aria-hidden', 'true' );
			} else {
				element.removeAttribute( 'aria-hidden' );
			}
		} );
	}

	function closeDrawer() {
		var backdrop = root.querySelector( '.sblc-drawer-backdrop' );
		if ( ! backdrop ) {
			return;
		}
		backdrop.remove();
		setDrawerBackgroundHidden( false );
		document.body.style.overflow = state.bodyOverflow;
		var opener = state.drawerOpener;
		state.drawerOpener = null;
		if ( opener && document.documentElement.contains( opener ) ) {
			opener.focus();
		}
	}

	function renderDrawer(data) {
		var resource = data || {};
		var history = (resource.request_history || []).map(function (item) { return '<li><code>' + escapeHtml(item.method) + '</code><span>' + escapeHtml(item.status || item.message || t('No response')) + '</span><span>' + escapeHtml(item.duration || 0) + 's</span></li>'; }).join('');
		var redirects = (resource.redirect_chain || []).map(function (item) { return '<li><span>' + escapeHtml(item.status) + '</span><span>' + escapeHtml(item.from) + '</span><span>→ ' + escapeHtml(item.to) + '</span></li>'; }).join('');
		var occurrences = (resource.occurrences || []).map(function (item) {
			var form = Number(item.editable) === 1 ? translateMarkup('<div class="sblc-repair-form"><label for="sblc-new-url-' + escapeHtml(item.id) + '">Replacement URL</label><input class="sblc-input" id="sblc-new-url-' + escapeHtml(item.id) + '" type="url" value="' + escapeHtml(resource.final_url && resource.status === 'redirect' ? resource.final_url : '') + '" data-repair-url="' + escapeHtml(item.id) + '" /><div class="sblc-actions"><button type="button" class="sblc-button sblc-button-primary" data-action="apply-repair" data-occurrence-id="' + escapeHtml(item.id) + '" data-operation="replace">Replace URL</button><button type="button" class="sblc-button sblc-button-secondary" data-action="apply-repair" data-occurrence-id="' + escapeHtml(item.id) + '" data-operation="nofollow">Add nofollow</button><button type="button" class="sblc-button sblc-button-secondary" data-action="apply-repair" data-occurrence-id="' + escapeHtml(item.id) + '" data-operation="unlink">Unlink</button></div></div>') : translateMarkup('<p><strong>Read-only source.</strong> This source can be reviewed but is not automatically edited.</p>');
			return translateMarkup('<article class="sblc-occurrence"><strong>' + escapeHtml(item.source_title || t('Untitled source')) + '</strong><p>' + escapeHtml(item.source_type) + ' · ' + escapeHtml(item.source_field) + ' · ' + escapeHtml(item.location) + '</p><p><a href="' + escapeHtml(item.source_url) + '" target="_blank" rel="noopener noreferrer">Open source</a></p><p>' + escapeHtml(item.context || item.anchor_text || '') + '</p>' + form + '</article>');
		}).join('');
		var repairs = (resource.repairs || []).map(function (item) {
			return translateMarkup('<li><span>' + escapeHtml(displayStatus(item.operation)) + ' · ' + escapeHtml(item.source_type) + ' · ' + escapeHtml(item.created_at) + '</span>' + (Number(item.undone) ? '<span class="sblc-subline">Undone</span>' : '<button type="button" class="sblc-icon-button" data-action="undo-repair" data-repair-id="' + escapeHtml(item.id) + '">Undo</button>') + (item.undo_error ? '<span class="sblc-subline">' + escapeHtml(item.undo_error) + '</span>' : '') + '</li>');
		}).join('');
		var drawer = document.createElement('div');
		drawer.className = 'sblc-drawer-backdrop';
		drawer.setAttribute('role', 'presentation');
		drawer.innerHTML = translateMarkup('<aside class="sblc-drawer" role="dialog" aria-modal="true" aria-labelledby="sblc-drawer-title"><div class="sblc-drawer-header"><h2 id="sblc-drawer-title">' + escapeHtml(resource.url) + '</h2><button type="button" class="sblc-close" data-action="close-drawer" aria-label="Close evidence">×</button></div><div class="sblc-drawer-body"><div class="sblc-actions">' + button(t('Recheck'), 'drawer-resource-action', 'sblc-button-secondary', ' data-resource-id="' + escapeHtml(resource.id) + '" data-resource-action="recheck"') + (resource.status === 'ignored' ? button(t('Restore'), 'drawer-resource-action', 'sblc-button-secondary', ' data-resource-id="' + escapeHtml(resource.id) + '" data-resource-action="restore"') : button(t('Ignore'), 'drawer-resource-action', 'sblc-button-secondary', ' data-resource-id="' + escapeHtml(resource.id) + '" data-resource-action="ignore"')) + button(t('Mark manually verified'), 'drawer-resource-action', 'sblc-button-secondary', ' data-resource-id="' + escapeHtml(resource.id) + '" data-resource-action="manual_verify"') + '</div><dl class="sblc-detail-grid"><dt>Classification</dt><dd><span class="sblc-status sblc-status-' + escapeHtml(resource.status) + '">' + escapeHtml(displayStatus(resource.status)) + '</span></dd><dt>Confidence</dt><dd>' + escapeHtml(resource.confidence) + '</dd><dt>HTTP result</dt><dd>' + escapeHtml(resource.http_code || t('No response')) + ' ' + escapeHtml(resource.status_text || '') + '</dd><dt>Final URL</dt><dd>' + escapeHtml(resource.final_url || resource.url) + '</dd><dt>Response time</dt><dd>' + escapeHtml(resource.response_time || 0) + 's</dd><dt>Last checked</dt><dd>' + escapeHtml(resource.last_checked || t('Not checked')) + '</dd><dt>Explanation</dt><dd>' + escapeHtml(resource.explanation || '') + '</dd></dl><section class="sblc-evidence-section"><h3>Request history</h3><ul class="sblc-history">' + (history || '<li>No request history.</li>') + '</ul></section>' + (redirects ? '<section class="sblc-evidence-section"><h3>Redirect chain</h3><ul class="sblc-history">' + redirects + '</ul></section>' : '') + '<section class="sblc-evidence-section"><h3>' + escapeHtml(t('Source locations')) + ' (' + escapeHtml((resource.occurrences || []).length) + ')</h3>' + (occurrences || '<p>No source locations remain in the current scan.</p>') + '</section>' + (repairs ? '<section class="sblc-evidence-section"><h3>Repair history</h3><ul class="sblc-history">' + repairs + '</ul></section>' : '') + '</div></aside>');
		state.drawerOpener = state.drawerOpener || document.activeElement;
		root.appendChild(drawer);
		setDrawerBackgroundHidden( true );
		state.bodyOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		var close = drawer.querySelector('[data-action="close-drawer"]');
		if (close) { close.focus(); }
	}

	function refresh(options) {
		var shouldResume = !options || options.resumeScan !== false;
		if (state.screen === 'settings') {
			root.querySelector('[data-screen-content]').innerHTML = '<div class="sblc-loading">' + escapeHtml(t('Loading settings…')) + '</div>';
			return api('/settings').then(renderSettings).catch(function (error) { notice(error.message, 'error'); });
		}
		if (state.screen === 'history') {
			root.querySelector('[data-screen-content]').innerHTML = '<div class="sblc-loading">' + escapeHtml(t('Loading history…')) + '</div>';
			return api('/history').then(renderHistoryList).catch(function (error) { notice(error.message, 'error'); });
		}
		if (!root.querySelector('[data-screen-content] .sblc-toolbar')) {
			root.querySelector('[data-screen-content]').innerHTML = '<div class="sblc-loading">' + escapeHtml(t('Loading findings…')) + '</div>';
		}
		var requestId = ++state.refreshId;
		var initialScope = state.scope;
		function loadList() {
			var query = '?page=' + state.page + '&per_page=' + state.perPage + '&status=' + encodeURIComponent(state.status) + '&type=' + encodeURIComponent(state.type) + '&scope=' + encodeURIComponent(state.scope) + '&search=' + encodeURIComponent(state.search);
			return api('/resources' + query);
		}
		var dashboardRequest = api('/dashboard');
		var listRequest = loadList();
		var progressRequest = dashboardRequest.then(function (dashboard) {
			if (requestId === state.refreshId) {
				if (!state.scopeInitialized) {
					state.scopeInitialized = true;
					if (isActiveScan(dashboard.progress) && ['internal', 'external'].indexOf(dashboard.progress.priority_scope) !== -1) { state.scope = dashboard.progress.priority_scope; }
				}
				state.lastProgress = dashboard.progress;
				if ( ! isActiveScan( dashboard.progress ) ) {
					window.clearTimeout(state.reconcileTimer);
					state.reconcileTimer = null;
					state.unchangedSteps = 0;
					state.lastStepProgressKey = '';
				}
				showActiveScan(dashboard.progress);
				if (shouldResume) { resumeActiveScan(dashboard.progress); }
			}
			return dashboard;
		});
		return Promise.all([progressRequest, listRequest]).then(function (responses) {
			return (initialScope === state.scope ? Promise.resolve(responses[1]) : loadList()).then(function (list) { return { dashboard: responses[0], list: list }; });
		}).then(function (responses) {
			if (requestId !== state.refreshId) { return; }
			var dashboard = responses.dashboard;
			dashboard.list = responses.list;
			state.lastProgress = dashboard.progress;
			renderFindings(dashboard);
			if (shouldResume) { resumeActiveScan(dashboard.progress); }
		}).catch(function (error) {
			if (requestId === state.refreshId) {
				var content = root.querySelector('[data-screen-content]');
				if (!content.querySelector('.sblc-toolbar')) { content.innerHTML = '<div class="sblc-empty"><p>' + escapeHtml(t('Findings could not be loaded.')) + '</p>' + button(t('Try again'), 'retry-findings') + '</div>'; }
				notice(error.message, 'error');
			}
		});
	}

	function scheduleMonitor(scanId, delay, retryCount) {
		window.clearTimeout(state.monitorTimer);
		state.monitorTimer = window.setTimeout(function () {
			state.monitorTimer = null;
			monitor(scanId, retryCount);
		}, delay);
	}

	function monitor(scanId, retryCount) {
		if (state.monitoring || !scanId) { return; }
		retryCount = Number(retryCount) || 0;
		state.monitoring = true;
		post('/scan/step', { scan_id: scanId, priority_scope: state.scope }).then(function (progress) {
			var nextKey = progressKey(progress);
			if (isActiveScan(progress) && nextKey === state.lastStepProgressKey) {
				state.unchangedSteps += 1;
			} else {
				state.unchangedSteps = 0;
			}
			state.lastStepProgressKey = nextKey;
			state.lastProgress = progress;
			showActiveScan(progress);
			return refresh().then(function () {
				state.monitoring = false;
				var latest = state.lastProgress || progress;
				if (latest.state === 'discovering' || latest.state === 'checking' || latest.state === 'cancelling') {
					if (state.unchangedSteps >= 45) {
						notice(t('The scan has not advanced. Automatic polling stopped; refresh this page to check its status.'), 'error');
						return;
					}
					scheduleMonitor(latest.scan_id, state.unchangedSteps >= 3 ? 5000 : 1600, 0);
				} else {
					state.unchangedSteps = 0;
					state.lastStepProgressKey = '';
					window.clearTimeout(state.reconcileTimer);
					state.reconcileTimer = null;
				}
			});
		}).catch(function () {
			state.monitoring = false;
			refresh({ resumeScan: false }).then(function () {
				var latest = state.lastProgress;
				if ( ! isActiveScan( latest ) || Number( latest.scan_id ) !== Number( scanId ) ) {
					return;
				}
				if ( retryCount >= 6 ) {
					notice(t('The scan worker is not responding. Automatic retries stopped; refresh this page to try again.'), 'error');
					window.clearTimeout(state.reconcileTimer);
					state.reconcileTimer = window.setTimeout(function () {
						state.reconcileTimer = null;
						refresh({ resumeScan: false }).then(function () {
							if (isActiveScan(state.lastProgress)) {
								notice(t('The scan is still active. Refresh this page to resume it.'), 'error');
							}
						});
					}, 60000);
					return;
				}
				scheduleMonitor(scanId, Math.min(8000, 1000 * Math.pow(2, Math.min(retryCount, 3))), retryCount + 1);
			});
		});
	}

	function selectedIds() {
		return Array.prototype.slice.call(root.querySelectorAll('[data-resource-check]:checked')).map(function (input) { return Number(input.value); });
	}

	root.addEventListener('click', function (event) {
		if ( event.target.classList && event.target.classList.contains( 'sblc-drawer-backdrop' ) ) {
			closeDrawer();
			return;
		}
		var target = event.target.closest('[data-action]');
		if (!target) { return; }
		var action = target.getAttribute('data-action');
		if (action === 'view-history-run') {
			state.historyRunId = Number(target.getAttribute('data-scan-id'));
			state.historyPage = 1;
			loadHistoryResults(state.historyRunId);
			return;
		}
		if (action === 'history-back') {
			state.historyRunId = 0;
			refresh();
			return;
		}
		if (action === 'history-page-prev' && state.historyPage > 1) {
			state.historyPage -= 1;
			loadHistoryResults(state.historyRunId);
			return;
		}
		if (action === 'history-page-next') {
			state.historyPage += 1;
			loadHistoryResults(state.historyRunId);
			return;
		}
		if (action === 'open-history-evidence') {
			var historicalScanId = target.getAttribute('data-scan-id');
			var historicalResourceId = target.getAttribute('data-resource-id');
			api('/history/' + encodeURIComponent(historicalScanId) + '/resource/' + encodeURIComponent(historicalResourceId)).then(renderHistoryEvidence).catch(function (error) { notice(error.message, 'error'); });
			return;
		}
		if (action === 'clear-filters') {
			state.status = 'all'; state.scope = 'all'; state.type = 'all'; state.search = ''; state.page = 1;
			['[data-scope-field]', '[data-type-field]'].forEach(function (selector) { var field = root.querySelector(selector); if (field) { field.value = 'all'; } });
			var searchField = root.querySelector('[data-search-field]'); if (searchField) { searchField.value = ''; }
			window.clearTimeout(state.searchTimer); refresh(); return;
		}
		if (action === 'filter-status') {
			state.status = target.getAttribute('data-status') || 'all'; state.page = 1; refresh(); return;
		}
		if (action === 'open-resource') {
			state.drawerOpener = target;
			var evidenceLabel = target.textContent;
			target.disabled = true; target.textContent = t('Loading…'); target.setAttribute('aria-busy', 'true');
			api('/resource/' + encodeURIComponent(target.getAttribute('data-resource-id'))).then(renderDrawer).catch(function (error) { state.drawerOpener = null; notice(error.message, 'error'); }).finally(function () { target.disabled = false; target.textContent = evidenceLabel; target.removeAttribute('aria-busy'); }); return;
		}
		if (action === 'retry-findings') { refresh(); return; }
		if (action === 'close-drawer') { closeDrawer(); return; }
		if (action === 'start-scan') {
			window.clearTimeout(state.monitorTimer);
			state.monitorTimer = null;
			post('/scan/start', { priority_scope: state.scope }).then(function (progress) { notice(t('Scan started. Discovery and verification will run in small bounded requests.'), 'success'); state.lastProgress = progress; state.monitoring = true; renderFindings({ progress: progress, counts: progress.counts || {}, list: { items: [], total: 0 } }); state.monitoring = false; monitor(progress.scan_id); }).catch(function (error) { notice(error.message, 'error'); }); return;
		}
		if (action === 'cancel-scan') {
			if (!window.confirm(t('Stop the current scan after its active bounded request?'))) { return; }
			window.clearTimeout(state.monitorTimer);
			state.monitorTimer = null;
			post('/scan/cancel', { scan_id: Number(target.getAttribute('data-scan-id')) }).then(function (progress) { state.lastProgress = progress; state.monitoring = false; refresh(); }).catch(function (error) { state.monitoring = false; notice(error.message, 'error'); }); return;
		}
		if (action === 'page-prev' && state.page > 1) { state.page -= 1; refresh(); return; }
		if (action === 'page-next') { state.page += 1; refresh(); return; }
		if (action === 'export-findings') { window.location.href = config.exportUrl; return; }
		if (action === 'bulk-action') {
			var select = root.querySelector('[data-bulk-select]'); var ids = selectedIds();
			if (!select || !select.value || !ids.length) { notice(t('Select resources and a bulk action first.'), 'error'); return; }
			post('/resources/bulk', { ids: ids, action: select.value }).then(function (result) { notice(result.message || t('Resources updated.'), 'success'); refresh(); }).catch(function (error) { notice(error.message, 'error'); }); return;
		}
		if (action === 'resource-action' || action === 'drawer-resource-action') {
			post('/resource/' + encodeURIComponent(target.getAttribute('data-resource-id')) + '/action', { action: target.getAttribute('data-resource-action') }).then(function (result) { notice(t('Resource updated.'), 'success'); closeDrawer(); refresh(); }).catch(function (error) { notice(error.message, 'error'); }); return;
		}
		if (action === 'undo-repair') {
			if (!window.confirm(t('Restore the source content saved before this repair?'))) { return; }
			post('/repair/' + encodeURIComponent(target.getAttribute('data-repair-id')) + '/undo', {}).then(function (result) { notice(result.message || t('Repair undone.'), 'success'); closeDrawer(); refresh(); }).catch(function (error) { notice(error.message, 'error'); }); return;
		}
		if (action === 'apply-repair') {
			var occurrenceId = target.getAttribute('data-occurrence-id'); var input = root.querySelector('[data-repair-url="' + occurrenceId + '"]'); var operation = target.getAttribute('data-operation');
			if (operation === 'unlink' && !window.confirm(t('Unlink this occurrence while preserving its visible text?'))) { return; }
			if (operation !== 'unlink' && !window.confirm(t('This will change stored WordPress content and create an undo record. Continue?'))) { return; }
			var repairButtons = target.closest('.sblc-repair-form').querySelectorAll('[data-action="apply-repair"]');
			var repairLabel = target.textContent;
			repairButtons.forEach(function (control) { control.disabled = true; });
			target.setAttribute('aria-busy', 'true'); target.textContent = t('Saving…');
			post('/repair', { occurrence_id: Number(occurrenceId), operation: operation, new_url: input ? input.value : '' }).then(function (result) { closeDrawer(); notice(result.message || t('Source updated.'), 'success'); refresh(); }).catch(function (error) {
				var form = target.closest('.sblc-repair-form');
				var message = form.querySelector('[data-repair-error]') || document.createElement('p');
				message.setAttribute('data-repair-error', ''); message.setAttribute('role', 'alert'); message.textContent = error.message;
				form.insertBefore(message, form.firstChild);
			}).finally(function () { repairButtons.forEach(function (control) { control.disabled = false; }); target.textContent = repairLabel; target.removeAttribute('aria-busy'); }); return;
		}
		if (action === 'save-settings') {
			event.preventDefault(); var form = root.querySelector('[data-settings-form]'); var formData = new FormData(form); var payload = {};
			formData.forEach(function (value, key) { if (key.slice(-2) === '[]') { var clean = key.slice(0, -2); payload[clean] = payload[clean] || []; payload[clean].push(value); } else { payload[key] = value; } });
			['include_comments','include_menus','include_featured_images','include_custom_fields','check_internal_links','check_external_links','email_notifications','delete_data_on_uninstall'].forEach(function (key) { payload[key] = form.querySelector('[name="' + key + '"]').checked; });
			['custom_meta_keys','excluded_domains','excluded_url_fragments'].forEach(function (key) { payload[key] = String(payload[key] || '').split(/\r?\n/).map(function (value) { return value.trim(); }).filter(Boolean); });
			post('/settings', payload).then(function () { notice(t('Settings saved.'), 'success'); }).catch(function (error) { notice(error.message, 'error'); }); return;
		}
	});

	root.addEventListener( 'keydown', function ( event ) {
		var backdrop = root.querySelector( '.sblc-drawer-backdrop' );
		if ( ! backdrop ) {
			return;
		}
		if ( 'Escape' === event.key ) {
			event.preventDefault();
			closeDrawer();
			return;
		}
		if ( 'Tab' !== event.key ) {
			return;
		}
		var dialog = backdrop.querySelector( '[role="dialog"]' );
		var focusable = dialog.querySelectorAll( 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])' );
		if ( ! focusable.length ) {
			event.preventDefault();
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if ( ! dialog.contains( document.activeElement ) || ( event.shiftKey && document.activeElement === first ) ) {
			event.preventDefault();
			( event.shiftKey ? last : first ).focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	root.addEventListener('input', function (event) {
		if (!event.target.hasAttribute('data-search-field')) { return; }
		window.clearTimeout(state.searchTimer);
		state.searchTimer = window.setTimeout(function () { state.search = event.target.value || ''; state.page = 1; refresh(); }, 350);
	});
	root.addEventListener('change', function (event) {
		if (event.target.hasAttribute('data-type-field')) { state.type = event.target.value; state.page = 1; refresh(); }
		if (event.target.hasAttribute('data-scope-field')) { state.scope = event.target.value; state.page = 1; refresh(); }
		if (event.target.hasAttribute('data-select-all')) { root.querySelectorAll('[data-resource-check]').forEach(function (input) { input.checked = event.target.checked; }); }
	});

	window.setInterval(updateElapsed, 1000);
	refresh();
}(window, document));
