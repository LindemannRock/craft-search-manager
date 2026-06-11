"use strict";var SearchModalWidget=(()=>{var Y=Object.defineProperty;var qe=Object.getOwnPropertyDescriptor;var _e=Object.getOwnPropertyNames;var je=Object.prototype.hasOwnProperty;var ze=(e,t)=>{for(var r in t)Y(e,r,{get:t[r],enumerable:!0})},Ge=(e,t,r,n)=>{if(t&&typeof t=="object"||typeof t=="function")for(let o of _e(t))!je.call(e,o)&&o!==r&&Y(e,o,{get:()=>t[o],enumerable:!(n=qe(t,o))||n.enumerable});return e};var We=e=>Ge(Y({},"__esModule",{value:!0}),e);var wt={};ze(wt,{default:()=>xt});var Ke={indices:[],placeholder:"Search...",theme:"light",maxResults:20,debounce:200,minChars:2,showRecent:!0,maxRecentSearches:5,groupResults:!0,siteId:"",apiKey:"",searchEndpoint:"/actions/search-manager/api/search",trackClickEndpoint:"/actions/search-manager/search/track-click",trackSearchEndpoint:"/actions/search-manager/search/track-search",idleTimeout:1500,source:"",enableHighlighting:!0,highlightTag:"mark",highlightClass:"",hideResultsWithoutUrl:!1,showCodeSnippets:!1,snippetMode:"balanced",showLoadingIndicator:!0,debug:!1,resultTitleLines:1,resultDescLines:1,snippetLength:150,parseMarkdownSnippets:!1,persistQueryInUrl:!0,queryParamName:"smq",highlightDestinationPage:!0,destinationHighlightSelector:"main, article, [data-search-content]",resultLayout:"default",hierarchyGroupBy:"",hierarchyStyle:"tree",hierarchyDisplay:"individual",maxHeadingsPerResult:3,styles:{},promotions:{showBadge:!0,badgeText:"Featured",badgePosition:"top-right"}},Ye={hotkey:"k",showTrigger:!0,triggerSelector:"",backdropOpacity:50,enableBackdropBlur:!0,preventBodyScroll:!0},Ve={showFilters:!0,paginationType:"numbered",resultsPerPage:20,updateUrl:!0,sortOptions:["relevance","date-desc","date-asc","title"]},Xe={dropdownPosition:"below",dropdownMaxHeight:400,showOnFocus:!0};function Qe(e){return{...Ke,...{modal:Ye,page:Ve,inline:Xe}[e]||{}}}function y(e,t=!1){if(e==null)return t;if(typeof e=="boolean")return e;if(typeof e=="number")return e!==0;if(e==="")return!0;let r=String(e).trim().toLowerCase();return["1","true","on","yes"].includes(r)?!0:["0","false","off","no"].includes(r)?!1:t}function S(e,t=0){if(e==null)return t;let r=Number.parseInt(e,10);return Number.isNaN(r)?t:r}function se(e,t={}){if(!e)return t;try{return JSON.parse(e)}catch(r){return console.warn("SearchWidget: Invalid JSON attribute",r),t}}function ae(e){return e?e.split(",").map(t=>t.trim()).filter(Boolean):[]}function V(e,t="modal"){let r=Qe(t),n=e.getAttribute("indices")||"",o=ae(n),s={indices:o,index:o[0]||"",placeholder:e.getAttribute("placeholder")||r.placeholder,theme:e.getAttribute("theme")||r.theme,siteId:e.getAttribute("site-id")||r.siteId,apiKey:e.getAttribute("api-key")||r.apiKey,source:e.getAttribute("source")||r.source,highlightTag:e.getAttribute("highlight-tag")||r.highlightTag,highlightClass:e.getAttribute("highlight-class")||r.highlightClass,searchEndpoint:r.searchEndpoint,trackClickEndpoint:r.trackClickEndpoint,trackSearchEndpoint:r.trackSearchEndpoint,maxResults:S(e.getAttribute("max-results"),r.maxResults),debounce:S(e.getAttribute("debounce"),r.debounce),minChars:S(e.getAttribute("min-chars"),r.minChars),maxRecentSearches:S(e.getAttribute("max-recent-searches"),r.maxRecentSearches),idleTimeout:S(e.getAttribute("idle-timeout"),r.idleTimeout),showRecent:y(e.getAttribute("show-recent"),r.showRecent),groupResults:y(e.getAttribute("group-results"),r.groupResults),enableHighlighting:y(e.getAttribute("enable-highlighting"),r.enableHighlighting),showLoadingIndicator:y(e.getAttribute("show-loading-indicator"),r.showLoadingIndicator),hideResultsWithoutUrl:y(e.getAttribute("hide-results-without-url"),r.hideResultsWithoutUrl),showCodeSnippets:y(e.getAttribute("show-code-snippets"),r.showCodeSnippets),debug:y(e.getAttribute("debug"),r.debug),snippetMode:e.getAttribute("snippet-mode")||r.snippetMode,snippetLength:S(e.getAttribute("snippet-length"),r.snippetLength),parseMarkdownSnippets:y(e.getAttribute("parse-markdown-snippets"),r.parseMarkdownSnippets),persistQueryInUrl:y(e.getAttribute("persist-query-in-url"),r.persistQueryInUrl),highlightDestinationPage:y(e.getAttribute("highlight-destination-page"),r.highlightDestinationPage),resultTitleLines:S(e.getAttribute("result-title-lines"),r.resultTitleLines),resultDescLines:S(e.getAttribute("result-desc-lines"),r.resultDescLines),queryParamName:e.getAttribute("query-param-name")||r.queryParamName,destinationHighlightSelector:e.getAttribute("destination-highlight-selector")||r.destinationHighlightSelector,resultLayout:e.getAttribute("result-layout")||r.resultLayout,hierarchyGroupBy:e.getAttribute("hierarchy-group-by")||r.hierarchyGroupBy,hierarchyStyle:e.getAttribute("hierarchy-style")||r.hierarchyStyle,hierarchyDisplay:e.getAttribute("hierarchy-display")||r.hierarchyDisplay,maxHeadingsPerResult:S(e.getAttribute("max-headings-per-result"),r.maxHeadingsPerResult),styles:se(e.getAttribute("styles"),r.styles),promotions:se(e.getAttribute("promotions"),r.promotions)};return t==="modal"&&Object.assign(s,{hotkey:e.getAttribute("hotkey")||r.hotkey,triggerSelector:e.getAttribute("trigger-selector")||r.triggerSelector,backdropOpacity:S(e.getAttribute("backdrop-opacity"),r.backdropOpacity),showTrigger:y(e.getAttribute("show-trigger"),r.showTrigger),enableBackdropBlur:y(e.getAttribute("enable-backdrop-blur"),r.enableBackdropBlur),preventBodyScroll:y(e.getAttribute("prevent-body-scroll"),r.preventBodyScroll)}),t==="page"&&Object.assign(s,{resultsPerPage:S(e.getAttribute("results-per-page"),r.resultsPerPage),paginationType:e.getAttribute("pagination-type")||r.paginationType,showFilters:y(e.getAttribute("show-filters"),r.showFilters),updateUrl:y(e.getAttribute("update-url"),r.updateUrl),sortOptions:ae(e.getAttribute("sort-options"))||r.sortOptions}),t==="inline"&&Object.assign(s,{dropdownPosition:e.getAttribute("dropdown-position")||r.dropdownPosition,dropdownMaxHeight:S(e.getAttribute("dropdown-max-height"),r.dropdownMaxHeight),showOnFocus:y(e.getAttribute("show-on-focus"),r.showOnFocus)}),s}function ie(e="modal"){let t=["indices","placeholder","theme","max-results","debounce","min-chars","show-recent","max-recent-searches","group-results","site-id","idle-timeout","source","enable-highlighting","highlight-tag","highlight-class","hide-results-without-url","show-code-snippets","snippet-mode","show-loading-indicator","debug","styles","promotions","result-layout","hierarchy-group-by","hierarchy-style","hierarchy-display","max-headings-per-result","result-title-lines","result-desc-lines","snippet-length","parse-markdown-snippets","persist-query-in-url","query-param-name","highlight-destination-page","destination-highlight-selector"],s={modal:["hotkey","show-trigger","trigger-selector","backdrop-opacity","enable-backdrop-blur","prevent-body-scroll"],page:["show-filters","pagination-type","results-per-page","update-url","sort-options"],inline:["dropdown-position","dropdown-max-height","show-on-focus"]};return[...t,...s[e]||[]]}var j={isOpen:!1,query:"",results:[],recentSearches:[],selectedIndex:-1,loading:!1,error:null,meta:null};function le(e={},t=null){let r={...j,...e};return{get(n){return r[n]},getAll(){return{...r}},set(n){let o=[];return Object.keys(n).forEach(s=>{let a=r[s],l=n[s];z(a,l)||o.push(s)}),o.length>0&&(r={...r,...n},t&&t(r,o)),o},reset(n=e){let o={...j,...n},s=Object.keys(o).filter(a=>!z(r[a],o[a]));s.length>0&&(r=o,t&&t(r,s))},is(n,o){return r[n]===o},toggle(n){let o=!r[n];return this.set({[n]:o}),o}}}function z(e,t){if(e===t)return!0;if(e==null||t==null)return!1;if(Array.isArray(e)&&Array.isArray(t))return e.length!==t.length?!1:e.every((r,n)=>z(r,t[n]));if(typeof e=="object"&&typeof t=="object"){let r=Object.keys(e),n=Object.keys(t);return r.length!==n.length?!1:r.every(o=>z(e[o],t[o]))}return!1}async function de({query:e,endpoint:t,indices:r=[],siteId:n="",maxResults:o=10,hideResultsWithoutUrl:s=!1,showCodeSnippets:a=!1,snippetMode:l="balanced",snippetLength:i=150,parseMarkdownSnippets:d=!1,debug:c=!1,apiKey:u="",signal:m}){let g=new URLSearchParams({q:e,hitsPerPage:o.toString()});g.append("enrich","1"),r.length>0&&g.append("indices",r.join(",")),n&&g.append("siteId",n),s&&g.append("hideResultsWithoutUrl","1"),a&&g.append("showCodeSnippets","1"),l&&l!=="balanced"&&g.append("snippetMode",l),i&&i!==150&&g.append("snippetLength",String(i)),d&&g.append("parseMarkdownSnippets","1"),c&&g.append("debug","1"),g.append("skipAnalytics","1");let v=t.includes("?")?"&":"?",k={Accept:"application/json"};u&&(k["X-Search-Manager-Key"]=u);let p=await fetch(`${t}${v}${g}`,{signal:m,headers:k});if(!p.ok)throw new Error(await Je(p));let b=await p.json();return b.error&&console.warn("Search warning:",b.error),{results:b.results||b.hits||[],total:b.total||0,meta:b.meta||null,error:b.error||null}}async function Je(e){let t=await Ze(e);return e.status===401?t||"Search requires an API key.":e.status===403?t||"This API key cannot access this search.":e.status===429?t||"Search rate limit exceeded. Try again in a moment.":t||"Search failed."}async function Ze(e){try{if((e.headers.get("content-type")||"").includes("application/json")){let r=await e.json(),n=r.error||r.message||"";return typeof n=="string"?n.slice(0,240):""}}catch{return""}return""}function ce({endpoint:e,elementId:t,query:r,index:n,apiKey:o=""}){if(!(!t||!e))try{let s=new FormData;s.append("elementId",t),s.append("query",r),s.append("index",n);let a={Accept:"application/json"};o&&(a["X-Search-Manager-Key"]=o),fetch(e,{method:"POST",body:s,headers:a}).catch(()=>{})}catch{}}function he({endpoint:e,query:t,indices:r=[],resultsCount:n=0,trigger:o="unknown",source:s="",siteId:a="",cached:l,took:i,apiKey:d=""}){if(!(!t||!e))try{let c=new FormData;c.append("q",t),c.append("indices",r.join(",")),c.append("resultsCount",n.toString()),c.append("trigger",o),c.append("source",s||"frontend-widget"),a&&c.append("siteId",a),typeof l=="boolean"&&c.append("cached",l?"1":"0"),typeof i=="number"&&Number.isFinite(i)&&i>=0&&c.append("took",i.toString());let u={Accept:"application/json"};d&&(u["X-Search-Manager-Key"]=d),fetch(e,{method:"POST",body:c,headers:u}).catch(()=>{})}catch{}}function ue(e){let t={};return e.forEach(r=>{let n=r.section||r.type||"Results";t[n]||(t[n]=[]),t[n].push(r)}),t}function ge(e,t){let r={};return e.forEach(n=>{let o=n[t]||n.section||n.type||"Results";r[o]||(r[o]=[]),r[o].push(n)}),r}var et="sm-recent-";function X(e){return`${et}${e||"default"}`}function G(e){try{let t=X(e),r=localStorage.getItem(t);return r?JSON.parse(r):[]}catch{return[]}}function me(e,t,r=null,n=5){if(!t||!t.trim())return G(e);let o=X(e),s={query:t.trim(),title:r?.title||t,url:r?.url||null,timestamp:Date.now()},a=G(e);a=a.filter(l=>l.query!==s.query),a.unshift(s),a=a.slice(0,n);try{localStorage.setItem(o,JSON.stringify(a))}catch{}return a}function pe(e){try{let t=X(e);localStorage.removeItem(t)}catch{}}var be={spinnerColor:"#3b82f6",spinnerColorDark:"#60a5fa",modalBg:"#ffffff",modalBgDark:"#1f2937",modalBorderRadius:"12",modalBorderWidth:"1",modalBorderColor:"#e5e7eb",modalBorderColorDark:"#374151",modalShadow:"0 25px 50px -12px rgba(0, 0, 0, 0.25)",modalShadowDark:"0 25px 50px -12px rgba(0, 0, 0, 0.5)",modalMaxWidth:"640",modalMaxHeight:"80",modalPaddingX:"16",modalPaddingY:"16",headerBg:"transparent",headerBgDark:"transparent",headerBorderColor:"#e5e7eb",headerBorderColorDark:"#374151",headerBorderWidth:"1",headerBorderRadius:"0",headerPaddingX:"16",headerPaddingY:"12",inputBg:"#ffffff",inputBgDark:"#1f2937",inputTextColor:"#111827",inputTextColorDark:"#f9fafb",inputPlaceholderColor:"#9ca3af",inputPlaceholderColorDark:"#9ca3af",inputBorderColor:"transparent",inputBorderColorDark:"transparent",inputFontSize:"16",inputBorderRadius:"0",inputBorderWidth:"0",inputPaddingX:"0",inputPaddingY:"0",resultBg:"transparent",resultBgDark:"transparent",resultBorderColor:"#e5e7eb",resultBorderColorDark:"#374151",resultActiveBg:"#e5e7eb",resultActiveBgDark:"#4b5563",resultActiveBorderColor:"#e5e7eb",resultActiveBorderColorDark:"#374151",resultActiveTextColor:"#111827",resultActiveTextColorDark:"#f9fafb",resultActiveDescColor:"#4b5563",resultActiveDescColorDark:"#d1d5db",resultActiveMutedColor:"#6b7280",resultActiveMutedColorDark:"#d1d5db",resultTextColor:"#111827",resultTextColorDark:"#f9fafb",resultDescColor:"#4b5563",resultDescColorDark:"#d1d5db",resultMutedColor:"#6b7280",resultMutedColorDark:"#d1d5db",resultGap:"8",resultBorderWidth:"0",resultPaddingX:"12",resultPaddingY:"12",resultBorderRadius:"8",triggerBg:"#ffffff",triggerBgDark:"#374151",triggerTextColor:"#374151",triggerTextColorDark:"#d1d5db",triggerBorderRadius:"8",triggerBorderWidth:"1",triggerBorderColor:"#d1d5db",triggerBorderColorDark:"#4b5563",triggerHoverBg:"#f9fafb",triggerHoverBgDark:"#4b5563",triggerHoverTextColor:"#111827",triggerHoverTextColorDark:"#f9fafb",triggerHoverBorderColor:"#3b82f6",triggerHoverBorderColorDark:"#60a5fa",triggerPaddingX:"12",triggerPaddingY:"8",triggerFontSize:"14",kbdBg:"#f3f4f6",kbdBgDark:"#4b5563",kbdTextColor:"#4b5563",kbdTextColorDark:"#e5e7eb",kbdBorderRadius:"4",backdropOpacity:"50",backdropBlur:"1",highlightEnabled:"1",highlightTag:"",highlightClass:"",highlightBgLight:"fef08a",highlightColorLight:"854d0e",highlightBgDark:"854d0e",highlightColorDark:"fef08a",iconColor:"#3b82f6",iconColorDark:"#60a5fa",promotedBg:"#2563eb",promotedBgDark:"#2563eb",promotedColor:"#ffffff",promotedColorDark:"#ffffff"};var fe={modalBg:"--sm-modal-bg",modalBgDark:"--sm-modal-bg-dark",modalBorderRadius:"--sm-modal-radius",modalBorderWidth:"--sm-modal-border-width",modalBorderColor:"--sm-modal-border-color",modalBorderColorDark:"--sm-modal-border-color-dark",modalShadow:"--sm-modal-shadow",modalShadowDark:"--sm-modal-shadow-dark",modalMaxWidth:"--sm-modal-width",modalMaxHeight:"--sm-modal-max-height",modalPaddingX:"--sm-modal-px",modalPaddingY:"--sm-modal-py",headerBg:"--sm-header-bg",headerBgDark:"--sm-header-bg-dark",headerBorderColor:"--sm-header-border-color",headerBorderColorDark:"--sm-header-border-color-dark",headerBorderWidth:"--sm-header-border-width",headerBorderRadius:"--sm-header-radius",headerPaddingX:"--sm-header-px",headerPaddingY:"--sm-header-py",inputBg:"--sm-input-bg",inputBgDark:"--sm-input-bg-dark",inputTextColor:"--sm-input-color",inputTextColorDark:"--sm-input-color-dark",inputPlaceholderColor:"--sm-input-placeholder",inputPlaceholderColorDark:"--sm-input-placeholder-dark",inputBorderColor:"--sm-input-border-color",inputBorderColorDark:"--sm-input-border-color-dark",inputFontSize:"--sm-input-font-size",inputBorderRadius:"--sm-input-radius",inputBorderWidth:"--sm-input-border-width",inputPaddingX:"--sm-input-px",inputPaddingY:"--sm-input-py",resultBg:"--sm-result-bg",resultBgDark:"--sm-result-bg-dark",resultBorderColor:"--sm-result-border-color",resultBorderColorDark:"--sm-result-border-color-dark",resultActiveBg:"--sm-result-active-bg",resultActiveBgDark:"--sm-result-active-bg-dark",resultActiveBorderColor:"--sm-result-active-border-color",resultActiveBorderColorDark:"--sm-result-active-border-color-dark",resultActiveTextColor:"--sm-result-active-text-color",resultActiveTextColorDark:"--sm-result-active-text-color-dark",resultActiveDescColor:"--sm-result-active-desc-color",resultActiveDescColorDark:"--sm-result-active-desc-color-dark",resultActiveMutedColor:"--sm-result-active-muted-color",resultActiveMutedColorDark:"--sm-result-active-muted-color-dark",resultTextColor:"--sm-result-text-color",resultTextColorDark:"--sm-result-text-color-dark",resultDescColor:"--sm-result-desc-color",resultDescColorDark:"--sm-result-desc-color-dark",resultMutedColor:"--sm-result-muted-color",resultMutedColorDark:"--sm-result-muted-color-dark",resultGap:"--sm-result-gap",resultBorderWidth:"--sm-result-border-width",resultPaddingX:"--sm-result-px",resultPaddingY:"--sm-result-py",resultBorderRadius:"--sm-result-radius",triggerBg:"--sm-trigger-bg",triggerBgDark:"--sm-trigger-bg-dark",triggerTextColor:"--sm-trigger-text-color",triggerTextColorDark:"--sm-trigger-text-color-dark",triggerBorderRadius:"--sm-trigger-radius",triggerBorderWidth:"--sm-trigger-border-width",triggerBorderColor:"--sm-trigger-border-color",triggerBorderColorDark:"--sm-trigger-border-color-dark",triggerHoverBg:"--sm-trigger-hover-bg",triggerHoverBgDark:"--sm-trigger-hover-bg-dark",triggerHoverTextColor:"--sm-trigger-hover-text-color",triggerHoverTextColorDark:"--sm-trigger-hover-text-color-dark",triggerHoverBorderColor:"--sm-trigger-hover-border-color",triggerHoverBorderColorDark:"--sm-trigger-hover-border-color-dark",triggerPaddingX:"--sm-trigger-px",triggerPaddingY:"--sm-trigger-py",triggerFontSize:"--sm-trigger-font-size",kbdBg:"--sm-kbd-bg",kbdBgDark:"--sm-kbd-bg-dark",kbdTextColor:"--sm-kbd-text-color",kbdTextColorDark:"--sm-kbd-text-color-dark",kbdBorderRadius:"--sm-kbd-radius",iconColor:"--sm-icon-color",iconColorDark:"--sm-icon-color-dark",highlightBgLight:"--sm-highlight-bg",highlightColorLight:"--sm-highlight-color",highlightBgDark:"--sm-highlight-bg-dark",highlightColorDark:"--sm-highlight-color-dark",promotedBg:"--sm-promoted-bg",promotedBgDark:"--sm-promoted-bg-dark",promotedColor:"--sm-promoted-color",promotedColorDark:"--sm-promoted-color-dark",spinnerColor:"--sm-spinner-color-light",spinnerColorDark:"--sm-spinner-color-dark"},Q=["modalBorderRadius","modalBorderWidth","modalMaxWidth","modalPaddingX","modalPaddingY","headerBorderWidth","headerBorderRadius","headerPaddingX","headerPaddingY","inputFontSize","inputBorderRadius","inputBorderWidth","inputPaddingX","inputPaddingY","resultGap","resultBorderWidth","resultPaddingX","resultPaddingY","resultBorderRadius","triggerBorderRadius","triggerBorderWidth","triggerPaddingX","triggerPaddingY","triggerFontSize","kbdBorderRadius"],J=["modalMaxHeight"],ye=["modalBg","modalBgDark","modalBorderColor","modalBorderColorDark","headerBg","headerBgDark","headerBorderColor","headerBorderColorDark","inputBg","inputBgDark","inputTextColor","inputTextColorDark","inputPlaceholderColor","inputPlaceholderColorDark","inputBorderColor","inputBorderColorDark","resultBg","resultBgDark","resultBorderColor","resultBorderColorDark","resultActiveBg","resultActiveBgDark","resultActiveBorderColor","resultActiveBorderColorDark","resultTextColor","resultTextColorDark","resultActiveTextColor","resultActiveTextColorDark","resultActiveDescColor","resultActiveDescColorDark","resultActiveMutedColor","resultActiveMutedColorDark","resultDescColor","resultDescColorDark","resultMutedColor","resultMutedColorDark","triggerBg","triggerBgDark","triggerTextColor","triggerTextColorDark","triggerBorderColor","triggerBorderColorDark","triggerHoverBg","triggerHoverBgDark","triggerHoverTextColor","triggerHoverTextColorDark","triggerHoverBorderColor","triggerHoverBorderColorDark","kbdBg","kbdBgDark","kbdTextColor","kbdTextColorDark","iconColor","iconColorDark","highlightBgLight","highlightColorLight","highlightBgDark","highlightColorDark","promotedBg","promotedBgDark","promotedColor","promotedColorDark","spinnerColor","spinnerColorDark"],Et={...be,highlightBgLight:"#fef08a",highlightColorLight:"#854d0e",highlightBgDark:"#854d0e",highlightColorDark:"#fef08a"};function rt(e){return typeof e=="string"&&/^(var|light-dark|calc|env|clamp|min|max|rgb|hsl)\s*\(/.test(e.trim())}function nt(e){return/^[0-9a-fA-F]{6}$/.test(e)}function ot(e,t){if(t==null||t==="")return null;let r=String(t);return rt(r)||(ye.includes(e)&&nt(r)&&(r="#"+r),Q.includes(e)&&(r=r+"px"),J.includes(e)&&(r=r+"vh")),r}function ve(e,t,r="light"){if(!t||typeof t!="object")return;let n=r==="dark",o=Object.entries(fe),s=new Set([...Q,...J]);for(let[a,l]of o){let i=a.endsWith("Dark");if(n){if(!i&&!s.has(a))continue}else if(i)continue;if(t[a]!==void 0&&t[a]!==null&&t[a]!==""){let d=ot(a,t[a]);d&&e.style.setProperty(l,d)}}}function h(e){return e?String(e).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"):""}function xe(e){return e?e.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"):""}function Z(e){if(!e)return[];let t=[],r=/"([^"]+)"/g,n;for(;(n=r.exec(e))!==null;)n[1].trim()&&t.push(n[1].trim());let o=e.replace(/"[^"]*"/g,""),s=new Set(["and","or","not","und","oder","nicht","et","ou","sauf","y","o","no"]);o.split(/\s+/).filter(l=>l.length>0).forEach(l=>{l=l.replace(/^[a-zA-Z]+:/,""),l=l.replace(/\*/g,""),l=l.replace(/\^\d+(\.\d+)?/,""),l=l.replace(/"/g,""),!(!l||s.has(l.toLowerCase()))&&t.push(l)});let a=[];return t.forEach(l=>{a.push(l);let i=l.split(/(?<=[a-z])(?=[A-Z])/);i.length>1&&i.forEach(d=>{d.length>=3&&a.push(d)})}),a}function $(e,t,r={}){let{enabled:n=!0,tag:o="mark",className:s="",terms:a=null}=r;if(!n)return h(e);let l=["sm-highlight"];s&&l.push(s);let i=` class="${l.join(" ")}"`,d=st(t,a);return d.length===0?h(e):at(e,d,o,i)}function st(e,t){return Array.isArray(t)&&t.length>0?ke(t):e?ke(Z(e)):[]}function ke(e){let t=new Set;return e.filter(r=>typeof r=="string"&&r.length>0).sort((r,n)=>n.length-r.length).filter(r=>{let n=r.toLowerCase();return t.has(n)?!1:(t.add(n),!0)})}function at(e,t,r,n){let o=e.toLowerCase(),s=[];if(t.forEach(c=>{let u=c.toLowerCase();if(!u)return;let m=0;for(;m<o.length;){let g=o.indexOf(u,m);if(g===-1)break;s.push({start:g,end:g+u.length}),m=g+u.length}}),s.length===0)return h(e);s.sort((c,u)=>c.start!==u.start?c.start-u.start:u.end-u.start-(c.end-c.start));let a=[],l=-1;s.forEach(c=>{c.start>=l&&(a.push(c),l=c.end)});let i="",d=0;return a.forEach(c=>{d<c.start&&(i+=h(e.slice(d,c.start))),i+=`<${r}${n}>${h(e.slice(c.start,c.end))}</${r}>`,d=c.end}),d<e.length&&(i+=h(e.slice(d))),i}function N(e,t,r="smq"){if(!e||e==="#")return e;if(it(e))return"#";let n=(t||"").trim();if(!n||!r||/^(mailto:|tel:)/i.test(e))return e;let[o,s]=e.split("#",2),[a,l]=o.split("?",2),i=new URLSearchParams(l||"");i.set(r,n);let d=i.toString(),c=s?`#${s}`:"";return`${a}${d?`?${d}`:""}${c}`}function it(e){let t=String(e).replace(/[\t\n\r]/g,"").replace(/^[\u0000-\u0020]+/,"");return/^(javascript|data|vbscript):/i.test(t)}var lt=0;function ee(e="sm"){return`${e}-${++lt}-${Date.now().toString(36)}`}function we(e){let t=document.createElement("div");return t.setAttribute("role","status"),t.setAttribute("aria-live","polite"),t.setAttribute("aria-atomic","true"),t.className="sm-sr-only",e.appendChild(t),t}function U(e,t,r=100){e&&(e.textContent="",setTimeout(()=>{e.textContent=t},r))}function te(e,t){return e===0?`No results found for "${t}"`:e===1?`1 result found for "${t}"`:`${e} results found for "${t}"`}function Ce(){return"Searching..."}function Se(e){return e===0?"No recent searches":e===1?"1 recent search available":`${e} recent searches available`}function W(e,{expanded:t,activeDescendant:r,listboxId:n}){e.setAttribute("aria-expanded",String(t)),e.setAttribute("aria-controls",n),r?e.setAttribute("aria-activedescendant",r):e.removeAttribute("aria-activedescendant")}function L(e,t){return`${e}-option-${t}`}function Te(e,t){if(!e||!t)return;let r=e.getBoundingClientRect(),n=t.getBoundingClientRect();r.top<n.top?e.scrollIntoView({block:"nearest",behavior:"smooth"}):r.bottom>n.bottom&&e.scrollIntoView({block:"nearest",behavior:"smooth"})}function dt(e,t,r={}){let{groupResults:n=!1,resultLayout:o="default",listboxId:s}=r;if(!e||e.length===0)return"";if(o==="hierarchical")return gt(e,t,r);if(n){let a=ue(e),l=0;return Object.entries(a).map(([i,d])=>`
            <div class="sm-section" role="group" aria-label="${h(i)}">
                <div class="sm-section-header">${h(i)}</div>
                ${d.map(c=>Ae(c,l++,t,r)).join("")}
            </div>
        `).join("")}return e.map((a,l)=>Ae(a,l,t,r)).join("")}function Ae(e,t,r,n={}){let{listboxId:o,enableHighlighting:s=!0,highlightTag:a="mark",highlightClass:l="",groupResults:i=!1,promotions:d={},debug:c=!1,persistQueryInUrl:u=!1,queryParamName:m="smq"}=n,g=e.title||e.name||"Untitled",v=e.description||e.excerpt||e.snippet||"",k=e.url||e.href||"#",p=N(k,r,u?m:""),b=e.section||e.type||"",f=L(o,t),x=e.promoted===!0,E={enabled:s,tag:a,className:l},w=$(g,r,{...E,terms:F(e,"title")}),A=v?$(v,r,{...E,terms:F(e,"description")}):"",H=ct(e,d),I=x?" sm-promoted":"",B=b&&!i?`<span class="sm-result-type">${h(b)}</span>`:"",C=c?Re(e):"";return c?`
            <a class="sm-result-item sm-debug-enabled${I}" id="${f}" role="option" aria-selected="false" href="${h(p)}" data-index="${t}" data-id="${h(e.id||"")}" data-title="${h(g)}">
                <div class="sm-result-main">
                    ${H}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${w}</span>
                        ${A?`<span class="sm-result-desc">${A}</span>`:""}
                    </div>
                    ${B}
                    ${P()}
                </div>
                ${C}
            </a>
        `:`
        <a class="sm-result-item${I}" id="${f}" role="option" aria-selected="false" href="${h(p)}" data-index="${t}" data-id="${h(e.id||"")}" data-title="${h(g)}">
            ${H}
            <div class="sm-result-content">
                <span class="sm-result-title">${w}</span>
                ${A?`<span class="sm-result-desc">${A}</span>`:""}
            </div>
            ${B}
            ${P()}
        </a>
    `}function Re(e){let t=[],r=e.backend?e.backend.toLowerCase():"";if((e._index||e.index)&&t.push(T("index",e._index||e.index,"index")),e.backend&&t.push(T("backend",r,"backend",r)),e.id&&t.push(T("id",e.id,"generic")),e.score!==void 0&&e.score!==null){let n=typeof e.score=="number"?e.score.toFixed(2):e.score;t.push(T("score",n,"score"))}if(e.site&&t.push(T("site",e.site,"generic")),e.language&&t.push(T("lang",e.language,"generic")),e.matchedIn&&Array.isArray(e.matchedIn)&&e.matchedIn.length>0){let n=e.matchedIn.join(", ");t.push(T("matched",n,"matched"))}return e.promoted&&t.push(T("promoted","yes","promoted")),e.boosted&&t.push(T("boosted","yes","boosted")),t.length===0?"":`<div class="sm-debug-info">${t.join("")}</div>`}function F(e,t){let r=Array.isArray(e.matchedPhrases)?e.matchedPhrases:[],n=e.matchedTerms,o=[];n&&(t==="title"&&Array.isArray(n.title)&&n.title.length>0?o=n.title:t==="description"&&Array.isArray(n.content)&&n.content.length>0?o=n.content:o=[...Array.isArray(n.title)?n.title:[],...Array.isArray(n.content)?n.content:[]]);let s=[...r,...o];return s.length>0?s:null}function T(e,t,r,n=""){let o=n?` data-backend="${h(n)}"`:"";return`<span class="sm-debug-item"><span class="sm-debug-label">${h(e)}</span><span class="sm-debug-value" data-type="${h(r)}"${o}>${h(String(t))}</span></span>`}function ct(e,t={}){let{showBadge:r=!0,badgeText:n="Featured",badgePosition:o="top-right"}=t;return!e.promoted||!r?"":`<span class="sm-promoted-badge ${`sm-promoted-badge--${o}`}">${h(n)}</span>`}function P(){return`<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>`}function ht(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
    </svg>`}function ut(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <line x1="4" y1="7" x2="20" y2="7"/>
        <line x1="4" y1="12" x2="20" y2="12"/>
        <line x1="4" y1="17" x2="14" y2="17"/>
    </svg>`}function De(){return`<svg class="sm-hierarchy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="9" x2="20" y2="9"/>
        <line x1="4" y1="15" x2="20" y2="15"/>
        <line x1="10" y1="3" x2="8" y2="21"/>
        <line x1="16" y1="3" x2="14" y2="21"/>
    </svg>`}function gt(e,t,r={}){let{hierarchyGroupBy:n="section",hierarchyStyle:o="tree",hierarchyDisplay:s="individual",maxHeadingsPerResult:a=3,listboxId:l}=r,i=o==="tree",d=o!=="none",u=ge(e,n||"section"),m=0;return Object.entries(u).map(([g,v])=>{let k=v.map(p=>{let b=m++,f=mt(p,b,t,r),x="",w=(p._matchedHeadings||[]).slice(0,a);if(w.length>0){let I=Math.min(...w.map(C=>C.level||2)),B=w.map(C=>i?(C.level||2)-I:0);x=w.map((C,M)=>{let O=B[M],q=!B.slice(M+1).some(K=>K===O),R=[];if(i){let K=B.slice(M+1);for(let _=0;_<O;_++)K.some(Ue=>Ue===_)&&R.push(_)}return pt(p,C,m++,t,r,q,O,R)}).join("")}let A=!!x;return`
                <div class="sm-hierarchy-block${A?" sm-hierarchy-block--has-children":""}${s==="unified"?" sm-hierarchy-block--unified":""}">
                    ${A?f.replace("sm-result-item sm-hierarchy-parent","sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children"):f}
                    ${A?`<div class="sm-hierarchy-children${d?"":" sm-hierarchy-children--no-connectors"}">${x}</div>`:""}
                </div>
            `}).join("");return`
            <div class="sm-hierarchy-group" role="group" aria-label="${h(g)}">
                <div class="sm-hierarchy-group-header">${h(g)}</div>
                ${k}
            </div>
        `}).join("")}function mt(e,t,r,n={}){let{listboxId:o,enableHighlighting:s=!0,highlightTag:a="mark",highlightClass:l="",debug:i=!1,persistQueryInUrl:d=!1,queryParamName:c="smq"}=n,u=e.title||e.name||"Untitled",m=e.description||e.excerpt||"",g=e.url||"#",v=N(g,r,d?c:""),k=L(o,t),p={enabled:s,tag:a,className:l},b=$(u,r,{...p,terms:F(e,"title")}),f=m?$(m,r,{...p,terms:F(e,"description")}):"",x=i?Re(e):"",w=e._matchedHeadings&&e._matchedHeadings.length>0?ht():ut();return i?`
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled" id="${k}" role="option" aria-selected="false" href="${h(v)}" data-index="${t}" data-id="${h(e.id||"")}" data-title="${h(u)}">
                <div class="sm-result-main">
                    ${w}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${b}</span>
                        ${f?`<span class="sm-result-desc">${f}</span>`:""}
                    </div>
                    ${P()}
                </div>
                ${x}
            </a>
        `:`
        <a class="sm-result-item sm-hierarchy-parent" id="${k}" role="option" aria-selected="false" href="${h(v)}" data-index="${t}" data-id="${h(e.id||"")}" data-title="${h(u)}">
            ${w}
            <div class="sm-result-content">
                <span class="sm-result-title">${b}</span>
                ${f?`<span class="sm-result-desc">${f}</span>`:""}
            </div>
            ${P()}
        </a>
    `}function pt(e,t,r,n,o={},s=!1,a=0,l=[]){let{listboxId:i,enableHighlighting:d=!0,highlightTag:c="mark",highlightClass:u="",debug:m=!1,persistQueryInUrl:g=!1,queryParamName:v="smq"}=o,p=(t.text||"").replace(/^#+\s*/,""),b=t.description||"",f=t.level||2,x=t.id||(p?bt(p):""),E=e.url||"#",w=x?`${E}#${x}`:E,A=N(w,n,g?v:""),H=L(i,r),I={enabled:d,tag:c,className:u},B=$(p,n,{...I,terms:F(e,"title")}),C=b?$(b,n,{...I,terms:F(e,"description")}):"",M=s?" sm-hierarchy-child-row-last":"",O=l.map(R=>`<div class="sm-hierarchy-guide" style="--sm-guide-depth:${R}" aria-hidden="true"></div>`).join(""),q="";if(m){let R=[];R.push(T("h",f,"generic")),x&&R.push(T("anchor",x,"generic")),e.id&&R.push(T("parent",e.id,"generic")),q=`<div class="sm-debug-info">${R.join("")}</div>`}return m?`
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${f} sm-hierarchy-depth-${a}${M}" style="--sm-hierarchy-depth:${a}">
                ${O}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${f} sm-debug-enabled" id="${H}" role="option" aria-selected="false" href="${h(A)}" data-index="${r}" data-id="${h(e.id||"")}" data-title="${h(p)}">
                    <div class="sm-result-main">
                        ${De()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${B}</span>
                            ${C?`<span class="sm-result-desc">${C}</span>`:""}
                        </div>
                        ${P()}
                    </div>
                    ${q}
                </a>
            </div>
        `:`
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${f} sm-hierarchy-depth-${a}${M}" style="--sm-hierarchy-depth:${a}">
            ${O}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${f}" id="${H}" role="option" aria-selected="false" href="${h(A)}" data-index="${r}" data-id="${h(e.id||"")}" data-title="${h(p)}">
                ${De()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${B}</span>
                    ${C?`<span class="sm-result-desc">${C}</span>`:""}
                </div>
                ${P()}
            </a>
        </div>
    `}function bt(e){let t=e.normalize("NFKD").toLowerCase();try{return t.replace(/[^\p{L}\p{N}]+/gu,"-").replace(/^-+|-+$/g,"")}catch{return t.replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"")}}function ft(e,t){return!e||e.length===0?"":`
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${t}-recent-label">Recent searches</span>
                <button class="sm-clear-recent" part="clear-recent">Clear</button>
            </div>
            ${e.map((r,n)=>`
                <div class="sm-result-item sm-recent-item" id="${L(t,n)}" role="option" aria-selected="false" data-index="${n}" data-url="${h(r.url||"")}" data-query="${h(r.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${h(r.title||r.query)}</span>
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
    `}function yt(){return`
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>Searching...</p>
        </div>
    `}function vt(e){return`
        <div class="sm-empty sm-error" part="error">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>${h(e||"Search failed.")}</p>
        </div>
    `}function Ee(e,t){let{query:r,results:n,recentSearches:o,loading:s,showRecent:a,error:l}=e,{showLoadingIndicator:i=!0}=t,d=r&&r.trim();return s&&i?{html:yt(),hasResults:!1,showListbox:!1}:l?{html:vt(l),hasResults:!1,showListbox:!1}:d?!n||n.length===0?{html:Be(r),hasResults:!1,showListbox:!1}:{html:dt(n,r,t),hasResults:!0,showListbox:!0}:a&&o&&o.length>0?{html:ft(o,t.listboxId),hasResults:!0,showListbox:!0}:{html:Be(""),hasResults:!1,showListbox:!1}}function re(e,t,r=!1){if(!e)return"";let n=[];if(n.push(D("results",t,"generic")),e.took!==void 0){let i=e.took<1?"<1ms":`${Math.round(e.took)}ms`;n.push(D("time",i,"time"))}if(e.cacheEnabled!==void 0&&(e.cacheEnabled?e.cached?n.push(D("cache","hit","cache-hit")):n.push(D("cache","miss","cache-miss")):n.push(D("cache","off","cache-off"))),e.cacheDriver&&n.push(D("storage",e.cacheDriver,"cache-driver",e.cacheDriver)),e.indices&&e.indices.length>0){let i=e.indices.length>2?`${e.indices.length} indices`:e.indices.join(", ");n.push(D("indices",i,"generic"))}if(e.synonymsExpanded){let i=e.expandedQueries?e.expandedQueries.length-1:0;n.push(D("synonyms",`+${i}`,"synonyms"))}let o=e.rulesMatched?.length||0;n.push(D("rules",o,o>0?"rules":"generic"));let s=e.promotionsMatched?.length||0;n.push(D("promoted",s,s>0?"promotions":"generic"));let l=`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${r?'<path d="M6 9l6 6 6-6"/>':'<path d="M18 15l-6-6-6 6"/>'}</svg>`;return r?`<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">Debug</span>${l}</div>`:`<div class="sm-toolbar-content">${n.join("")}</div><button class="sm-toolbar-toggle" aria-label="Collapse debug panel" aria-expanded="true">${l}</button>`}function D(e,t,r,n=""){let o=n?` data-backend="${h(n)}"`:"";return`<span class="sm-toolbar-item"><span class="sm-toolbar-label">${h(e)}</span><span class="sm-toolbar-value" data-type="${h(r)}"${o}>${h(String(t))}</span></span>`}function Ie(e,t){let{onSelect:r,onIndexChange:n,onEscape:o}=e,{listboxId:s}=t;return{handleKeydown(a,l,i){let d=i;switch(a.key){case"ArrowDown":return a.preventDefault(),d=Math.min(i+1,l-1),d!==i&&n&&n(d),d;case"ArrowUp":return a.preventDefault(),d=Math.max(i-1,-1),d!==i&&n&&n(d),d;case"Enter":return a.preventDefault(),i>=0&&r&&r(i),null;case"Escape":return a.preventDefault(),o&&o(),null;default:return null}},getListboxId(){return s}}}function $e(e,t,r={}){let{scrollContainer:n,inputElement:o,listboxId:s,selectedClass:a="sm-selected"}=r,l=t>=0?L(s,t):null;o&&W(o,{expanded:e.length>0,activeDescendant:l,listboxId:s}),e.forEach((i,d)=>{let c=d===t;i.classList.toggle(a,c),i.setAttribute("aria-selected",String(c)),c&&n&&Te(i,n)})}function Le(e,t){e.forEach((r,n)=>{r.addEventListener("mouseenter",()=>{t&&t(n)})})}var Pe="sm-page-highlight-style",He="__smPageHighlightRegistry",ne=class extends HTMLElement{constructor(){super(),this.attachShadow({mode:"open"}),this.config=null,this.state=le({...j},this.handleStateChange.bind(this)),this.searchSequence=0,this.debounceTimer=null,this.analyticsIdleTimer=null,this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.listboxId=ee("sm-listbox"),this.inputId=ee("sm-input"),this.liveRegion=null,this.keyboardNavigator=null,this.elements={},this.handleInput=this.handleInput.bind(this),this.handleKeydown=this.handleKeydown.bind(this),this.handleResultClick=this.handleResultClick.bind(this)}get widgetType(){throw new Error("Subclass must implement widgetType getter")}render(){throw new Error("Subclass must implement render()")}getResultsContainer(){throw new Error("Subclass must implement getResultsContainer()")}getInputElement(){throw new Error("Subclass must implement getInputElement()")}getLoadingElement(){return this.elements.loading||null}getDebugToolbarElement(){return this.elements.debugToolbar||null}connectedCallback(){this.config=V(this,this.widgetType),this.state.set({recentSearches:G(this.config.index)}),this.keyboardNavigator=Ie({onSelect:t=>this.selectResultAtIndex(t),onIndexChange:t=>this.state.set({selectedIndex:t}),onEscape:()=>this.handleEscape()},{listboxId:this.listboxId}),this.applyDestinationPageHighlight()}disconnectedCallback(){this.searchSequence++,this.debounceTimer&&(clearTimeout(this.debounceTimer),this.debounceTimer=null)}attributeChangedCallback(t,r,n){r!==n&&this.shadowRoot.children.length>0&&(this.config=V(this,this.widgetType),this.render(),this.applyCustomStyles())}handleStateChange(t,r){(r.includes("results")||r.includes("query")||r.includes("recentSearches")||r.includes("error"))&&this.renderResultsContent(),(r.includes("results")||r.includes("meta"))&&this.updateDebugToolbar(),r.includes("selectedIndex")&&this.updateSelectionVisual(),r.includes("loading")&&this.updateLoadingVisual()}handleInput(t){let r=t.target.value;if(this.state.set({query:r,selectedIndex:-1}),this.debounceTimer&&clearTimeout(this.debounceTimer),this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),!r.trim()){this.state.set({results:[]});return}r.length<this.config.minChars||(this.debounceTimer=setTimeout(()=>{this.executeSearch(r)},this.config.debounce))}async executeSearch(t){let r=++this.searchSequence;this.state.set({loading:!0,error:null}),this.liveRegion&&U(this.liveRegion,Ce());try{let{results:n,meta:o}=await de({query:t,endpoint:this.config.searchEndpoint,indices:this.config.indices,siteId:this.config.siteId,maxResults:this.config.maxResults,hideResultsWithoutUrl:this.config.hideResultsWithoutUrl,showCodeSnippets:this.config.showCodeSnippets,snippetMode:this.config.snippetMode,snippetLength:this.config.snippetLength,parseMarkdownSnippets:this.config.parseMarkdownSnippets,debug:this.config.debug,apiKey:this.config.apiKey});if(r!==this.searchSequence)return;this.state.set({results:n,meta:o,loading:!1,selectedIndex:n.length>0?0:-1}),o&&typeof o.cached=="boolean"?this.lastSearchCacheState={cached:o.cached,took:typeof o.took=="number"?o.took:null}:this.lastSearchCacheState=null,this.liveRegion&&U(this.liveRegion,te(n.length,t)),this.dispatchWidgetEvent("search",{query:t,results:n,meta:o}),this.startAnalyticsIdleTimer(t,n.length)}catch(n){if(r!==this.searchSequence||n.name==="AbortError")return;console.error("Search error:",n),this.state.set({results:[],loading:!1,error:n.message}),this.dispatchWidgetEvent("error",{query:t,error:n.message})}}renderResultsContent(){let t=this.getResultsContainer();if(!t)return;let r=this.state.getAll(),{showRecent:n,groupResults:o,enableHighlighting:s,highlightTag:a,highlightClass:l,showLoadingIndicator:i,debug:d}=this.config,{html:c,hasResults:u,showListbox:m}=Ee({query:r.query,results:r.results,recentSearches:r.recentSearches,loading:r.loading,error:r.error,showRecent:n},{listboxId:this.listboxId,groupResults:o,enableHighlighting:s,highlightTag:a,highlightClass:l,showLoadingIndicator:i,debug:d,persistQueryInUrl:this.config.highlightDestinationPage&&this.config.persistQueryInUrl,queryParamName:this.config.queryParamName,promotions:this.config.promotions,resultLayout:this.config.resultLayout,hierarchyGroupBy:this.config.hierarchyGroupBy,hierarchyStyle:this.config.hierarchyStyle,hierarchyDisplay:this.config.hierarchyDisplay,maxHeadingsPerResult:this.config.maxHeadingsPerResult});t.innerHTML=c,m?t.setAttribute("role","listbox"):t.removeAttribute("role");let g=this.getInputElement();g&&W(g,{expanded:u,activeDescendant:null,listboxId:this.listboxId}),this.liveRegion&&!r.loading&&(r.query&&r.results.length===0?U(this.liveRegion,te(0,r.query)):!r.query&&r.recentSearches.length>0&&n&&U(this.liveRegion,Se(r.recentSearches.length))),this.attachResultHandlers();let v=t.querySelector(".sm-clear-recent");v&&v.addEventListener("click",k=>{k.stopPropagation(),pe(this.config.index),this.state.set({recentSearches:[]})}),u&&r.results.length>0&&this.state.set({selectedIndex:0})}attachResultHandlers(){let t=this.getResultsContainer();if(!t)return;let r=t.querySelectorAll(".sm-result-item");r.forEach(n=>{n.addEventListener("click",o=>this.handleResultClick(o,n))}),Le(r,n=>{this.state.set({selectedIndex:n})})}updateSelectionVisual(){let t=this.getResultsContainer(),r=this.getInputElement();if(!t)return;let n=t.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");$e(n,o,{scrollContainer:t,inputElement:r,listboxId:this.listboxId})}handleKeydown(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");if(t.key==="Enter"){let s=this.state.get("query"),a=this.state.get("results")||[];s&&a.length>0&&this.trackSearchAnalytics(s,a.length,"enter")}this.keyboardNavigator.handleKeydown(t,n.length,o)}selectResultAtIndex(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item");t>=0&&n[t]&&n[t].click()}handleEscape(){}handleResultClick(t,r){let n=r.getAttribute("href"),o=r.dataset.url,s=n||o,a=r.dataset.title||r.querySelector(".sm-result-title")?.textContent,l=r.dataset.id,i=r.dataset.query||this.state.get("query"),d=r.classList.contains("sm-recent-item"),c=N(s,i,this.config.highlightDestinationPage&&this.config.persistQueryInUrl?this.config.queryParamName:"");if(!d&&i){let u=me(this.config.index,i,{title:a,url:s},this.config.maxRecentSearches);this.state.set({recentSearches:u})}if(l&&this.config.index&&ce({endpoint:this.config.trackClickEndpoint,elementId:l,query:i,index:this.config.index,apiKey:this.config.apiKey}),!d&&i&&this.trackSearchAnalytics(i,this.state.get("results")?.length||0,"click"),this.dispatchWidgetEvent("result-click",{id:l,title:a,url:c,query:i,isRecent:d}),s&&s!=="#")d&&(t.preventDefault(),window.location.href=c),this.onResultSelected(c,a,l);else if(i){t.preventDefault();let u=this.getInputElement();u&&(u.value=i,this.state.set({query:i}),this.executeSearch(i))}}onResultSelected(t,r,n){}applyDestinationPageHighlight(){if(!this.config.highlightDestinationPage||typeof window>"u"||typeof document>"u")return;let t=this.config.queryParamName||"smq",r=this.config.destinationHighlightSelector||"main, article, [data-search-content]",n=new URLSearchParams(window.location.search).get(t);if(!n||!n.trim())return;let o=this.getPageHighlightRegistry(),s=`${t}::${r}`;if(o.has(s))return;o.add(s);let a=()=>{this.ensurePageHighlightStyles(),this.highlightDestinationNodes(n.trim(),r,s)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",a,{once:!0}):window.requestAnimationFrame(a)}ensurePageHighlightStyles(){if(document.getElementById(Pe))return;let t=document.createElement("style");t.id=Pe,t.textContent=`
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `,document.head.appendChild(t)}highlightDestinationNodes(t,r,n){let o=Array.from(document.querySelectorAll(r));if(o.length===0)return;let s=[...new Set(Z(t).map(i=>i.trim()).filter(i=>i.length>=2))];if(s.length===0)return;let a=s.map(i=>xe(i)).filter(Boolean).sort((i,d)=>d.length-i.length).join("|");if(!a)return;let l=new RegExp(`(${a})`,"gi");o.forEach(i=>{i.getAttribute("data-sm-highlighted")!==n&&(this.highlightTextNodesInScope(i,l),i.setAttribute("data-sm-highlighted",n))})}highlightTextNodesInScope(t,r){let n=document.createTreeWalker(t,NodeFilter.SHOW_TEXT,{acceptNode:s=>{let a=s.nodeValue;if(!a||!a.trim())return NodeFilter.FILTER_REJECT;let l=s.parentElement;return!l||l.closest("script, style, noscript, textarea, code, pre, mark, .sm-highlight, .sm-page-highlight, search-modal")?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT}}),o=[];for(;n.nextNode();)o.push(n.currentNode);o.forEach(s=>{let a=s.nodeValue||"";if(r.lastIndex=0,!r.test(a))return;let l=document.createDocumentFragment(),i=0;r.lastIndex=0;let d=a.matchAll(r);for(let c of d){let u=c[0],m=c.index??-1;if(m<0)continue;m>i&&l.appendChild(document.createTextNode(a.slice(i,m)));let g=document.createElement("mark");g.className="sm-highlight sm-page-highlight",g.textContent=u,l.appendChild(g),i=m+u.length}i<a.length&&l.appendChild(document.createTextNode(a.slice(i))),s.parentNode?.replaceChild(l,s)})}getPageHighlightRegistry(){let t=window[He];if(t instanceof Set)return t;let r=new Set;return window[He]=r,r}updateLoadingVisual(){let t=this.getLoadingElement();if(t){let r=this.state.get("loading"),n=this.config?.showLoadingIndicator!==!1;t.hidden=!r||!n}}updateDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let{debug:r}=this.config,n=this.state.getAll();if(!r||!n.meta||n.results.length===0){t.hidden=!0;return}let o=t.classList.contains("sm-collapsed");t.innerHTML=re(n.meta,n.results.length,o),t.hidden=!1,o&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}attachDebugToolbarHandlers(t){let r=t.querySelector(".sm-toolbar-toggle");r&&r.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()});let n=t.querySelector(".sm-toolbar-collapsed-bar");n&&n.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()})}toggleDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let r=t.classList.toggle("sm-collapsed"),n=this.state.getAll();t.innerHTML=re(n.meta,n.results.length,r),r&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}applyCustomStyles(){if(!this.config)return;let t=this.shadowRoot.host,{theme:r,styles:n,resultTitleLines:o,resultDescLines:s}=this.config;ve(t,n,r),o&&t.style.setProperty("--sm-result-title-lines",String(o)),s&&t.style.setProperty("--sm-result-desc-lines",String(s))}initializeLiveRegion(){this.liveRegion=we(this.shadowRoot)}startAnalyticsIdleTimer(t,r){this.analyticsIdleTimer&&clearTimeout(this.analyticsIdleTimer);let n=this.config.idleTimeout;!n||n<=0||(this.analyticsIdleTimer=setTimeout(()=>{this.trackSearchAnalytics(t,r,"idle")},n))}trackSearchAnalytics(t,r,n){!t||t===this.lastTrackedQuery||(this.lastTrackedQuery=t,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),he({endpoint:this.config.trackSearchEndpoint,query:t,indices:this.config.indices,resultsCount:r,trigger:n,source:this.config.source,siteId:this.config.siteId,cached:this.lastSearchCacheState?.cached,took:this.lastSearchCacheState?.took,apiKey:this.config.apiKey}))}resetAnalyticsTracking(){this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null)}dispatchWidgetEvent(t,r={}){this.dispatchEvent(new CustomEvent(`search-${t}`,{bubbles:!0,composed:!0,detail:r}))}},Me=ne;var Oe=`/**
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
`;var kt=Oe+`
`+Ne+`
`+Fe,oe=class extends Me{constructor(){super(),this.externalTrigger=null,this.open=this.open.bind(this),this.close=this.close.bind(this),this.toggle=this.toggle.bind(this),this.handleGlobalKeydown=this.handleGlobalKeydown.bind(this),this.handleBackdropClick=this.handleBackdropClick.bind(this)}get widgetType(){return"modal"}static get observedAttributes(){return ie("modal")}connectedCallback(){super.connectedCallback(),this.render(),this.attachEventListeners()}disconnectedCallback(){super.disconnectedCallback(),this.detachEventListeners()}render(){let{theme:t,placeholder:r,showTrigger:n}=this.config;this.shadowRoot.innerHTML=`
            <style>${kt}</style>

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
                            placeholder="${r}"
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
        `,this.elements={trigger:this.shadowRoot.querySelector(".sm-trigger"),backdrop:this.shadowRoot.querySelector(".sm-backdrop"),modal:this.shadowRoot.querySelector(".sm-modal"),input:this.shadowRoot.querySelector(".sm-input"),results:this.shadowRoot.querySelector(".sm-results"),loading:this.shadowRoot.querySelector(".sm-loading"),close:this.shadowRoot.querySelector(".sm-close"),debugToolbar:this.shadowRoot.querySelector(".sm-debug-toolbar")},this.initializeLiveRegion(),this.shadowRoot.host.setAttribute("data-theme",t),this.applyCustomStyles()}getResultsContainer(){return this.elements.results}getInputElement(){return this.elements.input}getLoadingElement(){return this.elements.loading}applyCustomStyles(){if(super.applyCustomStyles(),!this.config)return;let{backdropOpacity:t,enableBackdropBlur:r}=this.config,n=this.shadowRoot.host;n.style.setProperty("--sm-backdrop-opacity",t/100),n.style.setProperty("--sm-backdrop-blur",r?"blur(4px)":"none")}attachEventListeners(){this.elements.trigger.addEventListener("click",this.toggle),this.elements.close.addEventListener("click",this.close),this.elements.backdrop.addEventListener("click",this.handleBackdropClick),this.elements.input.addEventListener("input",this.handleInput),this.elements.input.addEventListener("keydown",this.handleKeydown),document.addEventListener("keydown",this.handleGlobalKeydown);let{triggerSelector:t}=this.config;t&&(this.externalTrigger=document.querySelector(t),this.externalTrigger&&this.externalTrigger.addEventListener("click",this.toggle))}detachEventListeners(){document.removeEventListener("keydown",this.handleGlobalKeydown),this.externalTrigger&&(this.externalTrigger.removeEventListener("click",this.toggle),this.externalTrigger=null)}open(){this.state.set({isOpen:!0}),this.elements.backdrop.hidden=!1,this.elements.input.value="",this.state.set({query:"",results:[],selectedIndex:-1}),this.renderResultsContent(),requestAnimationFrame(()=>{this.elements.input.focus()}),this.config.preventBodyScroll&&(document.body.style.overflow="hidden"),this.dispatchWidgetEvent("open",{source:"programmatic"})}close(){this.state.set({isOpen:!1}),this.elements.backdrop.hidden=!0,this.config.preventBodyScroll&&(document.body.style.overflow=""),this.resetAnalyticsTracking(),this.dispatchWidgetEvent("close")}toggle(){this.state.get("isOpen")?this.close():this.open()}handleGlobalKeydown(t){let r=this.config.hotkey.toLowerCase();(navigator.platform.toUpperCase().indexOf("MAC")>=0?t.metaKey:t.ctrlKey)&&t.key.toLowerCase()===r&&(t.preventDefault(),this.toggle()),t.key==="Escape"&&this.state.get("isOpen")&&(t.preventDefault(),this.close())}handleEscape(){this.close()}handleBackdropClick(t){t.target===this.elements.backdrop&&this.close()}onResultSelected(t,r,n){this.close()}getHotkeyDisplay(){let t=navigator.platform.toUpperCase().indexOf("MAC")>=0,r=this.config.hotkey.toUpperCase();return t?`\u2318${r}`:`Ctrl+${r}`}},xt=oe;return We(wt);})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
