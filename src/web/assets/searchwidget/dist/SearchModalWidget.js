"use strict";var SearchModalWidget=(()=>{var ne=Object.defineProperty;var tt=Object.getOwnPropertyDescriptor;var rt=Object.getOwnPropertyNames;var nt=Object.prototype.hasOwnProperty;var st=(e,t)=>{for(var r in t)ne(e,r,{get:t[r],enumerable:!0})},ot=(e,t,r,n)=>{if(t&&typeof t=="object"||typeof t=="function")for(let s of rt(t))!nt.call(e,s)&&s!==r&&ne(e,s,{get:()=>t[s],enumerable:!(n=tt(t,s))||n.enumerable});return e};var at=e=>ot(ne({},"__esModule",{value:!0}),e);var Kt={};st(Kt,{default:()=>Ut});var it={indexHandles:[],placeholder:"Search...",theme:"light",resultsLimit:20,searchDebounceMs:200,searchMinChars:2,recentSearchesEnabled:!0,recentSearchesLimit:5,resultsGroupingEnabled:!0,siteId:"",apiKey:"",searchEndpoint:"/actions/search-manager/api/search",trackClickEndpoint:"/actions/search-manager/search/track-click",trackSearchEndpoint:"/actions/search-manager/search/track-search",analyticsIdleTimeoutMs:1500,analyticsSource:"",highlightResultsEnabled:!0,highlightTag:"mark",highlightClass:"",resultsRequireUrl:!1,snippetIncludeCodeBlocks:!1,snippetMode:"balanced",loadingIndicatorEnabled:!0,debugEnabled:!1,resultsTitleLines:1,resultsDescriptionLines:1,snippetMaxLength:150,snippetCleanMarkdown:!1,highlightDestinationPersistQuery:!0,highlightDestinationQueryParam:"smq",highlightDestinationEnabled:!0,highlightDestinationContentSelector:"main, article, [data-search-content]",resultsLayout:"default",hierarchyGroupBy:"",hierarchyStyle:"tree",hierarchyDisplay:"individual",hierarchyMaxHeadings:3,styles:{},promotionBadge:{showBadge:!0,badgeText:"Featured",badgePosition:"top-right"}},lt={triggerHotkey:"k",triggerEnabled:!0,triggerLabel:"Search",triggerSelector:"",modalBackdropOpacity:50,modalBackdropBlurEnabled:!0,modalPreventBodyScroll:!0};function dt(e){return{...it,...{modal:lt}[e]||{}}}function x(e,t=!1){if(e==null)return t;if(typeof e=="boolean")return e;if(typeof e=="number")return e!==0;if(e==="")return!0;let r=String(e).trim().toLowerCase();return["1","true","on","yes"].includes(r)?!0:["0","false","off","no"].includes(r)?!1:t}function L(e,t=0){if(e==null)return t;let r=Number.parseInt(e,10);return Number.isNaN(r)?t:r}function se(e,t={}){if(!e)return t;try{return JSON.parse(e)}catch(r){return console.warn("SearchWidget: Invalid JSON attribute",r),t}}function ct(e){return e?e.split(",").map(t=>t.trim()).filter(Boolean):[]}function J(e){return e.indexHandles.length>0?e.indexHandles.join(","):"all"}function ke(e){return e.indexHandles.length===1?e.indexHandles[0]:""}function z(e,t="modal"){let r=se(e.getAttribute("snippet-defaults"),{}),n={...dt(t),...Object.fromEntries(Object.entries(r).filter(([m])=>["snippetIncludeCodeBlocks","snippetMode","snippetMaxLength","snippetCleanMarkdown","minSnippetLength","maxSnippetLength","snippetModes"].includes(m)))},s=Array.isArray(n.snippetModes)?n.snippetModes:["early","balanced","deep"],o=Number.isFinite(Number(n.minSnippetLength))?Number(n.minSnippetLength):50,a=Number.isFinite(Number(n.maxSnippetLength))?Number(n.maxSnippetLength):1e3,l=Math.min(a,Math.max(o,L(e.getAttribute("snippet-max-length"),n.snippetMaxLength))),i=e.getAttribute("snippet-mode")||n.snippetMode,c=e.getAttribute("index-handles")||"",h={indexHandles:ct(c),placeholder:e.getAttribute("placeholder")||n.placeholder,theme:e.getAttribute("theme")||n.theme,siteId:e.getAttribute("site-id")||n.siteId,apiKey:e.getAttribute("api-key")||n.apiKey,analyticsSource:e.getAttribute("analytics-source")||n.analyticsSource,highlightTag:e.getAttribute("highlight-tag")||n.highlightTag,highlightClass:e.getAttribute("highlight-class")||n.highlightClass,searchEndpoint:n.searchEndpoint,trackClickEndpoint:n.trackClickEndpoint,trackSearchEndpoint:n.trackSearchEndpoint,resultsLimit:L(e.getAttribute("results-limit"),n.resultsLimit),searchDebounceMs:L(e.getAttribute("search-debounce-ms"),n.searchDebounceMs),searchMinChars:L(e.getAttribute("search-min-chars"),n.searchMinChars),recentSearchesLimit:L(e.getAttribute("recent-searches-limit"),n.recentSearchesLimit),analyticsIdleTimeoutMs:L(e.getAttribute("analytics-idle-timeout-ms"),n.analyticsIdleTimeoutMs),recentSearchesEnabled:x(e.getAttribute("recent-searches-enabled"),n.recentSearchesEnabled),resultsGroupingEnabled:x(e.getAttribute("results-grouping-enabled"),n.resultsGroupingEnabled),highlightResultsEnabled:x(e.getAttribute("highlight-results-enabled"),n.highlightResultsEnabled),loadingIndicatorEnabled:x(e.getAttribute("loading-indicator-enabled"),n.loadingIndicatorEnabled),resultsRequireUrl:x(e.getAttribute("results-require-url"),n.resultsRequireUrl),snippetIncludeCodeBlocks:x(e.getAttribute("snippet-include-code-blocks"),n.snippetIncludeCodeBlocks),debugEnabled:x(e.getAttribute("debug-enabled"),n.debugEnabled),snippetMode:s.includes(i)?i:n.snippetMode,snippetMaxLength:l,snippetCleanMarkdown:x(e.getAttribute("snippet-clean-markdown"),n.snippetCleanMarkdown),highlightDestinationPersistQuery:x(e.getAttribute("highlight-destination-persist-query"),n.highlightDestinationPersistQuery),highlightDestinationEnabled:x(e.getAttribute("highlight-destination-enabled"),n.highlightDestinationEnabled),resultsTitleLines:L(e.getAttribute("results-title-lines"),n.resultsTitleLines),resultsDescriptionLines:L(e.getAttribute("results-description-lines"),n.resultsDescriptionLines),highlightDestinationQueryParam:e.getAttribute("highlight-destination-query-param")||n.highlightDestinationQueryParam,highlightDestinationContentSelector:e.getAttribute("highlight-destination-content-selector")||n.highlightDestinationContentSelector,resultsLayout:e.getAttribute("results-layout")||n.resultsLayout,hierarchyGroupBy:e.getAttribute("hierarchy-group-by")||n.hierarchyGroupBy,hierarchyStyle:e.getAttribute("hierarchy-style")||n.hierarchyStyle,hierarchyDisplay:e.getAttribute("hierarchy-display")||n.hierarchyDisplay,hierarchyMaxHeadings:L(e.getAttribute("hierarchy-max-headings"),n.hierarchyMaxHeadings),styles:se(e.getAttribute("styles"),n.styles),promotionBadge:se(e.getAttribute("promotion-badge"),n.promotionBadge)};return t==="modal"&&Object.assign(h,{triggerHotkey:e.getAttribute("trigger-hotkey")||n.triggerHotkey,triggerLabel:e.getAttribute("trigger-label")||n.triggerLabel,triggerSelector:e.getAttribute("trigger-selector")||n.triggerSelector,modalBackdropOpacity:L(e.getAttribute("modal-backdrop-opacity"),n.modalBackdropOpacity),triggerEnabled:x(e.getAttribute("trigger-enabled"),n.triggerEnabled),modalBackdropBlurEnabled:x(e.getAttribute("modal-backdrop-blur-enabled"),n.modalBackdropBlurEnabled),modalPreventBodyScroll:x(e.getAttribute("modal-prevent-body-scroll"),n.modalPreventBodyScroll)}),h}function ve(e="modal"){let t=["index-handles","placeholder","theme","results-limit","search-debounce-ms","search-min-chars","recent-searches-enabled","recent-searches-limit","results-grouping-enabled","site-id","analytics-idle-timeout-ms","analytics-source","highlight-results-enabled","highlight-tag","highlight-class","results-require-url","snippet-include-code-blocks","snippet-mode","loading-indicator-enabled","debug-enabled","styles","promotion-badge","results-layout","hierarchy-group-by","hierarchy-style","hierarchy-display","hierarchy-max-headings","results-title-lines","results-description-lines","snippet-max-length","snippet-clean-markdown","highlight-destination-persist-query","highlight-destination-query-param","highlight-destination-enabled","highlight-destination-content-selector"],n={modal:["trigger-hotkey","trigger-enabled","trigger-label","trigger-selector","modal-backdrop-opacity","modal-backdrop-blur-enabled","modal-prevent-body-scroll"]};return[...t,...n[e]||[]]}var Z={isOpen:!1,query:"",results:[],recentSearches:[],selectedIndex:-1,loading:!1,error:null,meta:null};function xe(e={},t=null){let r={...Z,...e};return{get(n){return r[n]},getAll(){return{...r}},set(n){let s=[];return Object.keys(n).forEach(o=>{let a=r[o],l=n[o];ee(a,l)||s.push(o)}),s.length>0&&(r={...r,...n},t&&t(r,s)),s},reset(n=e){let s={...Z,...n},o=Object.keys(s).filter(a=>!ee(r[a],s[a]));o.length>0&&(r=s,t&&t(r,o))},is(n,s){return r[n]===s},toggle(n){let s=!r[n];return this.set({[n]:s}),s}}}function ee(e,t){if(e===t)return!0;if(e==null||t==null)return!1;if(Array.isArray(e)&&Array.isArray(t))return e.length!==t.length?!1:e.every((r,n)=>ee(r,t[n]));if(typeof e=="object"&&typeof t=="object"){let r=Object.keys(e),n=Object.keys(t);return r.length!==n.length?!1:r.every(s=>ee(e[s],t[s]))}return!1}async function we({query:e,endpoint:t,indexHandles:r=[],siteId:n="",resultsLimit:s=10,resultsRequireUrl:o=!1,snippetIncludeCodeBlocks:a=!1,snippetMode:l="",snippetMaxLength:i=0,snippetCleanMarkdown:c=!1,debugEnabled:d=!1,apiKey:h="",signal:m}){let g=new URLSearchParams({q:e,resultsLimit:s.toString()});r.length>0&&g.append("indexHandles",r.join(",")),n&&g.append("siteId",n),o&&g.append("resultsRequireUrl","1"),a&&g.append("snippetIncludeCodeBlocks","1"),l&&g.append("snippetMode",l),i&&g.append("snippetMaxLength",String(i)),c&&g.append("snippetCleanMarkdown","1"),d&&g.append("debugEnabled","1"),g.append("skipAnalytics","1");let b=t.includes("?")?"&":"?",v={Accept:"application/json"};h&&(v["X-Search-Manager-Key"]=h);let f=await fetch(`${t}${b}${g}`,{signal:m,headers:v});if(!f.ok)throw new Error(await ht(f));let p=await f.json();return p.error&&console.warn("Search warning:",p.error),{results:p.results||p.hits||[],total:p.total||0,meta:p.meta||null,error:p.error||null}}async function ht(e){let t=await ut(e);return e.status===401?t||"Search requires an API key.":e.status===403?t||"This API key cannot access this search.":e.status===429?t||"Search rate limit exceeded. Try again in a moment.":t||"Search failed."}async function ut(e){try{if((e.headers.get("content-type")||"").includes("application/json")){let r=await e.json(),n=r.error||r.message||"";return typeof n=="string"?n.slice(0,240):""}}catch{return""}return""}function Ce({endpoint:e,elementId:t,query:r,index:n,apiKey:s=""}){if(!(!t||!e))try{let o=new FormData;o.append("elementId",t),o.append("query",r),o.append("index",n);let a={Accept:"application/json"};s&&(a["X-Search-Manager-Key"]=s),fetch(e,{method:"POST",body:o,headers:a}).catch(()=>{})}catch{}}function Se({endpoint:e,query:t,indexHandles:r=[],resultsCount:n=0,trigger:s="unknown",analyticsSource:o="",siteId:a="",cached:l,took:i,apiKey:c=""}){if(!(!t||!e))try{let d=new FormData;d.append("q",t),d.append("indexHandles",r.join(",")),d.append("resultsCount",n.toString()),d.append("trigger",s),d.append("analyticsSource",o||"frontend-widget"),a&&d.append("siteId",a),typeof l=="boolean"&&d.append("cached",l?"1":"0"),typeof i=="number"&&Number.isFinite(i)&&i>=0&&d.append("took",i.toString());let h={Accept:"application/json"};c&&(h["X-Search-Manager-Key"]=c),fetch(e,{method:"POST",body:d,headers:h}).catch(()=>{})}catch{}}function Te(e){let t={};return e.forEach(r=>{let n=r.source||r.entrySection||r.type||"Results";t[n]||(t[n]=[]),t[n].push(r)}),t}function Ee(e,t){let r={};return e.forEach(n=>{let s=(t?n[t]:null)||n.source||n.entrySection||n.type||"Results";r[s]||(r[s]=[]),r[s].push(n)}),r}var gt="sm-recent-";function oe(e){return`${gt}${e||"default"}`}function te(e){try{let t=oe(e),r=localStorage.getItem(t);return r?JSON.parse(r):[]}catch{return[]}}function De(e,t,r=null,n=5){if(!t||!t.trim())return te(e);let s=oe(e),o={query:t.trim(),title:r?.title||t,url:r?.url||null,timestamp:Date.now()},a=te(e);a=a.filter(l=>l.query!==o.query),a.unshift(o),a=a.slice(0,n);try{localStorage.setItem(s,JSON.stringify(a))}catch{}return a}function Ae(e){try{let t=oe(e);localStorage.removeItem(t)}catch{}}var Be={spinnerColor:"#3b82f6",spinnerColorDark:"#60a5fa",modalBg:"#ffffff",modalBgDark:"#1f2937",modalBorderRadius:"12",modalBorderWidth:"1",modalBorderColor:"#e5e7eb",modalBorderColorDark:"#374151",modalShadow:"0 25px 50px -12px rgba(0, 0, 0, 0.25)",modalShadowDark:"0 25px 50px -12px rgba(0, 0, 0, 0.5)",modalMaxWidth:"640",modalMaxHeight:"80",modalPaddingX:"16",modalPaddingY:"16",headerBg:"transparent",headerBgDark:"transparent",headerBorderColor:"#e5e7eb",headerBorderColorDark:"#374151",headerBorderWidth:"1",headerBorderRadius:"0",headerPaddingX:"16",headerPaddingY:"12",inputBg:"#ffffff",inputBgDark:"#1f2937",inputTextColor:"#111827",inputTextColorDark:"#f9fafb",inputPlaceholderColor:"#9ca3af",inputPlaceholderColorDark:"#9ca3af",inputBorderColor:"transparent",inputBorderColorDark:"transparent",inputFontSize:"16",inputBorderRadius:"0",inputBorderWidth:"0",inputPaddingX:"0",inputPaddingY:"0",resultBg:"transparent",resultBgDark:"transparent",resultBorderColor:"#e5e7eb",resultBorderColorDark:"#374151",resultActiveBg:"#e5e7eb",resultActiveBgDark:"#4b5563",resultActiveBorderColor:"#e5e7eb",resultActiveBorderColorDark:"#374151",resultActiveTextColor:"#111827",resultActiveTextColorDark:"#f9fafb",resultActiveDescColor:"#4b5563",resultActiveDescColorDark:"#d1d5db",resultActiveMutedColor:"#6b7280",resultActiveMutedColorDark:"#d1d5db",resultTextColor:"#111827",resultTextColorDark:"#f9fafb",resultDescColor:"#4b5563",resultDescColorDark:"#d1d5db",resultMutedColor:"#6b7280",resultMutedColorDark:"#d1d5db",resultGap:"8",resultBorderWidth:"0",resultPaddingX:"12",resultPaddingY:"12",resultBorderRadius:"8",triggerBg:"#ffffff",triggerBgDark:"#374151",triggerTextColor:"#374151",triggerTextColorDark:"#d1d5db",triggerBorderRadius:"8",triggerBorderWidth:"1",triggerBorderColor:"#d1d5db",triggerBorderColorDark:"#4b5563",triggerHoverBg:"#f9fafb",triggerHoverBgDark:"#4b5563",triggerHoverTextColor:"#111827",triggerHoverTextColorDark:"#f9fafb",triggerHoverBorderColor:"#3b82f6",triggerHoverBorderColorDark:"#60a5fa",triggerPaddingX:"12",triggerPaddingY:"8",triggerFontSize:"14",kbdBg:"#f3f4f6",kbdBgDark:"#4b5563",kbdTextColor:"#4b5563",kbdTextColorDark:"#e5e7eb",kbdBorderRadius:"4",backdropOpacity:"50",backdropBlur:"1",highlightResultsEnabled:"1",highlightTag:"",highlightClass:"",highlightBgLight:"fef08a",highlightColorLight:"854d0e",highlightBgDark:"854d0e",highlightColorDark:"fef08a",iconColor:"#3b82f6",iconColorDark:"#60a5fa",promotedBg:"#2563eb",promotedBgDark:"#2563eb",promotedColor:"#ffffff",promotedColorDark:"#ffffff"};var W={modalBg:"--sm-modal-bg",modalBgDark:"--sm-modal-bg-dark",modalBorderRadius:"--sm-modal-radius",modalBorderWidth:"--sm-modal-border-width",modalBorderColor:"--sm-modal-border-color",modalBorderColorDark:"--sm-modal-border-color-dark",modalShadow:"--sm-modal-shadow",modalShadowDark:"--sm-modal-shadow-dark",modalMaxWidth:"--sm-modal-width",modalMaxHeight:"--sm-modal-max-height",modalPaddingX:"--sm-modal-px",modalPaddingY:"--sm-modal-py",headerBg:"--sm-header-bg",headerBgDark:"--sm-header-bg-dark",headerBorderColor:"--sm-header-border-color",headerBorderColorDark:"--sm-header-border-color-dark",headerBorderWidth:"--sm-header-border-width",headerBorderRadius:"--sm-header-radius",headerPaddingX:"--sm-header-px",headerPaddingY:"--sm-header-py",inputBg:"--sm-input-bg",inputBgDark:"--sm-input-bg-dark",inputTextColor:"--sm-input-color",inputTextColorDark:"--sm-input-color-dark",inputPlaceholderColor:"--sm-input-placeholder",inputPlaceholderColorDark:"--sm-input-placeholder-dark",inputBorderColor:"--sm-input-border-color",inputBorderColorDark:"--sm-input-border-color-dark",inputFontSize:"--sm-input-font-size",inputBorderRadius:"--sm-input-radius",inputBorderWidth:"--sm-input-border-width",inputPaddingX:"--sm-input-px",inputPaddingY:"--sm-input-py",resultBg:"--sm-result-bg",resultBgDark:"--sm-result-bg-dark",resultBorderColor:"--sm-result-border-color",resultBorderColorDark:"--sm-result-border-color-dark",resultActiveBg:"--sm-result-active-bg",resultActiveBgDark:"--sm-result-active-bg-dark",resultActiveBorderColor:"--sm-result-active-border-color",resultActiveBorderColorDark:"--sm-result-active-border-color-dark",resultActiveTextColor:"--sm-result-active-text-color",resultActiveTextColorDark:"--sm-result-active-text-color-dark",resultActiveDescColor:"--sm-result-active-desc-color",resultActiveDescColorDark:"--sm-result-active-desc-color-dark",resultActiveMutedColor:"--sm-result-active-muted-color",resultActiveMutedColorDark:"--sm-result-active-muted-color-dark",resultTextColor:"--sm-result-text-color",resultTextColorDark:"--sm-result-text-color-dark",resultDescColor:"--sm-result-desc-color",resultDescColorDark:"--sm-result-desc-color-dark",resultMutedColor:"--sm-result-muted-color",resultMutedColorDark:"--sm-result-muted-color-dark",resultGap:"--sm-result-gap",resultBorderWidth:"--sm-result-border-width",resultPaddingX:"--sm-result-px",resultPaddingY:"--sm-result-py",resultBorderRadius:"--sm-result-radius",triggerBg:"--sm-trigger-bg",triggerBgDark:"--sm-trigger-bg-dark",triggerTextColor:"--sm-trigger-text-color",triggerTextColorDark:"--sm-trigger-text-color-dark",triggerBorderRadius:"--sm-trigger-radius",triggerBorderWidth:"--sm-trigger-border-width",triggerBorderColor:"--sm-trigger-border-color",triggerBorderColorDark:"--sm-trigger-border-color-dark",triggerHoverBg:"--sm-trigger-hover-bg",triggerHoverBgDark:"--sm-trigger-hover-bg-dark",triggerHoverTextColor:"--sm-trigger-hover-text-color",triggerHoverTextColorDark:"--sm-trigger-hover-text-color-dark",triggerHoverBorderColor:"--sm-trigger-hover-border-color",triggerHoverBorderColorDark:"--sm-trigger-hover-border-color-dark",triggerPaddingX:"--sm-trigger-px",triggerPaddingY:"--sm-trigger-py",triggerFontSize:"--sm-trigger-font-size",kbdBg:"--sm-kbd-bg",kbdBgDark:"--sm-kbd-bg-dark",kbdTextColor:"--sm-kbd-text-color",kbdTextColorDark:"--sm-kbd-text-color-dark",kbdBorderRadius:"--sm-kbd-radius",iconColor:"--sm-icon-color",iconColorDark:"--sm-icon-color-dark",highlightBgLight:"--sm-highlight-bg",highlightColorLight:"--sm-highlight-color",highlightBgDark:"--sm-highlight-bg-dark",highlightColorDark:"--sm-highlight-color-dark",promotedBg:"--sm-promoted-bg",promotedBgDark:"--sm-promoted-bg-dark",promotedColor:"--sm-promoted-color",promotedColorDark:"--sm-promoted-color-dark",spinnerColor:"--sm-spinner-color-light",spinnerColorDark:"--sm-spinner-color-dark"},ae=["modalBorderRadius","modalBorderWidth","modalMaxWidth","modalPaddingX","modalPaddingY","headerBorderWidth","headerBorderRadius","headerPaddingX","headerPaddingY","inputFontSize","inputBorderRadius","inputBorderWidth","inputPaddingX","inputPaddingY","resultGap","resultBorderWidth","resultPaddingX","resultPaddingY","resultBorderRadius","triggerBorderRadius","triggerBorderWidth","triggerPaddingX","triggerPaddingY","triggerFontSize","kbdBorderRadius"],ie=["modalMaxHeight"],Ie=["modalBg","modalBgDark","modalBorderColor","modalBorderColorDark","headerBg","headerBgDark","headerBorderColor","headerBorderColorDark","inputBg","inputBgDark","inputTextColor","inputTextColorDark","inputPlaceholderColor","inputPlaceholderColorDark","inputBorderColor","inputBorderColorDark","resultBg","resultBgDark","resultBorderColor","resultBorderColorDark","resultActiveBg","resultActiveBgDark","resultActiveBorderColor","resultActiveBorderColorDark","resultTextColor","resultTextColorDark","resultActiveTextColor","resultActiveTextColorDark","resultActiveDescColor","resultActiveDescColorDark","resultActiveMutedColor","resultActiveMutedColorDark","resultDescColor","resultDescColorDark","resultMutedColor","resultMutedColorDark","triggerBg","triggerBgDark","triggerTextColor","triggerTextColorDark","triggerBorderColor","triggerBorderColorDark","triggerHoverBg","triggerHoverBgDark","triggerHoverTextColor","triggerHoverTextColorDark","triggerHoverBorderColor","triggerHoverBorderColorDark","kbdBg","kbdBgDark","kbdTextColor","kbdTextColorDark","iconColor","iconColorDark","highlightBgLight","highlightColorLight","highlightBgDark","highlightColorDark","promotedBg","promotedBgDark","promotedColor","promotedColorDark","spinnerColor","spinnerColorDark"],er={...Be,highlightBgLight:"#fef08a",highlightColorLight:"#854d0e",highlightBgDark:"#854d0e",highlightColorDark:"#fef08a"};var le=new WeakMap;function pt(){let e=new Set,t=new Set;for(let r of Object.keys(W)){if(r.endsWith("Dark")){t.add(r);continue}if(r.endsWith("Light")){let n=r.replace(/Light$/,"Dark");W[n]&&e.add(r);continue}W[`${r}Dark`]&&e.add(r)}return{lightKeys:e,darkKeys:t}}var Le=pt();function bt(e){return typeof e=="string"&&/^(var|light-dark|calc|env|clamp|min|max|rgb|hsl)\s*\(/.test(e.trim())}function ft(e){return/^[0-9a-fA-F]{6}$/.test(e)}function yt(e,t){if(t==null||t==="")return null;let r=String(t);return bt(r)||(Ie.includes(e)&&ft(r)&&(r="#"+r),ae.includes(e)&&(r=r+"px"),ie.includes(e)&&(r=r+"vh")),r}function Re(e,t,r="light"){if(!e)return;let n=le.get(e);if(n){for(let i of n)e.style.removeProperty(i);le.delete(e)}if(!t||typeof t!="object")return;let s=r==="dark",o=Object.entries(W),a=new Set([...ae,...ie]),l=new Set;for(let[i,c]of o){let d=Le.lightKeys.has(i),h=Le.darkKeys.has(i),m=d||h;if(s){if(d||!h&&!a.has(i))continue}else if(h)continue;if(t[i]!==void 0&&t[i]!==null&&t[i]!==""){let g=yt(i,t[i]);g&&(e.style.setProperty(c,g),m&&l.add(c))}}l.size>0&&le.set(e,l)}var kt=new Set(["mark","em","strong","u","b","i","span"]),vt=/^[A-Za-z0-9_-]+$/;function u(e){return e?String(e).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"):""}function He(e){return e?e.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"):""}function de(e){if(!e)return[];let t=[],r=/"([^"]+)"/g,n;for(;(n=r.exec(e))!==null;)n[1].trim()&&t.push(n[1].trim());let s=e.replace(/"[^"]*"/g,""),o=new Set(["and","or","not","und","oder","nicht","et","ou","sauf","y","o","no"]);s.split(/\s+/).filter(l=>l.length>0).forEach(l=>{l=l.replace(/^[a-zA-Z]+:/,""),l=l.replace(/\*/g,""),l=l.replace(/\^\d+(\.\d+)?/,""),l=l.replace(/"/g,""),!(!l||o.has(l.toLowerCase()))&&t.push(l)});let a=[];return t.forEach(l=>{a.push(l);let i=l.split(/(?<=[a-z])(?=[A-Z])/);i.length>1&&i.forEach(c=>{c.length>=3&&a.push(c)})}),a}function Y(e,t,r={}){let{enabled:n=!0,tag:s="mark",className:o="",terms:a=null}=r;if(!n)return u(e);let l=xt(s),c=["sm-highlight",...wt(o)],d=` class="${u(c.join(" "))}"`,h=Ct(t,a);return h.length===0?u(e):St(e,h,l,d)}function xt(e){let t=String(e||"mark").trim().toLowerCase();return kt.has(t)?t:"mark"}function wt(e){return String(e||"").trim().split(/\s+/).filter(t=>t&&vt.test(t))}function Ct(e,t){return Array.isArray(t)&&t.length>0?$e(t):e?$e(de(e)):[]}function $e(e){let t=new Set;return e.filter(r=>typeof r=="string"&&r.length>0).sort((r,n)=>n.length-r.length).filter(r=>{let n=r.toLowerCase();return t.has(n)?!1:(t.add(n),!0)})}function St(e,t,r,n){let s=e.toLowerCase(),o=[];if(t.forEach(d=>{let h=d.toLowerCase();if(!h)return;let m=0;for(;m<s.length;){let g=s.indexOf(h,m);if(g===-1)break;o.push({start:g,end:g+h.length}),m=g+h.length}}),o.length===0)return u(e);o.sort((d,h)=>d.start!==h.start?d.start-h.start:h.end-h.start-(d.end-d.start));let a=[],l=-1;o.forEach(d=>{d.start>=l&&(a.push(d),l=d.end)});let i="",c=0;return a.forEach(d=>{c<d.start&&(i+=u(e.slice(c,d.start))),i+=`<${r}${n}>${u(e.slice(d.start,d.end))}</${r}>`,c=d.end}),c<e.length&&(i+=u(e.slice(c))),i}function j(e,t,r="smq"){if(!e||e==="#")return e;if(Tt(e))return"#";let n=(t||"").trim();if(!n||!r||/^(mailto:|tel:)/i.test(e))return e;let[s,o]=e.split("#",2),[a,l]=s.split("?",2),i=new URLSearchParams(l||"");i.set(r,n);let c=i.toString(),d=o?`#${o}`:"";return`${a}${c?`?${c}`:""}${d}`}function Tt(e){let t=String(e).replace(/[\t\n\r]/g,"").replace(/^[\u0000-\u0020]+/,"");return/^(javascript|data|vbscript):/i.test(t)}var Et=0;function ce(e="sm"){return`${e}-${++Et}-${Date.now().toString(36)}`}function Me(e){let t=document.createElement("div");return t.setAttribute("role","status"),t.setAttribute("aria-live","polite"),t.setAttribute("aria-atomic","true"),t.className="sm-sr-only",e.appendChild(t),t}function Q(e,t,r=100){e&&(e.textContent="",setTimeout(()=>{e.textContent=t},r))}function he(e,t){return e===0?`No results found for "${t}"`:e===1?`1 result found for "${t}"`:`${e} results found for "${t}"`}function Pe(){return"Searching..."}function Oe(e){return e===0?"No recent searches":e===1?"1 recent search available":`${e} recent searches available`}function re(e,{expanded:t,activeDescendant:r,listboxId:n}){e.setAttribute("aria-expanded",String(t)),e.setAttribute("aria-controls",n),r?e.setAttribute("aria-activedescendant",r):e.removeAttribute("aria-activedescendant")}function _(e,t){return`${e}-option-${t}`}function Ne(e,t){if(!e||!t)return;let r=e.getBoundingClientRect(),n=t.getBoundingClientRect();r.top<n.top?e.scrollIntoView({block:"nearest",behavior:"smooth"}):r.bottom>n.bottom&&e.scrollIntoView({block:"nearest",behavior:"smooth"})}function Dt(e,t,r={}){let{resultsGroupingEnabled:n=!1,resultsLayout:s="default",listboxId:o}=r;if(!e||e.length===0)return"";if(s==="hierarchical")return Ot(e,t,r);if(n){let a=Te(e),l=0;return Object.entries(a).map(([i,c])=>`
            <div class="sm-section" role="group" aria-label="${u(i)}">
                <div class="sm-section-header">${u(i)}</div>
                ${c.map(d=>_e(d,l++,t,r)).join("")}
            </div>
        `).join("")}return e.map((a,l)=>_e(a,l,t,r)).join("")}function _e(e,t,r,n={}){let{listboxId:s,highlightResultsEnabled:o=!0,highlightTag:a="mark",highlightClass:l="",resultsGroupingEnabled:i=!1,promotionBadge:c={},debugEnabled:d=!1,highlightDestinationPersistQuery:h=!1,highlightDestinationQueryParam:m="smq"}=n,g=me(e),b=g?e.sectionTitle||e.title||e.name||"Untitled":e.title||e.name||"Untitled",v=e.snippet||"",f=g?e.sectionUrl||e.url||e.href||"#":e.url||e.href||"#",p=j(f,r,h?m:""),D=e.source||e.entrySection||e.type||"",k=_(s,t),y=e.promoted===!0,A=e._index||e.index||"",B=pe(e),C={enabled:o,tag:a,className:l},q=Y(b,r,{...C,terms:N(e,"title")}),$=v?ge(e,v,r,{...C,terms:N(e,"snippet")}):"",H=At(e,c),S=y?" sm-promoted":"",M=D&&!i?`<span class="sm-result-type">${u(D)}</span>`:"",I=d?Ge(e):"";return d?`
            <a class="sm-result-item sm-debug-enabled${S}" id="${k}" role="option" aria-selected="false" href="${u(p)}" data-index="${t}" data-source-index="${u(A)}"${B} data-title="${u(b)}">
                <div class="sm-result-main">
                    ${H}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${q}</span>
                        ${$?`<span class="sm-result-desc">${$}</span>`:""}
                    </div>
                    ${M}
                    ${F()}
                </div>
                ${I}
            </a>
        `:`
        <a class="sm-result-item${S}" id="${k}" role="option" aria-selected="false" href="${u(p)}" data-index="${t}" data-source-index="${u(A)}"${B} data-title="${u(b)}">
            ${H}
            <div class="sm-result-content">
                <span class="sm-result-title">${q}</span>
                ${$?`<span class="sm-result-desc">${$}</span>`:""}
            </div>
            ${M}
            ${F()}
        </a>
    `}function Ge(e){let t=[],r=e.backend?e.backend.toLowerCase():"";if((e._index||e.index)&&t.push(w("index",e._index||e.index,"index")),e.backend&&t.push(w("backend",r,"backend",r)),e.elementId&&t.push(w("element",e.elementId,"generic")),e.backendId&&t.push(w("hit",e.backendId,"generic")),e.score!==void 0&&e.score!==null){let n=typeof e.score=="number"?e.score.toFixed(2):e.score;t.push(w("score",n,"score"))}if(e.site&&t.push(w("site",e.site,"generic")),e.language&&t.push(w("lang",e.language,"generic")),e.matchedIn&&Array.isArray(e.matchedIn)&&e.matchedIn.length>0){let n=e.matchedIn.join(", ");t.push(w("matched",n,"matched"))}return e.promoted&&t.push(w("promoted","yes","promoted")),e.boosted&&t.push(w("boosted","yes","boosted")),t.length===0?"":`<div class="sm-debug-info">${t.join("")}</div>`}function N(e,t){let r=Array.isArray(e.matchedPhrases)?e.matchedPhrases:[],n=e.matchedTerms,s=[];n&&(t==="title"&&Array.isArray(n.title)&&n.title.length>0?s=n.title:t==="snippet"&&Array.isArray(n.content)&&n.content.length>0?s=n.content:s=[...Array.isArray(n.title)?n.title:[],...Array.isArray(n.content)?n.content:[]]);let o=[...r,...s];return o.length>0?o:null}function ge(e,t,r,n){return Y(t,r,n)}function w(e,t,r,n=""){let s=n?` data-backend="${u(n)}"`:"";return`<span class="sm-debug-item"><span class="sm-debug-label">${u(e)}</span><span class="sm-debug-value" data-type="${u(r)}"${s}>${u(String(t))}</span></span>`}function At(e,t={}){let{showBadge:r=!0,badgeText:n="Featured",badgePosition:s="top-right"}=t;return!e.promoted||!r?"":`<span class="sm-promoted-badge ${`sm-promoted-badge--${s}`}">${u(n)}</span>`}function me(e){return!!(e&&typeof e=="object"&&["heading","intro","promoted-page"].includes(String(e.sectionType||"")))}function Bt(e){return Array.isArray(e)&&e.some(me)}function It(e,t){let r=new Map,n=[];return e.forEach((s,o)=>{if(!me(s)){n.push({type:"single",item:s,order:o,score:O(s)});return}let a=$t(s);if(!r.has(a)){let c={hits:[],order:o,score:O(s)};r.set(a,c),n.push({type:"section-group",key:a,order:o,score:c.score})}let l=r.get(a);l.hits.push(s),l.score=Math.max(l.score,O(s));let i=n.find(c=>c.type==="section-group"&&c.key===a);i&&(i.score=l.score)}),n.map(s=>{if(s.type==="section-group"){let o=r.get(s.key);return{...s,item:Lt(o.hits,t)}}return s}).sort((s,o)=>{let a=ue(o.score,s.score);return a!==0?a:s.order-o.order}).map(s=>s.item)}function Lt(e,t){let r=[...e].sort((d,h)=>P(d)-P(h)),n=r.find(d=>d.sectionType==="intro")||null,s=[...e].sort((d,h)=>{let m=ue(O(h),O(d));return m!==0?m:P(d)-P(h)})[0]||r[0]||{},o=n||s,a=U(o),l=o.siteId??"",i=Number.isFinite(t)&&t>0?t:3,c=r.filter(d=>d.sectionType==="heading").sort((d,h)=>{let m=ue(O(h),O(d));return m!==0?m:P(d)-P(h)}).slice(0,i).sort((d,h)=>P(d)-P(h)).map(Rt);return{...o,elementId:a||o.elementId,backendId:n?.backendId||o.backendId||Ht(a,l),title:o.title||o.sectionTitle||o.name||"Untitled",url:o.url||"#",snippet:n&&n.snippet||null,score:O(s),headings:c,__sectionHitGroup:!0,__useBackendDomId:!0}}function Rt(e){let t=Number.parseInt(e.sectionLevel,10),r=Number.isFinite(t)?t:2;return{title:e.sectionTitle||e.title||"",text:e.sectionTitle||e.title||"",id:e.sectionAnchor||e.sectionId||"",level:r,url:e.sectionUrl||e.url||null,snippet:e.snippet||null,backendId:e.backendId||"",elementId:U(e),sectionType:e.sectionType,_index:e._index,index:e.index,matchedTerms:e.matchedTerms,matchedPhrases:e.matchedPhrases,__useBackendDomId:!0}}function $t(e){return[U(e)||ze(e)||"",e.siteId??""].join(":")}function P(e){let t=Number.parseInt(e.sectionIndex,10);return Number.isFinite(t)?t:Number.MAX_SAFE_INTEGER}function O(e){let t=Number(e?.score);return Number.isFinite(t)?t:Number.NEGATIVE_INFINITY}function ue(e,t){return e===t?0:e>t?1:-1}function Ht(e,t){let r=e||"unknown";return t!=null&&String(t)!==""?`${r}_${t}`:String(r)}function ze(e,t=null){return e?.backendId||t?.backendId||""}function U(e,t=null){return e?.elementId||t?.elementId||""}function pe(e,t=null){let r=ze(e,t)||U(e,t),n=U(e,t);return` data-id="${u(r)}" data-element-id="${u(n)}"`}function F(){return`<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>`}function Mt(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
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
    </svg>`}function Ot(e,t,r={}){let{hierarchyGroupBy:n="",hierarchyStyle:s="tree",hierarchyDisplay:o="individual",hierarchyMaxHeadings:a=3,listboxId:l}=r,i=s==="tree",c=s!=="none",d=Bt(e)?It(e,a):e,m=Ee(d,n||""),g=0;return Object.entries(m).map(([b,v])=>{let f=v.map(p=>{let D=g++,k=Nt(p,D,t,r),y="",A=p.headings||[],B=p.__sectionHitGroup?A:A.slice(0,a);if(B.length>0){let $=Math.min(...B.map(S=>S.level||2)),H=B.map(S=>i?(S.level||2)-$:0);y=B.map((S,M)=>{let I=H[M],V=!H.slice(M+1).some(G=>G===I),K=[];if(i){let G=H.slice(M+1);for(let T=0;T<I;T++)G.some(X=>X===T)&&K.push(T)}return _t(p,S,g++,t,r,V,I,K)}).join("")}let C=!!y;return`
                <div class="sm-hierarchy-block${C?" sm-hierarchy-block--has-children":""}${o==="unified"?" sm-hierarchy-block--unified":""}">
                    ${C?k.replace("sm-result-item sm-hierarchy-parent","sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children"):k}
                    ${C?`<div class="sm-hierarchy-children${c?"":" sm-hierarchy-children--no-connectors"}">${y}</div>`:""}
                </div>
            `}).join("");return`
            <div class="sm-hierarchy-group" role="group" aria-label="${u(b)}">
                <div class="sm-hierarchy-group-header">${u(b)}</div>
                ${f}
            </div>
        `}).join("")}function Nt(e,t,r,n={}){let{listboxId:s,highlightResultsEnabled:o=!0,highlightTag:a="mark",highlightClass:l="",debugEnabled:i=!1,highlightDestinationPersistQuery:c=!1,highlightDestinationQueryParam:d="smq"}=n,h=e.title||e.name||"Untitled",m=e.snippet||"",g=e.url||"#",b=j(g,r,c?d:""),v=_(s,t),f=e._index||e.index||"",p=pe(e),D={enabled:o,tag:a,className:l},k=Y(h,r,{...D,terms:N(e,"title")}),y=m?ge(e,m,r,{...D,terms:N(e,"snippet")}):"",A=i?Ge(e):"",C=e.headings&&e.headings.length>0?Mt():Pt();return i?`
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled" id="${v}" role="option" aria-selected="false" href="${u(b)}" data-index="${t}" data-source-index="${u(f)}"${p} data-title="${u(h)}">
                <div class="sm-result-main">
                    ${C}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${k}</span>
                        ${y?`<span class="sm-result-desc">${y}</span>`:""}
                    </div>
                    ${F()}
                </div>
                ${A}
            </a>
        `:`
        <a class="sm-result-item sm-hierarchy-parent" id="${v}" role="option" aria-selected="false" href="${u(b)}" data-index="${t}" data-source-index="${u(f)}"${p} data-title="${u(h)}">
            ${C}
            <div class="sm-result-content">
                <span class="sm-result-title">${k}</span>
                ${y?`<span class="sm-result-desc">${y}</span>`:""}
            </div>
            ${F()}
        </a>
    `}function _t(e,t,r,n,s={},o=!1,a=0,l=[]){let{listboxId:i,highlightResultsEnabled:c=!0,highlightTag:d="mark",highlightClass:h="",debugEnabled:m=!1,highlightDestinationPersistQuery:g=!1,highlightDestinationQueryParam:b="smq"}=s,f=(t.title||t.text||"").replace(/^#+\s*/,""),p=t.snippet||"",D=Number.parseInt(t.level,10),k=Number.isFinite(D)?Math.min(Math.max(D,1),6):2,y=t.id||(f?Ft(f):""),A=e.url||"#",B=t.url||(y?`${A}#${y}`:A),C=j(B,n,g?b:""),q=_(i,r),$=t._index||t.index||e._index||e.index||"",H=pe(t,e),S={enabled:c,tag:d,className:h},M=Y(f,n,{...S,terms:N(t,"title")||N(e,"title")}),I=p?ge(e,p,n,{...S,terms:N(t,"snippet")||N(e,"snippet")}):"",V=o?" sm-hierarchy-child-row-last":"",K=l.map(T=>`<div class="sm-hierarchy-guide" style="--sm-guide-depth:${T}" aria-hidden="true"></div>`).join(""),G="";if(m){let T=[];T.push(w("h",k,"generic")),y&&T.push(w("anchor",y,"generic"));let X=U(t,e);X&&T.push(w("parent",X,"generic")),G=`<div class="sm-debug-info">${T.join("")}</div>`}return m?`
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${k} sm-hierarchy-depth-${a}${V}" style="--sm-hierarchy-depth:${a}">
                ${K}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${k} sm-debug-enabled" id="${q}" role="option" aria-selected="false" href="${u(C)}" data-index="${r}" data-source-index="${u($)}"${H} data-title="${u(f)}">
                    <div class="sm-result-main">
                        ${Fe()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${M}</span>
                            ${I?`<span class="sm-result-desc">${I}</span>`:""}
                        </div>
                        ${F()}
                    </div>
                    ${G}
                </a>
            </div>
        `:`
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${k} sm-hierarchy-depth-${a}${V}" style="--sm-hierarchy-depth:${a}">
            ${K}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${k}" id="${q}" role="option" aria-selected="false" href="${u(C)}" data-index="${r}" data-source-index="${u($)}"${H} data-title="${u(f)}">
                ${Fe()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${M}</span>
                    ${I?`<span class="sm-result-desc">${I}</span>`:""}
                </div>
                ${F()}
            </a>
        </div>
    `}function Ft(e){let t=e.normalize("NFKD").toLowerCase();try{return t.replace(/[^\p{L}\p{N}]+/gu,"-").replace(/^-+|-+$/g,"")}catch{return t.replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"")}}function qt(e,t){return!e||e.length===0?"":`
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${t}-recent-label">Recent searches</span>
                <button class="sm-clear-recent" part="clear-recent">Clear</button>
            </div>
            ${e.map((r,n)=>`
                <div class="sm-result-item sm-recent-item" id="${_(t,n)}" role="option" aria-selected="false" data-index="${n}" data-url="${u(r.url||"")}" data-query="${u(r.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${u(r.title||r.query)}</span>
                    ${F()}
                </div>
            `).join("")}
        </div>
    `}function qe(e){return!e||!e.trim()?`
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
    `}function Gt(){return`
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>Searching...</p>
        </div>
    `}function zt(e){return`
        <div class="sm-empty sm-error" part="error">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>${u(e||"Search failed.")}</p>
        </div>
    `}function je(e,t){let{query:r,results:n,recentSearches:s,loading:o,recentSearchesEnabled:a,error:l}=e,{loadingIndicatorEnabled:i=!0}=t,c=r&&r.trim();return o&&i?{html:Gt(),hasResults:!1,showListbox:!1}:l?{html:zt(l),hasResults:!1,showListbox:!1}:c?!n||n.length===0?{html:qe(r),hasResults:!1,showListbox:!1}:{html:Dt(n,r,t),hasResults:!0,showListbox:!0}:a&&s&&s.length>0?{html:qt(s,t.listboxId),hasResults:!0,showListbox:!0}:{html:qe(""),hasResults:!1,showListbox:!1}}function be(e,t,r=!1){if(!e)return"";let n=[];if(n.push(R("results",t,"generic")),e.took!==void 0){let i=e.took<1?"<1ms":`${Math.round(e.took)}ms`;n.push(R("time",i,"time"))}if(e.cacheEnabled!==void 0&&(e.cacheEnabled?e.cached?n.push(R("cache","hit","cache-hit")):n.push(R("cache","miss","cache-miss")):n.push(R("cache","off","cache-off"))),e.cacheDriver&&n.push(R("storage",e.cacheDriver,"cache-driver",e.cacheDriver)),e.indices&&e.indices.length>0){let i=e.indices.length>2?`${e.indices.length} indices`:e.indices.join(", ");n.push(R("indices",i,"generic"))}if(e.synonymsExpanded){let i=e.expandedQueries?e.expandedQueries.length-1:0;n.push(R("synonyms",`+${i}`,"synonyms"))}let s=e.rulesMatched?.length||0;n.push(R("rules",s,s>0?"rules":"generic"));let o=e.promotionsMatched?.length||0;n.push(R("promoted",o,o>0?"promotions":"generic"));let l=`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${r?'<path d="M6 9l6 6 6-6"/>':'<path d="M18 15l-6-6-6 6"/>'}</svg>`;return r?`<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">Debug</span>${l}</div>`:`<div class="sm-toolbar-content">${n.join("")}</div><button class="sm-toolbar-toggle" aria-label="Collapse debug panel" aria-expanded="true">${l}</button>`}function R(e,t,r,n=""){let s=n?` data-backend="${u(n)}"`:"";return`<span class="sm-toolbar-item"><span class="sm-toolbar-label">${u(e)}</span><span class="sm-toolbar-value" data-type="${u(r)}"${s}>${u(String(t))}</span></span>`}function Ue(e,t){let{onSelect:r,onIndexChange:n,onEscape:s}=e,{listboxId:o}=t;return{handleKeydown(a,l,i){let c=i;switch(a.key){case"ArrowDown":return a.preventDefault(),c=Math.min(i+1,l-1),c!==i&&n&&n(c),c;case"ArrowUp":return a.preventDefault(),c=Math.max(i-1,-1),c!==i&&n&&n(c),c;case"Enter":return a.preventDefault(),i>=0&&r&&r(i),null;case"Escape":return a.preventDefault(),s&&s(),null;default:return null}},getListboxId(){return o}}}function Ke(e,t,r={}){let{scrollContainer:n,inputElement:s,listboxId:o,selectedClass:a="sm-selected"}=r,l=t>=0?_(o,t):null;s&&re(s,{expanded:e.length>0,activeDescendant:l,listboxId:o}),e.forEach((i,c)=>{let d=c===t;i.classList.toggle(a,d),i.setAttribute("aria-selected",String(d)),d&&n&&Ne(i,n)})}function We(e,t){e.forEach((r,n)=>{r.addEventListener("mouseenter",()=>{t&&t(n)})})}var Ye="sm-page-highlight-style",Qe="__smPageHighlightRegistry",Ve="__searchManagerHotkeyHandled",E=null,fe=class extends HTMLElement{constructor(){super(),this.attachShadow({mode:"open"}),this.config=null,this.state=xe({...Z},this.handleStateChange.bind(this)),this.searchSequence=0,this.debounceTimer=null,this.analyticsIdleTimer=null,this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.listboxId=ce("sm-listbox"),this.inputId=ce("sm-input"),this.liveRegion=null,this.keyboardNavigator=null,this.elements={},this.handleInput=this.handleInput.bind(this),this.handleKeydown=this.handleKeydown.bind(this),this.handleResultClick=this.handleResultClick.bind(this)}get widgetType(){throw new Error("Subclass must implement widgetType getter")}render(){throw new Error("Subclass must implement render()")}getResultsContainer(){throw new Error("Subclass must implement getResultsContainer()")}getInputElement(){throw new Error("Subclass must implement getInputElement()")}getLoadingElement(){return this.elements.loading||null}getDebugToolbarElement(){return this.elements.debugToolbar||null}connectedCallback(){this.config=z(this,this.widgetType),this.state.set({recentSearches:te(J(this.config))}),this.keyboardNavigator=Ue({onSelect:t=>this.selectResultAtIndex(t),onIndexChange:t=>this.state.set({selectedIndex:t}),onEscape:()=>this.handleEscape()},{listboxId:this.listboxId}),this.applyDestinationPageHighlight()}disconnectedCallback(){this.unregisterOpenWidget(),this.searchSequence++,this.debounceTimer&&(clearTimeout(this.debounceTimer),this.debounceTimer=null)}registerOpenWidget(){E&&E!==this&&typeof E.close=="function"&&E.close({reason:"replace",replacedBy:this,source:"replace"}),E=this}unregisterOpenWidget(){E===this&&(E=null)}claimHotkeyEvent(t,r){return t[Ve]||E&&E!==this&&E.state?.get("isOpen")&&E.config?.triggerHotkey?.toLowerCase()===r?!1:(t[Ve]=!0,!0)}attributeChangedCallback(t,r,n){r!==n&&this.shadowRoot.children.length>0&&(this.config=z(this,this.widgetType),this.render(),this.applyCustomStyles())}handleStateChange(t,r){(r.includes("results")||r.includes("query")||r.includes("recentSearches")||r.includes("error"))&&this.renderResultsContent(),(r.includes("results")||r.includes("meta"))&&this.updateDebugToolbar(),r.includes("selectedIndex")&&this.updateSelectionVisual(),r.includes("loading")&&this.updateLoadingVisual()}handleInput(t){let r=t.target.value;if(this.state.set({query:r,selectedIndex:-1}),this.debounceTimer&&clearTimeout(this.debounceTimer),this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),!r.trim()){this.state.set({results:[]});return}r.length<this.config.searchMinChars||(this.debounceTimer=setTimeout(()=>{this.executeSearch(r)},this.config.searchDebounceMs))}async executeSearch(t){let r=++this.searchSequence;this.state.set({loading:!0,error:null}),this.liveRegion&&Q(this.liveRegion,Pe());try{let{results:n,meta:s}=await we({query:t,endpoint:this.config.searchEndpoint,indexHandles:this.config.indexHandles,siteId:this.config.siteId,resultsLimit:this.config.resultsLimit,resultsRequireUrl:this.config.resultsRequireUrl,snippetIncludeCodeBlocks:this.config.snippetIncludeCodeBlocks,snippetMode:this.config.snippetMode,snippetMaxLength:this.config.snippetMaxLength,snippetCleanMarkdown:this.config.snippetCleanMarkdown,debugEnabled:this.config.debugEnabled,apiKey:this.config.apiKey});if(r!==this.searchSequence)return;this.state.set({results:n,meta:s,loading:!1,selectedIndex:n.length>0?0:-1}),s&&typeof s.cached=="boolean"?this.lastSearchCacheState={cached:s.cached,took:typeof s.took=="number"?s.took:null}:this.lastSearchCacheState=null,this.liveRegion&&Q(this.liveRegion,he(n.length,t)),this.dispatchWidgetEvent("search",{query:t,results:n,meta:s}),this.startAnalyticsIdleTimer(t,n.length)}catch(n){if(r!==this.searchSequence||n.name==="AbortError")return;console.error("Search error:",n),this.state.set({results:[],loading:!1,error:n.message}),this.dispatchWidgetEvent("error",{query:t,error:n.message})}}renderResultsContent(){let t=this.getResultsContainer();if(!t)return;let r=this.state.getAll(),{recentSearchesEnabled:n,resultsGroupingEnabled:s,highlightResultsEnabled:o,highlightTag:a,highlightClass:l,loadingIndicatorEnabled:i,debugEnabled:c}=this.config,{html:d,hasResults:h,showListbox:m}=je({query:r.query,results:r.results,recentSearches:r.recentSearches,loading:r.loading,error:r.error,recentSearchesEnabled:n},{listboxId:this.listboxId,resultsGroupingEnabled:s,highlightResultsEnabled:o,highlightTag:a,highlightClass:l,loadingIndicatorEnabled:i,debugEnabled:c,highlightDestinationPersistQuery:this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery,highlightDestinationQueryParam:this.config.highlightDestinationQueryParam,promotionBadge:this.config.promotionBadge,resultsLayout:this.config.resultsLayout,hierarchyGroupBy:this.config.hierarchyGroupBy,hierarchyStyle:this.config.hierarchyStyle,hierarchyDisplay:this.config.hierarchyDisplay,hierarchyMaxHeadings:this.config.hierarchyMaxHeadings});t.innerHTML=d,m?t.setAttribute("role","listbox"):t.removeAttribute("role");let g=this.getInputElement();g&&re(g,{expanded:h,activeDescendant:null,listboxId:this.listboxId}),this.liveRegion&&!r.loading&&(r.query&&r.results.length===0?Q(this.liveRegion,he(0,r.query)):!r.query&&r.recentSearches.length>0&&n&&Q(this.liveRegion,Oe(r.recentSearches.length))),this.attachResultHandlers();let b=t.querySelector(".sm-clear-recent");b&&b.addEventListener("click",v=>{v.stopPropagation(),Ae(J(this.config)),this.state.set({recentSearches:[]})}),h&&r.results.length>0&&this.state.set({selectedIndex:0})}attachResultHandlers(){let t=this.getResultsContainer();if(!t)return;let r=t.querySelectorAll(".sm-result-item");r.forEach(n=>{n.addEventListener("click",s=>this.handleResultClick(s,n))}),We(r,n=>{this.state.set({selectedIndex:n})})}updateSelectionVisual(){let t=this.getResultsContainer(),r=this.getInputElement();if(!t)return;let n=t.querySelectorAll(".sm-result-item"),s=this.state.get("selectedIndex");Ke(n,s,{scrollContainer:t,inputElement:r,listboxId:this.listboxId})}handleKeydown(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item"),s=this.state.get("selectedIndex");if(t.key==="Enter"){let o=this.state.get("query"),a=this.state.get("results")||[];o&&a.length>0&&this.trackSearchAnalytics(o,a.length,"enter")}this.keyboardNavigator.handleKeydown(t,n.length,s)}selectResultAtIndex(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item");t>=0&&n[t]&&n[t].click()}handleEscape(){}handleResultClick(t,r){let n=r.getAttribute("href"),s=r.dataset.url,o=n||s,a=r.dataset.title||r.querySelector(".sm-result-title")?.textContent,l=r.dataset.id,i=r.dataset.elementId||l,c=r.dataset.query||this.state.get("query"),d=r.classList.contains("sm-recent-item"),h=j(o,c,this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery?this.config.highlightDestinationQueryParam:"");if(!d&&c){let g=De(J(this.config),c,{title:a,url:o},this.config.recentSearchesLimit);this.state.set({recentSearches:g})}let m=r.dataset.sourceIndex||ke(this.config);if(i&&m&&Ce({endpoint:this.config.trackClickEndpoint,elementId:i,query:c,index:m,apiKey:this.config.apiKey}),!d&&c&&this.trackSearchAnalytics(c,this.state.get("results")?.length||0,"click"),this.dispatchWidgetEvent("result-click",{id:l,elementId:i,title:a,url:h,query:c,isRecent:d}),o&&o!=="#")d&&(t.preventDefault(),window.location.href=h),this.onResultSelected(h,a,l);else if(c){t.preventDefault();let g=this.getInputElement();g&&(g.value=c,this.state.set({query:c}),this.executeSearch(c))}}onResultSelected(t,r,n){}applyDestinationPageHighlight(){if(!this.config.highlightDestinationEnabled||typeof window>"u"||typeof document>"u")return;let t=this.config.highlightDestinationQueryParam||"smq",r=this.config.highlightDestinationContentSelector||"main, article, [data-search-content]",n=new URLSearchParams(window.location.search).get(t);if(!n||!n.trim())return;let s=this.getPageHighlightRegistry(),o=`${t}::${r}`;if(s.has(o))return;s.add(o);let a=()=>{this.ensurePageHighlightStyles(),this.highlightDestinationNodes(n.trim(),r,o)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",a,{once:!0}):window.requestAnimationFrame(a)}ensurePageHighlightStyles(){if(document.getElementById(Ye))return;let t=document.createElement("style");t.id=Ye,t.textContent=`
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `,document.head.appendChild(t)}highlightDestinationNodes(t,r,n){let s=Array.from(document.querySelectorAll(r));if(s.length===0)return;let o=[...new Set(de(t).map(i=>i.trim()).filter(i=>i.length>=2))];if(o.length===0)return;let a=o.map(i=>He(i)).filter(Boolean).sort((i,c)=>c.length-i.length).join("|");if(!a)return;let l=new RegExp(`(${a})`,"gi");s.forEach(i=>{i.getAttribute("data-sm-highlighted")!==n&&(this.highlightTextNodesInScope(i,l),i.setAttribute("data-sm-highlighted",n))})}highlightTextNodesInScope(t,r){let n=document.createTreeWalker(t,NodeFilter.SHOW_TEXT,{acceptNode:o=>{let a=o.nodeValue;if(!a||!a.trim())return NodeFilter.FILTER_REJECT;let l=o.parentElement;return!l||l.closest("script, style, noscript, textarea, mark, .sm-highlight, .sm-page-highlight, search-modal")?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT}}),s=[];for(;n.nextNode();)s.push(n.currentNode);s.forEach(o=>{let a=o.nodeValue||"";if(r.lastIndex=0,!r.test(a))return;let l=document.createDocumentFragment(),i=0;r.lastIndex=0;let c=a.matchAll(r);for(let d of c){let h=d[0],m=d.index??-1;if(m<0)continue;m>i&&l.appendChild(document.createTextNode(a.slice(i,m)));let g=document.createElement("mark");g.className="sm-highlight sm-page-highlight",g.textContent=h,l.appendChild(g),i=m+h.length}i<a.length&&l.appendChild(document.createTextNode(a.slice(i))),o.parentNode?.replaceChild(l,o)})}getPageHighlightRegistry(){let t=window[Qe];if(t instanceof Set)return t;let r=new Set;return window[Qe]=r,r}updateLoadingVisual(){let t=this.getLoadingElement();if(t){let r=this.state.get("loading"),n=this.config?.loadingIndicatorEnabled!==!1;t.hidden=!r||!n}}updateDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let{debugEnabled:r}=this.config,n=this.state.getAll();if(!r||!n.meta||n.results.length===0){t.hidden=!0;return}let s=t.classList.contains("sm-collapsed");t.innerHTML=be(n.meta,n.results.length,s),t.hidden=!1,s&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}attachDebugToolbarHandlers(t){let r=t.querySelector(".sm-toolbar-toggle");r&&r.addEventListener("click",s=>{s.preventDefault(),s.stopPropagation(),this.toggleDebugToolbar()});let n=t.querySelector(".sm-toolbar-collapsed-bar");n&&n.addEventListener("click",s=>{s.preventDefault(),s.stopPropagation(),this.toggleDebugToolbar()})}toggleDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let r=t.classList.toggle("sm-collapsed"),n=this.state.getAll();t.innerHTML=be(n.meta,n.results.length,r),r&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}applyCustomStyles(){if(!this.config)return;let t=this.shadowRoot.host,{theme:r,styles:n,resultsTitleLines:s,resultsDescriptionLines:o}=this.config;Re(t,n,r),s&&t.style.setProperty("--sm-result-title-lines",String(s)),o&&t.style.setProperty("--sm-result-desc-lines",String(o))}initializeLiveRegion(){this.liveRegion=Me(this.shadowRoot)}startAnalyticsIdleTimer(t,r){this.analyticsIdleTimer&&clearTimeout(this.analyticsIdleTimer);let n=this.config.analyticsIdleTimeoutMs;!n||n<=0||(this.analyticsIdleTimer=setTimeout(()=>{this.trackSearchAnalytics(t,r,"idle")},n))}trackSearchAnalytics(t,r,n){!t||t===this.lastTrackedQuery||(this.lastTrackedQuery=t,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),Se({endpoint:this.config.trackSearchEndpoint,query:t,indexHandles:this.config.indexHandles,resultsCount:r,trigger:n,analyticsSource:this.config.analyticsSource,siteId:this.config.siteId,cached:this.lastSearchCacheState?.cached,took:this.lastSearchCacheState?.took,apiKey:this.config.apiKey}))}resetAnalyticsTracking(){this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null)}dispatchWidgetEvent(t,r={}){this.dispatchEvent(new CustomEvent(`search-${t}`,{bubbles:!0,composed:!0,detail:r}))}},Xe=fe;var Je=`/**
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
    --sm-result-active-text-color: var(--sm-result-active-text-color-dark, var(--sm-text-primary));
    --sm-result-active-desc-color: var(--sm-result-active-desc-color-dark, var(--sm-text-secondary));
    --sm-result-active-muted-color: var(--sm-result-active-muted-color-dark, var(--sm-text-muted));

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
`;var Ze=`/**
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
    --sm-modal-shadow: var(--sm-modal-shadow-dark, 0 25px 50px -12px rgba(0, 0, 0, 0.5));

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
`;var et=`/* =========================================================================
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
`;var jt=Je+`
`+Ze+`
`+et,ye=class extends Xe{constructor(){super(),this.externalTrigger=null,this.previouslyFocused=null,this.open=this.open.bind(this),this.close=this.close.bind(this),this.toggle=this.toggle.bind(this),this.handleGlobalKeydown=this.handleGlobalKeydown.bind(this),this.handleBackdropClick=this.handleBackdropClick.bind(this),this.handleTriggerClick=this.handleTriggerClick.bind(this),this.handleExternalTriggerClick=this.handleExternalTriggerClick.bind(this),this.handleCloseClick=this.handleCloseClick.bind(this)}get widgetType(){return"modal"}static get observedAttributes(){return ve("modal")}connectedCallback(){super.connectedCallback(),this.render(),this.attachEventListeners()}disconnectedCallback(){this.state.get("isOpen")&&this.close({reason:"disconnect",source:"disconnect",restoreFocus:!1}),super.disconnectedCallback(),this.detachEventListeners()}attributeChangedCallback(t,r,n){if(r===n||this.shadowRoot.children.length===0)return;if(t==="theme"){this.config=z(this,this.widgetType),this.shadowRoot.host.setAttribute("data-theme",this.config.theme),this.applyCustomStyles();return}let s=this.state.get("isOpen"),o=this.state.get("query")||"";this.detachEventListeners(),this.config=z(this,this.widgetType),this.render(),this.attachEventListeners(),s&&(this.registerOpenWidget(),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value=o,this.renderResultsContent(),this.updateLoadingVisual(),this.updateDebugToolbar(),this.updateSelectionVisual(),document.body.style.overflow=this.config.modalPreventBodyScroll?"hidden":"",requestAnimationFrame(()=>{this.isConnected&&this.elements.input.focus()}))}render(){let{theme:t,placeholder:r,triggerEnabled:n,triggerLabel:s}=this.config,o=u(this.getHotkeyDisplay()),a=u(r||""),l=u(s||"Search");this.shadowRoot.innerHTML=`
            <style>${jt}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="${l}" aria-haspopup="dialog" aria-expanded="false" ${n?"":'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">${l}</span>
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
                            placeholder="${a}"
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
        `,this.elements={trigger:this.shadowRoot.querySelector(".sm-trigger"),backdrop:this.shadowRoot.querySelector(".sm-backdrop"),modal:this.shadowRoot.querySelector(".sm-modal"),input:this.shadowRoot.querySelector(".sm-input"),results:this.shadowRoot.querySelector(".sm-results"),loading:this.shadowRoot.querySelector(".sm-loading"),close:this.shadowRoot.querySelector(".sm-close"),debugToolbar:this.shadowRoot.querySelector(".sm-debug-toolbar")},this.initializeLiveRegion(),this.shadowRoot.host.setAttribute("data-theme",t),this.applyCustomStyles()}getResultsContainer(){return this.elements.results}getInputElement(){return this.elements.input}getLoadingElement(){return this.elements.loading}applyCustomStyles(){if(super.applyCustomStyles(),!this.config)return;let{modalBackdropOpacity:t,modalBackdropBlurEnabled:r}=this.config,n=this.shadowRoot.host;n.style.setProperty("--sm-backdrop-opacity",t/100),n.style.setProperty("--sm-backdrop-blur",r?"blur(4px)":"none")}attachEventListeners(){this.elements.trigger.addEventListener("click",this.handleTriggerClick),this.elements.close.addEventListener("click",this.handleCloseClick),this.elements.backdrop.addEventListener("click",this.handleBackdropClick),this.elements.input.addEventListener("input",this.handleInput),this.elements.input.addEventListener("keydown",this.handleKeydown),document.addEventListener("keydown",this.handleGlobalKeydown);let{triggerSelector:t}=this.config;t&&(this.externalTrigger=document.querySelector(t),this.externalTrigger&&this.externalTrigger.addEventListener("click",this.handleExternalTriggerClick))}detachEventListeners(){this.elements.trigger&&this.elements.trigger.removeEventListener("click",this.handleTriggerClick),this.elements.close&&this.elements.close.removeEventListener("click",this.handleCloseClick),this.elements.backdrop&&this.elements.backdrop.removeEventListener("click",this.handleBackdropClick),this.elements.input&&(this.elements.input.removeEventListener("input",this.handleInput),this.elements.input.removeEventListener("keydown",this.handleKeydown)),document.removeEventListener("keydown",this.handleGlobalKeydown),this.externalTrigger&&(this.externalTrigger.removeEventListener("click",this.handleExternalTriggerClick),this.externalTrigger=null)}open(t={}){let r=t.source||"programmatic";if(this.state.get("isOpen")){requestAnimationFrame(()=>{this.elements.input.focus()});return}this.previouslyFocused=document.activeElement instanceof HTMLElement?document.activeElement:null,this.registerOpenWidget(),this.state.set({isOpen:!0}),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value="",this.state.set({query:"",results:[],selectedIndex:-1}),this.renderResultsContent(),requestAnimationFrame(()=>{this.elements.input.focus()}),this.config.modalPreventBodyScroll&&(document.body.style.overflow="hidden"),this.dispatchWidgetEvent("open",{source:r})}close(t={}){let r=this.state.get("isOpen");this.state.set({isOpen:!1}),this.elements.backdrop.hidden=!0,this.elements.trigger.setAttribute("aria-expanded","false"),this.unregisterOpenWidget(),this.config.modalPreventBodyScroll&&(document.body.style.overflow=""),this.resetAnalyticsTracking(),r&&t.restoreFocus!==!1&&this.previouslyFocused?.isConnected&&this.previouslyFocused.focus(),this.previouslyFocused=null,r&&this.dispatchWidgetEvent("close",{reason:t.reason||"programmatic",source:t.source||"programmatic"})}toggle(t={}){this.state.get("isOpen")?this.close({reason:t.reason||"toggle",source:t.source||"toggle"}):this.open({source:t.source||"toggle"})}handleTriggerClick(){this.toggle({source:"trigger"})}handleExternalTriggerClick(){this.toggle({source:"external-trigger"})}handleCloseClick(){this.close({reason:"close-button",source:"close-button"})}handleGlobalKeydown(t){let r=this.config.triggerHotkey.toLowerCase();if((navigator.platform.toUpperCase().indexOf("MAC")>=0?t.metaKey:t.ctrlKey)&&t.key.toLowerCase()===r){if(!this.claimHotkeyEvent(t,r))return;t.preventDefault(),this.toggle({source:"hotkey"})}t.key==="Escape"&&this.state.get("isOpen")&&(t.preventDefault(),this.close({reason:"escape",source:"escape"}))}handleEscape(){this.close({reason:"escape",source:"keyboard"})}handleBackdropClick(t){t.target===this.elements.backdrop&&this.close({reason:"backdrop",source:"backdrop"})}onResultSelected(t,r,n){this.close({reason:"result-selected",source:"result-selected"})}getHotkeyDisplay(){let t=navigator.platform.toUpperCase().indexOf("MAC")>=0,r=this.config.triggerHotkey.toUpperCase();return t?`\u2318${r}`:`Ctrl+${r}`}},Ut=ye;return at(Kt);})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
