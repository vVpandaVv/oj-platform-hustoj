(() => {
    const url = new URL('app.js', document.currentScript.src);
    url.searchParams.set('v', '1.0.2');
    import(url.href).catch(() => {
        document.querySelectorAll('[data-logic-lab]').forEach(root => {
            root.textContent = '실습을 불러오지 못했습니다. 페이지를 새로 고침해 주세요.';
        });
    });
})();
