var SearchManagerHighlighter = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // src/modules/Highlighter.js
  var Highlighter_exports = {};
  __export(Highlighter_exports, {
    createHighlighter: () => createHighlighter,
    escapeHtml: () => escapeHtml,
    escapeRegex: () => escapeRegex,
    highlightMatches: () => highlightMatches,
    parseQueryTerms: () => parseQueryTerms
  });
  function escapeHtml(text) {
    if (!text)
      return "";
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
  function escapeRegex(string) {
    if (!string)
      return "";
    return string.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }
  function parseQueryTerms(query) {
    if (!query)
      return [];
    const terms = [];
    const phraseRegex = /"([^"]+)"/g;
    let match;
    while ((match = phraseRegex.exec(query)) !== null) {
      if (match[1].trim())
        terms.push(match[1].trim());
    }
    const remaining = query.replace(/"[^"]*"/g, "");
    const operators = /* @__PURE__ */ new Set([
      "and",
      "or",
      "not",
      // English
      "und",
      "oder",
      "nicht",
      // German
      "et",
      "ou",
      "sauf",
      // French
      "y",
      "o",
      "no"
      // Spanish
    ]);
    remaining.split(/\s+/).filter((w) => w.length > 0).forEach((word) => {
      word = word.replace(/^[a-zA-Z]+:/, "");
      word = word.replace(/\*/g, "");
      word = word.replace(/\^\d+(\.\d+)?/, "");
      word = word.replace(/"/g, "");
      if (!word || operators.has(word.toLowerCase()))
        return;
      terms.push(word);
    });
    const withCamel = [];
    terms.forEach((word) => {
      withCamel.push(word);
      const parts = word.split(/(?<=[a-z])(?=[A-Z])/);
      if (parts.length > 1) {
        parts.forEach((p) => {
          if (p.length >= 3)
            withCamel.push(p);
        });
      }
    });
    return withCamel;
  }
  function highlightMatches(text, query, options = {}) {
    const {
      enabled = true,
      tag = "mark",
      className = "",
      terms = null
    } = options;
    if (!enabled) {
      return escapeHtml(text);
    }
    const classes = ["sm-highlight"];
    if (className) {
      classes.push(className);
    }
    const classAttr = ` class="${classes.join(" ")}"`;
    const termList = buildHighlightTerms(query, terms);
    if (termList.length === 0) {
      return escapeHtml(text);
    }
    return applyHighlightRanges(text, termList, tag, classAttr);
  }
  function buildHighlightTerms(query, terms) {
    if (Array.isArray(terms) && terms.length > 0) {
      return normalizeTerms(terms);
    }
    if (!query) {
      return [];
    }
    return normalizeTerms(parseQueryTerms(query));
  }
  function normalizeTerms(terms) {
    const seen = /* @__PURE__ */ new Set();
    return terms.filter((w) => typeof w === "string" && w.length > 0).sort((a, b) => b.length - a.length).filter((w) => {
      const lower = w.toLowerCase();
      if (seen.has(lower))
        return false;
      seen.add(lower);
      return true;
    });
  }
  function applyHighlightRanges(text, terms, tag, classAttr) {
    const lowerText = text.toLowerCase();
    const ranges = [];
    terms.forEach((term) => {
      const lowerTerm = term.toLowerCase();
      if (!lowerTerm)
        return;
      let start = 0;
      while (start < lowerText.length) {
        const index = lowerText.indexOf(lowerTerm, start);
        if (index === -1)
          break;
        ranges.push({ start: index, end: index + lowerTerm.length });
        start = index + lowerTerm.length;
      }
    });
    if (ranges.length === 0) {
      return escapeHtml(text);
    }
    ranges.sort((a, b) => {
      if (a.start !== b.start)
        return a.start - b.start;
      return b.end - b.start - (a.end - a.start);
    });
    const merged = [];
    let lastEnd = -1;
    ranges.forEach((range) => {
      if (range.start >= lastEnd) {
        merged.push(range);
        lastEnd = range.end;
      }
    });
    let result = "";
    let cursor = 0;
    merged.forEach((range) => {
      if (cursor < range.start) {
        result += escapeHtml(text.slice(cursor, range.start));
      }
      result += `<${tag}${classAttr}>${escapeHtml(text.slice(range.start, range.end))}</${tag}>`;
      cursor = range.end;
    });
    if (cursor < text.length) {
      result += escapeHtml(text.slice(cursor));
    }
    return result;
  }
  function createHighlighter(options = {}) {
    return (text, query) => highlightMatches(text, query, options);
  }
  return __toCommonJS(Highlighter_exports);
})();
if(typeof window!=="undefined"){  var _h=SearchManagerHighlighter;  window.SearchManagerHighlighter={    highlight:_h.highlightMatches,    escapeHtml:_h.escapeHtml,    escapeRegex:_h.escapeRegex,    create:_h.createHighlighter,    parseQuery:_h.parseQueryTerms  };}
