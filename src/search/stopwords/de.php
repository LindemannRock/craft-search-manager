<?php

/**
 * German stop words for Search Manager search indexing
 *
 * Generic German stop words that work across all German-speaking regions
 * Covers de-DE (Germany), de-AT (Austria), de-CH (Switzerland)
 *
 * For regional customization:
 * Copy to: config/search-manager/stopwords/de-at.php or de-ch.php
 *
 * @since 5.0.0
 */

return [
    // Articles
    'der', 'die', 'das', 'den', 'dem', 'des',
    'ein', 'eine', 'einer', 'eines', 'einem', 'einen',

    // Pronouns
    'ich', 'du', 'er', 'sie', 'es', 'wir', 'ihr',
    'mein', 'meine', 'dein', 'deine', 'sein', 'seine',
    'ihr', 'ihre', 'unser', 'unsere', 'euer', 'eure',
    'dieser', 'diese', 'dieses', 'jener', 'jene', 'jenes',
    'welcher', 'welche', 'welches',
    'man', 'sich', 'selbst',

    // Prepositions
    'in', 'an', 'auf', 'bei', 'durch', 'für', 'gegen', 'hinter',
    'mit', 'nach', 'neben', 'über', 'unter', 'von', 'vor', 'zu',
    'zwischen', 'aus', 'außer', 'bis', 'entlang', 'gegenüber',
    'ohne', 'seit', 'um', 'während', 'wegen',

    // Conjunctions
    'und', 'oder', 'aber', 'denn', 'sondern', 'doch',
    'als', 'bis', 'dass', 'ob', 'obwohl', 'weil', 'wenn',

    // Verbs (common auxiliary and modal)
    'sein', 'haben', 'werden', 'können', 'müssen', 'sollen',
    'wollen', 'dürfen', 'mögen', 'möchten',
    'ist', 'sind', 'war', 'waren', 'gewesen',
    'hat', 'haben', 'hatte', 'hatten', 'gehabt',
    'wird', 'werden', 'wurde', 'wurden', 'geworden',
    'kann', 'können', 'konnte', 'konnten', 'gekonnt',
    'muss', 'müssen', 'musste', 'mussten', 'gemusst',

    // Adverbs
    'hier', 'da', 'dort', 'wo', 'wann', 'wie', 'warum',
    'auch', 'noch', 'nur', 'schon', 'sehr', 'so', 'zu',
    'mehr', 'weniger', 'viel', 'wenig',
    'nie', 'immer', 'oft', 'manchmal', 'selten',
    'bereits', 'bald', 'dann', 'jetzt', 'nun',

    // Others
    'ja', 'nein', 'nicht', 'kein', 'keine', 'keiner',
    'all', 'alle', 'alles', 'jeder', 'jede', 'jedes',
    'einige', 'etliche', 'manche', 'mehrere', 'viele',
    'was', 'wer', 'wen', 'wem', 'wessen',
    'etwas', 'nichts', 'alles',
];
