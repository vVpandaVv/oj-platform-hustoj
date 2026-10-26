import"./Serializer-NKKJlpJw.js";import{t as e}from"./ordinal-hYBb2elL.js";import{t}from"./arc-CKLtQpyO.js";import{t as n}from"./pie-F70LCKER.js";import{t as r}from"./mermaid-parser.core-D6nTh3OM.js";import{n as i,r as a}from"./chunk-AGHRB4JF-CKDlTGPJ.js";import{H as o,K as s,U as c,a as l,c as u,f as d,v as f,w as p,x as m,y as h}from"./chunk-CSCIHK7Q-B8ei2NaS.js";import{t as g}from"./chunk-WU5MYG2G-B-WcXTgn.js";import{i as _,p as v}from"./chunk-5ZQYHXKU-CklTyXXi.js";import{t as y}from"./chunk-4BX2VUAB-CkRz7Y_D.js";var b=d.pie,x={sections:new Map,showData:!1,config:b},S=x.sections,C=x.showData,w=structuredClone(b),T={getConfig:i(()=>structuredClone(w),`getConfig`),clear:i(()=>{S=new Map,C=x.showData,l()},`clear`),setDiagramTitle:s,getDiagramTitle:p,setAccTitle:c,getAccTitle:h,setAccDescription:o,getAccDescription:f,addSection:i(({label:e,value:t})=>{if(t<0)throw Error(`"${e}" has invalid value: ${t}. Negative values are not allowed in pie charts. All slice values must be >= 0.`);S.has(e)||(S.set(e,t),a.debug(`added new section: ${e}, with value: ${t}`))},`addSection`),getSections:i(()=>S,`getSections`),setShowData:i(e=>{C=e},`setShowData`),getShowData:i(()=>C,`getShowData`)},E=i((e,t)=>{y(e,t),t.setShowData(e.showData),e.sections.map(t.addSection)},`populateDb`),D={parse:i(async e=>{let t=await r(`pie`,e);a.debug(t),E(t,T)},`parse`)},O=i(e=>`
  .pieCircle{
    stroke: ${e.pieStrokeColor};
    stroke-width : ${e.pieStrokeWidth};
    opacity : ${e.pieOpacity};
  }
  .pieOuterCircle{
    stroke: ${e.pieOuterStrokeColor};
    stroke-width: ${e.pieOuterStrokeWidth};
    fill: none;
  }
  .pieTitleText {
    text-anchor: middle;
    font-size: ${e.pieTitleTextSize};
    fill: ${e.pieTitleTextColor};
    font-family: ${e.fontFamily};
  }
  .slice {
    font-family: ${e.fontFamily};
    fill: ${e.pieSectionTextColor};
    font-size:${e.pieSectionTextSize};
    // fill: white;
  }
  .legend text {
    fill: ${e.pieLegendTextColor};
    font-family: ${e.fontFamily};
    font-size: ${e.pieLegendTextSize};
  }
`,`getStyles`),k=i(e=>{let t=[...e.values()].reduce((e,t)=>e+t,0),r=[...e.entries()].map(([e,t])=>({label:e,value:t})).filter(e=>e.value/t*100>=1);return n().value(e=>e.value).sort(null)(r)},`createPieArcs`),A={parser:D,db:T,renderer:{draw:i((n,r,i,o)=>{a.debug(`rendering pie chart
`+n);let s=o.db,c=m(),l=_(s.getConfig(),c.pie),d=g(r),f=d.append(`g`);f.attr(`transform`,`translate(225,225)`);let{themeVariables:p}=c,[h]=v(p.pieOuterStrokeWidth);h??=2;let y=l.textPosition,b=t().innerRadius(0).outerRadius(185),x=t().innerRadius(185*y).outerRadius(185*y);f.append(`circle`).attr(`cx`,0).attr(`cy`,0).attr(`r`,185+h/2).attr(`class`,`pieOuterCircle`);let S=s.getSections(),C=k(S),w=[p.pie1,p.pie2,p.pie3,p.pie4,p.pie5,p.pie6,p.pie7,p.pie8,p.pie9,p.pie10,p.pie11,p.pie12],T=0;S.forEach(e=>{T+=e});let E=C.filter(e=>(e.data.value/T*100).toFixed(0)!==`0`),D=e(w).domain([...S.keys()]);f.selectAll(`mySlices`).data(E).enter().append(`path`).attr(`d`,b).attr(`fill`,e=>D(e.data.label)).attr(`class`,`pieCircle`),f.selectAll(`mySlices`).data(E).enter().append(`text`).text(e=>(e.data.value/T*100).toFixed(0)+`%`).attr(`transform`,e=>`translate(`+x.centroid(e)+`)`).style(`text-anchor`,`middle`).attr(`class`,`slice`);let O=f.append(`text`).text(s.getDiagramTitle()).attr(`x`,0).attr(`y`,-400/2).attr(`class`,`pieTitleText`),A=[...S.entries()].map(([e,t])=>({label:e,value:t})),j=f.selectAll(`.legend`).data(A).enter().append(`g`).attr(`class`,`legend`).attr(`transform`,(e,t)=>{let n=22*A.length/2;return`translate(216,`+(t*22-n)+`)`});j.append(`rect`).attr(`width`,18).attr(`height`,18).style(`fill`,e=>D(e.label)).style(`stroke`,e=>D(e.label)),j.append(`text`).attr(`x`,22).attr(`y`,14).text(e=>s.getShowData()?`${e.label} [${e.value}]`:e.label);let M=512+Math.max(...j.selectAll(`text`).nodes().map(e=>e?.getBoundingClientRect().width??0)),N=O.node()?.getBoundingClientRect().width??0,P=450/2-N/2,F=450/2+N/2,I=Math.min(0,P),L=Math.max(M,F)-I;d.attr(`viewBox`,`${I} 0 ${L} 450`),u(d,450,L,l.useMaxWidth)},`draw`)},styles:O};export{A as diagram};