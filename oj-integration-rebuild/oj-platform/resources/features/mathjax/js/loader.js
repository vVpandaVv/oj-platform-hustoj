(function () {
  'use strict';

  var targetSelector = '[data-ojp-latex]';
  var runtimeId = 'oj-platform-mathjax-runtime';
  var loaderScript = document.currentScript
    || document.getElementById('oj-platform-mathjax-loader-js');

  function pendingTargets() {
    return Array.prototype.slice.call(document.querySelectorAll(targetSelector))
      .filter(function (target) {
        return target.getAttribute('data-ojp-latex-rendered') !== 'true';
      });
  }

  function report(error) {
    if (window.console && typeof window.console.error === 'function') {
      window.console.error('OJ Platform MathJax rendering failed.', error);
    }
  }

  function typeset(targets) {
    if (!window.MathJax || typeof window.MathJax.typesetPromise !== 'function') {
      return Promise.resolve();
    }

    return window.MathJax.typesetPromise(targets).then(function () {
      targets.forEach(function (target) {
        target.setAttribute('data-ojp-latex-rendered', 'true');
      });
    }).catch(report);
  }

  function typesetAfterStartup(targets) {
    var startup = window.MathJax && window.MathJax.startup;
    if (startup && startup.promise && typeof startup.promise.then === 'function') {
      return startup.promise.then(function () {
        return typeset(targets);
      }).catch(report);
    }

    return typeset(targets);
  }

  function addUnique(values, value) {
    if (values.indexOf(value) === -1) {
      values.push(value);
    }

    return values;
  }

  function configure() {
    var config = window.MathJax && typeof window.MathJax === 'object'
      ? window.MathJax
      : {};
    config.loader = config.loader || {};
    config.loader.load = addUnique(
      Array.isArray(config.loader.load) ? config.loader.load.slice() : [],
      'ui/safe'
    );

    config.tex = config.tex || {};
    config.tex.inlineMath = [['\\(', '\\)']];
    config.tex.displayMath = [['\\[', '\\]'], ['$$', '$$']];
    config.tex.processEscapes = true;
    config.tex.processEnvironments = false;
    var packages = config.tex.packages && !Array.isArray(config.tex.packages)
      ? config.tex.packages
      : {};
    var removedPackages = Array.isArray(packages['[-]'])
      ? packages['[-]'].slice()
      : [];
    addUnique(removedPackages, 'require');
    addUnique(removedPackages, 'autoload');
    packages['[-]'] = removedPackages;
    config.tex.packages = packages;

    config.options = config.options || {};
    config.options.processHtmlClass = 'ojp-math-content';
    config.options.ignoreHtmlClass = 'ojp-math-ignore';
    config.options.enableMenu = false;
    config.options.safeOptions = {
      allow: {
        URLs: 'safe',
        classes: 'none',
        cssIDs: 'none',
        styles: 'none'
      },
      safeProtocols: {
        http: true,
        https: true,
        file: false,
        javascript: false,
        data: false
      }
    };

    config.startup = config.startup || {};
    config.startup.typeset = false;
    window.MathJax = config;
  }

  function runtimeUrl() {
    if (!loaderScript || !loaderScript.src) {
      return '';
    }

    var loaderUrl = new URL(loaderScript.src, document.baseURI);
    var url = new URL('../vendor/tex-chtml.js', loaderUrl);
    var version = loaderUrl.searchParams.get('ver');
    if (version) {
      url.searchParams.set('ver', version);
    }

    return url.toString();
  }

  function start() {
    var targets = pendingTargets();
    if (targets.length === 0) {
      return;
    }

    if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') {
      typesetAfterStartup(targets);
      return;
    }

    var existing = document.getElementById(runtimeId)
      || document.getElementById('MathJax-script');
    if (existing) {
      existing.addEventListener('load', function () {
        typesetAfterStartup(targets);
      }, {once: true});
      return;
    }

    var source = runtimeUrl();
    if (source === '') {
      return;
    }

    configure();
    var script = document.createElement('script');
    script.id = runtimeId;
    script.src = source;
    script.async = true;
    script.addEventListener('load', function () {
      typesetAfterStartup(targets);
    }, {once: true});
    script.addEventListener('error', function () {
      report(new Error('MathJax runtime could not be loaded.'));
    }, {once: true});
    document.head.appendChild(script);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, {once: true});
  } else {
    start();
  }
}());
