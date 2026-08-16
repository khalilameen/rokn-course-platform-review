/**
 * We'll load jQuery and the Bootstrap jQuery plugin which provides support
 * for JavaScript based Bootstrap features such as modals and tabs. This
 * code may be modified to fit the specific needs of your application.
 */

const Popper = require('popper.js');

window.Popper = Popper.default || Popper;
window.$ = window.jQuery = require('jquery');

require('bootstrap');

