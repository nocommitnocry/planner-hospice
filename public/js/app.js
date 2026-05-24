'use strict';

/**
 * JS dell'applicazione (servito come file esterno: la CSP è `script-src 'self'`
 * e blocca i gestori inline `on*=` e gli script inline).
 *
 * Conferme: ogni <form data-confirm="messaggio"> chiede conferma prima di
 * inviare. Sostituisce i vecchi `onsubmit="return confirm('...')"` inline.
 * Listener delegato sul document: copre anche i form aggiunti dopo il load.
 */
document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }
    var messaggio = form.getAttribute('data-confirm');
    if (messaggio && !window.confirm(messaggio)) {
        event.preventDefault();
    }
});
