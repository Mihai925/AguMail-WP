/*
 * AguMail: checkout email capture.
 *
 * When a shopper leaves an email field, the address is posted in the
 * background to ?wc-ajax=agumail_capture, so a cart they abandon can still be
 * recovered. Nothing waits for it: the post is fire-and-forget, and it survives
 * the page moving on. Only the email is sent. The marketing checkbox is never
 * read here; it counts only on a submitted order.
 *
 * The fields, found by listening on the document so any checkout works:
 *   #billing_email   the classic checkout, and CheckoutWC
 *   #email           the block checkout's contact step (#contact-fields); no
 *                    other email input there, such as a gift recipient's
 *   #username        My Account login, when it holds an email
 *   #reg_email       My Account registration
 *
 * A shopper who types their email and then leaves the page without leaving the
 * field is caught too: when the page is hidden, the email still in the focused
 * field goes as a beacon.
 *
 * When the answer carries a signature, the AguMail site script (if the store
 * runs it) is told who this is, with the address exactly as the server signed
 * it: window.agumail.identify(answer.email, answer.sig).
 *
 * Plain ES5, no dependencies. window.agumailCapture.endpoint, and the nonce
 * the call carries (window.agumailCapture.nonce), are set by the
 * plugin next to the script.
 */
(function (window, document) {
  'use strict';

  var config = window.agumailCapture;
  if (!config || !config.endpoint || !document || !document.addEventListener) {
    return;
  }

  var emailPattern = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;
  var lastSent = '';

  function hasClass(node, name) {
    return typeof node.className === 'string' && (' ' + node.className + ' ').indexOf(' ' + name + ' ') !== -1;
  }

  function within(element, test) {
    for (var node = element.parentNode; node && node.nodeType === 1; node = node.parentNode) {
      if (test(node)) {
        return true;
      }
    }
    return false;
  }

  function inBlockCheckout(node) {
    return node.id === 'contact-fields' || hasClass(node, 'wc-block-checkout');
  }

  /** checkout, login or register for a field this script captures; null for any other. */
  function contextOf(field) {
    if (!field || field.tagName !== 'INPUT') {
      return null;
    }
    if (field.id === 'billing_email') {
      return 'checkout';
    }
    if (field.id === 'username') {
      return 'login';
    }
    if (field.id === 'reg_email') {
      return 'register';
    }
    if (field.id === 'email' && within(field, inBlockCheckout)) {
      return 'checkout';
    }
    return null;
  }

  function identify(email, sig) {
    var tracker = window.agumail;
    if (tracker && typeof tracker.identify === 'function') {
      tracker.identify(email, sig);
      return;
    }
    // The site script loads on its own schedule; if it has not yet, try once it has.
    if (document.readyState !== 'complete' && window.addEventListener) {
      window.addEventListener('load', function () {
        var loaded = window.agumail;
        if (loaded && typeof loaded.identify === 'function') {
          loaded.identify(email, sig);
        }
      });
    }
  }

  function beacon(body) {
    var navigator = window.navigator;
    if (!navigator || typeof navigator.sendBeacon !== 'function' || typeof window.Blob !== 'function') {
      return false;
    }
    try {
      return navigator.sendBeacon(config.endpoint, new window.Blob([body], { type: 'application/x-www-form-urlencoded' }));
    } catch (error) {
      return false;
    }
  }

  /** Posts the email in the background; a page that is going away sends a beacon, which needs no answer. */
  function send(email, context, leaving) {
    var body = 'email=' + encodeURIComponent(email) + '&context=' + encodeURIComponent(context)
      + '&nonce=' + encodeURIComponent(config.nonce || '');
    if (leaving && beacon(body)) {
      return;
    }
    if (typeof window.fetch === 'function') {
      try {
        window.fetch(config.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          keepalive: true,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body
        }).then(function (response) {
          return response && response.ok ? response.json() : null;
        }).then(function (answer) {
          // The server signs the address as it sanitised it, so identify with that one.
          if (answer && typeof answer.sig === 'string' && answer.sig && typeof answer.email === 'string' && answer.email) {
            identify(answer.email, answer.sig);
          }
        })['catch'](function () {});
        return;
      } catch (error) {
        // An older fetch that refuses keepalive: send a beacon instead.
      }
    }
    // Nothing more to try; capture is a nicety, never in the shopper's way.
    beacon(body);
  }

  function capture(field, leaving) {
    var context = contextOf(field);
    if (!context) {
      return;
    }
    var email = String(field.value || '').replace(/^\s+|\s+$/g, '').toLowerCase();
    if (email === lastSent || !emailPattern.test(email)) {
      return;
    }
    lastSent = email;
    send(email, context, leaving);
  }

  function onLeave(event) {
    try {
      capture(event && event.target, false);
    } catch (error) {
      // Never let capture break the page.
    }
  }

  function onHidden() {
    try {
      if (document.visibilityState === 'hidden') {
        capture(document.activeElement, true);
      }
    } catch (error) {
      // Never let capture break the page.
    }
  }

  // The capture phase, so a script that stops the event on the field cannot hide it.
  document.addEventListener('focusout', onLeave, true);
  document.addEventListener('change', onLeave, true);
  document.addEventListener('visibilitychange', onHidden);
}(window, document));
