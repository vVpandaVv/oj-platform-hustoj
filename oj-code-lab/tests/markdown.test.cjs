const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const Markdown=require('../resources/js/markdown.js');
function render(source) {
 const container={innerHTML:'',textContent:''};
 const ok=Markdown.render(container,source);
 return {ok,html:container.innerHTML,text:container.textContent};
}
test('renders Korean notes with headings, emphasis, strike and existing line breaks',()=>{
 const {ok,html}=render('# 풀이 기록\n\n**굵게** *기울임* ~~취소~~\n다음 줄');
 assert.equal(ok,true);
 for(const expected of ['<h1>풀이 기록</h1>','<strong>굵게</strong>','<em>기울임</em>','<s>취소</s>','<br>\n다음 줄'])assert.ok(html.includes(expected),expected);
});
test('renders nested ordered and unordered lists, blockquotes and GFM tables',()=>{
 const {html}=render('1. 첫째\n   - 하위\n2. 둘째\n\n> 인용\n\n| 이름 | 점수 |\n| --- | ---: |\n| 판다 | 90 |');
 for(const expected of ['<ol>','<ul>','<blockquote>','<table>','<th style="text-align:right">점수</th>','<td>판다</td>'])assert.ok(html.includes(expected),expected);
});
test('code blocks and inline code escape executable markup',()=>{
 const {html}=render('```python\nprint("<script>alert(1)</script>")\n```\n\n`<img src=x onerror=alert(1)>`');
 assert.match(html,/<code class="language-python">/);
 assert.ok(html.includes('&lt;script&gt;'));
 assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
 assert.doesNotMatch(html,/<script|<img/);
});
test('raw HTML, SVG and event handler markup remains visible text',()=>{
 const {html}=render('<script>alert(1)</script>\n<img src=x onerror=alert(1)>\n<svg onload=alert(2)></svg>\n<iframe srcdoc="<script>x</script>"></iframe>');
 assert.doesNotMatch(html,/<(?:script|img|svg|iframe)\b/i);
 assert.match(html,/&lt;script&gt;/);
 assert.match(html,/&lt;img src=x onerror=alert\(1\)&gt;/);
});
test('safe links and images have privacy and navigation attributes',()=>{
 const {html}=render('[문서](https://example.com/문서?a=1&b=2) [메일](mailto:test@example.com) [목차](#section)\n![그림](https://example.com/panda.png "그림 제목")');
 assert.equal((html.match(/rel="noopener noreferrer"/g)||[]).length,3);
 assert.match(html,/href="#section"/);
 assert.match(html,/src="https:\/\/example.com\/panda.png"/);
 assert.match(html,/loading="lazy"/);
 assert.equal((html.match(/referrerpolicy="no-referrer"/g)||[]).length,4);
});
test('dangerous, relative and obfuscated URL schemes never become links or images',()=>{
 const urls=[
  'javascript:alert%281%29','JaVaScRiPt:alert%281%29','vbscript:msgbox%281%29',
  'data:text/html;base64,PHNjcmlwdD4=','data:image/png;base64,aA==','file:///etc/passwd',
  '//evil.example/x','/wp-admin/action','../action','java&#x73;cript:alert%281%29',
  'java%0ascript:alert%281%29','https://example.com/%0a/x','https://example.com/%250d/x',
  'https://example.com/%5c/x','https:%2f%2fevil.example','%2f%2fevil.example',
  'https://example.com/%00','https://example.com/%7f'
 ];
 for(const url of urls) {
  const {html}=render('[링크]('+url+') ![그림]('+url+')');
  assert.doesNotMatch(html,/<a\b|<img\b/i,url);
 }
});
test('image destinations cannot use mailto or anchors even when valid for a link',()=>{
 const {html}=render('![메일](mailto:test@example.com) ![목차](#section)');
 assert.doesNotMatch(html,/<img\b/i);
 assert.match(html,/메일/);
});
test('attribute quotes, angle brackets and fence language cannot escape output attributes',()=>{
 const {html}=render('[링크](https://example.com "x\\" onclick=\\"alert(1)")\n![이미지](https://example.com/x.png "x\\" onerror=\\"alert(1)")\n```python\" onmouseover=\"alert(1)\npass\n```');
 assert.doesNotMatch(html,/\s(?:onclick|onerror|onmouseover)="alert/);
 assert.ok(html.includes('&quot;'));
});
test('large notes fall back to complete plain text without truncating source',()=>{
 const source='한글 **노트**\n'.repeat(10001);
 const result=render(source);
 assert.equal(result.ok,false);
 assert.equal(result.text,source);
 assert.equal(result.html,'');
});
test('missing renderer fails closed and retains exact original note',()=>{
 const context={window:{}};
 vm.runInNewContext(fs.readFileSync(require.resolve('../resources/js/markdown.js'),'utf8'),context);
 const source='<img src=x onerror=alert(1)> **노트**';
 const container={textContent:'',innerHTML:''};
 assert.equal(context.window.OclMarkdown.render(container,source),false);
 assert.equal(container.textContent,source);
 assert.equal(container.innerHTML,'');
});
test('bounded adversarial reference, delimiter and HTML inputs render promptly',()=>{
 const started=Date.now();
 const samples=[
  '[x]: <'.repeat(12000),
  '<!--'.repeat(24000),
  '*_['.repeat(33000),
  '> '.repeat(24000)+'끝',
  '['.repeat(49000)+']'.repeat(49000)
 ];
 for(const source of samples)assert.equal(render(source).ok,true);
 assert.ok(Date.now()-started<5000,'preview parsing should remain bounded');
});
