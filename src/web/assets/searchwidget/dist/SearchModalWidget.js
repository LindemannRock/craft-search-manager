"use strict";var SearchModalWidget=(()=>{var oe=Object.defineProperty;var nt=Object.getOwnPropertyDescriptor;var st=Object.getOwnPropertyNames;var ot=Object.prototype.hasOwnProperty;var at=(e,t)=>{for(var r in t)oe(e,r,{get:t[r],enumerable:!0})},it=(e,t,r,n)=>{if(t&&typeof t=="object"||typeof t=="function")for(let s of st(t))!ot.call(e,s)&&s!==r&&oe(e,s,{get:()=>t[s],enumerable:!(n=nt(t,s))||n.enumerable});return e};var lt=e=>it(oe({},"__esModule",{value:!0}),e);var Yt={};at(Yt,{default:()=>Wt});var dt={indexHandles:[],placeholder:"Search...",theme:"light",resultsLimit:20,searchDebounceMs:200,searchMinChars:2,recentSearchesEnabled:!0,recentSearchesLimit:5,resultsGroupingEnabled:!0,siteId:"",apiKey:"",searchEndpoint:"/actions/search-manager/api/search",trackClickEndpoint:"/actions/search-manager/search/track-click",trackSearchEndpoint:"/actions/search-manager/search/track-search",analyticsIdleTimeoutMs:1500,analyticsSource:"",highlightResultsEnabled:!0,highlightTag:"mark",highlightClass:"",resultsRequireUrl:!1,snippetIncludeCodeBlocks:!1,snippetMode:"balanced",loadingIndicatorEnabled:!0,debugEnabled:!1,resultsTitleLines:1,resultsDescriptionLines:1,snippetMaxLength:150,snippetCleanMarkdown:!1,highlightDestinationPersistQuery:!0,highlightDestinationQueryParam:"smq",highlightDestinationEnabled:!0,highlightDestinationContentSelector:"main, article, [data-search-content]",resultsLayout:"default",hierarchyGroupBy:"",hierarchyStyle:"tree",hierarchyDisplay:"individual",hierarchyMaxHeadings:3,styles:{},translations:{},promotionBadge:{showBadge:!0,badgeText:"Featured",badgePosition:"top-right"}},ct={triggerHotkey:"k",triggerEnabled:!0,triggerLabel:"Search",triggerSelector:"",modalBackdropOpacity:50,modalBackdropBlurEnabled:!0,modalPreventBodyScroll:!0};function ht(e){return{...dt,...{modal:ct}[e]||{}}}function w(e,t=!1){if(e==null)return t;if(typeof e=="boolean")return e;if(typeof e=="number")return e!==0;if(e==="")return!0;let r=String(e).trim().toLowerCase();return["1","true","on","yes"].includes(r)?!0:["0","false","off","no"].includes(r)?!1:t}function I(e,t=0){if(e==null)return t;let r=Number.parseInt(e,10);return Number.isNaN(r)?t:r}function Z(e,t={}){if(!e)return t;try{return JSON.parse(e)}catch(r){return console.warn("SearchWidget: Invalid JSON attribute",r),t}}function ut(e){return e?e.split(",").map(t=>t.trim()).filter(Boolean):[]}function ee(e){return e.indexHandles.length>0?e.indexHandles.join(","):"all"}function xe(e){return e.indexHandles.length===1?e.indexHandles[0]:""}function K(e,t="modal"){let r=Z(e.getAttribute("snippet-defaults"),{}),n={...ht(t),...Object.fromEntries(Object.entries(r).filter(([m])=>["snippetIncludeCodeBlocks","snippetMode","snippetMaxLength","snippetCleanMarkdown","minSnippetLength","maxSnippetLength","snippetModes"].includes(m)))},s=Array.isArray(n.snippetModes)?n.snippetModes:["early","balanced","deep"],o=Number.isFinite(Number(n.minSnippetLength))?Number(n.minSnippetLength):50,a=Number.isFinite(Number(n.maxSnippetLength))?Number(n.maxSnippetLength):1e3,l=Math.min(a,Math.max(o,I(e.getAttribute("snippet-max-length"),n.snippetMaxLength))),i=e.getAttribute("snippet-mode")||n.snippetMode,d=e.getAttribute("index-handles")||"",u={indexHandles:ut(d),placeholder:e.getAttribute("placeholder")||n.placeholder,theme:e.getAttribute("theme")||n.theme,siteId:e.getAttribute("site-id")||n.siteId,apiKey:e.getAttribute("api-key")||n.apiKey,analyticsSource:e.getAttribute("analytics-source")||n.analyticsSource,highlightTag:e.getAttribute("highlight-tag")||n.highlightTag,highlightClass:e.getAttribute("highlight-class")||n.highlightClass,searchEndpoint:n.searchEndpoint,trackClickEndpoint:n.trackClickEndpoint,trackSearchEndpoint:n.trackSearchEndpoint,resultsLimit:I(e.getAttribute("results-limit"),n.resultsLimit),searchDebounceMs:I(e.getAttribute("search-debounce-ms"),n.searchDebounceMs),searchMinChars:I(e.getAttribute("search-min-chars"),n.searchMinChars),recentSearchesLimit:I(e.getAttribute("recent-searches-limit"),n.recentSearchesLimit),analyticsIdleTimeoutMs:I(e.getAttribute("analytics-idle-timeout-ms"),n.analyticsIdleTimeoutMs),recentSearchesEnabled:w(e.getAttribute("recent-searches-enabled"),n.recentSearchesEnabled),resultsGroupingEnabled:w(e.getAttribute("results-grouping-enabled"),n.resultsGroupingEnabled),highlightResultsEnabled:w(e.getAttribute("highlight-results-enabled"),n.highlightResultsEnabled),loadingIndicatorEnabled:w(e.getAttribute("loading-indicator-enabled"),n.loadingIndicatorEnabled),resultsRequireUrl:w(e.getAttribute("results-require-url"),n.resultsRequireUrl),snippetIncludeCodeBlocks:w(e.getAttribute("snippet-include-code-blocks"),n.snippetIncludeCodeBlocks),debugEnabled:w(e.getAttribute("debug-enabled"),n.debugEnabled),snippetMode:s.includes(i)?i:n.snippetMode,snippetMaxLength:l,snippetCleanMarkdown:w(e.getAttribute("snippet-clean-markdown"),n.snippetCleanMarkdown),highlightDestinationPersistQuery:w(e.getAttribute("highlight-destination-persist-query"),n.highlightDestinationPersistQuery),highlightDestinationEnabled:w(e.getAttribute("highlight-destination-enabled"),n.highlightDestinationEnabled),resultsTitleLines:I(e.getAttribute("results-title-lines"),n.resultsTitleLines),resultsDescriptionLines:I(e.getAttribute("results-description-lines"),n.resultsDescriptionLines),highlightDestinationQueryParam:e.getAttribute("highlight-destination-query-param")||n.highlightDestinationQueryParam,highlightDestinationContentSelector:e.getAttribute("highlight-destination-content-selector")||n.highlightDestinationContentSelector,resultsLayout:e.getAttribute("results-layout")||n.resultsLayout,hierarchyGroupBy:e.getAttribute("hierarchy-group-by")||n.hierarchyGroupBy,hierarchyStyle:e.getAttribute("hierarchy-style")||n.hierarchyStyle,hierarchyDisplay:e.getAttribute("hierarchy-display")||n.hierarchyDisplay,hierarchyMaxHeadings:I(e.getAttribute("hierarchy-max-headings"),n.hierarchyMaxHeadings),styles:Z(e.getAttribute("styles"),n.styles),translations:Z(e.getAttribute("translations"),n.translations),promotionBadge:Z(e.getAttribute("promotion-badge"),n.promotionBadge)};return t==="modal"&&Object.assign(u,{triggerHotkey:e.getAttribute("trigger-hotkey")||n.triggerHotkey,triggerLabel:e.getAttribute("trigger-label")||n.triggerLabel,triggerSelector:e.getAttribute("trigger-selector")||n.triggerSelector,modalBackdropOpacity:I(e.getAttribute("modal-backdrop-opacity"),n.modalBackdropOpacity),triggerEnabled:w(e.getAttribute("trigger-enabled"),n.triggerEnabled),modalBackdropBlurEnabled:w(e.getAttribute("modal-backdrop-blur-enabled"),n.modalBackdropBlurEnabled),modalPreventBodyScroll:w(e.getAttribute("modal-prevent-body-scroll"),n.modalPreventBodyScroll)}),u}function we(e="modal"){let t=["index-handles","placeholder","theme","results-limit","search-debounce-ms","search-min-chars","recent-searches-enabled","recent-searches-limit","results-grouping-enabled","site-id","analytics-idle-timeout-ms","analytics-source","highlight-results-enabled","highlight-tag","highlight-class","results-require-url","snippet-include-code-blocks","snippet-mode","loading-indicator-enabled","debug-enabled","styles","translations","promotion-badge","results-layout","hierarchy-group-by","hierarchy-style","hierarchy-display","hierarchy-max-headings","results-title-lines","results-description-lines","snippet-max-length","snippet-clean-markdown","highlight-destination-persist-query","highlight-destination-query-param","highlight-destination-enabled","highlight-destination-content-selector"],n={modal:["trigger-hotkey","trigger-enabled","trigger-label","trigger-selector","modal-backdrop-opacity","modal-backdrop-blur-enabled","modal-prevent-body-scroll"]};return[...t,...n[e]||[]]}var te={isOpen:!1,query:"",results:[],recentSearches:[],selectedIndex:-1,loading:!1,error:null,meta:null};function Ce(e={},t=null){let r={...te,...e};return{get(n){return r[n]},getAll(){return{...r}},set(n){let s=[];return Object.keys(n).forEach(o=>{let a=r[o],l=n[o];re(a,l)||s.push(o)}),s.length>0&&(r={...r,...n},t&&t(r,s)),s},reset(n=e){let s={...te,...n},o=Object.keys(s).filter(a=>!re(r[a],s[a]));o.length>0&&(r=s,t&&t(r,o))},is(n,s){return r[n]===s},toggle(n){let s=!r[n];return this.set({[n]:s}),s}}}function re(e,t){if(e===t)return!0;if(e==null||t==null)return!1;if(Array.isArray(e)&&Array.isArray(t))return e.length!==t.length?!1:e.every((r,n)=>re(r,t[n]));if(typeof e=="object"&&typeof t=="object"){let r=Object.keys(e),n=Object.keys(t);return r.length!==n.length?!1:r.every(s=>re(e[s],t[s]))}return!1}function g(e,t,r={}){let n=e&&Object.prototype.hasOwnProperty.call(e,t)?e[t]:t;return String(n).replace(/\{([a-zA-Z0-9_]+)\}/g,(s,o)=>Object.prototype.hasOwnProperty.call(r,o)?String(r[o]):s)}async function Se({query:e,endpoint:t,indexHandles:r=[],siteId:n="",resultsLimit:s=10,resultsRequireUrl:o=!1,snippetIncludeCodeBlocks:a=!1,snippetMode:l="",snippetMaxLength:i=0,snippetCleanMarkdown:d=!1,debugEnabled:c=!1,apiKey:u="",signal:m,translations:p={}}){let b=new URLSearchParams({q:e,resultsLimit:s.toString()});r.length>0&&b.append("indexHandles",r.join(",")),n&&b.append("siteId",n),o&&b.append("resultsRequireUrl","1"),a&&b.append("snippetIncludeCodeBlocks","1"),l&&b.append("snippetMode",l),i&&b.append("snippetMaxLength",String(i)),d&&b.append("snippetCleanMarkdown","1"),c&&b.append("debugEnabled","1"),b.append("skipAnalytics","1");let k=t.includes("?")?"&":"?",T={Accept:"application/json"};u&&(T["X-Search-Manager-Key"]=u);let f=await fetch(`${t}${k}${b}`,{signal:m,headers:T});if(!f.ok)throw new Error(await gt(f,p));let y=await f.json();return y.error&&console.warn("Search warning:",y.error),{results:y.results||y.hits||[],total:y.total||0,meta:y.meta||null,error:y.error||null}}async function gt(e,t={}){let r=await mt(e);return e.status===401?r||g(t,"Search requires an API key."):e.status===403?r||g(t,"This API key cannot access this search."):e.status===429?r||g(t,"Search rate limit exceeded. Try again in a moment."):r||g(t,"Search failed.")}async function mt(e){try{if((e.headers.get("content-type")||"").includes("application/json")){let r=await e.json(),n=r.error||r.message||"";return typeof n=="string"?n.slice(0,240):""}}catch{return""}return""}function Te({endpoint:e,elementId:t,query:r,index:n,apiKey:s=""}){if(!(!t||!e))try{let o=new FormData;o.append("elementId",t),o.append("query",r),o.append("index",n);let a={Accept:"application/json"};s&&(a["X-Search-Manager-Key"]=s),fetch(e,{method:"POST",body:o,headers:a}).catch(()=>{})}catch{}}function Ee({endpoint:e,query:t,indexHandles:r=[],resultsCount:n=0,trigger:s="unknown",analyticsSource:o="",siteId:a="",cached:l,took:i,apiKey:d=""}){if(!(!t||!e))try{let c=new FormData;c.append("q",t),c.append("indexHandles",r.join(",")),c.append("resultsCount",n.toString()),c.append("trigger",s),c.append("analyticsSource",o||"frontend-widget"),a&&c.append("siteId",a),typeof l=="boolean"&&c.append("cached",l?"1":"0"),typeof i=="number"&&Number.isFinite(i)&&i>=0&&c.append("took",i.toString());let u={Accept:"application/json"};d&&(u["X-Search-Manager-Key"]=d),fetch(e,{method:"POST",body:c,headers:u}).catch(()=>{})}catch{}}function De(e){let t={};return e.forEach(r=>{let n=r.source||r.entrySection||r.type||"Results";t[n]||(t[n]=[]),t[n].push(r)}),t}function Ae(e,t){let r={};return e.forEach(n=>{let s=(t?n[t]:null)||n.source||n.entrySection||n.type||"Results";r[s]||(r[s]=[]),r[s].push(n)}),r}var pt="sm-recent-";function ae(e){return`${pt}${e||"default"}`}function ne(e){try{let t=ae(e),r=localStorage.getItem(t);return r?JSON.parse(r):[]}catch{return[]}}function Be(e,t,r=null,n=5){if(!t||!t.trim())return ne(e);let s=ae(e),o={query:t.trim(),title:r?.title||t,url:r?.url||null,timestamp:Date.now()},a=ne(e);a=a.filter(l=>l.query!==o.query),a.unshift(o),a=a.slice(0,n);try{localStorage.setItem(s,JSON.stringify(a))}catch{}return a}function Ie(e){try{let t=ae(e);localStorage.removeItem(t)}catch{}}var Le={spinnerColor:"#3b82f6",spinnerColorDark:"#60a5fa",modalBg:"#ffffff",modalBgDark:"#1f2937",modalBorderRadius:"12",modalBorderWidth:"1",modalBorderColor:"#e5e7eb",modalBorderColorDark:"#374151",modalShadow:"0 25px 50px -12px rgba(0, 0, 0, 0.25)",modalShadowDark:"0 25px 50px -12px rgba(0, 0, 0, 0.5)",modalMaxWidth:"640",modalMaxHeight:"80",modalPaddingX:"16",modalPaddingY:"16",headerBg:"transparent",headerBgDark:"transparent",headerBorderColor:"#e5e7eb",headerBorderColorDark:"#374151",headerBorderWidth:"1",headerBorderRadius:"0",headerPaddingX:"16",headerPaddingY:"12",inputBg:"#ffffff",inputBgDark:"#1f2937",inputTextColor:"#111827",inputTextColorDark:"#f9fafb",inputPlaceholderColor:"#9ca3af",inputPlaceholderColorDark:"#9ca3af",inputBorderColor:"transparent",inputBorderColorDark:"transparent",inputFontSize:"16",inputBorderRadius:"0",inputBorderWidth:"0",inputPaddingX:"0",inputPaddingY:"0",resultBg:"transparent",resultBgDark:"transparent",resultBorderColor:"#e5e7eb",resultBorderColorDark:"#374151",resultActiveBg:"#e5e7eb",resultActiveBgDark:"#4b5563",resultActiveBorderColor:"#e5e7eb",resultActiveBorderColorDark:"#374151",resultActiveTextColor:"#111827",resultActiveTextColorDark:"#f9fafb",resultActiveDescColor:"#4b5563",resultActiveDescColorDark:"#d1d5db",resultActiveMutedColor:"#6b7280",resultActiveMutedColorDark:"#d1d5db",resultTextColor:"#111827",resultTextColorDark:"#f9fafb",resultDescColor:"#4b5563",resultDescColorDark:"#d1d5db",resultMutedColor:"#6b7280",resultMutedColorDark:"#d1d5db",resultGap:"8",resultBorderWidth:"0",resultPaddingX:"12",resultPaddingY:"12",resultBorderRadius:"8",triggerBg:"#ffffff",triggerBgDark:"#374151",triggerTextColor:"#374151",triggerTextColorDark:"#d1d5db",triggerBorderRadius:"8",triggerBorderWidth:"1",triggerBorderColor:"#d1d5db",triggerBorderColorDark:"#4b5563",triggerHoverBg:"#f9fafb",triggerHoverBgDark:"#4b5563",triggerHoverTextColor:"#111827",triggerHoverTextColorDark:"#f9fafb",triggerHoverBorderColor:"#3b82f6",triggerHoverBorderColorDark:"#60a5fa",triggerPaddingX:"12",triggerPaddingY:"8",triggerFontSize:"14",kbdBg:"#f3f4f6",kbdBgDark:"#4b5563",kbdTextColor:"#4b5563",kbdTextColorDark:"#e5e7eb",kbdBorderRadius:"4",backdropOpacity:"50",backdropBlur:"1",highlightResultsEnabled:"1",highlightTag:"",highlightClass:"",highlightBgLight:"fef08a",highlightColorLight:"854d0e",highlightBgDark:"854d0e",highlightColorDark:"fef08a",iconColor:"#3b82f6",iconColorDark:"#60a5fa",promotedBg:"#2563eb",promotedBgDark:"#2563eb",promotedColor:"#ffffff",promotedColorDark:"#ffffff"};var V={modalBg:"--sm-modal-bg",modalBgDark:"--sm-modal-bg-dark",modalBorderRadius:"--sm-modal-radius",modalBorderWidth:"--sm-modal-border-width",modalBorderColor:"--sm-modal-border-color",modalBorderColorDark:"--sm-modal-border-color-dark",modalShadow:"--sm-modal-shadow",modalShadowDark:"--sm-modal-shadow-dark",modalMaxWidth:"--sm-modal-width",modalMaxHeight:"--sm-modal-max-height",modalPaddingX:"--sm-modal-px",modalPaddingY:"--sm-modal-py",headerBg:"--sm-header-bg",headerBgDark:"--sm-header-bg-dark",headerBorderColor:"--sm-header-border-color",headerBorderColorDark:"--sm-header-border-color-dark",headerBorderWidth:"--sm-header-border-width",headerBorderRadius:"--sm-header-radius",headerPaddingX:"--sm-header-px",headerPaddingY:"--sm-header-py",inputBg:"--sm-input-bg",inputBgDark:"--sm-input-bg-dark",inputTextColor:"--sm-input-color",inputTextColorDark:"--sm-input-color-dark",inputPlaceholderColor:"--sm-input-placeholder",inputPlaceholderColorDark:"--sm-input-placeholder-dark",inputBorderColor:"--sm-input-border-color",inputBorderColorDark:"--sm-input-border-color-dark",inputFontSize:"--sm-input-font-size",inputBorderRadius:"--sm-input-radius",inputBorderWidth:"--sm-input-border-width",inputPaddingX:"--sm-input-px",inputPaddingY:"--sm-input-py",resultBg:"--sm-result-bg",resultBgDark:"--sm-result-bg-dark",resultBorderColor:"--sm-result-border-color",resultBorderColorDark:"--sm-result-border-color-dark",resultActiveBg:"--sm-result-active-bg",resultActiveBgDark:"--sm-result-active-bg-dark",resultActiveBorderColor:"--sm-result-active-border-color",resultActiveBorderColorDark:"--sm-result-active-border-color-dark",resultActiveTextColor:"--sm-result-active-text-color",resultActiveTextColorDark:"--sm-result-active-text-color-dark",resultActiveDescColor:"--sm-result-active-desc-color",resultActiveDescColorDark:"--sm-result-active-desc-color-dark",resultActiveMutedColor:"--sm-result-active-muted-color",resultActiveMutedColorDark:"--sm-result-active-muted-color-dark",resultTextColor:"--sm-result-text-color",resultTextColorDark:"--sm-result-text-color-dark",resultDescColor:"--sm-result-desc-color",resultDescColorDark:"--sm-result-desc-color-dark",resultMutedColor:"--sm-result-muted-color",resultMutedColorDark:"--sm-result-muted-color-dark",resultGap:"--sm-result-gap",resultBorderWidth:"--sm-result-border-width",resultPaddingX:"--sm-result-px",resultPaddingY:"--sm-result-py",resultBorderRadius:"--sm-result-radius",triggerBg:"--sm-trigger-bg",triggerBgDark:"--sm-trigger-bg-dark",triggerTextColor:"--sm-trigger-text-color",triggerTextColorDark:"--sm-trigger-text-color-dark",triggerBorderRadius:"--sm-trigger-radius",triggerBorderWidth:"--sm-trigger-border-width",triggerBorderColor:"--sm-trigger-border-color",triggerBorderColorDark:"--sm-trigger-border-color-dark",triggerHoverBg:"--sm-trigger-hover-bg",triggerHoverBgDark:"--sm-trigger-hover-bg-dark",triggerHoverTextColor:"--sm-trigger-hover-text-color",triggerHoverTextColorDark:"--sm-trigger-hover-text-color-dark",triggerHoverBorderColor:"--sm-trigger-hover-border-color",triggerHoverBorderColorDark:"--sm-trigger-hover-border-color-dark",triggerPaddingX:"--sm-trigger-px",triggerPaddingY:"--sm-trigger-py",triggerFontSize:"--sm-trigger-font-size",kbdBg:"--sm-kbd-bg",kbdBgDark:"--sm-kbd-bg-dark",kbdTextColor:"--sm-kbd-text-color",kbdTextColorDark:"--sm-kbd-text-color-dark",kbdBorderRadius:"--sm-kbd-radius",iconColor:"--sm-icon-color",iconColorDark:"--sm-icon-color-dark",highlightBgLight:"--sm-highlight-bg",highlightColorLight:"--sm-highlight-color",highlightBgDark:"--sm-highlight-bg-dark",highlightColorDark:"--sm-highlight-color-dark",promotedBg:"--sm-promoted-bg",promotedBgDark:"--sm-promoted-bg-dark",promotedColor:"--sm-promoted-color",promotedColorDark:"--sm-promoted-color-dark",spinnerColor:"--sm-spinner-color-light",spinnerColorDark:"--sm-spinner-color-dark"},ie=["modalBorderRadius","modalBorderWidth","modalMaxWidth","modalPaddingX","modalPaddingY","headerBorderWidth","headerBorderRadius","headerPaddingX","headerPaddingY","inputFontSize","inputBorderRadius","inputBorderWidth","inputPaddingX","inputPaddingY","resultGap","resultBorderWidth","resultPaddingX","resultPaddingY","resultBorderRadius","triggerBorderRadius","triggerBorderWidth","triggerPaddingX","triggerPaddingY","triggerFontSize","kbdBorderRadius"],le=["modalMaxHeight"],Re=["modalBg","modalBgDark","modalBorderColor","modalBorderColorDark","headerBg","headerBgDark","headerBorderColor","headerBorderColorDark","inputBg","inputBgDark","inputTextColor","inputTextColorDark","inputPlaceholderColor","inputPlaceholderColorDark","inputBorderColor","inputBorderColorDark","resultBg","resultBgDark","resultBorderColor","resultBorderColorDark","resultActiveBg","resultActiveBgDark","resultActiveBorderColor","resultActiveBorderColorDark","resultTextColor","resultTextColorDark","resultActiveTextColor","resultActiveTextColorDark","resultActiveDescColor","resultActiveDescColorDark","resultActiveMutedColor","resultActiveMutedColorDark","resultDescColor","resultDescColorDark","resultMutedColor","resultMutedColorDark","triggerBg","triggerBgDark","triggerTextColor","triggerTextColorDark","triggerBorderColor","triggerBorderColorDark","triggerHoverBg","triggerHoverBgDark","triggerHoverTextColor","triggerHoverTextColorDark","triggerHoverBorderColor","triggerHoverBorderColorDark","kbdBg","kbdBgDark","kbdTextColor","kbdTextColorDark","iconColor","iconColorDark","highlightBgLight","highlightColorLight","highlightBgDark","highlightColorDark","promotedBg","promotedBgDark","promotedColor","promotedColorDark","spinnerColor","spinnerColorDark"],sr={...Le,highlightBgLight:"#fef08a",highlightColorLight:"#854d0e",highlightBgDark:"#854d0e",highlightColorDark:"#fef08a"};var de=new WeakMap;function ft(){let e=new Set,t=new Set;for(let r of Object.keys(V)){if(r.endsWith("Dark")){t.add(r);continue}if(r.endsWith("Light")){let n=r.replace(/Light$/,"Dark");V[n]&&e.add(r);continue}V[`${r}Dark`]&&e.add(r)}return{lightKeys:e,darkKeys:t}}var $e=ft();function yt(e){return typeof e=="string"&&/^(var|light-dark|calc|env|clamp|min|max|rgb|hsl)\s*\(/.test(e.trim())}function kt(e){return/^[0-9a-fA-F]{6}$/.test(e)}function vt(e,t){if(t==null||t==="")return null;let r=String(t);return yt(r)||(Re.includes(e)&&kt(r)&&(r="#"+r),ie.includes(e)&&(r=r+"px"),le.includes(e)&&(r=r+"vh")),r}function He(e,t,r="light"){if(!e)return;let n=de.get(e);if(n){for(let i of n)e.style.removeProperty(i);de.delete(e)}if(!t||typeof t!="object")return;let s=r==="dark",o=Object.entries(V),a=new Set([...ie,...le]),l=new Set;for(let[i,d]of o){let c=$e.lightKeys.has(i),u=$e.darkKeys.has(i),m=c||u;if(s){if(c||!u&&!a.has(i))continue}else if(u)continue;if(t[i]!==void 0&&t[i]!==null&&t[i]!==""){let p=vt(i,t[i]);p&&(e.style.setProperty(d,p),m&&l.add(d))}}l.size>0&&de.set(e,l)}var xt=new Set(["mark","em","strong","u","b","i","span"]),wt=/^[A-Za-z0-9_-]+$/;function h(e){return e?String(e).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"):""}function Pe(e){return e?e.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"):""}function ce(e){if(!e)return[];let t=[],r=/"([^"]+)"/g,n;for(;(n=r.exec(e))!==null;)n[1].trim()&&t.push(n[1].trim());let s=e.replace(/"[^"]*"/g,""),o=new Set(["and","or","not","und","oder","nicht","et","ou","sauf","y","o","no"]);s.split(/\s+/).filter(l=>l.length>0).forEach(l=>{l=l.replace(/^[a-zA-Z]+:/,""),l=l.replace(/\*/g,""),l=l.replace(/\^\d+(\.\d+)?/,""),l=l.replace(/"/g,""),!(!l||o.has(l.toLowerCase()))&&t.push(l)});let a=[];return t.forEach(l=>{a.push(l);let i=l.split(/(?<=[a-z])(?=[A-Z])/);i.length>1&&i.forEach(d=>{d.length>=3&&a.push(d)})}),a}function X(e,t,r={}){let{enabled:n=!0,tag:s="mark",className:o="",terms:a=null}=r;if(!n)return h(e);let l=Ct(s),d=["sm-highlight",...St(o)],c=` class="${h(d.join(" "))}"`,u=Tt(t,a);return u.length===0?h(e):Et(e,u,l,c)}function Ct(e){let t=String(e||"mark").trim().toLowerCase();return xt.has(t)?t:"mark"}function St(e){return String(e||"").trim().split(/\s+/).filter(t=>t&&wt.test(t))}function Tt(e,t){return Array.isArray(t)&&t.length>0?Me(t):e?Me(ce(e)):[]}function Me(e){let t=new Set;return e.filter(r=>typeof r=="string"&&r.length>0).sort((r,n)=>n.length-r.length).filter(r=>{let n=r.toLowerCase();return t.has(n)?!1:(t.add(n),!0)})}function Et(e,t,r,n){let s=e.toLowerCase(),o=[];if(t.forEach(c=>{let u=c.toLowerCase();if(!u)return;let m=0;for(;m<s.length;){let p=s.indexOf(u,m);if(p===-1)break;o.push({start:p,end:p+u.length}),m=p+u.length}}),o.length===0)return h(e);o.sort((c,u)=>c.start!==u.start?c.start-u.start:u.end-u.start-(c.end-c.start));let a=[],l=-1;o.forEach(c=>{c.start>=l&&(a.push(c),l=c.end)});let i="",d=0;return a.forEach(c=>{d<c.start&&(i+=h(e.slice(d,c.start))),i+=`<${r}${n}>${h(e.slice(c.start,c.end))}</${r}>`,d=c.end}),d<e.length&&(i+=h(e.slice(d))),i}function W(e,t,r="smq"){if(!e||e==="#")return e;if(Dt(e))return"#";let n=(t||"").trim();if(!n||!r||/^(mailto:|tel:)/i.test(e))return e;let[s,o]=e.split("#",2),[a,l]=s.split("?",2),i=new URLSearchParams(l||"");i.set(r,n);let d=i.toString(),c=o?`#${o}`:"";return`${a}${d?`?${d}`:""}${c}`}function Dt(e){let t=String(e).replace(/[\t\n\r]/g,"").replace(/^[\u0000-\u0020]+/,"");return/^(javascript|data|vbscript):/i.test(t)}var At=0;function he(e="sm"){return`${e}-${++At}-${Date.now().toString(36)}`}function Oe(e){let t=document.createElement("div");return t.setAttribute("role","status"),t.setAttribute("aria-live","polite"),t.setAttribute("aria-atomic","true"),t.className="sm-sr-only",e.appendChild(t),t}function J(e,t,r=100){e&&(e.textContent="",setTimeout(()=>{e.textContent=t},r))}function ue(e,t,r={}){return e===0?g(r,'No results found for "{query}"',{query:t}):e===1?g(r,'1 result found for "{query}"',{query:t}):g(r,'{count} results found for "{query}"',{count:e,query:t})}function Ne(e={}){return g(e,"Searching...")}function _e(e,t={}){return e===0?g(t,"No recent searches"):e===1?g(t,"1 recent search available"):g(t,"{count} recent searches available",{count:e})}function se(e,{expanded:t,activeDescendant:r,listboxId:n}){e.setAttribute("aria-expanded",String(t)),e.setAttribute("aria-controls",n),r?e.setAttribute("aria-activedescendant",r):e.removeAttribute("aria-activedescendant")}function z(e,t){return`${e}-option-${t}`}function Fe(e,t){if(!e||!t)return;let r=e.getBoundingClientRect(),n=t.getBoundingClientRect();r.top<n.top?e.scrollIntoView({block:"nearest",behavior:"smooth"}):r.bottom>n.bottom&&e.scrollIntoView({block:"nearest",behavior:"smooth"})}function Bt(e,t,r={}){let{resultsGroupingEnabled:n=!1,resultsLayout:s="default",listboxId:o}=r;if(!e||e.length===0)return"";if(s==="hierarchical")return _t(e,t,r);if(n){let a=De(e),l=0;return Object.entries(a).map(([i,d])=>`
            <div class="sm-section" role="group" aria-label="${h(i)}">
                <div class="sm-section-header">${h(i)}</div>
                ${d.map(c=>qe(c,l++,t,r)).join("")}
            </div>
        `).join("")}return e.map((a,l)=>qe(a,l,t,r)).join("")}function qe(e,t,r,n={}){let{listboxId:s,highlightResultsEnabled:o=!0,highlightTag:a="mark",highlightClass:l="",resultsGroupingEnabled:i=!1,promotionBadge:d={},debugEnabled:c=!1,highlightDestinationPersistQuery:u=!1,highlightDestinationQueryParam:m="smq",translations:p={}}=n,b=pe(e),k=b?e.sectionTitle||e.title||e.name||g(p,"Untitled"):e.title||e.name||g(p,"Untitled"),T=e.snippet||"",f=b?e.sectionUrl||e.url||e.href||"#":e.url||e.href||"#",y=W(f,r,u?m:""),E=e.source||e.entrySection||e.type||"",v=z(s,t),x=e.promoted===!0,S=e._index||e.index||"",R=be(e),$={enabled:o,tag:a,className:l},q=X(k,r,{...$,terms:F(e,"title")}),D=T?me(e,T,r,{...$,terms:F(e,"snippet")}):"",A=It(e,d),H=x?" sm-promoted":"",M=E&&!i?`<span class="sm-result-type">${h(E)}</span>`:"",P=c?je(e,p):"";return c?`
            <a class="sm-result-item sm-debug-enabled${H}" id="${v}" role="option" aria-selected="false" href="${h(y)}" data-index="${t}" data-source-index="${h(S)}"${R} data-title="${h(k)}">
                <div class="sm-result-main">
                    ${A}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${q}</span>
                        ${D?`<span class="sm-result-desc">${D}</span>`:""}
                    </div>
                    ${M}
                    ${j()}
                </div>
                ${P}
            </a>
        `:`
        <a class="sm-result-item${H}" id="${v}" role="option" aria-selected="false" href="${h(y)}" data-index="${t}" data-source-index="${h(S)}"${R} data-title="${h(k)}">
            ${A}
            <div class="sm-result-content">
                <span class="sm-result-title">${q}</span>
                ${D?`<span class="sm-result-desc">${D}</span>`:""}
            </div>
            ${M}
            ${j()}
        </a>
    `}function je(e,t={}){let r=[],n=e.backend?e.backend.toLowerCase():"";if((e._index||e.index)&&r.push(C("index",e._index||e.index,"index","",t)),e.backend&&r.push(C("backend",n,"backend",n,t)),e.elementId&&r.push(C("element",e.elementId,"generic","",t)),e.backendId&&r.push(C("hit",e.backendId,"generic","",t)),e.score!==void 0&&e.score!==null){let s=typeof e.score=="number"?e.score.toFixed(2):e.score;r.push(C("score",s,"score","",t))}if(e.site&&r.push(C("site",e.site,"generic","",t)),e.language&&r.push(C("lang",e.language,"generic","",t)),e.matchedIn&&Array.isArray(e.matchedIn)&&e.matchedIn.length>0){let s=e.matchedIn.join(", ");r.push(C("matched",s,"matched","",t))}return e.promoted&&r.push(C("promoted",g(t,"yes"),"promoted","",t)),e.boosted&&r.push(C("boosted",g(t,"yes"),"boosted","",t)),r.length===0?"":`<div class="sm-debug-info">${r.join("")}</div>`}function F(e,t){let r=Array.isArray(e.matchedPhrases)?e.matchedPhrases:[],n=e.matchedTerms,s=[];n&&(t==="title"&&Array.isArray(n.title)&&n.title.length>0?s=n.title:t==="snippet"&&Array.isArray(n.content)&&n.content.length>0?s=n.content:s=[...Array.isArray(n.title)?n.title:[],...Array.isArray(n.content)?n.content:[]]);let o=[...r,...s];return o.length>0?o:null}function me(e,t,r,n){return X(t,r,n)}function C(e,t,r,n="",s={}){let o=n?` data-backend="${h(n)}"`:"";return`<span class="sm-debug-item"><span class="sm-debug-label">${h(g(s,e))}</span><span class="sm-debug-value" data-type="${h(r)}"${o}>${h(String(t))}</span></span>`}function It(e,t={}){let{showBadge:r=!0,badgeText:n="Featured",badgePosition:s="top-right"}=t;return!e.promoted||!r?"":`<span class="sm-promoted-badge ${`sm-promoted-badge--${new Set(["top-right","top-left","inline"]).has(s)?s:"top-right"}`}">${h(n)}</span>`}function pe(e){return!!(e&&typeof e=="object"&&["heading","intro","promoted-page"].includes(String(e.sectionType||"")))}function Lt(e){return Array.isArray(e)&&e.some(pe)}function Rt(e,t){let r=new Map,n=[];return e.forEach((s,o)=>{if(!pe(s)){n.push({type:"single",item:s,order:o,score:_(s)});return}let a=Mt(s);if(!r.has(a)){let d={hits:[],order:o,score:_(s)};r.set(a,d),n.push({type:"section-group",key:a,order:o,score:d.score})}let l=r.get(a);l.hits.push(s),l.score=Math.max(l.score,_(s));let i=n.find(d=>d.type==="section-group"&&d.key===a);i&&(i.score=l.score)}),n.map(s=>{if(s.type==="section-group"){let o=r.get(s.key);return{...s,item:$t(o.hits,t)}}return s}).sort((s,o)=>{let a=ge(o.score,s.score);return a!==0?a:s.order-o.order}).map(s=>s.item)}function $t(e,t){let r=[...e].sort((c,u)=>N(c)-N(u)),n=r.find(c=>c.sectionType==="intro")||null,s=[...e].sort((c,u)=>{let m=ge(_(u),_(c));return m!==0?m:N(c)-N(u)})[0]||r[0]||{},o=n||s,a=Y(o),l=o.siteId??"",i=Number.isFinite(t)&&t>0?t:3,d=r.filter(c=>c.sectionType==="heading").sort((c,u)=>{let m=ge(_(u),_(c));return m!==0?m:N(c)-N(u)}).slice(0,i).sort((c,u)=>N(c)-N(u)).map(Ht);return{...o,elementId:a||o.elementId,backendId:n?.backendId||o.backendId||Pt(a,l),title:o.title||o.sectionTitle||o.name||"",url:o.url||"#",snippet:n&&n.snippet||null,score:_(s),headings:d,__sectionHitGroup:!0,__useBackendDomId:!0}}function Ht(e){let t=Number.parseInt(e.sectionLevel,10),r=Number.isFinite(t)?t:2;return{title:e.sectionTitle||e.title||"",text:e.sectionTitle||e.title||"",id:e.sectionAnchor||e.sectionId||"",level:r,url:e.sectionUrl||e.url||null,snippet:e.snippet||null,backendId:e.backendId||"",elementId:Y(e),sectionType:e.sectionType,_index:e._index,index:e.index,matchedTerms:e.matchedTerms,matchedPhrases:e.matchedPhrases,__useBackendDomId:!0}}function Mt(e){return[Y(e)||Ue(e)||"",e.siteId??""].join(":")}function N(e){let t=Number.parseInt(e.sectionIndex,10);return Number.isFinite(t)?t:Number.MAX_SAFE_INTEGER}function _(e){let t=Number(e?.score);return Number.isFinite(t)?t:Number.NEGATIVE_INFINITY}function ge(e,t){return e===t?0:e>t?1:-1}function Pt(e,t){let r=e||"unknown";return t!=null&&String(t)!==""?`${r}_${t}`:String(r)}function Ue(e,t=null){return e?.backendId||t?.backendId||""}function Y(e,t=null){return e?.elementId||t?.elementId||""}function be(e,t=null){let r=Ue(e,t)||Y(e,t),n=Y(e,t);return` data-id="${h(r)}" data-element-id="${h(n)}"`}function j(){return`<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M5 12h14M12 5l7 7-7 7"/>
    </svg>`}function Ot(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/>
        <line x1="16" y1="17" x2="8" y2="17"/>
        <polyline points="10 9 9 9 8 9"/>
    </svg>`}function Nt(){return`<svg class="sm-hierarchy-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <line x1="4" y1="7" x2="20" y2="7"/>
        <line x1="4" y1="12" x2="20" y2="12"/>
        <line x1="4" y1="17" x2="14" y2="17"/>
    </svg>`}function Ge(){return`<svg class="sm-hierarchy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="9" x2="20" y2="9"/>
        <line x1="4" y1="15" x2="20" y2="15"/>
        <line x1="10" y1="3" x2="8" y2="21"/>
        <line x1="16" y1="3" x2="14" y2="21"/>
    </svg>`}function _t(e,t,r={}){let{hierarchyGroupBy:n="",hierarchyStyle:s="tree",hierarchyDisplay:o="individual",hierarchyMaxHeadings:a=3,listboxId:l}=r,i=s==="tree",d=s!=="none",c=Lt(e)?Rt(e,a):e,m=Ae(c,n||""),p=0;return Object.entries(m).map(([b,k])=>{let T=k.map(f=>{let y=p++,E=Ft(f,y,t,r),v="",x=f.headings||[],S=f.__sectionHitGroup?x:x.slice(0,a);if(S.length>0){let q=Math.min(...S.map(A=>A.level||2)),D=S.map(A=>i?(A.level||2)-q:0);v=S.map((A,H)=>{let M=D[H],P=!D.slice(H+1).some(U=>U===M),Q=[];if(i){let U=D.slice(H+1);for(let G=0;G<M;G++)U.some(O=>O===G)&&Q.push(G)}return qt(f,A,p++,t,r,P,M,Q)}).join("")}let R=!!v;return`
                <div class="sm-hierarchy-block${R?" sm-hierarchy-block--has-children":""}${o==="unified"?" sm-hierarchy-block--unified":""}">
                    ${R?E.replace("sm-result-item sm-hierarchy-parent","sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children"):E}
                    ${R?`<div class="sm-hierarchy-children${d?"":" sm-hierarchy-children--no-connectors"}">${v}</div>`:""}
                </div>
            `}).join("");return`
            <div class="sm-hierarchy-group" role="group" aria-label="${h(b)}">
                <div class="sm-hierarchy-group-header">${h(b)}</div>
                ${T}
            </div>
        `}).join("")}function Ft(e,t,r,n={}){let{listboxId:s,highlightResultsEnabled:o=!0,highlightTag:a="mark",highlightClass:l="",debugEnabled:i=!1,highlightDestinationPersistQuery:d=!1,highlightDestinationQueryParam:c="smq",translations:u={}}=n,m=e.title||e.name||g(u,"Untitled"),p=e.snippet||"",b=e.url||"#",k=W(b,r,d?c:""),T=z(s,t),f=e._index||e.index||"",y=be(e),E={enabled:o,tag:a,className:l},v=X(m,r,{...E,terms:F(e,"title")}),x=p?me(e,p,r,{...E,terms:F(e,"snippet")}):"",S=i?je(e,u):"",$=e.headings&&e.headings.length>0?Ot():Nt();return i?`
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled" id="${T}" role="option" aria-selected="false" href="${h(k)}" data-index="${t}" data-source-index="${h(f)}"${y} data-title="${h(m)}">
                <div class="sm-result-main">
                    ${$}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${v}</span>
                        ${x?`<span class="sm-result-desc">${x}</span>`:""}
                    </div>
                    ${j()}
                </div>
                ${S}
            </a>
        `:`
        <a class="sm-result-item sm-hierarchy-parent" id="${T}" role="option" aria-selected="false" href="${h(k)}" data-index="${t}" data-source-index="${h(f)}"${y} data-title="${h(m)}">
            ${$}
            <div class="sm-result-content">
                <span class="sm-result-title">${v}</span>
                ${x?`<span class="sm-result-desc">${x}</span>`:""}
            </div>
            ${j()}
        </a>
    `}function qt(e,t,r,n,s={},o=!1,a=0,l=[]){let{listboxId:i,highlightResultsEnabled:d=!0,highlightTag:c="mark",highlightClass:u="",debugEnabled:m=!1,highlightDestinationPersistQuery:p=!1,highlightDestinationQueryParam:b="smq",translations:k={}}=s,f=(t.title||t.text||"").replace(/^#+\s*/,""),y=t.snippet||"",E=Number.parseInt(t.level,10),v=Number.isFinite(E)?Math.min(Math.max(E,1),6):2,x=t.id||(f?Gt(f):""),S=e.url||"#",R=t.url||(x?`${S}#${x}`:S),$=W(R,n,p?b:""),q=z(i,r),D=t._index||t.index||e._index||e.index||"",A=be(t,e),H={enabled:d,tag:c,className:u},M=X(f,n,{...H,terms:F(t,"title")||F(e,"title")}),P=y?me(e,y,n,{...H,terms:F(t,"snippet")||F(e,"snippet")}):"",Q=o?" sm-hierarchy-child-row-last":"",U=l.map(O=>`<div class="sm-hierarchy-guide" style="--sm-guide-depth:${O}" aria-hidden="true"></div>`).join(""),G="";if(m){let O=[];O.push(C("h",v,"generic","",k)),x&&O.push(C("anchor",x,"generic","",k));let ve=Y(t,e);ve&&O.push(C("parent",ve,"generic","",k)),G=`<div class="sm-debug-info">${O.join("")}</div>`}return m?`
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${v} sm-hierarchy-depth-${a}${Q}" style="--sm-hierarchy-depth:${a}">
                ${U}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${v} sm-debug-enabled" id="${q}" role="option" aria-selected="false" href="${h($)}" data-index="${r}" data-source-index="${h(D)}"${A} data-title="${h(f)}">
                    <div class="sm-result-main">
                        ${Ge()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${M}</span>
                            ${P?`<span class="sm-result-desc">${P}</span>`:""}
                        </div>
                        ${j()}
                    </div>
                    ${G}
                </a>
            </div>
        `:`
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${v} sm-hierarchy-depth-${a}${Q}" style="--sm-hierarchy-depth:${a}">
            ${U}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${v}" id="${q}" role="option" aria-selected="false" href="${h($)}" data-index="${r}" data-source-index="${h(D)}"${A} data-title="${h(f)}">
                ${Ge()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${M}</span>
                    ${P?`<span class="sm-result-desc">${P}</span>`:""}
                </div>
                ${j()}
            </a>
        </div>
    `}function Gt(e){let t=e.normalize("NFKD").toLowerCase();try{return t.replace(/[^\p{L}\p{N}]+/gu,"-").replace(/^-+|-+$/g,"")}catch{return t.replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"")}}function zt(e,t,r={}){return!e||e.length===0?"":`
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${t}-recent-label">${h(g(r,"Recent searches"))}</span>
                <button class="sm-clear-recent" part="clear-recent">${h(g(r,"Clear"))}</button>
            </div>
            ${e.map((n,s)=>`
                <div class="sm-result-item sm-recent-item" id="${z(t,s)}" role="option" aria-selected="false" data-index="${s}" data-url="${h(n.url||"")}" data-query="${h(n.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${h(n.title||n.query)}</span>
                    ${j()}
                </div>
            `).join("")}
        </div>
    `}function ze(e,t={}){return!e||!e.trim()?`
            <div class="sm-empty" part="empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <p>${h(g(t,"Start typing to search"))}</p>
            </div>
        `:`
        <div class="sm-empty" part="empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <path d="m15 9-6 6M9 9l6 6"/>
            </svg>
            <p>${h(g(t,'No results for "{query}"',{query:e}))}</p>
        </div>
    `}function jt(e={}){return`
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>${h(g(e,"Searching..."))}</p>
        </div>
    `}function Ut(e,t={}){return`
        <div class="sm-empty sm-error" part="error">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>${h(e||g(t,"Search failed."))}</p>
        </div>
    `}function Ke(e,t){let{query:r,results:n,recentSearches:s,loading:o,recentSearchesEnabled:a,error:l}=e,{loadingIndicatorEnabled:i=!0,translations:d={}}=t,c=r&&r.trim();return o&&i?{html:jt(d),hasResults:!1,showListbox:!1}:l?{html:Ut(l,d),hasResults:!1,showListbox:!1}:c?!n||n.length===0?{html:ze(r,d),hasResults:!1,showListbox:!1}:{html:Bt(n,r,t),hasResults:!0,showListbox:!0}:a&&s&&s.length>0?{html:zt(s,t.listboxId,d),hasResults:!0,showListbox:!0}:{html:ze("",d),hasResults:!1,showListbox:!1}}function fe(e,t,r=!1,n={}){if(!e)return"";let s=[];if(s.push(L("results",t,"generic","",n)),e.took!==void 0){let d=e.took<1?"<1ms":`${Math.round(e.took)}ms`;s.push(L("time",d,"time","",n))}if(e.cacheEnabled!==void 0&&(e.cacheEnabled?e.cached?s.push(L("cache",g(n,"hit"),"cache-hit","",n)):s.push(L("cache",g(n,"miss"),"cache-miss","",n)):s.push(L("cache",g(n,"off"),"cache-off","",n))),e.cacheDriver&&s.push(L("storage",e.cacheDriver,"cache-driver",e.cacheDriver,n)),e.indices&&e.indices.length>0){let d=e.indices.length>2?g(n,"{count} indices",{count:e.indices.length}):e.indices.join(", ");s.push(L("indices",d,"generic","",n))}if(e.synonymsExpanded){let d=e.expandedQueries?e.expandedQueries.length-1:0;s.push(L("synonyms",`+${d}`,"synonyms","",n))}let o=e.rulesMatched?.length||0;s.push(L("rules",o,o>0?"rules":"generic","",n));let a=e.promotionsMatched?.length||0;s.push(L("promoted",a,a>0?"promotions":"generic","",n));let i=`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${r?'<path d="M6 9l6 6 6-6"/>':'<path d="M18 15l-6-6-6 6"/>'}</svg>`;return r?`<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">${h(g(n,"Debug"))}</span>${i}</div>`:`<div class="sm-toolbar-content">${s.join("")}</div><button class="sm-toolbar-toggle" aria-label="${h(g(n,"Collapse debug panel"))}" aria-expanded="true">${i}</button>`}function L(e,t,r,n="",s={}){let o=n?` data-backend="${h(n)}"`:"";return`<span class="sm-toolbar-item"><span class="sm-toolbar-label">${h(g(s,e))}</span><span class="sm-toolbar-value" data-type="${h(r)}"${o}>${h(String(t))}</span></span>`}function We(e,t){let{onSelect:r,onIndexChange:n,onEscape:s}=e,{listboxId:o}=t;return{handleKeydown(a,l,i){let d=i;switch(a.key){case"ArrowDown":return a.preventDefault(),d=Math.min(i+1,l-1),d!==i&&n&&n(d),d;case"ArrowUp":return a.preventDefault(),d=Math.max(i-1,-1),d!==i&&n&&n(d),d;case"Enter":return a.preventDefault(),i>=0&&r&&r(i),null;case"Escape":return a.preventDefault(),s&&s(),null;default:return null}},getListboxId(){return o}}}function Ye(e,t,r={}){let{scrollContainer:n,inputElement:s,listboxId:o,selectedClass:a="sm-selected"}=r,l=t>=0?z(o,t):null;s&&se(s,{expanded:e.length>0,activeDescendant:l,listboxId:o}),e.forEach((i,d)=>{let c=d===t;i.classList.toggle(a,c),i.setAttribute("aria-selected",String(c)),c&&n&&Fe(i,n)})}function Qe(e,t){e.forEach((r,n)=>{r.addEventListener("mouseenter",()=>{t&&t(n)})})}var Ve="sm-page-highlight-style",Xe="__smPageHighlightRegistry",Je="__searchManagerHotkeyHandled",B=null,ye=class extends HTMLElement{constructor(){super(),this.attachShadow({mode:"open"}),this.config=null,this.state=Ce({...te},this.handleStateChange.bind(this)),this.searchSequence=0,this.debounceTimer=null,this.analyticsIdleTimer=null,this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.listboxId=he("sm-listbox"),this.inputId=he("sm-input"),this.liveRegion=null,this.keyboardNavigator=null,this.elements={},this.handleInput=this.handleInput.bind(this),this.handleKeydown=this.handleKeydown.bind(this),this.handleResultClick=this.handleResultClick.bind(this)}get widgetType(){throw new Error("Subclass must implement widgetType getter")}render(){throw new Error("Subclass must implement render()")}getResultsContainer(){throw new Error("Subclass must implement getResultsContainer()")}getInputElement(){throw new Error("Subclass must implement getInputElement()")}getLoadingElement(){return this.elements.loading||null}getDebugToolbarElement(){return this.elements.debugToolbar||null}connectedCallback(){this.config=K(this,this.widgetType),this.state.set({recentSearches:ne(ee(this.config))}),this.keyboardNavigator=We({onSelect:t=>this.selectResultAtIndex(t),onIndexChange:t=>this.state.set({selectedIndex:t}),onEscape:()=>this.handleEscape()},{listboxId:this.listboxId}),this.applyDestinationPageHighlight()}disconnectedCallback(){this.unregisterOpenWidget(),this.searchSequence++,this.debounceTimer&&(clearTimeout(this.debounceTimer),this.debounceTimer=null)}registerOpenWidget(){B&&B!==this&&typeof B.close=="function"&&B.close({reason:"replace",replacedBy:this,source:"replace"}),B=this}unregisterOpenWidget(){B===this&&(B=null)}claimHotkeyEvent(t,r){return t[Je]||B&&B!==this&&B.state?.get("isOpen")&&B.config?.triggerHotkey?.toLowerCase()===r?!1:(t[Je]=!0,!0)}attributeChangedCallback(t,r,n){r!==n&&this.shadowRoot.children.length>0&&(this.config=K(this,this.widgetType),this.render(),this.applyCustomStyles())}handleStateChange(t,r){(r.includes("results")||r.includes("query")||r.includes("recentSearches")||r.includes("error"))&&this.renderResultsContent(),(r.includes("results")||r.includes("meta"))&&this.updateDebugToolbar(),r.includes("selectedIndex")&&this.updateSelectionVisual(),r.includes("loading")&&this.updateLoadingVisual()}handleInput(t){let r=t.target.value;if(this.state.set({query:r,selectedIndex:-1}),this.debounceTimer&&clearTimeout(this.debounceTimer),this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),!r.trim()){this.state.set({results:[]});return}r.length<this.config.searchMinChars||(this.debounceTimer=setTimeout(()=>{this.executeSearch(r)},this.config.searchDebounceMs))}async executeSearch(t){let r=++this.searchSequence;this.state.set({loading:!0,error:null}),this.liveRegion&&J(this.liveRegion,Ne(this.config.translations));try{let{results:n,meta:s}=await Se({query:t,endpoint:this.config.searchEndpoint,indexHandles:this.config.indexHandles,siteId:this.config.siteId,resultsLimit:this.config.resultsLimit,resultsRequireUrl:this.config.resultsRequireUrl,snippetIncludeCodeBlocks:this.config.snippetIncludeCodeBlocks,snippetMode:this.config.snippetMode,snippetMaxLength:this.config.snippetMaxLength,snippetCleanMarkdown:this.config.snippetCleanMarkdown,debugEnabled:this.config.debugEnabled,apiKey:this.config.apiKey,translations:this.config.translations});if(r!==this.searchSequence)return;this.state.set({results:n,meta:s,loading:!1,selectedIndex:n.length>0?0:-1}),s&&typeof s.cached=="boolean"?this.lastSearchCacheState={cached:s.cached,took:typeof s.took=="number"?s.took:null}:this.lastSearchCacheState=null,this.liveRegion&&J(this.liveRegion,ue(n.length,t,this.config.translations)),this.dispatchWidgetEvent("search",{query:t,results:n,meta:s}),this.startAnalyticsIdleTimer(t,n.length)}catch(n){if(r!==this.searchSequence||n.name==="AbortError")return;console.error("Search error:",n),this.state.set({results:[],loading:!1,error:n.message}),this.dispatchWidgetEvent("error",{query:t,error:n.message})}}renderResultsContent(){let t=this.getResultsContainer();if(!t)return;let r=this.state.getAll(),{recentSearchesEnabled:n,resultsGroupingEnabled:s,highlightResultsEnabled:o,highlightTag:a,highlightClass:l,loadingIndicatorEnabled:i,debugEnabled:d}=this.config,{html:c,hasResults:u,showListbox:m}=Ke({query:r.query,results:r.results,recentSearches:r.recentSearches,loading:r.loading,error:r.error,recentSearchesEnabled:n},{listboxId:this.listboxId,resultsGroupingEnabled:s,highlightResultsEnabled:o,highlightTag:a,highlightClass:l,loadingIndicatorEnabled:i,debugEnabled:d,translations:this.config.translations,highlightDestinationPersistQuery:this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery,highlightDestinationQueryParam:this.config.highlightDestinationQueryParam,promotionBadge:this.config.promotionBadge,resultsLayout:this.config.resultsLayout,hierarchyGroupBy:this.config.hierarchyGroupBy,hierarchyStyle:this.config.hierarchyStyle,hierarchyDisplay:this.config.hierarchyDisplay,hierarchyMaxHeadings:this.config.hierarchyMaxHeadings});t.innerHTML=c,m?t.setAttribute("role","listbox"):t.removeAttribute("role");let p=this.getInputElement();p&&se(p,{expanded:u,activeDescendant:null,listboxId:this.listboxId}),this.liveRegion&&!r.loading&&(r.query&&r.results.length===0?J(this.liveRegion,ue(0,r.query,this.config.translations)):!r.query&&r.recentSearches.length>0&&n&&J(this.liveRegion,_e(r.recentSearches.length,this.config.translations))),this.attachResultHandlers();let b=t.querySelector(".sm-clear-recent");b&&b.addEventListener("click",k=>{k.stopPropagation(),Ie(ee(this.config)),this.state.set({recentSearches:[]})}),u&&r.results.length>0&&this.state.set({selectedIndex:0})}attachResultHandlers(){let t=this.getResultsContainer();if(!t)return;let r=t.querySelectorAll(".sm-result-item");r.forEach(n=>{n.addEventListener("click",s=>this.handleResultClick(s,n))}),Qe(r,n=>{this.state.set({selectedIndex:n})})}updateSelectionVisual(){let t=this.getResultsContainer(),r=this.getInputElement();if(!t)return;let n=t.querySelectorAll(".sm-result-item"),s=this.state.get("selectedIndex");Ye(n,s,{scrollContainer:t,inputElement:r,listboxId:this.listboxId})}handleKeydown(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item"),s=this.state.get("selectedIndex");if(t.key==="Enter"){let o=this.state.get("query"),a=this.state.get("results")||[];o&&a.length>0&&this.trackSearchAnalytics(o,a.length,"enter")}this.keyboardNavigator.handleKeydown(t,n.length,s)}selectResultAtIndex(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item");t>=0&&n[t]&&n[t].click()}handleEscape(){}handleResultClick(t,r){let n=r.getAttribute("href"),s=r.dataset.url,o=n||s,a=r.dataset.title||r.querySelector(".sm-result-title")?.textContent,l=r.dataset.id,i=r.dataset.elementId||l,d=r.dataset.query||this.state.get("query"),c=r.classList.contains("sm-recent-item"),u=W(o,d,this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery?this.config.highlightDestinationQueryParam:"");if(!c&&d){let p=Be(ee(this.config),d,{title:a,url:o},this.config.recentSearchesLimit);this.state.set({recentSearches:p})}let m=r.dataset.sourceIndex||xe(this.config);if(i&&m&&Te({endpoint:this.config.trackClickEndpoint,elementId:i,query:d,index:m,apiKey:this.config.apiKey}),!c&&d&&this.trackSearchAnalytics(d,this.state.get("results")?.length||0,"click"),this.dispatchWidgetEvent("result-click",{id:l,elementId:i,title:a,url:u,query:d,isRecent:c}),o&&o!=="#")c&&(t.preventDefault(),window.location.href=u),this.onResultSelected(u,a,l);else if(d){t.preventDefault();let p=this.getInputElement();p&&(p.value=d,this.state.set({query:d}),this.executeSearch(d))}}onResultSelected(t,r,n){}applyDestinationPageHighlight(){if(!this.config.highlightDestinationEnabled||typeof window>"u"||typeof document>"u")return;let t=this.config.highlightDestinationQueryParam||"smq",r=this.config.highlightDestinationContentSelector||"main, article, [data-search-content]",n=new URLSearchParams(window.location.search).get(t);if(!n||!n.trim())return;let s=this.getPageHighlightRegistry(),o=`${t}::${r}`;if(s.has(o))return;s.add(o);let a=()=>{this.ensurePageHighlightStyles(),this.highlightDestinationNodes(n.trim(),r,o)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",a,{once:!0}):window.requestAnimationFrame(a)}ensurePageHighlightStyles(){if(document.getElementById(Ve))return;let t=document.createElement("style");t.id=Ve,t.textContent=`
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `,document.head.appendChild(t)}highlightDestinationNodes(t,r,n){let s=Array.from(document.querySelectorAll(r));if(s.length===0)return;let o=[...new Set(ce(t).map(i=>i.trim()).filter(i=>i.length>=2))];if(o.length===0)return;let a=o.map(i=>Pe(i)).filter(Boolean).sort((i,d)=>d.length-i.length).join("|");if(!a)return;let l=new RegExp(`(${a})`,"gi");s.forEach(i=>{i.getAttribute("data-sm-highlighted")!==n&&(this.highlightTextNodesInScope(i,l),i.setAttribute("data-sm-highlighted",n))})}highlightTextNodesInScope(t,r){let n=document.createTreeWalker(t,NodeFilter.SHOW_TEXT,{acceptNode:o=>{let a=o.nodeValue;if(!a||!a.trim())return NodeFilter.FILTER_REJECT;let l=o.parentElement;return!l||l.closest("script, style, noscript, textarea, mark, .sm-highlight, .sm-page-highlight, search-modal")?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT}}),s=[];for(;n.nextNode();)s.push(n.currentNode);s.forEach(o=>{let a=o.nodeValue||"";if(r.lastIndex=0,!r.test(a))return;let l=document.createDocumentFragment(),i=0;r.lastIndex=0;let d=a.matchAll(r);for(let c of d){let u=c[0],m=c.index??-1;if(m<0)continue;m>i&&l.appendChild(document.createTextNode(a.slice(i,m)));let p=document.createElement("mark");p.className="sm-highlight sm-page-highlight",p.textContent=u,l.appendChild(p),i=m+u.length}i<a.length&&l.appendChild(document.createTextNode(a.slice(i))),o.parentNode?.replaceChild(l,o)})}getPageHighlightRegistry(){let t=window[Xe];if(t instanceof Set)return t;let r=new Set;return window[Xe]=r,r}updateLoadingVisual(){let t=this.getLoadingElement();if(t){let r=this.state.get("loading"),n=this.config?.loadingIndicatorEnabled!==!1;t.hidden=!r||!n}}updateDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let{debugEnabled:r}=this.config,n=this.state.getAll();if(!r||!n.meta||n.results.length===0){t.hidden=!0;return}let s=t.classList.contains("sm-collapsed");t.innerHTML=fe(n.meta,n.results.length,s,this.config.translations),t.hidden=!1,s&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}attachDebugToolbarHandlers(t){let r=t.querySelector(".sm-toolbar-toggle");r&&r.addEventListener("click",s=>{s.preventDefault(),s.stopPropagation(),this.toggleDebugToolbar()});let n=t.querySelector(".sm-toolbar-collapsed-bar");n&&n.addEventListener("click",s=>{s.preventDefault(),s.stopPropagation(),this.toggleDebugToolbar()})}toggleDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let r=t.classList.toggle("sm-collapsed"),n=this.state.getAll();t.innerHTML=fe(n.meta,n.results.length,r,this.config.translations),r&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}applyCustomStyles(){if(!this.config)return;let t=this.shadowRoot.host,{theme:r,styles:n,resultsTitleLines:s,resultsDescriptionLines:o}=this.config;He(t,n,r),s&&t.style.setProperty("--sm-result-title-lines",String(s)),o&&t.style.setProperty("--sm-result-desc-lines",String(o))}initializeLiveRegion(){this.liveRegion=Oe(this.shadowRoot)}startAnalyticsIdleTimer(t,r){this.analyticsIdleTimer&&clearTimeout(this.analyticsIdleTimer);let n=this.config.analyticsIdleTimeoutMs;!n||n<=0||(this.analyticsIdleTimer=setTimeout(()=>{this.trackSearchAnalytics(t,r,"idle")},n))}trackSearchAnalytics(t,r,n){!t||t===this.lastTrackedQuery||(this.lastTrackedQuery=t,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),Ee({endpoint:this.config.trackSearchEndpoint,query:t,indexHandles:this.config.indexHandles,resultsCount:r,trigger:n,analyticsSource:this.config.analyticsSource,siteId:this.config.siteId,cached:this.lastSearchCacheState?.cached,took:this.lastSearchCacheState?.took,apiKey:this.config.apiKey}))}resetAnalyticsTracking(){this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null)}dispatchWidgetEvent(t,r={}){this.dispatchEvent(new CustomEvent(`search-${t}`,{bubbles:!0,composed:!0,detail:r}))}},Ze=ye;var et=`/**
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
`;var tt=`/**
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
`;var rt=`/* =========================================================================
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
`;var Kt=et+`
`+tt+`
`+rt,ke=class extends Ze{constructor(){super(),this.externalTrigger=null,this.previouslyFocused=null,this.open=this.open.bind(this),this.close=this.close.bind(this),this.toggle=this.toggle.bind(this),this.handleGlobalKeydown=this.handleGlobalKeydown.bind(this),this.handleBackdropClick=this.handleBackdropClick.bind(this),this.handleTriggerClick=this.handleTriggerClick.bind(this),this.handleExternalTriggerClick=this.handleExternalTriggerClick.bind(this),this.handleCloseClick=this.handleCloseClick.bind(this)}get widgetType(){return"modal"}static get observedAttributes(){return we("modal")}connectedCallback(){super.connectedCallback(),this.render(),this.attachEventListeners()}disconnectedCallback(){this.state.get("isOpen")&&this.close({reason:"disconnect",source:"disconnect",restoreFocus:!1}),super.disconnectedCallback(),this.detachEventListeners()}attributeChangedCallback(t,r,n){if(r===n||this.shadowRoot.children.length===0)return;if(t==="theme"){this.config=K(this,this.widgetType),this.shadowRoot.host.setAttribute("data-theme",this.config.theme),this.applyCustomStyles();return}let s=this.state.get("isOpen"),o=this.state.get("query")||"";this.detachEventListeners(),this.config=K(this,this.widgetType),this.render(),this.attachEventListeners(),s&&(this.registerOpenWidget(),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value=o,this.renderResultsContent(),this.updateLoadingVisual(),this.updateDebugToolbar(),this.updateSelectionVisual(),document.body.style.overflow=this.config.modalPreventBodyScroll?"hidden":"",requestAnimationFrame(()=>{this.isConnected&&this.elements.input.focus()}))}render(){let{theme:t,placeholder:r,triggerEnabled:n,triggerLabel:s,translations:o}=this.config,a=h(this.getHotkeyDisplay()),l=h(r||""),i=h(s||g(o,"Search")),d=h(g(o,"Search")),c=h(g(o,"Close search")),u=h(g(o,"Search results"));this.shadowRoot.innerHTML=`
            <style>${Kt}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="${i}" aria-haspopup="dialog" aria-expanded="false" ${n?"":'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">${i}</span>
                <kbd class="sm-trigger-kbd" aria-hidden="true">${a}</kbd>
            </button>

            <!-- Modal backdrop -->
            <div class="sm-backdrop" part="backdrop" hidden>
                <div class="sm-modal" part="modal" role="dialog" aria-modal="true" aria-label="${d}">
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
                            placeholder="${l}"
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
                        <button class="sm-close" part="close" aria-label="${c}">
                            <kbd>esc</kbd>
                        </button>
                    </div>

                    <!-- Results -->
                    <div class="sm-results" part="results" id="${this.listboxId}" role="listbox" aria-label="${u}"></div>

                    <!-- Debug toolbar (sticky at bottom) -->
                    <div class="sm-debug-toolbar" part="debug-toolbar" hidden></div>

                    <!-- Footer -->
                    <div class="sm-footer" part="footer">
                        <div class="sm-footer-hints">
                            <span><kbd>\u2191</kbd><kbd>\u2193</kbd> ${h(g(o,"navigate"))}</span>
                            <span><kbd>\u21B5</kbd> ${h(g(o,"select"))}</span>
                            <span><kbd>esc</kbd> ${h(g(o,"close"))}</span>
                        </div>
                        <div class="sm-footer-brand">
                            ${h(g(o,"Powered by"))} <strong>Search Manager</strong>
                        </div>
                    </div>
                </div>
            </div>
        `,this.elements={trigger:this.shadowRoot.querySelector(".sm-trigger"),backdrop:this.shadowRoot.querySelector(".sm-backdrop"),modal:this.shadowRoot.querySelector(".sm-modal"),input:this.shadowRoot.querySelector(".sm-input"),results:this.shadowRoot.querySelector(".sm-results"),loading:this.shadowRoot.querySelector(".sm-loading"),close:this.shadowRoot.querySelector(".sm-close"),debugToolbar:this.shadowRoot.querySelector(".sm-debug-toolbar")},this.initializeLiveRegion(),this.shadowRoot.host.setAttribute("data-theme",t),this.applyCustomStyles()}getResultsContainer(){return this.elements.results}getInputElement(){return this.elements.input}getLoadingElement(){return this.elements.loading}applyCustomStyles(){if(super.applyCustomStyles(),!this.config)return;let{modalBackdropOpacity:t,modalBackdropBlurEnabled:r}=this.config,n=this.shadowRoot.host;n.style.setProperty("--sm-backdrop-opacity",t/100),n.style.setProperty("--sm-backdrop-blur",r?"blur(4px)":"none")}attachEventListeners(){this.elements.trigger.addEventListener("click",this.handleTriggerClick),this.elements.close.addEventListener("click",this.handleCloseClick),this.elements.backdrop.addEventListener("click",this.handleBackdropClick),this.elements.input.addEventListener("input",this.handleInput),this.elements.input.addEventListener("keydown",this.handleKeydown),document.addEventListener("keydown",this.handleGlobalKeydown);let{triggerSelector:t}=this.config;t&&(this.externalTrigger=document.querySelector(t),this.externalTrigger&&this.externalTrigger.addEventListener("click",this.handleExternalTriggerClick))}detachEventListeners(){this.elements.trigger&&this.elements.trigger.removeEventListener("click",this.handleTriggerClick),this.elements.close&&this.elements.close.removeEventListener("click",this.handleCloseClick),this.elements.backdrop&&this.elements.backdrop.removeEventListener("click",this.handleBackdropClick),this.elements.input&&(this.elements.input.removeEventListener("input",this.handleInput),this.elements.input.removeEventListener("keydown",this.handleKeydown)),document.removeEventListener("keydown",this.handleGlobalKeydown),this.externalTrigger&&(this.externalTrigger.removeEventListener("click",this.handleExternalTriggerClick),this.externalTrigger=null)}open(t={}){let r=t.source||"programmatic";if(this.state.get("isOpen")){requestAnimationFrame(()=>{this.elements.input.focus()});return}this.previouslyFocused=document.activeElement instanceof HTMLElement?document.activeElement:null,this.registerOpenWidget(),this.state.set({isOpen:!0}),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value="",this.state.set({query:"",results:[],selectedIndex:-1}),this.renderResultsContent(),requestAnimationFrame(()=>{this.elements.input.focus()}),this.config.modalPreventBodyScroll&&(document.body.style.overflow="hidden"),this.dispatchWidgetEvent("open",{source:r})}close(t={}){let r=this.state.get("isOpen");this.state.set({isOpen:!1}),this.elements.backdrop.hidden=!0,this.elements.trigger.setAttribute("aria-expanded","false"),this.unregisterOpenWidget(),this.config.modalPreventBodyScroll&&(document.body.style.overflow=""),this.resetAnalyticsTracking(),r&&t.restoreFocus!==!1&&this.previouslyFocused?.isConnected&&this.previouslyFocused.focus(),this.previouslyFocused=null,r&&this.dispatchWidgetEvent("close",{reason:t.reason||"programmatic",source:t.source||"programmatic"})}toggle(t={}){this.state.get("isOpen")?this.close({reason:t.reason||"toggle",source:t.source||"toggle"}):this.open({source:t.source||"toggle"})}handleTriggerClick(){this.toggle({source:"trigger"})}handleExternalTriggerClick(){this.toggle({source:"external-trigger"})}handleCloseClick(){this.close({reason:"close-button",source:"close-button"})}handleGlobalKeydown(t){let r=this.config.triggerHotkey.toLowerCase();if((navigator.platform.toUpperCase().indexOf("MAC")>=0?t.metaKey:t.ctrlKey)&&t.key.toLowerCase()===r){if(!this.claimHotkeyEvent(t,r))return;t.preventDefault(),this.toggle({source:"hotkey"})}t.key==="Escape"&&this.state.get("isOpen")&&(t.preventDefault(),this.close({reason:"escape",source:"escape"}))}handleEscape(){this.close({reason:"escape",source:"keyboard"})}handleBackdropClick(t){t.target===this.elements.backdrop&&this.close({reason:"backdrop",source:"backdrop"})}onResultSelected(t,r,n){this.close({reason:"result-selected",source:"result-selected"})}getHotkeyDisplay(){let t=navigator.platform.toUpperCase().indexOf("MAC")>=0,r=this.config.triggerHotkey.toUpperCase();return t?`\u2318${r}`:`Ctrl+${r}`}},Wt=ke;return lt(Yt);})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
