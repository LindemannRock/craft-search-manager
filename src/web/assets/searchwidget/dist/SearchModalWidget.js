var SearchModalWidget=(()=>{var K=Object.defineProperty;var _e=Object.getOwnPropertyDescriptor;var qe=Object.getOwnPropertyNames;var ze=Object.prototype.hasOwnProperty;var je=(e,r)=>{for(var t in r)K(e,t,{get:r[t],enumerable:!0})},Ge=(e,r,t,n)=>{if(r&&typeof r=="object"||typeof r=="function")for(let o of qe(r))!ze.call(e,o)&&o!==t&&K(e,o,{get:()=>r[o],enumerable:!(n=_e(r,o))||n.enumerable});return e};var We=e=>Ge(K({},"__esModule",{value:!0}),e);var vt={};je(vt,{default:()=>ft});var Ye={indices:[],placeholder:"Search...",theme:"light",maxResults:20,debounce:200,minChars:2,showRecent:!0,maxRecentSearches:5,groupResults:!0,siteId:"",searchEndpoint:"/actions/search-manager/api/search",trackClickEndpoint:"/actions/search-manager/search/track-click",trackSearchEndpoint:"/actions/search-manager/search/track-search",idleTimeout:1500,source:"",enableHighlighting:!0,highlightTag:"mark",highlightClass:"",hideResultsWithoutUrl:!1,showCodeSnippets:!1,snippetMode:"balanced",showLoadingIndicator:!0,debug:!1,resultTitleLines:1,resultDescLines:1,snippetLength:150,parseMarkdownSnippets:!1,persistQueryInUrl:!0,queryParamName:"smq",highlightDestinationPage:!0,destinationHighlightSelector:"main, article, [data-search-content]",resultLayout:"default",hierarchyGroupBy:"",hierarchyStyle:"tree",hierarchyDisplay:"individual",maxHeadingsPerResult:3,styles:{},promotions:{showBadge:!0,badgeText:"Featured",badgePosition:"top-right"}},Ke={hotkey:"k",showTrigger:!0,triggerSelector:"",backdropOpacity:50,enableBackdropBlur:!0,preventBodyScroll:!0},Ve={showFilters:!0,paginationType:"numbered",resultsPerPage:20,updateUrl:!0,sortOptions:["relevance","date-desc","date-asc","title"]},Qe={dropdownPosition:"below",dropdownMaxHeight:400,showOnFocus:!0};function Xe(e){return{...Ye,...{modal:Ke,page:Ve,inline:Qe}[e]||{}}}function f(e,r=!1){return e==null?r:e===""?!0:e!=="false"&&e!=="0"}function C(e,r=0){if(e==null)return r;let t=Number.parseInt(e,10);return Number.isNaN(t)?r:t}function se(e,r={}){if(!e)return r;try{return JSON.parse(e)}catch(t){return console.warn("SearchWidget: Invalid JSON attribute",t),r}}function ae(e){return e?e.split(",").map(r=>r.trim()).filter(Boolean):[]}function V(e,r="modal"){let t=Xe(r),n=e.getAttribute("indices")||"",o=ae(n),a={indices:o,index:o[0]||"",placeholder:e.getAttribute("placeholder")||t.placeholder,theme:e.getAttribute("theme")||t.theme,siteId:e.getAttribute("site-id")||t.siteId,source:e.getAttribute("source")||t.source,highlightTag:e.getAttribute("highlight-tag")||t.highlightTag,highlightClass:e.getAttribute("highlight-class")||t.highlightClass,searchEndpoint:t.searchEndpoint,trackClickEndpoint:t.trackClickEndpoint,trackSearchEndpoint:t.trackSearchEndpoint,maxResults:C(e.getAttribute("max-results"),t.maxResults),debounce:C(e.getAttribute("debounce"),t.debounce),minChars:C(e.getAttribute("min-chars"),t.minChars),maxRecentSearches:C(e.getAttribute("max-recent-searches"),t.maxRecentSearches),idleTimeout:C(e.getAttribute("idle-timeout"),t.idleTimeout),showRecent:f(e.getAttribute("show-recent"),t.showRecent),groupResults:f(e.getAttribute("group-results"),t.groupResults),enableHighlighting:f(e.getAttribute("enable-highlighting"),t.enableHighlighting),showLoadingIndicator:f(e.getAttribute("show-loading-indicator"),t.showLoadingIndicator),hideResultsWithoutUrl:f(e.getAttribute("hide-results-without-url"),t.hideResultsWithoutUrl),showCodeSnippets:f(e.getAttribute("show-code-snippets"),t.showCodeSnippets),debug:f(e.getAttribute("debug"),t.debug),snippetMode:e.getAttribute("snippet-mode")||t.snippetMode,snippetLength:C(e.getAttribute("snippet-length"),t.snippetLength),parseMarkdownSnippets:f(e.getAttribute("parse-markdown-snippets"),t.parseMarkdownSnippets),persistQueryInUrl:f(e.getAttribute("persist-query-in-url"),t.persistQueryInUrl),highlightDestinationPage:f(e.getAttribute("highlight-destination-page"),t.highlightDestinationPage),resultTitleLines:C(e.getAttribute("result-title-lines"),t.resultTitleLines),resultDescLines:C(e.getAttribute("result-desc-lines"),t.resultDescLines),queryParamName:e.getAttribute("query-param-name")||t.queryParamName,destinationHighlightSelector:e.getAttribute("destination-highlight-selector")||t.destinationHighlightSelector,resultLayout:e.getAttribute("result-layout")||t.resultLayout,hierarchyGroupBy:e.getAttribute("hierarchy-group-by")||t.hierarchyGroupBy,hierarchyStyle:e.getAttribute("hierarchy-style")||t.hierarchyStyle,hierarchyDisplay:e.getAttribute("hierarchy-display")||t.hierarchyDisplay,maxHeadingsPerResult:C(e.getAttribute("max-headings-per-result"),t.maxHeadingsPerResult),styles:se(e.getAttribute("styles"),t.styles),promotions:se(e.getAttribute("promotions"),t.promotions)};return r==="modal"&&Object.assign(a,{hotkey:e.getAttribute("hotkey")||t.hotkey,triggerSelector:e.getAttribute("trigger-selector")||t.triggerSelector,backdropOpacity:C(e.getAttribute("backdrop-opacity"),t.backdropOpacity),showTrigger:f(e.getAttribute("show-trigger"),t.showTrigger),enableBackdropBlur:f(e.getAttribute("enable-backdrop-blur"),t.enableBackdropBlur),preventBodyScroll:f(e.getAttribute("prevent-body-scroll"),t.preventBodyScroll)}),r==="page"&&Object.assign(a,{resultsPerPage:C(e.getAttribute("results-per-page"),t.resultsPerPage),paginationType:e.getAttribute("pagination-type")||t.paginationType,showFilters:f(e.getAttribute("show-filters"),t.showFilters),updateUrl:f(e.getAttribute("update-url"),t.updateUrl),sortOptions:ae(e.getAttribute("sort-options"))||t.sortOptions}),r==="inline"&&Object.assign(a,{dropdownPosition:e.getAttribute("dropdown-position")||t.dropdownPosition,dropdownMaxHeight:C(e.getAttribute("dropdown-max-height"),t.dropdownMaxHeight),showOnFocus:f(e.getAttribute("show-on-focus"),t.showOnFocus)}),a}function ie(e="modal"){let r=["indices","placeholder","theme","max-results","debounce","min-chars","show-recent","max-recent-searches","group-results","site-id","idle-timeout","source","enable-highlighting","highlight-tag","highlight-class","hide-results-without-url","show-code-snippets","snippet-mode","show-loading-indicator","debug","styles","promotions","result-layout","hierarchy-group-by","hierarchy-style","hierarchy-display","max-headings-per-result","result-title-lines","result-desc-lines","snippet-length","parse-markdown-snippets","persist-query-in-url","query-param-name","highlight-destination-page","destination-highlight-selector"],a={modal:["hotkey","show-trigger","trigger-selector","backdrop-opacity","enable-backdrop-blur","prevent-body-scroll"],page:["show-filters","pagination-type","results-per-page","update-url","sort-options"],inline:["dropdown-position","dropdown-max-height","show-on-focus"]};return[...r,...a[e]||[]]}var z={isOpen:!1,query:"",results:[],recentSearches:[],selectedIndex:-1,loading:!1,error:null,meta:null};function le(e={},r=null){let t={...z,...e};return{get(n){return t[n]},getAll(){return{...t}},set(n){let o=[];return Object.keys(n).forEach(a=>{let s=t[a],l=n[a];j(s,l)||o.push(a)}),o.length>0&&(t={...t,...n},r&&r(t,o)),o},reset(n=e){let o={...z,...n},a=Object.keys(o).filter(s=>!j(t[s],o[s]));a.length>0&&(t=o,r&&r(t,a))},is(n,o){return t[n]===o},toggle(n){let o=!t[n];return this.set({[n]:o}),o}}}function j(e,r){if(e===r)return!0;if(e==null||r==null)return!1;if(Array.isArray(e)&&Array.isArray(r))return e.length!==r.length?!1:e.every((t,n)=>j(t,r[n]));if(typeof e=="object"&&typeof r=="object"){let t=Object.keys(e),n=Object.keys(r);return t.length!==n.length?!1:t.every(o=>j(e[o],r[o]))}return!1}async function de({query:e,endpoint:r,indices:t=[],siteId:n="",maxResults:o=10,hideResultsWithoutUrl:a=!1,showCodeSnippets:s=!1,snippetMode:l="balanced",snippetLength:i=150,parseMarkdownSnippets:d=!1,debug:c=!1,signal:u}){let g=new URLSearchParams({q:e,hitsPerPage:o.toString()});g.append("enrich","1"),t.length>0&&g.append("indices",t.join(",")),n&&g.append("siteId",n),a&&g.append("hideResultsWithoutUrl","1"),s&&g.append("showCodeSnippets","1"),l&&l!=="balanced"&&g.append("snippetMode",l),i&&i!==150&&g.append("snippetLength",String(i)),d&&g.append("parseMarkdownSnippets","1"),c&&g.append("debug","1"),g.append("skipAnalytics","1");let m=r.includes("?")?"&":"?",v=await fetch(`${r}${m}${g}`,{signal:u,headers:{Accept:"application/json"}});if(!v.ok)throw new Error("Search failed");let p=await v.json();return p.error&&console.warn("Search warning:",p.error),{results:p.results||p.hits||[],total:p.total||0,meta:p.meta||null,error:p.error||null}}function ce({endpoint:e,elementId:r,query:t,index:n}){if(!(!r||!e))try{let o=new FormData;o.append("elementId",r),o.append("query",t),o.append("index",n),fetch(e,{method:"POST",body:o,headers:{Accept:"application/json"}}).catch(()=>{})}catch{}}function he({endpoint:e,query:r,indices:t=[],resultsCount:n=0,trigger:o="unknown",source:a="",siteId:s=""}){if(!(!r||!e))try{let l=new FormData;l.append("q",r),l.append("indices",t.join(",")),l.append("resultsCount",n.toString()),l.append("trigger",o),l.append("source",a||"frontend-widget"),s&&l.append("siteId",s),fetch(e,{method:"POST",body:l,headers:{Accept:"application/json"}}).catch(()=>{})}catch{}}function ge(e){let r={};return e.forEach(t=>{let n=t.section||t.type||"Results";r[n]||(r[n]=[]),r[n].push(t)}),r}function ue(e,r){let t={};return e.forEach(n=>{let o=n[r]||n.section||n.type||"Results";t[o]||(t[o]=[]),t[o].push(n)}),t}var Je="sm-recent-";function Q(e){return`${Je}${e||"default"}`}function G(e){try{let r=Q(e),t=localStorage.getItem(r);return t?JSON.parse(t):[]}catch{return[]}}function me(e,r,t=null,n=5){if(!r||!r.trim())return G(e);let o=Q(e),a={query:r.trim(),title:t?.title||r,url:t?.url||null,timestamp:Date.now()},s=G(e);s=s.filter(l=>l.query!==a.query),s.unshift(a),s=s.slice(0,n);try{localStorage.setItem(o,JSON.stringify(s))}catch{}return s}function pe(e){try{let r=Q(e);localStorage.removeItem(r)}catch{}}var be={spinnerColor:"#3b82f6",spinnerColorDark:"#60a5fa",modalBg:"#ffffff",modalBgDark:"#1f2937",modalBorderRadius:"12",modalBorderWidth:"1",modalBorderColor:"#e5e7eb",modalBorderColorDark:"#374151",modalShadow:"0 25px 50px -12px rgba(0, 0, 0, 0.25)",modalShadowDark:"0 25px 50px -12px rgba(0, 0, 0, 0.5)",modalMaxWidth:"640",modalMaxHeight:"80",modalPaddingX:"16",modalPaddingY:"16",headerBg:"transparent",headerBgDark:"transparent",headerBorderColor:"#e5e7eb",headerBorderColorDark:"#374151",headerBorderWidth:"1",headerBorderRadius:"0",headerPaddingX:"16",headerPaddingY:"12",inputBg:"#ffffff",inputBgDark:"#1f2937",inputTextColor:"#111827",inputTextColorDark:"#f9fafb",inputPlaceholderColor:"#9ca3af",inputPlaceholderColorDark:"#9ca3af",inputBorderColor:"transparent",inputBorderColorDark:"transparent",inputFontSize:"16",inputBorderRadius:"0",inputBorderWidth:"0",inputPaddingX:"0",inputPaddingY:"0",resultBg:"transparent",resultBgDark:"transparent",resultBorderColor:"#e5e7eb",resultBorderColorDark:"#374151",resultActiveBg:"#e5e7eb",resultActiveBgDark:"#4b5563",resultActiveBorderColor:"#e5e7eb",resultActiveBorderColorDark:"#374151",resultActiveTextColor:"#111827",resultActiveTextColorDark:"#f9fafb",resultActiveDescColor:"#4b5563",resultActiveDescColorDark:"#d1d5db",resultActiveMutedColor:"#6b7280",resultActiveMutedColorDark:"#d1d5db",resultTextColor:"#111827",resultTextColorDark:"#f9fafb",resultDescColor:"#4b5563",resultDescColorDark:"#d1d5db",resultMutedColor:"#6b7280",resultMutedColorDark:"#d1d5db",resultGap:"8",resultBorderWidth:"0",resultPaddingX:"12",resultPaddingY:"12",resultBorderRadius:"8",triggerBg:"#ffffff",triggerBgDark:"#374151",triggerTextColor:"#374151",triggerTextColorDark:"#d1d5db",triggerBorderRadius:"8",triggerBorderWidth:"1",triggerBorderColor:"#d1d5db",triggerBorderColorDark:"#4b5563",triggerHoverBg:"#f9fafb",triggerHoverBgDark:"#4b5563",triggerHoverTextColor:"#111827",triggerHoverTextColorDark:"#f9fafb",triggerHoverBorderColor:"#3b82f6",triggerHoverBorderColorDark:"#60a5fa",triggerPaddingX:"12",triggerPaddingY:"8",triggerFontSize:"14",kbdBg:"#f3f4f6",kbdBgDark:"#4b5563",kbdTextColor:"#4b5563",kbdTextColorDark:"#e5e7eb",kbdBorderRadius:"4",backdropOpacity:"50",backdropBlur:"1",highlightEnabled:"1",highlightTag:"",highlightClass:"",highlightBgLight:"fef08a",highlightColorLight:"854d0e",highlightBgDark:"854d0e",highlightColorDark:"fef08a",iconColor:"#3b82f6",iconColorDark:"#60a5fa",promotedBg:"#2563eb",promotedBgDark:"#3b82f6",promotedColor:"#ffffff",promotedColorDark:"#ffffff"};var fe={modalBg:"--sm-modal-bg",modalBgDark:"--sm-modal-bg-dark",modalBorderRadius:"--sm-modal-radius",modalBorderWidth:"--sm-modal-border-width",modalBorderColor:"--sm-modal-border-color",modalBorderColorDark:"--sm-modal-border-color-dark",modalShadow:"--sm-modal-shadow",modalShadowDark:"--sm-modal-shadow-dark",modalMaxWidth:"--sm-modal-width",modalMaxHeight:"--sm-modal-max-height",modalPaddingX:"--sm-modal-px",modalPaddingY:"--sm-modal-py",headerBg:"--sm-header-bg",headerBgDark:"--sm-header-bg-dark",headerBorderColor:"--sm-header-border-color",headerBorderColorDark:"--sm-header-border-color-dark",headerBorderWidth:"--sm-header-border-width",headerBorderRadius:"--sm-header-radius",headerPaddingX:"--sm-header-px",headerPaddingY:"--sm-header-py",inputBg:"--sm-input-bg",inputBgDark:"--sm-input-bg-dark",inputTextColor:"--sm-input-color",inputTextColorDark:"--sm-input-color-dark",inputPlaceholderColor:"--sm-input-placeholder",inputPlaceholderColorDark:"--sm-input-placeholder-dark",inputBorderColor:"--sm-input-border-color",inputBorderColorDark:"--sm-input-border-color-dark",inputFontSize:"--sm-input-font-size",inputBorderRadius:"--sm-input-radius",inputBorderWidth:"--sm-input-border-width",inputPaddingX:"--sm-input-px",inputPaddingY:"--sm-input-py",resultBg:"--sm-result-bg",resultBgDark:"--sm-result-bg-dark",resultBorderColor:"--sm-result-border-color",resultBorderColorDark:"--sm-result-border-color-dark",resultActiveBg:"--sm-result-active-bg",resultActiveBgDark:"--sm-result-active-bg-dark",resultActiveBorderColor:"--sm-result-active-border-color",resultActiveBorderColorDark:"--sm-result-active-border-color-dark",resultActiveTextColor:"--sm-result-active-text-color",resultActiveTextColorDark:"--sm-result-active-text-color-dark",resultActiveDescColor:"--sm-result-active-desc-color",resultActiveDescColorDark:"--sm-result-active-desc-color-dark",resultActiveMutedColor:"--sm-result-active-muted-color",resultActiveMutedColorDark:"--sm-result-active-muted-color-dark",resultTextColor:"--sm-result-text-color",resultTextColorDark:"--sm-result-text-color-dark",resultDescColor:"--sm-result-desc-color",resultDescColorDark:"--sm-result-desc-color-dark",resultMutedColor:"--sm-result-muted-color",resultMutedColorDark:"--sm-result-muted-color-dark",resultGap:"--sm-result-gap",resultBorderWidth:"--sm-result-border-width",resultPaddingX:"--sm-result-px",resultPaddingY:"--sm-result-py",resultBorderRadius:"--sm-result-radius",triggerBg:"--sm-trigger-bg",triggerBgDark:"--sm-trigger-bg-dark",triggerTextColor:"--sm-trigger-text-color",triggerTextColorDark:"--sm-trigger-text-color-dark",triggerBorderRadius:"--sm-trigger-radius",triggerBorderWidth:"--sm-trigger-border-width",triggerBorderColor:"--sm-trigger-border-color",triggerBorderColorDark:"--sm-trigger-border-color-dark",triggerHoverBg:"--sm-trigger-hover-bg",triggerHoverBgDark:"--sm-trigger-hover-bg-dark",triggerHoverTextColor:"--sm-trigger-hover-text-color",triggerHoverTextColorDark:"--sm-trigger-hover-text-color-dark",triggerHoverBorderColor:"--sm-trigger-hover-border-color",triggerHoverBorderColorDark:"--sm-trigger-hover-border-color-dark",triggerPaddingX:"--sm-trigger-px",triggerPaddingY:"--sm-trigger-py",triggerFontSize:"--sm-trigger-font-size",kbdBg:"--sm-kbd-bg",kbdBgDark:"--sm-kbd-bg-dark",kbdTextColor:"--sm-kbd-text-color",kbdTextColorDark:"--sm-kbd-text-color-dark",kbdBorderRadius:"--sm-kbd-radius",iconColor:"--sm-icon-color",iconColorDark:"--sm-icon-color-dark",highlightBgLight:"--sm-highlight-bg",highlightColorLight:"--sm-highlight-color",highlightBgDark:"--sm-highlight-bg-dark",highlightColorDark:"--sm-highlight-color-dark",promotedBg:"--sm-promoted-bg",promotedBgDark:"--sm-promoted-bg-dark",promotedColor:"--sm-promoted-color",promotedColorDark:"--sm-promoted-color-dark",spinnerColor:"--sm-spinner-color-light",spinnerColorDark:"--sm-spinner-color-dark"},X=["modalBorderRadius","modalBorderWidth","modalMaxWidth","modalPaddingX","modalPaddingY","headerBorderWidth","headerBorderRadius","headerPaddingX","headerPaddingY","inputFontSize","inputBorderRadius","inputBorderWidth","inputPaddingX","inputPaddingY","resultGap","resultBorderWidth","resultPaddingX","resultPaddingY","resultBorderRadius","triggerBorderRadius","triggerBorderWidth","triggerPaddingX","triggerPaddingY","triggerFontSize","kbdBorderRadius"],J=["modalMaxHeight"],ve=["modalBg","modalBgDark","modalBorderColor","modalBorderColorDark","headerBg","headerBgDark","headerBorderColor","headerBorderColorDark","inputBg","inputBgDark","inputTextColor","inputTextColorDark","inputPlaceholderColor","inputPlaceholderColorDark","inputBorderColor","inputBorderColorDark","resultBg","resultBgDark","resultBorderColor","resultBorderColorDark","resultActiveBg","resultActiveBgDark","resultActiveBorderColor","resultActiveBorderColorDark","resultTextColor","resultTextColorDark","resultActiveTextColor","resultActiveTextColorDark","resultActiveDescColor","resultActiveDescColorDark","resultActiveMutedColor","resultActiveMutedColorDark","resultDescColor","resultDescColorDark","resultMutedColor","resultMutedColorDark","triggerBg","triggerBgDark","triggerTextColor","triggerTextColorDark","triggerBorderColor","triggerBorderColorDark","triggerHoverBg","triggerHoverBgDark","triggerHoverTextColor","triggerHoverTextColorDark","triggerHoverBorderColor","triggerHoverBorderColorDark","kbdBg","kbdBgDark","kbdTextColor","kbdTextColorDark","iconColor","iconColorDark","highlightBgLight","highlightColorLight","highlightBgDark","highlightColorDark","promotedBg","promotedBgDark","promotedColor","promotedColorDark","spinnerColor","spinnerColorDark"],At={...be,highlightBgLight:"#fef08a",highlightColorLight:"#854d0e",highlightBgDark:"#854d0e",highlightColorDark:"#fef08a"};function et(e){return typeof e=="string"&&/^(var|light-dark|calc|env|clamp|min|max|rgb|hsl)\s*\(/.test(e.trim())}function tt(e){return/^[0-9a-fA-F]{6}$/.test(e)}function rt(e,r){if(r==null||r==="")return null;let t=String(r);return et(t)||(ve.includes(e)&&tt(t)&&(t="#"+t),X.includes(e)&&(t=t+"px"),J.includes(e)&&(t=t+"vh")),t}function ye(e,r,t="light"){if(!r||typeof r!="object")return;let n=t==="dark",o=Object.entries(fe),a=new Set([...X,...J]);for(let[s,l]of o){let i=s.endsWith("Dark");if(n){if(!i&&!a.has(s))continue}else if(i)continue;if(r[s]!==void 0&&r[s]!==null&&r[s]!==""){let d=rt(s,r[s]);d&&e.style.setProperty(l,d)}}}function h(e){if(!e)return"";let r=document.createElement("div");return r.textContent=e,r.innerHTML}function xe(e){return e?e.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"):""}function Z(e){if(!e)return[];let r=[],t=/"([^"]+)"/g,n;for(;(n=t.exec(e))!==null;)n[1].trim()&&r.push(n[1].trim());let o=e.replace(/"[^"]*"/g,""),a=new Set(["and","or","not","und","oder","nicht","et","ou","sauf","y","o","no"]);o.split(/\s+/).filter(l=>l.length>0).forEach(l=>{l=l.replace(/^[a-zA-Z]+:/,""),l=l.replace(/\*/g,""),l=l.replace(/\^\d+(\.\d+)?/,""),l=l.replace(/"/g,""),!(!l||a.has(l.toLowerCase()))&&r.push(l)});let s=[];return r.forEach(l=>{s.push(l);let i=l.split(/(?<=[a-z])(?=[A-Z])/);i.length>1&&i.forEach(d=>{d.length>=3&&s.push(d)})}),s}function $(e,r,t={}){let{enabled:n=!0,tag:o="mark",className:a="",terms:s=null}=t;if(!n)return h(e);let l=["sm-highlight"];a&&l.push(a);let i=` class="${l.join(" ")}"`,d=nt(r,s);return d.length===0?h(e):ot(e,d,o,i)}function nt(e,r){return Array.isArray(r)&&r.length>0?ke(r):e?ke(Z(e)):[]}function ke(e){let r=new Set;return e.filter(t=>typeof t=="string"&&t.length>0).sort((t,n)=>n.length-t.length).filter(t=>{let n=t.toLowerCase();return r.has(n)?!1:(r.add(n),!0)})}function ot(e,r,t,n){let o=e.toLowerCase(),a=[];if(r.forEach(c=>{let u=c.toLowerCase();if(!u)return;let g=0;for(;g<o.length;){let m=o.indexOf(u,g);if(m===-1)break;a.push({start:m,end:m+u.length}),g=m+u.length}}),a.length===0)return h(e);a.sort((c,u)=>c.start!==u.start?c.start-u.start:u.end-u.start-(c.end-c.start));let s=[],l=-1;a.forEach(c=>{c.start>=l&&(s.push(c),l=c.end)});let i="",d=0;return s.forEach(c=>{d<c.start&&(i+=h(e.slice(d,c.start))),i+=`<${t}${n}>${h(e.slice(c.start,c.end))}</${t}>`,d=c.end}),d<e.length&&(i+=h(e.slice(d))),i}function N(e,r,t="smq"){if(!e||e==="#")return e;let n=(r||"").trim();if(!n||!t||/^(mailto:|tel:|javascript:)/i.test(e))return e;let[o,a]=e.split("#",2),[s,l]=o.split("?",2),i=new URLSearchParams(l||"");i.set(t,n);let d=i.toString(),c=a?`#${a}`:"";return`${s}${d?`?${d}`:""}${c}`}var st=0;function ee(e="sm"){return`${e}-${++st}-${Date.now().toString(36)}`}function we(e){let r=document.createElement("div");return r.setAttribute("role","status"),r.setAttribute("aria-live","polite"),r.setAttribute("aria-atomic","true"),r.className="sm-sr-only",e.appendChild(r),r}function U(e,r,t=100){e&&(e.textContent="",setTimeout(()=>{e.textContent=r},t))}function te(e,r){return e===0?`No results found for "${r}"`:e===1?`1 result found for "${r}"`:`${e} results found for "${r}"`}function Ce(){return"Searching..."}function Se(e){return e===0?"No recent searches":e===1?"1 recent search available":`${e} recent searches available`}function W(e,{expanded:r,activeDescendant:t,listboxId:n}){e.setAttribute("aria-expanded",String(r)),e.setAttribute("aria-controls",n),t?e.setAttribute("aria-activedescendant",t):e.removeAttribute("aria-activedescendant")}function L(e,r){return`${e}-option-${r}`}function Te(e,r){if(!e||!r)return;let t=e.getBoundingClientRect(),n=r.getBoundingClientRect();t.top<n.top?e.scrollIntoView({block:"nearest",behavior:"smooth"}):t.bottom>n.bottom&&e.scrollIntoView({block:"nearest",behavior:"smooth"})}function at(e,r,t={}){let{groupResults:n=!1,resultLayout:o="default",listboxId:a}=t;if(!e||e.length===0)return"";if(o==="hierarchical")return ct(e,r,t);if(n){let s=ge(e),l=0;return Object.entries(s).map(([i,d])=>`
            <div class="sm-section" role="group" aria-label="${h(i)}">
                <div class="sm-section-header">${h(i)}</div>
                ${d.map(c=>Ae(c,l++,r,t)).join("")}
            </div>
        `).join("")}return e.map((s,l)=>Ae(s,l,r,t)).join("")}function Ae(e,r,t,n={}){let{listboxId:o,enableHighlighting:a=!0,highlightTag:s="mark",highlightClass:l="",groupResults:i=!1,promotions:d={},debug:c=!1,persistQueryInUrl:u=!1,queryParamName:g="smq"}=n,m=e.title||e.name||"Untitled",v=e.description||e.excerpt||e.snippet||"",p=e.url||e.href||"#",y=N(p,t,u?g:""),A=e.section||e.type||"",b=L(o,r),k=e.promoted===!0,E={enabled:a,tag:s,className:l},x=$(m,t,{...E,terms:F(e,"title")}),T=v?$(v,t,{...E,terms:F(e,"description")}):"",H=it(e,d),I=k?" sm-promoted":"",B=A&&!i?`<span class="sm-result-type">${h(A)}</span>`:"",w=c?Re(e):"";return c?`
            <a class="sm-result-item sm-debug-enabled${I}" id="${b}" role="option" aria-selected="false" href="${h(y)}" data-index="${r}" data-id="${e.id||""}" data-title="${h(m)}">
                <div class="sm-result-main">
                    ${H}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${x}</span>
                        ${T?`<span class="sm-result-desc">${T}</span>`:""}
                    </div>
                    ${B}
                    ${P()}
                </div>
                ${w}
            </a>
        `:`
        <a class="sm-result-item${I}" id="${b}" role="option" aria-selected="false" href="${h(y)}" data-index="${r}" data-id="${e.id||""}" data-title="${h(m)}">
            ${H}
            <div class="sm-result-content">
                <span class="sm-result-title">${x}</span>
                ${T?`<span class="sm-result-desc">${T}</span>`:""}
            </div>
            ${B}
            ${P()}
        </a>
    `}function Re(e){let r=[],t=e.backend?e.backend.toLowerCase():"";if((e._index||e.index)&&r.push(S("index",e._index||e.index,"index")),e.backend&&r.push(S("backend",t,"backend",t)),e.id&&r.push(S("id",e.id,"generic")),e.score!==void 0&&e.score!==null){let n=typeof e.score=="number"?e.score.toFixed(2):e.score;r.push(S("score",n,"score"))}if(e.site&&r.push(S("site",e.site,"generic")),e.language&&r.push(S("lang",e.language,"generic")),e.matchedIn&&Array.isArray(e.matchedIn)&&e.matchedIn.length>0){let n=e.matchedIn.join(", ");r.push(S("matched",n,"matched"))}return e.promoted&&r.push(S("promoted","yes","promoted")),e.boosted&&r.push(S("boosted","yes","boosted")),r.length===0?"":`<div class="sm-debug-info">${r.join("")}</div>`}function F(e,r){let t=Array.isArray(e.matchedPhrases)?e.matchedPhrases:[],n=e.matchedTerms,o=[];n&&(r==="title"&&Array.isArray(n.title)&&n.title.length>0?o=n.title:r==="description"&&Array.isArray(n.content)&&n.content.length>0?o=n.content:o=[...Array.isArray(n.title)?n.title:[],...Array.isArray(n.content)?n.content:[]]);let a=[...t,...o];return a.length>0?a:null}function S(e,r,t,n=""){let o=n?` data-backend="${h(n)}"`:"";return`<span class="sm-debug-item"><span class="sm-debug-label">${h(e)}</span><span class="sm-debug-value" data-type="${h(t)}"${o}>${h(String(r))}</span></span>`}function it(e,r={}){let{showBadge:t=!0,badgeText:n="Featured",badgePosition:o="top-right"}=r;return!e.promoted||!t?"":`<span class="sm-promoted-badge ${`sm-promoted-badge--${o}`}">${h(n)}</span>`}function P(){return`<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>`}function lt(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
    </svg>`}function dt(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <line x1="4" y1="7" x2="20" y2="7"/>
        <line x1="4" y1="12" x2="20" y2="12"/>
        <line x1="4" y1="17" x2="14" y2="17"/>
    </svg>`}function De(){return`<svg class="sm-hierarchy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="9" x2="20" y2="9"/>
        <line x1="4" y1="15" x2="20" y2="15"/>
        <line x1="10" y1="3" x2="8" y2="21"/>
        <line x1="16" y1="3" x2="14" y2="21"/>
    </svg>`}function ct(e,r,t={}){let{hierarchyGroupBy:n="section",hierarchyStyle:o="tree",hierarchyDisplay:a="individual",maxHeadingsPerResult:s=3,listboxId:l}=t,i=o==="tree",d=o!=="none",u=ue(e,n||"section"),g=0;return Object.entries(u).map(([m,v])=>{let p=v.map(y=>{let A=g++,b=ht(y,A,r,t),k="",x=(y._matchedHeadings||[]).slice(0,s);if(x.length>0){let I=Math.min(...x.map(w=>w.level||2)),B=x.map(w=>i?(w.level||2)-I:0);k=x.map((w,M)=>{let O=B[M],_=!B.slice(M+1).some(Y=>Y===O),R=[];if(i){let Y=B.slice(M+1);for(let q=0;q<O;q++)Y.some(Ue=>Ue===q)&&R.push(q)}return gt(y,w,g++,r,t,_,O,R)}).join("")}let T=!!k;return`
                <div class="sm-hierarchy-block${T?" sm-hierarchy-block--has-children":""}${a==="unified"?" sm-hierarchy-block--unified":""}">
                    ${T?b.replace("sm-result-item sm-hierarchy-parent","sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children"):b}
                    ${T?`<div class="sm-hierarchy-children${d?"":" sm-hierarchy-children--no-connectors"}">${k}</div>`:""}
                </div>
            `}).join("");return`
            <div class="sm-hierarchy-group" role="group" aria-label="${h(m)}">
                <div class="sm-hierarchy-group-header">${h(m)}</div>
                ${p}
            </div>
        `}).join("")}function ht(e,r,t,n={}){let{listboxId:o,enableHighlighting:a=!0,highlightTag:s="mark",highlightClass:l="",debug:i=!1,persistQueryInUrl:d=!1,queryParamName:c="smq"}=n,u=e.title||e.name||"Untitled",g=e.description||e.excerpt||"",m=e.url||"#",v=N(m,t,d?c:""),p=L(o,r),y={enabled:a,tag:s,className:l},A=$(u,t,{...y,terms:F(e,"title")}),b=g?$(g,t,{...y,terms:F(e,"description")}):"",k=i?Re(e):"",x=e._matchedHeadings&&e._matchedHeadings.length>0?lt():dt();return i?`
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled" id="${p}" role="option" aria-selected="false" href="${h(v)}" data-index="${r}" data-id="${e.id||""}" data-title="${h(u)}">
                <div class="sm-result-main">
                    ${x}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${A}</span>
                        ${b?`<span class="sm-result-desc">${b}</span>`:""}
                    </div>
                    ${P()}
                </div>
                ${k}
            </a>
        `:`
        <a class="sm-result-item sm-hierarchy-parent" id="${p}" role="option" aria-selected="false" href="${h(v)}" data-index="${r}" data-id="${e.id||""}" data-title="${h(u)}">
            ${x}
            <div class="sm-result-content">
                <span class="sm-result-title">${A}</span>
                ${b?`<span class="sm-result-desc">${b}</span>`:""}
            </div>
            ${P()}
        </a>
    `}function gt(e,r,t,n,o={},a=!1,s=0,l=[]){let{listboxId:i,enableHighlighting:d=!0,highlightTag:c="mark",highlightClass:u="",debug:g=!1,persistQueryInUrl:m=!1,queryParamName:v="smq"}=o,y=(r.text||"").replace(/^#+\s*/,""),A=r.description||"",b=r.level||2,k=r.id||(y?ut(y):""),E=e.url||"#",x=k?`${E}#${k}`:E,T=N(x,n,m?v:""),H=L(i,t),I={enabled:d,tag:c,className:u},B=$(y,n,{...I,terms:F(e,"title")}),w=A?$(A,n,{...I,terms:F(e,"description")}):"",M=a?" sm-hierarchy-child-row-last":"",O=l.map(R=>`<div class="sm-hierarchy-guide" style="--sm-guide-depth:${R}" aria-hidden="true"></div>`).join(""),_="";if(g){let R=[];R.push(S("h",b,"generic")),k&&R.push(S("anchor",k,"generic")),e.id&&R.push(S("parent",e.id,"generic")),_=`<div class="sm-debug-info">${R.join("")}</div>`}return g?`
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${b} sm-hierarchy-depth-${s}${M}" style="--sm-hierarchy-depth:${s}">
                ${O}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${b} sm-debug-enabled" id="${H}" role="option" aria-selected="false" href="${h(T)}" data-index="${t}" data-id="${e.id||""}" data-title="${h(y)}">
                    <div class="sm-result-main">
                        ${De()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${B}</span>
                            ${w?`<span class="sm-result-desc">${w}</span>`:""}
                        </div>
                        ${P()}
                    </div>
                    ${_}
                </a>
            </div>
        `:`
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${b} sm-hierarchy-depth-${s}${M}" style="--sm-hierarchy-depth:${s}">
            ${O}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${b}" id="${H}" role="option" aria-selected="false" href="${h(T)}" data-index="${t}" data-id="${e.id||""}" data-title="${h(y)}">
                ${De()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${B}</span>
                    ${w?`<span class="sm-result-desc">${w}</span>`:""}
                </div>
                ${P()}
            </a>
        </div>
    `}function ut(e){let r=e.normalize("NFKD").toLowerCase();try{return r.replace(/[^\p{L}\p{N}]+/gu,"-").replace(/^-+|-+$/g,"")}catch{return r.replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"")}}function mt(e,r){return!e||e.length===0?"":`
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${r}-recent-label">Recent searches</span>
                <button class="sm-clear-recent" part="clear-recent">Clear</button>
            </div>
            ${e.map((t,n)=>`
                <div class="sm-result-item sm-recent-item" id="${L(r,n)}" role="option" aria-selected="false" data-index="${n}" data-url="${t.url||""}" data-query="${h(t.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${h(t.title||t.query)}</span>
                    ${P()}
                </div>
            `).join("")}
        </div>
    `}function Be(e){return!e||!e.trim()?`
            <div class="sm-empty" part="empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <p>Start typing to search</p>
            </div>
        `:`
        <div class="sm-empty" part="empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <path d="m15 9-6 6M9 9l6 6"/>
            </svg>
            <p>No results for "<strong>${h(e)}</strong>"</p>
        </div>
    `}function pt(){return`
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>Searching...</p>
        </div>
    `}function Ee(e,r){let{query:t,results:n,recentSearches:o,loading:a,showRecent:s}=e,{showLoadingIndicator:l=!0}=r,i=t&&t.trim();return a&&l?{html:pt(),hasResults:!1,showListbox:!1}:i?!n||n.length===0?{html:Be(t),hasResults:!1,showListbox:!1}:{html:at(n,t,r),hasResults:!0,showListbox:!0}:s&&o&&o.length>0?{html:mt(o,r.listboxId),hasResults:!0,showListbox:!0}:{html:Be(""),hasResults:!1,showListbox:!1}}function re(e,r,t=!1){if(!e)return"";let n=[];if(n.push(D("results",r,"generic")),e.took!==void 0){let i=e.took<1?"<1ms":`${Math.round(e.took)}ms`;n.push(D("time",i,"time"))}if(e.cacheEnabled!==void 0&&(e.cacheEnabled?e.cached?n.push(D("cache","hit","cache-hit")):n.push(D("cache","miss","cache-miss")):n.push(D("cache","off","cache-off"))),e.cacheDriver&&n.push(D("storage",e.cacheDriver,"cache-driver",e.cacheDriver)),e.indices&&e.indices.length>0){let i=e.indices.length>2?`${e.indices.length} indices`:e.indices.join(", ");n.push(D("indices",i,"generic"))}if(e.synonymsExpanded){let i=e.expandedQueries?e.expandedQueries.length-1:0;n.push(D("synonyms",`+${i}`,"synonyms"))}let o=e.rulesMatched?.length||0;n.push(D("rules",o,o>0?"rules":"generic"));let a=e.promotionsMatched?.length||0;n.push(D("promoted",a,a>0?"promotions":"generic"));let l=`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${t?'<path d="M6 9l6 6 6-6"/>':'<path d="M18 15l-6-6-6 6"/>'}</svg>`;return t?`<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">Debug</span>${l}</div>`:`<div class="sm-toolbar-content">${n.join("")}</div><button class="sm-toolbar-toggle" aria-label="Collapse debug panel" aria-expanded="true">${l}</button>`}function D(e,r,t,n=""){let o=n?` data-backend="${h(n)}"`:"";return`<span class="sm-toolbar-item"><span class="sm-toolbar-label">${h(e)}</span><span class="sm-toolbar-value" data-type="${h(t)}"${o}>${h(String(r))}</span></span>`}function Ie(e,r){let{onSelect:t,onIndexChange:n,onEscape:o}=e,{listboxId:a}=r;return{handleKeydown(s,l,i){let d=i;switch(s.key){case"ArrowDown":return s.preventDefault(),d=Math.min(i+1,l-1),d!==i&&n&&n(d),d;case"ArrowUp":return s.preventDefault(),d=Math.max(i-1,-1),d!==i&&n&&n(d),d;case"Enter":return s.preventDefault(),i>=0&&t&&t(i),null;case"Escape":return s.preventDefault(),o&&o(),null;default:return null}},getListboxId(){return a}}}function $e(e,r,t={}){let{scrollContainer:n,inputElement:o,listboxId:a,selectedClass:s="sm-selected"}=t,l=r>=0?L(a,r):null;o&&W(o,{expanded:e.length>0,activeDescendant:l,listboxId:a}),e.forEach((i,d)=>{let c=d===r;i.classList.toggle(s,c),i.setAttribute("aria-selected",String(c)),c&&n&&Te(i,n)})}function Le(e,r){e.forEach((t,n)=>{t.addEventListener("mouseenter",()=>{r&&r(n)})})}var Pe="sm-page-highlight-style",He="__smPageHighlightRegistry",ne=class extends HTMLElement{constructor(){super(),this.attachShadow({mode:"open"}),this.config=null,this.state=le({...z},this.handleStateChange.bind(this)),this.abortController=null,this.debounceTimer=null,this.analyticsIdleTimer=null,this.lastTrackedQuery=null,this.listboxId=ee("sm-listbox"),this.inputId=ee("sm-input"),this.liveRegion=null,this.keyboardNavigator=null,this.elements={},this.handleInput=this.handleInput.bind(this),this.handleKeydown=this.handleKeydown.bind(this),this.handleResultClick=this.handleResultClick.bind(this)}get widgetType(){throw new Error("Subclass must implement widgetType getter")}render(){throw new Error("Subclass must implement render()")}getResultsContainer(){throw new Error("Subclass must implement getResultsContainer()")}getInputElement(){throw new Error("Subclass must implement getInputElement()")}getLoadingElement(){return this.elements.loading||null}getDebugToolbarElement(){return this.elements.debugToolbar||null}connectedCallback(){this.config=V(this,this.widgetType),this.state.set({recentSearches:G(this.config.index)}),this.keyboardNavigator=Ie({onSelect:r=>this.selectResultAtIndex(r),onIndexChange:r=>this.state.set({selectedIndex:r}),onEscape:()=>this.handleEscape()},{listboxId:this.listboxId}),this.applyDestinationPageHighlight()}disconnectedCallback(){this.abortController&&(this.abortController.abort(),this.abortController=null),this.debounceTimer&&(clearTimeout(this.debounceTimer),this.debounceTimer=null)}attributeChangedCallback(r,t,n){t!==n&&this.shadowRoot.children.length>0&&(this.config=V(this,this.widgetType),this.render(),this.applyCustomStyles())}handleStateChange(r,t){(t.includes("results")||t.includes("query")||t.includes("recentSearches"))&&this.renderResultsContent(),(t.includes("results")||t.includes("meta"))&&this.updateDebugToolbar(),t.includes("selectedIndex")&&this.updateSelectionVisual(),t.includes("loading")&&this.updateLoadingVisual()}handleInput(r){let t=r.target.value;if(this.state.set({query:t,selectedIndex:-1}),this.debounceTimer&&clearTimeout(this.debounceTimer),this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),!t.trim()){this.state.set({results:[]});return}t.length<this.config.minChars||(this.debounceTimer=setTimeout(()=>{this.executeSearch(t)},this.config.debounce))}async executeSearch(r){this.abortController&&this.abortController.abort(),this.abortController=new AbortController,this.state.set({loading:!0,error:null}),this.liveRegion&&U(this.liveRegion,Ce());try{let{results:t,meta:n}=await de({query:r,endpoint:this.config.searchEndpoint,indices:this.config.indices,siteId:this.config.siteId,maxResults:this.config.maxResults,hideResultsWithoutUrl:this.config.hideResultsWithoutUrl,showCodeSnippets:this.config.showCodeSnippets,snippetMode:this.config.snippetMode,snippetLength:this.config.snippetLength,parseMarkdownSnippets:this.config.parseMarkdownSnippets,debug:this.config.debug,signal:this.abortController.signal});this.state.set({results:t,meta:n,loading:!1,selectedIndex:t.length>0?0:-1}),this.liveRegion&&U(this.liveRegion,te(t.length,r)),this.dispatchWidgetEvent("search",{query:r,results:t,meta:n}),this.startAnalyticsIdleTimer(r,t.length)}catch(t){if(t.name==="AbortError")return;console.error("Search error:",t),this.state.set({results:[],loading:!1,error:t.message}),this.dispatchWidgetEvent("error",{query:r,error:t.message})}}renderResultsContent(){let r=this.getResultsContainer();if(!r)return;let t=this.state.getAll(),{showRecent:n,groupResults:o,enableHighlighting:a,highlightTag:s,highlightClass:l,showLoadingIndicator:i,debug:d}=this.config,{html:c,hasResults:u,showListbox:g}=Ee({query:t.query,results:t.results,recentSearches:t.recentSearches,loading:t.loading,showRecent:n},{listboxId:this.listboxId,groupResults:o,enableHighlighting:a,highlightTag:s,highlightClass:l,showLoadingIndicator:i,debug:d,persistQueryInUrl:this.config.highlightDestinationPage&&this.config.persistQueryInUrl,queryParamName:this.config.queryParamName,promotions:this.config.promotions,resultLayout:this.config.resultLayout,hierarchyGroupBy:this.config.hierarchyGroupBy,hierarchyStyle:this.config.hierarchyStyle,hierarchyDisplay:this.config.hierarchyDisplay,maxHeadingsPerResult:this.config.maxHeadingsPerResult});r.innerHTML=c,g?r.setAttribute("role","listbox"):r.removeAttribute("role");let m=this.getInputElement();m&&W(m,{expanded:u,activeDescendant:null,listboxId:this.listboxId}),this.liveRegion&&!t.loading&&(t.query&&t.results.length===0?U(this.liveRegion,te(0,t.query)):!t.query&&t.recentSearches.length>0&&n&&U(this.liveRegion,Se(t.recentSearches.length))),this.attachResultHandlers();let v=r.querySelector(".sm-clear-recent");v&&v.addEventListener("click",p=>{p.stopPropagation(),pe(this.config.index),this.state.set({recentSearches:[]})}),u&&t.results.length>0&&this.state.set({selectedIndex:0})}attachResultHandlers(){let r=this.getResultsContainer();if(!r)return;let t=r.querySelectorAll(".sm-result-item");t.forEach(n=>{n.addEventListener("click",o=>this.handleResultClick(o,n))}),Le(t,n=>{this.state.set({selectedIndex:n})})}updateSelectionVisual(){let r=this.getResultsContainer(),t=this.getInputElement();if(!r)return;let n=r.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");$e(n,o,{scrollContainer:r,inputElement:t,listboxId:this.listboxId})}handleKeydown(r){let t=this.getResultsContainer();if(!t)return;let n=t.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");if(r.key==="Enter"){let a=this.state.get("query"),s=this.state.get("results")||[];a&&s.length>0&&this.trackSearchAnalytics(a,s.length,"enter")}this.keyboardNavigator.handleKeydown(r,n.length,o)}selectResultAtIndex(r){let t=this.getResultsContainer();if(!t)return;let n=t.querySelectorAll(".sm-result-item");r>=0&&n[r]&&n[r].click()}handleEscape(){}handleResultClick(r,t){let n=t.getAttribute("href"),o=t.dataset.url,a=n||o,s=t.dataset.title||t.querySelector(".sm-result-title")?.textContent,l=t.dataset.id,i=t.dataset.query||this.state.get("query"),d=t.classList.contains("sm-recent-item"),c=N(a,i,this.config.highlightDestinationPage&&this.config.persistQueryInUrl?this.config.queryParamName:"");if(!d&&i){let u=me(this.config.index,i,{title:s,url:a},this.config.maxRecentSearches);this.state.set({recentSearches:u})}if(l&&this.config.index&&ce({endpoint:this.config.trackClickEndpoint,elementId:l,query:i,index:this.config.index}),!d&&i&&this.trackSearchAnalytics(i,this.state.get("results")?.length||0,"click"),this.dispatchWidgetEvent("result-click",{id:l,title:s,url:c,query:i,isRecent:d}),a&&a!=="#")d&&(r.preventDefault(),window.location.href=c),this.onResultSelected(c,s,l);else if(i){r.preventDefault();let u=this.getInputElement();u&&(u.value=i,this.state.set({query:i}),this.executeSearch(i))}}onResultSelected(r,t,n){}applyDestinationPageHighlight(){if(!this.config.highlightDestinationPage||typeof window>"u"||typeof document>"u")return;let r=this.config.queryParamName||"smq",t=this.config.destinationHighlightSelector||"main, article, [data-search-content]",n=new URLSearchParams(window.location.search).get(r);if(!n||!n.trim())return;let o=this.getPageHighlightRegistry(),a=`${r}::${t}`;if(o.has(a))return;o.add(a);let s=()=>{this.ensurePageHighlightStyles(),this.highlightDestinationNodes(n.trim(),t,a)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",s,{once:!0}):window.requestAnimationFrame(s)}ensurePageHighlightStyles(){if(document.getElementById(Pe))return;let r=document.createElement("style");r.id=Pe,r.textContent=`
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `,document.head.appendChild(r)}highlightDestinationNodes(r,t,n){let o=Array.from(document.querySelectorAll(t));if(o.length===0)return;let a=[...new Set(Z(r).map(i=>i.trim()).filter(i=>i.length>=2))];if(a.length===0)return;let s=a.map(i=>xe(i)).filter(Boolean).sort((i,d)=>d.length-i.length).join("|");if(!s)return;let l=new RegExp(`(${s})`,"gi");o.forEach(i=>{i.getAttribute("data-sm-highlighted")!==n&&(this.highlightTextNodesInScope(i,l),i.setAttribute("data-sm-highlighted",n))})}highlightTextNodesInScope(r,t){let n=document.createTreeWalker(r,NodeFilter.SHOW_TEXT,{acceptNode:a=>{let s=a.nodeValue;if(!s||!s.trim())return NodeFilter.FILTER_REJECT;let l=a.parentElement;return!l||l.closest("script, style, noscript, textarea, code, pre, mark, .sm-highlight, .sm-page-highlight, search-modal")?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT}}),o=[];for(;n.nextNode();)o.push(n.currentNode);o.forEach(a=>{let s=a.nodeValue||"";if(t.lastIndex=0,!t.test(s))return;let l=document.createDocumentFragment(),i=0;t.lastIndex=0;let d=s.matchAll(t);for(let c of d){let u=c[0],g=c.index??-1;if(g<0)continue;g>i&&l.appendChild(document.createTextNode(s.slice(i,g)));let m=document.createElement("mark");m.className="sm-highlight sm-page-highlight",m.textContent=u,l.appendChild(m),i=g+u.length}i<s.length&&l.appendChild(document.createTextNode(s.slice(i))),a.parentNode?.replaceChild(l,a)})}getPageHighlightRegistry(){let r=window[He];if(r instanceof Set)return r;let t=new Set;return window[He]=t,t}updateLoadingVisual(){let r=this.getLoadingElement();if(r){let t=this.state.get("loading"),n=this.config?.showLoadingIndicator!==!1;r.hidden=!t||!n}}updateDebugToolbar(){let r=this.getDebugToolbarElement();if(!r)return;let{debug:t}=this.config,n=this.state.getAll();if(!t||!n.meta||n.results.length===0){r.hidden=!0;return}let o=r.classList.contains("sm-collapsed");r.innerHTML=re(n.meta,n.results.length,o),r.hidden=!1,o&&r.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(r)}attachDebugToolbarHandlers(r){let t=r.querySelector(".sm-toolbar-toggle");t&&t.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()});let n=r.querySelector(".sm-toolbar-collapsed-bar");n&&n.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()})}toggleDebugToolbar(){let r=this.getDebugToolbarElement();if(!r)return;let t=r.classList.toggle("sm-collapsed"),n=this.state.getAll();r.innerHTML=re(n.meta,n.results.length,t),t&&r.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(r)}applyCustomStyles(){if(!this.config)return;let r=this.shadowRoot.host,{theme:t,styles:n,resultTitleLines:o,resultDescLines:a}=this.config;ye(r,n,t),o&&r.style.setProperty("--sm-result-title-lines",String(o)),a&&r.style.setProperty("--sm-result-desc-lines",String(a))}initializeLiveRegion(){this.liveRegion=we(this.shadowRoot)}startAnalyticsIdleTimer(r,t){this.analyticsIdleTimer&&clearTimeout(this.analyticsIdleTimer);let n=this.config.idleTimeout;!n||n<=0||(this.analyticsIdleTimer=setTimeout(()=>{this.trackSearchAnalytics(r,t,"idle")},n))}trackSearchAnalytics(r,t,n){!r||r===this.lastTrackedQuery||(this.lastTrackedQuery=r,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),he({endpoint:this.config.trackSearchEndpoint,query:r,indices:this.config.indices,resultsCount:t,trigger:n,source:this.config.source,siteId:this.config.siteId}))}resetAnalyticsTracking(){this.lastTrackedQuery=null,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null)}dispatchWidgetEvent(r,t={}){this.dispatchEvent(new CustomEvent(`search-${r}`,{bubbles:!0,composed:!0,detail:t}))}},Me=ne;var Oe=`/**
 * Search Widget Base Styles
 *
 * Shared styles used by all widget types (modal, page, inline).
 * These styles handle results display, highlighting, loading states,
 * and accessibility utilities.
 *
 * @module styles/base
 * @author Search Manager
 * @since 5.32.0
 */

/* =========================================================================
   HOST & RESET
   ========================================================================= */

:host {
    /* Text colors - semantic naming with config variable mapping */
    /* Color contrast ratios meet WCAG 2.1 AA (4.5:1 for normal text) */
    --sm-text-primary: var(--sm-result-text-color, #111827);
    --sm-text-secondary: var(--sm-result-desc-color, #4b5563);
    --sm-text-muted: var(--sm-result-muted-color, #6b7280);
    --sm-hierarchy-connector-color: var(--sm-result-muted-color, #6b7280);

    /* Header defaults */
    --sm-header-bg: transparent;
    --sm-header-border-color: #e5e7eb;
    --sm-header-px: 16px;
    --sm-header-py: 12px;
    --sm-header-radius: 0px;
    --sm-header-border-width: 1px;

    /* Input defaults */
    --sm-input-bg: #ffffff;
    --sm-input-color: #111827;
    --sm-input-placeholder: #9ca3af;
    --sm-input-font-size: 16px;
    --sm-input-border-color: transparent;
    --sm-input-border-width: 0px;
    --sm-input-radius: 0px;
    --sm-input-px: 0px;
    --sm-input-py: 0px;

    /* Borders and backgrounds - map from config variable names */
    --sm-border-color: var(--sm-header-border-color, #e5e7eb);
    --sm-selected-bg: var(--sm-result-active-bg, #e5e7eb);
    --sm-selected-border: var(--sm-result-active-border-color, #3b82f6);
    --sm-result-radius: 8px;

    /* Computed shadow borders \u2014 box-shadow: inset used everywhere for consistent borders.
       Unified card, default items, and active states all use the same technique. */
    --_border-shadow: inset 0 0 0 var(--sm-result-border-width, 1px) var(--sm-result-border-color, #e5e7eb);
    --_active-shadow: inset 0 0 0 var(--sm-result-border-width, 1px) var(--sm-selected-border);

    /* Promoted badge */
    --sm-promoted-bg: #2563eb;
    --sm-promoted-color: #ffffff;

    /* Highlighting */
    --sm-highlight-bg: #fef08a;
    --sm-highlight-color: #854d0e;

    /* Result line clamping */
    --sm-result-title-lines: 1;
    --sm-result-desc-lines: 1;

    /* Kbd / keyboard shortcuts - 4.5:1 contrast ratio minimum */
    --sm-kbd-bg: #f3f4f6;
    --sm-kbd-border: #d1d5db;
    --sm-kbd-color: var(--sm-kbd-text-color, #4b5563);
    --sm-kbd-radius: 4px;

    /* Icon color (hierarchy icons, arrows) */
    --sm-icon: var(--sm-icon-color, #3b82f6);

    /* Spinner */
    --sm-spinner-color: var(--sm-spinner-color-light, #3b82f6);

    display: inline-block;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

/* Dark theme base variables */
/* Color contrast ratios meet WCAG 2.1 AA (4.5:1 for normal text, 3:1 for large text) */
:host([data-theme="dark"]) {
    --sm-input-bg: var(--sm-input-bg-dark, #1f2937);
    --sm-input-color: var(--sm-input-color-dark, #f9fafb);
    --sm-input-placeholder: var(--sm-input-placeholder-dark, #9ca3af);

    --sm-text-primary: var(--sm-result-text-color-dark, #f9fafb);
    --sm-text-secondary: var(--sm-result-desc-color-dark, #d1d5db);
    --sm-text-muted: var(--sm-result-muted-color-dark, #9ca3af);
    --sm-hierarchy-connector-color: var(--sm-result-muted-color-dark, #9ca3af);

    --sm-header-bg: var(--sm-header-bg-dark, transparent);
    --sm-header-border-color: var(--sm-header-border-color-dark, #374151);

    --sm-input-border-color: var(--sm-input-border-color-dark, transparent);

    --sm-border-color: var(--sm-header-border-color-dark, #374151);
    --sm-result-bg: var(--sm-result-bg-dark, transparent);
    --sm-result-border-color: var(--sm-result-border-color-dark, #374151);
    --sm-selected-bg: var(--sm-result-active-bg-dark, #4b5563);
    --sm-selected-border: var(--sm-result-active-border-color-dark, #3b82f6);

    --sm-promoted-bg: var(--sm-promoted-bg-dark, #3b82f6);
    --sm-promoted-color: var(--sm-promoted-color-dark, #ffffff);

    --sm-highlight-bg: var(--sm-highlight-bg-dark, #854d0e);
    --sm-highlight-color: var(--sm-highlight-color-dark, #fef08a);

    --sm-kbd-bg: var(--sm-kbd-bg-dark, #374151);
    --sm-kbd-border: #4b5563;
    --sm-kbd-color: var(--sm-kbd-text-color-dark, #e5e7eb);

    --sm-icon: var(--sm-icon-color-dark, #60a5fa);

    --sm-spinner-color: var(--sm-spinner-color-dark, #60a5fa);
}

*, *::before, *::after {
    box-sizing: border-box;
}

/* =========================================================================
   INPUT
   ========================================================================= */

.sm-input {
    flex: 1;
    border: var(--sm-input-border-width) solid var(--sm-input-border-color);
    border-radius: var(--sm-input-radius);
    padding: var(--sm-input-py) var(--sm-input-px);
    background: var(--sm-input-bg);
    color: var(--sm-input-color);
    font-size: var(--sm-input-font-size);
    outline: none;
}

.sm-input::placeholder {
    color: var(--sm-input-placeholder);
}

/* =========================================================================
   LOADING STATE
   ========================================================================= */

.sm-loading {
    flex-shrink: 0;
}

.sm-spinner {
    animation: sm-spin 1s linear infinite;
    color: var(--sm-spinner-color);
}

@keyframes sm-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.sm-loading-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 48px 24px;
    color: var(--sm-text-muted);
    text-align: center;
}

.sm-loading-state p {
    margin: 0;
    font-size: 14px;
}

/* =========================================================================
   RESULTS CONTAINER
   ========================================================================= */

.sm-results {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: var(--sm-result-gap, 0px);
}

/* =========================================================================
   SECTION GROUPING
   ========================================================================= */

.sm-section {
    display: flex;
    flex-direction: column;
    gap: var(--sm-result-gap, 0px);
}

.sm-section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0;
    font-size: 12px;
    font-weight: 600;
    color: var(--sm-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.sm-clear-recent {
    padding: 2px 8px;
    background: transparent;
    border: none;
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    color: var(--sm-text-muted);
    cursor: pointer;
    text-transform: none;
    letter-spacing: normal;
}

.sm-clear-recent:hover {
    background: var(--sm-selected-bg);
    color: var(--sm-text-secondary);
}

/* =========================================================================
   RESULT ITEM
   ========================================================================= */

.sm-result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: var(--sm-result-py, 12px) var(--sm-result-px, 12px);
    border-radius: var(--sm-result-radius);
    background: var(--sm-result-bg, transparent);
    border: none;
    box-shadow: var(--_border-shadow);
    color: var(--sm-text-primary);
    text-decoration: none;
    cursor: pointer;
    transition: background 0.1s ease;
}

/* Hover and selected share the same visual \u2014 mouseenter always sets .sm-selected,
   so separate hover colors would never be visible. Use one set of active colors.
   box-shadow color changes from --_border-shadow (gray) to --_active-shadow (blue). */
.sm-result-item:hover,
.sm-result-item.sm-selected {
    background: var(--sm-selected-bg);
    box-shadow: var(--_active-shadow);
}

.sm-result-item:hover .sm-result-title,
.sm-result-item.sm-selected .sm-result-title {
    color: var(--sm-result-active-text-color, var(--sm-text-primary));
}

.sm-result-item:hover .sm-result-desc,
.sm-result-item.sm-selected .sm-result-desc {
    color: var(--sm-result-active-desc-color, var(--sm-text-secondary));
}

.sm-result-item:hover .sm-result-icon,
.sm-result-item:hover .sm-result-arrow,
.sm-result-item.sm-selected .sm-result-icon,
.sm-result-item.sm-selected .sm-result-arrow {
    color: var(--sm-result-active-muted-color, var(--sm-text-muted));
}

.sm-result-icon {
    flex-shrink: 0;
    color: var(--sm-text-muted);
}

.sm-result-content {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.sm-result-title {
    font-size: 14px;
    font-weight: 500;
    color: var(--sm-text-primary);
    display: -webkit-box;
    -webkit-line-clamp: var(--sm-result-title-lines);
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: normal;
}

.sm-result-desc {
    font-size: 13px;
    color: var(--sm-text-secondary);
    display: -webkit-box;
    -webkit-line-clamp: var(--sm-result-desc-lines);
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: normal;
}

.sm-result-type {
    flex-shrink: 0;
    padding: 2px 8px;
    background: var(--sm-kbd-bg);
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    color: var(--sm-text-muted);
}

.sm-result-arrow {
    flex-shrink: 0;
    color: var(--sm-icon);
    opacity: 0;
    transition: opacity 0.1s ease;
}

.sm-result-item:hover .sm-result-arrow,
.sm-result-item.sm-selected .sm-result-arrow {
    opacity: 1;
}

/* =========================================================================
   HIERARCHICAL RESULTS (Algolia DocSearch-style)
   ========================================================================= */

/* Group wrapper */
.sm-hierarchy-group {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--sm-result-gap, 0px);
}

/* Group header */
.sm-hierarchy-group-header {
    padding: 8px 0;
    font-size: 12px;
    font-weight: 600;
    color: var(--sm-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Parent result (page-level) */
.sm-hierarchy-parent {
    position: relative;
    gap: 10px; /* Fixed \u2014 icon-to-text gap, independent of --sm-result-gap */
    padding: var(--sm-result-py, 12px) var(--sm-result-px, 12px);
}

.sm-hierarchy-parent .sm-hierarchy-icon {
    flex-shrink: 0;
    color: var(--sm-icon);
}

/* Child result (heading-level) with gutter connector */
/* --sm-hierarchy-depth: 0-based depth (0=shallowest heading, 1=next, etc.) */
/* --sm-hierarchy-indent: per-level step (default 40px) */
/* Depth 0 connector aligns with parent icon center; deeper levels add indent steps */
.sm-hierarchy-child-row {
    --_indent: calc(var(--sm-result-px, 12px) - 4px + var(--sm-hierarchy-depth, 0) * var(--sm-hierarchy-indent, 40px));
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px; /* Fixed \u2014 icon-to-text gap, independent of --sm-result-gap */
    padding-inline-start: var(--_indent);
}

/* Vertical connector line (continues through non-last children) */
.sm-hierarchy-child-row::before {
    content: '';
    position: absolute;
    top: calc(-1 * var(--sm-result-gap, 0px));
    bottom: calc(-1 * var(--sm-result-gap, 0px));
    inset-inline-start: calc(var(--_indent) + 14px);
    width: 0;
    border-inline-start: 2px solid var(--sm-hierarchy-connector-color);
    pointer-events: none;
}

/* Horizontal branch (reaches from vertical line to content) */
.sm-hierarchy-child-row::after {
    content: '';
    position: absolute;
    top: 50%;
    inset-inline-start: calc(var(--_indent) + 14px);
    width: 20px;
    height: 0;
    border-top: 2px solid var(--sm-hierarchy-connector-color);
    pointer-events: none;
}

/* Last child at its level: vertical stops at center, curves into horizontal */
.sm-hierarchy-child-row-last::before {
    bottom: 50%;
    border-end-start-radius: 6px;
    border-bottom: 2px solid var(--sm-hierarchy-connector-color);
    width: 20px;
}

/* Last child: curve handles the branch, hide separate horizontal */
.sm-hierarchy-child-row-last::after {
    display: none;
}

.sm-hierarchy-child {
    flex: 1;
    font-weight: 400;
    margin-inline-start: 34px;
}

.sm-hierarchy-block {
    position: relative;
}

.sm-hierarchy-children {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--sm-result-gap, 0px);
}

.sm-hierarchy-parent--has-children {
    margin-bottom: var(--sm-result-gap, 0px);
}

/* Ancestor depth guide lines - vertical continuation through deeper children */
.sm-hierarchy-guide {
    position: absolute;
    top: calc(-1 * var(--sm-result-gap, 0px));
    bottom: calc(-1 * var(--sm-result-gap, 0px));
    inset-inline-start: calc(var(--sm-result-px, 12px) - 4px + var(--sm-guide-depth, 0) * var(--sm-hierarchy-indent, 40px) + 14px);
    width: 0;
    border-inline-start: 2px solid var(--sm-hierarchy-connector-color);
    pointer-events: none;
}

/* No-connectors mode: hide all connector lines and guides */
.sm-hierarchy-children--no-connectors .sm-hierarchy-child-row::before,
.sm-hierarchy-children--no-connectors .sm-hierarchy-child-row::after,
.sm-hierarchy-children--no-connectors .sm-hierarchy-guide {
    display: none;
}

.sm-hierarchy-children--no-connectors .sm-hierarchy-child-row {
    padding-inline-start: 0;
}

.sm-hierarchy-children--no-connectors .sm-hierarchy-child {
    margin-inline-start: 0;
}

.sm-hierarchy-child .sm-hierarchy-icon {
    margin-inline-start: 0;
}

/* =========================================================================
   UNIFIED HIERARCHY DISPLAY (Starlight-style)
   The outer block IS the card. Inner items are borderless rows with
   border-top dividers. All children forced flat (depth 0). Hover covers
   the full child-row (including connector gutter), not the inner item.
   ========================================================================= */

/* The block IS the card. Same box-shadow border technique as .sm-result-item.
   Rows extend to the full card edge; the active row outline overlaps the
   shadow border perfectly with no gap. */
.sm-hierarchy-block--unified {
    border-radius: var(--sm-result-radius);
    background: var(--sm-result-bg, transparent);
    border: none;
    box-shadow: var(--_border-shadow);
    overflow: hidden;
}

/* Strip card styling from all inner result items \u2014 they are rows now */
.sm-hierarchy-block--unified .sm-result-item {
    border-radius: 0;
    background: transparent;
    box-shadow: none;
}

/* Suppress result-item active state in unified card \u2014
   hover/active is handled at parent row / child-row level instead.
   Text color hover rules (.sm-result-item:hover .sm-result-title etc.)
   still fire normally since they target child elements. */
.sm-hierarchy-block--unified .sm-result-item:hover,
.sm-hierarchy-block--unified .sm-result-item.sm-selected {
    background: transparent;
    box-shadow: none;
}

/* No gap between parent and children */
.sm-hierarchy-block--unified .sm-hierarchy-parent--has-children {
    margin-bottom: 0;
}

/* Children container: no gap, no background \u2014 use border-top dividers instead */
.sm-hierarchy-block--unified .sm-hierarchy-children {
    gap: 0;
    background: transparent;
}

/* Force all children flat \u2014 override the computed --_indent directly.
   This beats the inline style="--sm-hierarchy-depth:N" set by JS,
   because --_indent is re-declared here and no longer reads --sm-hierarchy-depth. */
.sm-hierarchy-block--unified .sm-hierarchy-child-row {
    --_indent: calc(var(--sm-result-px, 12px) - 4px);
    cursor: pointer;
    border-top: 1px solid var(--sm-result-border-color, #e5e7eb);
}

/* Hide depth guide lines (not needed in flat unified layout) */
.sm-hierarchy-block--unified .sm-hierarchy-guide {
    display: none;
}

/* Border-radius on first/last rows so outline follows the card's rounding */
.sm-hierarchy-block--unified .sm-hierarchy-parent {
    border-radius: var(--sm-result-radius) var(--sm-result-radius) 0 0;
}
.sm-hierarchy-block--unified:not(.sm-hierarchy-block--has-children) .sm-hierarchy-parent {
    border-radius: var(--sm-result-radius);
}
.sm-hierarchy-block--unified .sm-hierarchy-child-row-last {
    border-radius: 0 0 var(--sm-result-radius) var(--sm-result-radius);
}

/* Full-row active state on child rows (covers connector gutter + content).
   No card border, so outline-offset: 0 sits flush at the card edge. */
.sm-hierarchy-block--unified .sm-hierarchy-child-row:hover,
.sm-hierarchy-block--unified .sm-hierarchy-child-row:has(.sm-selected) {
    background: var(--sm-selected-bg);
    outline: var(--sm-result-border-width, 1px) solid var(--sm-selected-border);
    outline-offset: calc(-1 * var(--sm-result-border-width, 1px));
}

/* Parent row \u2014 border-bottom acts as divider between parent and children */
.sm-hierarchy-block--unified .sm-hierarchy-parent--has-children {
    border-bottom: 1px solid var(--sm-result-border-color, #e5e7eb);
}
.sm-hierarchy-block--unified .sm-hierarchy-parent:hover,
.sm-hierarchy-block--unified .sm-hierarchy-parent.sm-selected {
    background: var(--sm-selected-bg);
    outline: var(--sm-result-border-width, 1px) solid var(--sm-selected-border);
    outline-offset: calc(-1 * var(--sm-result-border-width, 1px));
}

/* Connectors: no gap to bridge, so top/bottom stay at 0 */
.sm-hierarchy-block--unified .sm-hierarchy-child-row::before {
    top: 0;
    bottom: 0;
}
/* Last child: restore bottom: 50% so the curve aligns with the title center.
   Must re-declare here because the general child-row rule above (0-2-0 specificity)
   overrides the base .sm-hierarchy-child-row-last::before (0-1-0 specificity). */
.sm-hierarchy-block--unified .sm-hierarchy-child-row-last::before {
    top: 0;
    bottom: 50%;
}


/* =========================================================================
   PROMOTED RESULTS
   ========================================================================= */

.sm-result-item.sm-promoted {
    position: relative;
}

.sm-promoted-badge {
    position: absolute;
    padding: 2px 6px;
    background: var(--sm-promoted-bg);
    color: var(--sm-promoted-color);
    font-size: 11px;
    font-weight: 700;
    border-radius: var(--sm-kbd-radius);
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.sm-promoted-badge--top-right {
    top: 4px;
    inset-inline-end: 4px;
}

.sm-promoted-badge--top-left {
    top: 4px;
    inset-inline-start: 4px;
}

.sm-promoted-badge--inline {
    position: static;
    margin-inline-start: 8px;
}

/* =========================================================================
   HIGHLIGHTING
   ========================================================================= */

/* Uses .sm-highlight class to work with any tag (mark, span, etc.) */
.sm-highlight {
    background: var(--sm-highlight-bg);
    color: var(--sm-highlight-color);
    border-radius: 2px;
    padding: 0 2px;
}

/* =========================================================================
   EMPTY STATE
   ========================================================================= */

.sm-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 48px 24px;
    color: var(--sm-text-muted);
    text-align: center;
}

.sm-empty p {
    margin: 0;
    font-size: 14px;
}

.sm-empty strong {
    color: var(--sm-text-secondary);
}

/* =========================================================================
   ACCESSIBILITY
   ========================================================================= */

/* Screen reader only - visually hidden but accessible */
.sm-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* =========================================================================
   RTL SUPPORT (BASE)
   Logical properties (padding-inline-start, margin-inline-start,
   inset-inline-start/end) handle most LTR\u2194RTL mirroring automatically.
   Only rules that have no logical equivalent remain here.
   ========================================================================= */

:host([dir="rtl"]) .sm-result-item {
    direction: rtl;
}

:host([dir="rtl"]) .sm-result-arrow {
    transform: scaleX(-1);
}

:host([dir="rtl"]) .sm-hierarchy-child-row {
    flex-direction: row-reverse;
}
`;var Ne=`/**
 * Search Widget Modal Styles
 *
 * Styles specific to the modal widget variant.
 * Includes backdrop, modal container, trigger button,
 * header, footer, and mobile responsive behavior.
 *
 * @module styles/modal
 * @author Search Manager
 * @since 5.32.0
 */

/* =========================================================================
   MODAL-SPECIFIC HOST VARIABLES
   ========================================================================= */

:host {
    /* Modal container */
    --sm-modal-bg: #ffffff;
    --sm-modal-border: var(--sm-modal-border-color, #e5e7eb);
    --sm-modal-border-width: 1px;
    --sm-modal-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    --sm-modal-radius: 12px;
    --sm-modal-width: 640px;
    --sm-modal-max-height: 80vh;
    --sm-modal-px: 16px;
    --sm-modal-py: 16px;

    /* Search header (.sm-header container) */
    --sm-header-bg: transparent;
    --sm-header-border-color: #e5e7eb;
    --sm-header-border-width: 1px;
    --sm-header-radius: 0px;
    --sm-header-px: 16px;
    --sm-header-py: 12px;

    /* Trigger button */
    --sm-trigger-bg: #ffffff;
    --sm-trigger-color: var(--sm-trigger-text-color, #374151);
    --sm-trigger-border: var(--sm-trigger-border-color, #d1d5db);
    --sm-trigger-radius: 8px;
    --sm-trigger-border-width: 1px;
    --sm-trigger-px: 12px;
    --sm-trigger-py: 8px;
    --sm-trigger-font-size: 14px;

    /* Trigger hover */
    --sm-trigger-hover-bg-resolved: var(--sm-trigger-hover-bg, #f9fafb);
    --sm-trigger-hover-color: var(--sm-trigger-hover-text-color, #111827);
    --sm-trigger-hover-border: var(--sm-trigger-hover-border-color, #3b82f6);
}

/* Dark theme - modal-specific overrides */
:host([data-theme="dark"]) {
    --sm-modal-bg: var(--sm-modal-bg-dark, #1f2937);
    --sm-modal-border: var(--sm-modal-border-color-dark, #374151);

    --sm-header-bg: var(--sm-header-bg-dark, transparent);
    --sm-header-border-color: var(--sm-header-border-color-dark, #374151);

    --sm-trigger-bg: var(--sm-trigger-bg-dark, #374151);
    --sm-trigger-color: var(--sm-trigger-text-color-dark, #e5e7eb);
    --sm-trigger-border: var(--sm-trigger-border-color-dark, #4b5563);

    --sm-trigger-hover-bg-resolved: var(--sm-trigger-hover-bg-dark, #4b5563);
    --sm-trigger-hover-color: var(--sm-trigger-hover-text-color-dark, #f9fafb);
    --sm-trigger-hover-border: var(--sm-trigger-hover-border-color-dark, #60a5fa);
}

/* =========================================================================
   TRIGGER BUTTON
   ========================================================================= */

.sm-trigger {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: var(--sm-trigger-py) var(--sm-trigger-px);
    background: var(--sm-trigger-bg);
    border: var(--sm-trigger-border-width) solid var(--sm-trigger-border);
    border-radius: var(--sm-trigger-radius);
    color: var(--sm-trigger-color);
    font-size: var(--sm-trigger-font-size);
    cursor: pointer;
    transition: all 0.15s ease;
}

.sm-trigger:hover {
    background: var(--sm-trigger-hover-bg-resolved);
    color: var(--sm-trigger-hover-color);
    border-color: var(--sm-trigger-hover-border);
}

.sm-trigger-text {
    /* Text shown next to search icon */
}

.sm-trigger-kbd {
    display: inline-flex;
    align-items: center;
    padding: 2px 6px;
    background: var(--sm-kbd-bg);
    border: 1px solid var(--sm-kbd-border);
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    font-family: inherit;
    color: var(--sm-kbd-color);
}

/* =========================================================================
   BACKDROP
   ========================================================================= */

.sm-backdrop {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 10vh;
    background: rgba(0, 0, 0, var(--sm-backdrop-opacity, 0.5));
    backdrop-filter: var(--sm-backdrop-blur, blur(4px));
    animation: sm-fade-in 0.15s ease;
}

.sm-backdrop[hidden] {
    display: none;
}

@keyframes sm-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* =========================================================================
   MODAL CONTAINER
   ========================================================================= */

.sm-modal {
    width: var(--sm-modal-width);
    max-width: calc(100vw - 32px);
    max-height: var(--sm-modal-max-height);
    background: var(--sm-modal-bg);
    border: var(--sm-modal-border-width, 1px) solid var(--sm-modal-border);
    border-radius: var(--sm-modal-radius);
    box-shadow: var(--sm-modal-shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: sm-slide-up 0.2s ease;
    text-align: start;
}

@keyframes sm-slide-up {
    from {
        opacity: 0;
        transform: translateY(-10px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* =========================================================================
   MODAL HEADER
   ========================================================================= */

.sm-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: var(--sm-header-py) var(--sm-header-px);
    background: var(--sm-header-bg);
    border-bottom: var(--sm-header-border-width) solid var(--sm-header-border-color);
    border-radius: var(--sm-header-radius);
}

.sm-search-icon {
    flex-shrink: 0;
    color: var(--sm-text-muted);
}

.sm-close {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    padding: 4px 8px;
    background: transparent;
    border: none;
    cursor: pointer;
}

.sm-close kbd {
    padding: 2px 6px;
    background: var(--sm-kbd-bg);
    border: 1px solid var(--sm-kbd-border);
    border-radius: var(--sm-kbd-radius);
    font-size: 11px;
    font-family: inherit;
    color: var(--sm-kbd-color);
}

/* =========================================================================
   MODAL RESULTS
   ========================================================================= */

.sm-results {
    padding: var(--sm-modal-py) var(--sm-modal-px);
}

/* =========================================================================
   MODAL FOOTER
   ========================================================================= */

.sm-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: var(--sm-modal-py) var(--sm-modal-px);
    border-top: 1px solid var(--sm-border-color);
    font-size: 12px;
    color: var(--sm-text-muted);
}

.sm-footer-hints {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sm-footer-hints span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.sm-footer kbd {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    padding: 2px 4px;
    background: var(--sm-kbd-bg);
    border: 1px solid var(--sm-kbd-border);
    border-radius: var(--sm-kbd-radius);
    font-size: 10px;
    font-family: inherit;
    color: var(--sm-kbd-color);
}

.sm-footer-brand {
    color: var(--sm-text-muted);
}

.sm-footer-brand strong {
    color: var(--sm-text-secondary);
}

/* =========================================================================
   RTL SUPPORT (MODAL-SPECIFIC)
   ========================================================================= */

:host([dir="rtl"]) .sm-header,
:host([dir="rtl"]) .sm-footer {
    direction: rtl;
}

/* =========================================================================
   MOBILE RESPONSIVE
   ========================================================================= */

@media (max-width: 640px) {
    .sm-backdrop {
        padding-top: 0;
        align-items: flex-end;
    }

    .sm-modal {
        max-width: 100%;
        max-height: 90vh;
        border-radius: var(--sm-modal-radius) var(--sm-modal-radius) 0 0;
    }

    .sm-trigger-text,
    .sm-footer-hints {
        display: none;
    }
}
`;var Fe=`/* =========================================================================
   DEBUG MODE - Developer Tools Panel
   Extensible key:value format with labels for clarity
   ========================================================================= */

/* Result item with debug enabled - column layout for full-width debug bar */
.sm-result-item.sm-debug-enabled {
    flex-direction: column;
    padding: 0;
    gap: 0;
}

/* Main content wrapper (icon, content, arrow) - flex row like normal result */
.sm-result-main {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: var(--sm-result-py, 12px) var(--sm-result-px, 12px);
    width: 100%;
}

/* Hierarchy parent debug: match normal parent gap (10px not 12px) */
.sm-hierarchy-parent.sm-debug-enabled .sm-result-main {
    gap: 10px;
}

/* Hierarchy child debug: no padding (child-row handles indent via --_indent) */
.sm-hierarchy-child.sm-debug-enabled .sm-result-main {
    padding: var(--sm-result-py, 12px) var(--sm-result-px, 12px);
}

/* Hierarchy child debug info bar: indent to match content alignment */
.sm-hierarchy-child.sm-debug-enabled .sm-debug-info {
    border-radius: 0 0 var(--sm-result-radius) var(--sm-result-radius);
}

/* Debug info bar - full width at bottom of result */
.sm-debug-info {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px 10px;
    width: 100%;
    padding: 6px 12px;
    background: #f1f5f9;
    border-top: 1px solid #e2e8f0;
    border-radius: 0 0 var(--sm-result-radius) var(--sm-result-radius);
    font-size: 10px;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Monaco, Consolas, monospace;
    line-height: 1.5;
    /* Debug info is always LTR - technical English labels/values */
    direction: ltr;
    text-align: start;
}

:host([data-theme="dark"]) .sm-debug-info {
    background: rgba(15, 23, 42, 0.6);
    border-top-color: #334155;
}

/* Each debug item: label + value pair */
.sm-debug-item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}

/* Labels - dimmed, uppercase */
.sm-debug-label {
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 9px;
    letter-spacing: 0.03em;
}

:host([data-theme="dark"]) .sm-debug-label {
    color: #94a3b8;
}

/* Values - base style */
.sm-debug-value {
    padding: 1px 5px;
    border-radius: 3px;
    font-weight: 500;
}

/* Backend values - color coded */
.sm-debug-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.15);
    color: #92400e;
}
.sm-debug-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.12);
    color: #991b1b;
}
.sm-debug-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.12);
    color: #6d28d9;
}
.sm-debug-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.12);
    color: #0e7490;
}
.sm-debug-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.12);
    color: #9d174d;
}
.sm-debug-value[data-backend="file"] {
    background: rgba(107, 114, 128, 0.12);
    color: #4b5563;
}
.sm-debug-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}
.sm-debug-value[data-backend="elasticsearch"] {
    background: rgba(254, 197, 20, 0.15);
    color: #a16207;
}

/* Index value - outlined style */
.sm-debug-value[data-type="index"] {
    background: transparent;
    border: 1px solid #cbd5e1;
    color: #475569;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="index"] {
    border-color: #475569;
    color: #94a3b8;
}

/* Generic values - subtle */
.sm-debug-value[data-type="generic"] {
    background: rgba(100, 116, 139, 0.1);
    color: #475569;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="generic"] {
    background: rgba(148, 163, 184, 0.15);
    color: #cbd5e1;
}

/* Score value - highlighted */
.sm-debug-value[data-type="score"] {
    background: rgba(34, 197, 94, 0.1);
    color: #166534;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="score"] {
    background: rgba(34, 197, 94, 0.15);
    color: #86efac;
}

/* Matched fields value - purple/blue to highlight important debug info */
.sm-debug-value[data-type="matched"] {
    background: rgba(99, 102, 241, 0.1);
    color: #4338ca;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="matched"] {
    background: rgba(99, 102, 241, 0.15);
    color: #a5b4fc;
}

/* Promoted value - gold/yellow to indicate featured/promoted */
.sm-debug-value[data-type="promoted"] {
    background: rgba(245, 158, 11, 0.15);
    color: #b45309;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="promoted"] {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}

/* Boosted value - green to indicate positive boost */
.sm-debug-value[data-type="boosted"] {
    background: rgba(34, 197, 94, 0.1);
    color: #166534;
}

:host([data-theme="dark"]) .sm-debug-value[data-type="boosted"] {
    background: rgba(34, 197, 94, 0.15);
    color: #86efac;
}

/* Dark mode backend colors */
:host([data-theme="dark"]) .sm-debug-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.2);
    color: #fca5a5;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.2);
    color: #c4b5fd;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.2);
    color: #67e8f9;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.2);
    color: #f9a8d4;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="file"] {
    background: rgba(156, 163, 175, 0.2);
    color: #d1d5db;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}
:host([data-theme="dark"]) .sm-debug-value[data-backend="elasticsearch"] {
    background: rgba(254, 197, 20, 0.2);
    color: #fde047;
}

/* =========================================================================
   DEBUG TOOLBAR - Floating search summary panel
   ========================================================================= */

.sm-debug-toolbar {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    justify-content: center;
    gap: 0;
    padding: 0;
    background: linear-gradient(to bottom, #f8fafc, #f1f5f9);
    border-top: 1px solid #e2e8f0;
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.04);
    font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Monaco, Consolas, monospace;
    direction: ltr;
    text-align: center;
    flex-shrink: 0;
    overflow: hidden;
}

:host([data-theme="dark"]) .sm-debug-toolbar {
    background: linear-gradient(to bottom, #1e293b, #0f172a);
    border-top-color: #334155;
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.2);
}

/* Toggle button (right side when expanded) */
.sm-toolbar-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    padding: 0;
    background: transparent;
    border: none;
    border-inline-start: 1px solid #e2e8f0;
    color: #94a3b8;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    flex-shrink: 0;
}

.sm-toolbar-toggle:hover {
    background: rgba(0, 0, 0, 0.05);
    color: #475569;
}

:host([data-theme="dark"]) .sm-toolbar-toggle {
    border-inline-start-color: #334155;
    color: #64748b;
}

:host([data-theme="dark"]) .sm-toolbar-toggle:hover {
    background: rgba(255, 255, 255, 0.05);
    color: #94a3b8;
}

/* Content wrapper */
.sm-toolbar-content {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    flex: 1;
    overflow-x: auto;
}

/* Collapsed bar - entire bar is clickable */
.sm-toolbar-collapsed-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.15s;
}

.sm-toolbar-collapsed-bar:hover {
    background: rgba(0, 0, 0, 0.03);
}

:host([data-theme="dark"]) .sm-toolbar-collapsed-bar:hover {
    background: rgba(255, 255, 255, 0.03);
}

.sm-toolbar-collapsed-label {
    color: #64748b;
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

:host([data-theme="dark"]) .sm-toolbar-collapsed-label {
    color: #94a3b8;
}

.sm-toolbar-collapsed-bar svg {
    color: #94a3b8;
}

:host([data-theme="dark"]) .sm-toolbar-collapsed-bar svg {
    color: #64748b;
}

/* Toolbar item - stacked vertically (value on top, label below) */
.sm-toolbar-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 8px 14px;
    border-inline-end: 1px solid #e2e8f0;
}

.sm-toolbar-item:last-child {
    border-inline-end: none;
}

:host([data-theme="dark"]) .sm-toolbar-item {
    border-inline-end-color: #334155;
}

/* Label below value - small uppercase */
.sm-toolbar-label {
    order: 2; /* Put below value */
    color: #475569;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 8px;
    letter-spacing: 0.05em;
}

:host([data-theme="dark"]) .sm-toolbar-label {
    color: #94a3b8;
}

.sm-toolbar-value {
    padding: 2px 6px;
    border-radius: 3px;
    font-weight: 600;
}

/* Generic values */
.sm-toolbar-value[data-type="generic"] {
    background: rgba(100, 116, 139, 0.12);
    color: #334155;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="generic"] {
    background: rgba(148, 163, 184, 0.15);
    color: #e2e8f0;
}

/* Time - blue tint */
.sm-toolbar-value[data-type="time"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="time"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}

/* Cache hit - green */
.sm-toolbar-value[data-type="cache-hit"] {
    background: rgba(34, 197, 94, 0.15);
    color: #166534;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-hit"] {
    background: rgba(34, 197, 94, 0.2);
    color: #86efac;
}

/* Cache miss - amber */
.sm-toolbar-value[data-type="cache-miss"] {
    background: rgba(245, 158, 11, 0.15);
    color: #92400e;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-miss"] {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}

/* Cache off - gray */
.sm-toolbar-value[data-type="cache-off"] {
    background: rgba(107, 114, 128, 0.12);
    color: #6b7280;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-off"] {
    background: rgba(107, 114, 128, 0.2);
    color: #9ca3af;
}

/* Cache driver types */
.sm-toolbar-value[data-type="cache-driver"][data-backend="redis"] {
    background: rgba(220, 38, 38, 0.12);
    color: #b91c1c;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="file"] {
    background: rgba(107, 114, 128, 0.12);
    color: #4b5563;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="memcached"] {
    background: rgba(34, 197, 94, 0.12);
    color: #166534;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="database"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}
.sm-toolbar-value[data-type="cache-driver"][data-backend="apcu"] {
    background: rgba(168, 85, 247, 0.12);
    color: #7c3aed;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="redis"] {
    background: rgba(220, 38, 38, 0.2);
    color: #fca5a5;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="file"] {
    background: rgba(156, 163, 175, 0.2);
    color: #d1d5db;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="memcached"] {
    background: rgba(34, 197, 94, 0.2);
    color: #86efac;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="database"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-type="cache-driver"][data-backend="apcu"] {
    background: rgba(168, 85, 247, 0.2);
    color: #c4b5fd;
}

/* Synonyms - purple */
.sm-toolbar-value[data-type="synonyms"] {
    background: rgba(139, 92, 246, 0.12);
    color: #6d28d9;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="synonyms"] {
    background: rgba(139, 92, 246, 0.2);
    color: #c4b5fd;
}

/* Rules - cyan */
.sm-toolbar-value[data-type="rules"] {
    background: rgba(6, 182, 212, 0.12);
    color: #0e7490;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="rules"] {
    background: rgba(6, 182, 212, 0.2);
    color: #67e8f9;
}

/* Promotions - pink */
.sm-toolbar-value[data-type="promotions"] {
    background: rgba(236, 72, 153, 0.12);
    color: #9d174d;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-type="promotions"] {
    background: rgba(236, 72, 153, 0.2);
    color: #f9a8d4;
}

/* Backend values - reuse colors from debug info */
.sm-toolbar-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.15);
    color: #92400e;
}
.sm-toolbar-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.12);
    color: #991b1b;
}
.sm-toolbar-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.12);
    color: #6d28d9;
}
.sm-toolbar-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.12);
    color: #0e7490;
}
.sm-toolbar-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.12);
    color: #9d174d;
}
.sm-toolbar-value[data-backend="file"] {
    background: rgba(107, 114, 128, 0.12);
    color: #4b5563;
}
.sm-toolbar-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.12);
    color: #1d4ed8;
}

:host([data-theme="dark"]) .sm-toolbar-value[data-backend="mysql"] {
    background: rgba(245, 158, 11, 0.2);
    color: #fcd34d;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="redis"] {
    background: rgba(220, 38, 38, 0.2);
    color: #fca5a5;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="typesense"] {
    background: rgba(139, 92, 246, 0.2);
    color: #c4b5fd;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="algolia"] {
    background: rgba(6, 182, 212, 0.2);
    color: #67e8f9;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="meilisearch"] {
    background: rgba(236, 72, 153, 0.2);
    color: #f9a8d4;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="file"] {
    background: rgba(156, 163, 175, 0.2);
    color: #d1d5db;
}
:host([data-theme="dark"]) .sm-toolbar-value[data-backend="pgsql"] {
    background: rgba(59, 130, 246, 0.2);
    color: #93c5fd;
}
`;var bt=Oe+`
`+Ne+`
`+Fe,oe=class extends Me{constructor(){super(),this.externalTrigger=null,this.open=this.open.bind(this),this.close=this.close.bind(this),this.toggle=this.toggle.bind(this),this.handleGlobalKeydown=this.handleGlobalKeydown.bind(this),this.handleBackdropClick=this.handleBackdropClick.bind(this)}get widgetType(){return"modal"}static get observedAttributes(){return ie("modal")}connectedCallback(){super.connectedCallback(),this.render(),this.attachEventListeners()}disconnectedCallback(){super.disconnectedCallback(),this.detachEventListeners()}render(){let{theme:r,placeholder:t,showTrigger:n}=this.config;this.shadowRoot.innerHTML=`
            <style>${bt}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="Open search" ${n?"":'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">Search</span>
                <kbd class="sm-trigger-kbd" aria-hidden="true">${this.getHotkeyDisplay()}</kbd>
            </button>

            <!-- Modal backdrop -->
            <div class="sm-backdrop" part="backdrop" hidden>
                <div class="sm-modal" part="modal" role="dialog" aria-modal="true" aria-label="Search">
                    <!-- Search input -->
                    <div class="sm-header" part="header">
                        <svg class="sm-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input
                            type="text"
                            id="${this.inputId}"
                            class="sm-input"
                            part="input"
                            placeholder="${t}"
                            maxlength="256"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="off"
                            spellcheck="false"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-haspopup="listbox"
                            aria-expanded="false"
                            aria-controls="${this.listboxId}"
                        />
                        <div class="sm-loading" part="loading" hidden>
                            <svg class="sm-spinner" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <button class="sm-close" part="close" aria-label="Close search">
                            <kbd>esc</kbd>
                        </button>
                    </div>

                    <!-- Results -->
                    <div class="sm-results" part="results" id="${this.listboxId}" role="listbox" aria-label="Search results"></div>

                    <!-- Debug toolbar (sticky at bottom) -->
                    <div class="sm-debug-toolbar" part="debug-toolbar" hidden></div>

                    <!-- Footer -->
                    <div class="sm-footer" part="footer">
                        <div class="sm-footer-hints">
                            <span><kbd>\u2191</kbd><kbd>\u2193</kbd> navigate</span>
                            <span><kbd>\u21B5</kbd> select</span>
                            <span><kbd>esc</kbd> close</span>
                        </div>
                        <div class="sm-footer-brand">
                            Powered by <strong>Search Manager</strong>
                        </div>
                    </div>
                </div>
            </div>
        `,this.elements={trigger:this.shadowRoot.querySelector(".sm-trigger"),backdrop:this.shadowRoot.querySelector(".sm-backdrop"),modal:this.shadowRoot.querySelector(".sm-modal"),input:this.shadowRoot.querySelector(".sm-input"),results:this.shadowRoot.querySelector(".sm-results"),loading:this.shadowRoot.querySelector(".sm-loading"),close:this.shadowRoot.querySelector(".sm-close"),debugToolbar:this.shadowRoot.querySelector(".sm-debug-toolbar")},this.initializeLiveRegion(),this.shadowRoot.host.setAttribute("data-theme",r),this.applyCustomStyles()}getResultsContainer(){return this.elements.results}getInputElement(){return this.elements.input}getLoadingElement(){return this.elements.loading}applyCustomStyles(){if(super.applyCustomStyles(),!this.config)return;let{backdropOpacity:r,enableBackdropBlur:t}=this.config,n=this.shadowRoot.host;n.style.setProperty("--sm-backdrop-opacity",r/100),n.style.setProperty("--sm-backdrop-blur",t?"blur(4px)":"none")}attachEventListeners(){this.elements.trigger.addEventListener("click",this.toggle),this.elements.close.addEventListener("click",this.close),this.elements.backdrop.addEventListener("click",this.handleBackdropClick),this.elements.input.addEventListener("input",this.handleInput),this.elements.input.addEventListener("keydown",this.handleKeydown),document.addEventListener("keydown",this.handleGlobalKeydown);let{triggerSelector:r}=this.config;r&&(this.externalTrigger=document.querySelector(r),this.externalTrigger&&this.externalTrigger.addEventListener("click",this.toggle))}detachEventListeners(){document.removeEventListener("keydown",this.handleGlobalKeydown),this.externalTrigger&&(this.externalTrigger.removeEventListener("click",this.toggle),this.externalTrigger=null)}open(){this.state.set({isOpen:!0}),this.elements.backdrop.hidden=!1,this.elements.input.value="",this.state.set({query:"",results:[],selectedIndex:-1}),this.renderResultsContent(),requestAnimationFrame(()=>{this.elements.input.focus()}),this.config.preventBodyScroll&&(document.body.style.overflow="hidden"),this.dispatchWidgetEvent("open",{source:"programmatic"})}close(){this.state.set({isOpen:!1}),this.elements.backdrop.hidden=!0,this.config.preventBodyScroll&&(document.body.style.overflow=""),this.resetAnalyticsTracking(),this.dispatchWidgetEvent("close")}toggle(){this.state.get("isOpen")?this.close():this.open()}handleGlobalKeydown(r){let t=this.config.hotkey.toLowerCase();(navigator.platform.toUpperCase().indexOf("MAC")>=0?r.metaKey:r.ctrlKey)&&r.key.toLowerCase()===t&&(r.preventDefault(),this.toggle()),r.key==="Escape"&&this.state.get("isOpen")&&(r.preventDefault(),this.close())}handleEscape(){this.close()}handleBackdropClick(r){r.target===this.elements.backdrop&&this.close()}onResultSelected(r,t,n){this.close()}getHotkeyDisplay(){let r=navigator.platform.toUpperCase().indexOf("MAC")>=0,t=this.config.hotkey.toUpperCase();return r?`\u2318${t}`:`Ctrl+${t}`}},ft=oe;return We(vt);})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
