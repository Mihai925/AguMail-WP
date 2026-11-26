/*
 * AguMail settings page: keeps the store data sync's progress current while
 * it runs. Every few seconds it asks the settings page's own admin-ajax action
 * (bulk_sync_progress, nonce-checked for a store manager) for the progress
 * block, rendered and escaped by PHP, and swaps in the parts that changed. It
 * stops once the sync is no longer running, waits longer after a failed call,
 * and skips its turn while the tab is hidden. A 403 means the session or the
 * page's nonce expired: polling stops, and the page says to reload. Nothing
 * here talks to AguMail.
 *
 * For screen readers, the block itself is not a live region, so the numbers
 * ticking up are not read out every few seconds. A status region next to it
 * says the block's headline (syncing, waiting, finished, stopped) whenever
 * that changes, and keyboard focus inside the block survives each update.
 */
(function () {
	'use strict';

	var config = window.agumailAdmin || {};
	var interval = Number(config.interval) > 0 ? Number(config.interval) : 3000;
	var timer = null;
	var lastHtml = null;
	var lastStatus = null;
	var live = null;
	var focusable = 'a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]';

	function panel() {
		return document.querySelector('[data-agumail-bulk]');
	}

	function running(element) {
		return !!element && element.getAttribute('data-status') === 'running';
	}

	/** The block's headline, as read out: how the sync stands and why it waits. */
	function statusText(element) {
		var parts = [];
		Array.prototype.forEach.call(element.querySelectorAll('.agumail-bulk-outcome, .agumail-bulk-wait'), function (line) {
			var text = line.textContent.replace(/\s+/g, ' ').trim();
			if (text) {
				parts.push(text);
			}
		});
		return parts.join(' ');
	}

	/**
	 * Readies the block for updates: it stops being a live region as a whole,
	 * and a status region after it, there before anything changes, says the
	 * headline when it changes.
	 */
	function prepare(element) {
		element.removeAttribute('aria-live');
		if (!live) {
			live = document.createElement('p');
			live.className = 'screen-reader-text';
			live.setAttribute('role', 'status');
			live.setAttribute('aria-live', 'polite');
			live.setAttribute('aria-atomic', 'true');
			live.setAttribute('data-agumail-bulk-live', '');
		}
		if (live.parentNode !== element.parentNode || live.previousSibling !== element) {
			element.parentNode.insertBefore(live, element.nextSibling);
		}
		if (lastStatus === null) {
			lastStatus = statusText(element);
		}
	}

	function announce(element) {
		var text = statusText(element);
		if (text !== lastStatus) {
			lastStatus = text;
			live.textContent = text;
		}
	}

	function stopWithReload() {
		window.clearTimeout(timer);
		var element = panel();
		if (!element || !config.reload || element.querySelector('.agumail-bulk-reload')) {
			return;
		}
		// A status region, added empty and filled a moment later, so screen
		// readers notice the words arrive and read them out.
		var note = document.createElement('p');
		note.className = 'agumail-bulk-reload';
		note.setAttribute('role', 'status');
		element.appendChild(note);
		window.setTimeout(function () {
			note.textContent = config.reload;
		}, 100);
	}

	function later(delay) {
		window.clearTimeout(timer);
		timer = window.setTimeout(poll, delay);
	}

	/** Where focus is inside the block, to find the same control after an update. */
	function focusMark(element) {
		var active = document.activeElement;
		if (!active || active === element || !element.contains(active)) {
			return null;
		}
		var data = [];
		Array.prototype.forEach.call(active.attributes, function (attribute) {
			if (attribute.name.indexOf('data-') === 0) {
				data.push(attribute);
			}
		});
		return {
			id: active.id,
			tag: active.tagName,
			name: active.getAttribute('name'),
			data: data.map(function (attribute) { return { name: attribute.name, value: attribute.value }; }),
			index: Array.prototype.indexOf.call(element.querySelectorAll(focusable), active)
		};
	}

	function sameControl(mark, candidate) {
		if (candidate.tagName !== mark.tag) {
			return false;
		}
		if (mark.data.length) {
			return mark.data.every(function (attribute) { return candidate.getAttribute(attribute.name) === attribute.value; });
		}
		return mark.name !== null && candidate.getAttribute('name') === mark.name;
	}

	/** Puts focus back on the control that had it, or on the block. */
	function restoreFocus(element, mark) {
		var active = document.activeElement;
		if (!mark || (active && active !== document.body && document.documentElement.contains(active))) {
			return;
		}
		var controls = Array.prototype.slice.call(element.querySelectorAll(focusable));
		var target = mark.id ? document.getElementById(mark.id) : null;
		if (!target || !element.contains(target)) {
			target = null;
		}
		if (!target && (mark.data.length || mark.name !== null)) {
			target = controls.filter(function (candidate) { return sameControl(mark, candidate); })[0] || null;
		}
		if (!target && mark.index >= 0 && mark.index < controls.length) {
			target = controls[mark.index];
		}
		if (!target) {
			if (!element.hasAttribute('tabindex')) {
				element.setAttribute('tabindex', '-1');
			}
			target = element;
		}
		target.focus({ preventScroll: true });
		if (document.activeElement !== target && target !== element) {
			if (!element.hasAttribute('tabindex')) {
				element.setAttribute('tabindex', '-1');
			}
			element.focus({ preventScroll: true });
		}
	}

	/**
	 * Makes current's children match fresh's, keeping every child that did not
	 * change in place, so focus and the reader's spot in it stay put.
	 */
	function patchChildren(current, fresh) {
		var old = Array.prototype.slice.call(current.childNodes);
		var at = 0;
		Array.prototype.slice.call(fresh.childNodes).forEach(function (node) {
			var match = -1;
			for (var i = at; i < old.length; i++) {
				if (old[i].isEqualNode(node)) {
					match = i;
					break;
				}
			}
			if (match < 0) {
				current.insertBefore(node, at < old.length ? old[at] : null);
				return;
			}
			for (; at < match; at++) {
				current.removeChild(old[at]);
			}
			at++;
		});
		for (; at < old.length; at++) {
			current.removeChild(old[at]);
		}
	}

	// Set here, not by PHP: the focus fallback's tabindex, and aria-live, which prepare takes off.
	var ownAttributes = ['tabindex', 'aria-live'];

	function patchAttributes(current, fresh) {
		Array.prototype.slice.call(current.attributes).forEach(function (attribute) {
			if (ownAttributes.indexOf(attribute.name) < 0 && !fresh.hasAttribute(attribute.name)) {
				current.removeAttribute(attribute.name);
			}
		});
		Array.prototype.forEach.call(fresh.attributes, function (attribute) {
			if (ownAttributes.indexOf(attribute.name) < 0 && current.getAttribute(attribute.name) !== attribute.value) {
				current.setAttribute(attribute.name, attribute.value);
			}
		});
	}

	/** Brings the block on the page up to date with the HTML PHP sent. */
	function update(html) {
		if (html === lastHtml) {
			return;
		}
		lastHtml = html;
		var current = panel();
		if (!current) {
			return;
		}
		var holder = document.createElement('div');
		holder.innerHTML = html;
		var fresh = holder.querySelector('[data-agumail-bulk]');
		var mark = focusMark(current);
		if (fresh) {
			patchAttributes(current, fresh);
			patchChildren(current, fresh);
		} else {
			current.outerHTML = html;
			current = panel();
			if (!current) {
				return;
			}
		}
		prepare(current);
		announce(current);
		restoreFocus(current, mark);
	}

	function poll() {
		var element = panel();
		if (!running(element) || !config.ajaxUrl || !config.nonce || !window.fetch) {
			return;
		}
		if (document.hidden) {
			later(interval);
			return;
		}
		var body = new window.FormData();
		body.append('action', 'agumail');
		body.append('agumail_action', 'bulk_sync_progress');
		body.append('_ajax_nonce', config.nonce);
		window.fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (response) {
				if (response.status === 403) {
					stopWithReload();
					return false;
				}
				return response.json();
			})
			.then(function (json) {
				if (json === false) {
					return;
				}
				var data = json && json.success ? json.data : null;
				if (!data || typeof data.html !== 'string') {
					later(interval * 4);
					return;
				}
				update(data.html);
				if (data.status === 'running') {
					later(interval);
				}
			})
			.catch(function () {
				later(interval * 4);
			});
	}

	function start() {
		var element = panel();
		if (running(element)) {
			prepare(element);
			later(interval);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
