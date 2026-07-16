"use strict";var SearchModalWidget=(()=>{var oe=Object.defineProperty;var st=Object.getOwnPropertyDescriptor;var it=Object.getOwnPropertyNames;var at=Object.prototype.hasOwnProperty;var lt=(e,t)=>{for(var r in t)oe(e,r,{get:t[r],enumerable:!0})},dt=(e,t,r,n)=>{if(t&&typeof t=="object"||typeof t=="function")for(let o of it(t))!at.call(e,o)&&o!==r&&oe(e,o,{get:()=>t[o],enumerable:!(n=st(t,o))||n.enumerable});return e};var ct=e=>dt(oe({},"__esModule",{value:!0}),e);var Yt={};lt(Yt,{default:()=>Wt});var ht={indexHandles:[],placeholder:"Search...",theme:"light",resultsLimit:20,searchDebounceMs:200,searchMinChars:2,recentlyViewedEnabled:!0,recentlyViewedLimit:5,resultsGroupingEnabled:!0,siteId:"",apiKey:"",searchEndpoint:"/actions/search-manager/api/search",trackClickEndpoint:"/actions/search-manager/search/track-click",trackSearchEndpoint:"/actions/search-manager/search/track-search",analyticsIdleTimeoutMs:1500,analyticsSource:"",highlightResultsEnabled:!0,highlightTag:"mark",highlightClass:"",resultsRequireUrl:!1,snippetIncludeCodeBlocks:!1,snippetMode:"balanced",loadingIndicatorEnabled:!0,debugEnabled:!1,snippetMaxLength:150,snippetCleanMarkdown:!1,highlightDestinationPersistQuery:!0,highlightDestinationQueryParam:"smq",highlightDestinationEnabled:!0,highlightDestinationContentSelector:"main, article, [data-search-content]",resultsLayout:"default",hierarchyGroupBy:"",hierarchyStyle:"tree",hierarchyDisplay:"individual",hierarchyMaxHeadings:3,styles:{},translations:{},promotionDisplay:"none",promotionBadgeText:"Featured",promotionBadgePosition:"inline"},mt={triggerHotkey:"k",triggerEnabled:!0,triggerLabel:"Search",triggerSelector:"",modalBackdropOpacity:50,modalBackdropBlurEnabled:!0,modalPreventBodyScroll:!0};function ut(e){return{...ht,...{modal:mt}[e]||{}}}function C(e,t=!1){if(e==null)return t;if(typeof e=="boolean")return e;if(typeof e=="number")return e!==0;if(e==="")return!0;let r=String(e).trim().toLowerCase();return["1","true","on","yes"].includes(r)?!0:["0","false","off","no"].includes(r)?!1:t}function P(e,t=0){if(e==null)return t;let r=Number.parseInt(e,10);return Number.isNaN(r)?t:r}function ke(e,t,r){return t.includes(e)?e:r}function se(e,t={}){if(!e)return t;try{return JSON.parse(e)}catch(r){return console.warn("SearchWidget: Invalid JSON attribute",r),t}}function gt(e){return e?e.split(",").map(t=>t.trim()).filter(Boolean):[]}function Z(e){return e.indexHandles.length>0?e.indexHandles.join(","):"all"}function we(e){return e.indexHandles.length===1?e.indexHandles[0]:""}function U(e,t="modal"){let r=se(e.getAttribute("snippet-defaults"),{}),n={...ut(t),...Object.fromEntries(Object.entries(r).filter(([g])=>["snippetIncludeCodeBlocks","snippetMode","snippetMaxLength","snippetCleanMarkdown","minSnippetLength","maxSnippetLength","snippetModes"].includes(g)))},o=Array.isArray(n.snippetModes)?n.snippetModes:["early","balanced","deep"],s=Number.isFinite(Number(n.minSnippetLength))?Number(n.minSnippetLength):50,i=Number.isFinite(Number(n.maxSnippetLength))?Number(n.maxSnippetLength):1e3,d=Math.min(i,Math.max(s,P(e.getAttribute("snippet-max-length"),n.snippetMaxLength))),a=e.getAttribute("snippet-mode")||n.snippetMode,l=e.getAttribute("index-handles")||"",m={indexHandles:gt(l),placeholder:e.getAttribute("placeholder")||n.placeholder,theme:e.getAttribute("theme")||n.theme,siteId:e.getAttribute("site-id")||n.siteId,apiKey:e.getAttribute("api-key")||n.apiKey,analyticsSource:e.getAttribute("analytics-source")||n.analyticsSource,highlightTag:e.getAttribute("highlight-tag")||n.highlightTag,highlightClass:e.getAttribute("highlight-class")||n.highlightClass,searchEndpoint:n.searchEndpoint,trackClickEndpoint:n.trackClickEndpoint,trackSearchEndpoint:n.trackSearchEndpoint,resultsLimit:P(e.getAttribute("results-limit"),n.resultsLimit),searchDebounceMs:P(e.getAttribute("search-debounce-ms"),n.searchDebounceMs),searchMinChars:P(e.getAttribute("search-min-chars"),n.searchMinChars),recentlyViewedLimit:P(e.getAttribute("recently-viewed-limit"),n.recentlyViewedLimit),analyticsIdleTimeoutMs:P(e.getAttribute("analytics-idle-timeout-ms"),n.analyticsIdleTimeoutMs),recentlyViewedEnabled:C(e.getAttribute("recently-viewed-enabled"),n.recentlyViewedEnabled),resultsGroupingEnabled:C(e.getAttribute("results-grouping-enabled"),n.resultsGroupingEnabled),highlightResultsEnabled:C(e.getAttribute("highlight-results-enabled"),n.highlightResultsEnabled),loadingIndicatorEnabled:C(e.getAttribute("loading-indicator-enabled"),n.loadingIndicatorEnabled),resultsRequireUrl:C(e.getAttribute("results-require-url"),n.resultsRequireUrl),snippetIncludeCodeBlocks:C(e.getAttribute("snippet-include-code-blocks"),n.snippetIncludeCodeBlocks),debugEnabled:C(e.getAttribute("debug-enabled"),n.debugEnabled),snippetMode:o.includes(a)?a:n.snippetMode,snippetMaxLength:d,snippetCleanMarkdown:C(e.getAttribute("snippet-clean-markdown"),n.snippetCleanMarkdown),highlightDestinationPersistQuery:C(e.getAttribute("highlight-destination-persist-query"),n.highlightDestinationPersistQuery),highlightDestinationEnabled:C(e.getAttribute("highlight-destination-enabled"),n.highlightDestinationEnabled),highlightDestinationQueryParam:e.getAttribute("highlight-destination-query-param")||n.highlightDestinationQueryParam,highlightDestinationContentSelector:e.getAttribute("highlight-destination-content-selector")||n.highlightDestinationContentSelector,resultsLayout:e.getAttribute("results-layout")||n.resultsLayout,hierarchyGroupBy:e.getAttribute("hierarchy-group-by")||n.hierarchyGroupBy,hierarchyStyle:e.getAttribute("hierarchy-style")||n.hierarchyStyle,hierarchyDisplay:e.getAttribute("hierarchy-display")||n.hierarchyDisplay,hierarchyMaxHeadings:P(e.getAttribute("hierarchy-max-headings"),n.hierarchyMaxHeadings),styles:se(e.getAttribute("styles"),n.styles),translations:se(e.getAttribute("translations"),n.translations),promotionDisplay:ke(e.getAttribute("promotion-display"),["badge","tint","none"],n.promotionDisplay),promotionBadgeText:e.getAttribute("promotion-badge-text")||n.promotionBadgeText,promotionBadgePosition:ke(e.getAttribute("promotion-badge-position"),["inline","above","below"],n.promotionBadgePosition)};return t==="modal"&&Object.assign(m,{triggerHotkey:e.getAttribute("trigger-hotkey")||n.triggerHotkey,triggerLabel:e.getAttribute("trigger-label")||n.triggerLabel,triggerSelector:e.getAttribute("trigger-selector")||n.triggerSelector,modalBackdropOpacity:P(e.getAttribute("modal-backdrop-opacity"),n.modalBackdropOpacity),triggerEnabled:C(e.getAttribute("trigger-enabled"),n.triggerEnabled),modalBackdropBlurEnabled:C(e.getAttribute("modal-backdrop-blur-enabled"),n.modalBackdropBlurEnabled),modalPreventBodyScroll:C(e.getAttribute("modal-prevent-body-scroll"),n.modalPreventBodyScroll)}),m}function Ce(e="modal"){let t=["index-handles","placeholder","theme","results-limit","search-debounce-ms","search-min-chars","recently-viewed-enabled","recently-viewed-limit","results-grouping-enabled","site-id","analytics-idle-timeout-ms","analytics-source","highlight-results-enabled","highlight-tag","highlight-class","results-require-url","snippet-include-code-blocks","snippet-mode","loading-indicator-enabled","debug-enabled","styles","translations","promotion-display","promotion-badge-text","promotion-badge-position","results-layout","hierarchy-group-by","hierarchy-style","hierarchy-display","hierarchy-max-headings","snippet-max-length","snippet-clean-markdown","highlight-destination-persist-query","highlight-destination-query-param","highlight-destination-enabled","highlight-destination-content-selector"],n={modal:["trigger-hotkey","trigger-enabled","trigger-label","trigger-selector","modal-backdrop-opacity","modal-backdrop-blur-enabled","modal-prevent-body-scroll"]};return[...t,...n[e]||[]]}var ee={isOpen:!1,query:"",results:[],recentlyViewed:[],selectedIndex:-1,loading:!1,error:null,meta:null};function Se(e={},t=null){let r={...ee,...e};return{get(n){return r[n]},getAll(){return{...r}},set(n){let o=[];return Object.keys(n).forEach(s=>{let i=r[s],d=n[s];te(i,d)||o.push(s)}),o.length>0&&(r={...r,...n},t&&t(r,o)),o},reset(n=e){let o={...ee,...n},s=Object.keys(o).filter(i=>!te(r[i],o[i]));s.length>0&&(r=o,t&&t(r,s))},is(n,o){return r[n]===o},toggle(n){let o=!r[n];return this.set({[n]:o}),o}}}function te(e,t){if(e===t)return!0;if(e==null||t==null)return!1;if(Array.isArray(e)&&Array.isArray(t))return e.length!==t.length?!1:e.every((r,n)=>te(r,t[n]));if(typeof e=="object"&&typeof t=="object"){let r=Object.keys(e),n=Object.keys(t);return r.length!==n.length?!1:r.every(o=>te(e[o],t[o]))}return!1}function u(e,t,r={}){let n=e&&Object.prototype.hasOwnProperty.call(e,t)?e[t]:t;return String(n).replace(/\{([a-zA-Z0-9_]+)\}/g,(o,s)=>Object.prototype.hasOwnProperty.call(r,s)?String(r[s]):o)}async function De({query:e,endpoint:t,indexHandles:r=[],siteId:n="",resultsLimit:o=10,resultsRequireUrl:s=!1,snippetIncludeCodeBlocks:i=!1,snippetMode:d="",snippetMaxLength:a=0,snippetCleanMarkdown:l=!1,debugEnabled:c=!1,apiKey:m="",signal:g,translations:p={}}){let b=new URLSearchParams({q:e,resultsLimit:o.toString()});r.length>0&&b.append("indexHandles",r.join(",")),n&&b.append("siteId",n),s&&b.append("resultsRequireUrl","1"),i&&b.append("snippetIncludeCodeBlocks","1"),d&&b.append("snippetMode",d),a&&b.append("snippetMaxLength",String(a)),l&&b.append("snippetCleanMarkdown","1"),c&&b.append("debugEnabled","1"),b.append("skipAnalytics","1");let x=t.includes("?")?"&":"?",$={Accept:"application/json"};m&&($["X-Search-Manager-Key"]=m);let v=await fetch(`${t}${x}${b}`,{signal:g,headers:$});if(!v.ok)throw new Error(await pt(v,p));let y=await v.json();return y.error&&console.warn("Search warning:",y.error),{results:y.results||y.hits||[],total:y.total||0,meta:y.meta||null,error:y.error||null}}async function pt(e,t={}){let r=await bt(e);return e.status===401?r||u(t,"Search requires an API key."):e.status===403?r||u(t,"This API key cannot access this search."):e.status===429?r||u(t,"Search rate limit exceeded. Try again in a moment."):r||u(t,"Search failed.")}async function bt(e){try{if((e.headers.get("content-type")||"").includes("application/json")){let r=await e.json(),n=r.error||r.message||"";return typeof n=="string"?n.slice(0,240):""}}catch{return""}return""}function Te({endpoint:e,elementId:t,query:r,index:n,apiKey:o=""}){if(!(!t||!e))try{let s=new FormData;s.append("elementId",t),s.append("query",r),s.append("index",n);let i={Accept:"application/json"};o&&(i["X-Search-Manager-Key"]=o),fetch(e,{method:"POST",body:s,headers:i}).catch(()=>{})}catch{}}function Ae({endpoint:e,query:t,indexHandles:r=[],resultsCount:n=0,trigger:o="unknown",analyticsSource:s="",siteId:i="",cached:d,took:a,apiKey:l=""}){if(!(!t||!e))try{let c=new FormData;c.append("q",t),c.append("indexHandles",r.join(",")),c.append("resultsCount",n.toString()),c.append("trigger",o),c.append("analyticsSource",s||"frontend-widget"),i&&c.append("siteId",i),typeof d=="boolean"&&c.append("cached",d?"1":"0"),typeof a=="number"&&Number.isFinite(a)&&a>=0&&c.append("took",a.toString());let m={Accept:"application/json"};l&&(m["X-Search-Manager-Key"]=l),fetch(e,{method:"POST",body:c,headers:m}).catch(()=>{})}catch{}}function Be(e){let t={};return e.forEach(r=>{let n=r.source||r.entrySection||r.type||"Results";t[n]||(t[n]=[]),t[n].push(r)}),t}function Ee(e,t){let r={};return e.forEach(n=>{let o=(t?n[t]:null)||n.source||n.entrySection||n.type||"Results";r[o]||(r[o]=[]),r[o].push(n)}),r}var ft="sm-recently-viewed-";function ie(e){return`${ft}${e||"default"}`}function re(e){try{let t=ie(e),r=localStorage.getItem(t);return r?JSON.parse(r):[]}catch{return[]}}function Ie(e,t,r=null,n=5){if(!t||!t.trim())return re(e);let o=ie(e),s={query:t.trim(),title:r?.title||t,url:r?.url||null,timestamp:Date.now()},i=re(e);i=i.filter(d=>d.query!==s.query),i.unshift(s),i=i.slice(0,n);try{localStorage.setItem(o,JSON.stringify(i))}catch{}return i}function $e(e){try{let t=ie(e);localStorage.removeItem(t)}catch{}}var Le={spinnerColor:"#3b82f6",spinnerColorDark:"#60a5fa",modalBg:"#ffffff",modalBgDark:"#1f2937",modalBorderRadius:"12",modalBorderWidth:"1",modalBorderColor:"#e5e7eb",modalBorderColorDark:"#374151",modalShadow:"0 25px 50px -12px rgba(0, 0, 0, 0.25)",modalShadowDark:"0 25px 50px -12px rgba(0, 0, 0, 0.5)",modalMaxWidth:"640",modalMaxHeight:"80",modalPaddingX:"16",modalPaddingY:"16",headerBg:"transparent",headerBgDark:"transparent",headerBorderColor:"#e5e7eb",headerBorderColorDark:"#374151",headerBorderWidth:"1",headerBorderRadius:"0",headerPaddingX:"16",headerPaddingY:"12",inputBg:"#ffffff",inputBgDark:"#1f2937",inputTextColor:"#111827",inputTextColorDark:"#f9fafb",inputPlaceholderColor:"#9ca3af",inputPlaceholderColorDark:"#9ca3af",inputBorderColor:"transparent",inputBorderColorDark:"transparent",inputFontSize:"16",inputBorderRadius:"0",inputBorderWidth:"0",inputPaddingX:"0",inputPaddingY:"0",resultBg:"transparent",resultBgDark:"transparent",resultBorderColor:"#e5e7eb",resultBorderColorDark:"#374151",resultActiveBg:"#e5e7eb",resultActiveBgDark:"#4b5563",resultActiveBorderColor:"#e5e7eb",resultActiveBorderColorDark:"#374151",resultActiveTextColor:"#111827",resultActiveTextColorDark:"#f9fafb",resultActiveDescColor:"#4b5563",resultActiveDescColorDark:"#d1d5db",resultActiveMutedColor:"#6b7280",resultActiveMutedColorDark:"#d1d5db",resultTextColor:"#111827",resultTextColorDark:"#f9fafb",resultDescColor:"#4b5563",resultDescColorDark:"#d1d5db",resultMutedColor:"#6b7280",resultMutedColorDark:"#d1d5db",resultGap:"8",resultBorderWidth:"0",resultPaddingX:"12",resultPaddingY:"12",resultBorderRadius:"8",triggerBg:"#ffffff",triggerBgDark:"#374151",triggerTextColor:"#374151",triggerTextColorDark:"#d1d5db",triggerBorderRadius:"8",triggerBorderWidth:"1",triggerBorderColor:"#d1d5db",triggerBorderColorDark:"#4b5563",triggerHoverBg:"#f9fafb",triggerHoverBgDark:"#4b5563",triggerHoverTextColor:"#111827",triggerHoverTextColorDark:"#f9fafb",triggerHoverBorderColor:"#3b82f6",triggerHoverBorderColorDark:"#60a5fa",triggerPaddingX:"12",triggerPaddingY:"8",triggerFontSize:"14",kbdBg:"#f3f4f6",kbdBgDark:"#4b5563",kbdTextColor:"#4b5563",kbdTextColorDark:"#e5e7eb",kbdBorderRadius:"4",backdropOpacity:"50",backdropBlur:"1",highlightResultsEnabled:"1",highlightTag:"",highlightClass:"",highlightBgLight:"fef08a",highlightColorLight:"854d0e",highlightBgDark:"854d0e",highlightColorDark:"fef08a",highlightActiveBgLight:"",highlightActiveColorLight:"",highlightActiveBgDark:"",highlightActiveColorDark:"",iconColor:"#3b82f6",iconColorDark:"#60a5fa",hierarchyConnectorColor:"",hierarchyConnectorColorDark:"",searchIconColor:"",searchIconColorDark:"",clearIconColor:"",clearIconColorDark:"",resultIconColor:"",resultIconColorDark:"",arrowColor:"",arrowColorDark:"",iconActiveColor:"",iconActiveColorDark:"",resultIconActiveColor:"",resultIconActiveColorDark:"",hierarchyConnectorActiveColor:"",hierarchyConnectorActiveColorDark:"",promotedBg:"#2563eb",promotedBgDark:"#2563eb",promotedColor:"#ffffff",promotedColorDark:"#ffffff",promotedActiveBg:"",promotedActiveBgDark:"",promotedActiveColor:"",promotedActiveColorDark:"",promotedBorderColor:"",promotedBorderColorDark:"",promotedPaddingX:"6",promotedPaddingY:"2",promotedBorderRadius:"4",promotedBorderWidth:"0",promotedTintBg:"#eff6ff",promotedTintBgDark:"#1e3a8a",promotedTintTextColor:"",promotedTintTextColorDark:"",promotedTintActiveBg:"",promotedTintActiveBgDark:"",scrollbarColor:"",scrollbarColorDark:"",footerBg:"",footerBgDark:"",footerTextColor:"",footerTextColorDark:"",footerPaddingX:"16",footerPaddingY:"16"};var Q={modalBg:"--sm-modal-bg",modalBgDark:"--sm-modal-bg-dark",modalBorderRadius:"--sm-modal-radius",modalBorderWidth:"--sm-modal-border-width",modalBorderColor:"--sm-modal-border-color",modalBorderColorDark:"--sm-modal-border-color-dark",modalShadow:"--sm-modal-shadow",modalShadowDark:"--sm-modal-shadow-dark",modalMaxWidth:"--sm-modal-width",modalMaxHeight:"--sm-modal-max-height",modalPaddingX:"--sm-modal-px",modalPaddingY:"--sm-modal-py",headerBg:"--sm-header-bg",headerBgDark:"--sm-header-bg-dark",headerBorderColor:"--sm-header-border-color",headerBorderColorDark:"--sm-header-border-color-dark",headerBorderWidth:"--sm-header-border-width",headerBorderRadius:"--sm-header-radius",headerPaddingX:"--sm-header-px",headerPaddingY:"--sm-header-py",inputBg:"--sm-input-bg",inputBgDark:"--sm-input-bg-dark",inputTextColor:"--sm-input-color",inputTextColorDark:"--sm-input-color-dark",inputPlaceholderColor:"--sm-input-placeholder",inputPlaceholderColorDark:"--sm-input-placeholder-dark",inputBorderColor:"--sm-input-border-color",inputBorderColorDark:"--sm-input-border-color-dark",inputFontSize:"--sm-input-font-size",inputBorderRadius:"--sm-input-radius",inputBorderWidth:"--sm-input-border-width",inputPaddingX:"--sm-input-px",inputPaddingY:"--sm-input-py",resultBg:"--sm-result-bg",resultBgDark:"--sm-result-bg-dark",resultBorderColor:"--sm-result-border-color",resultBorderColorDark:"--sm-result-border-color-dark",resultActiveBg:"--sm-result-active-bg",resultActiveBgDark:"--sm-result-active-bg-dark",resultActiveBorderColor:"--sm-result-active-border-color",resultActiveBorderColorDark:"--sm-result-active-border-color-dark",resultActiveTextColor:"--sm-result-active-text-color",resultActiveTextColorDark:"--sm-result-active-text-color-dark",resultActiveDescColor:"--sm-result-active-desc-color",resultActiveDescColorDark:"--sm-result-active-desc-color-dark",resultActiveMutedColor:"--sm-result-active-muted-color",resultActiveMutedColorDark:"--sm-result-active-muted-color-dark",resultTextColor:"--sm-result-text-color",resultTextColorDark:"--sm-result-text-color-dark",resultDescColor:"--sm-result-desc-color",resultDescColorDark:"--sm-result-desc-color-dark",resultMutedColor:"--sm-result-muted-color",resultMutedColorDark:"--sm-result-muted-color-dark",resultGap:"--sm-result-gap",resultBorderWidth:"--sm-result-border-width",resultPaddingX:"--sm-result-px",resultPaddingY:"--sm-result-py",resultBorderRadius:"--sm-result-radius",triggerBg:"--sm-trigger-bg",triggerBgDark:"--sm-trigger-bg-dark",triggerTextColor:"--sm-trigger-text-color",triggerTextColorDark:"--sm-trigger-text-color-dark",triggerBorderRadius:"--sm-trigger-radius",triggerBorderWidth:"--sm-trigger-border-width",triggerBorderColor:"--sm-trigger-border-color",triggerBorderColorDark:"--sm-trigger-border-color-dark",triggerHoverBg:"--sm-trigger-hover-bg",triggerHoverBgDark:"--sm-trigger-hover-bg-dark",triggerHoverTextColor:"--sm-trigger-hover-text-color",triggerHoverTextColorDark:"--sm-trigger-hover-text-color-dark",triggerHoverBorderColor:"--sm-trigger-hover-border-color",triggerHoverBorderColorDark:"--sm-trigger-hover-border-color-dark",triggerPaddingX:"--sm-trigger-px",triggerPaddingY:"--sm-trigger-py",triggerFontSize:"--sm-trigger-font-size",kbdBg:"--sm-kbd-bg",kbdBgDark:"--sm-kbd-bg-dark",kbdTextColor:"--sm-kbd-text-color",kbdTextColorDark:"--sm-kbd-text-color-dark",kbdBorderRadius:"--sm-kbd-radius",iconColor:"--sm-icon-color",iconColorDark:"--sm-icon-color-dark",searchIconColor:"--sm-search-icon-color",searchIconColorDark:"--sm-search-icon-color-dark",clearIconColor:"--sm-clear-icon-color",clearIconColorDark:"--sm-clear-icon-color-dark",resultIconColor:"--sm-result-icon-color",resultIconColorDark:"--sm-result-icon-color-dark",arrowColor:"--sm-arrow-color",arrowColorDark:"--sm-arrow-color-dark",iconActiveColor:"--sm-icon-active-color",iconActiveColorDark:"--sm-icon-active-color-dark",resultIconActiveColor:"--sm-result-icon-active-color",resultIconActiveColorDark:"--sm-result-icon-active-color-dark",hierarchyConnectorActiveColor:"--sm-hierarchy-connector-active-color",hierarchyConnectorActiveColorDark:"--sm-hierarchy-connector-active-color-dark",hierarchyConnectorColor:"--sm-hierarchy-connector-color",hierarchyConnectorColorDark:"--sm-hierarchy-connector-color-dark",highlightBgLight:"--sm-highlight-bg",highlightColorLight:"--sm-highlight-color",highlightBgDark:"--sm-highlight-bg-dark",highlightColorDark:"--sm-highlight-color-dark",highlightActiveBgLight:"--sm-highlight-active-bg",highlightActiveColorLight:"--sm-highlight-active-color",highlightActiveBgDark:"--sm-highlight-active-bg-dark",highlightActiveColorDark:"--sm-highlight-active-color-dark",promotedBg:"--sm-promoted-bg",promotedBgDark:"--sm-promoted-bg-dark",promotedColor:"--sm-promoted-color",promotedColorDark:"--sm-promoted-color-dark",promotedActiveBg:"--sm-promoted-active-bg",promotedActiveBgDark:"--sm-promoted-active-bg-dark",promotedActiveColor:"--sm-promoted-active-color",promotedActiveColorDark:"--sm-promoted-active-color-dark",promotedBorderColor:"--sm-promoted-border-color",promotedBorderColorDark:"--sm-promoted-border-color-dark",promotedPaddingX:"--sm-promoted-px",promotedPaddingY:"--sm-promoted-py",promotedBorderRadius:"--sm-promoted-radius",promotedBorderWidth:"--sm-promoted-border-width",promotedTintBg:"--sm-promoted-tint-bg",promotedTintBgDark:"--sm-promoted-tint-bg-dark",promotedTintTextColor:"--sm-promoted-tint-text",promotedTintTextColorDark:"--sm-promoted-tint-text-dark",promotedTintActiveBg:"--sm-promoted-tint-active-bg",promotedTintActiveBgDark:"--sm-promoted-tint-active-bg-dark",spinnerColor:"--sm-spinner-color-light",spinnerColorDark:"--sm-spinner-color-dark",scrollbarColor:"--sm-scrollbar-color",scrollbarColorDark:"--sm-scrollbar-color-dark",footerBg:"--sm-footer-bg",footerBgDark:"--sm-footer-bg-dark",footerTextColor:"--sm-footer-text-color",footerTextColorDark:"--sm-footer-text-color-dark",footerPaddingX:"--sm-footer-px",footerPaddingY:"--sm-footer-py"},ae=["modalBorderRadius","modalBorderWidth","modalMaxWidth","modalPaddingX","modalPaddingY","headerBorderWidth","headerBorderRadius","headerPaddingX","headerPaddingY","inputFontSize","inputBorderRadius","inputBorderWidth","inputPaddingX","inputPaddingY","resultGap","resultBorderWidth","resultPaddingX","resultPaddingY","resultBorderRadius","triggerBorderRadius","triggerBorderWidth","triggerPaddingX","triggerPaddingY","triggerFontSize","kbdBorderRadius","footerPaddingX","footerPaddingY","promotedPaddingX","promotedPaddingY","promotedBorderRadius","promotedBorderWidth"],le=["modalMaxHeight"],Re=["modalBg","modalBgDark","modalBorderColor","modalBorderColorDark","headerBg","headerBgDark","headerBorderColor","headerBorderColorDark","inputBg","inputBgDark","inputTextColor","inputTextColorDark","inputPlaceholderColor","inputPlaceholderColorDark","inputBorderColor","inputBorderColorDark","resultBg","resultBgDark","resultBorderColor","resultBorderColorDark","resultActiveBg","resultActiveBgDark","resultActiveBorderColor","resultActiveBorderColorDark","resultTextColor","resultTextColorDark","resultActiveTextColor","resultActiveTextColorDark","resultActiveDescColor","resultActiveDescColorDark","resultActiveMutedColor","resultActiveMutedColorDark","resultDescColor","resultDescColorDark","resultMutedColor","resultMutedColorDark","triggerBg","triggerBgDark","triggerTextColor","triggerTextColorDark","triggerBorderColor","triggerBorderColorDark","triggerHoverBg","triggerHoverBgDark","triggerHoverTextColor","triggerHoverTextColorDark","triggerHoverBorderColor","triggerHoverBorderColorDark","kbdBg","kbdBgDark","kbdTextColor","kbdTextColorDark","iconColor","iconColorDark","searchIconColor","searchIconColorDark","clearIconColor","clearIconColorDark","resultIconColor","resultIconColorDark","arrowColor","arrowColorDark","iconActiveColor","iconActiveColorDark","resultIconActiveColor","resultIconActiveColorDark","hierarchyConnectorActiveColor","hierarchyConnectorActiveColorDark","hierarchyConnectorColor","hierarchyConnectorColorDark","highlightBgLight","highlightColorLight","highlightBgDark","highlightColorDark","highlightActiveBgLight","highlightActiveColorLight","highlightActiveBgDark","highlightActiveColorDark","promotedBg","promotedBgDark","promotedColor","promotedColorDark","promotedActiveBg","promotedActiveBgDark","promotedActiveColor","promotedActiveColorDark","promotedBorderColor","promotedBorderColorDark","promotedTintBg","promotedTintBgDark","promotedTintTextColor","promotedTintTextColorDark","promotedTintActiveBg","promotedTintActiveBgDark","spinnerColor","spinnerColorDark","scrollbarColor","scrollbarColorDark","footerBg","footerBgDark","footerTextColor","footerTextColorDark"],sr={...Le,highlightBgLight:"#fef08a",highlightColorLight:"#854d0e",highlightBgDark:"#854d0e",highlightColorDark:"#fef08a"};var de=new WeakMap;function yt(){let e=new Set,t=new Set;for(let r of Object.keys(Q)){if(r.endsWith("Dark")){t.add(r);continue}if(r.endsWith("Light")){let n=r.replace(/Light$/,"Dark");Q[n]&&e.add(r);continue}Q[`${r}Dark`]&&e.add(r)}return{lightKeys:e,darkKeys:t}}var _e=yt();function xt(e){return typeof e=="string"&&/^(var|light-dark|calc|env|clamp|min|max|rgb|hsl)\s*\(/.test(e.trim())}function kt(e){return/^[0-9a-fA-F]{6}$/.test(e)}function wt(e,t){if(t==null||t==="")return null;let r=String(t);return xt(r)||(Re.includes(e)&&kt(r)&&(r="#"+r),ae.includes(e)&&(r=r+"px"),le.includes(e)&&(r=r+"vh")),r}function Me(e,t,r="light"){if(!e)return;let n=de.get(e);if(n){for(let a of n)e.style.removeProperty(a);de.delete(e)}if(!t||typeof t!="object")return;let o=r==="dark",s=Object.entries(Q),i=new Set([...ae,...le]),d=new Set;for(let[a,l]of s){let c=_e.lightKeys.has(a),m=_e.darkKeys.has(a),g=c||m;if(o){if(c||!m&&!i.has(a))continue}else if(m)continue;if(t[a]!==void 0&&t[a]!==null&&t[a]!==""){let p=wt(a,t[a]);p&&(e.style.setProperty(l,p),g&&d.add(l))}}d.size>0&&de.set(e,d)}var Ct=new Set(["mark","em","strong","u","b","i","span"]),St=/^[A-Za-z0-9_-]+$/;function h(e){return e?String(e).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#39;"):""}function He(e){return e?e.replace(/[.*+?^${}()|[\]\\]/g,"\\$&"):""}function ce(e){if(!e)return[];let t=[],r=/"([^"]+)"/g,n;for(;(n=r.exec(e))!==null;)n[1].trim()&&t.push(n[1].trim());let o=e.replace(/"[^"]*"/g,""),s=new Set(["and","or","not","und","oder","nicht","et","ou","sauf","y","o","no"]);o.split(/\s+/).filter(d=>d.length>0).forEach(d=>{d=d.replace(/^[a-zA-Z]+:/,""),d=d.replace(/\*/g,""),d=d.replace(/\^\d+(\.\d+)?/,""),d=d.replace(/"/g,""),!(!d||s.has(d.toLowerCase()))&&t.push(d)});let i=[];return t.forEach(d=>{i.push(d);let a=d.split(/(?<=[a-z])(?=[A-Z])/);a.length>1&&a.forEach(l=>{l.length>=3&&i.push(l)})}),i}function X(e,t,r={}){let{enabled:n=!0,tag:o="mark",className:s="",terms:i=null}=r;if(!n)return h(e);let d=Dt(o),l=["sm-highlight",...Tt(s)],c=` class="${h(l.join(" "))}"`,m=At(t,i);return m.length===0?h(e):Bt(e,m,d,c)}function Dt(e){let t=String(e||"mark").trim().toLowerCase();return Ct.has(t)?t:"mark"}function Tt(e){return String(e||"").trim().split(/\s+/).filter(t=>t&&St.test(t))}function At(e,t){return Array.isArray(t)&&t.length>0?Pe(t):e?Pe(ce(e)):[]}function Pe(e){let t=new Set;return e.filter(r=>typeof r=="string"&&r.length>0).sort((r,n)=>n.length-r.length).filter(r=>{let n=r.toLowerCase();return t.has(n)?!1:(t.add(n),!0)})}function Bt(e,t,r,n){let o=e.toLowerCase(),s=[];if(t.forEach(c=>{let m=c.toLowerCase();if(!m)return;let g=0;for(;g<o.length;){let p=o.indexOf(m,g);if(p===-1)break;s.push({start:p,end:p+m.length}),g=p+m.length}}),s.length===0)return h(e);s.sort((c,m)=>c.start!==m.start?c.start-m.start:m.end-m.start-(c.end-c.start));let i=[],d=-1;s.forEach(c=>{c.start>=d&&(i.push(c),d=c.end)});let a="",l=0;return i.forEach(c=>{l<c.start&&(a+=h(e.slice(l,c.start))),a+=`<${r}${n}>${h(e.slice(c.start,c.end))}</${r}>`,l=c.end}),l<e.length&&(a+=h(e.slice(l))),a}function K(e,t,r="smq"){if(!e||e==="#")return e;if(Et(e))return"#";let n=(t||"").trim();if(!n||!r||/^(mailto:|tel:)/i.test(e))return e;let[o,s]=e.split("#",2),[i,d]=o.split("?",2),a=new URLSearchParams(d||"");a.set(r,n);let l=a.toString(),c=s?`#${s}`:"";return`${i}${l?`?${l}`:""}${c}`}function Et(e){let t=String(e).replace(/[\t\n\r]/g,"").replace(/^[\u0000-\u0020]+/,"");return/^(javascript|data|vbscript):/i.test(t)}var It=0;function he(e="sm"){return`${e}-${++It}-${Date.now().toString(36)}`}function Oe(e){let t=document.createElement("div");return t.setAttribute("role","status"),t.setAttribute("aria-live","polite"),t.setAttribute("aria-atomic","true"),t.className="sm-sr-only",e.appendChild(t),t}function J(e,t,r=100){e&&(e.textContent="",setTimeout(()=>{e.textContent=t},r))}function me(e,t,r={}){return e===0?u(r,'No results found for "{query}"',{query:t}):e===1?u(r,'1 result found for "{query}"',{query:t}):u(r,'{count} results found for "{query}"',{count:e,query:t})}function Ne(e={}){return u(e,"Searching...")}function Fe(e,t={}){return e===0?u(t,"No recently viewed items"):e===1?u(t,"1 recently viewed item available"):u(t,"{count} recently viewed items available",{count:e})}function ne(e,{expanded:t,activeDescendant:r,listboxId:n}){e.setAttribute("aria-expanded",String(t)),e.setAttribute("aria-controls",n),r?e.setAttribute("aria-activedescendant",r):e.removeAttribute("aria-activedescendant")}function q(e,t){return`${e}-option-${t}`}function qe(e,t){if(!e||!t)return;let r=e.getBoundingClientRect(),n=t.getBoundingClientRect();r.top<n.top?e.scrollIntoView({block:"nearest",behavior:"smooth"}):r.bottom>n.bottom&&e.scrollIntoView({block:"nearest",behavior:"smooth"})}function $t(e,t,r={}){let{resultsGroupingEnabled:n=!1,resultsLayout:o="default",listboxId:s}=r;if(!e||e.length===0)return"";if(o==="hierarchical")return Ft(e,t,r);if(n){let i=Be(e),d=0;return Object.entries(i).map(([a,l])=>`
            <div class="sm-section" role="group" aria-label="${h(a)}">
                <div class="sm-section-header">${h(a)}</div>
                ${l.map(c=>Ve(c,d++,t,r)).join("")}
            </div>
        `).join("")}return e.map((i,d)=>Ve(i,d,t,r)).join("")}function Ve(e,t,r,n={}){let{listboxId:o,highlightResultsEnabled:s=!0,highlightTag:i="mark",highlightClass:d="",resultsGroupingEnabled:a=!1,debugEnabled:l=!1,highlightDestinationPersistQuery:c=!1,highlightDestinationQueryParam:m="smq",translations:g={}}=n,p=pe(e),b=p?e.sectionTitle||e.title||e.name||u(g,"Untitled"):e.title||e.name||u(g,"Untitled"),x=e.snippet||"",$=p?e.sectionUrl||e.url||e.href||"#":e.url||e.href||"#",v=K($,r,c?m:""),y=e.source||e.entrySection||e.type||"",A=q(o,t),k=e._index||e.index||"",w=be(e),D={enabled:s,tag:i,className:d},R=X(b,r,{...D,terms:N(e,"title")}),B=x?ge(e,x,r,{...D,terms:N(e,"snippet")}):"",f=Ue(e,n),T=f.rowClass,E=y&&!a?`<span class="sm-result-type">${h(y)}</span>`:"",_=l?Ge(e,g):"";return l?`
            <a class="sm-result-item sm-debug-enabled${T}" id="${A}" role="option" aria-selected="false" href="${h(v)}" data-index="${t}" data-source-index="${h(k)}"${w} data-title="${h(b)}">
                <div class="sm-result-main">
                    <div class="sm-result-content">
                        ${f.aboveMarkup}
                        <span class="sm-result-title">${f.titlePrefix}${R}${f.titleSuffix}</span>
                        ${f.blockMarkup}
                        ${B?`<span class="sm-result-desc">${B}</span>`:""}
                    </div>
                    ${E}
                    ${V()}
                </div>
                ${_}
            </a>
        `:`
        <a class="sm-result-item${T}" id="${A}" role="option" aria-selected="false" href="${h(v)}" data-index="${t}" data-source-index="${h(k)}"${w} data-title="${h(b)}">
            <div class="sm-result-content">
                ${f.aboveMarkup}
                <span class="sm-result-title">${f.titlePrefix}${R}${f.titleSuffix}</span>
                ${f.blockMarkup}
                ${B?`<span class="sm-result-desc">${B}</span>`:""}
            </div>
            ${E}
            ${V()}
        </a>
    `}function Ge(e,t={}){let r=[],n=e.backend?e.backend.toLowerCase():"";if((e._index||e.index)&&r.push(S("index",e._index||e.index,"index","",t)),e.backend&&r.push(S("backend",n,"backend",n,t)),e.elementId&&r.push(S("element",e.elementId,"generic","",t)),e.backendId&&r.push(S("hit",e.backendId,"generic","",t)),e.score!==void 0&&e.score!==null){let o=typeof e.score=="number"?e.score.toFixed(2):e.score;r.push(S("score",o,"score","",t))}if(e.site&&r.push(S("site",e.site,"generic","",t)),e.language&&r.push(S("lang",e.language,"generic","",t)),e.matchedIn&&Array.isArray(e.matchedIn)&&e.matchedIn.length>0){let o=e.matchedIn.join(", ");r.push(S("matched",o,"matched","",t))}return e.promoted&&r.push(S("promoted",u(t,"yes"),"promoted","",t)),e.boosted&&r.push(S("boosted",u(t,"yes"),"boosted","",t)),r.length===0?"":`<div class="sm-debug-info">${r.join("")}</div>`}function N(e,t){let r=Array.isArray(e.matchedPhrases)?e.matchedPhrases:[],n=e.matchedTerms,o=[];n&&(t==="title"&&Array.isArray(n.title)&&n.title.length>0?o=n.title:t==="snippet"&&Array.isArray(n.content)&&n.content.length>0?o=n.content:o=[...Array.isArray(n.title)?n.title:[],...Array.isArray(n.content)?n.content:[]]);let s=[...r,...o];return s.length>0?s:null}function ge(e,t,r,n){return X(t,r,n)}function S(e,t,r,n="",o={}){let s=n?` data-backend="${h(n)}"`:"";return`<span class="sm-debug-item"><span class="sm-debug-label">${h(u(o,e))}</span><span class="sm-debug-value" data-type="${h(r)}"${s}>${h(String(t))}</span></span>`}function Ue(e,t={}){let{promotionDisplay:r="none",promotionBadgeText:n="Featured",promotionBadgePosition:o="inline"}=t;if(e.promoted!==!0||r==="none")return{rowClass:"",titlePrefix:"",titleSuffix:"",aboveMarkup:"",blockMarkup:""};if(r==="tint")return{rowClass:" sm-promoted sm-promoted--tint",titlePrefix:"",titleSuffix:`<span class="sm-sr-only"> ${h(n)}</span>`,aboveMarkup:"",blockMarkup:""};let s=`<span class="sm-promoted-badge">${h(n)}</span>`;return o==="above"?{rowClass:" sm-promoted",titlePrefix:"",titleSuffix:"",aboveMarkup:`<span class="sm-promoted-badge-row sm-promoted-badge-row--above">${s}</span>`,blockMarkup:""}:o==="below"?{rowClass:" sm-promoted",titlePrefix:"",titleSuffix:"",aboveMarkup:"",blockMarkup:`<span class="sm-promoted-badge-row">${s}</span>`}:{rowClass:" sm-promoted",titlePrefix:s,titleSuffix:"",aboveMarkup:"",blockMarkup:""}}function pe(e){return!!(e&&typeof e=="object"&&["heading","intro","promoted-page"].includes(String(e.sectionType||"")))}function Lt(e){return Array.isArray(e)&&e.some(pe)}function Rt(e,t){let r=new Map,n=[];return e.forEach((o,s)=>{if(!pe(o)){n.push({type:"single",item:o,order:s,score:O(o)});return}let i=Pt(o);if(!r.has(i)){let l={hits:[],order:s,score:O(o)};r.set(i,l),n.push({type:"section-group",key:i,order:s,score:l.score})}let d=r.get(i);d.hits.push(o),d.score=Math.max(d.score,O(o));let a=n.find(l=>l.type==="section-group"&&l.key===i);a&&(a.score=d.score)}),n.map(o=>{if(o.type==="section-group"){let s=r.get(o.key);return{...o,item:_t(s.hits,t)}}return o}).sort((o,s)=>{let i=ue(s.score,o.score);return i!==0?i:o.order-s.order}).map(o=>o.item)}function _t(e,t){let r=[...e].sort((c,m)=>H(c)-H(m)),n=r.find(c=>c.sectionType==="intro")||null,o=[...e].sort((c,m)=>{let g=ue(O(m),O(c));return g!==0?g:H(c)-H(m)})[0]||r[0]||{},s=n||o,i=W(s),d=s.siteId??"",a=Number.isFinite(t)&&t>0?t:3,l=r.filter(c=>c.sectionType==="heading").sort((c,m)=>{let g=ue(O(m),O(c));return g!==0?g:H(c)-H(m)}).slice(0,a).sort((c,m)=>H(c)-H(m)).map(Mt);return{...s,elementId:i||s.elementId,backendId:n?.backendId||s.backendId||Ht(i,d),title:s.title||s.sectionTitle||s.name||"",url:s.url||"#",snippet:n&&n.snippet||null,score:O(o),promoted:r.some(c=>c.promoted===!0),headings:l,__sectionHitGroup:!0,__useBackendDomId:!0}}function Mt(e){let t=Number.parseInt(e.sectionLevel,10),r=Number.isFinite(t)?t:2;return{title:e.sectionTitle||e.title||"",text:e.sectionTitle||e.title||"",id:e.sectionAnchor||e.sectionId||"",level:r,url:e.sectionUrl||e.url||null,snippet:e.snippet||null,backendId:e.backendId||"",elementId:W(e),sectionType:e.sectionType,_index:e._index,index:e.index,matchedTerms:e.matchedTerms,matchedPhrases:e.matchedPhrases,__useBackendDomId:!0}}function Pt(e){return[W(e)||Ke(e)||"",e.siteId??""].join(":")}function H(e){let t=Number.parseInt(e.sectionIndex,10);return Number.isFinite(t)?t:Number.MAX_SAFE_INTEGER}function O(e){let t=Number(e?.score);return Number.isFinite(t)?t:Number.NEGATIVE_INFINITY}function ue(e,t){return e===t?0:e>t?1:-1}function Ht(e,t){let r=e||"unknown";return t!=null&&String(t)!==""?`${r}_${t}`:String(r)}function Ke(e,t=null){return e?.backendId||t?.backendId||""}function W(e,t=null){return e?.elementId||t?.elementId||""}function be(e,t=null){let r=Ke(e,t)||W(e,t),n=W(e,t);return` data-id="${h(r)}" data-element-id="${h(n)}"`}function V(){return`<svg class="sm-result-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
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
    </svg>`}function ze(){return`<svg class="sm-hierarchy-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <line x1="4" y1="9" x2="20" y2="9"/>
        <line x1="4" y1="15" x2="20" y2="15"/>
        <line x1="10" y1="3" x2="8" y2="21"/>
        <line x1="16" y1="3" x2="14" y2="21"/>
    </svg>`}function Ft(e,t,r={}){let{hierarchyGroupBy:n="",hierarchyStyle:o="tree",hierarchyDisplay:s="individual",hierarchyMaxHeadings:i=3,listboxId:d}=r,a=o==="tree",l=o!=="none",c=Lt(e)?Rt(e,i):e,g=Ee(c,n||""),p=0;return Object.entries(g).map(([b,x])=>{let $=x.map(v=>{let y=p++,A=qt(v,y,t,r),k="",w=v.headings||[],D=v.__sectionHitGroup?w:w.slice(0,i);if(D.length>0){let f=Math.min(...D.map(E=>E.level||2)),T=D.map(E=>a?(E.level||2)-f:0);k=D.map((E,_)=>{let z=T[_],j=!T.slice(_+1).some(G=>G===z),Y=[];if(a){let G=T.slice(_+1);for(let F=0;F<z;F++)G.some(M=>M===F)&&Y.push(F)}return Vt(v,E,p++,t,r,j,z,Y)}).join("")}let R=!!k;return`
                <div class="sm-hierarchy-block${R?" sm-hierarchy-block--has-children":""}${s==="unified"?" sm-hierarchy-block--unified":""}">
                    ${R?A.replace("sm-result-item sm-hierarchy-parent","sm-result-item sm-hierarchy-parent sm-hierarchy-parent--has-children"):A}
                    ${R?`<div class="sm-hierarchy-children${l?"":" sm-hierarchy-children--no-connectors"}">${k}</div>`:""}
                </div>
            `}).join("");return`
            <div class="sm-hierarchy-group" role="group" aria-label="${h(b)}">
                <div class="sm-hierarchy-group-header">${h(b)}</div>
                ${$}
            </div>
        `}).join("")}function qt(e,t,r,n={}){let{listboxId:o,highlightResultsEnabled:s=!0,highlightTag:i="mark",highlightClass:d="",debugEnabled:a=!1,highlightDestinationPersistQuery:l=!1,highlightDestinationQueryParam:c="smq",translations:m={}}=n,g=e.title||e.name||u(m,"Untitled"),p=e.snippet||"",b=e.url||"#",x=K(b,r,l?c:""),$=q(o,t),v=e._index||e.index||"",y=be(e),A={enabled:s,tag:i,className:d},k=X(g,r,{...A,terms:N(e,"title")}),w=p?ge(e,p,r,{...A,terms:N(e,"snippet")}):"",D=a?Ge(e,m):"",B=e.headings&&e.headings.length>0?Ot():Nt(),f=Ue(e,n),T=f.rowClass;return a?`
            <a class="sm-result-item sm-hierarchy-parent sm-debug-enabled${T}" id="${$}" role="option" aria-selected="false" href="${h(x)}" data-index="${t}" data-source-index="${h(v)}"${y} data-title="${h(g)}">
                <div class="sm-result-main">
                    ${B}
                    <div class="sm-result-content">
                        ${f.aboveMarkup}
                        <span class="sm-result-title">${f.titlePrefix}${k}${f.titleSuffix}</span>
                        ${f.blockMarkup}
                        ${w?`<span class="sm-result-desc">${w}</span>`:""}
                    </div>
                    ${V()}
                </div>
                ${D}
            </a>
        `:`
        <a class="sm-result-item sm-hierarchy-parent${T}" id="${$}" role="option" aria-selected="false" href="${h(x)}" data-index="${t}" data-source-index="${h(v)}"${y} data-title="${h(g)}">
            ${B}
            <div class="sm-result-content">
                ${f.aboveMarkup}
                <span class="sm-result-title">${f.titlePrefix}${k}${f.titleSuffix}</span>
                ${f.blockMarkup}
                ${w?`<span class="sm-result-desc">${w}</span>`:""}
            </div>
            ${V()}
        </a>
    `}function Vt(e,t,r,n,o={},s=!1,i=0,d=[]){let{listboxId:a,highlightResultsEnabled:l=!0,highlightTag:c="mark",highlightClass:m="",debugEnabled:g=!1,highlightDestinationPersistQuery:p=!1,highlightDestinationQueryParam:b="smq",translations:x={}}=o,v=(t.title||t.text||"").replace(/^#+\s*/,""),y=t.snippet||"",A=Number.parseInt(t.level,10),k=Number.isFinite(A)?Math.min(Math.max(A,1),6):2,w=t.id||(v?zt(v):""),D=e.url||"#",R=t.url||(w?`${D}#${w}`:D),B=K(R,n,p?b:""),f=q(a,r),T=t._index||t.index||e._index||e.index||"",E=be(t,e),_={enabled:l,tag:c,className:m},z=X(v,n,{..._,terms:N(t,"title")||N(e,"title")}),j=y?ge(e,y,n,{..._,terms:N(t,"snippet")||N(e,"snippet")}):"",Y=s?" sm-hierarchy-child-row-last":"",G=d.map(M=>`<div class="sm-hierarchy-guide" style="--sm-guide-depth:${M}" aria-hidden="true"></div>`).join(""),F="";if(g){let M=[];M.push(S("h",k,"generic","",x)),w&&M.push(S("anchor",w,"generic","",x));let xe=W(t,e);xe&&M.push(S("parent",xe,"generic","",x)),F=`<div class="sm-debug-info">${M.join("")}</div>`}return g?`
            <div class="sm-hierarchy-child-row sm-hierarchy-level-${k} sm-hierarchy-depth-${i}${Y}" style="--sm-hierarchy-depth:${i}">
                ${G}
                <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${k} sm-debug-enabled" id="${f}" role="option" aria-selected="false" href="${h(B)}" data-index="${r}" data-source-index="${h(T)}"${E} data-title="${h(v)}">
                    <div class="sm-result-main">
                        ${ze()}
                        <div class="sm-result-content">
                            <span class="sm-result-title">${z}</span>
                            ${j?`<span class="sm-result-desc">${j}</span>`:""}
                        </div>
                        ${V()}
                    </div>
                    ${F}
                </a>
            </div>
        `:`
        <div class="sm-hierarchy-child-row sm-hierarchy-level-${k} sm-hierarchy-depth-${i}${Y}" style="--sm-hierarchy-depth:${i}">
            ${G}
            <a class="sm-result-item sm-hierarchy-child sm-hierarchy-level-${k}" id="${f}" role="option" aria-selected="false" href="${h(B)}" data-index="${r}" data-source-index="${h(T)}"${E} data-title="${h(v)}">
                ${ze()}
                <div class="sm-result-content">
                    <span class="sm-result-title">${z}</span>
                    ${j?`<span class="sm-result-desc">${j}</span>`:""}
                </div>
                ${V()}
            </a>
        </div>
    `}function zt(e){let t=e.normalize("NFKD").toLowerCase();try{return t.replace(/[^\p{L}\p{N}]+/gu,"-").replace(/^-+|-+$/g,"")}catch{return t.replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"")}}function jt(e,t,r={}){return!e||e.length===0?"":`
        <div class="sm-section">
            <div class="sm-section-header">
                <span id="${t}-recently-viewed-label">${h(u(r,"Recently viewed"))}</span>
                <button class="sm-recently-viewed-clear" part="recently-viewed-clear">${h(u(r,"Clear"))}</button>
            </div>
            ${e.map((n,o)=>`
                <div class="sm-result-item sm-recently-viewed-item" id="${q(t,o)}" role="option" aria-selected="false" data-index="${o}" data-url="${h(n.url||"")}" data-query="${h(n.query)}">
                    <svg class="sm-result-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span class="sm-result-title">${h(n.title||n.query)}</span>
                    ${V()}
                </div>
            `).join("")}
        </div>
    `}function je(e,t={}){return!e||!e.trim()?`
            <div class="sm-empty" part="empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <p>${h(u(t,"Start typing to search"))}</p>
            </div>
        `:`
        <div class="sm-empty" part="empty">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <path d="m15 9-6 6M9 9l6 6"/>
            </svg>
            <p>${h(u(t,'No results for "{query}"',{query:e}))}</p>
        </div>
    `}function Gt(e={}){return`
        <div class="sm-loading-state" part="loading-state">
            <svg class="sm-spinner" width="24" height="24" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.25"/>
                <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"/>
            </svg>
            <p>${h(u(e,"Searching..."))}</p>
        </div>
    `}function Ut(e,t={}){return`
        <div class="sm-empty sm-error" part="error">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>${h(e||u(t,"Search failed."))}</p>
        </div>
    `}function We(e,t){let{query:r,results:n,recentlyViewed:o,loading:s,recentlyViewedEnabled:i,error:d}=e,{loadingIndicatorEnabled:a=!0,translations:l={}}=t,c=r&&r.trim();return s&&a?{html:Gt(l),hasResults:!1,showListbox:!1}:d?{html:Ut(d,l),hasResults:!1,showListbox:!1}:c?!n||n.length===0?{html:je(r,l),hasResults:!1,showListbox:!1}:{html:$t(n,r,t),hasResults:!0,showListbox:!0}:i&&o&&o.length>0?{html:jt(o,t.listboxId,l),hasResults:!0,showListbox:!0}:{html:je("",l),hasResults:!1,showListbox:!1}}function fe(e,t,r=!1,n={}){if(!e)return"";let o=[];if(o.push(L("results",t,"generic","",n)),e.took!==void 0){let l=e.took<1?"<1ms":`${Math.round(e.took)}ms`;o.push(L("time",l,"time","",n))}if(e.cacheEnabled!==void 0&&(e.cacheEnabled?e.cached?o.push(L("cache",u(n,"hit"),"cache-hit","",n)):o.push(L("cache",u(n,"miss"),"cache-miss","",n)):o.push(L("cache",u(n,"off"),"cache-off","",n))),e.cacheDriver&&o.push(L("storage",e.cacheDriver,"cache-driver",e.cacheDriver,n)),e.indices&&e.indices.length>0){let l=e.indices.length>2?u(n,"{count} indices",{count:e.indices.length}):e.indices.join(", ");o.push(L("indices",l,"generic","",n))}if(e.synonymsExpanded){let l=e.expandedQueries?e.expandedQueries.length-1:0;o.push(L("synonyms",`+${l}`,"synonyms","",n))}let s=e.rulesMatched?.length||0;o.push(L("rules",s,s>0?"rules":"generic","",n));let i=e.promotionsMatched?.length||0;o.push(L("promoted",i,i>0?"promotions":"generic","",n));let a=`<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">${r?'<path d="M6 9l6 6 6-6"/>':'<path d="M18 15l-6-6-6 6"/>'}</svg>`;return r?`<div class="sm-toolbar-collapsed-bar"><span class="sm-toolbar-collapsed-label">${h(u(n,"Debug"))}</span>${a}</div>`:`<div class="sm-toolbar-content">${o.join("")}</div><button class="sm-toolbar-toggle" aria-label="${h(u(n,"Collapse debug panel"))}" aria-expanded="true">${a}</button>`}function L(e,t,r,n="",o={}){let s=n?` data-backend="${h(n)}"`:"";return`<span class="sm-toolbar-item"><span class="sm-toolbar-label">${h(u(o,e))}</span><span class="sm-toolbar-value" data-type="${h(r)}"${s}>${h(String(t))}</span></span>`}function Ye(e,t){let{onSelect:r,onIndexChange:n,onEscape:o}=e,{listboxId:s}=t;return{handleKeydown(i,d,a){let l=a;switch(i.key){case"ArrowDown":return i.preventDefault(),l=Math.min(a+1,d-1),l!==a&&n&&n(l),l;case"ArrowUp":return i.preventDefault(),l=Math.max(a-1,-1),l!==a&&n&&n(l),l;case"Enter":return i.preventDefault(),a>=0&&r&&r(a),null;case"Escape":return i.preventDefault(),o&&o(),null;default:return null}},getListboxId(){return s}}}function Qe(e,t,r={}){let{scrollContainer:n,inputElement:o,listboxId:s,selectedClass:i="sm-selected"}=r,d=t>=0?q(s,t):null;o&&ne(o,{expanded:e.length>0,activeDescendant:d,listboxId:s}),e.forEach((a,l)=>{let c=l===t;a.classList.toggle(i,c),a.setAttribute("aria-selected",String(c)),c&&n&&qe(a,n)})}function Xe(e,t){e.forEach((r,n)=>{r.addEventListener("mousemove",()=>{t&&!r.classList.contains("sm-selected")&&t(n)})})}var Je="sm-page-highlight-style",Ze="__smPageHighlightRegistry",et="__searchManagerHotkeyHandled",I=null,ve=class extends HTMLElement{constructor(){super(),this.attachShadow({mode:"open"}),this.config=null,this.state=Se({...ee},this.handleStateChange.bind(this)),this.searchSequence=0,this.debounceTimer=null,this.analyticsIdleTimer=null,this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.listboxId=he("sm-listbox"),this.inputId=he("sm-input"),this.liveRegion=null,this.keyboardNavigator=null,this.elements={},this.handleInput=this.handleInput.bind(this),this.handleKeydown=this.handleKeydown.bind(this),this.handleResultClick=this.handleResultClick.bind(this)}get widgetType(){throw new Error("Subclass must implement widgetType getter")}render(){throw new Error("Subclass must implement render()")}getResultsContainer(){throw new Error("Subclass must implement getResultsContainer()")}getInputElement(){throw new Error("Subclass must implement getInputElement()")}getLoadingElement(){return this.elements.loading||null}getDebugToolbarElement(){return this.elements.debugToolbar||null}connectedCallback(){this.config=U(this,this.widgetType),this.state.set({recentlyViewed:re(Z(this.config))}),this.keyboardNavigator=Ye({onSelect:t=>this.selectResultAtIndex(t),onIndexChange:t=>this.state.set({selectedIndex:t}),onEscape:()=>this.handleEscape()},{listboxId:this.listboxId}),this.applyDestinationPageHighlight()}disconnectedCallback(){this.unregisterOpenWidget(),this.searchSequence++,this.debounceTimer&&(clearTimeout(this.debounceTimer),this.debounceTimer=null)}registerOpenWidget(){I&&I!==this&&typeof I.close=="function"&&I.close({reason:"replace",replacedBy:this,source:"replace"}),I=this}unregisterOpenWidget(){I===this&&(I=null)}claimHotkeyEvent(t,r){return t[et]||I&&I!==this&&I.state?.get("isOpen")&&I.config?.triggerHotkey?.toLowerCase()===r?!1:(t[et]=!0,!0)}attributeChangedCallback(t,r,n){r!==n&&this.shadowRoot.children.length>0&&(this.config=U(this,this.widgetType),this.render(),this.applyCustomStyles())}handleStateChange(t,r){(r.includes("results")||r.includes("query")||r.includes("recentlyViewed")||r.includes("error"))&&this.renderResultsContent(),(r.includes("results")||r.includes("meta"))&&this.updateDebugToolbar(),r.includes("selectedIndex")&&this.updateSelectionVisual(),r.includes("loading")&&this.updateLoadingVisual()}handleInput(t){let r=t.target.value;if(this.elements&&this.elements.clear&&(this.elements.clear.hidden=!r),this.state.set({query:r,selectedIndex:-1}),this.debounceTimer&&clearTimeout(this.debounceTimer),this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),!r.trim()){this.state.set({results:[]});return}r.length<this.config.searchMinChars||(this.debounceTimer=setTimeout(()=>{this.executeSearch(r)},this.config.searchDebounceMs))}async executeSearch(t){let r=++this.searchSequence;this.state.set({loading:!0,error:null}),this.liveRegion&&J(this.liveRegion,Ne(this.config.translations));try{let{results:n,meta:o}=await De({query:t,endpoint:this.config.searchEndpoint,indexHandles:this.config.indexHandles,siteId:this.config.siteId,resultsLimit:this.config.resultsLimit,resultsRequireUrl:this.config.resultsRequireUrl,snippetIncludeCodeBlocks:this.config.snippetIncludeCodeBlocks,snippetMode:this.config.snippetMode,snippetMaxLength:this.config.snippetMaxLength,snippetCleanMarkdown:this.config.snippetCleanMarkdown,debugEnabled:this.config.debugEnabled,apiKey:this.config.apiKey,translations:this.config.translations});if(r!==this.searchSequence)return;this.state.set({results:n,meta:o,loading:!1,selectedIndex:n.length>0?0:-1}),o&&typeof o.cached=="boolean"?this.lastSearchCacheState={cached:o.cached,took:typeof o.took=="number"?o.took:null}:this.lastSearchCacheState=null,this.liveRegion&&J(this.liveRegion,me(n.length,t,this.config.translations)),this.dispatchWidgetEvent("search",{query:t,results:n,meta:o}),this.startAnalyticsIdleTimer(t,n.length)}catch(n){if(r!==this.searchSequence||n.name==="AbortError")return;console.error("Search error:",n),this.state.set({results:[],loading:!1,error:n.message}),this.dispatchWidgetEvent("error",{query:t,error:n.message})}}renderResultsContent(){let t=this.getResultsContainer();if(!t)return;let r=this.state.getAll(),{recentlyViewedEnabled:n,resultsGroupingEnabled:o,highlightResultsEnabled:s,highlightTag:i,highlightClass:d,loadingIndicatorEnabled:a,debugEnabled:l}=this.config,{html:c,hasResults:m,showListbox:g}=We({query:r.query,results:r.results,recentlyViewed:r.recentlyViewed,loading:r.loading,error:r.error,recentlyViewedEnabled:n},{listboxId:this.listboxId,resultsGroupingEnabled:o,highlightResultsEnabled:s,highlightTag:i,highlightClass:d,loadingIndicatorEnabled:a,debugEnabled:l,translations:this.config.translations,highlightDestinationPersistQuery:this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery,highlightDestinationQueryParam:this.config.highlightDestinationQueryParam,promotionDisplay:this.config.promotionDisplay,promotionBadgeText:this.config.promotionBadgeText,promotionBadgePosition:this.config.promotionBadgePosition,resultsLayout:this.config.resultsLayout,hierarchyGroupBy:this.config.hierarchyGroupBy,hierarchyStyle:this.config.hierarchyStyle,hierarchyDisplay:this.config.hierarchyDisplay,hierarchyMaxHeadings:this.config.hierarchyMaxHeadings});t.innerHTML=c,g?t.setAttribute("role","listbox"):t.removeAttribute("role");let p=this.getInputElement();p&&ne(p,{expanded:m,activeDescendant:null,listboxId:this.listboxId}),this.liveRegion&&!r.loading&&(r.query&&r.results.length===0?J(this.liveRegion,me(0,r.query,this.config.translations)):!r.query&&r.recentlyViewed.length>0&&n&&J(this.liveRegion,Fe(r.recentlyViewed.length,this.config.translations))),this.attachResultHandlers();let b=t.querySelector(".sm-recently-viewed-clear");b&&b.addEventListener("click",x=>{x.stopPropagation(),$e(Z(this.config)),this.state.set({recentlyViewed:[]})}),m&&r.results.length>0&&this.state.set({selectedIndex:0})}attachResultHandlers(){let t=this.getResultsContainer();if(!t)return;let r=t.querySelectorAll(".sm-result-item");r.forEach(n=>{n.addEventListener("click",o=>this.handleResultClick(o,n))}),Xe(r,n=>{this.state.set({selectedIndex:n})})}updateSelectionVisual(){let t=this.getResultsContainer(),r=this.getInputElement();if(!t)return;let n=t.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");Qe(n,o,{scrollContainer:t,inputElement:r,listboxId:this.listboxId})}handleKeydown(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item"),o=this.state.get("selectedIndex");if(t.key==="Enter"){let s=this.state.get("query"),i=this.state.get("results")||[];s&&i.length>0&&this.trackSearchAnalytics(s,i.length,"enter")}this.keyboardNavigator.handleKeydown(t,n.length,o)}selectResultAtIndex(t){let r=this.getResultsContainer();if(!r)return;let n=r.querySelectorAll(".sm-result-item");t>=0&&n[t]&&n[t].click()}handleEscape(){}handleResultClick(t,r){let n=r.getAttribute("href"),o=r.dataset.url,s=n||o,i=r.dataset.title||r.querySelector(".sm-result-title")?.textContent,d=r.dataset.id,a=r.dataset.elementId||d,l=r.dataset.query||this.state.get("query"),c=r.classList.contains("sm-recently-viewed-item"),m=K(s,l,this.config.highlightDestinationEnabled&&this.config.highlightDestinationPersistQuery?this.config.highlightDestinationQueryParam:"");if(!c&&l){let p=Ie(Z(this.config),l,{title:i,url:s},this.config.recentlyViewedLimit);this.state.set({recentlyViewed:p})}let g=r.dataset.sourceIndex||we(this.config);if(a&&g&&Te({endpoint:this.config.trackClickEndpoint,elementId:a,query:l,index:g,apiKey:this.config.apiKey}),!c&&l&&this.trackSearchAnalytics(l,this.state.get("results")?.length||0,"click"),this.dispatchWidgetEvent("result-click",{id:d,elementId:a,title:i,url:m,query:l,isRecentlyViewed:c}),s&&s!=="#")c&&(t.preventDefault(),window.location.href=m),this.onResultSelected(m,i,d);else if(l){t.preventDefault();let p=this.getInputElement();p&&(p.value=l,this.state.set({query:l}),this.executeSearch(l))}}onResultSelected(t,r,n){}applyDestinationPageHighlight(){if(!this.config.highlightDestinationEnabled||typeof window>"u"||typeof document>"u")return;let t=this.config.highlightDestinationQueryParam||"smq",r=this.config.highlightDestinationContentSelector||"main, article, [data-search-content]",n=new URLSearchParams(window.location.search).get(t);if(!n||!n.trim())return;let o=this.getPageHighlightRegistry(),s=`${t}::${r}`;if(o.has(s))return;o.add(s);let i=()=>{this.ensurePageHighlightStyles(),this.highlightDestinationNodes(n.trim(),r,s)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",i,{once:!0}):window.requestAnimationFrame(i)}ensurePageHighlightStyles(){if(document.getElementById(Je))return;let t=document.createElement("style");t.id=Je,t.textContent=`
            .sm-page-highlight {
                background: var(--sm-highlight-bg, #fef08a);
                color: var(--sm-highlight-color, #854d0e);
                border-radius: 0.15em;
                padding: 0 0.08em;
            }
        `,document.head.appendChild(t)}highlightDestinationNodes(t,r,n){let o=Array.from(document.querySelectorAll(r));if(o.length===0)return;let s=[...new Set(ce(t).map(a=>a.trim()).filter(a=>a.length>=2))];if(s.length===0)return;let i=s.map(a=>He(a)).filter(Boolean).sort((a,l)=>l.length-a.length).join("|");if(!i)return;let d=new RegExp(`(${i})`,"gi");o.forEach(a=>{a.getAttribute("data-sm-highlighted")!==n&&(this.highlightTextNodesInScope(a,d),a.setAttribute("data-sm-highlighted",n))})}highlightTextNodesInScope(t,r){let n=document.createTreeWalker(t,NodeFilter.SHOW_TEXT,{acceptNode:s=>{let i=s.nodeValue;if(!i||!i.trim())return NodeFilter.FILTER_REJECT;let d=s.parentElement;return!d||d.closest("script, style, noscript, textarea, mark, .sm-highlight, .sm-page-highlight, search-modal")?NodeFilter.FILTER_REJECT:NodeFilter.FILTER_ACCEPT}}),o=[];for(;n.nextNode();)o.push(n.currentNode);o.forEach(s=>{let i=s.nodeValue||"";if(r.lastIndex=0,!r.test(i))return;let d=document.createDocumentFragment(),a=0;r.lastIndex=0;let l=i.matchAll(r);for(let c of l){let m=c[0],g=c.index??-1;if(g<0)continue;g>a&&d.appendChild(document.createTextNode(i.slice(a,g)));let p=document.createElement("mark");p.className="sm-highlight sm-page-highlight",p.textContent=m,d.appendChild(p),a=g+m.length}a<i.length&&d.appendChild(document.createTextNode(i.slice(a))),s.parentNode?.replaceChild(d,s)})}getPageHighlightRegistry(){let t=window[Ze];if(t instanceof Set)return t;let r=new Set;return window[Ze]=r,r}updateLoadingVisual(){let t=this.getLoadingElement();if(t){let r=this.state.get("loading"),n=this.config?.loadingIndicatorEnabled!==!1;t.hidden=!r||!n}}updateDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let{debugEnabled:r}=this.config,n=this.state.getAll();if(!r||!n.meta||n.results.length===0){t.hidden=!0;return}let o=t.classList.contains("sm-collapsed");t.innerHTML=fe(n.meta,n.results.length,o,this.config.translations),t.hidden=!1,o&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}attachDebugToolbarHandlers(t){let r=t.querySelector(".sm-toolbar-toggle");r&&r.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()});let n=t.querySelector(".sm-toolbar-collapsed-bar");n&&n.addEventListener("click",o=>{o.preventDefault(),o.stopPropagation(),this.toggleDebugToolbar()})}toggleDebugToolbar(){let t=this.getDebugToolbarElement();if(!t)return;let r=t.classList.toggle("sm-collapsed"),n=this.state.getAll();t.innerHTML=fe(n.meta,n.results.length,r,this.config.translations),r&&t.classList.add("sm-collapsed"),this.attachDebugToolbarHandlers(t)}applyCustomStyles(){if(!this.config)return;let t=this.shadowRoot.host,{theme:r,styles:n}=this.config;Me(t,n,r)}initializeLiveRegion(){this.liveRegion=Oe(this.shadowRoot)}startAnalyticsIdleTimer(t,r){this.analyticsIdleTimer&&clearTimeout(this.analyticsIdleTimer);let n=this.config.analyticsIdleTimeoutMs;!n||n<=0||(this.analyticsIdleTimer=setTimeout(()=>{this.trackSearchAnalytics(t,r,"idle")},n))}trackSearchAnalytics(t,r,n){!t||t===this.lastTrackedQuery||(this.lastTrackedQuery=t,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null),Ae({endpoint:this.config.trackSearchEndpoint,query:t,indexHandles:this.config.indexHandles,resultsCount:r,trigger:n,analyticsSource:this.config.analyticsSource,siteId:this.config.siteId,cached:this.lastSearchCacheState?.cached,took:this.lastSearchCacheState?.took,apiKey:this.config.apiKey}))}resetAnalyticsTracking(){this.lastTrackedQuery=null,this.lastSearchCacheState=null,this.analyticsIdleTimer&&(clearTimeout(this.analyticsIdleTimer),this.analyticsIdleTimer=null)}dispatchWidgetEvent(t,r={}){this.dispatchEvent(new CustomEvent(`search-${t}`,{bubbles:!0,composed:!0,detail:r}))}},tt=ve;var rt=`/**
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

.sm-recently-viewed-clear {
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

.sm-recently-viewed-clear:hover {
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

/* Hover and selected share the same visual \u2014 pointer movement sets .sm-selected,
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
    white-space: normal;
    overflow-wrap: anywhere;
}

/* Description length is bounded by snippetMaxLength server-side \u2014 no clamp */
.sm-result-desc {
    font-size: 13px;
    color: var(--sm-text-secondary);
    white-space: normal;
    overflow-wrap: anywhere;
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
.sm-hierarchy-child-row.sm-selected::after,
.sm-hierarchy-child-row:hover .sm-debug-enabled .sm-result-main::before,
.sm-hierarchy-child-row:hover .sm-debug-enabled .sm-result-main::after,
.sm-hierarchy-child-row.sm-selected .sm-debug-enabled .sm-result-main::before,
.sm-hierarchy-child-row.sm-selected .sm-debug-enabled .sm-result-main::after {
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

/* Debug rows: the strip below the content stretches the row, so 50% anchors
   drift. Re-anchor the branch and last-row curve to the content block
   (.sm-result-main) \u2014 the vertical through-line still spans the full row. */
.sm-hierarchy-child-row:has(.sm-debug-enabled)::after {
    display: none;
}

.sm-hierarchy-child-row .sm-debug-enabled .sm-result-main {
    position: relative;
}

.sm-hierarchy-child-row .sm-debug-enabled .sm-result-main::after {
    content: '';
    position: absolute;
    top: 50%;
    inset-inline-start: -20px;
    width: 20px;
    height: 0;
    border-top: 2px solid var(--sm-hierarchy-connector-color);
    pointer-events: none;
}

.sm-hierarchy-child-row-last:has(.sm-debug-enabled)::before {
    /* Keep only the short bridge across the gap up to the parent \u2014
       the curve itself is re-anchored to the content block below */
    top: calc(-1 * var(--sm-result-gap, 0px));
    bottom: auto;
    height: var(--sm-result-gap, 0px);
    width: 0;
    border-bottom: none;
    border-end-start-radius: 0;
}

.sm-hierarchy-child-row-last .sm-debug-enabled .sm-result-main::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 50%;
    inset-inline-start: -20px;
    width: 20px;
    border-inline-start: 2px solid var(--sm-hierarchy-connector-color);
    border-bottom: 2px solid var(--sm-hierarchy-connector-color);
    border-end-start-radius: 6px;
    pointer-events: none;
}

/* Last row: the curve provides the branch */
.sm-hierarchy-child-row-last .sm-debug-enabled .sm-result-main::after {
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

/* Badge chip \u2014 sits inline at the end of the title, or on its own line
   below it (.sm-promoted-badge-row wrapper) */
.sm-promoted-badge {
    display: inline-flex;
    align-items: center;
    padding: var(--sm-promoted-py, 2px) var(--sm-promoted-px, 6px);
    background: var(--sm-promoted-bg);
    color: var(--sm-promoted-color);
    border: var(--sm-promoted-border-width, 0) solid var(--sm-promoted-border-color, transparent);
    border-radius: var(--sm-promoted-radius, 4px);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    margin-inline-end: 8px;
    vertical-align: middle;
}

:host([data-theme="dark"]) .sm-promoted-badge {
    border-color: var(--sm-promoted-border-color-dark, transparent);
}

.sm-promoted-badge-row {
    display: block;
    margin-top: 3px;
}

.sm-promoted-badge-row--above {
    margin-top: 0;
    margin-bottom: 3px;
}

.sm-promoted-badge-row .sm-promoted-badge {
    margin-inline-end: 0;
}

/* Badge on the hovered/selected row \u2014 empty keys keep the base colors */
.sm-result-item.sm-selected .sm-promoted-badge,
.sm-result-item:hover .sm-promoted-badge {
    background: var(--sm-promoted-active-bg, var(--sm-promoted-bg));
    color: var(--sm-promoted-active-color, var(--sm-promoted-color));
}

:host([data-theme="dark"]) .sm-result-item.sm-selected .sm-promoted-badge,
:host([data-theme="dark"]) .sm-result-item:hover .sm-promoted-badge {
    background: var(--sm-promoted-active-bg-dark, var(--sm-promoted-bg));
    color: var(--sm-promoted-active-color-dark, var(--sm-promoted-color));
}

/* Row tint mode \u2014 resting rows; the tint text color (when set) covers
   title, description, and type in one color */
.sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) {
    background: var(--sm-promoted-tint-bg, #eff6ff);
}

:host([data-theme="dark"]) .sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) {
    background: var(--sm-promoted-tint-bg-dark, #1e3a8a);
}

.sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-result-title {
    color: var(--sm-promoted-tint-text, var(--sm-text-primary));
}

.sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-result-desc {
    color: var(--sm-promoted-tint-text, var(--sm-text-secondary));
}

.sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-result-type {
    color: var(--sm-promoted-tint-text, var(--sm-text-muted));
}

:host([data-theme="dark"]) .sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-result-title {
    color: var(--sm-promoted-tint-text-dark, var(--sm-text-primary));
}

:host([data-theme="dark"]) .sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-result-desc {
    color: var(--sm-promoted-tint-text-dark, var(--sm-text-secondary));
}

:host([data-theme="dark"]) .sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-result-type {
    color: var(--sm-promoted-tint-text-dark, var(--sm-text-muted));
}

/* Tinted row hover/selection \u2014 empty key falls back to the normal active bg */
.sm-result-item.sm-promoted--tint.sm-selected,
.sm-result-item.sm-promoted--tint:hover {
    background: var(--sm-promoted-tint-active-bg, var(--sm-selected-bg));
}

:host([data-theme="dark"]) .sm-result-item.sm-promoted--tint.sm-selected,
:host([data-theme="dark"]) .sm-result-item.sm-promoted--tint:hover {
    background: var(--sm-promoted-tint-active-bg-dark, var(--sm-selected-bg));
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
`;var nt=`/**
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
`;var ot=`/* =========================================================================
   DEBUG MODE - Developer Tools Panel
   Extensible key:value format with labels for clarity

   No dedicated style keys: every color derives from the widget's own
   tokens (muted/primary text, footer bg/text, dividers) via color-mix,
   so debug adapts to both schemes and any custom theme automatically.
   Semantic hues (score, cache, backends) are internal constants; their
   text mixes with the theme's primary text for readable contrast.
   ========================================================================= */

:host {
    /* Derived surfaces */
    --_dbg-surface: color-mix(in srgb, var(--sm-text-muted) 8%, transparent);
    --_dbg-border: color-mix(in srgb, var(--sm-text-muted) 25%, transparent);

    /* Semantic hues (internal, not configurable) */
    --_dbg-hue-generic: var(--sm-text-muted);
    --_dbg-hue-score: #22c55e;
    --_dbg-hue-time: #3b82f6;
    --_dbg-hue-matched: #6366f1;
    --_dbg-hue-promoted: #f59e0b;
    --_dbg-hue-boosted: #22c55e;
    --_dbg-hue-synonyms: #8b5cf6;
    --_dbg-hue-rules: #06b6d4;
    --_dbg-hue-promotions: #ec4899;
    --_dbg-hue-cache-hit: #22c55e;
    --_dbg-hue-cache-miss: #f59e0b;
    --_dbg-hue-cache-off: #6b7280;
    --_dbg-hue-mysql: #f59e0b;
    --_dbg-hue-redis: #dc2626;
    --_dbg-hue-typesense: #8b5cf6;
    --_dbg-hue-algolia: #06b6d4;
    --_dbg-hue-meilisearch: #ec4899;
    --_dbg-hue-file: #6b7280;
    --_dbg-hue-pgsql: #3b82f6;
    --_dbg-hue-elasticsearch: #eab308;
    --_dbg-hue-memcached: #22c55e;
    --_dbg-hue-database: #3b82f6;
    --_dbg-hue-apcu: #a855f7;
}

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
    /* Text/muted basis \u2014 re-derived on the active row below */
    --_dbg-text-basis: var(--sm-text-primary);
    --_dbg-muted-basis: var(--sm-text-muted);
    /* Hue-neutral chips follow the basis so they flip with the row state */
    --_dbg-hue-generic: var(--_dbg-muted-basis);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 3px 10px;
    width: 100%;
    padding: 6px 12px;
    background: color-mix(in srgb, var(--_dbg-muted-basis) 8%, transparent);
    border-top: 1px solid color-mix(in srgb, var(--_dbg-muted-basis) 25%, transparent);
    border-radius: 0 0 var(--sm-result-radius) var(--sm-result-radius);
    font-size: 10px;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Monaco, Consolas, monospace;
    line-height: 1.5;
    /* Debug info is always LTR - technical English labels/values */
    direction: ltr;
    text-align: start;
}

/* On the hovered/selected row the strip sits on the active background \u2014
   re-derive from the Active / Hover text chain so chips stay readable */
.sm-result-item.sm-selected .sm-debug-info,
.sm-result-item:hover .sm-debug-info {
    --_dbg-text-basis: var(--sm-result-active-text-color, var(--sm-text-primary));
    --_dbg-muted-basis: var(--sm-result-active-muted-color, var(--sm-text-muted));
}

/* On tinted promoted rows the strip sits on the tint background \u2014
   follow the tint text color when one is set */
.sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-debug-info {
    --_dbg-text-basis: var(--sm-promoted-tint-text, var(--sm-text-primary));
    --_dbg-muted-basis: var(--sm-promoted-tint-text, var(--sm-text-muted));
}

:host([data-theme="dark"]) .sm-result-item.sm-promoted--tint:not(.sm-selected):not(:hover) .sm-debug-info {
    --_dbg-text-basis: var(--sm-promoted-tint-text-dark, var(--sm-text-primary));
    --_dbg-muted-basis: var(--sm-promoted-tint-text-dark, var(--sm-text-muted));
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
    color: var(--_dbg-muted-basis, var(--sm-text-muted));
    font-weight: 600;
    text-transform: uppercase;
    font-size: 9px;
    letter-spacing: 0.03em;
}

/* Values - base style: hue-tinted chip, text mixed toward the theme text */
.sm-debug-value {
    --_hue: var(--_dbg-hue-generic);
    padding: 1px 5px;
    border-radius: 3px;
    font-weight: 500;
    background: color-mix(in srgb, var(--_hue) 16%, transparent);
    color: color-mix(in srgb, var(--_hue) 60%, var(--_dbg-text-basis, var(--sm-text-primary)));
}

/* Score / matched / promoted / boosted values */
.sm-debug-value[data-type="score"] { --_hue: var(--_dbg-hue-score); }
.sm-debug-value[data-type="matched"] { --_hue: var(--_dbg-hue-matched); }
.sm-debug-value[data-type="promoted"] { --_hue: var(--_dbg-hue-promoted); }
.sm-debug-value[data-type="boosted"] { --_hue: var(--_dbg-hue-boosted); }

/* Backend values - color coded */
.sm-debug-value[data-backend="mysql"] { --_hue: var(--_dbg-hue-mysql); }
.sm-debug-value[data-backend="redis"] { --_hue: var(--_dbg-hue-redis); }
.sm-debug-value[data-backend="typesense"] { --_hue: var(--_dbg-hue-typesense); }
.sm-debug-value[data-backend="algolia"] { --_hue: var(--_dbg-hue-algolia); }
.sm-debug-value[data-backend="meilisearch"] { --_hue: var(--_dbg-hue-meilisearch); }
.sm-debug-value[data-backend="file"] { --_hue: var(--_dbg-hue-file); }
.sm-debug-value[data-backend="pgsql"] { --_hue: var(--_dbg-hue-pgsql); }
.sm-debug-value[data-backend="elasticsearch"] { --_hue: var(--_dbg-hue-elasticsearch); }

/* Index value - outlined style */
.sm-debug-value[data-type="index"] {
    background: transparent;
    border: 1px solid color-mix(in srgb, var(--_dbg-muted-basis, var(--sm-text-muted)) 40%, transparent);
    color: color-mix(in srgb, var(--_dbg-muted-basis, var(--sm-text-muted)) 70%, var(--_dbg-text-basis, var(--sm-text-primary)));
}

/* =========================================================================
   DEBUG TOOLBAR - Floating search summary panel
   Follows the footer: same background/text derivation, same divider.
   ========================================================================= */

.sm-debug-toolbar {
    display: flex;
    flex-wrap: nowrap;
    align-items: stretch;
    justify-content: center;
    gap: 0;
    padding: 0;
    background: var(--sm-footer-bg-resolved, transparent);
    border-top: var(--sm-header-border-width, 1px) solid var(--sm-border-color);
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.06);
    font-size: 11px;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Monaco, Consolas, monospace;
    direction: ltr;
    text-align: center;
    flex-shrink: 0;
    overflow: hidden;
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
    border-inline-start: 1px solid var(--_dbg-border);
    color: var(--sm-text-muted);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    flex-shrink: 0;
}

.sm-toolbar-toggle:hover {
    background: color-mix(in srgb, var(--sm-text-muted) 10%, transparent);
    color: var(--sm-text-primary);
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
    background: color-mix(in srgb, var(--sm-text-muted) 6%, transparent);
}

.sm-toolbar-collapsed-label {
    color: var(--sm-footer-text-resolved, var(--sm-text-muted));
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.sm-toolbar-collapsed-bar svg {
    color: var(--sm-text-muted);
}

/* Toolbar item - stacked vertically (value on top, label below) */
.sm-toolbar-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 8px 14px;
    border-inline-end: 1px solid var(--_dbg-border);
}

.sm-toolbar-item:last-child {
    border-inline-end: none;
}

/* Label below value - small uppercase */
.sm-toolbar-label {
    order: 2; /* Put below value */
    color: var(--sm-footer-text-resolved, var(--sm-text-muted));
    font-weight: 600;
    text-transform: uppercase;
    font-size: 8px;
    letter-spacing: 0.05em;
}

/* Values - hue-tinted chip, text mixed toward the theme text */
.sm-toolbar-value {
    --_hue: var(--_dbg-hue-generic);
    padding: 2px 6px;
    border-radius: 3px;
    font-weight: 600;
    background: color-mix(in srgb, var(--_hue) 16%, transparent);
    color: color-mix(in srgb, var(--_hue) 60%, var(--sm-text-primary));
}

.sm-toolbar-value[data-type="time"] { --_hue: var(--_dbg-hue-time); }
.sm-toolbar-value[data-type="cache-hit"] { --_hue: var(--_dbg-hue-cache-hit); }
.sm-toolbar-value[data-type="cache-miss"] { --_hue: var(--_dbg-hue-cache-miss); }
.sm-toolbar-value[data-type="cache-off"] { --_hue: var(--_dbg-hue-cache-off); }
.sm-toolbar-value[data-type="synonyms"] { --_hue: var(--_dbg-hue-synonyms); }
.sm-toolbar-value[data-type="rules"] { --_hue: var(--_dbg-hue-rules); }
.sm-toolbar-value[data-type="promotions"] { --_hue: var(--_dbg-hue-promotions); }

/* Cache driver types */
.sm-toolbar-value[data-type="cache-driver"][data-backend="redis"] { --_hue: var(--_dbg-hue-redis); }
.sm-toolbar-value[data-type="cache-driver"][data-backend="file"] { --_hue: var(--_dbg-hue-file); }
.sm-toolbar-value[data-type="cache-driver"][data-backend="memcached"] { --_hue: var(--_dbg-hue-memcached); }
.sm-toolbar-value[data-type="cache-driver"][data-backend="database"] { --_hue: var(--_dbg-hue-database); }
.sm-toolbar-value[data-type="cache-driver"][data-backend="apcu"] { --_hue: var(--_dbg-hue-apcu); }

/* Backend values - same coding as the per-result debug info */
.sm-toolbar-value[data-backend="mysql"] { --_hue: var(--_dbg-hue-mysql); }
.sm-toolbar-value[data-backend="redis"] { --_hue: var(--_dbg-hue-redis); }
.sm-toolbar-value[data-backend="typesense"] { --_hue: var(--_dbg-hue-typesense); }
.sm-toolbar-value[data-backend="algolia"] { --_hue: var(--_dbg-hue-algolia); }
.sm-toolbar-value[data-backend="meilisearch"] { --_hue: var(--_dbg-hue-meilisearch); }
.sm-toolbar-value[data-backend="file"] { --_hue: var(--_dbg-hue-file); }
.sm-toolbar-value[data-backend="pgsql"] { --_hue: var(--_dbg-hue-pgsql); }
`;var Kt=rt+`
`+nt+`
`+ot,ye=class extends tt{constructor(){super(),this.externalTrigger=null,this.previouslyFocused=null,this.open=this.open.bind(this),this.close=this.close.bind(this),this.toggle=this.toggle.bind(this),this.handleGlobalKeydown=this.handleGlobalKeydown.bind(this),this.handleClearClick=this.handleClearClick.bind(this),this.handleBackdropClick=this.handleBackdropClick.bind(this),this.handleTriggerClick=this.handleTriggerClick.bind(this),this.handleExternalTriggerClick=this.handleExternalTriggerClick.bind(this),this.handleCloseClick=this.handleCloseClick.bind(this)}get widgetType(){return"modal"}static get observedAttributes(){return Ce("modal")}connectedCallback(){super.connectedCallback(),this.render(),this.attachEventListeners()}disconnectedCallback(){this.state.get("isOpen")&&this.close({reason:"disconnect",source:"disconnect",restoreFocus:!1}),super.disconnectedCallback(),this.detachEventListeners()}attributeChangedCallback(t,r,n){if(r===n||this.shadowRoot.children.length===0)return;if(t==="theme"){this.config=U(this,this.widgetType),this.shadowRoot.host.setAttribute("data-theme",this.config.theme),this.applyCustomStyles();return}let o=this.state.get("isOpen"),s=this.state.get("query")||"";this.detachEventListeners(),this.config=U(this,this.widgetType),this.render(),this.attachEventListeners(),o&&(this.registerOpenWidget(),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value=s,this.elements.clear.hidden=!s,this.renderResultsContent(),this.updateLoadingVisual(),this.updateDebugToolbar(),this.updateSelectionVisual(),document.body.style.overflow=this.config.modalPreventBodyScroll?"hidden":"",requestAnimationFrame(()=>{this.isConnected&&this.elements.input.focus()}))}render(){let{theme:t,placeholder:r,triggerEnabled:n,triggerLabel:o,translations:s}=this.config,i=h(this.getHotkeyDisplay()),d=h(r||""),a=h(o||u(s,"Search")),l=h(u(s,"Search")),c=h(u(s,"Close search")),m=h(u(s,"Clear search")),g=h(u(s,"Search results"));this.shadowRoot.innerHTML=`
            <style>${Kt}</style>

            <!-- Trigger button -->
            <button class="sm-trigger" part="trigger" aria-label="${a}" aria-haspopup="dialog" aria-expanded="false" ${n?"":'style="display: none;"'}>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <path d="m21 21-4.35-4.35"/>
                </svg>
                <span class="sm-trigger-text">${a}</span>
                <kbd class="sm-trigger-kbd" aria-hidden="true">${i}</kbd>
            </button>

            <!-- Modal backdrop -->
            <div class="sm-backdrop" part="backdrop" hidden>
                <div class="sm-modal" part="modal" role="dialog" aria-modal="true" aria-label="${l}">
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
                                placeholder="${d}"
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
                            <button class="sm-clear" part="clear" aria-label="${m}" hidden>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </span>
                        <button class="sm-close" part="close" aria-label="${c}">
                            <kbd>esc</kbd>
                        </button>
                    </div>

                    <!-- Results -->
                    <div class="sm-results" part="results" id="${this.listboxId}" role="listbox" aria-label="${g}"></div>

                    <!-- Debug toolbar (sticky at bottom) -->
                    <div class="sm-debug-toolbar" part="debug-toolbar" hidden></div>

                    <!-- Footer -->
                    <div class="sm-footer" part="footer">
                        <div class="sm-footer-hints">
                            <span><kbd>\u2191</kbd><kbd>\u2193</kbd> ${h(u(s,"navigate"))}</span>
                            <span><kbd>\u21B5</kbd> ${h(u(s,"select"))}</span>
                            <span><kbd>esc</kbd> ${h(u(s,"close"))}</span>
                        </div>
                        <div class="sm-footer-brand">
                            ${h(u(s,"Powered by"))} <a href="https://github.com/LindemannRock/craft-search-manager" target="_blank" rel="noopener noreferrer"><strong>Search Manager</strong><span class="sm-sr-only"> ${h(u(s,"(opens in a new tab)"))}</span></a>
                        </div>
                    </div>
                </div>
            </div>
        `,this.elements={trigger:this.shadowRoot.querySelector(".sm-trigger"),backdrop:this.shadowRoot.querySelector(".sm-backdrop"),modal:this.shadowRoot.querySelector(".sm-modal"),input:this.shadowRoot.querySelector(".sm-input"),results:this.shadowRoot.querySelector(".sm-results"),loading:this.shadowRoot.querySelector(".sm-loading"),clear:this.shadowRoot.querySelector(".sm-clear"),close:this.shadowRoot.querySelector(".sm-close"),debugToolbar:this.shadowRoot.querySelector(".sm-debug-toolbar")},this.initializeLiveRegion(),this.shadowRoot.host.setAttribute("data-theme",t),this.applyCustomStyles()}getResultsContainer(){return this.elements.results}getInputElement(){return this.elements.input}getLoadingElement(){return this.elements.loading}applyCustomStyles(){if(super.applyCustomStyles(),!this.config)return;let{modalBackdropOpacity:t,modalBackdropBlurEnabled:r}=this.config,n=this.shadowRoot.host;n.style.setProperty("--sm-backdrop-opacity",t/100),n.style.setProperty("--sm-backdrop-blur",r?"blur(4px)":"none")}attachEventListeners(){this.elements.trigger.addEventListener("click",this.handleTriggerClick),this.elements.close.addEventListener("click",this.handleCloseClick),this.elements.backdrop.addEventListener("click",this.handleBackdropClick),this.elements.input.addEventListener("input",this.handleInput),this.elements.input.addEventListener("keydown",this.handleKeydown),this.elements.clear.addEventListener("click",this.handleClearClick),document.addEventListener("keydown",this.handleGlobalKeydown);let{triggerSelector:t}=this.config;t&&(this.externalTrigger=document.querySelector(t),this.externalTrigger&&this.externalTrigger.addEventListener("click",this.handleExternalTriggerClick))}detachEventListeners(){this.elements.trigger&&this.elements.trigger.removeEventListener("click",this.handleTriggerClick),this.elements.close&&this.elements.close.removeEventListener("click",this.handleCloseClick),this.elements.backdrop&&this.elements.backdrop.removeEventListener("click",this.handleBackdropClick),this.elements.input&&(this.elements.input.removeEventListener("input",this.handleInput),this.elements.clear.removeEventListener("click",this.handleClearClick),this.elements.input.removeEventListener("keydown",this.handleKeydown)),document.removeEventListener("keydown",this.handleGlobalKeydown),this.externalTrigger&&(this.externalTrigger.removeEventListener("click",this.handleExternalTriggerClick),this.externalTrigger=null)}open(t={}){let r=t.source||"programmatic";if(this.state.get("isOpen")){requestAnimationFrame(()=>{this.elements.input.focus()});return}this.previouslyFocused=document.activeElement instanceof HTMLElement?document.activeElement:null,this.registerOpenWidget(),this.state.set({isOpen:!0}),this.elements.backdrop.hidden=!1,this.elements.trigger.setAttribute("aria-expanded","true"),this.elements.input.value="",this.elements.clear.hidden=!0,this.state.set({query:"",results:[],selectedIndex:-1}),this.renderResultsContent(),requestAnimationFrame(()=>{this.elements.input.focus()}),this.config.modalPreventBodyScroll&&(document.body.style.overflow="hidden"),this.dispatchWidgetEvent("open",{source:r})}close(t={}){let r=this.state.get("isOpen");this.state.set({isOpen:!1}),this.elements.backdrop.hidden=!0,this.elements.trigger.setAttribute("aria-expanded","false"),this.unregisterOpenWidget(),this.config.modalPreventBodyScroll&&(document.body.style.overflow=""),this.resetAnalyticsTracking(),r&&t.restoreFocus!==!1&&this.previouslyFocused?.isConnected&&this.previouslyFocused.focus(),this.previouslyFocused=null,r&&this.dispatchWidgetEvent("close",{reason:t.reason||"programmatic",source:t.source||"programmatic"})}toggle(t={}){this.state.get("isOpen")?this.close({reason:t.reason||"toggle",source:t.source||"toggle"}):this.open({source:t.source||"toggle"})}handleTriggerClick(){this.toggle({source:"trigger"})}handleExternalTriggerClick(){this.toggle({source:"external-trigger"})}handleCloseClick(){this.close({reason:"close-button",source:"close-button"})}handleGlobalKeydown(t){let r=this.config.triggerHotkey.toLowerCase();if((navigator.platform.toUpperCase().indexOf("MAC")>=0?t.metaKey:t.ctrlKey)&&t.key.toLowerCase()===r){if(!this.claimHotkeyEvent(t,r))return;t.preventDefault(),this.toggle({source:"hotkey"})}t.key==="Escape"&&this.state.get("isOpen")&&(t.preventDefault(),this.close({reason:"escape",source:"escape"}))}handleEscape(){this.close({reason:"escape",source:"keyboard"})}handleClearClick(){this.elements.input.value="",this.elements.input.dispatchEvent(new Event("input",{bubbles:!0})),this.elements.input.focus()}handleBackdropClick(t){t.target===this.elements.backdrop&&this.close({reason:"backdrop",source:"backdrop"})}onResultSelected(t,r,n){this.close({reason:"result-selected",source:"result-selected"})}getHotkeyDisplay(){let t=navigator.platform.toUpperCase().indexOf("MAC")>=0,r=this.config.triggerHotkey.toUpperCase();return t?`\u2318${r}`:`Ctrl+${r}`}},Wt=ye;return ct(Yt);})();
if(typeof customElements!=='undefined'&&!customElements.get('search-modal')){customElements.define('search-modal',SearchModalWidget.default);}
