"use strict";var SearchModalWidget=(()=>{var se=Object.defineProperty;var nt=Object.getOwnPropertyDescriptor;var ot=Object.getOwnPropertyNames;var st=Object.prototype.hasOwnProperty;var at=(e,t)=>{for(var r in t)se(e,r,{get:t[r],enumerable:!0})},it=(e,t,r,n)=>{if(t&&typeof t=="object"||typeof t=="function")for(let o of ot(t))!st.call(e,o)&&o!==r&&se(e,o,{get:()=>t[o],enumerable:!(n=nt(t,o))||n.enumerable});return e};var lt=e=>it(se({},"__esModule",{value:!0}),e);var Yt={};at(Yt,{default:()=>Wt});var ct={indexHandles:[],placeholder:"Search...",theme:"light",resultsLimit:20,searchDebounceMs:200,searchMinChars:2,recentSearchesEnabled:!0,recentSearchesLimit:5,resultsGroupingEnabled:!0,siteId:"",apiKey:"",searchEndpoint:"/actions/search-manager/api/search",trackClickEndpoint:"/actions/search-manager/search/track-click",trackSearchEndpoint:"/actions/search-manager/search/track-search",analyticsIdleTimeoutMs:1500,analyticsSource:"",highlightResultsEnabled:!0,highlightTag:"mark",highlightClass:"",resultsRequireUrl:!1,snippetIncludeCodeBlocks:!1,snippetMode:"balanced",loadingIndicatorEnabled:!0,debugEnabled:!1,resultsTitleLines:1,resultsDescriptionLines:1,snippetMaxLength:150,snippetCleanMarkdown:!1,highlightDestinationPersistQuery:!0,highlightDestinationQueryParam:"smq",highlightDestinationEnabled:!0,highlightDestinationContentSelector:"main, article, [data-search-content]",resultsLayout:"default",hierarchyGroupBy:"",hierarchyStyle:"tree",hierarchyDisplay:"individual",hierarchyMaxHeadings:3,styles:{},translations:{},promotionBadge:{showBadge:!0,badgeText:"Featured",badgePosition:"top-right"}},dt={triggerHotkey:"k",triggerEnabled:!0,triggerLabel:"Search",triggerSelector:"",modalBackdropOpacity:50,modalBackdropBlurEnabled:!0,modalPreventBodyScroll:!0};function ht(e){return{...ct,...{modal:dt}[e]||{}}}function C(e,t=!1){if(e==null)return t;if(typeof e=="boolean")return e;if(typeof e=="number")return e!==0;if(e==="")return!0;let r=String(e).trim().toLowerCase();return["1","true","on","yes"].includes(r)?!0:["0","false","off","no"].includes(r)?!1:t}function B(e,t=0){if(e==null)return t;let r=Number.parseInt(e,10);return Number.isNaN(r)?t:r}function Z(e,t={}){if(!e)return t;try{return JSON.parse(e)}catch(r){return console.warn("SearchWidget: Invalid JSON attribute",r),t}}function ut(e){return e?e.split(",").map(t=>t.trim()).filter(Boolean):[]}function ee(e){return e.indexHandles.length>0?e.indexHandles.join(","):"all"}function xe(e){return e.indexHandles.length===1?e.indexHandles[0]:""}function K(e,t="modal"){let r=Z(e.getAttribute("snippet-defaults"),{}),n={...ht(t),...Object.fromEntries(Object.entries(r).filter(([m])=>["snippetIncludeCodeBlocks","snippetMode","snippetMaxLength","snippetCleanMarkdown","minSnippetLength","maxSnippetLength","snippetModes"].includes(m)))},o=Array.isArray(n.snippetModes)?n.snippetModes:["early","balanced","deep"],s=Number.isFinite(Number(n.minSnippetLength))?Number(n.minSnippetLength):50,a=Number.isFinite(Number(n.maxSnippetLength))?Number(n.maxSnippetLength):1e3,l=Math.min(a,Math.max(s,B(e.getAttribute("snippet-max-length"),n.snippetMaxLength))),i=e.getAttribute("snippet-mode")||n.snippetMode,c=e.getAttribute("index-handles")||"",u={indexHandles:ut(c),placeholder:e.getAttribute("placeholder")||n.placeholder,theme:e.getAttribute("theme")||n.theme,siteId:e.getAttribute("site-id")||n.siteId,apiKey:e.getAttribute("api-key")||n.apiKey,analyticsSource:e.getAttribute("analytics-source")||n.analyticsSource,highlightTag:e.getAttribute("highlight-tag")||n.highlightTag,highlightClass:e.getAttribute("highlight-class")||n.highlightClass,searchEndpoint:n.searchEndpoint,trackClickEndpoint:n.trackClickEndpoint,trackSearchEndpoint:n.trackSearchEndpoint,resultsLimit:B(e.getAttribute("results-limit"),n.resultsLimit),searchDebounceMs:B(e.getAttribute("search-debounce-ms"),n.searchDebounceMs),searchMinChars:B(e.getAttribute("search-min-chars"),n.searchMinChars),recentSearchesLimit:B(e.getAttribute("recent-searches-limit"),n.recentSearchesLimit),analyticsIdleTimeoutMs:B(e.getAttribute("analytics-idle-timeout-ms"),n.analyticsIdleTimeoutMs),recentSearchesEnabled:C(e.getAttribute("recent-searches-enabled"),n.recentSearchesEnabled),resultsGroupingEnabled:C(e.getAttribute("results-grouping-enabled"),n.resultsGroupingEnabled),highlightResultsEnabled:C(e.getAttribute("highlight-results-enabled"),n.highlightResultsEnabled),loadingIndicatorEnabled:C(e.getAttribute("loading-indicator-enabled"),n.loadingIndicatorEnabled),resultsRequireUrl:C(e.getAttribute("results-require-url"),n.resultsRequireUrl),snippetIncludeCodeBlocks:C(e.getAttribute("snippet-include-code-blocks"),n.snippetIncludeCodeBlocks),debugEnabled:C(e.getAttribute("debug-enabled"),n.debugEnabled),snippetMode:o.includes(i)?i:n.snippetMode,snippetMaxLength:l,snippetCleanMarkdown:C(e.getAttribute("snippet-clean-markdown"),n.snippetCleanMarkdown),highlightDestinationPersistQuery:C(e.getAttribute("highlight-destination-persist-query"),n.highlightDestinationPersistQuery),highlightDestinationEnabled:C(e.getAttribute("highlight-destination-enabled"),n.highlightDestinationEnabled),resultsTitleLines:B(e.getAttribute("results-title-lines"),n.resultsTitleLines),resultsDescriptionLines:B(e.getAttribute("results-description-lines"),n.resultsDescriptionLines),highlightDestinationQueryParam:e.getAttribute("highlight-destination-query-param")||n.highlightDestinationQueryParam,highlightDestinationContentSelector:e.getAttribute("highlight-destination-content-selector")||n.highlightDestinationContentSelector,resultsLayout:e.getAttribute("results-layout")||n.resultsLayout,hierarchyGroupBy:e.getAttribute("hierarchy-group-by")||n.hierarchyGroupBy,hierarchyStyle:e.getAttribute("hierarchy-style")||n.hierarchyStyle,hierarchyDisplay:e.getAttribute("hierarchy-display")||n.hierarchyDisplay,hierarchyMaxHeadings:B(e.getAttribute("hierarchy-max-headings"),n.hierarchyMaxHeadings),styles:Z(e.getAttribute("styles"),n.styles),translations:Z(e.getAttribute("translations"),n.translations),promotionBadge:Z(e.getAttribute("promotion-badge"),n.promotionBadge)};return t==="modal"&&Object.assign(u,{triggerHotkey:e.getAttribute("trigger-hotkey")||n.triggerHotkey,triggerLabel:e.getAttribute("trigger-label")||n.triggerLabel,triggerSelector:e.getAttribute("trigger-selector")||n.triggerSelector,modalBackdropOpacity:B(e.getAttribute("modal-backdrop-opacity"),n.modalBackdropOpacity),triggerEnabled:C(e.getAttribute("trigger-enabled"),n.triggerEnabled),modalBackdropBlurEnabled:C(e.getAttribute("modal-backdrop-blur-enabled"),n.modalBackdropBlurEnabled),modalPreventBodyScroll:C(e.getAttribute("modal-prevent-body-scroll"),n.modalPreventBodyScroll)}),u}function Ce(e="modal"){let t=["index-handles","placeholder","theme","results-limit","search-debounce-ms","search-min-chars","recent-searches-enabled","recent-searches-limit","results-grouping-enabled","site-id","analytics-idle-timeout-ms","analytics-source","highlight-results-enabled","highlight-tag","highlight-class","results-require-url","snippet-include-code-blocks","snippet-mode","loading-indicator-enabled","debug-enabled","styles","translations","promotion-badge","results-layout","hierarchy-group-by","hierarchy-style","hierarchy-display","hierarchy-max-headings","results-title-lines","results-description-lines","snippet-max-length","snippet-clean-markdown","highlight-destination-persist-query","highlight-destination-query-param","highlight-destination-enabled","highlight-destination-content-selector"],n={modal:["trigger-hotkey","trigger-enabled","trigger-label","trigger-selector","modal-backdrop-opacity","modal-backdrop-blur-enabled","modal-prevent-body-scroll"]};return[...t,...n[e]||[]]}var te={isOpen:!1,query:"",results:[],recentSearches:[],selectedIndex:-1,loading:!1,error:null,meta:null};function we(e={},t=null){let r={...te,...e};return{get(n){return r[n]},getAll(){return{...r}},set(n){let o=[];return Object.keys(n).forEach(s=>{let a=r[s],l=n[s];re(a,l)||o.push(s)}),o.length>0&&(r={...r,...n},t&&t(r,o)),o},reset(n=e){let o={...te,...n},s=Object.keys(o).filter(a=>!re(r[a],o[a]));s.length>0&&(r=o,t&&t(r,s))},is(n,o){return r[n]===o},toggle(n){let o=!r[n];return this.set({[n]:o}),o}}}function re(e,t){if(e===t)return!0;if(e==null||t==null)return!1;if(Array.isArray(e)&&Array.isArray(t))return e.length!==t.length?!1:e.every((r,n)=>re(r,t[n]));if(typeof e=="object"&&typeof t=="object"){let r=Object.keys(e),n=Object.keys(t);return r.length!==n.length?!1:r.every(o=>re(e[o],t[o]))}return!1}function g(e,t,r={}){let n=e&&Object.prototype.hasOwnProperty.call(e,t)?e[t]:t;return String(n).replace(/\{([a-zA-Z0-9_]+)\}/g,(o,s)=>Object.prototype.hasOwnProperty.call(r,s)?String(r[s]):o)}async function Se({query:e,endpoint:t,indexHandles:r=[],siteId:n="",resultsLimit:o=10,resultsRequireUrl:s=!1,snippetIncludeCodeBlocks:a=!1,snippetMode:l="",snippetMaxLength:i=0,snippetCleanMarkdown:c=!1,debugEnabled:d=!1,apiKey:u="",signal:m,translations:p={}}){let b=new URLSearchParams({q:e,resultsLimit:o.toString()});r.length>0&&b.append("indexHandles",r.join(",")),n&&b.append("siteId",n),s&&b.append("resultsRequireUrl","1"),a&&b.append("snippetIncludeCodeBlocks","1"),l&&b.append("snippetMode",l),i&&b.append("snippetMaxLength",String(i)),c&&b.append("snippetCleanMarkdown","1"),d&&b.append("debugEnabled","1"),b.append("skipAnalytics","1");let y=t.includes("?")?"&":"?",D={Accept:"application/json"};u&&(D["X-Search-Manager-Key"]=u);let f=await fetch(`${t}${y}${b}`,{signal:m,headers:D});if(!f.ok)throw new Error(await gt(f,p));let v=await f.json();return v.error&&console.warn("Search warning:",v.error),{results:v.results||v.hits||[],total:v.total||0,meta:v.meta||null,error:v.error||null}}async function gt(e,t={}){let r=await mt(e);return e.status===401?r||g(t,"Search requires an API key."):e.status===403?r||g(t,"This API key cannot access this search."):e.status===429?r||g(t,"Search rate limit exceeded. Try again in a moment."):r||g(t,"Search failed.")}async function mt(e){try{if((e.headers.get("content-type")||"").includes("application/json")){let r=await e.json(),n=r.error||r.message||"";return typeof n=="string"?n.slice(0,240):""}}catch{return""}return""}function De({endpoint:e,elementId:t,query:r,index:n,apiKey:o=""}){if(!(!t||!e))try{let s=new FormData;s.append("elementId",t),s.append("query",r),s.append("index",n);let a={Accept:"application/json"};o&&(a["X-Search-Manager-Key"]=o),fetch(e,{method:"POST",body:s,headers:a}).catch(()=>{})}catch{}}function Te({endpoint:e,query:t,indexHandles:r=[],resultsCount:n=0,trigger:o="unknown",analyticsSource:s="",siteId:a="",cached:l,took:i,apiKey:c=""}){if(!(!t||!e))try{let d=new FormData;d.append("q",t),d.append("indexHandles",r.join(",")),d.append("resultsCount",n.toString()),d.append("trigger",o),d.append("analyticsSource",s||"frontend-widget"),a&&d.append("siteId",a),typeof l=="boolean"&&d.append("cached",l?"1":"0"),typeof i=="number"&&Number.isFinite(i)&&i>=0&&d.append("took",i.toString());let u={Accept:"application/json"};c&&(u["X-Search-Manager-Key"]=c),fetch(e,{method:"POST",body:d,headers:u}).catch(()=>{})}catch{}}function Ae(e){let t={};return e.forEach(r=>{let n=r.source||r.entrySection||r.type||"Results";t[n]||(t[n]=[]),t[n].push(r)}),t}function Ee(e,t){let r={};return e.forEach(n=>{let o=(t?n[t]:null)||n.source||n.entrySection||n.type||"Results";r[o]||(r[o]=[]),r[o].push(n)}),r}var pt="sm-recent-";function ae(e){return`${pt}${e||"default"}`}function ne(e){try{let t=ae(e),r=localStorage.getItem(t);return r?JSON.parse(r):[]}catch{return[]}}function Ie(e,t,r=null,n=5){if(!t||!t.trim())return ne(e);let o=ae(e),s={query:t.trim(),title:r?.title||t,url:r?.url||null,timestamp:Date.now()},a=ne(e);a=a.filter(l=>l.query!==s.query),a.unshift(s),a=a.slice(0,n);try{localStorage.setItem(o,JSON.stringify(a))}catch{}return a}function Be(e){try{let t=ae(e);localStorage.removeItem(t)}catch{}}var Le={spinnerColor:"#3b82f6",spinnerColorDark:"#60a5fa",modalBg:"#ffffff",modalBgDark:"#1f2937",modalBorderRadius:"12",modalBorderWidth:"1",modalBorderColor:"#e5e7eb",modalBorderColorDark:"#374151",modalShadow:"0 25px 50px -12px rgba(0, 0, 0, 0.25)",modalShadowDark:"0 25px 50px -12px rgba(0, 0, 0, 0.5)",modalMaxWidth:"640",modalMaxHeight:"80",modalPaddingX:"16",modalPaddingY:"16",headerBg:"transparent",headerBgDark:"transparent",headerBorderColor:"#e5e7eb",headerBorderColorDark:"#374151",headerBorderWidth:"1",headerBorderRadius:"0",headerPaddingX:"16",headerPaddingY:"12",inputBg:"#ffffff",inputBgDark:"#1f2937",inputTextColor:"#111827",inputTextColorDark:"#f9fafb",inputPlaceholderColor:"#9ca3af",inputPlaceholderColorDark:"#9ca3af",inputBorderColor:"transparent",inputBorderColorDark:"transparent",inputFontSize:"16",inputBorderRadius:"0",inputBorderWidth:"0",inputPaddingX:"0",inputPaddingY:"0",resultBg:"transparent",resultBgDark:"transparent",resultBorderColor:"#e5e7eb",resultBorderColorDark:"#374151",resultActiveBg:"#e5e7eb",resultActiveBgDark:"#4b5563",resultActiveBorderColor:"#e5e7eb",resultActiveBorderColorDark:"#374151",resultActiveTextColor:"#111827",resultActiveTextColorDark:"#f9fafb",resultActiveDescColor:"#4b5563",resultActiveDescColorDark:"#d1d5db",resultActiveMutedColor:"#6b7280",resultActiveMutedColorDark:"#d1d5db",resultTextColor:"#111827",resultTextColorDark:"#f9fafb",resultDescColor:"#4b5563",resultDescColorDark:"#d1d5db",resultMutedColor:"#6b7280",resultMutedColorDark:"#d1d5db",resultGap:"8",resultBorderWidth:"0",resultPaddingX:"12",resultPaddingY:"12",resultBorderRadius:"8",triggerBg:"#ffffff",triggerBgDark:"#374151",triggerTextColor:"#374151",triggerTextColorDark:"#d1d5db",triggerBorderRadius:"8",triggerBorderWidth:"1",triggerBorderColor:"#d1d5db",triggerBorderColorDark:"#4b5563",triggerHoverBg:"#f9fafb",triggerHoverBgDark:"#4b5563",triggerHoverTextColor:"#111827",triggerHoverTextColorDark:"#f9fafb",triggerHoverBorderColor:"#3b82f6",triggerHoverBorderColorDark:"#60a5fa",triggerPaddingX:"12",triggerPaddingY:"8",triggerFontSize:"14",kbdBg:"#f3f4f6",kbdBgDark:"#4b5563",kbdTextColor:"#4b5563",kbdTextColorDark:"#e5e7eb",kbdBorderRadius:"4",backdropOpacity:"50",backdropBlur:"1",highlightResultsEnabled:"1",highlightTag:"",highlightClass:"",highlightBgLight:"fef08a",highlightColorLight:"854d0e",highlightBgDark:"854d0e",highlightColorDark:"fef08a",highlightActiveBgLight:"",highlightActiveColorLight:"",highlightActiveBgDark:"",highlightActiveColorDark:"",iconColor:"#3b82f6",iconColorDark:"#60a5fa",hierarchyConnectorColor:"",hierarchyConnectorColorDark:"",searchIconColor:"",searchIconColorDark:"",clearIconColor:"",clearIconColorDark:"",resultIconColor:"",resultIconColorDark:"",arrowColor:"",arrowColorDark:"",iconActiveColor:"",iconActiveColorDark:"",resultIconActiveColor:"",resultIconActiveColorDark:"",hierarchyConnectorActiveColor:"",hierarchyConnectorActiveColorDark:"",promotedBg:"#2563eb",promotedBgDark:"#2563eb",promotedColor:"#ffffff",promotedColorDark:"#ffffff",scrollbarColor:"",scrollbarColorDark:"",footerBg:"",footerBgDark:"",footerTextColor:"",footerTextColorDark:"",footerPaddingX:"16",footerPaddingY:"16"};var V={modalBg:"--sm-modal-bg",modalBgDark:"--sm-modal-bg-dark",modalBorderRadius:"--sm-modal-radius",modalBorderWidth:"--sm-modal-border-width",modalBorderColor:"--sm-modal-border-color",modalBorderColorDark:"--sm-modal-border-color-dark",modalShadow:"--sm-modal-shadow",modalShadowDark:"--sm-modal-shadow-dark",modalMaxWidth:"--sm-modal-width",modalMaxHeight:"--sm-modal-max-height",modalPaddingX:"--sm-modal-px",modalPaddingY:"--sm-modal-py",headerBg:"--sm-header-bg",headerBgDark:"--sm-header-bg-dark",headerBorderColor:"--sm-header-border-color",headerBorderColorDark:"--sm-header-border-color-dark",headerBorderWidth:"--sm-header-border-width",headerBorderRadius:"--sm-header-radius",headerPaddingX:"--sm-header-px",headerPaddingY:"--sm-header-py",inputBg:"--sm-input-bg",inputBgDark:"--sm-input-bg-dark",inputTextColor:"--sm-input-color",inputTextColorDark:"--sm-input-color-dark",inputPlaceholderColor:"--sm-input-placeholder",inputPlaceholderColorDark:"--sm-input-placeholder-dark",inputBorderColor:"--sm-input-border-color",inputBorderColorDark:"--sm-input-border-color-dark",inputFontSize:"--sm-input-font-size",inputBorderRadius:"--sm-input-radius",inputBorderWidth:"--sm-input-border-width",inputPaddingX:"--sm-input-px",inputPaddingY:"--sm-input-py",resultBg:"--sm-result-bg",resultBgDark:"--sm-result-bg-dark",resultBorderColor:"--sm-result-border-color",resultBorderColorDark:"--sm-result-border-color-dark",resultActiveBg:"--sm-result-active-bg",resultActiveBgDark:"--sm-result-active-bg-dark",resultActiveBorderColor:"--sm-result-active-border-color",resultActiveBorderColorDark:"--sm-result-active-border-color-dark",resultActiveTextColor:"--sm-result-active-text-color",resultActiveTextColorDark:"--sm-result-active-text-color-dark",resultActiveDescColor:"--sm-result-active-desc-color",resultActiveDescColorDark:"--sm-result-active-desc-color-dark",resultActiveMutedColor:"--sm-result-active-muted-color",resultActiveMutedColorDark:"--sm-result-active-muted-color-dark",resultTextColor:"--sm-result-text-color",resultTextColorDark:"--sm-result-text-color-dark",resultDescColor:"--sm-result-desc-color",resultDescColorDark:"--sm-result-desc-color-dark",resultMutedColor:"--sm-result-muted-color",resultMutedColorDark:"--sm-result-muted-color-dark",resultGap:"--sm-result-gap",resultBorderWidth:"--sm-result-border-width",resultPaddingX:"--sm-result-px",resultPaddingY:"--sm-result-py",resultBorderRadius:"--sm-result-radius",triggerBg:"--sm-trigger-bg",triggerBgDark:"--sm-trigger-bg-dark",triggerTextColor:"--sm-trigger-text-color",triggerTextColorDark:"--sm-trigger-text-color-dark",triggerBorderRadius:"--sm-trigger-radius",triggerBorderWidth:"--sm-trigger-border-width",triggerBorderColor:"--sm-trigger-border-color",triggerBorderColorDark:"--sm-trigger-border-color-dark",triggerHoverBg:"--sm-trigger-hover-bg",triggerHoverBgDark:"--sm-trigger-hover-bg-dark",triggerHoverTextColor:"--sm-trigger-hover-text-color",triggerHoverTextColorDark:"--sm-trigger-hover-text-color-dark",triggerHoverBorderColor:"--sm-trigger-hover-border-color",triggerHoverBorderColorDark:"--sm-trigger-hover-border-color-dark",triggerPaddingX:"--sm-trigger-px",triggerPaddingY:"--sm-trigger-py",triggerFontSize:"--sm-trigger-font-size",kbdBg:"--sm-kbd-bg",kbdBgDark:"--sm-kbd-bg-dark",kbdTextColor:"--sm-kbd-text-color",kbdTextColorDark:"--sm-kbd-text-color-dark",kbdBorderRadius:"--sm-kbd-radius",iconColor:"--sm-icon-color",iconColorDark:"--sm-icon-color-dark",searchIconColor:"--sm-search-icon-color",searchIconColorDark:"--sm-search-icon-color-dark",clearIconColor:"--sm-clear-icon-color",clearIconColorDark:"--sm-clear-icon-color-dark",resultIconColor:"--sm-result-icon-color",resultIconColorDark:"--sm-result-icon-color-dark",arrowColor:"--sm-arrow-color",arrowColorDark:"--sm-arrow-color-dark",iconActiveColor:"--sm-icon-active-color",iconActiveColorDark:"--sm-icon-active-color-dark",resultIconActiveColor:"--sm-result-icon-active-color",resultIconActiveColorDark:"--sm-result-icon-active-color-dark",hierarchyConnectorActiveColor:"--sm-hierarchy-connector-active-color",hierarchyConnectorActiveColorDark:"--sm-hierarchy-connector-active-color-dark",hierarchyConnectorColor:"--sm-hierarchy-connector-color",hierarchyConnectorColorDark:"--sm-hierarchy-connector-color-dark",highlightBgLight:"--sm-highlight-bg",highlightColorLight:"--sm-highlight-color",highlightBgDark:"--sm-highlight-bg-dark",highlightColorDark:"--sm-highlight-color-dark",highlightActiveBgLight:"--sm-highlight-active-bg",highlightActiveColorLight:"--sm-highlight-active-color",highlightActiveBgDark:"--sm-highlight-active-bg-dark",highlightActiveColorDark:"--sm-highlight-active-color-dark",promotedBg:"--sm-promoted-bg",promotedBgDark:"--sm-promoted-bg-dark",promotedColor:"--sm-promoted-color",promotedColorDark:"--sm-promoted-color-dark",spinnerColor:"--sm-spinner-color-light",spinnerColorDark:"--sm-spinner-color-dark",scrollbarColor:"--sm-scrollbar-color",scrollbarColorDark:"--sm-scrollbar-color-dark",footerBg:"--sm-footer-bg",footerBgDark:"--sm-footer-bg-dark",footerTextColor:"--sm-footer-text-color",footerTextColorDark:"--sm-footer-text-color-dark",footerPaddingX:"--sm-footer-px",footerPaddingY:"--sm-footer-py"},ie=["modalBorderRadius","modalBorderWidth","modalMaxWidth","modalPaddingX","modalPaddingY","headerBorderWidth","headerBorderRadius","headerPaddingX","headerPaddingY","inputFontSize","inputBorderRadius","inputBorderWidth","inputPaddingX","inputPaddingY","resultGap","resultBorderWidth","resultPaddingX","resultPaddingY","resultBorderRadius","triggerBorderRadius","triggerBorderWidth","triggerPaddingX","triggerPaddingY","triggerFontSize","kbdBorderRadius","footerPaddingX","footerPaddingY"],le=["modalMaxHeight"],Re=["modalBg","modalBgDark","modalBorderColor","modalBorderColorDark","headerBg","headerBgDark","headerBorderColor","headerBorderColorDark","inputBg","inputBgDark","inputTextColor","inputTextColorDark","inputPlaceholderColor","inputPlaceholderColorDark","inputBorderColor","inputBorderColorDark","resultBg","resultBgDark","resultBorderColor","resultBorderColorDark","resultActiveBg","resultActiveBgDark","resultActiveBorderColor","resultActiveBorderColorDark","resultTextColor","resultTextColorDark","resultActiveTextColor","resultActiveTextColorDark","resultActiveDescColor","resultActiveDescColorDark","resultActiveMutedColor","resultActiveMutedColorDark","resultDescColor","resultDescColorDark","resultMutedColor","resultMutedColorDark","triggerBg","triggerBgDark","triggerTextColor","triggerTextColorDark","triggerBorderColor","triggerBorderColorDark","triggerHoverBg","triggerHoverBgDark","triggerHoverTextColor","triggerHoverTextColorDark","triggerHoverBorderColor","triggerHoverBorderColorDark","kbdBg","kbdBgDark","kbdTextColor","kbdTextColorDark","iconColor","iconColorDark","searchIconColor","searchIconColorDark","clearIconColor","clearIconColorDark","resultIconColor","resultIconColorDark","arrowColor","arrowColorDark","iconActiveColor","iconActiveColorDark","resultIconActiveColor","resultIconActiveColorDark","hierarchyConnectorActiveColor","hierarchyConnectorActiveColorDark","hierarchyConnectorColor","hierarchyConnectorColorDark","highlightBgLight","highlightColorLight","highlightBgDark","highlightColorDark","highlightActiveBgLight","highlightActiveColorLight","highlightActiveBgDark","highlightActiveColorDark","promotedBg","promotedBgDark","promotedColor","promotedColorDark","spinnerColor","spinnerColorDark","scrollbarColor","scrollbarColorDark","footerBg","footerBgDark","footerTextColor","footerTextColorDark"],or={...Le,highlightBgLight:"#fef08a",highlightColorLight:"#854d0e",highlightBgDark:"#854d0e",highlightColorDark:"#fef08a"};var ce=new WeakMap;function ft(){let e=new Set,t=new Set;for(let r of Object.keys(V)){if(r.endsWith("Dark")){t.add(r);continue}if(r.endsWith("Light")){let n=r.replace(/Light$/,"Dark");V[n]&&e.add(r);continue}V[`${r}Dark`]&&e.add(r)}return{lightKeys:e,darkKeys:t}}var $e=ft();function vt(e){return typeof e=="string"&&/^(var|light-dark|calc|env|clamp|min|max|rgb|hsl)\s*\(/.test(e.trim())}function yt(e){return/^[0-9a-fA-F]{6}$/.test(e)}function kt(e,t){if(t==null||t==="")return null;let r=String(t);return vt(r)||(Re.includes(e)&&yt(r)&&(r="#"+r),ie.includes(e)&&(r=r+"px"),le.includes(e)&&(r=r+"vh")),r}function He(e,t,r="light"){if(!e)return;let n=ce.get(e);if(n){for(let i of n)e.style.removeProperty(i);ce.delete(e)}if(!t||typeof t!="object")return;let o=r==="dark",s=Object.entries(V),a=new Set([...ie,...le]),l=new Set;for(let[i,c]of s){let d=$e.lightKeys.has(i),u=$e.darkKeys.has(i),m=d||u;if(o){if(d||!u&&!a.has(i))continue}else if(u)continue;if(t[i]!==void 0&&t[i]!==null&&t[i]!==""){let p=kt(i,t[i]);p&&(e.style.setProperty(c,p),m&&l.add(c))}}l.size>0&&ce.set(e,l)}var xt=new Set(["mark","em","strong","u","b","i","span"]),Ct=/^[A-Za-z0-9_-]+$/;function h(e){return e?String(e).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"):""}function Me(e){return e?e.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"):""}function de(e){if(!e)return[];let t=[],r=/"([^"]+)"/g,n;for(;(n=r.exec(e))!==null;)n[1].trim()&&t.push(n[1].trim());let o=e.replace(/"[^"]*"/g,""),s=new Set(["and","or","not","und","oder","nicht","et","ou","sauf","y","o","no"]);o.split(/\s+/).filter(l=>l.length>0).forEach(l=>{l=l.replace(/^[a-zA-Z]+:/,""),l=l.replace(/\*/g,""),l=l.replace(/\^\d+(\.\d+)?/,""),l=l.replace(/"/g,""),!(!l||s.has(l.toLowerCase()))&&t.push(l)});let a=[];return t.forEach(l=>{a.push(l);let i=l.split(/(?<=[a-z])(?=[A-Z])/);i.length>1&&i.forEach(c=>{c.length>=3&&a.push(c)})}),a}function X(e,t,r={}){let{enabled:n=!0,tag:o="mark",className:s="",terms:a=null}=r;if(!n)return h(e);let l=wt(o),c=["sm-highlight",...St(s)],d=` class="${h(c.join(" "))}"`,u=Dt(t,a);return u.length===0?h(e):Tt(e,u,l,d)}function wt(e){let t=String(e||"mark").trim().toLowerCase();return xt.has(t)?t:"mark"}function St(e){return String(e||"").trim().split(/\s+/).filter(t=>t&&Ct.test(t))}function Dt(e,t){return Array.isArray(t)&&t.length>0?Pe(t):e?Pe(de(e)):[]}function Pe(e){let t=new Set;return e.filter(r=>typeof r=="string"&&r.length>0).sort((r,n)=>n.length-r.length).filter(r=>{let n=r.toLowerCase();return t.has(n)?!1:(t.add(n),!0)})}function Tt(e,t,r,n){let o=e.toLowerCase(),s=[];if(t.forEach(d=>{let u=d.toLowerCase();if(!u)return;let m=0;for(;m<o.length;){let p=o.indexOf(u,m);if(p===-1)break;s.push({start:p,end:p+u.length}),m=p+u.length}}),s.length===0)return h(e);s.sort((d,u)=>d.start!==u.start?d.start-u.start:u.end-u.start-(d.end-d.start));let a=[],l=-1;s.forEach(d=>{d.start>=l&&(a.push(d),l=d.end)});let i="",c=0;return a.forEach(d=>{c<d.start&&(i+=h(e.slice(c,d.start))),i+=`<${r}${n}>${h(e.slice(d.start,d.end))}</${r}>`,c=d.end}),c<e.length&&(i+=h(e.slice(c))),i}function W(e,t,r="smq"){if(!e||e==="#")return e;if(At(e))return"#";let n=(t||"").trim();if(!n||!r||/^(mailto:|tel:)/i.test(e))return e;let[o,s]=e.split("#",2),[a,l]=o.split("?",2),i=new URLSearchParams(l||"");i.set(r,n);let c=i.toString(),d=s?`#${s}`:"";return`${a}${c?`?${c}`:""}${d}`}function At(e){let t=String(e).replace(/[\t\n\r]/g,"").replace(/^[\u0000-\u0020]+/,"");return/^(javascript|data|vbscript):/i.test(t)}var Et=0;function he(e="sm"){return`${e}-${++Et}-${Date.now().toString(36)}`}function Oe(e){let t=document.createElement("div");return t.setAttribute("role","status"),t.setAttribute("aria-live","polite"),t.setAttribute("aria-atomic","true"),t.className="sm-sr-only",e.appendChild(t),t}function J(e,t,r=100){e&&(e.textContent="",setTimeout(()=>{e.textContent=t},r))}function ue(e,t,r={}){return e===0?g(r,'No results found for "{query}"',{query:t}):e===1?g(r,'1 result found for "{query}"',{query:t}):g(r,'{count} results found for "{query}"',{count:e,query:t})}function Ne(e={}){return g(e,"Searching...")}function _e(e,t={}){return e===0?g(t,"No recent searches"):e===1?g(t,"1 recent search available"):g(t,"{count} recent searches available",{count:e})}function oe(e,{expanded:t,activeDescendant:r,listboxId:n}){e.setAttribute("aria-expanded",String(t)),e.setAttribute("aria-controls",n),r?e.setAttribute("aria-activedescendant",r):e.removeAttribute("aria-activedescendant")}function z(e,t){return`${e}-option-${t}`}function Fe(e,t){if(!e||!t)return;let r=e.getBoundingClientRect(),n=t.getBoundingClientRect();r.top<n.top?e.scrollIntoView({block:"nearest",behavior:"smooth"}):r.bottom>n.bottom&&e.scrollIntoView({block:"nearest",behavior:"smooth"})}function It(e,t,r={}){let{resultsGroupingEnabled:n=!1,resultsLayout:o="default",listboxId:s}=r;if(!e||e.length===0)return"";if(o==="hierarchical")return _t(e,t,r);if(n){let a=Ae(e),l=0;return Object.entries(a).map(([i,c])=>`
            <div class="sm-section" role="group" aria-label="${h(i)}">
                <div class="sm-section-header">${h(i)}</div>
                ${c.map(d=>qe(d,l++,t,r)).join("")}
            </div>
        `).join("")}return e.map((a,l)=>qe(a,l,t,r)).join("")}function qe(e,t,r,n={}){let{listboxId:o,highlightResultsEnabled:s=!0,highlightTag:a="mark",highlightClass:l="",resultsGroupingEnabled:i=!1,promotionBadge:c={},debugEnabled:d=!1,highlightDestinationPersistQuery:u=!1,highlightDestinationQueryParam:m="smq",translations:p={}}=n,b=pe(e),y=b?e.sectionTitle||e.title||e.name||g(p,"Untitled"):e.title||e.name||g(p,"Untitled"),D=e.snippet||"",f=b?e.sectionUrl||e.url||e.href||"#":e.url||e.href||"#",v=W(f,r,u?m:""),T=e.source||e.entrySection||e.type||"",k=z(o,t),x=e.promoted===!0,S=e._index||e.index||"",R=be(e),$={enabled:s,tag:a,className:l},q=X(y,r,{...$,terms:F(e,"title")}),A=D?me(e,D,r,{...$,terms:F(e,"snippet")}):"",E=Bt(e,c),H=x?" sm-promoted":"",P=T&&!i?`<span class="sm-result-type">${h(T)}</span>`:"",M=d?je(e,p):"";return d?`
            <a class="sm-result-item sm-debug-enabled${H}" id="${k}" role="option" aria-selected="false" href="${h(v)}" data-index="${t}" data-source-index="${h(S)}"${R} data-title="${h(y)}">
                <div class="sm-result-main">
                    ${E}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${q}</span>
                        ${A?`<span class="sm-result-desc">${A}</span>`:""}
                    </div>
                    ${P}
                    ${j()}
                </div>
                ${M}
            </a>
        `:`
        <a class="sm-result-item${H}" id="${k}" role="option" aria-selected="false" href="${h(v)}" data-index="${t}" data-source-index="${h(S)}"${R} data-title="${h(y)}">
            ${E}
            <div class="sm-result-content">
                <span class="sm-result-title">${q}</span>
                ${A?`<span class="sm-result-desc">${A}</span>`:""}
            </div>
            ${P}
            ${j()}
        </a>
    `}function je(e,t={}){let r=[],n=e.backend?e.backend.toLowerCase():"";if((e._index||e.index)&&r.push(w("index",e._index||e.index,"index","",t)),e.backend&&r.push(w("backend",n,"backend",n,t)),e.elementId&&r.push(w("element",e.elementId,"generic","",t)),e.backendId&&r.push(w("hit",e.backendId,"generic","",t)),e.score!==void 0&&e.score!==null){let o=typeof e.score=="number"?e.score.toFixed(2):e.score;r.push(w("score",o,"score","",t))}if(e.site&&r.push(w("site",e.site,"generic","",t)),e.language&&r.push(w("lang",e.language,"generic","",t)),e.matchedIn&&Array.isArray(e.matchedIn)&&e.matchedIn.length>0){let o=e.matchedIn.join(", ");r.push(w("matched",o,"matched","",t))}return e.promoted&&r.push(w("promoted",g(t,"yes"),"promoted","",t)),e.boosted&&r.push(w("boosted",g(t,"yes"),"boosted","",t)),r.length===0?"":`<div class="sm-debug-info">${r.join("")}</div>`}function F(e,t){let r=Array.isArray(e.matchedPhrases)?e.matchedPhrases:[],n=e.matchedTerms,o=[];n&&(t==="title"&&Array.isArray(n.title)&&n.title.length>0?o=n.title:t==="snippet"&&Array.isArray(n.content)&&n.content.length>0?o=n.content:o=[...Array.isArray(n.title)?n.title:[],...Array.isArray(n.content)?n.content:[]]);let s=[...r,...o];return s.length>0?s:null}function me(e,t,r,n){return X(t,r,n)}function w(e,t,r,n="",o={}){let s=n?` data-backend="${h(n)}"`:"";return`<span class="sm-debug-item"><span class="sm-debug-label">${h(g(o,e))}</span><span class="sm-debug-value" data-type="${h(r)}"${s}>${h(String(t))}</span></span>`}function Bt(e,t={}){let{showBadge:r=!0,badgeText:n="Featured",badgePosition:o="top-right"}=t;return!e.promoted||!r?"":`<span class="sm-promoted-badge ${`sm-promoted-badge--${new Set(["top-right","top-left","inline"]).has(o)?o:"top-right"}`}">${h(n)}</span>`}function pe(e){return!!(e&&typeof e=="object"&&["heading","intro","promoted-page"].includes(String(e.sectionType||"")))}function Lt(e){return Array.isArray(e)&&e.some(pe)}function Rt(e,t){let r=new Map,n=[];return e.forEach((o,s)=>{if(!pe(o)){n.push({type:"single",item:o,order:s,score:_(o)});return}let a=Pt(o);if(!r.has(a)){let c={hits:[],order:s,score:_(o)};r.set(a,c),n.push({type:"section-group",key:a,order:s,score:c.score})}let l=r.get(a);l.hits.push(o),l.score=Math.max(l.score,_(o));let i=n.find(c=>c.type==="section-group"&&c.key===a);i&&(i.score=l.score)}),n.map(o=>{if(o.type==="section-group"){let s=r.get(o.key);return{...o,item:$t(s.hits,t)}}return o}).sort((o,s)=>{let a=ge(s.score,o.score);return a!==0?a:o.order-s.order}).map(o=>o.item)}function $t(e,t){let r=[...e].sort((d,u)=>N(d)-N(u)),n=r.find(d=>d.sectionType==="intro")||null,o=[...e].sort((d,u)=>{let m=ge(_(u),_(d));return m!==0?m:N(d)-N(u)})[0]||r[0]||{},s=n||o,a=Y(s),l=s.siteId??"",i=Number.isFinite(t)&&t>0?t:3,c=r.filter(d=>d.sectionType==="heading").sort((d,u)=>{let m=ge(_(u),_(d));return m!==0?m:N(d)-N(u)}).slice(0,i).sort((d,u)=>N(d)-N(u)).map(Ht);return{...s,elementId:a||s.elementId,backendId:n?.backendId||s.backendId||Mt(a,l),title:s.title||s.sectionTitle||s.name||"",url:s.url||"#",snippet:n&&n.snippet||null,score:_(o),headings:c,__sectionHitGroup:!0,__useBackendDomId:!0}}function Ht(e){let t=Number.parseInt(e.sectionLevel,10),r=Number.isFinite(t)?t:2;return{title:e.sectionTitle||e.title||"",text:e.sectionTitle||e.title||"",id:e.sectionAnchor||e.sectionId||"",level:r,url:e.sectionUrl||e.url||null,snippet:e.snippet||null,backendId:e.backendId||"",elementId:Y(e),sectionType:e.sectionType,_index:e._index,index:e.index,matchedTerms:e.matchedTerms,matchedPhrases:e.matchedPhrases,__useBackendDomId:!0}}function Pt(e){return[Y(e)||Ue(e)||"",e.siteId??""].join(":")}function N(e){let t=Number.parseInt(e.sectionIndex,10);return Number.isFinite(t)?t:Number.MAX_SAFE_INTEGER}function _(e){let t=Number(e?.score);return Number.isFinite(t)?t:Number.NEGATIVE_INFINITY}function ge(e,t){return e===t?0:e>t?1:-1}function Mt(e,t){let r=e||"unknown";return t!=null&&String(t)!==""?`${r}_${t}`:String(r)}function Ue(e,t=null){return e?.backendId||t?.backendId||""}function Y(e,t=null){return e?.elementId||t?.elementId||""}function be(e,t=null){let r=Ue(e,t)||Y(e,t),n=Y(e,t);return` data-id="${h(r)}" data-element-id="${h(n)}"`}function j(){return`<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
    </svg>`}function _t(e,t,r={}){let{hierarchyGroupBy:n="",hierarchyStyle:o="tree",hierarchyDisplay:s="individual",hierarchyMaxHeadings:a=3,listboxId:l}=r,i=o==="tree",c=o!=="none",d=Lt(e)?Rt(e,a):e,m=Ee(d,n||""),p=0;return Object.entries(m).map(([b,y])=>{let D=y.map(f=>{let v=p++,T=Ft(f,v,t,r),k="",x=f.headings||[],S=f.__sectionHitGroup?x:x.slice(0,a);if(S.length>0){let q=Math.min(...S.map(E=>E.level||2)),A=S.map(E=>i?(E.level||2)-q:0);k=S.map((E,H)=>{let P=A[H],M=!A.slice(H+1).some(U=>U===P),Q=[];if(i){let U=A.slice(H+1);for(let G=0;G<P;G++)U.some(O=>O===G)&&Q.push(G)}return qt(f,E,p++,t,r,M,P,Q)}).join("")}let R=!!k;return`
                <div class="sm-hierarchy-block${R?" sm-hierarchy-block--has-children":""}${s==="unified"?" sm-hierarchy-block--unified":""}">
                    ${R?T.replace("sm-result-item sm-hierarchy-parent","sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children"):T}
                    ${R?`<div class="sm-hierarchy-children${c?"":" sm-hierarchy-children--no-connectors"}">${k}</div>`:""}
                </div>
            `}).join("");return`
            <div class="sm-hierarchy-group" role="group" aria-label="${h(b)}">
                <div class="sm-hierarchy-group-header">${h(b)}</div>
                ${D}
            </div>
        `}).join("")}function Ft(e,t,r,n={}){let{listboxId:o,highlightResultsEnabled:s=!0,highlightTag:a="mark",highlightClass:l="",debugEnabled:i=!1,highlightDestinationPersistQuery:c=!1,highlightDestinationQueryParam:d="smq",translations:u={}}=n,m=e.title||e.name||g(u,"Untitled"),p=e.snippet||"",b=e.url||"#",y=W(b,r,c?d:""),D=z(o,t),f=e._index||e.index||"",v=be(e),T={enabled:s,tag:a,className:l},k=X(m,r,{...T,terms:F(e,"title")}),x=p?me(e,p,r,{...T,terms:F(e,"snippet")}):"",S=i?je(e,u):"",$=e.headings&&e.headings.length>0?Ot():Nt();return i?`
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled" id="${D}" role="option" aria-selected="false" href="${h(y)}" data-index="${t}" data-source-index="${h(f)}"${v} data-title="${h(m)}">
                <div class="sm-result-main">
                    ${$}
                    <div class="sm-result-content">
                        <span class="sm-result-title">${k}</span>
                        ${x?`<span class="sm-result-desc">${x}</span>`:""}
                    </div>
                    ${j()}
                </div>
                ${S}
            </a>
        `:`
        <a class="sm-result-item sm-hierarchy-parent" id="${D}" role="option" aria-selected="false" href="${h(y)}" data-index="${t}" data-source-index="${h(f)}"${v} data-title="${h(m)}">
            ${$}
            <div class="sm-result-content">
                <span class="sm-result-title">${k}</span>
                ${x?`<span class="sm-result-desc">${x}</span>`:""}
            </div>
            ${j()}
        </a>
    `}function qt(e,t,r,n,o={},s=!1,a=0,l=[]){let{listboxId:i,highlightResultsEnabled:c=!0,highlightTag:d="mark",highlightClass:u="",debugEnabled:m=!1,highlightDestinationPersistQuery:p=!1,highlightDestinationQueryParam:b="smq",translations:y={}}=o,f=(t.title||t.text||"").replace(/^#+\s*/,""),v=t.snippet||"",T=Number.parseInt(t.level,10),k=Number.isFinite(T)?Math.min(Math.max(T,1),6):2,x=t.id||(f?Gt(f):""),S=e.url||"#",R=t.url||(x?`${S}#${x}`:S),$=W(R,n,p?b:""),q=z(i,r),A=t._index||t.index||e._index||e.index||"",E=be(t,e),H={enabled:c,tag:d,className:u},P=X(f,n,{...H,terms:F(t,"title")||F(e,"title")}),M=v?me(e,v,n,{...H,terms:F(t,"snippet")||F(e,"snippet")}):"",Q=s?" sm-hierarchy-child-row-last":"",U=l.map(O=>`<div class="sm-hierarchy-guide" style="--sm-guide-depth:${O}" aria-hidden="true"></div>`).join(""),G="";if(m){let O=[];O.push(w("h",k,"generic","",y)),x&&O.push(w("anchor",x,"generic","",y));let ke=Y(t,e);ke&&O.push(w("parent",ke,"generic","",y)),G=`<div class="sm-debug-info">${O.join("")}</div>`}return m?`
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${k} sm-hierarchy-depth-${a}${Q}" style="--sm-hierarchy-depth:${a}">
                ${U}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${k} sm-debug-enabled" id="${q}" role="option" aria-selected="false" href="${h($)}" data-index="${r}" data-source-index="${h(A)}"${E} data-title="${h(f)}">
                    <div class="sm-result-main">
                        ${Ge()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${P}</span>
                            ${M?`<span class="sm-result-desc">${M}</span>`:""}
                        </div>
                        ${j()}
                    </div>
                    ${G}
                </a>
            </div>
        `:`
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${k} sm-hierarchy-depth-${a}${Q}" style="--sm-hierarchy-depth:${a}">
            ${U}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${k}" id="${q}" role="option" aria-selected="false" href="${h($)}" data-index="${r}" data-source-index="${h(A)}"${E} data-title="${h(f)}">
                ${Ge()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${P}</span>
                    ${M?`<span class="sm-result-desc">${M}</span>`:""}
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
            ${e.map((n,o)=>`
                <div class="sm-result-item sm-recent-item" id="${z(t,o)}" role="option" aria-selected="false" data-index="${o}" data-url="${h(n.url||"")}" data-query="${h(n.query)}">
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
    `}function Ke(e,t){let{query:r,results:n,recentSearches:o,loading:s,recentSearchesEnabled:a,error:l}=e,{loadingIndicatorEnabled:i=!0,translations:c={}}=t,d=r&&r.trim();return s&&i?{html:jt(c),hasResults:!1,showListbox:!1}:l?{html:Ut(l,c),hasResults:!1,showListbox:!1}:d?!n||n.length===0?{html:ze(r,c),hasResults:!1,showListbox:!1}:{html:It(n,r,t),hasResults:!0,showListbox:!0}:a&&o&&o.length>0?{html:zt(o,t.listboxId,c),hasResults:!0,showListbox:!0}:{html:ze("",c),hasResults:!1,showListbox:!1}}function fe(e,t,r=!1,n={}){if(!e)return"";let o=[];if(o.push(L("results",t,"generic","",n)),e.took!==void 0){let c=e.took<1?"<1ms":`${Math.round(e.took)}ms`;o.push(L("time",c,"time","",n))}if(e.cacheEnabled!==void 0&&(e.cacheEnabled?e.cached?o.push(L("cache",g(n,"hit"),"cache-hit","",n)):o.push(L("cache",g(n,"miss"),"cache-miss","",n)):o.push(L("cache",g(n,"off"),"cache-off","",n))),e.cacheDriver&&o.push(L("storage",e.cacheDriver,"cache-driver",e.cacheDriver,n)),e.indices&&e.indices.length>0){let c=e.indices.length>2?g(n,"{count} indices",{count:e.indices.length}):e.indices.join(", ");o.push(L("indices",c,"generic","",n))}if(e.synonymsExpanded){let c=e.expandedQueries?e.expandedQueries.length-1:0;o.push(L("synonyms",`+${c}`,"synonyms","",n))}let s=e.rulesMatched?.length||0;o.push(L("rules",s,s>0?"rules":"generic","",n));let a=e.promotionsMatched?.length||0;o.push(L("promoted",a,a>0?"promotions":"generic","",n));let i=`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${r?'<path d="M6 9l6 6 6-6"/>':'<path d="M18 15l-6-6-6 6"/>'}</svg>`;return r?`<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">${h(g(n,"Debug"))}</span>${i}</div>`:`<div class="sm-toolbar-content">${o.join("")}</div><button class="sm-toolbar-toggle" aria-label="${h(g(n,"Collapse debug panel"))}" aria-expanded="true">${i}</button>`}function L(e,t,r,n="",o={}){let s=n?` data-backend="${h(n)}"`:"";return`<span class="sm-toolbar-item"><span class="sm-toolbar-label">${h(g(o,e))}</span><span class="sm-toolbar-value" data-type="${h(r)}"${s}>${h(String(t))}</span></span>`}function We(e,t){let{onSelect:r,onIndexChange:n,onEscape:o}=e,{listboxId:s}=t;return{handleKeydown(a,l,i){let c=i;switch(a.key){case"ArrowDown":return a.preventDefault(),c=Math.min(i+1,l-1),c!==i&&n&&n(c),c;case"ArrowUp":return a.preventDefault(),c=Math.max(i-1,-1),c!==i&&n&&n(c),c;case"Enter":return a.preventDefault(),i>=0&&r&&r(i),null;case"Escape":return a.preventDefault(),o&&o(),null;default:return null}},getListboxId(){return s}}}function Ye(e,t,r={}){let{scrollContainer:n,inputElement:o,listboxId:s,selectedClass:a="sm-selected"}=r,l=t>=0?z(s,t):null;o&&oe(o,{expanded:e.length>0,activeDescendant:l,listboxId:s}),e.forEach((i,c)=>{let d=c===t;i.classList.toggle(a,d),i.setAttribute("aria-selected",String(d)),d&&n&&Fe(i,n)})}function Qe(e,t){e.forEach((r,n)=>{r.addEventListener("mouseenter",()=>{t&&t(n)})})}var Ve="sm-page-highlight-style",Xe="__smPageHighlightRegistry",Je="__searchManagerHotkeyHandled",I=null,ve=class extends HTMLElement{constructor(){super(),this.attachShadow({mode:"open"}),this.config=null,this.state=we({...te},this.handleStateChange.bind(this)),this.searchSequence=0,this.debounceTimer=null,this.analyticsIdleTimer=null,this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.listboxId=he("sm-listbox"),this.inputId=he("sm-input"),this.liveRegion=null,this.keyboardNavigator=null,this.elements={},this.handleInput=this.handleInput.bind(this),this.handleKeydown=this.handleKeydown.bind(this),this.handleResultClick=this.handleResultClick.bind(this)}get widgetType(){throw new Error("Subclass must implement widgetType getter")}render(){throw new Error("Subclass must implement render()")}getResultsContainer(){throw new Error("Subclass must implement getResultsContainer()")}getInputElement(){throw new Error("Subclass must implement getInputElement()")}getLoadingElement(){return this.elements.loading||null}getDebugToolbarElement(){return this.elements.debugToolbar||null}connectedCallback(){this.config=K(this,this.widgetType),this.state.set({recentSearches:ne(ee(this.config))}),this.keyboardNavigator=We({onSelect:t=>this.selectResultAtIndex(t),onIndexChange:t=>this.state.set({selectedIndex:t}),onEscape:()=>this.handleEscape()},{listboxId:this.listboxId}),this.applyDestinationPageHighlight()}disconnectedCallback(){this.unregisterOpenWidget(),this.searchSequence++,this.debounceTimer&&(clearTimeout(this.debounceTimer),this.debounceTimer=null)}registerOpenWidget(){I&&I!==this&&typeof I.close=="function"&&I.close({reason:"replace",replacedBy:this,source:"replace"}),I=this}unregisterOpenWidget(){I===this&&(I=null)}claimHotkeyEvent(t,r){return t[Je]||I&&I!==this&&I.state?.get("isOpen")&&I.config?.triggerHotkey?.toLowerCase()===r?!1:(t[Je]=!0,!0)}attributeChangedCallback(t,r,n){r!==n&&this.shadowRoot.children.length>0&&(this.config=K(this,this.widgetType),this.render(),this.applyCustomStyles())}handleStateChange(t,r){(r.includes("results")||r.includes("query")||r.includes("recentSearches")||r.includes("error"))&&this.renderResultsContent(),(r.includes("results")||r.includes("meta"))&&this.updateDebugToolbar(),r.includes("selectedIndex")&&this.updateSelectionVisual(),r.includes("loading")&&this.updateLoadingVisual()}handleInput(t){let r=t.target.value;if(this.elements&&this.elements.clear&&(this.elements.clear.hidden=!r),this.state.set({query:r,selectedIndex:-1}),this.debounceTimer&&clearTimeout(this.debounceTimer),this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),!r.trim()){this.state.set({results:[]});return}r.length<this.config.searchMinChars||(this.debounceTimer=setTimeout(()=>{this.executeSearch(r)},this.config.searchDebounceMs))}async executeSearch(t){let r=++this.searchSequence;this.state.set({loading:!0,error:null}),this.liveRegion&&J(this.liveRegion,Ne(this.config.translations));try{let{results:n,meta:o}=await Se({query:t,endpoint:this.config.searchEndpoint,indexHandles:this.config.indexHandles,siteId:this.config.siteId,resultsLimit:this.config.resultsLimit,resultsRequireUrl:this.config.resultsRequireUrl,snippetIncludeCodeBlocks:this.config.snippetIncludeCodeBlocks,snippetMode:this.config.snippetMode,snippetMaxLength:this.config.snippetMaxLength,snippetCleanMarkdown:this.config.snippetCleanMarkdown,debugEnabled:this.config.debugEnabled,apiKey:this.config.apiKey,translations:this.config.translations});if(r!==this.searchSequence)return;this.state.set({results:n,meta:o,loading:!1,selectedIndex:n.length>0?0:-1}),o&&typeof o.cached=="boolean"?this.lastSearchCacheState={cached:o.cached,took:typeof o.took=="number"?o.took:null}:this.lastSearchCacheState=null,this.liveRegion&&J(this.liveRegion,ue(n.length,t,this.config.translations)),this.dispatchWidgetEvent("search",{query:t,results:n,meta:o}),this.startAnalyticsIdleTimer(t,n.length)}catch(n){if(r!==this.searchSequence||n.name==="AbortError")return;console.error("Search error:",n),this.state.set({results:[],loading:!1,error:n.message}),this.dispatchWidgetEvent("error",{query:t,error:n.message})}}renderResultsContent(){let t=this.getResultsContainer();if(!t)return;let r=this.state.getAll(),{recentSearchesEnabled:n,resultsGroupingEnabled:o,highlightResultsEnabled:s,highlightTag:a,highlightClass:l,loadingIndicatorEnabled:i,debugEnabled:c}=this.config,{html:d,hasResults:u,showListbox:m}=Ke({query:r.query,results:r.results,recentSearches:r.recentSearches,loading:r.loading,error:r.error,recentSearchesEnabled:n},{listboxId:this.listboxId,resultsGroupingEnabled:o,highlightResultsEnabled:s,highlightTag:a,highlightClass:l,loadingIndicatorEnabled:i,debugEnabled:c,translations:this.config.translations,highlightDestinationPersistQuery:this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery,highlightDestinationQueryParam:this.config.highlightDestinationQueryParam,promotionBadge:this.config.promotionBadge,resultsLayout:this.config.resultsLayout,hierarchyGroupBy:this.config.hierarchyGroupBy,hierarchyStyle:this.config.hierarchyStyle,hierarchyDisplay:this.config.hierarchyDisplay,hierarchyMaxHeadings:this.config.hierarchyMaxHeadings});t.innerHTML=d,m?t.setAttribute("role","listbox"):t.removeAttribute("role");let p=this.getInputElement();p&&oe(p,{expanded:u,activeDescendant:null,listboxId:this.listboxId}),this.liveRegion&&!r.loading&&(r.query&&r.results.length===0?J(this.liveRegion,ue(0,r.query,this.config.translations)):!r.query&&r.recentSearches.length>0&&n&&J(this.liveRegion,_e(r.recentSearches.length,this.config.translations))),this.attachResultHandlers();let b=t.querySelector(".sm-clear-recent");b&&b.addEventListener("click",y=>{y.stopPropagation(),Be(ee(this.config)),this.state.set({recentSearches:[]})}),u&&r.results.length>0&&this.state.set({selectedIndex:0})}attachResultHandlers(){let t=this.getResultsContainer();if(!t)return;let r=t.querySelectorAll(".sm-result-item");r.forEach(n=>{n.addEventListener("click",o=>this.handleResultClick(o,n))}),Qe(r,n=>{this.state.set({selectedIndex:n})})}updateSelectionVisual(){let t=this.getResultsContainer(),r=this.getInputElement();if(!t)return;let n=t.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");Ye(n,o,{scrollContainer:t,inputElement:r,listboxId:this.listboxId})}handleKeydown(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");if(t.key==="Enter"){let s=this.state.get("query"),a=this.state.get("results")||[];s&&a.length>0&&this.trackSearchAnalytics(s,a.length,"enter")}this.keyboardNavigator.handleKeydown(t,n.length,o)}selectResultAtIndex(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item");t>=0&&n[t]&&n[t].click()}handleEscape(){}handleResultClick(t,r){let n=r.getAttribute("href"),o=r.dataset.url,s=n||o,a=r.dataset.title||r.querySelector(".sm-result-title")?.textContent,l=r.dataset.id,i=r.dataset.elementId||l,c=r.dataset.query||this.state.get("query"),d=r.classList.contains("sm-recent-item"),u=W(s,c,this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery?this.config.highlightDestinationQueryParam:"");if(!d&&c){let p=Ie(ee(this.config),c,{title:a,url:s},this.config.recentSearchesLimit);this.state.set({recentSearches:p})}let m=r.dataset.sourceIndex||xe(this.config);if(i&&m&&De({endpoint:this.config.trackClickEndpoint,elementId:i,query:c,index:m,apiKey:this.config.apiKey}),!d&&c&&this.trackSearchAnalytics(c,this.state.get("results")?.length||0,"click"),this.dispatchWidgetEvent("result-click",{id:l,elementId:i,title:a,url:u,query:c,isRecent:d}),s&&s!=="#")d&&(t.preventDefault(),window.location.href=u),this.onResultSelected(u,a,l);else if(c){t.preventDefault();let p=this.getInputElement();p&&(p.value=c,this.state.set({query:c}),this.executeSearch(c))}}onResultSelected(t,r,n){}applyDestinationPageHighlight(){if(!this.config.highlightDestinationEnabled||typeof window>"u"||typeof document>"u")return;let t=this.config.highlightDestinationQueryParam||"smq",r=this.config.highlightDestinationContentSelector||"main, article, [data-search-content]",n=new URLSearchParams(window.location.search).get(t);if(!n||!n.trim())return;let o=this.getPageHighlightRegistry(),s=`${t}::${r}`;if(o.has(s))return;o.add(s);let a=()=>{this.ensurePageHighlightStyles(),this.highlightDestinationNodes(n.trim(),r,s)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",a,{once:!0}):window.requestAnimationFrame(a)}ensurePageHighlightStyles(){if(document.getElementById(Ve))return;let t=document.createElement("style");t.id=Ve,t.textContent=`
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `,document.head.appendChild(t)}highlightDestinationNodes(t,r,n){let o=Array.from(document.querySelectorAll(r));if(o.length===0)return;let s=[...new Set(de(t).map(i=>i.trim()).filter(i=>i.length>=2))];if(s.length===0)return;let a=s.map(i=>Me(i)).filter(Boolean).sort((i,c)=>c.length-i.length).join("|");if(!a)return;let l=new RegExp(`(${a})`,"gi");o.forEach(i=>{i.getAttribute("data-sm-highlighted")!==n&&(this.highlightTextNodesInScope(i,l),i.setAttribute("data-sm-highlighted",n))})}highlightTextNodesInScope(t,r){let n=document.createTreeWalker(t,NodeFilter.SHOW_TEXT,{acceptNode:s=>{let a=s.nodeValue;if(!a||!a.trim())return NodeFilter.FILTER_REJECT;let l=s.parentElement;return!l||l.closest("script, style, noscript, textarea, mark, .sm-highlight, .sm-page-highlight, search-modal")?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT}}),o=[];for(;n.nextNode();)o.push(n.currentNode);o.forEach(s=>{let a=s.nodeValue||"";if(r.lastIndex=0,!r.test(a))return;let l=document.createDocumentFragment(),i=0;r.lastIndex=0;let c=a.matchAll(r);for(let d of c){let u=d[0],m=d.index??-1;if(m<0)continue;m>i&&l.appendChild(document.createTextNode(a.slice(i,m)));let p=document.createElement("mark");p.className="sm-highlight sm-page-highlight",p.textContent=u,l.appendChild(p),i=m+u.length}i<a.length&&l.appendChild(document.createTextNode(a.slice(i))),s.parentNode?.replaceChild(l,s)})}getPageHighlightRegistry(){let t=window[Xe];if(t instanceof Set)return t;let r=new Set;return window[Xe]=r,r}updateLoadingVisual(){let t=this.getLoadingElement();if(t){let r=this.state.get("loading"),n=this.config?.loadingIndicatorEnabled!==!1;t.hidden=!r||!n}}updateDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let{debugEnabled:r}=this.config,n=this.state.getAll();if(!r||!n.meta||n.results.length===0){t.hidden=!0;return}let o=t.classList.contains("sm-collapsed");t.innerHTML=fe(n.meta,n.results.length,o,this.config.translations),t.hidden=!1,o&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}attachDebugToolbarHandlers(t){let r=t.querySelector(".sm-toolbar-toggle");r&&r.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()});let n=t.querySelector(".sm-toolbar-collapsed-bar");n&&n.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()})}toggleDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let r=t.classList.toggle("sm-collapsed"),n=this.state.getAll();t.innerHTML=fe(n.meta,n.results.length,r,this.config.translations),r&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}applyCustomStyles(){if(!this.config)return;let t=this.shadowRoot.host,{theme:r,styles:n,resultsTitleLines:o,resultsDescriptionLines:s}=this.config;He(t,n,r),o&&t.style.setProperty("--sm-result-title-lines",String(o)),s&&t.style.setProperty("--sm-result-desc-lines",String(s))}initializeLiveRegion(){this.liveRegion=Oe(this.shadowRoot)}startAnalyticsIdleTimer(t,r){this.analyticsIdleTimer&&clearTimeout(this.analyticsIdleTimer);let n=this.config.analyticsIdleTimeoutMs;!n||n<=0||(this.analyticsIdleTimer=setTimeout(()=>{this.trackSearchAnalytics(t,r,"idle")},n))}trackSearchAnalytics(t,r,n){!t||t===this.lastTrackedQuery||(this.lastTrackedQuery=t,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),Te({endpoint:this.config.trackSearchEndpoint,query:t,indexHandles:this.config.indexHandles,resultsCount:r,trigger:n,analyticsSource:this.config.analyticsSource,siteId:this.config.siteId,cached:this.lastSearchCacheState?.cached,took:this.lastSearchCacheState?.took,apiKey:this.config.apiKey}))}resetAnalyticsTracking(){this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null)}dispatchWidgetEvent(t,r={}){this.dispatchEvent(new CustomEvent(`search-${t}`,{bubbles:!0,composed:!0,detail:r}))}},Ze=ve;var et=`/**
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

    /* Highlighting on the active/hovered row \u2014 falls back to the base
       highlight so nothing changes unless the active pair is set */
    --sm-highlight-active-bg: var(--sm-highlight-bg);
    --sm-highlight-active-color: var(--sm-highlight-color);

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

    /* Utility glyph colors \u2014 unset keys fall back to the muted chain */
    --sm-result-icon-resolved: var(--sm-result-icon-color, var(--sm-text-muted));
    --sm-result-icon-active-resolved: var(--sm-result-icon-active-color, var(--sm-result-icon-color, var(--sm-result-active-muted-color, var(--sm-text-muted))));
    --sm-arrow-resolved: var(--sm-arrow-color, var(--sm-result-active-muted-color, var(--sm-text-muted)));
    --sm-icon-active-resolved: var(--sm-icon-active-color, var(--sm-icon));
    --sm-hierarchy-connector-active-resolved: var(--sm-hierarchy-connector-active-color, var(--sm-hierarchy-connector-color));

    /* Spinner */
    --sm-spinner-color: var(--sm-spinner-color-light, #3b82f6);

    /* Scrollbar thumb \u2014 unset falls back to semi-transparent muted */
    --sm-scrollbar-resolved: var(--sm-scrollbar-color, color-mix(in srgb, var(--sm-text-muted) 45%, transparent));

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
    --sm-hierarchy-connector-color: var(--sm-hierarchy-connector-color-dark, var(--sm-result-muted-color-dark, #9ca3af));

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
    --sm-highlight-active-bg: var(--sm-highlight-active-bg-dark, var(--sm-highlight-bg));
    --sm-highlight-active-color: var(--sm-highlight-active-color-dark, var(--sm-highlight-color));

    --sm-kbd-bg: var(--sm-kbd-bg-dark, #374151);
    --sm-kbd-border: #4b5563;
    --sm-kbd-color: var(--sm-kbd-text-color-dark, #e5e7eb);

    --sm-icon: var(--sm-icon-color-dark, #60a5fa);
    --sm-result-icon-resolved: var(--sm-result-icon-color-dark, var(--sm-text-muted));
    --sm-result-icon-active-resolved: var(--sm-result-icon-active-color-dark, var(--sm-result-icon-color-dark, var(--sm-result-active-muted-color, var(--sm-text-muted))));
    --sm-arrow-resolved: var(--sm-arrow-color-dark, var(--sm-result-active-muted-color, var(--sm-text-muted)));
    --sm-icon-active-resolved: var(--sm-icon-active-color-dark, var(--sm-icon));
    --sm-hierarchy-connector-active-resolved: var(--sm-hierarchy-connector-active-color-dark, var(--sm-hierarchy-connector-color));

    --sm-spinner-color: var(--sm-spinner-color-dark, #60a5fa);
    --sm-scrollbar-resolved: var(--sm-scrollbar-color-dark, color-mix(in srgb, var(--sm-text-muted) 45%, transparent));
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
    scrollbar-width: thin;
    scrollbar-color: var(--sm-scrollbar-resolved) transparent;
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
.sm-result-item.sm-selected .sm-result-icon {
    color: var(--sm-result-icon-active-resolved, var(--sm-result-active-muted-color, var(--sm-text-muted)));
}

.sm-result-item:hover .sm-result-arrow,
.sm-result-item.sm-selected .sm-result-arrow {
    color: var(--sm-arrow-resolved, var(--sm-result-active-muted-color, var(--sm-text-muted)));
}

.sm-result-icon {
    flex-shrink: 0;
    color: var(--sm-result-icon-resolved, var(--sm-text-muted));
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
    /* Invisible at rest; visible color comes from arrowColor, falling back
       to the active/hover muted chain */
    color: var(--sm-arrow-resolved, var(--sm-result-active-muted-color, var(--sm-text-muted)));
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

.sm-result-item:hover .sm-hierarchy-icon,
.sm-result-item.sm-selected .sm-hierarchy-icon {
    color: var(--sm-icon-active-resolved, var(--sm-icon));
}

/* Hovered/selected child row recolors its connector segment.
   Connectors are drawn with borders only \u2014 never set a background here
   (it fills the last-row curve box into a solid square). */
.sm-hierarchy-child-row:hover::before,
.sm-hierarchy-child-row:hover::after,
.sm-hierarchy-child-row.sm-selected::before,
.sm-hierarchy-child-row.sm-selected::after {
    border-color: var(--sm-hierarchy-connector-active-resolved, var(--sm-hierarchy-connector-color));
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

/* Keep highlights legible when the row background changes on hover/selection */
.sm-result-item:hover .sm-highlight,
.sm-result-item.sm-selected .sm-highlight {
    background: var(--sm-highlight-active-bg);
    color: var(--sm-highlight-active-color);
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

/* Legacy Safari (pre-18.2) scrollbar fallback \u2014 modern browsers use the
   standard scrollbar-width/scrollbar-color above and ignore this block */
@supports not (scrollbar-color: red red) {
    .sm-results::-webkit-scrollbar {
        width: 8px;
    }

    .sm-results::-webkit-scrollbar-track {
        background: transparent;
    }

    .sm-results::-webkit-scrollbar-thumb {
        background: var(--sm-scrollbar-resolved);
        border-radius: 4px;
    }
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

    /* Search icon \u2014 unset key falls back to muted */
    --sm-search-icon-resolved: var(--sm-search-icon-color, var(--sm-text-muted));

    /* Clear icon \u2014 unset falls back to muted, hover to primary text */
    --sm-clear-icon-resolved: var(--sm-clear-icon-color, var(--sm-text-muted));
    --sm-clear-icon-hover-resolved: var(--sm-clear-icon-color, var(--sm-text-primary));

    /* Footer \u2014 unset bg matches the modal; unset text follows the muted chain */
    --sm-footer-bg-resolved: var(--sm-footer-bg, transparent);
    --sm-footer-text-resolved: var(--sm-footer-text-color, var(--sm-text-muted));
    --sm-footer-brand-strong-resolved: var(--sm-footer-text-color, var(--sm-text-secondary));

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

    --sm-search-icon-resolved: var(--sm-search-icon-color-dark, var(--sm-text-muted));
    --sm-clear-icon-resolved: var(--sm-clear-icon-color-dark, var(--sm-text-muted));
    --sm-clear-icon-hover-resolved: var(--sm-clear-icon-color-dark, var(--sm-text-primary));
    --sm-footer-bg-resolved: var(--sm-footer-bg-dark, transparent);
    --sm-footer-text-resolved: var(--sm-footer-text-color-dark, var(--sm-text-muted));
    --sm-footer-brand-strong-resolved: var(--sm-footer-text-color-dark, var(--sm-text-secondary));
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
    /* Header sits flush against the results \u2014 round the top corners only */
    border-radius: var(--sm-header-radius) var(--sm-header-radius) 0 0;
}

.sm-search-icon {
    flex-shrink: 0;
    color: var(--sm-search-icon-resolved, var(--sm-text-muted));
}

.sm-input-wrap {
    position: relative;
    flex: 1;
    display: flex;
    min-width: 0;
}

.sm-input-wrap .sm-input {
    /* Reserve space for the spinner + clear button so they never shift the input */
    padding-inline-end: calc(var(--sm-input-px, 0px) + 56px);
}

.sm-input-wrap .sm-loading {
    position: absolute;
    inset-inline-end: 32px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    pointer-events: none;
}

/* display: flex above would defeat the UA [hidden] default \u2014 restate it */
.sm-input-wrap .sm-loading[hidden] {
    display: none;
}

.sm-clear {
    position: absolute;
    inset-inline-end: 4px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    padding: 4px;
    background: transparent;
    border: none;
    color: var(--sm-clear-icon-resolved, var(--sm-text-muted));
    cursor: pointer;
}

.sm-clear:hover {
    color: var(--sm-clear-icon-hover-resolved, var(--sm-text-primary));
}

.sm-clear[hidden] {
    display: none;
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
    /* Own padding keys when set, otherwise follows the modal padding */
    padding: var(--sm-footer-py, var(--sm-modal-py)) var(--sm-footer-px, var(--sm-modal-px));
    background: var(--sm-footer-bg-resolved);
    /* Footer divider mirrors the header's divider width and color */
    border-top: var(--sm-header-border-width, 1px) solid var(--sm-border-color);
    font-size: 12px;
    color: var(--sm-footer-text-resolved, var(--sm-text-muted));
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
    color: var(--sm-footer-text-resolved, var(--sm-text-muted));
}

.sm-footer-brand a {
    color: inherit;
    text-decoration: none;
}

.sm-footer-brand a:hover,
.sm-footer-brand a:focus-visible {
    text-decoration: underline;
}

.sm-footer-brand strong {
    color: var(--sm-footer-brand-strong-resolved, var(--sm-text-secondary));
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
`+rt,ye=class extends Ze{constructor(){super(),this.externalTrigger=null,this.previouslyFocused=null,this.open=this.open.bind(this),this.close=this.close.bind(this),this.toggle=this.toggle.bind(this),this.handleGlobalKeydown=this.handleGlobalKeydown.bind(this),this.handleClearClick=this.handleClearClick.bind(this),this.handleBackdropClick=this.handleBackdropClick.bind(this),this.handleTriggerClick=this.handleTriggerClick.bind(this),this.handleExternalTriggerClick=this.handleExternalTriggerClick.bind(this),this.handleCloseClick=this.handleCloseClick.bind(this)}get widgetType(){return"modal"}static get observedAttributes(){return Ce("modal")}connectedCallback(){super.connectedCallback(),this.render(),this.attachEventListeners()}disconnectedCallback(){this.state.get("isOpen")&&this.close({reason:"disconnect",source:"disconnect",restoreFocus:!1}),super.disconnectedCallback(),this.detachEventListeners()}attributeChangedCallback(t,r,n){if(r===n||this.shadowRoot.children.length===0)return;if(t==="theme"){this.config=K(this,this.widgetType),this.shadowRoot.host.setAttribute("data-theme",this.config.theme),this.applyCustomStyles();return}let o=this.state.get("isOpen"),s=this.state.get("query")||"";this.detachEventListeners(),this.config=K(this,this.widgetType),this.render(),this.attachEventListeners(),o&&(this.registerOpenWidget(),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value=s,this.elements.clear.hidden=!s,this.renderResultsContent(),this.updateLoadingVisual(),this.updateDebugToolbar(),this.updateSelectionVisual(),document.body.style.overflow=this.config.modalPreventBodyScroll?"hidden":"",requestAnimationFrame(()=>{this.isConnected&&this.elements.input.focus()}))}render(){let{theme:t,placeholder:r,triggerEnabled:n,triggerLabel:o,translations:s}=this.config,a=h(this.getHotkeyDisplay()),l=h(r||""),i=h(o||g(s,"Search")),c=h(g(s,"Search")),d=h(g(s,"Close search")),u=h(g(s,"Clear search")),m=h(g(s,"Search results"));this.shadowRoot.innerHTML=`
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
                <div class="sm-modal" part="modal" role="dialog" aria-modal="true" aria-label="${c}">
                    <!-- Search input -->
                    <div class="sm-header" part="header">
                        <svg class="sm-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <span class="sm-input-wrap">
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
                                <svg class="sm-spinner" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                                    <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
                                </svg>
                            </div>
                            <button class="sm-clear" part="clear" aria-label="${u}" hidden>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </span>
                        <button class="sm-close" part="close" aria-label="${d}">
                            <kbd>esc</kbd>
                        </button>
                    </div>

                    <!-- Results -->
                    <div class="sm-results" part="results" id="${this.listboxId}" role="listbox" aria-label="${m}"></div>

                    <!-- Debug toolbar (sticky at bottom) -->
                    <div class="sm-debug-toolbar" part="debug-toolbar" hidden></div>

                    <!-- Footer -->
                    <div class="sm-footer" part="footer">
                        <div class="sm-footer-hints">
                            <span><kbd>\u2191</kbd><kbd>\u2193</kbd> ${h(g(s,"navigate"))}</span>
                            <span><kbd>\u21B5</kbd> ${h(g(s,"select"))}</span>
                            <span><kbd>esc</kbd> ${h(g(s,"close"))}</span>
                        </div>
                        <div class="sm-footer-brand">
                            ${h(g(s,"Powered by"))} <a href="https://github.com/LindemannRock/craft-search-manager" target="_blank" rel="noopener noreferrer"><strong>Search Manager</strong><span class="sm-sr-only"> ${h(g(s,"(opens in a new tab)"))}</span></a>
                        </div>
                    </div>
                </div>
            </div>
        `,this.elements={trigger:this.shadowRoot.querySelector(".sm-trigger"),backdrop:this.shadowRoot.querySelector(".sm-backdrop"),modal:this.shadowRoot.querySelector(".sm-modal"),input:this.shadowRoot.querySelector(".sm-input"),results:this.shadowRoot.querySelector(".sm-results"),loading:this.shadowRoot.querySelector(".sm-loading"),clear:this.shadowRoot.querySelector(".sm-clear"),close:this.shadowRoot.querySelector(".sm-close"),debugToolbar:this.shadowRoot.querySelector(".sm-debug-toolbar")},this.initializeLiveRegion(),this.shadowRoot.host.setAttribute("data-theme",t),this.applyCustomStyles()}getResultsContainer(){return this.elements.results}getInputElement(){return this.elements.input}getLoadingElement(){return this.elements.loading}applyCustomStyles(){if(super.applyCustomStyles(),!this.config)return;let{modalBackdropOpacity:t,modalBackdropBlurEnabled:r}=this.config,n=this.shadowRoot.host;n.style.setProperty("--sm-backdrop-opacity",t/100),n.style.setProperty("--sm-backdrop-blur",r?"blur(4px)":"none")}attachEventListeners(){this.elements.trigger.addEventListener("click",this.handleTriggerClick),this.elements.close.addEventListener("click",this.handleCloseClick),this.elements.backdrop.addEventListener("click",this.handleBackdropClick),this.elements.input.addEventListener("input",this.handleInput),this.elements.input.addEventListener("keydown",this.handleKeydown),this.elements.clear.addEventListener("click",this.handleClearClick),document.addEventListener("keydown",this.handleGlobalKeydown);let{triggerSelector:t}=this.config;t&&(this.externalTrigger=document.querySelector(t),this.externalTrigger&&this.externalTrigger.addEventListener("click",this.handleExternalTriggerClick))}detachEventListeners(){this.elements.trigger&&this.elements.trigger.removeEventListener("click",this.handleTriggerClick),this.elements.close&&this.elements.close.removeEventListener("click",this.handleCloseClick),this.elements.backdrop&&this.elements.backdrop.removeEventListener("click",this.handleBackdropClick),this.elements.input&&(this.elements.input.removeEventListener("input",this.handleInput),this.elements.clear.removeEventListener("click",this.handleClearClick),this.elements.input.removeEventListener("keydown",this.handleKeydown)),document.removeEventListener("keydown",this.handleGlobalKeydown),this.externalTrigger&&(this.externalTrigger.removeEventListener("click",this.handleExternalTriggerClick),this.externalTrigger=null)}open(t={}){let r=t.source||"programmatic";if(this.state.get("isOpen")){requestAnimationFrame(()=>{this.elements.input.focus()});return}this.previouslyFocused=document.activeElement instanceof HTMLElement?document.activeElement:null,this.registerOpenWidget(),this.state.set({isOpen:!0}),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value="",this.elements.clear.hidden=!0,this.state.set({query:"",results:[],selectedIndex:-1}),this.renderResultsContent(),requestAnimationFrame(()=>{this.elements.input.focus()}),this.config.modalPreventBodyScroll&&(document.body.style.overflow="hidden"),this.dispatchWidgetEvent("open",{source:r})}close(t={}){let r=this.state.get("isOpen");this.state.set({isOpen:!1}),this.elements.backdrop.hidden=!0,this.elements.trigger.setAttribute("aria-expanded","false"),this.unregisterOpenWidget(),this.config.modalPreventBodyScroll&&(document.body.style.overflow=""),this.resetAnalyticsTracking(),r&&t.restoreFocus!==!1&&this.previouslyFocused?.isConnected&&this.previouslyFocused.focus(),this.previouslyFocused=null,r&&this.dispatchWidgetEvent("close",{reason:t.reason||"programmatic",source:t.source||"programmatic"})}toggle(t={}){this.state.get("isOpen")?this.close({reason:t.reason||"toggle",source:t.source||"toggle"}):this.open({source:t.source||"toggle"})}handleTriggerClick(){this.toggle({source:"trigger"})}handleExternalTriggerClick(){this.toggle({source:"external-trigger"})}handleCloseClick(){this.close({reason:"close-button",source:"close-button"})}handleGlobalKeydown(t){let r=this.config.triggerHotkey.toLowerCase();if((navigator.platform.toUpperCase().indexOf("MAC")>=0?t.metaKey:t.ctrlKey)&&t.key.toLowerCase()===r){if(!this.claimHotkeyEvent(t,r))return;t.preventDefault(),this.toggle({source:"hotkey"})}t.key==="Escape"&&this.state.get("isOpen")&&(t.preventDefault(),this.close({reason:"escape",source:"escape"}))}handleEscape(){this.close({reason:"escape",source:"keyboard"})}handleClearClick(){this.elements.input.value="",this.elements.input.dispatchEvent(new Event("input",{bubbles:!0})),this.elements.input.focus()}handleBackdropClick(t){t.target===this.elements.backdrop&&this.close({reason:"backdrop",source:"backdrop"})}onResultSelected(t,r,n){this.close({reason:"result-selected",source:"result-selected"})}getHotkeyDisplay(){let t=navigator.platform.toUpperCase().indexOf("MAC")>=0,r=this.config.triggerHotkey.toUpperCase();return t?`\u2318${r}`:`Ctrl+${r}`}},Wt=ye;return lt(Yt);})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
