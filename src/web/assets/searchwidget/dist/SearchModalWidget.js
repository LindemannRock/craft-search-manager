"use strict";var SearchModalWidget=(()=>{var te=Object.defineProperty;var et=Object.getOwnPropertyDescriptor;var tt=Object.getOwnPropertyNames;var rt=Object.prototype.hasOwnProperty;var nt=(e,t)=>{for(var n in t)te(e,n,{get:t[n],enumerable:!0})},ot=(e,t,n,r)=>{if(t&&typeof t=="object"||typeof t=="function")for(let o of tt(t))!rt.call(e,o)&&o!==n&&te(e,o,{get:()=>t[o],enumerable:!(r=et(t,o))||r.enumerable});return e};var st=e=>ot(te({},"__esModule",{value:!0}),e);var Gt={};nt(Gt,{default:()=>zt});var at={indices:[],placeholder:"Search...",theme:"light",maxResults:20,debounce:200,minChars:2,showRecent:!0,maxRecentSearches:5,groupResults:!0,siteId:"",apiKey:"",searchEndpoint:"/actions/search-manager/api/search",trackClickEndpoint:"/actions/search-manager/search/track-click",trackSearchEndpoint:"/actions/search-manager/search/track-search",idleTimeout:1500,source:"",enableHighlighting:!0,highlightTag:"mark",highlightClass:"",hideResultsWithoutUrl:!1,showCodeSnippets:!1,snippetMode:"balanced",showLoadingIndicator:!0,debug:!1,resultTitleLines:1,resultDescLines:1,snippetLength:150,parseMarkdownSnippets:!1,persistQueryInUrl:!0,queryParamName:"smq",highlightDestinationPage:!0,destinationHighlightSelector:"main, article, [data-search-content]",resultLayout:"default",hierarchyGroupBy:"",hierarchyStyle:"tree",hierarchyDisplay:"individual",maxHeadingsPerResult:3,styles:{},promotions:{showBadge:!0,badgeText:"Featured",badgePosition:"top-right"}},it={hotkey:"k",showTrigger:!0,triggerSelector:"",backdropOpacity:50,enableBackdropBlur:!0,preventBodyScroll:!0},lt={showFilters:!0,paginationType:"numbered",resultsPerPage:20,updateUrl:!0,sortOptions:["relevance","date-desc","date-asc","title"]},dt={dropdownPosition:"below",dropdownMaxHeight:400,showOnFocus:!0};function ct(e){return{...at,...{modal:it,page:lt,inline:dt}[e]||{}}}function y(e,t=!1){if(e==null)return t;if(typeof e=="boolean")return e;if(typeof e=="number")return e!==0;if(e==="")return!0;let n=String(e).trim().toLowerCase();return["1","true","on","yes"].includes(n)?!0:["0","false","off","no"].includes(n)?!1:t}function S(e,t=0){if(e==null)return t;let n=Number.parseInt(e,10);return Number.isNaN(n)?t:n}function re(e,t={}){if(!e)return t;try{return JSON.parse(e)}catch(n){return console.warn("SearchWidget: Invalid JSON attribute",n),t}}function fe(e){return e?e.split(",").map(t=>t.trim()).filter(Boolean):[]}function X(e){return e.indices.length>0?e.indices.join(","):"all"}function ye(e){return e.indices.length===1?e.indices[0]:""}function ne(e,t="modal"){let n=re(e.getAttribute("snippet-defaults"),{}),r={...ct(t),...Object.fromEntries(Object.entries(n).filter(([m])=>["showCodeSnippets","snippetMode","snippetLength","parseMarkdownSnippets","minSnippetLength","maxSnippetLength","snippetModes"].includes(m)))},o=Array.isArray(r.snippetModes)?r.snippetModes:["early","balanced","deep"],s=Number.isFinite(Number(r.minSnippetLength))?Number(r.minSnippetLength):50,a=Number.isFinite(Number(r.maxSnippetLength))?Number(r.maxSnippetLength):1e3,l=Math.min(a,Math.max(s,S(e.getAttribute("snippet-length"),r.snippetLength))),i=e.getAttribute("snippet-mode")||r.snippetMode,c=e.getAttribute("indices")||"",h={indices:fe(c),placeholder:e.getAttribute("placeholder")||r.placeholder,theme:e.getAttribute("theme")||r.theme,siteId:e.getAttribute("site-id")||r.siteId,apiKey:e.getAttribute("api-key")||r.apiKey,source:e.getAttribute("source")||r.source,highlightTag:e.getAttribute("highlight-tag")||r.highlightTag,highlightClass:e.getAttribute("highlight-class")||r.highlightClass,searchEndpoint:r.searchEndpoint,trackClickEndpoint:r.trackClickEndpoint,trackSearchEndpoint:r.trackSearchEndpoint,maxResults:S(e.getAttribute("max-results"),r.maxResults),debounce:S(e.getAttribute("debounce"),r.debounce),minChars:S(e.getAttribute("min-chars"),r.minChars),maxRecentSearches:S(e.getAttribute("max-recent-searches"),r.maxRecentSearches),idleTimeout:S(e.getAttribute("idle-timeout"),r.idleTimeout),showRecent:y(e.getAttribute("show-recent"),r.showRecent),groupResults:y(e.getAttribute("group-results"),r.groupResults),enableHighlighting:y(e.getAttribute("enable-highlighting"),r.enableHighlighting),showLoadingIndicator:y(e.getAttribute("show-loading-indicator"),r.showLoadingIndicator),hideResultsWithoutUrl:y(e.getAttribute("hide-results-without-url"),r.hideResultsWithoutUrl),showCodeSnippets:y(e.getAttribute("show-code-snippets"),r.showCodeSnippets),debug:y(e.getAttribute("debug"),r.debug),snippetMode:o.includes(i)?i:r.snippetMode,snippetLength:l,parseMarkdownSnippets:y(e.getAttribute("parse-markdown-snippets"),r.parseMarkdownSnippets),persistQueryInUrl:y(e.getAttribute("persist-query-in-url"),r.persistQueryInUrl),highlightDestinationPage:y(e.getAttribute("highlight-destination-page"),r.highlightDestinationPage),resultTitleLines:S(e.getAttribute("result-title-lines"),r.resultTitleLines),resultDescLines:S(e.getAttribute("result-desc-lines"),r.resultDescLines),queryParamName:e.getAttribute("query-param-name")||r.queryParamName,destinationHighlightSelector:e.getAttribute("destination-highlight-selector")||r.destinationHighlightSelector,resultLayout:e.getAttribute("result-layout")||r.resultLayout,hierarchyGroupBy:e.getAttribute("hierarchy-group-by")||r.hierarchyGroupBy,hierarchyStyle:e.getAttribute("hierarchy-style")||r.hierarchyStyle,hierarchyDisplay:e.getAttribute("hierarchy-display")||r.hierarchyDisplay,maxHeadingsPerResult:S(e.getAttribute("max-headings-per-result"),r.maxHeadingsPerResult),styles:re(e.getAttribute("styles"),r.styles),promotions:re(e.getAttribute("promotions"),r.promotions)};return t==="modal"&&Object.assign(h,{hotkey:e.getAttribute("hotkey")||r.hotkey,triggerSelector:e.getAttribute("trigger-selector")||r.triggerSelector,backdropOpacity:S(e.getAttribute("backdrop-opacity"),r.backdropOpacity),showTrigger:y(e.getAttribute("show-trigger"),r.showTrigger),enableBackdropBlur:y(e.getAttribute("enable-backdrop-blur"),r.enableBackdropBlur),preventBodyScroll:y(e.getAttribute("prevent-body-scroll"),r.preventBodyScroll)}),t==="page"&&Object.assign(h,{resultsPerPage:S(e.getAttribute("results-per-page"),r.resultsPerPage),paginationType:e.getAttribute("pagination-type")||r.paginationType,showFilters:y(e.getAttribute("show-filters"),r.showFilters),updateUrl:y(e.getAttribute("update-url"),r.updateUrl),sortOptions:fe(e.getAttribute("sort-options"))||r.sortOptions}),t==="inline"&&Object.assign(h,{dropdownPosition:e.getAttribute("dropdown-position")||r.dropdownPosition,dropdownMaxHeight:S(e.getAttribute("dropdown-max-height"),r.dropdownMaxHeight),showOnFocus:y(e.getAttribute("show-on-focus"),r.showOnFocus)}),h}function ke(e="modal"){let t=["indices","placeholder","theme","max-results","debounce","min-chars","show-recent","max-recent-searches","group-results","site-id","idle-timeout","source","enable-highlighting","highlight-tag","highlight-class","hide-results-without-url","show-code-snippets","snippet-mode","show-loading-indicator","debug","styles","promotions","result-layout","hierarchy-group-by","hierarchy-style","hierarchy-display","max-headings-per-result","result-title-lines","result-desc-lines","snippet-length","parse-markdown-snippets","persist-query-in-url","query-param-name","highlight-destination-page","destination-highlight-selector"],s={modal:["hotkey","show-trigger","trigger-selector","backdrop-opacity","enable-backdrop-blur","prevent-body-scroll"],page:["show-filters","pagination-type","results-per-page","update-url","sort-options"],inline:["dropdown-position","dropdown-max-height","show-on-focus"]};return[...t,...s[e]||[]]}var Q={isOpen:!1,query:"",results:[],recentSearches:[],selectedIndex:-1,loading:!1,error:null,meta:null};function ve(e={},t=null){let n={...Q,...e};return{get(r){return n[r]},getAll(){return{...n}},set(r){let o=[];return Object.keys(r).forEach(s=>{let a=n[s],l=r[s];J(a,l)||o.push(s)}),o.length>0&&(n={...n,...r},t&&t(n,o)),o},reset(r=e){let o={...Q,...r},s=Object.keys(o).filter(a=>!J(n[a],o[a]));s.length>0&&(n=o,t&&t(n,s))},is(r,o){return n[r]===o},toggle(r){let o=!n[r];return this.set({[r]:o}),o}}}function J(e,t){if(e===t)return!0;if(e==null||t==null)return!1;if(Array.isArray(e)&&Array.isArray(t))return e.length!==t.length?!1:e.every((n,r)=>J(n,t[r]));if(typeof e=="object"&&typeof t=="object"){let n=Object.keys(e),r=Object.keys(t);return n.length!==r.length?!1:n.every(o=>J(e[o],t[o]))}return!1}async function xe({query:e,endpoint:t,indices:n=[],siteId:r="",maxResults:o=10,hideResultsWithoutUrl:s=!1,showCodeSnippets:a=!1,snippetMode:l="",snippetLength:i=0,parseMarkdownSnippets:c=!1,debug:d=!1,apiKey:h="",signal:m}){let g=new URLSearchParams({q:e,hitsPerPage:o.toString()});n.length>0&&g.append("indices",n.join(",")),r&&g.append("siteId",r),s&&g.append("hideResultsWithoutUrl","1"),a&&g.append("showCodeSnippets","1"),l&&g.append("snippetMode",l),i&&g.append("snippetLength",String(i)),c&&g.append("parseMarkdownSnippets","1"),d&&g.append("debug","1"),g.append("skipAnalytics","1");let b=t.includes("?")?"&":"?",x={Accept:"application/json"};h&&(x["X-Search-Manager-Key"]=h);let f=await fetch(`${t}${b}${g}`,{signal:m,headers:x});if(!f.ok)throw new Error(await ht(f));let p=await f.json();return p.error&&console.warn("Search warning:",p.error),{results:p.results||p.hits||[],total:p.total||0,meta:p.meta||null,error:p.error||null}}async function ht(e){let t=await ut(e);return e.status===401?t||"Search requires an API key.":e.status===403?t||"This API key cannot access this search.":e.status===429?t||"Search rate limit exceeded. Try again in a moment.":t||"Search failed."}async function ut(e){try{if((e.headers.get("content-type")||"").includes("application/json")){let n=await e.json(),r=n.error||n.message||"";return typeof r=="string"?r.slice(0,240):""}}catch{return""}return""}function we({endpoint:e,elementId:t,query:n,index:r,apiKey:o=""}){if(!(!t||!e))try{let s=new FormData;s.append("elementId",t),s.append("query",n),s.append("index",r);let a={Accept:"application/json"};o&&(a["X-Search-Manager-Key"]=o),fetch(e,{method:"POST",body:s,headers:a}).catch(()=>{})}catch{}}function Ce({endpoint:e,query:t,indices:n=[],resultsCount:r=0,trigger:o="unknown",source:s="",siteId:a="",cached:l,took:i,apiKey:c=""}){if(!(!t||!e))try{let d=new FormData;d.append("q",t),d.append("indices",n.join(",")),d.append("resultsCount",r.toString()),d.append("trigger",o),d.append("source",s||"frontend-widget"),a&&d.append("siteId",a),typeof l=="boolean"&&d.append("cached",l?"1":"0"),typeof i=="number"&&Number.isFinite(i)&&i>=0&&d.append("took",i.toString());let h={Accept:"application/json"};c&&(h["X-Search-Manager-Key"]=c),fetch(e,{method:"POST",body:d,headers:h}).catch(()=>{})}catch{}}function Se(e){let t={};return e.forEach(n=>{let r=n.source||n.entrySection||n.type||"Results";t[r]||(t[r]=[]),t[r].push(n)}),t}function Te(e,t){let n={};return e.forEach(r=>{let o=(t?r[t]:null)||r.source||r.entrySection||r.type||"Results";n[o]||(n[o]=[]),n[o].push(r)}),n}var gt="sm-recent-";function oe(e){return`${gt}${e||"default"}`}function Z(e){try{let t=oe(e),n=localStorage.getItem(t);return n?JSON.parse(n):[]}catch{return[]}}function Ae(e,t,n=null,r=5){if(!t||!t.trim())return Z(e);let o=oe(e),s={query:t.trim(),title:n?.title||t,url:n?.url||null,timestamp:Date.now()},a=Z(e);a=a.filter(l=>l.query!==s.query),a.unshift(s),a=a.slice(0,r);try{localStorage.setItem(o,JSON.stringify(a))}catch{}return a}function De(e){try{let t=oe(e);localStorage.removeItem(t)}catch{}}var Ie={spinnerColor:"#3b82f6",spinnerColorDark:"#60a5fa",modalBg:"#ffffff",modalBgDark:"#1f2937",modalBorderRadius:"12",modalBorderWidth:"1",modalBorderColor:"#e5e7eb",modalBorderColorDark:"#374151",modalShadow:"0 25px 50px -12px rgba(0, 0, 0, 0.25)",modalShadowDark:"0 25px 50px -12px rgba(0, 0, 0, 0.5)",modalMaxWidth:"640",modalMaxHeight:"80",modalPaddingX:"16",modalPaddingY:"16",headerBg:"transparent",headerBgDark:"transparent",headerBorderColor:"#e5e7eb",headerBorderColorDark:"#374151",headerBorderWidth:"1",headerBorderRadius:"0",headerPaddingX:"16",headerPaddingY:"12",inputBg:"#ffffff",inputBgDark:"#1f2937",inputTextColor:"#111827",inputTextColorDark:"#f9fafb",inputPlaceholderColor:"#9ca3af",inputPlaceholderColorDark:"#9ca3af",inputBorderColor:"transparent",inputBorderColorDark:"transparent",inputFontSize:"16",inputBorderRadius:"0",inputBorderWidth:"0",inputPaddingX:"0",inputPaddingY:"0",resultBg:"transparent",resultBgDark:"transparent",resultBorderColor:"#e5e7eb",resultBorderColorDark:"#374151",resultActiveBg:"#e5e7eb",resultActiveBgDark:"#4b5563",resultActiveBorderColor:"#e5e7eb",resultActiveBorderColorDark:"#374151",resultActiveTextColor:"#111827",resultActiveTextColorDark:"#f9fafb",resultActiveDescColor:"#4b5563",resultActiveDescColorDark:"#d1d5db",resultActiveMutedColor:"#6b7280",resultActiveMutedColorDark:"#d1d5db",resultTextColor:"#111827",resultTextColorDark:"#f9fafb",resultDescColor:"#4b5563",resultDescColorDark:"#d1d5db",resultMutedColor:"#6b7280",resultMutedColorDark:"#d1d5db",resultGap:"8",resultBorderWidth:"0",resultPaddingX:"12",resultPaddingY:"12",resultBorderRadius:"8",triggerBg:"#ffffff",triggerBgDark:"#374151",triggerTextColor:"#374151",triggerTextColorDark:"#d1d5db",triggerBorderRadius:"8",triggerBorderWidth:"1",triggerBorderColor:"#d1d5db",triggerBorderColorDark:"#4b5563",triggerHoverBg:"#f9fafb",triggerHoverBgDark:"#4b5563",triggerHoverTextColor:"#111827",triggerHoverTextColorDark:"#f9fafb",triggerHoverBorderColor:"#3b82f6",triggerHoverBorderColorDark:"#60a5fa",triggerPaddingX:"12",triggerPaddingY:"8",triggerFontSize:"14",kbdBg:"#f3f4f6",kbdBgDark:"#4b5563",kbdTextColor:"#4b5563",kbdTextColorDark:"#e5e7eb",kbdBorderRadius:"4",backdropOpacity:"50",backdropBlur:"1",highlightEnabled:"1",highlightTag:"",highlightClass:"",highlightBgLight:"fef08a",highlightColorLight:"854d0e",highlightBgDark:"854d0e",highlightColorDark:"fef08a",iconColor:"#3b82f6",iconColorDark:"#60a5fa",promotedBg:"#2563eb",promotedBgDark:"#2563eb",promotedColor:"#ffffff",promotedColorDark:"#ffffff"};var Be={modalBg:"--sm-modal-bg",modalBgDark:"--sm-modal-bg-dark",modalBorderRadius:"--sm-modal-radius",modalBorderWidth:"--sm-modal-border-width",modalBorderColor:"--sm-modal-border-color",modalBorderColorDark:"--sm-modal-border-color-dark",modalShadow:"--sm-modal-shadow",modalShadowDark:"--sm-modal-shadow-dark",modalMaxWidth:"--sm-modal-width",modalMaxHeight:"--sm-modal-max-height",modalPaddingX:"--sm-modal-px",modalPaddingY:"--sm-modal-py",headerBg:"--sm-header-bg",headerBgDark:"--sm-header-bg-dark",headerBorderColor:"--sm-header-border-color",headerBorderColorDark:"--sm-header-border-color-dark",headerBorderWidth:"--sm-header-border-width",headerBorderRadius:"--sm-header-radius",headerPaddingX:"--sm-header-px",headerPaddingY:"--sm-header-py",inputBg:"--sm-input-bg",inputBgDark:"--sm-input-bg-dark",inputTextColor:"--sm-input-color",inputTextColorDark:"--sm-input-color-dark",inputPlaceholderColor:"--sm-input-placeholder",inputPlaceholderColorDark:"--sm-input-placeholder-dark",inputBorderColor:"--sm-input-border-color",inputBorderColorDark:"--sm-input-border-color-dark",inputFontSize:"--sm-input-font-size",inputBorderRadius:"--sm-input-radius",inputBorderWidth:"--sm-input-border-width",inputPaddingX:"--sm-input-px",inputPaddingY:"--sm-input-py",resultBg:"--sm-result-bg",resultBgDark:"--sm-result-bg-dark",resultBorderColor:"--sm-result-border-color",resultBorderColorDark:"--sm-result-border-color-dark",resultActiveBg:"--sm-result-active-bg",resultActiveBgDark:"--sm-result-active-bg-dark",resultActiveBorderColor:"--sm-result-active-border-color",resultActiveBorderColorDark:"--sm-result-active-border-color-dark",resultActiveTextColor:"--sm-result-active-text-color",resultActiveTextColorDark:"--sm-result-active-text-color-dark",resultActiveDescColor:"--sm-result-active-desc-color",resultActiveDescColorDark:"--sm-result-active-desc-color-dark",resultActiveMutedColor:"--sm-result-active-muted-color",resultActiveMutedColorDark:"--sm-result-active-muted-color-dark",resultTextColor:"--sm-result-text-color",resultTextColorDark:"--sm-result-text-color-dark",resultDescColor:"--sm-result-desc-color",resultDescColorDark:"--sm-result-desc-color-dark",resultMutedColor:"--sm-result-muted-color",resultMutedColorDark:"--sm-result-muted-color-dark",resultGap:"--sm-result-gap",resultBorderWidth:"--sm-result-border-width",resultPaddingX:"--sm-result-px",resultPaddingY:"--sm-result-py",resultBorderRadius:"--sm-result-radius",triggerBg:"--sm-trigger-bg",triggerBgDark:"--sm-trigger-bg-dark",triggerTextColor:"--sm-trigger-text-color",triggerTextColorDark:"--sm-trigger-text-color-dark",triggerBorderRadius:"--sm-trigger-radius",triggerBorderWidth:"--sm-trigger-border-width",triggerBorderColor:"--sm-trigger-border-color",triggerBorderColorDark:"--sm-trigger-border-color-dark",triggerHoverBg:"--sm-trigger-hover-bg",triggerHoverBgDark:"--sm-trigger-hover-bg-dark",triggerHoverTextColor:"--sm-trigger-hover-text-color",triggerHoverTextColorDark:"--sm-trigger-hover-text-color-dark",triggerHoverBorderColor:"--sm-trigger-hover-border-color",triggerHoverBorderColorDark:"--sm-trigger-hover-border-color-dark",triggerPaddingX:"--sm-trigger-px",triggerPaddingY:"--sm-trigger-py",triggerFontSize:"--sm-trigger-font-size",kbdBg:"--sm-kbd-bg",kbdBgDark:"--sm-kbd-bg-dark",kbdTextColor:"--sm-kbd-text-color",kbdTextColorDark:"--sm-kbd-text-color-dark",kbdBorderRadius:"--sm-kbd-radius",iconColor:"--sm-icon-color",iconColorDark:"--sm-icon-color-dark",highlightBgLight:"--sm-highlight-bg",highlightColorLight:"--sm-highlight-color",highlightBgDark:"--sm-highlight-bg-dark",highlightColorDark:"--sm-highlight-color-dark",promotedBg:"--sm-promoted-bg",promotedBgDark:"--sm-promoted-bg-dark",promotedColor:"--sm-promoted-color",promotedColorDark:"--sm-promoted-color-dark",spinnerColor:"--sm-spinner-color-light",spinnerColorDark:"--sm-spinner-color-dark"},se=["modalBorderRadius","modalBorderWidth","modalMaxWidth","modalPaddingX","modalPaddingY","headerBorderWidth","headerBorderRadius","headerPaddingX","headerPaddingY","inputFontSize","inputBorderRadius","inputBorderWidth","inputPaddingX","inputPaddingY","resultGap","resultBorderWidth","resultPaddingX","resultPaddingY","resultBorderRadius","triggerBorderRadius","triggerBorderWidth","triggerPaddingX","triggerPaddingY","triggerFontSize","kbdBorderRadius"],ae=["modalMaxHeight"],Ee=["modalBg","modalBgDark","modalBorderColor","modalBorderColorDark","headerBg","headerBgDark","headerBorderColor","headerBorderColorDark","inputBg","inputBgDark","inputTextColor","inputTextColorDark","inputPlaceholderColor","inputPlaceholderColorDark","inputBorderColor","inputBorderColorDark","resultBg","resultBgDark","resultBorderColor","resultBorderColorDark","resultActiveBg","resultActiveBgDark","resultActiveBorderColor","resultActiveBorderColorDark","resultTextColor","resultTextColorDark","resultActiveTextColor","resultActiveTextColorDark","resultActiveDescColor","resultActiveDescColorDark","resultActiveMutedColor","resultActiveMutedColorDark","resultDescColor","resultDescColorDark","resultMutedColor","resultMutedColorDark","triggerBg","triggerBgDark","triggerTextColor","triggerTextColorDark","triggerBorderColor","triggerBorderColorDark","triggerHoverBg","triggerHoverBgDark","triggerHoverTextColor","triggerHoverTextColorDark","triggerHoverBorderColor","triggerHoverBorderColorDark","kbdBg","kbdBgDark","kbdTextColor","kbdTextColorDark","iconColor","iconColorDark","highlightBgLight","highlightColorLight","highlightBgDark","highlightColorDark","promotedBg","promotedBgDark","promotedColor","promotedColorDark","spinnerColor","spinnerColorDark"],Zt={...Ie,highlightBgLight:"#fef08a",highlightColorLight:"#854d0e",highlightBgDark:"#854d0e",highlightColorDark:"#fef08a"};function pt(e){return typeof e=="string"&&/^(var|light-dark|calc|env|clamp|min|max|rgb|hsl)\s*\(/.test(e.trim())}function bt(e){return/^[0-9a-fA-F]{6}$/.test(e)}function ft(e,t){if(t==null||t==="")return null;let n=String(t);return pt(n)||(Ee.includes(e)&&bt(n)&&(n="#"+n),se.includes(e)&&(n=n+"px"),ae.includes(e)&&(n=n+"vh")),n}function Re(e,t,n="light"){if(!t||typeof t!="object")return;let r=n==="dark",o=Object.entries(Be),s=new Set([...se,...ae]);for(let[a,l]of o){let i=a.endsWith("Dark");if(r){if(!i&&!s.has(a))continue}else if(i)continue;if(t[a]!==void 0&&t[a]!==null&&t[a]!==""){let c=ft(a,t[a]);c&&e.style.setProperty(l,c)}}}var yt=new Set(["mark","em","strong","u","b","i","span"]),kt=/^[A-Za-z0-9_-]+$/;function u(e){return e?String(e).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"):""}function Le(e){return e?e.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"):""}function ie(e){if(!e)return[];let t=[],n=/"([^"]+)"/g,r;for(;(r=n.exec(e))!==null;)r[1].trim()&&t.push(r[1].trim());let o=e.replace(/"[^"]*"/g,""),s=new Set(["and","or","not","und","oder","nicht","et","ou","sauf","y","o","no"]);o.split(/\s+/).filter(l=>l.length>0).forEach(l=>{l=l.replace(/^[a-zA-Z]+:/,""),l=l.replace(/\*/g,""),l=l.replace(/\^\d+(\.\d+)?/,""),l=l.replace(/"/g,""),!(!l||s.has(l.toLowerCase()))&&t.push(l)});let a=[];return t.forEach(l=>{a.push(l);let i=l.split(/(?<=[a-z])(?=[A-Z])/);i.length>1&&i.forEach(c=>{c.length>=3&&a.push(c)})}),a}function W(e,t,n={}){let{enabled:r=!0,tag:o="mark",className:s="",terms:a=null}=n;if(!r)return u(e);let l=vt(o),c=["sm-highlight",...xt(s)],d=` class="${u(c.join(" "))}"`,h=wt(t,a);return h.length===0?u(e):Ct(e,h,l,d)}function vt(e){let t=String(e||"mark").trim().toLowerCase();return yt.has(t)?t:"mark"}function xt(e){return String(e||"").trim().split(/\s+/).filter(t=>t&&kt.test(t))}function wt(e,t){return Array.isArray(t)&&t.length>0?$e(t):e?$e(ie(e)):[]}function $e(e){let t=new Set;return e.filter(n=>typeof n=="string"&&n.length>0).sort((n,r)=>r.length-n.length).filter(n=>{let r=n.toLowerCase();return t.has(r)?!1:(t.add(r),!0)})}function Ct(e,t,n,r){let o=e.toLowerCase(),s=[];if(t.forEach(d=>{let h=d.toLowerCase();if(!h)return;let m=0;for(;m<o.length;){let g=o.indexOf(h,m);if(g===-1)break;s.push({start:g,end:g+h.length}),m=g+h.length}}),s.length===0)return u(e);s.sort((d,h)=>d.start!==h.start?d.start-h.start:h.end-h.start-(d.end-d.start));let a=[],l=-1;s.forEach(d=>{d.start>=l&&(a.push(d),l=d.end)});let i="",c=0;return a.forEach(d=>{c<d.start&&(i+=u(e.slice(c,d.start))),i+=`<${n}${r}>${u(e.slice(d.start,d.end))}</${n}>`,c=d.end}),c<e.length&&(i+=u(e.slice(c))),i}function j(e,t,n="smq"){if(!e||e==="#")return e;if(St(e))return"#";let r=(t||"").trim();if(!r||!n||/^(mailto:|tel:)/i.test(e))return e;let[o,s]=e.split("#",2),[a,l]=o.split("?",2),i=new URLSearchParams(l||"");i.set(n,r);let c=i.toString(),d=s?`#${s}`:"";return`${a}${c?`?${c}`:""}${d}`}function St(e){let t=String(e).replace(/[\t\n\r]/g,"").replace(/^[\u0000-\u0020]+/,"");return/^(javascript|data|vbscript):/i.test(t)}var Tt=0;function le(e="sm"){return`${e}-${++Tt}-${Date.now().toString(36)}`}function He(e){let t=document.createElement("div");return t.setAttribute("role","status"),t.setAttribute("aria-live","polite"),t.setAttribute("aria-atomic","true"),t.className="sm-sr-only",e.appendChild(t),t}function K(e,t,n=100){e&&(e.textContent="",setTimeout(()=>{e.textContent=t},n))}function de(e,t){return e===0?`No results found for "${t}"`:e===1?`1 result found for "${t}"`:`${e} results found for "${t}"`}function Pe(){return"Searching..."}function Me(e){return e===0?"No recent searches":e===1?"1 recent search available":`${e} recent searches available`}function ee(e,{expanded:t,activeDescendant:n,listboxId:r}){e.setAttribute("aria-expanded",String(t)),e.setAttribute("aria-controls",r),n?e.setAttribute("aria-activedescendant",n):e.removeAttribute("aria-activedescendant")}function F(e,t){return`${e}-option-${t}`}function Ne(e,t){if(!e||!t)return;let n=e.getBoundingClientRect(),r=t.getBoundingClientRect();n.top<r.top?e.scrollIntoView({block:"nearest",behavior:"smooth"}):n.bottom>r.bottom&&e.scrollIntoView({block:"nearest",behavior:"smooth"})}function At(e,t,n={}){let{groupResults:r=!1,resultLayout:o="default",listboxId:s}=n;if(!e||e.length===0)return"";if(o==="hierarchical")return Mt(e,t,n);if(r){let a=Se(e),l=0;return Object.entries(a).map(([i,c])=>`
            <div class="sm-section" role="group" aria-label="${u(i)}">
                <div class="sm-section-header">${u(i)}</div>
                ${c.map(d=>Oe(d,l++,t,n)).join("")}
            </div>
        `).join("")}return e.map((a,l)=>Oe(a,l,t,n)).join("")}function Oe(e,t,n,r={}){let{listboxId:o,enableHighlighting:s=!0,highlightTag:a="mark",highlightClass:l="",groupResults:i=!1,promotions:c={},debug:d=!1,persistQueryInUrl:h=!1,queryParamName:m="smq"}=r,g=ue(e),b=g?e.sectionTitle||e.title||e.name||"Untitled":e.title||e.name||"Untitled",x=e.snippet||"",f=g?e.sectionUrl||e.url||e.href||"#":e.url||e.href||"#",p=j(f,n,h?m:""),I=e.source||e.entrySection||e.type||"",v=F(o,t),k=e.promoted===!0,B=e._index||e.index||"",E=ge(e),C={enabled:s,tag:a,className:l},U=W(b,n,{...C,terms:O(e,"title")}),L=x?he(e,x,n,{...C,terms:O(e,"snippet")}):"",H=Dt(e,c),T=k?" sm-promoted":"",P=I&&!i?`<span class="sm-result-type">${u(I)}</span>`:"",R=d?Ue(e):"";return d?`
            <a class="sm-result-item sm-debug-enabled${T}" id="${v}" role="option" aria-selected="false" href="${u(p)}" data-index="${t}" data-source-index="${u(B)}"${E} data-title="${u(b)}">
                <div class="sm-result-main">
                    ${H}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${U}</span>
                        ${L?`<span class="sm-result-desc">${L}</span>`:""}
                    </div>
                    ${P}
                    ${_()}
                </div>
                ${R}
            </a>
        `:`
        <a class="sm-result-item${T}" id="${v}" role="option" aria-selected="false" href="${u(p)}" data-index="${t}" data-source-index="${u(B)}"${E} data-title="${u(b)}">
            ${H}
            <div class="sm-result-content">
                <span class="sm-result-title">${U}</span>
                ${L?`<span class="sm-result-desc">${L}</span>`:""}
            </div>
            ${P}
            ${_()}
        </a>
    `}function Ue(e){let t=[],n=e.backend?e.backend.toLowerCase():"";if((e._index||e.index)&&t.push(w("index",e._index||e.index,"index")),e.backend&&t.push(w("backend",n,"backend",n)),e.elementId&&t.push(w("element",e.elementId,"generic")),e.backendId&&t.push(w("hit",e.backendId,"generic")),e.score!==void 0&&e.score!==null){let r=typeof e.score=="number"?e.score.toFixed(2):e.score;t.push(w("score",r,"score"))}if(e.site&&t.push(w("site",e.site,"generic")),e.language&&t.push(w("lang",e.language,"generic")),e.matchedIn&&Array.isArray(e.matchedIn)&&e.matchedIn.length>0){let r=e.matchedIn.join(", ");t.push(w("matched",r,"matched"))}return e.promoted&&t.push(w("promoted","yes","promoted")),e.boosted&&t.push(w("boosted","yes","boosted")),t.length===0?"":`<div class="sm-debug-info">${t.join("")}</div>`}function O(e,t){let n=Array.isArray(e.matchedPhrases)?e.matchedPhrases:[],r=e.matchedTerms,o=[];r&&(t==="title"&&Array.isArray(r.title)&&r.title.length>0?o=r.title:t==="snippet"&&Array.isArray(r.content)&&r.content.length>0?o=r.content:o=[...Array.isArray(r.title)?r.title:[],...Array.isArray(r.content)?r.content:[]]);let s=[...n,...o];return s.length>0?s:null}function he(e,t,n,r){return W(t,n,r)}function w(e,t,n,r=""){let o=r?` data-backend="${u(r)}"`:"";return`<span class="sm-debug-item"><span class="sm-debug-label">${u(e)}</span><span class="sm-debug-value" data-type="${u(n)}"${o}>${u(String(t))}</span></span>`}function Dt(e,t={}){let{showBadge:n=!0,badgeText:r="Featured",badgePosition:o="top-right"}=t;return!e.promoted||!n?"":`<span class="sm-promoted-badge ${`sm-promoted-badge--${o}`}">${u(r)}</span>`}function ue(e){return!!(e&&typeof e=="object"&&["heading","intro","promoted-page"].includes(String(e.sectionType||"")))}function It(e){return Array.isArray(e)&&e.some(ue)}function Bt(e,t){let n=new Map,r=[];return e.forEach((o,s)=>{if(!ue(o)){r.push({type:"single",item:o,order:s,score:N(o)});return}let a=$t(o);if(!n.has(a)){let c={hits:[],order:s,score:N(o)};n.set(a,c),r.push({type:"section-group",key:a,order:s,score:c.score})}let l=n.get(a);l.hits.push(o),l.score=Math.max(l.score,N(o));let i=r.find(c=>c.type==="section-group"&&c.key===a);i&&(i.score=l.score)}),r.map(o=>{if(o.type==="section-group"){let s=n.get(o.key);return{...o,item:Et(s.hits,t)}}return o}).sort((o,s)=>{let a=ce(s.score,o.score);return a!==0?a:o.order-s.order}).map(o=>o.item)}function Et(e,t){let n=[...e].sort((d,h)=>M(d)-M(h)),r=n.find(d=>d.sectionType==="intro")||null,o=[...e].sort((d,h)=>{let m=ce(N(h),N(d));return m!==0?m:M(d)-M(h)})[0]||n[0]||{},s=r||o,a=z(s),l=s.siteId??"",i=Number.isFinite(t)&&t>0?t:3,c=n.filter(d=>d.sectionType==="heading").sort((d,h)=>{let m=ce(N(h),N(d));return m!==0?m:M(d)-M(h)}).slice(0,i).sort((d,h)=>M(d)-M(h)).map(Rt);return{...s,elementId:a||s.elementId,backendId:r?.backendId||s.backendId||Lt(a,l),title:s.title||s.sectionTitle||s.name||"Untitled",url:s.url||"#",snippet:r&&r.snippet||null,score:N(o),headings:c,__sectionHitGroup:!0,__useBackendDomId:!0}}function Rt(e){let t=Number.parseInt(e.sectionLevel,10),n=Number.isFinite(t)?t:2;return{title:e.sectionTitle||e.title||"",text:e.sectionTitle||e.title||"",id:e.sectionAnchor||e.sectionId||"",level:n,url:e.sectionUrl||e.url||null,snippet:e.snippet||null,backendId:e.backendId||"",elementId:z(e),sectionType:e.sectionType,_index:e._index,index:e.index,matchedTerms:e.matchedTerms,matchedPhrases:e.matchedPhrases,__useBackendDomId:!0}}function $t(e){return[z(e)||qe(e)||"",e.siteId??""].join(":")}function M(e){let t=Number.parseInt(e.sectionIndex,10);return Number.isFinite(t)?t:Number.MAX_SAFE_INTEGER}function N(e){let t=Number(e?.score);return Number.isFinite(t)?t:Number.NEGATIVE_INFINITY}function ce(e,t){return e===t?0:e>t?1:-1}function Lt(e,t){let n=e||"unknown";return t!=null&&String(t)!==""?`${n}_${t}`:String(n)}function qe(e,t=null){return e?.backendId||t?.backendId||""}function z(e,t=null){return e?.elementId||t?.elementId||""}function ge(e,t=null){let n=qe(e,t)||z(e,t),r=z(e,t);return` data-id="${u(n)}" data-element-id="${u(r)}"`}function _(){return`<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>`}function Ht(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
    </svg>`}function Pt(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <line x1="4" y1="7" x2="20" y2="7"/>
        <line x1="4" y1="12" x2="20" y2="12"/>
        <line x1="4" y1="17" x2="14" y2="17"/>
    </svg>`}function Fe(){return`<svg class="sm-hierarchy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="9" x2="20" y2="9"/>
        <line x1="4" y1="15" x2="20" y2="15"/>
        <line x1="10" y1="3" x2="8" y2="21"/>
        <line x1="16" y1="3" x2="14" y2="21"/>
    </svg>`}function Mt(e,t,n={}){let{hierarchyGroupBy:r="",hierarchyStyle:o="tree",hierarchyDisplay:s="individual",maxHeadingsPerResult:a=3,listboxId:l}=n,i=o==="tree",c=o!=="none",d=It(e)?Bt(e,a):e,m=Te(d,r||""),g=0;return Object.entries(m).map(([b,x])=>{let f=x.map(p=>{let I=g++,v=Nt(p,I,t,n),k="",B=p.headings||[],E=p.__sectionHitGroup?B:B.slice(0,a);if(E.length>0){let L=Math.min(...E.map(T=>T.level||2)),H=E.map(T=>i?(T.level||2)-L:0);k=E.map((T,P)=>{let R=H[P],Y=!H.slice(P+1).some(q=>q===R),G=[];if(i){let q=H.slice(P+1);for(let A=0;A<R;A++)q.some(V=>V===A)&&G.push(A)}return Ot(p,T,g++,t,n,Y,R,G)}).join("")}let C=!!k;return`
                <div class="sm-hierarchy-block${C?" sm-hierarchy-block--has-children":""}${s==="unified"?" sm-hierarchy-block--unified":""}">
                    ${C?v.replace("sm-result-item sm-hierarchy-parent","sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children"):v}
                    ${C?`<div class="sm-hierarchy-children${c?"":" sm-hierarchy-children--no-connectors"}">${k}</div>`:""}
                </div>
            `}).join("");return`
            <div class="sm-hierarchy-group" role="group" aria-label="${u(b)}">
                <div class="sm-hierarchy-group-header">${u(b)}</div>
                ${f}
            </div>
        `}).join("")}function Nt(e,t,n,r={}){let{listboxId:o,enableHighlighting:s=!0,highlightTag:a="mark",highlightClass:l="",debug:i=!1,persistQueryInUrl:c=!1,queryParamName:d="smq"}=r,h=e.title||e.name||"Untitled",m=e.snippet||"",g=e.url||"#",b=j(g,n,c?d:""),x=F(o,t),f=e._index||e.index||"",p=ge(e),I={enabled:s,tag:a,className:l},v=W(h,n,{...I,terms:O(e,"title")}),k=m?he(e,m,n,{...I,terms:O(e,"snippet")}):"",B=i?Ue(e):"",C=e.headings&&e.headings.length>0?Ht():Pt();return i?`
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled" id="${x}" role="option" aria-selected="false" href="${u(b)}" data-index="${t}" data-source-index="${u(f)}"${p} data-title="${u(h)}">
                <div class="sm-result-main">
                    ${C}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${v}</span>
                        ${k?`<span class="sm-result-desc">${k}</span>`:""}
                    </div>
                    ${_()}
                </div>
                ${B}
            </a>
        `:`
        <a class="sm-result-item sm-hierarchy-parent" id="${x}" role="option" aria-selected="false" href="${u(b)}" data-index="${t}" data-source-index="${u(f)}"${p} data-title="${u(h)}">
            ${C}
            <div class="sm-result-content">
                <span class="sm-result-title">${v}</span>
                ${k?`<span class="sm-result-desc">${k}</span>`:""}
            </div>
            ${_()}
        </a>
    `}function Ot(e,t,n,r,o={},s=!1,a=0,l=[]){let{listboxId:i,enableHighlighting:c=!0,highlightTag:d="mark",highlightClass:h="",debug:m=!1,persistQueryInUrl:g=!1,queryParamName:b="smq"}=o,f=(t.title||t.text||"").replace(/^#+\s*/,""),p=t.snippet||"",I=Number.parseInt(t.level,10),v=Number.isFinite(I)?Math.min(Math.max(I,1),6):2,k=t.id||(f?Ft(f):""),B=e.url||"#",E=t.url||(k?`${B}#${k}`:B),C=j(E,r,g?b:""),U=F(i,n),L=t._index||t.index||e._index||e.index||"",H=ge(t,e),T={enabled:c,tag:d,className:h},P=W(f,r,{...T,terms:O(t,"title")||O(e,"title")}),R=p?he(e,p,r,{...T,terms:O(t,"snippet")||O(e,"snippet")}):"",Y=s?" sm-hierarchy-child-row-last":"",G=l.map(A=>`<div class="sm-hierarchy-guide" style="--sm-guide-depth:${A}" aria-hidden="true"></div>`).join(""),q="";if(m){let A=[];A.push(w("h",v,"generic")),k&&A.push(w("anchor",k,"generic"));let V=z(t,e);V&&A.push(w("parent",V,"generic")),q=`<div class="sm-debug-info">${A.join("")}</div>`}return m?`
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${v} sm-hierarchy-depth-${a}${Y}" style="--sm-hierarchy-depth:${a}">
                ${G}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${v} sm-debug-enabled" id="${U}" role="option" aria-selected="false" href="${u(C)}" data-index="${n}" data-source-index="${u(L)}"${H} data-title="${u(f)}">
                    <div class="sm-result-main">
                        ${Fe()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${P}</span>
                            ${R?`<span class="sm-result-desc">${R}</span>`:""}
                        </div>
                        ${_()}
                    </div>
                    ${q}
                </a>
            </div>
        `:`
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${v} sm-hierarchy-depth-${a}${Y}" style="--sm-hierarchy-depth:${a}">
            ${G}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${v}" id="${U}" role="option" aria-selected="false" href="${u(C)}" data-index="${n}" data-source-index="${u(L)}"${H} data-title="${u(f)}">
                ${Fe()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${P}</span>
                    ${R?`<span class="sm-result-desc">${R}</span>`:""}
                </div>
                ${_()}
            </a>
        </div>
    `}function Ft(e){let t=e.normalize("NFKD").toLowerCase();try{return t.replace(/[^\p{L}\p{N}]+/gu,"-").replace(/^-+|-+$/g,"")}catch{return t.replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"")}}function _t(e,t){return!e||e.length===0?"":`
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${t}-recent-label">Recent searches</span>
                <button class="sm-clear-recent" part="clear-recent">Clear</button>
            </div>
            ${e.map((n,r)=>`
                <div class="sm-result-item sm-recent-item" id="${F(t,r)}" role="option" aria-selected="false" data-index="${r}" data-url="${u(n.url||"")}" data-query="${u(n.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${u(n.title||n.query)}</span>
                    ${_()}
                </div>
            `).join("")}
        </div>
    `}function _e(e){return!e||!e.trim()?`
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
            <p>No results for "<strong>${u(e)}</strong>"</p>
        </div>
    `}function Ut(){return`
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>Searching...</p>
        </div>
    `}function qt(e){return`
        <div class="sm-empty sm-error" part="error">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>${u(e||"Search failed.")}</p>
        </div>
    `}function je(e,t){let{query:n,results:r,recentSearches:o,loading:s,showRecent:a,error:l}=e,{showLoadingIndicator:i=!0}=t,c=n&&n.trim();return s&&i?{html:Ut(),hasResults:!1,showListbox:!1}:l?{html:qt(l),hasResults:!1,showListbox:!1}:c?!r||r.length===0?{html:_e(n),hasResults:!1,showListbox:!1}:{html:At(r,n,t),hasResults:!0,showListbox:!0}:a&&o&&o.length>0?{html:_t(o,t.listboxId),hasResults:!0,showListbox:!0}:{html:_e(""),hasResults:!1,showListbox:!1}}function me(e,t,n=!1){if(!e)return"";let r=[];if(r.push($("results",t,"generic")),e.took!==void 0){let i=e.took<1?"<1ms":`${Math.round(e.took)}ms`;r.push($("time",i,"time"))}if(e.cacheEnabled!==void 0&&(e.cacheEnabled?e.cached?r.push($("cache","hit","cache-hit")):r.push($("cache","miss","cache-miss")):r.push($("cache","off","cache-off"))),e.cacheDriver&&r.push($("storage",e.cacheDriver,"cache-driver",e.cacheDriver)),e.indices&&e.indices.length>0){let i=e.indices.length>2?`${e.indices.length} indices`:e.indices.join(", ");r.push($("indices",i,"generic"))}if(e.synonymsExpanded){let i=e.expandedQueries?e.expandedQueries.length-1:0;r.push($("synonyms",`+${i}`,"synonyms"))}let o=e.rulesMatched?.length||0;r.push($("rules",o,o>0?"rules":"generic"));let s=e.promotionsMatched?.length||0;r.push($("promoted",s,s>0?"promotions":"generic"));let l=`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${n?'<path d="M6 9l6 6 6-6"/>':'<path d="M18 15l-6-6-6 6"/>'}</svg>`;return n?`<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">Debug</span>${l}</div>`:`<div class="sm-toolbar-content">${r.join("")}</div><button class="sm-toolbar-toggle" aria-label="Collapse debug panel" aria-expanded="true">${l}</button>`}function $(e,t,n,r=""){let o=r?` data-backend="${u(r)}"`:"";return`<span class="sm-toolbar-item"><span class="sm-toolbar-label">${u(e)}</span><span class="sm-toolbar-value" data-type="${u(n)}"${o}>${u(String(t))}</span></span>`}function ze(e,t){let{onSelect:n,onIndexChange:r,onEscape:o}=e,{listboxId:s}=t;return{handleKeydown(a,l,i){let c=i;switch(a.key){case"ArrowDown":return a.preventDefault(),c=Math.min(i+1,l-1),c!==i&&r&&r(c),c;case"ArrowUp":return a.preventDefault(),c=Math.max(i-1,-1),c!==i&&r&&r(c),c;case"Enter":return a.preventDefault(),i>=0&&n&&n(i),null;case"Escape":return a.preventDefault(),o&&o(),null;default:return null}},getListboxId(){return s}}}function Ge(e,t,n={}){let{scrollContainer:r,inputElement:o,listboxId:s,selectedClass:a="sm-selected"}=n,l=t>=0?F(s,t):null;o&&ee(o,{expanded:e.length>0,activeDescendant:l,listboxId:s}),e.forEach((i,c)=>{let d=c===t;i.classList.toggle(a,d),i.setAttribute("aria-selected",String(d)),d&&r&&Ne(i,r)})}function We(e,t){e.forEach((n,r)=>{n.addEventListener("mouseenter",()=>{t&&t(r)})})}var Ke="sm-page-highlight-style",Ye="__smPageHighlightRegistry",Ve="__searchManagerHotkeyHandled",D=null,pe=class extends HTMLElement{constructor(){super(),this.attachShadow({mode:"open"}),this.config=null,this.state=ve({...Q},this.handleStateChange.bind(this)),this.searchSequence=0,this.debounceTimer=null,this.analyticsIdleTimer=null,this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.listboxId=le("sm-listbox"),this.inputId=le("sm-input"),this.liveRegion=null,this.keyboardNavigator=null,this.elements={},this.handleInput=this.handleInput.bind(this),this.handleKeydown=this.handleKeydown.bind(this),this.handleResultClick=this.handleResultClick.bind(this)}get widgetType(){throw new Error("Subclass must implement widgetType getter")}render(){throw new Error("Subclass must implement render()")}getResultsContainer(){throw new Error("Subclass must implement getResultsContainer()")}getInputElement(){throw new Error("Subclass must implement getInputElement()")}getLoadingElement(){return this.elements.loading||null}getDebugToolbarElement(){return this.elements.debugToolbar||null}connectedCallback(){this.config=ne(this,this.widgetType),this.state.set({recentSearches:Z(X(this.config))}),this.keyboardNavigator=ze({onSelect:t=>this.selectResultAtIndex(t),onIndexChange:t=>this.state.set({selectedIndex:t}),onEscape:()=>this.handleEscape()},{listboxId:this.listboxId}),this.applyDestinationPageHighlight()}disconnectedCallback(){this.unregisterOpenWidget(),this.searchSequence++,this.debounceTimer&&(clearTimeout(this.debounceTimer),this.debounceTimer=null)}registerOpenWidget(){D&&D!==this&&typeof D.close=="function"&&D.close({reason:"replace",replacedBy:this,source:"replace"}),D=this}unregisterOpenWidget(){D===this&&(D=null)}claimHotkeyEvent(t,n){return t[Ve]||D&&D!==this&&D.state?.get("isOpen")&&D.config?.hotkey?.toLowerCase()===n?!1:(t[Ve]=!0,!0)}attributeChangedCallback(t,n,r){n!==r&&this.shadowRoot.children.length>0&&(this.config=ne(this,this.widgetType),this.render(),this.applyCustomStyles())}handleStateChange(t,n){(n.includes("results")||n.includes("query")||n.includes("recentSearches")||n.includes("error"))&&this.renderResultsContent(),(n.includes("results")||n.includes("meta"))&&this.updateDebugToolbar(),n.includes("selectedIndex")&&this.updateSelectionVisual(),n.includes("loading")&&this.updateLoadingVisual()}handleInput(t){let n=t.target.value;if(this.state.set({query:n,selectedIndex:-1}),this.debounceTimer&&clearTimeout(this.debounceTimer),this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),!n.trim()){this.state.set({results:[]});return}n.length<this.config.minChars||(this.debounceTimer=setTimeout(()=>{this.executeSearch(n)},this.config.debounce))}async executeSearch(t){let n=++this.searchSequence;this.state.set({loading:!0,error:null}),this.liveRegion&&K(this.liveRegion,Pe());try{let{results:r,meta:o}=await xe({query:t,endpoint:this.config.searchEndpoint,indices:this.config.indices,siteId:this.config.siteId,maxResults:this.config.maxResults,hideResultsWithoutUrl:this.config.hideResultsWithoutUrl,showCodeSnippets:this.config.showCodeSnippets,snippetMode:this.config.snippetMode,snippetLength:this.config.snippetLength,parseMarkdownSnippets:this.config.parseMarkdownSnippets,debug:this.config.debug,apiKey:this.config.apiKey});if(n!==this.searchSequence)return;this.state.set({results:r,meta:o,loading:!1,selectedIndex:r.length>0?0:-1}),o&&typeof o.cached=="boolean"?this.lastSearchCacheState={cached:o.cached,took:typeof o.took=="number"?o.took:null}:this.lastSearchCacheState=null,this.liveRegion&&K(this.liveRegion,de(r.length,t)),this.dispatchWidgetEvent("search",{query:t,results:r,meta:o}),this.startAnalyticsIdleTimer(t,r.length)}catch(r){if(n!==this.searchSequence||r.name==="AbortError")return;console.error("Search error:",r),this.state.set({results:[],loading:!1,error:r.message}),this.dispatchWidgetEvent("error",{query:t,error:r.message})}}renderResultsContent(){let t=this.getResultsContainer();if(!t)return;let n=this.state.getAll(),{showRecent:r,groupResults:o,enableHighlighting:s,highlightTag:a,highlightClass:l,showLoadingIndicator:i,debug:c}=this.config,{html:d,hasResults:h,showListbox:m}=je({query:n.query,results:n.results,recentSearches:n.recentSearches,loading:n.loading,error:n.error,showRecent:r},{listboxId:this.listboxId,groupResults:o,enableHighlighting:s,highlightTag:a,highlightClass:l,showLoadingIndicator:i,debug:c,persistQueryInUrl:this.config.highlightDestinationPage&&this.config.persistQueryInUrl,queryParamName:this.config.queryParamName,promotions:this.config.promotions,resultLayout:this.config.resultLayout,hierarchyGroupBy:this.config.hierarchyGroupBy,hierarchyStyle:this.config.hierarchyStyle,hierarchyDisplay:this.config.hierarchyDisplay,maxHeadingsPerResult:this.config.maxHeadingsPerResult});t.innerHTML=d,m?t.setAttribute("role","listbox"):t.removeAttribute("role");let g=this.getInputElement();g&&ee(g,{expanded:h,activeDescendant:null,listboxId:this.listboxId}),this.liveRegion&&!n.loading&&(n.query&&n.results.length===0?K(this.liveRegion,de(0,n.query)):!n.query&&n.recentSearches.length>0&&r&&K(this.liveRegion,Me(n.recentSearches.length))),this.attachResultHandlers();let b=t.querySelector(".sm-clear-recent");b&&b.addEventListener("click",x=>{x.stopPropagation(),De(X(this.config)),this.state.set({recentSearches:[]})}),h&&n.results.length>0&&this.state.set({selectedIndex:0})}attachResultHandlers(){let t=this.getResultsContainer();if(!t)return;let n=t.querySelectorAll(".sm-result-item");n.forEach(r=>{r.addEventListener("click",o=>this.handleResultClick(o,r))}),We(n,r=>{this.state.set({selectedIndex:r})})}updateSelectionVisual(){let t=this.getResultsContainer(),n=this.getInputElement();if(!t)return;let r=t.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");Ge(r,o,{scrollContainer:t,inputElement:n,listboxId:this.listboxId})}handleKeydown(t){let n=this.getResultsContainer();if(!n)return;let r=n.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");if(t.key==="Enter"){let s=this.state.get("query"),a=this.state.get("results")||[];s&&a.length>0&&this.trackSearchAnalytics(s,a.length,"enter")}this.keyboardNavigator.handleKeydown(t,r.length,o)}selectResultAtIndex(t){let n=this.getResultsContainer();if(!n)return;let r=n.querySelectorAll(".sm-result-item");t>=0&&r[t]&&r[t].click()}handleEscape(){}handleResultClick(t,n){let r=n.getAttribute("href"),o=n.dataset.url,s=r||o,a=n.dataset.title||n.querySelector(".sm-result-title")?.textContent,l=n.dataset.id,i=n.dataset.elementId||l,c=n.dataset.query||this.state.get("query"),d=n.classList.contains("sm-recent-item"),h=j(s,c,this.config.highlightDestinationPage&&this.config.persistQueryInUrl?this.config.queryParamName:"");if(!d&&c){let g=Ae(X(this.config),c,{title:a,url:s},this.config.maxRecentSearches);this.state.set({recentSearches:g})}let m=n.dataset.sourceIndex||ye(this.config);if(i&&m&&we({endpoint:this.config.trackClickEndpoint,elementId:i,query:c,index:m,apiKey:this.config.apiKey}),!d&&c&&this.trackSearchAnalytics(c,this.state.get("results")?.length||0,"click"),this.dispatchWidgetEvent("result-click",{id:l,elementId:i,title:a,url:h,query:c,isRecent:d}),s&&s!=="#")d&&(t.preventDefault(),window.location.href=h),this.onResultSelected(h,a,l);else if(c){t.preventDefault();let g=this.getInputElement();g&&(g.value=c,this.state.set({query:c}),this.executeSearch(c))}}onResultSelected(t,n,r){}applyDestinationPageHighlight(){if(!this.config.highlightDestinationPage||typeof window>"u"||typeof document>"u")return;let t=this.config.queryParamName||"smq",n=this.config.destinationHighlightSelector||"main, article, [data-search-content]",r=new URLSearchParams(window.location.search).get(t);if(!r||!r.trim())return;let o=this.getPageHighlightRegistry(),s=`${t}::${n}`;if(o.has(s))return;o.add(s);let a=()=>{this.ensurePageHighlightStyles(),this.highlightDestinationNodes(r.trim(),n,s)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",a,{once:!0}):window.requestAnimationFrame(a)}ensurePageHighlightStyles(){if(document.getElementById(Ke))return;let t=document.createElement("style");t.id=Ke,t.textContent=`
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `,document.head.appendChild(t)}highlightDestinationNodes(t,n,r){let o=Array.from(document.querySelectorAll(n));if(o.length===0)return;let s=[...new Set(ie(t).map(i=>i.trim()).filter(i=>i.length>=2))];if(s.length===0)return;let a=s.map(i=>Le(i)).filter(Boolean).sort((i,c)=>c.length-i.length).join("|");if(!a)return;let l=new RegExp(`(${a})`,"gi");o.forEach(i=>{i.getAttribute("data-sm-highlighted")!==r&&(this.highlightTextNodesInScope(i,l),i.setAttribute("data-sm-highlighted",r))})}highlightTextNodesInScope(t,n){let r=document.createTreeWalker(t,NodeFilter.SHOW_TEXT,{acceptNode:s=>{let a=s.nodeValue;if(!a||!a.trim())return NodeFilter.FILTER_REJECT;let l=s.parentElement;return!l||l.closest("script, style, noscript, textarea, mark, .sm-highlight, .sm-page-highlight, search-modal")?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT}}),o=[];for(;r.nextNode();)o.push(r.currentNode);o.forEach(s=>{let a=s.nodeValue||"";if(n.lastIndex=0,!n.test(a))return;let l=document.createDocumentFragment(),i=0;n.lastIndex=0;let c=a.matchAll(n);for(let d of c){let h=d[0],m=d.index??-1;if(m<0)continue;m>i&&l.appendChild(document.createTextNode(a.slice(i,m)));let g=document.createElement("mark");g.className="sm-highlight sm-page-highlight",g.textContent=h,l.appendChild(g),i=m+h.length}i<a.length&&l.appendChild(document.createTextNode(a.slice(i))),s.parentNode?.replaceChild(l,s)})}getPageHighlightRegistry(){let t=window[Ye];if(t instanceof Set)return t;let n=new Set;return window[Ye]=n,n}updateLoadingVisual(){let t=this.getLoadingElement();if(t){let n=this.state.get("loading"),r=this.config?.showLoadingIndicator!==!1;t.hidden=!n||!r}}updateDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let{debug:n}=this.config,r=this.state.getAll();if(!n||!r.meta||r.results.length===0){t.hidden=!0;return}let o=t.classList.contains("sm-collapsed");t.innerHTML=me(r.meta,r.results.length,o),t.hidden=!1,o&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}attachDebugToolbarHandlers(t){let n=t.querySelector(".sm-toolbar-toggle");n&&n.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()});let r=t.querySelector(".sm-toolbar-collapsed-bar");r&&r.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()})}toggleDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let n=t.classList.toggle("sm-collapsed"),r=this.state.getAll();t.innerHTML=me(r.meta,r.results.length,n),n&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}applyCustomStyles(){if(!this.config)return;let t=this.shadowRoot.host,{theme:n,styles:r,resultTitleLines:o,resultDescLines:s}=this.config;Re(t,r,n),o&&t.style.setProperty("--sm-result-title-lines",String(o)),s&&t.style.setProperty("--sm-result-desc-lines",String(s))}initializeLiveRegion(){this.liveRegion=He(this.shadowRoot)}startAnalyticsIdleTimer(t,n){this.analyticsIdleTimer&&clearTimeout(this.analyticsIdleTimer);let r=this.config.idleTimeout;!r||r<=0||(this.analyticsIdleTimer=setTimeout(()=>{this.trackSearchAnalytics(t,n,"idle")},r))}trackSearchAnalytics(t,n,r){!t||t===this.lastTrackedQuery||(this.lastTrackedQuery=t,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),Ce({endpoint:this.config.trackSearchEndpoint,query:t,indices:this.config.indices,resultsCount:n,trigger:r,source:this.config.source,siteId:this.config.siteId,cached:this.lastSearchCacheState?.cached,took:this.lastSearchCacheState?.took,apiKey:this.config.apiKey}))}resetAnalyticsTracking(){this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null)}dispatchWidgetEvent(t,n={}){this.dispatchEvent(new CustomEvent(`search-${t}`,{bubbles:!0,composed:!0,detail:n}))}},Xe=pe;var Qe=`/**
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

    --sm-promoted-bg: var(--sm-promoted-bg-dark, #2563eb);
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
   HIERARCHICAL RESULTS
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
   UNIFIED HIERARCHY DISPLAY
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
`;var Je=`/**
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
`;var Ze=`/* =========================================================================
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
`;var jt=Qe+`
`+Je+`
`+Ze,be=class extends Xe{constructor(){super(),this.externalTrigger=null,this.previouslyFocused=null,this.open=this.open.bind(this),this.close=this.close.bind(this),this.toggle=this.toggle.bind(this),this.handleGlobalKeydown=this.handleGlobalKeydown.bind(this),this.handleBackdropClick=this.handleBackdropClick.bind(this),this.handleTriggerClick=this.handleTriggerClick.bind(this),this.handleExternalTriggerClick=this.handleExternalTriggerClick.bind(this),this.handleCloseClick=this.handleCloseClick.bind(this)}get widgetType(){return"modal"}static get observedAttributes(){return ke("modal")}connectedCallback(){super.connectedCallback(),this.render(),this.attachEventListeners()}disconnectedCallback(){this.state.get("isOpen")&&this.close({reason:"disconnect",source:"disconnect",restoreFocus:!1}),super.disconnectedCallback(),this.detachEventListeners()}render(){let{theme:t,placeholder:n,showTrigger:r}=this.config,o=u(this.getHotkeyDisplay()),s=u(n||"");this.shadowRoot.innerHTML=`
            <style>${jt}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="Open search" aria-haspopup="dialog" aria-expanded="false" ${r?"":'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">Search</span>
                <kbd class="sm-trigger-kbd" aria-hidden="true">${o}</kbd>
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
                            placeholder="${s}"
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
        `,this.elements={trigger:this.shadowRoot.querySelector(".sm-trigger"),backdrop:this.shadowRoot.querySelector(".sm-backdrop"),modal:this.shadowRoot.querySelector(".sm-modal"),input:this.shadowRoot.querySelector(".sm-input"),results:this.shadowRoot.querySelector(".sm-results"),loading:this.shadowRoot.querySelector(".sm-loading"),close:this.shadowRoot.querySelector(".sm-close"),debugToolbar:this.shadowRoot.querySelector(".sm-debug-toolbar")},this.initializeLiveRegion(),this.shadowRoot.host.setAttribute("data-theme",t),this.applyCustomStyles()}getResultsContainer(){return this.elements.results}getInputElement(){return this.elements.input}getLoadingElement(){return this.elements.loading}applyCustomStyles(){if(super.applyCustomStyles(),!this.config)return;let{backdropOpacity:t,enableBackdropBlur:n}=this.config,r=this.shadowRoot.host;r.style.setProperty("--sm-backdrop-opacity",t/100),r.style.setProperty("--sm-backdrop-blur",n?"blur(4px)":"none")}attachEventListeners(){this.elements.trigger.addEventListener("click",this.handleTriggerClick),this.elements.close.addEventListener("click",this.handleCloseClick),this.elements.backdrop.addEventListener("click",this.handleBackdropClick),this.elements.input.addEventListener("input",this.handleInput),this.elements.input.addEventListener("keydown",this.handleKeydown),document.addEventListener("keydown",this.handleGlobalKeydown);let{triggerSelector:t}=this.config;t&&(this.externalTrigger=document.querySelector(t),this.externalTrigger&&this.externalTrigger.addEventListener("click",this.handleExternalTriggerClick))}detachEventListeners(){document.removeEventListener("keydown",this.handleGlobalKeydown),this.externalTrigger&&(this.externalTrigger.removeEventListener("click",this.handleExternalTriggerClick),this.externalTrigger=null)}open(t={}){let n=t.source||"programmatic";if(this.state.get("isOpen")){requestAnimationFrame(()=>{this.elements.input.focus()});return}this.previouslyFocused=document.activeElement instanceof HTMLElement?document.activeElement:null,this.registerOpenWidget(),this.state.set({isOpen:!0}),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value="",this.state.set({query:"",results:[],selectedIndex:-1}),this.renderResultsContent(),requestAnimationFrame(()=>{this.elements.input.focus()}),this.config.preventBodyScroll&&(document.body.style.overflow="hidden"),this.dispatchWidgetEvent("open",{source:n})}close(t={}){let n=this.state.get("isOpen");this.state.set({isOpen:!1}),this.elements.backdrop.hidden=!0,this.elements.trigger.setAttribute("aria-expanded","false"),this.unregisterOpenWidget(),this.config.preventBodyScroll&&(document.body.style.overflow=""),this.resetAnalyticsTracking(),n&&t.restoreFocus!==!1&&this.previouslyFocused?.isConnected&&this.previouslyFocused.focus(),this.previouslyFocused=null,n&&this.dispatchWidgetEvent("close",{reason:t.reason||"programmatic",source:t.source||"programmatic"})}toggle(t={}){this.state.get("isOpen")?this.close({reason:t.reason||"toggle",source:t.source||"toggle"}):this.open({source:t.source||"toggle"})}handleTriggerClick(){this.toggle({source:"trigger"})}handleExternalTriggerClick(){this.toggle({source:"external-trigger"})}handleCloseClick(){this.close({reason:"close-button",source:"close-button"})}handleGlobalKeydown(t){let n=this.config.hotkey.toLowerCase();if((navigator.platform.toUpperCase().indexOf("MAC")>=0?t.metaKey:t.ctrlKey)&&t.key.toLowerCase()===n){if(!this.claimHotkeyEvent(t,n))return;t.preventDefault(),this.toggle({source:"hotkey"})}t.key==="Escape"&&this.state.get("isOpen")&&(t.preventDefault(),this.close({reason:"escape",source:"escape"}))}handleEscape(){this.close({reason:"escape",source:"keyboard"})}handleBackdropClick(t){t.target===this.elements.backdrop&&this.close({reason:"backdrop",source:"backdrop"})}onResultSelected(t,n,r){this.close({reason:"result-selected",source:"result-selected"})}getHotkeyDisplay(){let t=navigator.platform.toUpperCase().indexOf("MAC")>=0,n=this.config.hotkey.toUpperCase();return t?`\u2318${n}`:`Ctrl+${n}`}},zt=be;return st(Gt);})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
